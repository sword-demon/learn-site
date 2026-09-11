<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * maskPhone() — wire-format contract: must match the Zod MaskedPhone regex
 * /^1[3-9]\d\*{4}\d{4}$/ (11 chars: 138****1234).
 *
 * See packages/contracts/src/distribution.ts:MaskedPhone. The shape is locked
 * by the distribution module's masked_phone / referee_masked_phone fields.
 */
final class HelperMaskingTest extends TestCase
{
    public function testMainlandPhoneRenders11CharMask(): void
    {
        self::assertSame('139****1234', maskPhone('13912341234'));
        self::assertSame('158****5678', maskPhone('15800005678'));
        self::assertSame('188****9999', maskPhone('18888889999'));
    }

    public function testMaskedStringMatchesContractRegex(): void
    {
        $masked = maskPhone('13900000001');
        self::assertSame(11, strlen($masked));
        self::assertSame(1, preg_match('/^1[3-9]\d\*{4}\d{4}$/', $masked));
    }

    public function testEmptyReturnsDashSentinel(): void
    {
        // ponytail: empty input is the controller's "no phone yet" path.
        // Returning a regex-shaped string would falsely imply "1X********0000"
        // — the dash is the explicit "no value" signal.
        self::assertSame('—', maskPhone(''));
    }

    public function testAlreadyMaskedOrJunkPassesThroughUnchanged(): void
    {
        // Already-masked: do not double-mask.
        self::assertSame('139****1234', maskPhone('139****1234'));
        // Non-mainland / non-digit: pass through rather than corrupt.
        self::assertSame('not-a-phone', maskPhone('not-a-phone'));
    }
}