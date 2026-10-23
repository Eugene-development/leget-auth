#!/bin/sh
set -eu
cd /var/www
# Production settings arrive through env_file and secrets; never generate keys here.
: "${APP_KEY:?Set the permanent APP_KEY before starting the container}"
# Validate without displaying or changing the encryption key.
php -r '
$key = getenv("APP_KEY");
if (str_starts_with($key, "APP_KEY=")) {
    fwrite(STDERR, "Invalid APP_KEY: use only the key value, without a second APP_KEY= prefix.\n");
    exit(1);
}
$decoded = str_starts_with($key, "base64:") ? base64_decode(substr($key, 7), true) : $key;
if ($decoded === false || strlen($decoded) !== 32) {
    fwrite(STDERR, "Invalid APP_KEY: AES-256-CBC requires 32 bytes. Restore the existing auth key.\n");
    exit(1);
}
'
if [ -s /run/secrets/jwt_secret ]; then
    JWT_SECRET=$(tr -d '\r\n' < /run/secrets/jwt_secret)
    export JWT_SECRET
fi
: "${JWT_SECRET:?Set the shared API/auth JWT secret}"

if [ -s /run/secrets/form_context_secret ]; then
    FORM_CONTEXT_SECRET=$(tr -d '\r\n' < /run/secrets/form_context_secret)
    export FORM_CONTEXT_SECRET
fi
: "${FORM_CONTEXT_SECRET:?Set the shared form context secret}"
if [ "${#FORM_CONTEXT_SECRET}" -lt 32 ]; then
    echo "FORM_CONTEXT_SECRET must contain at least 32 characters" >&2
    exit 1
fi

mkdir -p storage/logs storage/framework/sessions storage/framework/views storage/framework/cache/data bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
# Cache belongs to this container, never to a shared volume.
rm -f bootstrap/cache/config.php
# Compatibility for the old scheduler role; new Compose uses an explicit command.
if [ "${CONTAINER_ROLE:-}" = scheduler ]; then
    set -- php artisan schedule:work
fi
if [ "$#" -eq 0 ]; then set -- php-fpm; fi
if [ "$1" = php-fpm ]; then
    exec "$@"
fi
exec su-exec www-data "$@"
