# syntax=docker/dockerfile:1.7

# The chialab image ships Composer and the same PHP extensions used at runtime,
# so dependency resolution validates the actual production platform.
FROM chialab/php:8.2-fpm AS composer-deps
WORKDIR /src
COPY composer.json composer.lock ./
RUN composer config -g repos.packagist composer https://mirrors.cloud.tencent.com/composer/
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM node:22-alpine AS frontend-build
WORKDIR /src/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM chialab/php:8.2-fpm AS runtime

COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

RUN set -eux; \
    if [ -f /etc/apt/sources.list.d/debian.sources ]; then \
      sed -i 's|deb.debian.org|mirrors.tencentyun.com|g; s|security.debian.org|mirrors.tencentyun.com|g' /etc/apt/sources.list.d/debian.sources; \
    fi; \
    if [ -f /etc/apt/sources.list ]; then \
      sed -i 's|deb.debian.org|mirrors.tencentyun.com|g; s|security.debian.org|mirrors.tencentyun.com|g' /etc/apt/sources.list; \
    fi; \
    apt-get update; \
    apt-get install -y --no-install-recommends ffmpeg; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=composer-deps /src/vendor ./vendor
COPY . ./
COPY --from=frontend-build /src/public/admin ./public/admin

RUN mkdir -p runtime/log runtime/cache runtime/temp runtime/session public/storage \
    && chown -R www-data:www-data runtime public/storage

FROM nginx:1.27-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=runtime /var/www/html/public /var/www/html/public
