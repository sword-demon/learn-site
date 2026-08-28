<?php
declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use support\Redis;

/**
 * CaptchaService — one-time graphical captcha (FR-091).
 *
 *   captcha:{id}  →  normalized answer     TTL 120s
 *
 * Rules:
 *  - The answer is normalized: lowercased and whitespace stripped before
 *    storage AND comparison. We deliberately do NOT compare on the wire —
 *    server is the only judge.
 *  - consume() deletes the key on success AND on failure: one-time use, full
 *    stop. Successful login also consumes the captcha.
 *  - Redis unreachable → fail closed (no captcha issued / no verification).
 *  - Image data is a small PNG generated via GD. If GD is unavailable we
 *    fail closed (FR-091: graphical captcha is mandatory, not optional).
 */
final class CaptchaService
{
    private const PREFIX = 'captcha:';
    private const TTL    = 120;

    /** @return array{captcha_id: string, image: string, ttl_seconds: int}|null */
    public function issue(): ?array
    {
        $redis = $this->redis();
        if ($redis === null) {
            return null;
        }
        if (!function_exists('imagecreatetruecolor')) {
            Logger::error('captcha.gd_missing');
            return null;
        }
        $id     = bin2hex(random_bytes(8));
        $answer = $this->generateAnswer();
        $redis->setex(self::PREFIX . $id, self::TTL, strtolower($answer));
        return ['captcha_id' => $id, 'image' => $this->renderImage($answer), 'ttl_seconds' => self::TTL];
    }

    /**
     * Verify and consume. Returns true iff the captcha is valid (correct and
     * unexpired). Deletes the key in every code path.
     *
     * Important: per FR-091 / SC-022 the captcha is one-time even on the
     * FAILURE path — a wrong answer burns the captcha so attackers can't
     * grind by guessing a 1-character typo first.
     */
    public function consume(string $captchaId, string $answer): bool
    {
        $redis = $this->redis();
        if ($redis === null) {
            return false;
        }
        $key   = self::PREFIX . $captchaId;
        $stored = $redis->get($key);
        $redis->del($key);
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        $expected = preg_replace('/\s+/', '', strtolower($stored));
        $given    = preg_replace('/\s+/', '', strtolower($answer));
        return is_string($given) && hash_equals($expected, (string) $given);
    }

    private function generateAnswer(): string
    {
        // 4-char alphanumeric; ambiguity reduced by skipping 0/O/1/I/l.
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $len      = strlen($alphabet);
        $out      = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= $alphabet[random_int(0, $len - 1)];
        }
        return $out;
    }

    private function renderImage(string $answer): string
    {
        $w = 120; $h = 40;
        $im = imagecreatetruecolor($w, $h);
        $bg  = imagecolorallocate($im, 245, 245, 245);
        $fg  = imagecolorallocate($im, 30, 30, 30);
        $muted = imagecolorallocate($im, 180, 180, 180);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);
        // noise dots
        for ($i = 0; $i < 60; $i++) {
            imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $muted);
        }
        // scramble text into the image
        $x = 12;
        foreach (str_split($answer) as $ch) {
            $y = random_int(8, 22);
            imagestring($im, 5, $x, $y, $ch, $fg);
            $x += random_int(18, 24);
        }
        // single scratch line
        imageline($im, 0, random_int(10, 30), $w, random_int(10, 30), $muted);
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    private function redis(): ?object
    {
        if (class_exists(\Tests\RedisStub::class) && \Tests\RedisStub::$instance !== null) {
            $stub = \Tests\RedisStub::$instance;
            if ($stub->stayDown || $stub->downOnNextCall) {
                $stub->downOnNextCall = false;
                return null;
            }
            return $stub;
        }
        try {
            return Redis::connection('default');
        } catch (\Throwable $e) {
            Logger::error('redis.unavailable', ['err' => $e->getMessage()]);
            return null;
        }
    }
}
