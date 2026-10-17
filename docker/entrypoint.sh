#!/bin/sh
set -e

HOST_UID=${HOST_UID:-1000}
HOST_GID=${HOST_GID:-1000}

# Remap www-data to match host UID/GID so bind-mounted files are accessible
sed -i "s/^www-data:x:[0-9]*:[0-9]*:/www-data:x:$HOST_UID:$HOST_GID:/" /etc/passwd
sed -i "s/^www-data:x:[0-9]*:/www-data:x:$HOST_GID:/" /etc/group

# Ensure key directories exist and are writable
mkdir -p /app/var/cache /app/var/log /app/public/assets
chown -R www-data:www-data /app/var /app/public/assets 2>/dev/null || true

# --- First-run setup (idempotent) ---

if [ ! -f /app/vendor/autoload.php ]; then
    echo "--> [setup] Running composer install..."
    su-exec www-data composer install --no-interaction --prefer-dist
fi

su-exec www-data php bin/console doctrine:database:create --if-not-exists --no-interaction 2>/dev/null || true
su-exec www-data php bin/console doctrine:migrations:migrate --no-interaction 2>/dev/null || true

if [ ! -f /app/public/assets/importmap.json ]; then
    echo "--> [setup] Running importmap:install..."
    su-exec www-data php bin/console importmap:install --no-interaction
fi

# Supervisord must stay root so it can open /dev/stdout dispatchers
# (a root-owned pipe); it drops each program to www-data via "user=".
exec "$@"
