<?php

declare(strict_types=1);

namespace Tests;

use App\support\ShareShortCode;
use PHPUnit\Framework\TestCase;

final class ShareShortCodeTest extends TestCase
{
    public function testLengthIsTwelve(): void
    {
        $code = ShareShortCode::generate();
        self::assertSame(12, strlen($code));
    }

    public function testUsesCrockfordAlphabet(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = ShareShortCode::generate();
            self::assertMatchesRegularExpression(
                '/^[A-HJ-NP-Z2-9]{12}$/',
                $code,
                "confusable character in $code",
            );
        }
    }

    public function testIsValidRejectsBadLength(): void
    {
        self::assertFalse(ShareShortCode::isValid('ABC'));
        self::assertFalse(ShareShortCode::isValid(''));
    }

    public function testIsValidRejectsConfusables(): void
    {
        // 0, 1, I, O, L are NOT in our alphabet; "I0L2A4B6C8D0" is 13 chars
        // and contains I/0/L — must fail.
        self::assertFalse(ShareShortCode::isValid('I0L2A4B6C8D0'));
        self::assertFalse(ShareShortCode::isValid('AAAAAAAAAAAAAAAA')); // ok
        self::assertFalse(ShareShortCode::isValid('AAAAA0AAAAAAA')); // 0 is out
        self::assertFalse(ShareShortCode::isValid('AAAAA1AAAAAAA')); // 1 is out
        self::assertFalse(ShareShortCode::isValid('AAAAAIAAAAAAA')); // I is out
        self::assertFalse(ShareShortCode::isValid('AAAAALAAAAAAA')); // L is out
        self::assertFalse(ShareShortCode::isValid('AAAAAOAAAAAAA')); // O is out
    }

    public function testGeneratedCodesAreUniqueAcross10000(): void
    {
        // 10000 samples at 62 bits of entropy → collision probability ~ 1e-10.
        // If this fails the alphabet or random_bytes is broken.
        $seen = [];
        for ($i = 0; $i < 10000; $i++) {
            $code = ShareShortCode::generate();
            self::assertArrayNotHasKey($code, $seen, "collision: $code at $i");
            $seen[$code] = true;
        }
    }
}