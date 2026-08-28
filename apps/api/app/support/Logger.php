<?php
declare(strict_types=1);

namespace App\support;

/**
 * Structured request logger (FR-093).
 *
 * One static facade over Monolog. Emits JSON lines to stdout (INFO+) and
 * stderr (WARNING+). Never logs passwords, tokens, captcha answers, payment
 * credentials or learner phones. Logs only hashed token fingerprints and
 * captcha IDs.
 */
final class Logger
{
    private static ?\Monolog\Logger $log = null;

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::log()->info($message, self::scrub($context));
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::log()->warning($message, self::scrub($context));
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::log()->error($message, self::scrub($context));
    }

    private static function log(): \Monolog\Logger
    {
        if (self::$log !== null) {
            return self::$log;
        }
        $log       = new \Monolog\Logger('app');
        $stream    = new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::INFO);
        $stream->setFormatter(new \Monolog\Formatter\JsonFormatter());
        $log->pushHandler($stream);
        $err       = new \Monolog\Handler\StreamHandler('php://stderr', \Monolog\Logger::WARNING);
        $err->setFormatter(new \Monolog\Formatter\JsonFormatter());
        $log->pushHandler($err);
        return self::$log = $log;
    }

    /** Strip secrets even if a caller forgets. Defence-in-depth. */
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function scrub(array $context): array
    {
        $blocked = ['password', 'captcha_answer', 'token', 'access_token', 'refresh_token',
                    'phone', 'payment_credential', 'wechat_pay_secret', 'card_no'];
        foreach ($blocked as $key) {
            if (array_key_exists($key, $context)) {
                $context[$key] = '[redacted]';
            }
        }
        return $context;
    }
}
