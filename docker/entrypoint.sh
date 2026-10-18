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

# --- Application secret: one random value per installation, kept in the persistent /app/var ---
# APP_SECRET set explicitly wins. The example value from .env is refused, so it can never be used.
EXAMPLE_SECRET='change-me-generate-with-openssl-rand-hex-32'
SECRET_FILE=/app/var/app_secret

if [ -z "${APP_SECRET:-}" ]; then
    if [ ! -s "$SECRET_FILE" ]; then
        echo "--> [setup] Generating the application secret..."
        head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$SECRET_FILE"
        chmod 600 "$SECRET_FILE"
        chown www-data:www-data "$SECRET_FILE"
    fi
    APP_SECRET=$(cat "$SECRET_FILE")
    export APP_SECRET
fi

if [ "$APP_SECRET" = "$EXAMPLE_SECRET" ]; then
    echo "APP_SECRET is still the example value from .env. Unset it or set your own." >&2
    exit 1
fi

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
