#!/usr/bin/env bash
#
# Deploys the latest master on the server.
# Run from anywhere: bash /path/to/crm-project/deploy.sh
#
# Steps: pull, install dependencies, build assets, migrate, rebuild caches,
# restart Horizon, then check the production .env.
# On any error the script stops and leaves the app in maintenance mode,
# so a half-finished deploy is never served. Fix the problem, then run it again
# (or `php artisan up` if you are sure the app is fine).

set -euo pipefail

cd "$(dirname "$0")"

step() {
    echo
    echo "==> $1"
}

on_error() {
    echo
    echo "DEPLOY FAILED at line $1. The app is still in maintenance mode."
    echo "Fix the problem and run deploy.sh again, or run 'php artisan up' if the app is safe to serve."
}
trap 'on_error $LINENO' ERR

step "Pulling the latest code"
git pull --ff-only

step "Maintenance mode"
php artisan down --retry=60 || true

step "PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

step "Frontend assets"
npm ci
npm run build

step "Database migrations"
php artisan migrate --force

step "Caches"
php artisan optimize:clear
php artisan optimize
php artisan filament:optimize

step "Restarting Horizon (the process manager starts it again with the new code)"
php artisan horizon:terminate

step "Back online"
php artisan up

trap - ERR

step "Production .env check"
if ! bash ./check-production-env.sh; then
    echo
    echo "WARNING: the .env check found problems (see above). The deploy itself succeeded."
    exit 1
fi

echo
echo "Deploy finished."
