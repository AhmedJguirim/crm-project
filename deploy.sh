#!/usr/bin/env bash
#
# Deploys the latest master on the server.
# Run from anywhere: bash /path/to/crm-project/deploy.sh
#
# Steps, in this order:
#   0. preflight (nothing is changed): .env present, PostgreSQL, no DB_URL, pg_dump and flock installed,
#      backup folder outside the project, one deploy at a time
#   1. maintenance mode with a friendly page, 2. pause Horizon, 3. wait for running jobs (up to 1900 s),
#   4. git pull, 5. composer install, 6. npm build, 7. database dump (then keep the newest dumps only),
#   8. migrate, 9. rebuild caches (the cache store is kept: login lockouts and invitation caps survive),
#   10. production .env check, 11. restart Horizon, 12. back online.
# On any error after step 1 the script stops and leaves the app in maintenance mode with Horizon paused,
# so a half-finished deploy is never served. Fix the problem, then run it again.
#
# Shell variables:
#   DEPLOY_BACKUP_DIR   where the dumps go (default: $HOME/crm-backups); must be outside the project
#   DEPLOY_BACKUP_KEEP  how many dumps to keep (default: 5)
# The dump holds every organization's data: the file is mode 600 and the folder 700.
# Restore: pg_restore --clean --if-exists --no-owner --host=HOST --port=PORT --username=USER --dbname=DATABASE FILE

set -Eeuo pipefail

cd "$(dirname "$0")"

step() {
    echo
    echo "==> $1"
}

refuse() {
    echo "Deploy stopped: $1"
    exit 1
}

