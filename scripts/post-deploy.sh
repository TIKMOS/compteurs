#!/usr/bin/env bash
# Mise en production (README, « Mise en production ») : à lancer après chaque `git pull`.
# Idempotent : peut être relancé sans risque.
set -euo pipefail

cd "$(dirname "$0")/.."

if grep -qE '^APP_DEBUG=true' .env; then
    echo "Refus : APP_DEBUG=true dans .env. Mettez APP_DEBUG=false en production." >&2
    exit 1
fi

php artisan down --retry=30 || true

composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

php artisan up
echo "Mise en production terminée."
