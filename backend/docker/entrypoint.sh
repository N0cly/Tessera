#!/bin/sh
# Backend container entrypoint.
#
# Idempotent setup steps so a fresh `docker compose up` from a clean clone
# leaves the stack working without any manual commands:
#   - Generate the JWT keypair if missing OR if it can't be decrypted with
#     the current JWT_PASSPHRASE (covers the case where the operator rotated
#     the passphrase — old tokens become invalid, which is the correct
#     security behavior).
#   - Run Doctrine migrations when RUN_MIGRATIONS=1 (set on the `backend`
#     service in docker-compose; worker leaves it off to avoid races).
#
# Exec's the actual CMD at the end so PID 1 is the real process and
# signals reach it cleanly.
set -e

cd /app

mkdir -p /app/config/jwt
chmod 0700 /app/config/jwt 2>/dev/null || true

regen_keys=0
if [ ! -f /app/config/jwt/private.pem ] || [ ! -f /app/config/jwt/public.pem ]; then
    regen_keys=1
else
    if ! php -r '
        $key = openssl_pkey_get_private(
            file_get_contents("/app/config/jwt/private.pem"),
            getenv("JWT_PASSPHRASE") ?: ""
        );
        exit($key === false ? 1 : 0);
    ' >/dev/null 2>&1; then
        echo "[entrypoint] existing JWT key cannot be decrypted with the current JWT_PASSPHRASE; regenerating."
        rm -f /app/config/jwt/private.pem /app/config/jwt/public.pem
        regen_keys=1
    fi
fi

if [ "$regen_keys" = "1" ]; then
    echo "[entrypoint] generating JWT keypair…"
    php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    echo "[entrypoint] running Doctrine migrations…"
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

exec "$@"
