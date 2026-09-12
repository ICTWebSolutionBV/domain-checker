#!/usr/bin/env bash
#
# Deploy script for Domain Checker (Ploi).
#
# Ploi's own deploy script should be exactly:
#
#     cd {SITE_DIRECTORY}
#     git pull origin main
#     bash deploy.sh
#
# Keeping the steps in a tracked file means they can be reviewed, diffed and
# corrected in a pull request. The three production failures of 2026-04-20
# were all debugged by editing prose in the README.
#
# File permissions are deliberately NOT set here. They are one-time server
# setup, not something to re-apply on every deploy:
#
#     sudo chown -R ploi:www-data storage bootstrap/cache
#     sudo chmod -R 2775 storage bootstrap/cache
#
# The setgid bit (the 2) makes every file created later inherit the group, so
# both the deploy user and PHP-FPM keep write access without `chmod -R 777`.
# With ACLs instead:
#
#     sudo setfacl -R -m u:www-data:rwX -m d:u:www-data:rwX storage bootstrap/cache
#
set -Eeuo pipefail

cd "$(dirname "$0")"

# Read a key from .env without sourcing it (values may contain spaces, #, $).
env_value() {
    local key="$1"
    local line
    line=$(grep -E "^${key}=" .env | tail -n 1 || true)
    line="${line#*=}"
    line="${line%\"}"
    line="${line#\"}"
    printf '%s' "$line"
}

APP_URL="${APP_URL:-$(env_value APP_URL)}"

echo "==> Directories"
# Must exist before composer/npm run, or their post-install steps fail.
mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs storage/backups

echo "==> Clear stale compiled files"
# The tree is about to change under a running site; compiled config and routes
# from the previous release must not survive into the new code.
php artisan optimize:clear

echo "==> Dependencies"
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
npm ci
npm run build

echo "==> Database"
DB_CONNECTION="$(env_value DB_CONNECTION)"
if [ "${DB_CONNECTION}" = "mysql" ] || [ "${DB_CONNECTION}" = "mariadb" ]; then
    DUMP="storage/backups/$(date +%Y-%m-%d-%H%M%S).sql.gz"
    echo "    dumping to ${DUMP}"
    # A dump taken before the migration is the only thing that makes a bad
    # migration recoverable; this repo has already shipped a table drop.
    MYSQL_PWD="$(env_value DB_PASSWORD)" mysqldump \
        --host="$(env_value DB_HOST)" \
        --port="$(env_value DB_PORT)" \
        --user="$(env_value DB_USERNAME)" \
        --single-transaction --quick --routines --events \
        "$(env_value DB_DATABASE)" | gzip >"${DUMP}"
    # Keep two weeks of dumps; they are full copies of a database holding
    # passkey credentials and 2FA secrets, so they do not accumulate forever.
    find storage/backups -name '*.sql.gz' -mtime +14 -delete
else
    echo "    DB_CONNECTION=${DB_CONNECTION}: no dump taken, back this up yourself"
fi

# Migrations run against the old code while it is still serving traffic, so a
# column rename or drop would 500 every request until the new code is live.
php artisan down --retry=60
trap 'php artisan up' EXIT
php artisan migrate --force
php artisan up
trap - EXIT

echo "==> Caches"
# config + routes + views + events. Views are cached again now that the
# permissions are fixed once on the server instead of chmod 777 per deploy:
# world-writable compiled Blade under storage/framework/views is executable
# PHP that any other account on the box could replace.
php artisan optimize
php artisan storage:link
# No queue worker is needed today (mail is sent synchronously and there are no
# jobs), but restarting is free and correct the moment one exists.
php artisan queue:restart

echo "==> Health check"
# `optimize` can succeed and still leave the site 500-ing, for instance on a
# missing env var. Fail the deploy loudly instead of printing "deployed".
curl -fsS --max-time 10 "${APP_URL%/}/up" >/dev/null

echo "Application deployed."
