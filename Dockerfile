# syntax=docker/dockerfile:1
FROM php:8.4-fpm-alpine AS php-base
RUN --mount=type=secret,id=build_ca_bundle --mount=type=bind,source=resources/operations/php.ini,target=/setup-php.ini --mount=type=bind,source=resources/operations/fpm.conf,target=/setup-fpm.conf \
    if [ -s /run/secrets/build_ca_bundle ]; then export SSL_CERT_FILE=/run/secrets/build_ca_bundle; fi; \
    apk add --no-cache libpq oniguruma libxml2 libzip libcurl \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev oniguruma-dev libxml2-dev libzip-dev curl-dev linux-headers \
    && docker-php-ext-install -j2 pdo_pgsql mbstring dom pcntl opcache zip curl \
    && apk del .build-deps \
    && cp /setup-php.ini /usr/local/etc/php/conf.d/99-kardorost.ini \
    && cp /setup-fpm.conf /usr/local/etc/php-fpm.d/zz-kardorost.conf \
    && chmod 644 /usr/local/etc/php/conf.d/99-kardorost.ini /usr/local/etc/php-fpm.d/zz-kardorost.conf
WORKDIR /var/www/html

FROM composer/composer:2-bin AS composer-bin

FROM php-base AS backend
RUN --mount=type=bind,source=.,target=/source \
    --mount=type=bind,from=composer-bin,source=/composer,target=/usr/local/bin/composer \
    --mount=type=cache,target=/root/.composer/cache --mount=type=secret,id=build_ca_bundle \
    if [ -s /run/secrets/build_ca_bundle ]; then export COMPOSER_CAFILE=/run/secrets/build_ca_bundle; fi; \
    cp -a /source/. /var/www/html/ \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
    && composer check-platform-reqs --no-dev

FROM node:24-alpine AS frontend
RUN --mount=type=bind,source=.,target=/source --mount=type=cache,target=/root/.npm --mount=type=secret,id=build_ca_bundle \
    if [ -s /run/secrets/build_ca_bundle ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/build_ca_bundle; fi; \
    mkdir -p /app && cd /app \
    && cp /source/package.json /source/package-lock.json . \
    && npm ci --no-audit --no-fund \
    && cp -a /source/resources /source/public /source/vite.config.js . \
    && npm run build

FROM postgres:17-alpine AS database
ARG APP_REVISION=unknown
LABEL org.opencontainers.image.source="https://github.com/vandaschool/kardorost-platform3" \
      org.opencontainers.image.revision="$APP_REVISION"
COPY --chmod=755 resources/operations/postgres-init.sh /docker-entrypoint-initdb.d/10-kardorost.sh

FROM php-base AS application
ARG APP_REVISION=unknown
LABEL org.opencontainers.image.source="https://github.com/vandaschool/kardorost-platform3" \
      org.opencontainers.image.revision="$APP_REVISION"
RUN --mount=type=bind,from=backend,source=/var/www/html,target=/backend \
    --mount=type=bind,from=frontend,source=/app/public/build,target=/frontend \
    cp -a /backend/. /var/www/html/ \
    && cp -a /frontend /var/www/html/public/build \
    && cp resources/operations/entrypoint.sh /usr/local/bin/kardorost-entrypoint \
    && chmod 755 /usr/local/bin/kardorost-entrypoint \
    && mkdir -p storage/app/private storage/app/learning-private storage/app/support-private \
        storage/app/receipts-private storage/app/refunds-private storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R u=rwX,g=rX,o= storage bootstrap/cache
USER www-data
ENTRYPOINT ["kardorost-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginxinc/nginx-unprivileged:1.28-alpine AS web
ARG APP_REVISION=unknown
LABEL org.opencontainers.image.source="https://github.com/vandaschool/kardorost-platform3" \
      org.opencontainers.image.revision="$APP_REVISION"
USER root
RUN --mount=type=bind,from=application,source=/var/www/html/public,target=/compiled-public \
    --mount=type=bind,source=resources/operations/nginx.conf,target=/compiled-nginx.conf \
    rm -rf /usr/share/nginx/html/* \
    && cp -a /compiled-public/. /usr/share/nginx/html/ \
    && cp /compiled-nginx.conf /etc/nginx/conf.d/default.conf \
    && chmod 644 /etc/nginx/conf.d/default.conf \
    && chown -R nginx:nginx /usr/share/nginx/html \
    && chmod -R u=rwX,go=rX /usr/share/nginx/html
USER nginx
