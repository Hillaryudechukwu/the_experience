#!/usr/bin/env bash

# Interlude Expo + Laravel deployment script.
# Deploys the web application and API to experience.synteric.co.uk.

set -Eeuo pipefail

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SERVER_USER="${SERVER_USER:-tripuwtd}"
SERVER_HOST="${SERVER_HOST:-vira.synteric.co.uk}"
SERVER_PATH="${SERVER_PATH:-/home/tripuwtd/experience.synteric.co.uk}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_ed25519}"
SSH_PORT="${SSH_PORT:-21098}"
PUBLIC_URL="${PUBLIC_URL:-https://experience.synteric.co.uk}"
API_URL="${API_URL:-${PUBLIC_URL%/}/api}"
REMOTE_PHP="${REMOTE_PHP:-}"
REMOTE_COMPOSER="${REMOTE_COMPOSER:-}"
REMOTE_RESTART_COMMAND="${REMOTE_RESTART_COMMAND:-}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-true}"
DRY_RUN="${DRY_RUN:-false}"

SSH_OPTIONS=(
    -i "$SSH_KEY"
    -p "$SSH_PORT"
    -o BatchMode=yes
    -o StrictHostKeyChecking=accept-new
)

on_error() {
    printf "\n%bDeployment failed on line %s.%b\n" "$RED" "$1" "$NC" >&2
}
trap 'on_error $LINENO' ERR

activate_node_20() {
    local current_major=0
    local nvm_dir="${NVM_DIR:-$HOME/.nvm}"
    local candidate
    local candidate_major

    if command -v node >/dev/null 2>&1; then
        current_major="$(node -p 'process.versions.node.split(".")[0]')"
    fi

    if (( current_major < 20 )) && [[ -s "$nvm_dir/nvm.sh" ]]; then
        export NVM_DIR="$nvm_dir"
        set +u
        # nvm is a shell function and must be sourced in this process.
        source "$NVM_DIR/nvm.sh"
        nvm use 20 >/dev/null 2>&1 || nvm use 22 >/dev/null 2>&1 || true
        set -u
    fi

    if command -v node >/dev/null 2>&1; then
        current_major="$(node -p 'process.versions.node.split(".")[0]')"
    fi

    if (( current_major < 20 )); then
        for candidate in "$nvm_dir"/versions/node/v2[0-9].*/bin/node; do
            [[ -x "$candidate" ]] || continue
            candidate_major="$($candidate -p 'process.versions.node.split(".")[0]')"
            if (( candidate_major >= 20 )); then
                export PATH="$(dirname "$candidate"):$PATH"
            fi
        done
    fi
}

activate_node_20

printf "%bStarting Interlude deployment...%b\n\n" "$GREEN" "$NC"
printf "%bConfiguration:%b\n" "$YELLOW" "$NC"
printf "Source:       %s\n" "$SCRIPT_DIR"
printf "SSH gateway:  %s@%s:%s\n" "$SERVER_USER" "$SERVER_HOST" "$SSH_PORT"
printf "Application:  %s\n" "$SERVER_PATH"
printf "URL:          %s\n" "$PUBLIC_URL"
printf "API URL:      %s\n" "$API_URL"
printf "SSH key:      %s\n" "$SSH_KEY"
printf "Migrations:   %s\n" "$RUN_MIGRATIONS"
printf "Dry run:      %s\n\n" "$DRY_RUN"

for command in node npm ssh rsync; do
    command -v "$command" >/dev/null 2>&1 || {
        printf "%b%s is required.%b\n" "$RED" "$command" "$NC" >&2
        exit 1
    }
done

for required_file in \
    mobile/package.json \
    mobile/package-lock.json \
    mobile/app.json \
    api/artisan \
    api/composer.json \
    api/composer.lock \
    api/public/index.php \
    api/public/.htaccess; do
    if [[ ! -f "$SCRIPT_DIR/$required_file" ]]; then
        printf "%bRequired application file is missing: %s%b\n" "$RED" "$required_file" "$NC" >&2
        exit 1
    fi
done

if [[ ! -f "$SSH_KEY" ]]; then
    printf "%bSSH key does not exist: %s%b\n" "$RED" "$SSH_KEY" "$NC" >&2
    exit 1
fi

