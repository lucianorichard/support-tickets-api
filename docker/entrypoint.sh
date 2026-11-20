#!/bin/sh
set -e

# Environment variables from compose.yaml take precedence over .env; the file
# only provides the defaults and a stable APP_KEY for every process in the
# container (including `docker compose exec`). A per-container key is fine:
# API tokens are stored hashed in the database and do not depend on it.
[ -f .env ] || cp .env.example .env
if [ -z "$APP_KEY" ] && ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi

php artisan migrate --force

if [ "$SEED_DEMO_DATA" = "true" ]; then
    php artisan app:seed-demo-if-empty
fi

exec "$@"
