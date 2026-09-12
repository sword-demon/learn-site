<?php

return [
    '' => [
        \App\middleware\RequestLogger::class,
        \App\middleware\Cors::class,
        \App\middleware\ApiRequestAudit::class,
    ],
];
