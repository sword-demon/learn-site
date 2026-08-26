# Webman 2.2 API image. PHP 8.4-cli digest is pinned (Constitution II).
# Official run command: php start.php start  (foreground; do not pass -d in containers)
# https://www.workerman.net/doc/webman/install.html

ARG PHP_IMAGE=php:8.4-cli@sha256:f78661b492226388a7057679cc731c3e43bc92ba66cd49a8cfe12374a56bee9f

# ---- composer vendor ----
FROM ${PHP_IMAGE} AS vendor

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libssl-dev zlib1g-dev libzip-dev \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install -j$(nproc) zip \
 && curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
COPY apps/api/support ./support
COPY apps/api/start.php ./
COPY apps/api/config ./config
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader --ignore-platform-reqs --no-scripts

# ---- test vendor (includes phpunit / phpstan) ----
FROM vendor AS vendor-dev
RUN composer install --no-interaction --prefer-dist --no-progress --ignore-platform-reqs --no-scripts

# ---- runtime extensions ----
FROM ${PHP_IMAGE} AS php-ext

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        ca-certificates curl tzdata fonts-dejavu-core \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev \
        $PHPIZE_DEPS \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) gd zip bcmath pdo pdo_mysql sockets pcntl posix \
 && pecl install redis-6.2.0 \
 && docker-php-ext-enable redis opcache \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# ---- production runtime ----
FROM php-ext AS runtime

COPY --from=vendor /app/vendor /app/vendor
COPY apps/api /app
COPY docker/api/entrypoint.sh /usr/local/bin/api-entrypoint
RUN groupadd -r app && useradd -r -g app -u 10001 -d /app -s /usr/sbin/nologin app \
 && mkdir -p /app/runtime/logs /app/runtime/views /app/uploads \
 && chown -R app:app /app \
 && chmod +x /usr/local/bin/api-entrypoint \
 && chmod +x /app/start.php

ENV WEBMAN_WORKERS=2
EXPOSE 8787
STOPSIGNAL SIGTERM
ENTRYPOINT ["api-entrypoint"]
CMD ["php", "start.php", "start"]

# ---- test image: same runtime + dev vendor ----
FROM php-ext AS test

COPY --from=vendor-dev /app/vendor /app/vendor
COPY apps/api /app
RUN mkdir -p /app/runtime/logs /app/runtime/views
WORKDIR /app
CMD ["php", "vendor/bin/phpunit"]
