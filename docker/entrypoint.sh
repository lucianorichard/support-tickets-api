#!/bin/sh
set -e

# A random key per container is fine here: API tokens are stored hashed in the
# database and do not depend on APP_KEY.
if [ -z "$APP_KEY" ]; then
    APP_KEY="$(php artisan key:generate --show)"
    export APP_KEY
fi

php artisan migrate --force

if [ "$SEED_DEMO_DATA" = "true" ]; then
    php artisan app:seed-demo-if-empty
fi

exec "$@"
