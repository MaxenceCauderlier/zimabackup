#!/bin/sh
set -eu

mkdir -p \
    /var/www/html/storage/cache \
    /var/www/html/storage/logs \
    /var/www/html/storage/secrets/repositories

# The bind-mounted storage directory may be created by Docker as root.
# Best effort only: some NAS setups intentionally restrict chmod/chown.
chown -R www-data:www-data /var/www/html/storage 2>/dev/null || true

su -s /bin/sh -c "php /var/www/html/bin/migrate.php" www-data

exec "$@"
