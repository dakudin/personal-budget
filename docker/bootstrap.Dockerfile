# Temporary Composer container for restoring vendor without host PHP.
FROM composer:2@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0

USER root
RUN apk add --no-cache icu-dev libpng-dev \
    && docker-php-ext-install -j"$(nproc)" intl gd bcmath pdo_mysql exif

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /app
