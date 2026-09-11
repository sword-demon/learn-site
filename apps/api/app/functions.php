<?php

declare(strict_types=1);

/**
 * Tiny helpers for the distribution module. Kept here so every service /
 * controller that masks a phone or a short code routes through one function
 * instead of inlining a regex. ponytail: one definition per transformation,
 * one test can pin the format.
 */

if (!function_exists('maskPhone')) {
    /**
     * Mask a mainland-China phone per FR-024. Wire shape: 138****1234
     * (`^1[3-9]\d\*{4}\d{4}$`). Returns "—" for empty input.
     */
    function maskPhone(string $phone): string
    {
        if ($phone === '') {
            return '—';
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return $phone;
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }
}

if (!function_exists('maskShortCode')) {
    /**
     * Render a 12-char share short code as the SC-009 export shape:
     * `ABCD****WXYZ`. Confusable chars 0/1/I/O are not in the alphabet, so
     * masking by index is safe regardless of source.
     */
    function maskShortCode(string $code): string
    {
        if (strlen($code) !== 12) {
            return str_repeat('*', 12);
        }
        return substr($code, 0, 4) . '****' . substr($code, -4);
    }
}

if (!function_exists('todayDate')) {
    function todayDate(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->format('Y-m-d');
    }
}

if (!function_exists('nowDatetime')) {
    function nowDatetime(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->format('Y-m-d H:i:s');
    }
}

if (!function_exists('toIso8601')) {
    /**
     * Render a Unix-seconds int (or null) as the wire format used everywhere
     * distribution contracts appear: ISO-8601 with the Asia/Shanghai offset.
     */
    function toIso8601(?int $unixSeconds): ?string
    {
        if ($unixSeconds === null) {
            return null;
        }
        return (new \DateTimeImmutable('@' . $unixSeconds))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
            ->format(\DateTimeInterface::ATOM);
    }
}
