#!/usr/bin/env bash
# Flags insecure settings in the .env of a production server.
#
# Usage:      ./check-production-env.sh [directory]     (default: current directory)
# Reads:      <directory>/.env, and when present <directory>/.env.example, <directory>/.git, <directory>/vendor
# Output:     one line per finding, "FAIL  ..." or "WARN  ...", then a summary line
# Exit code:  0 no failure (warnings allowed), 1 at least one failure, 2 no .env file or .env not readable
# Secrets:    the values of APP_KEY, APP_PREVIOUS_KEYS, DB_PASSWORD, RESEND_WEBHOOK_SECRET and
#             REDIS_PASSWORD are never printed. The .env file is read as text, never sourced.
set -u

dir=${1:-.}
env_file="$dir/.env"
example_file="$dir/.env.example"
failures=0
warnings=0

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

is_off() {
    case $(printf '%s' "${1-}" | tr '[:upper:]' '[:lower:]') in
        ''|false|'(false)'|0|null|'(null)'|empty|'(empty)') return 0 ;;
        *) return 1 ;;
    esac
}

fail() {
    printf 'FAIL  %s\n' "$1"
    failures=$((failures + 1))
}

warn() {
    printf 'WARN  %s\n' "$1"
    warnings=$((warnings + 1))
}

lower() {
    printf '%s' "${1-}" | tr '[:upper:]' '[:lower:]'
}

trim() {
    local value=${1-}
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s' "$value"
}

value_or_default() {
    local value
    if value=$(env_value "$env_file" "$1"); then
        printf '%s' "$value"
        return 0
    fi
    printf '%s' "$2"
}

is_set() {
    env_value "$env_file" "$1" >/dev/null
}

list_has_item() {
    local list=$1 wanted=$2 item
    local IFS=','
    for item in $list; do
        if [ "$(trim "$item")" = "$wanted" ]; then
            return 0
        fi
    done
    return 1
}

echo "Checking $env_file"

if [ ! -f "$env_file" ]; then
    echo "FAIL  No .env file in $dir."
    exit 2
fi

if [ ! -r "$env_file" ]; then
    echo "FAIL  Cannot read $env_file."
    exit 2
fi

committed_keys=()
committed_passwords=()

add_committed() {
    local kind=$1 value=$2
    value=${value%$'\r'}
    value=$(trim "$value")
    case $value in
        \"*\") value=${value#\"}; value=${value%\"} ;;
        \'*\') value=${value#\'}; value=${value%\'} ;;
    esac
    if [ -z "$value" ]; then
        return 0
    fi
    if [ "$kind" = key ]; then
        committed_keys+=("$value")
        return 0
    fi
    committed_passwords+=("$value")
}

if [ -f "$example_file" ] && [ ! -r "$example_file" ]; then
    warn "Cannot read $example_file: keys committed in it are not checked."
fi

if [ -f "$example_file" ]; then
    add_committed key "$(env_value "$example_file" APP_KEY)"
    add_committed password "$(env_value "$example_file" DB_PASSWORD)"
fi

