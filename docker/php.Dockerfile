FROM chialab/php:8.2-fpm

COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*
