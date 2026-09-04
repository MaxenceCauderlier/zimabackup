FROM php:8.4-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libsqlite3-dev \
        restic \
        unzip \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite \
    && sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf \
    && sed -ri "s!/var/www/!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY . .

RUN mkdir -p \
        storage/cache \
        storage/logs \
        storage/secrets/repositories \
    && chown -R www-data:www-data storage

COPY docker/entrypoint.sh /usr/local/bin/zimabackup-entrypoint
RUN chmod +x /usr/local/bin/zimabackup-entrypoint

ENTRYPOINT ["zimabackup-entrypoint"]
CMD ["apache2-foreground"]
