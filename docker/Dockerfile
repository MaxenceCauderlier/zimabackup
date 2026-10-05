FROM php:8.4-cli AS vendor

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /build

COPY composer.json ./
COPY src ./src

# The runtime image provides pdo_sqlite. The vendor stage only resolves PHP
# dependencies and builds the optimized autoloader.
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts \
    --ignore-platform-req=ext-pdo_sqlite

FROM php:8.4-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

LABEL org.opencontainers.image.title="ZimaBackup" \
      org.opencontainers.image.description="Application-aware backup and disaster recovery for ZimaOS" \
      org.opencontainers.image.licenses="GPL-3.0-or-later"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        libsqlite3-dev \
        restic \
        tzdata \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite \
    && sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf \
    && sed -ri "s!/var/www/!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /build/vendor ./vendor

RUN mkdir -p \
        storage/cache \
        storage/logs \
        storage/secrets/repositories \
        storage/secrets/application-installs \
        storage/manifests \
    && chown -R www-data:www-data storage

COPY docker/entrypoint.sh /usr/local/bin/zimabackup-entrypoint
RUN chmod +x /usr/local/bin/zimabackup-entrypoint

EXPOSE 80

ENTRYPOINT ["zimabackup-entrypoint"]
CMD ["apache2-foreground"]
