FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-libs libzip freetype libjpeg-turbo libpng \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev freetype-dev libjpeg-turbo-dev libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd opcache \
    && apk del .build-deps

COPY php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html
