#!/bin/sh
set -e

if [ "$(id -u)" = "0" ]; then
    # Started as root (docker compose dev setup): remap www-data to the host user so
    # bind-mounted files stay accessible, and run the app commands as www-data.
    HOST_UID=${HOST_UID:-1000}
    HOST_GID=${HOST_GID:-1000}

    sed -i "s/^www-data:x:[0-9]*:[0-9]*:/www-data:x:$HOST_UID:$HOST_GID:/" /etc/passwd
    sed -i "s/^www-data:x:[0-9]*:/www-data:x:$HOST_GID:/" /etc/group

    RUN="su-exec www-data"
else
    # Started as a non-root user (TrueNAS apps run the image as UID 568): the volume
    # must already be writable by that user, and supervisord runs without privileges.
    RUN=""
    if [ "$1" = "supervisord" ]; then
        set -- supervisord -c /etc/supervisord-nonroot.conf
    fi
fi

# Ensure key directories exist and are writable
mkdir -p /app/var/cache /app/var/log /app/public/assets
if [ -n "$RUN" ]; then
    chown -R www-data:www-data /app/var /app/public/assets 2>/dev/null || true
fi

# --- First-run setup (idempotent) ---

if [ ! -f /app/vendor/autoload.php ]; then
    echo "--> [setup] Running composer install..."
    $RUN composer install --no-interaction --prefer-dist
fi

$RUN php bin/console doctrine:database:create --if-not-exists --no-interaction 2>/dev/null || true
$RUN php bin/console doctrine:migrations:migrate --no-interaction 2>/dev/null || true

if [ ! -f /app/public/assets/importmap.json ]; then
    echo "--> [setup] Running importmap:install..."
    $RUN php bin/console importmap:install --no-interaction
fi

# Supervisord must stay root so it can open /dev/stdout dispatchers
# (a root-owned pipe); it drops each program to www-data via "user=".
exec "$@"
