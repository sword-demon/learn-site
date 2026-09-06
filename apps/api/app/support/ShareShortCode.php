<?php

declare(strict_types=1);

namespace App\support;

/**
 * Crockford-style 32-symbol alphabet that excludes confusable glyphs
 * (0/O, 1/I/L). Used to mint short codes for `share_entries.short_code`.
 *
 * Length is fixed at 12 characters (per spec FR-024 / SC-009). At ~62 bits
 * of entropy that is plenty for a referral-link namespace that never sees
 * unauthenticated enumeration.
 */
final class ShareShortCode
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const LENGTH = 12;

    public static function generate(): string
    {
        // random_bytes is the only path that's safe for opaque tokens; the
        // mt_rand() default is too predictable here.
        $alphabetLen = strlen(self::ALPHABET);
        $output = '';
        $maxValid = (int) (256 - (256 % $alphabetLen));
        do {
            $bytes = random_bytes(self::LENGTH);
            $byteIdx = 0;
            while (strlen($output) < self::LENGTH && $byteIdx < strlen($bytes)) {
                $byte = ord($bytes[$byteIdx]);
                $byteIdx++;
                if ($byte >= $maxValid) {
                    continue; // reject bias
                }
                $output .= self::ALPHABET[$byte % $alphabetLen];
            }
        } while (strlen($output) < self::LENGTH);
        return $output;
    }

    public static function isValid(string $code): bool
    {
        if (strlen($code) !== self::LENGTH) {
            return false;
        }
        return preg_match('/^[A-HJ-NP-Z2-9]+$/', $code) === 1;
    }
}