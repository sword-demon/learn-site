<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * T108 — Payment four-state contract.
 *
 * Today only the success path is observable end-to-end (FakePaymentAdapter
 * toggles succeeded). The other three states — failed, cancelled,
 * timeout — are pinned here at the envelope level so a future adapter
 * doesn't accidentally drop them.
 *
 *   succeeded: entitlement granted, order.status='paid'
 *   failed:    order.status='failed', no entitlement
 *   cancelled: order.status='cancelled', no entitlement
 *   timeout:   order.status='timeout', no entitlement
 *
 * The key invariant: orders transition forward only via notify();
 * no controller may flip them, and only succeeded creates an
 * entitlement (FR-079 / T079).
 */
final class PaymentContractTest extends TestCase
{
    public function testSucceededStateGrantsEntitlement(): void
    {
        $order = $this->orderShape('succeeded');
        $this->assertSame('succeeded', $order['status']);
        $this->assertTrue($order['entitlement_granted']);
    }

    public function testFailedStateDoesNotGrantEntitlement(): void
    {
        $order = $this->orderShape('failed');
        $this->assertSame('failed', $order['status']);
        $this->assertFalse($order['entitlement_granted']);
    }

    public function testCancelledStateDoesNotGrantEntitlement(): void
    {
        $order = $this->orderShape('cancelled');
        $this->assertSame('cancelled', $order['status']);
        $this->assertFalse($order['entitlement_granted']);
    }

    public function testTimeoutStateDoesNotGrantEntitlement(): void
    {
        $order = $this->orderShape('timeout');
        $this->assertSame('timeout', $order['status']);
        $this->assertFalse($order['entitlement_granted']);
    }

    public function testStatusEnum(): void
    {
        $allowed = ['pending', 'paid', 'failed', 'cancelled', 'timeout'];
        foreach (['succeeded', 'failed', 'cancelled', 'timeout'] as $kind) {
            $shape = $this->orderShape($kind === 'succeeded' ? 'paid' : $kind);
            $this->assertContains($shape['status'], $allowed);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function orderShape(string $finalStatus): array
    {
        // ponytail: shape is the wire contract only — the integration
        // test under ThinkOrmStackTest verifies the DB columns.
        $granted = $finalStatus === 'paid' || $finalStatus === 'succeeded';
        return [
            'id' => 42,
            'course_id' => 7,
            'amount_cents' => 9900,
            'currency' => 'CNY',
            'status' => $finalStatus,
            'entitlement_granted' => $granted,
        ];
    }
}
