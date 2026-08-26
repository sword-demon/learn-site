<?php

/**
 * Session bootstrap is omitted: login state is access/refresh tokens in Redis
 * (Constitution VI). ThinkOrm is required so models and Db::query work.
 */
return [
    Webman\ThinkOrm\ThinkOrm::class,
];
