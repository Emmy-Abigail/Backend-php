FROM php:8.4-cli-bookworm

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

RUN apt-get update \
    && apt-get install --yes --no-install-recommends libzip-dev libpng-dev libjpeg62-turbo-dev unzip \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo_mysql zip gd \
    && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress

COPY . .
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

RUN sed -i 's/\r$//' docker/entrypoint.sh \
    && chmod +x docker/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/var/www/docker/entrypoint.sh"]
