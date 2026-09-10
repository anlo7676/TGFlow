FROM php:7.4-fpm-alpine
RUN apk add --no-cache $PHPIZE_DEPS linux-headers oniguruma-dev \
    && docker-php-ext-install bcmath mbstring mysqli opcache pdo_mysql \
    && pecl install redis-5.3.7 \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS linux-headers oniguruma-dev
WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts
COPY . .
COPY docker/init.sh /usr/local/bin/tgflow-init
RUN composer dump-autoload --optimize \
    && chmod +x /usr/local/bin/tgflow-init \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php-fpm"]
