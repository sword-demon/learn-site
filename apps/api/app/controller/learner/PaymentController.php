<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\service\PaymentConfigService;
use App\support\ApiResponse;
use support\Request;

final class PaymentController
{
    public function __construct(private readonly PaymentConfigService $config)
    {
    }

    public function options(Request $request): \support\Response
    {
        if (getenv('APP_ENV') === 'testing' || getenv('PAYMENT_DRIVER') === 'fake') {
            return ApiResponse::ok([
                'enabled' => true,
                'enabled_channels' => ['wxpay', 'alipay'],
            ]);
        }
        $config = $this->config->getActive();
        $channels = $config['enabled_channels'] ?? [];

        return ApiResponse::ok([
            'enabled' => $config !== null && $config['enabled'] === true,
            'enabled_channels' => is_array($channels) ? array_values($channels) : [],
        ]);
    }
}