node_major="$(node -p 'process.versions.node.split(".")[0]')"
if (( node_major < 20 )); then
    printf "%bNode 20 or newer is required for the Expo production build (found %s).%b\n" \
        "$RED" "$(node --version)" "$NC" >&2
    exit 1
fi

if [[ "$DRY_RUN" != "true" ]]; then
    read -r -p "Deploy Interlude to ${PUBLIC_URL%/} via $SERVER_HOST? (y/N) " reply
    if [[ ! "$reply" =~ ^[Yy]$ ]]; then
        printf "%bDeployment cancelled.%b\n" "$RED" "$NC"
        exit 1
    fi
fi

printf "%bInstalling locked web dependencies and exporting Expo...%b\n" "$YELLOW" "$NC"
npm ci --prefix "$SCRIPT_DIR/mobile"
(
    cd "$SCRIPT_DIR/mobile"
    EXPO_PUBLIC_API_URL="$API_URL" npx expo export --platform web --output-dir dist
)

if [[ ! -f "$SCRIPT_DIR/mobile/dist/index.html" ]]; then
    printf "%bExpo did not produce mobile/dist/index.html.%b\n" "$RED" "$NC" >&2
    exit 1
fi

printf "%bChecking SSH and the remote application directory...%b\n" "$YELLOW" "$NC"
if [[ "$DRY_RUN" == "true" ]]; then
    ssh "${SSH_OPTIONS[@]}" "$SERVER_USER@$SERVER_HOST" "test -d '$SERVER_PATH'"
else
    ssh "${SSH_OPTIONS[@]}" "$SERVER_USER@$SERVER_HOST" \
        "mkdir -p -- '$SERVER_PATH/api/public' '$SERVER_PATH/api/storage' '$SERVER_PATH/api/bootstrap/cache'"
fi

RSYNC_OPTIONS=(
    --archive
    --compress
    --checksum
    --human-readable
    --itemize-changes
    --exclude=.DS_Store
)

if [[ "$DRY_RUN" == "true" ]]; then
    RSYNC_OPTIONS+=(--dry-run)
fi

RSYNC_SHELL="ssh -i $SSH_KEY -p $SSH_PORT -o BatchMode=yes -o StrictHostKeyChecking=accept-new"

printf "%bSynchronising the Laravel application...%b\n" "$YELLOW" "$NC"
rsync "${RSYNC_OPTIONS[@]}" --delete \
    --exclude=.env \
    --exclude=.env.* \
    --exclude=vendor/ \
    --exclude=node_modules/ \
    --exclude=storage/ \
    --exclude=bootstrap/cache/ \
    --exclude=public/build/ \
    --exclude=public/_expo/ \
    --exclude=public/assets/ \
    --exclude=public/index.html \
    -e "$RSYNC_SHELL" \
    "$SCRIPT_DIR/api/" \
    "$SERVER_USER@$SERVER_HOST:$SERVER_PATH/api/"

printf "%bSynchronising the Expo web bundle...%b\n" "$YELLOW" "$NC"
rsync "${RSYNC_OPTIONS[@]}" --delete \
    --filter='protect index.php' \
    --filter='protect .htaccess' \
    --filter='protect robots.txt' \
    --filter='protect build/' \
    -e "$RSYNC_SHELL" \
    "$SCRIPT_DIR/mobile/dist/" \
    "$SERVER_USER@$SERVER_HOST:$SERVER_PATH/api/public/"

if [[ "$DRY_RUN" == "true" ]]; then
    printf "\n%bDry run complete; no remote application files were changed.%b\n" "$GREEN" "$NC"
    exit 0
fi

printf "%bInstalling PHP dependencies and finalising Laravel...%b\n" "$YELLOW" "$NC"
ssh "${SSH_OPTIONS[@]}" "$SERVER_USER@$SERVER_HOST" \
    "bash -s -- '$SERVER_PATH/api' '$REMOTE_PHP' '$REMOTE_COMPOSER' '$REMOTE_RESTART_COMMAND' '$RUN_MIGRATIONS'" <<'REMOTE_SCRIPT'
set -Eeuo pipefail

app_path="$1"
php_bin="$2"
composer_bin="$3"
restart_command="$4"
run_migrations="$5"

cd "$app_path"

if [[ ! -f .env ]]; then
    echo "Missing $app_path/.env." >&2
    echo "Create it from .env.example and configure production database, Redis, mail, and provider credentials." >&2
    exit 1
