FROM php:8.4-fpm-alpine

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    XDG_CONFIG_HOME=/app/var/caddy/config \
    XDG_DATA_HOME=/app/var/caddy/data

RUN apk add --no-cache \
    zip unzip \
    libzip-dev \
    freetype-dev libjpeg-turbo-dev libpng-dev \
    caddy poppler-utils supervisor \
    && apk add --no-cache su-exec \
    && apk add --no-cache unrar-free --repository=https://dl-cdn.alpinelinux.org/alpine/edge/testing \
    && docker-php-ext-install zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd

# RAR5-capable archive reader; unrar-free only decodes pre-RAR5 archives.
# ConversionService prefers this extension over the unrar CLI fallback.
# Built from git: PECL's latest release (4.2.0) predates the PHP 8.4 API.
# Pinned to a commit so the build does not change silently when upstream moves.
ARG PHP_RAR_COMMIT=9c8fcd9ebc9feaf36f945d6d7407fdcd57b7136f
RUN apk add --no-cache --virtual .rar-build-deps $PHPIZE_DEPS git \
    && git init -q /tmp/php-rar \
    && cd /tmp/php-rar \
    && git fetch -q --depth 1 https://github.com/cataphract/php-rar "$PHP_RAR_COMMIT" \
    && git checkout -q FETCH_HEAD \
    && phpize -q >/dev/null \
    && ./configure -q >/dev/null \
    && make -j"$(nproc)" >/dev/null \
    && make install >/dev/null \
    && docker-php-ext-enable rar \
    && apk del .rar-build-deps \
    && rm -rf /tmp/php-rar

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# CLI fallback for CBR conversion (ConversionService calls `unrar`).
RUN ln -s /usr/bin/unrar-free /usr/local/bin/unrar \
    && test -x /usr/local/bin/unrar

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --no-scripts

COPY --chown=www-data:www-data . .

COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer dump-env prod

RUN mkdir -p var/cache/prod var/log public/assets "${XDG_CONFIG_HOME}" "${XDG_DATA_HOME}" \
    && chown -R www-data:www-data /app/var /app/public/assets

RUN su-exec www-data php bin/console importmap:install \
    && su-exec www-data php bin/console asset-map:compile

RUN test -s public/assets/manifest.json \
    && test -s public/assets/importmap.json \
    && test -s public/assets/entrypoint.app.json

COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/supervisord.conf /etc/supervisord.conf
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]

EXPOSE 8080

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
