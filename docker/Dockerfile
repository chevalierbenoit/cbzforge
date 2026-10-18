# unrar: reads RAR archives (CBR). Built from RARLAB's official source, pinned by checksum,
# so the image does not depend on a distribution package. The source may be used and
# distributed freely; see the licence in the unrar source tree.
FROM php:8.4-fpm-alpine AS unrar

ARG UNRAR_VERSION=7.3.1
ARG UNRAR_SHA256=634900842a3737d9cc15bbcc71d4c74cc713437e0bca296a573424fe5f2660ab

RUN apk add --no-cache g++ make \
    && wget -qO /tmp/unrar.tar.gz "https://www.rarlab.com/rar/unrarsrc-${UNRAR_VERSION}.tar.gz" \
    && echo "${UNRAR_SHA256}  /tmp/unrar.tar.gz" | sha256sum -c - \
    && mkdir /tmp/unrar-src \
    && tar -xzf /tmp/unrar.tar.gz -C /tmp/unrar-src --strip-components=1 \
    && make -C /tmp/unrar-src -f makefile CXX=g++ \
    && install -m 0755 /tmp/unrar-src/unrar /usr/local/bin/unrar \
    && test -x /usr/local/bin/unrar

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
    curl \
    libstdc++ \
    && apk add --no-cache su-exec \
    && docker-php-ext-install zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd

# Run as UID/GID 568 (the TrueNAS apps user). The entrypoint still remaps www-data
# to HOST_UID/HOST_GID when the container starts as root (docker compose dev setup).
RUN sed -i 's/^www-data:x:82:82:/www-data:x:568:568:/' /etc/passwd \
    && sed -i 's/^www-data:x:82:/www-data:x:568:/' /etc/group \
    && test "$(id -u www-data)" = 568

COPY --from=unrar /usr/local/bin/unrar /usr/local/bin/unrar

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

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
COPY docker/supervisord-nonroot.conf /etc/supervisord-nonroot.conf
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]

EXPOSE 8080

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