if [ -d "$dir/.git" ] && command -v git >/dev/null 2>&1; then
    abs_dir=$(CDPATH= cd -- "$dir" && pwd -P)
    git_ceiling=${abs_dir%/*}
    if git_history=$(GIT_CEILING_DIRECTORIES="$git_ceiling" git -C "$dir" log -p --format= -- .env.example 2>/dev/null); then
        while IFS= read -r history_value; do
            add_committed key "$history_value"
        done < <(printf '%s\n' "$git_history" | sed -n 's/^[+-]APP_KEY=//p')
        while IFS= read -r history_value; do
            add_committed password "$history_value"
        done < <(printf '%s\n' "$git_history" | sed -n 's/^[+-]DB_PASSWORD=//p')
    else
        git_status=$?
        git_error=$(GIT_CEILING_DIRECTORIES="$git_ceiling" git -C "$dir" log -1 --format= -- .env.example 2>&1 >/dev/null | head -n 1)
        git_error=${git_error#fatal: }
        warn "Could not read the git history of .env.example (git: ${git_error:-exit code $git_status}): keys committed earlier are not checked."
    fi
fi

is_committed() {
    local kind=$1 value=$2 known
    if [ -z "$value" ]; then
        return 1
    fi
    if [ "$kind" = key ]; then
        for known in ${committed_keys[@]+"${committed_keys[@]}"}; do
            if [ "$known" = "$value" ]; then
                return 0
            fi
        done
        return 1
    fi
    for known in ${committed_passwords[@]+"${committed_passwords[@]}"}; do
        if [ "$known" = "$value" ]; then
            return 0
        fi
    done
    return 1
}

# 1
if is_set APP_ENV; then
    app_env=$(value_or_default APP_ENV production)
    if [ "$app_env" != "production" ]; then
        fail "APP_ENV is \"$app_env\", not \"production\": local mode opens the Horizon dashboard to everyone and relaxes the password rules."
    fi
fi

# 2
if ! is_off "$(value_or_default APP_DEBUG false)"; then
    fail "APP_DEBUG is on: error pages show stack traces and environment values. Set APP_DEBUG=false."
fi

# 3a, 3b
app_key=$(value_or_default APP_KEY "")
if [ -z "$app_key" ]; then
    fail "APP_KEY is empty. Run: php artisan key:generate"
elif is_committed key "$app_key"; then
    fail "APP_KEY is a key committed in .env.example (public repository). Generate a new one with php artisan key:generate and do not keep the old one in APP_PREVIOUS_KEYS."
fi

# 3c
previous_keys=$(value_or_default APP_PREVIOUS_KEYS "")
if [ -n "$previous_keys" ]; then
    previous_committed=0
    old_ifs=$IFS
    IFS=','
    for previous_key in $previous_keys; do
        if is_committed key "$(trim "$previous_key")"; then
            previous_committed=1
        fi
    done
    IFS=$old_ifs
    if [ "$previous_committed" = 1 ]; then
        fail "APP_PREVIOUS_KEYS contains a key committed in .env.example (public repository). Remove it."
    fi
fi

# 4a, 4b
db_password=$(value_or_default DB_PASSWORD "")
if is_off "$db_password"; then
    warn "DB_PASSWORD is empty: make sure the database only accepts local connections."
elif is_committed password "$db_password"; then
    fail "DB_PASSWORD is the password committed in .env.example (public repository). Change the database password."
fi

# 5a, 5b
app_url=$(value_or_default APP_URL "http://localhost")
case $(lower "$app_url") in
    https://*) ;;
    *)
        fail "APP_URL does not start with https:// (\"$app_url\"): cookies and signed download links need the public https address."
        ;;
esac
url_host=${app_url#*://}
url_host=${url_host%%/*}
url_host=${url_host%%\?*}
url_host=${url_host##*@}
case $url_host in
    \[*\]*) url_host=${url_host%%]*}] ;;
    *) url_host=${url_host%%:*} ;;
esac
url_host=$(lower "$url_host")
case $url_host in
    localhost|127.*|0.0.0.0|'[::1]'|*.test|*.local|*.localhost)
        warn "APP_URL points to a local address (\"$app_url\"): set the public address of the application."
        ;;
esac

# 6
if is_off "$(value_or_default SESSION_SECURE_COOKIE false)"; then
    fail "SESSION_SECURE_COOKIE is not true: the session cookie is also sent over plain http. Set SESSION_SECURE_COOKIE=true."
fi

# 7
if is_off "$(value_or_default SESSION_ENCRYPT false)"; then
    warn "SESSION_ENCRYPT is not true: session data is stored unencrypted. Set SESSION_ENCRYPT=true (everyone is logged out once)."
fi

# 8
if [ "$(lower "$(value_or_default LOG_LEVEL debug)")" = "debug" ]; then
    warn "LOG_LEVEL is debug: logs grow fast and can hold request details. Set LOG_LEVEL=warning."
fi

# 9
log_channel=$(value_or_default LOG_CHANNEL stack)
log_stack=$(value_or_default LOG_STACK single)
if [ "$log_channel" = "single" ] || { [ "$log_channel" = "stack" ] && list_has_item "$log_stack" single; }; then
    warn "The log file is never rotated (single channel). Set LOG_STACK=daily."
fi

# 10
if ! is_off "$(value_or_default RESEND_ROUTES true)" && is_off "$(value_or_default RESEND_WEBHOOK_SECRET "")"; then
    fail "POST /resend/webhook accepts unsigned requests: set RESEND_WEBHOOK_SECRET (Resend dashboard), or RESEND_ROUTES=false while no webhook is used."
fi

# 11
if is_off "$(value_or_default REDIS_PASSWORD "")"; then
    warn "REDIS_PASSWORD is empty: make sure Redis only listens on localhost or a private network."
fi

# 12
dev_packages=()
for dev_package in vendor/fruitcake/laravel-debugbar vendor/laravel/boost; do
    if [ -d "$dir/$dev_package" ]; then
        dev_packages+=("$dev_package")
    fi
done
if [ "${#dev_packages[@]}" -gt 0 ]; then
    dev_list=""
    for dev_package in "${dev_packages[@]}"; do
        dev_list="${dev_list:+$dev_list, }$dev_package"
    done
    fail "Development packages are installed ($dev_list). Deploy with: composer install --no-dev --optimize-autoloader"
fi

# 13
if env_mode=$(stat -c '%a' "$env_file" 2>/dev/null) && [ -n "$env_mode" ] && [ "${env_mode: -1}" != "0" ]; then
    warn ".env is readable by other users of the server (mode $env_mode). Run: chmod 600 .env"
fi

# 14
if [ "$(lower "$(value_or_default QUEUE_CONNECTION database)")" = "sync" ]; then
    fail "QUEUE_CONNECTION is sync: mails, imports and exports then run inside the web request and time out, and mail links follow the address the visitor typed. Set QUEUE_CONNECTION=redis."
fi
if [ "$(lower "$(value_or_default QUEUE_LONG_RUNNING_CONNECTION redis-long)")" = "sync" ]; then
    fail "QUEUE_LONG_RUNNING_CONNECTION is sync: imports and exports then run inside the web request and time out. Set QUEUE_LONG_RUNNING_CONNECTION=redis-long."
fi

# 15
if is_set TRUSTED_PROXIES; then
    proxy_items=()
    IFS=',' read -r -a proxy_items <<< "$(value_or_default TRUSTED_PROXIES "")"
    for proxy_item in ${proxy_items[@]+"${proxy_items[@]}"}; do
        proxy_item=$(trim "$proxy_item")
        case "$(lower "$proxy_item")" in
            '*'|'**'|remote_addr)
                fail "TRUSTED_PROXIES contains \"$proxy_item\", which the application ignores: list the addresses or ranges of your proxies, or remove the line when nginx runs on the same server."
                break
                ;;
        esac
    done
fi

if [ "$failures" -eq 0 ] && [ "$warnings" -eq 0 ]; then
    echo "Production env check: no problems found."
else
    echo "Production env check: $failures failure(s), $warnings warning(s)."
fi

if [ "$failures" -gt 0 ]; then
    exit 1
fi
exit 0
