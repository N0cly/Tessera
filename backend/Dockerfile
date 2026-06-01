FROM php:8.4-cli-alpine

RUN apk add --no-cache \
        git \
        unzip \
        icu-dev \
        postgresql-dev \
        libpng-dev \
        linux-headers \
        autoconf \
        g++ \
        make \
    && docker-php-ext-install -j"$(nproc)" intl pdo pdo_pgsql opcache gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make linux-headers \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-scripts --no-interaction --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p var/cache var/log var/geoip config/jwt \
    && chmod -R 0777 var config/jwt \
    && chmod +x docker/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