fi

if [[ -z "$php_bin" ]]; then
    for candidate in \
        /opt/alt/php85/usr/bin/php \
        /opt/alt/php84/usr/bin/php \
        /opt/alt/php83/usr/bin/php; do
        if [[ -x "$candidate" ]]; then
            php_bin="$candidate"
            break
        fi
    done
fi
if [[ -z "$php_bin" ]] && command -v php >/dev/null 2>&1; then
    php_bin="$(command -v php)"
fi
if [[ -z "$php_bin" ]] || [[ ! -x "$php_bin" ]]; then
    echo "Remote PHP 8.3 or newer was not found. Set REMOTE_PHP to the hosting PHP executable." >&2
    exit 1
fi

php_version_id="$("$php_bin" -r 'echo PHP_VERSION_ID;')"
if (( php_version_id < 80300 )); then
    echo "Laravel requires PHP 8.3 or newer; found $("$php_bin" -r 'echo PHP_VERSION;')." >&2
    exit 1
fi

if [[ -z "$composer_bin" ]]; then
    if command -v composer >/dev/null 2>&1; then
        composer_bin="$(command -v composer)"
    elif [[ -f "$HOME/composer.phar" ]]; then
        composer_bin="$HOME/composer.phar"
    fi
fi
if [[ -z "$composer_bin" ]] || [[ ! -f "$composer_bin" && ! -x "$composer_bin" ]]; then
    echo "Remote Composer was not found. Set REMOTE_COMPOSER to the Composer executable or PHAR." >&2
    exit 1
fi

echo "Using remote PHP: $php_bin ($("$php_bin" -r 'echo PHP_VERSION;'))"
echo "Using remote Composer: $composer_bin"

if [[ "$composer_bin" == *.phar ]]; then
    "$php_bin" "$composer_bin" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
else
    "$composer_bin" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
fi

chmod -R ug+rwX storage bootstrap/cache

"$php_bin" artisan config:clear
if [[ "$run_migrations" == "true" ]]; then
    "$php_bin" artisan migrate --force
fi
"$php_bin" artisan config:cache
"$php_bin" artisan route:cache
"$php_bin" artisan view:cache

# Apache sends API, policy, and platform-association requests to Laravel. All
# other non-files are handled by Expo Router's web entry point.
cat > public/.htaccess <<'HTACCESS'
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    RewriteCond %{HTTP:X-XSRF-Token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    RewriteCond %{REQUEST_URI} ^/(api|legal|\.well-known)(/|$) [NC]
    RewriteRule ^ index.php [L]

    RewriteCond %{REQUEST_FILENAME} -d [OR]
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [L]

    RewriteRule ^ index.html [L]
</IfModule>
HTACCESS

if [[ -n "$restart_command" ]]; then
    echo "Running configured restart command."
    bash -lc "$restart_command"
fi
REMOTE_SCRIPT

printf "%bChecking deployed application endpoints...%b\n" "$YELLOW" "$NC"
if command -v curl >/dev/null 2>&1; then
    for endpoint in / /api/health /legal/privacy; do
        if curl --fail --silent --show-error --location --max-time 30 \
            --output /dev/null "${PUBLIC_URL%/}$endpoint"; then
            printf "Verified %s%s\n" "${PUBLIC_URL%/}" "$endpoint"
        else
            curl_status=$?
            if [[ "$curl_status" -eq 6 ]]; then
                printf "%bPublic DNS for %s does not resolve yet; skipping HTTP verification.%b\n" \
                    "$YELLOW" "${PUBLIC_URL%/}" "$NC"
                break
            fi
            printf "%bEndpoint verification failed for %s%s (curl exit %s).%b\n" \
                "$RED" "${PUBLIC_URL%/}" "$endpoint" "$curl_status" "$NC" >&2
            exit "$curl_status"
        fi
    done
else
    printf "%bcurl is unavailable; skipping HTTP verification.%b\n" "$YELLOW" "$NC"
fi

printf "\n%bInterlude deployment complete: %s/%b\n" "$GREEN" "${PUBLIC_URL%/}" "$NC"
printf "%bSet the hosting document root to %s/api/public.%b\n" "$YELLOW" "$SERVER_PATH" "$NC"
