<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * maskPhone() — wire-format contract: must match the Zod MaskedPhone regex
 * /^1[3-9]\*{8}\d{4}$/ (14 chars: 1 prefix + 8 stars + 4 digits).
 *
 * See packages/contracts/src/distribution.ts:MaskedPhone. The shape is locked
 * by the distribution module's masked_phone / referee_masked_phone fields.
 */
final class HelperMaskingTest extends TestCase
{
    public function testMainlandPhoneRenders14CharMask(): void
    {
        self::assertSame('13********1234', maskPhone('13912341234'));
        self::assertSame('15********5678', maskPhone('15800005678'));
        self::assertSame('18********9999', maskPhone('18888889999'));
    }

    public function testMaskedStringIsExactly14CharsAndMatchesContractRegex(): void
    {
        $masked = maskPhone('13900000001');
        self::assertSame(14, strlen($masked));
        self::assertSame(1, preg_match('/^1[3-9]\*{8}\d{4}$/', $masked));
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
        self::assertSame('13********1234', maskPhone('13********1234'));
        // Non-mainland / non-digit: pass through rather than corrupt.
        self::assertSame('not-a-phone', maskPhone('not-a-phone'));
    }
}