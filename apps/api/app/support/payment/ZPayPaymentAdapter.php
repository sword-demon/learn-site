<?php

declare(strict_types=1);

namespace App\support\payment;

use App\service\BusinessException;
use App\service\PaymentConfigService;
use support\Request;
use support\think\Db;

final class ZPayPaymentAdapter implements PaymentAdapter
{
    private ?string $notifyIssue = null;
    private ?int $notifyIssueOrderId = null;
    private bool $notifySignatureValid = false;

    public function __construct(private readonly PaymentConfigService $config)
    {
    }

    public function createCharge(int $orderId, float $amount, string $currency, ?string $channel = null): array
    {
        $config = $this->config->getActive();
        if ($config === null || $config['enabled'] !== true) {
            throw new BusinessException('CONFLICT', 'PAYMENT_DISABLED');
        }

        $channel ??= 'wxpay';
        if (!in_array($channel, $config['enabled_channels'], true)) {
            throw new BusinessException('CONFLICT', 'PAYMENT_CHANNEL_DISABLED');
        }

        $order = Db::name('orders')->alias('o')
            ->join('courses c', 'c.id = o.course_id')
            ->where('o.id', $orderId)
            ->field('o.id, o.paid_amount, o.currency, c.title')
            ->find();
        if (!is_array($order)) {
            throw new BusinessException('NOT_FOUND', 'ORDER_NOT_FOUND');
        }

        $payload = [
            'pid' => (string) $config['pid'],
            'type' => $channel,
            'notify_url' => (string) $config['notify_url'],
            'return_url' => (string) $config['return_url'],
            'out_trade_no' => (string) $orderId,
            'name' => substr((string) ($order['title'] ?? '课程'), 0, 64),
            'money' => number_format($amount, 2, '.', ''),
            'param' => '',
            'sign_type' => 'MD5',
        ];
        $payload['sign'] = $this->sign($payload, $this->config->merchantKey());

        return [
            'type' => 'redirect',
            'redirect_url' => rtrim((string) $config['api_url'], '/') . '/submit.php?'
                . http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
            'out_trade_no' => (string) $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'provider' => 'zpay',
            'channel' => $channel,
        ];
    }

    public function parseNotify(Request $request): ?NotifyResult
    {
        $this->notifyIssue = null;
        $this->notifyIssueOrderId = null;
        $this->notifySignatureValid = false;
        $payload = $this->verifySignature($request);
        if ($payload === null) {
            $this->notifyIssue = 'invalid_signature';
            return null;
        }
        $this->notifySignatureValid = true;

        $outTradeNo = (string) $payload['out_trade_no'];
        if (!ctype_digit($outTradeNo) || (int) $outTradeNo <= 0) {
            $this->notifyIssue = 'invalid_payload';
            return null;
        }
        $orderId = (int) $outTradeNo;
        $this->notifyIssueOrderId = $orderId;
        $order = Db::name('orders')->where('id', $orderId)->find();
        if (!is_array($order)) {
            $this->notifyIssue = 'unknown_order';
            return null;
        }

        $money = (string) $payload['money'];
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $money)
            || $this->money($money) !== $this->money((string) $order['paid_amount'])) {
            $this->notifyIssue = 'amount_mismatch';
            return null;
        }

        $status = strtoupper((string) $payload['trade_status']);
        $providerRef = (string) $payload['trade_no'];
        return match ($status) {
            'TRADE_SUCCESS' => NotifyResult::succeeded($orderId, $providerRef),
            default => NotifyResult::failed($orderId, $providerRef),
        };
    }

    public function setSuccessHandler(callable $handler): void
    {
        unset($handler);
    }

    /** @return array<string, scalar|null>|null */
    public function verifySignature(Request $request): ?array
    {
        $payload = array_merge($request->get(), $request->post());
        foreach (['pid', 'type', 'out_trade_no', 'trade_no', 'name', 'money', 'trade_status', 'sign_type', 'sign'] as $field) {
            if (!isset($payload[$field]) || !is_scalar($payload[$field])) {
                return null;
            }
        }

        $config = $this->config->getActive();
        if ($config === null
            || (string) $payload['pid'] !== (string) $config['pid']
            || !in_array((string) $payload['type'], ['wxpay', 'alipay'], true)
            || strtoupper((string) $payload['sign_type']) !== 'MD5') {
            return null;
        }

        $expected = $this->sign($payload, $this->config->merchantKey());
        $actual = strtolower(trim((string) $payload['sign']));
        return hash_equals($expected, $actual) ? $payload : null;
    }

    public function notifyIssue(): ?string
    {
        return $this->notifyIssue;
    }

    public function notifyIssueOrderId(): ?int
    {
        return $this->notifyIssueOrderId;
    }

    public function notifySignatureValid(): bool
    {
        return $this->notifySignatureValid;
    }

    /** @param array<string, mixed> $payload */
    private function sign(array $payload, string $merchantKey): string
    {
        ksort($payload);
        $parts = [];
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '' || $key === 'sign' || $key === 'sign_type') {
                continue;
            }
            $parts[] = $key . '=' . stripslashes((string) $value);
        }
        return strtolower(md5(implode('&', $parts) . $merchantKey));
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
