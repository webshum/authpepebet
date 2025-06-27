FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    libbz2-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    redis-tools && \
    docker-php-ext-install \
    pdo \
    pdo_mysql \
    bcmath \
    zip \
    gd

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY ./docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www
