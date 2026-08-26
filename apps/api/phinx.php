<?php

/**
 * Schema tooling only. Runtime queries use think-orm, not Phinx.
 */
$env = [
    'adapter' => 'mysql',
    'host' => getenv('DB_HOST') ?: 'mysql',
    'name' => getenv('DB_DATABASE') ?: 'learn_site',
    'user' => getenv('DB_USER') ?: 'learn_site',
    'pass' => getenv('DB_PASSWORD') ?: '',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

return [
    'paths' => [
        'migrations' => 'database/migrations',
        'seeds' => 'database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'runtime',
        'runtime' => $env,
    ],
];