env_value() {
    local file=$1 key=$2 line value
    line=$(grep -E "^[[:space:]]*(export[[:space:]]+)?${key}[[:space:]]*=" "$file" 2>/dev/null | tail -n 1)
    if [ -z "$line" ]; then
        return 1
    fi
    value=${line#*=}
    value="${value#"${value%%[![:space:]]*}"}"
    case $value in
        \"*) value=${value#\"}; value=${value%%\"*} ;;
        \'*) value=${value#\'}; value=${value%%\'*} ;;
        *) value=${value%%[[:space:]]#*}; value="${value%"${value##*[![:space:]]}"}" ;;
    esac
    printf '%s' "$value"
}

on_error() {
    echo
    echo "DEPLOY FAILED at line $1. The app is still in maintenance mode and Horizon is paused."
    if [ -n "$backup_taken" ]; then
        echo "Database backup taken before the migrations: $backup_taken"
        echo "To restore it: pg_restore --clean --if-exists --no-owner --host=$db_host --port=$db_port --username=$db_username --dbname=$db_database $backup_taken"
    fi
    echo "Fix the problem and run deploy.sh again. To serve the current code without deploying: php artisan horizon:continue && php artisan up"
}

main() {
    local project_dir env_file db_connection db_url db_host db_port db_database db_username db_password
    local backup_dir backup_keep resolved_dir previous_commit backup_file file others delete_count
    local -a old_dumps

    backup_taken=""
    project_dir=$(pwd -P)
    env_file="$project_dir/.env"

    if [ ! -f "$env_file" ]; then
        refuse "no .env file in $project_dir."
    fi

    db_connection=$(env_value "$env_file" DB_CONNECTION || true)
    if [ "$db_connection" != "pgsql" ]; then
        refuse "the backup step only supports PostgreSQL (DB_CONNECTION=pgsql)."
    fi

    db_url=$(env_value "$env_file" DB_URL || true)
    if [ -n "$db_url" ]; then
        refuse "DB_URL is set; the backup step reads DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD. Remove DB_URL or back up by hand."
    fi

    if ! command -v pg_dump >/dev/null 2>&1; then
        refuse "pg_dump was not found. Install the PostgreSQL client (same major version as the server) and run deploy.sh again."
    fi

    if ! command -v flock >/dev/null 2>&1; then
        refuse "flock was not found. Install util-linux and run deploy.sh again."
    fi

    backup_keep=${DEPLOY_BACKUP_KEEP:-5}
    if [[ ! $backup_keep =~ ^[1-9][0-9]{0,3}$ ]]; then
        refuse "DEPLOY_BACKUP_KEEP must be a whole number of 1 or more."
    fi

    backup_dir=${DEPLOY_BACKUP_DIR:-}
    if [ -z "$backup_dir" ]; then
        if [ -z "${HOME:-}" ]; then
            refuse "set DEPLOY_BACKUP_DIR (HOME is not set)."
        fi
        backup_dir="$HOME/crm-backups"
    fi

    resolved_dir=$(realpath -m -- "$backup_dir")
    if [ "$resolved_dir" = "$project_dir" ] || [[ $resolved_dir == "$project_dir"/* ]]; then
        refuse "DEPLOY_BACKUP_DIR must be outside the project directory."
    fi

    mkdir -p -- "$resolved_dir"
    chmod 700 -- "$resolved_dir"
    backup_dir=$resolved_dir

    exec 9>"$backup_dir/.deploy.lock"
    if ! flock -n 9; then
        refuse "another deploy is running."
    fi

    db_host=$(env_value "$env_file" DB_HOST || true)
    db_host=${db_host:-127.0.0.1}
    db_port=$(env_value "$env_file" DB_PORT || true)
    db_port=${db_port:-5432}
    db_database=$(env_value "$env_file" DB_DATABASE || true)
    db_database=${db_database:-laravel}
    db_username=$(env_value "$env_file" DB_USERNAME || true)
    db_username=${db_username:-root}
    db_password=$(env_value "$env_file" DB_PASSWORD || true)

    step "Maintenance mode"
    if ! php artisan down --render=errors::503 --retry=60 --refresh=15; then
        refuse "could not switch maintenance mode on. Nothing else was run."
    fi

    trap 'on_error $LINENO' ERR

    step "Pausing Horizon"
    php artisan horizon:pause

    step "Waiting for running jobs"
    php artisan deploy:wait-for-jobs

    step "Pulling the latest code"
    previous_commit=$(git rev-parse --short HEAD)
    previous_commit=${previous_commit//[^0-9a-zA-Z]/}
    git pull --ff-only

    step "PHP dependencies"
    composer install --no-dev --optimize-autoloader --no-interaction

    step "Frontend assets"
    npm ci
    npm run build

    step "Database backup"
    backup_file="$backup_dir/crm-$(date -u +%Y%m%dT%H%M%SZ)-${previous_commit}.dump"
    if ! (
        umask 077
        PGPASSWORD="$db_password" pg_dump --format=custom --no-owner --host="$db_host" --port="$db_port" --username="$db_username" --dbname="$db_database" --file="$backup_file"
    ); then
        rm -f -- "$backup_file"
        false
    fi
    backup_taken=$backup_file
    echo "Database backup: $backup_file"

    old_dumps=()
    for file in "$backup_dir"/crm-*.dump; do
        if [ -f "$file" ] && [ "$file" != "$backup_file" ]; then
            old_dumps+=("$file")
        fi
    done
    others=${#old_dumps[@]}
    delete_count=$((others - (backup_keep - 1)))
    if [ "$delete_count" -gt 0 ]; then
        for file in "${old_dumps[@]:0:$delete_count}"; do
            rm -f -- "$file"
        done
    fi

    step "Database migrations"
    php artisan migrate --force

    step "Caches (the cache store is kept so login lockouts and invitation caps survive)"
    php artisan optimize:clear --except=cache
    php artisan optimize

    step "Production .env check"
    if ! bash ./check-production-env.sh; then
        echo
        echo "The production .env check failed (see above). The app stays in maintenance mode and Horizon is paused. Fix the .env, then run deploy.sh again."
        exit 1
    fi

    step "Restarting Horizon (the process manager starts it again with the new code)"
    php artisan horizon:terminate

    step "Back online"
    php artisan up

    trap - ERR

    echo
    echo "Deploy finished."
    exit 0
}

# Everything lives in main() and main() always exits: bash reads a script while it runs, and git pull
# rewrites this very file, so bash must never read on past this line.
main "$@"
