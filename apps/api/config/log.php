<?php
/**
 * JSON structured logging (FR-093) — request_id + actor + action + result.
 *
 * Writes one JSON object per line to stdout/stderr. The structured logger is
 * the ONLY logger the application uses. No raw stack traces leak to clients;
 * server-side context (request_id, account_id, action) is preserved for audit.
 */

return [
    'default' => [
        'handlers' => [
            [
                'class'    => \Monolog\Handler\StreamHandler::class,
                'constructor' => [
                    'stream' => 'php://stdout',
                    'level'  => \Monolog\Logger::INFO,
                    'bubble' => false,
                ],
                'formatter' => [
                    'class'   => \Monolog\Formatter\JsonFormatter::class,
                    'constructor' => [
                        'batchMode' => \Monolog\Formatter\JsonFormatter::BATCH_MODE_NEWLINES,
                        'appendNewline' => true,
                    ],
                ],
            ],
            [
                'class'    => \Monolog\Handler\StreamHandler::class,
                'constructor' => [
                    'stream' => 'php://stderr',
                    'level'  => \Monolog\Logger::WARNING,
                    'bubble' => false,
                ],
                'formatter' => [
                    'class'   => \Monolog\Formatter\JsonFormatter::class,
                    'constructor' => [
                        'batchMode' => \Monolog\Formatter\JsonFormatter::BATCH_MODE_NEWLINES,
                        'appendNewline' => true,
                    ],
                ],
            ],
        ],
    ],
];