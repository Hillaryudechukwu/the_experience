#!/usr/bin/env bash

# Interlude production deployment.
#
# api.experience.synteric.co.uk: Laravel (document root: api/public)
# experience.synteric.co.uk:     Expo web export only

set -Eeuo pipefail

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

SERVER_USER="${SERVER_USER:-tripuwtd}"
API_SERVER_HOST="${API_SERVER_HOST:-vira.synteric.co.uk}"
WEB_SERVER_HOST="${WEB_SERVER_HOST:-vira.synteric.co.uk}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_ed25519}"
SSH_PORT="${SSH_PORT:-21098}"

API_SITE_PATH="${API_SITE_PATH:-/home/tripuwtd/api.experience.synteric.co.uk}"
API_APP_PATH="${API_APP_PATH:-$API_SITE_PATH/api}"
WEB_ROOT_PATH="${WEB_ROOT_PATH:-/home/tripuwtd/experience.synteric.co.uk}"

API_ORIGIN="${API_ORIGIN:-https://api.experience.synteric.co.uk}"
API_PUBLIC_URL="${API_PUBLIC_URL:-${API_ORIGIN%/}/api}"
WEB_ORIGIN="${WEB_ORIGIN:-https://experience.synteric.co.uk}"
PRIVACY_URL="${PRIVACY_URL:-${WEB_ORIGIN%/}/privacy}"
API_DOMAIN="${API_ORIGIN#*://}"
API_DOMAIN="${API_DOMAIN%%/*}"
API_DOCUMENT_ROOT="${API_APP_PATH#/home/$SERVER_USER/}/public"

REMOTE_PHP="${REMOTE_PHP:-}"
REMOTE_COMPOSER="${REMOTE_COMPOSER:-}"
REMOTE_RESTART_COMMAND="${REMOTE_RESTART_COMMAND:-}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-true}"
RUN_SEEDERS="${RUN_SEEDERS:-true}"
DRY_RUN="${DRY_RUN:-false}"
DESTINATION_ACTIVATION_ROLLOUT_PERCENTAGE="${DESTINATION_ACTIVATION_ROLLOUT_PERCENTAGE:-}"
DESTINATION_ACTIVATION_ALLOWED_CITIES="${DESTINATION_ACTIVATION_ALLOWED_CITIES:-}"
DESTINATION_ACTIVATION_ALLOWED_CANDIDATES="${DESTINATION_ACTIVATION_ALLOWED_CANDIDATES:-}"
DESTINATION_PREWARM_ENABLED="${DESTINATION_PREWARM_ENABLED:-}"
REQUIRE_ANDROID_APP_LINKS="${REQUIRE_ANDROID_APP_LINKS:-true}"

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

    node_supported() {
        local major="$1"
        (( major == 20 || major == 22 || major == 24 || major >= 25 ))
    }

    if command -v node >/dev/null 2>&1; then
        current_major="$(node -p 'process.versions.node.split(".")[0]')"
    fi

    if ! node_supported "$current_major" && [[ -s "$nvm_dir/nvm.sh" ]]; then
        export NVM_DIR="$nvm_dir"
        set +u
        source "$NVM_DIR/nvm.sh"
        nvm use 20 >/dev/null 2>&1 || nvm use 22 >/dev/null 2>&1 || true
        set -u
    fi

    if command -v node >/dev/null 2>&1; then
        current_major="$(node -p 'process.versions.node.split(".")[0]')"
    fi

    if ! node_supported "$current_major"; then
        for candidate in "$nvm_dir"/versions/node/v20.*/bin/node \
            "$nvm_dir"/versions/node/v22.*/bin/node \
            "$nvm_dir"/versions/node/v24.*/bin/node \
            "$nvm_dir"/versions/node/v2[5-9].*/bin/node; do
            [[ -x "$candidate" ]] || continue
            candidate_major="$($candidate -p 'process.versions.node.split(".")[0]')"
            if node_supported "$candidate_major"; then
                export PATH="$(dirname "$candidate"):$PATH"
                break
            fi
        done
    fi
}

activate_node_20

printf "%bStarting split Interlude deployment...%b\n\n" "$GREEN" "$NC"
printf "%bAPI host:%b\n" "$YELLOW" "$NC"
printf "  SSH:           %s@%s:%s\n" "$SERVER_USER" "$API_SERVER_HOST" "$SSH_PORT"
printf "  Application:   %s\n" "$API_APP_PATH"
printf "  Document root: %s/public\n" "$API_APP_PATH"
printf "  URL:           %s\n" "$API_ORIGIN"
printf "%bWeb host:%b\n" "$YELLOW" "$NC"
printf "  SSH:           %s@%s:%s\n" "$SERVER_USER" "$WEB_SERVER_HOST" "$SSH_PORT"
printf "  Document root: %s\n" "$WEB_ROOT_PATH"
printf "  URL:           %s\n" "$WEB_ORIGIN"
printf "Web API URL:     %s\n" "$API_PUBLIC_URL"
printf "Privacy URL:     %s\n" "$PRIVACY_URL"
printf "Migrations:      %s\n" "$RUN_MIGRATIONS"
printf "Catalogue seed:  %s\n" "$RUN_SEEDERS"
printf "Dry run:         %s\n\n" "$DRY_RUN"
printf "Rollout:         %s\n" "${DESTINATION_ACTIVATION_ROLLOUT_PERCENTAGE:-preserve remote value}"
printf "Android links:   %s\n\n" "$REQUIRE_ANDROID_APP_LINKS"

for command in node npm ssh rsync curl rg; do
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
if (( node_major == 21 || node_major == 23 || node_major < 20 )); then
    printf "%bA supported even-numbered Node release (20, 22, 24, or newer) is required for the Expo production build (found %s).%b\n" \
        "$RED" "$(node --version)" "$NC" >&2
    exit 1
fi

if [[ "$DRY_RUN" != "true" ]]; then
    read -r -p "Deploy Laravel to $API_ORIGIN and Expo to $WEB_ORIGIN? (y/N) " reply
    if [[ ! "$reply" =~ ^[Yy]$ ]]; then
        printf "%bDeployment cancelled.%b\n" "$RED" "$NC"
        exit 1
    fi
fi

printf "%bInstalling locked web dependencies and exporting Expo...%b\n" "$YELLOW" "$NC"
npm ci --prefix "$SCRIPT_DIR/mobile"
(
    cd "$SCRIPT_DIR/mobile"
    EXPO_PUBLIC_API_URL="$API_PUBLIC_URL" \
        EXPO_PUBLIC_PRIVACY_URL="$PRIVACY_URL" \
        npx expo export --platform web --output-dir dist --clear
)

if [[ ! -f "$SCRIPT_DIR/mobile/dist/index.html" ]]; then
    printf "%bExpo did not produce mobile/dist/index.html.%b\n" "$RED" "$NC" >&2
    exit 1
fi

if ! rg --quiet --fixed-strings "$API_PUBLIC_URL" "$SCRIPT_DIR/mobile/dist/_expo"; then
    printf "%bThe Expo bundle does not contain the production API URL: %s%b\n" \
        "$RED" "$API_PUBLIC_URL" "$NC" >&2
    exit 1
fi
if ! rg --quiet --fixed-strings "$PRIVACY_URL" "$SCRIPT_DIR/mobile/dist/_expo"; then
    printf "%bThe Expo bundle does not contain the production privacy URL: %s%b\n" \
        "$RED" "$PRIVACY_URL" "$NC" >&2
    exit 1
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

API_RSYNC_SHELL="ssh -i $SSH_KEY -p $SSH_PORT -o BatchMode=yes -o StrictHostKeyChecking=accept-new"
WEB_RSYNC_SHELL="$API_RSYNC_SHELL"

printf "%bPreparing the API deployment directory...%b\n" "$YELLOW" "$NC"
if [[ "$DRY_RUN" == "true" ]]; then
    ssh "${SSH_OPTIONS[@]}" "$SERVER_USER@$API_SERVER_HOST" "test -d '$API_APP_PATH'"
else
    ssh "${SSH_OPTIONS[@]}" "$SERVER_USER@$API_SERVER_HOST" \
        "mkdir -p -- '$API_APP_PATH/public' '$API_APP_PATH/bootstrap/cache' '$API_APP_PATH/storage/app/private' '$API_APP_PATH/storage/app/public' '$API_APP_PATH/storage/framework/cache/data' '$API_APP_PATH/storage/framework/sessions' '$API_APP_PATH/storage/framework/views' '$API_APP_PATH/storage/logs'"

    printf "%bSetting the API subdomain document root...%b\n" "$YELLOW" "$NC"
    docroot_result="$(ssh "${SSH_OPTIONS[@]}" "$SERVER_USER@$API_SERVER_HOST" \
        "uapi --output=jsonpretty SubDomain changedocroot domain='$API_DOMAIN' docroot='$API_DOCUMENT_ROOT'")"
    printf "%s\n" "$docroot_result"
    if ! grep -Eq '"status"[[:space:]]*:[[:space:]]*1' <<< "$docroot_result"; then
        printf "%bcPanel did not accept the API document root change.%b\n" "$RED" "$NC" >&2
        exit 1
    fi

    # This parent-level guard is normally bypassed because cPanel serves
    # API_APP_PATH/public directly. It prevents directory listing and keeps the
    # API online if LiteSpeed is slow to reload a changed virtual host.
    ssh "${SSH_OPTIONS[@]}" "$SERVER_USER@$API_SERVER_HOST" \
        "bash -s -- '$API_SITE_PATH'" <<'API_ROOT_GUARD'
set -Eeuo pipefail
site_path="$1"
cat > "$site_path/.htaccess" <<'HTACCESS'
<IfModule mod_rewrite.c>
    Options -Indexes
    RewriteEngine On
    RewriteRule ^api/public/index\.php$ - [L]
    RewriteRule ^ api/public/index.php [L,QSA]
</IfModule>
HTACCESS
API_ROOT_GUARD

    # During the initial split, retain the existing production secrets and
    # APP_KEY instead of creating a fresh application identity. This migration
    # is possible only when both domains use the same SSH host.
    if [[ "$API_SERVER_HOST" == "$WEB_SERVER_HOST" ]]; then
        ssh "${SSH_OPTIONS[@]}" "$SERVER_USER@$API_SERVER_HOST" \
            "if [ ! -f '$API_APP_PATH/.env' ] && [ -f '$WEB_ROOT_PATH/api/.env' ]; then cp -p -- '$WEB_ROOT_PATH/api/.env' '$API_APP_PATH/.env'; fi"
    fi
fi

printf "%bSynchronising Laravel to the API host...%b\n" "$YELLOW" "$NC"
rsync "${RSYNC_OPTIONS[@]}" --delete \
    --exclude=.env \
    --exclude=.env.* \
    --exclude=.agents/ \
    --exclude=.claude/ \
    --exclude=.codex/ \
    --exclude=.cursor/ \
    --exclude=.mcp.json \
    --exclude=boost.json \
    --exclude=.phpunit.result.cache \
    --exclude=tests/ \
    --exclude=vendor/ \
    --exclude=node_modules/ \
    --exclude=storage/ \
    --exclude=bootstrap/cache/ \
    --exclude=public/build/ \
    -e "$API_RSYNC_SHELL" \
    "$SCRIPT_DIR/api/" \
    "$SERVER_USER@$API_SERVER_HOST:$API_APP_PATH/"

if [[ "$DRY_RUN" == "true" ]]; then
    printf "%bDry-running the Expo web sync...%b\n" "$YELLOW" "$NC"
    rsync "${RSYNC_OPTIONS[@]}" --delete \
        --filter='protect cgi-bin/' \
        --filter='protect .well-known/*.txt' \
        --filter='protect .well-known/assetlinks.json' \
        --filter='protect .well-known/apple-app-site-association' \
        --filter='protect .well-known/pki-validation/***' \
        -e "$WEB_RSYNC_SHELL" \
        "$SCRIPT_DIR/mobile/dist/" \
        "$SERVER_USER@$WEB_SERVER_HOST:$WEB_ROOT_PATH/"
    printf "\n%bDry run complete; no remote files were changed.%b\n" "$GREEN" "$NC"
    exit 0
fi

printf "%bInstalling PHP dependencies and finalising Laravel...%b\n" "$YELLOW" "$NC"
ssh "${SSH_OPTIONS[@]}" "$SERVER_USER@$API_SERVER_HOST" \
    "bash -s -- '$API_APP_PATH' '$REMOTE_PHP' '$REMOTE_COMPOSER' '$REMOTE_RESTART_COMMAND' '$RUN_MIGRATIONS' '$RUN_SEEDERS' '$API_ORIGIN' '$WEB_ORIGIN' '$PRIVACY_URL' '$DESTINATION_ACTIVATION_ROLLOUT_PERCENTAGE' '$DESTINATION_ACTIVATION_ALLOWED_CITIES' '$DESTINATION_ACTIVATION_ALLOWED_CANDIDATES' '$DESTINATION_PREWARM_ENABLED'" <<'REMOTE_SCRIPT'
set -Eeuo pipefail

app_path="$1"
php_bin="$2"
composer_bin="$3"
restart_command="$4"
run_migrations="$5"
run_seeders="$6"
api_origin="$7"
web_origin="$8"
privacy_url="$9"
rollout_percentage="${10}"
allowed_cities="${11}"
allowed_candidates="${12}"
prewarm_enabled="${13}"

cd "$app_path"

if [[ ! -f .env ]]; then
    echo "Missing $app_path/.env." >&2
    echo "Create it from .env.example and configure the production database and credentials." >&2
    exit 1
fi

if [[ -z "$php_bin" ]]; then
    for candidate in \
        /opt/alt/php85/usr/bin/php \
        /opt/alt/php84/usr/bin/php \
        /opt/alt/php83/usr/bin/php; do
        if [[ -x "$candidate" ]] && \
            "$candidate" -r 'exit(extension_loaded("phar") ? 0 : 1);'; then
            php_bin="$candidate"
            break
        fi
    done
fi
if [[ -z "$php_bin" ]] && command -v php >/dev/null 2>&1; then
    php_bin="$(command -v php)"
fi
if [[ -z "$php_bin" ]] || [[ ! -x "$php_bin" ]]; then
    echo "Remote PHP 8.3 or newer with PHAR support was not found." >&2
    exit 1
fi

php_version_id="$("$php_bin" -r 'echo PHP_VERSION_ID;')"
if (( php_version_id < 80300 )); then
    echo "Laravel requires PHP 8.3 or newer; found $("$php_bin" -r 'echo PHP_VERSION;')." >&2
    exit 1
fi
if ! "$php_bin" -r 'exit(extension_loaded("phar") ? 0 : 1);'; then
    echo "Remote PHP $php_bin does not have the PHAR extension required by Composer." >&2
    exit 1
fi

if [[ -z "$composer_bin" ]]; then
    php_dir="$(dirname "$php_bin")"
    for candidate in "$php_dir/composer.phar" "$php_dir/composer"; do
        if [[ -f "$candidate" || -x "$candidate" ]]; then
            composer_bin="$candidate"
            break
        fi
    done
fi
if [[ -z "$composer_bin" ]] && command -v composer >/dev/null 2>&1; then
    composer_bin="$(command -v composer)"
fi
if [[ -z "$composer_bin" ]] || [[ ! -f "$composer_bin" && ! -x "$composer_bin" ]]; then
    echo "Remote Composer was not found." >&2
    exit 1
fi

set_env() {
    local key="$1"
    local value="$2"
    local escaped
    escaped="$(printf '%s' "$value" | sed 's/[&|]/\\&/g')"

    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${escaped}|" .env
    else
        printf '\n%s=%s\n' "$key" "$value" >> .env
    fi
}

set_env_if_provided() {
    local key="$1"
    local value="$2"
    if [[ -n "$value" ]]; then
        set_env "$key" "$value"
    fi
}

set_env APP_NAME "Interlude"
set_env APP_URL "$api_origin"
set_env CORS_ALLOWED_ORIGINS "$web_origin"
set_env PRIVACY_POLICY_URL "$privacy_url"
set_env APP_LINKS_HOST "${web_origin#*://}"
set_env CACHE_STORE "database"
set_env QUEUE_CONNECTION "database"
set_env DB_QUEUE_RETRY_AFTER "360"
set_env DESTINATION_ACTIVATION_ENABLED "false"
set_env_if_provided DESTINATION_ACTIVATION_ROLLOUT_PERCENTAGE "$rollout_percentage"
set_env_if_provided DESTINATION_ACTIVATION_ALLOWED_CITIES "$allowed_cities"
set_env_if_provided DESTINATION_ACTIVATION_ALLOWED_CANDIDATES "$allowed_candidates"
set_env DESTINATION_ACTIVATION_DAILY_ACTOR_LIMIT "5"
set_env DESTINATION_ACTIVATION_DAILY_IP_LIMIT "10"
set_env DESTINATION_ACTIVATION_DAILY_GLOBAL_LIMIT "100"
set_env_if_provided DESTINATION_PREWARM_ENABLED "$prewarm_enabled"
set_env DESTINATION_PREWARM_MINIMUM_DEMAND "10"
set_env DESTINATION_PREWARM_DAILY_LIMIT "3"
set_env DESTINATION_PREWARM_PER_RUN_LIMIT "3"

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
if [[ "$run_seeders" == "true" ]]; then
    "$php_bin" artisan db:seed --force
fi
set_env DESTINATION_ACTIVATION_ENABLED "true"
"$php_bin" artisan config:cache
"$php_bin" artisan route:cache
"$php_bin" artisan view:cache
"$php_bin" artisan queue:restart
# Kick the scheduler once so RecordQueueHeartbeat lands before the health gate;
# host cron must still run schedule:run every minute (see PROVIDER_SMOKE_RUNBOOK).
"$php_bin" artisan schedule:run
"$php_bin" artisan experience:export-legal

if [[ -n "$restart_command" ]]; then
    bash -lc "$restart_command"
fi
REMOTE_SCRIPT

printf "%bChecking the API before publishing dependent web artifacts...%b\n" "$YELLOW" "$NC"
destinations_json="$(curl --fail --silent --show-error --max-time 30 "${API_ORIGIN%/}/api/destinations")"
node -e 'JSON.parse(process.argv[1])' "$destinations_json"
printf "Verified JSON: %s/api/destinations\n" "${API_ORIGIN%/}"

printf "%bPublishing privacy policy and Android App Links on the web host...%b\n" "$YELLOW" "$NC"
mkdir -p "$SCRIPT_DIR/mobile/dist/.well-known"
rsync --archive --compress \
    -e "$API_RSYNC_SHELL" \
    "$SERVER_USER@$API_SERVER_HOST:$API_APP_PATH/storage/app/legal/privacy.html" \
    "$SCRIPT_DIR/mobile/dist/privacy"
assetlinks_file="$SCRIPT_DIR/mobile/dist/.well-known/assetlinks.json"
assetlinks_candidate="${assetlinks_file}.candidate"
rm -f -- "$assetlinks_candidate"
if ! assetlinks_status="$(curl --silent --show-error --max-time 30 \
    --output "$assetlinks_candidate" --write-out '%{http_code}' \
    "${API_ORIGIN%/}/.well-known/assetlinks.json")"; then
    printf "%bCould not retrieve Android App Links from the API host.%b\n" "$RED" "$NC" >&2
    exit 1
fi

if [[ "$assetlinks_status" == "200" ]]; then
    if ! node -e 'JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"))' \
        "$assetlinks_candidate"; then
        printf "%bGenerated assetlinks.json is not valid JSON.%b\n" "$RED" "$NC" >&2
        exit 1
    fi
    mv -- "$assetlinks_candidate" "$assetlinks_file"
elif [[ "$assetlinks_status" == "404" ]]; then
    rm -f -- "$assetlinks_candidate" "$assetlinks_file"
    if [[ "$REQUIRE_ANDROID_APP_LINKS" == "true" ]]; then
        printf "%bAndroid App Links are required but the API returned 404. Set APP_LINKS_ANDROID_SHA256 to the release signing fingerprint.%b\n" \
            "$RED" "$NC" >&2
        exit 1
    fi
    printf "%bAndroid App Links explicitly skipped; REQUIRE_ANDROID_APP_LINKS=false.%b\n" "$YELLOW" "$NC"
else
    rm -f -- "$assetlinks_candidate"
    printf "%bAndroid App Links endpoint returned HTTP %s.%b\n" \
        "$RED" "$assetlinks_status" "$NC" >&2
    exit 1
fi

printf "%bSynchronising the Expo-only web host...%b\n" "$YELLOW" "$NC"
ssh "${SSH_OPTIONS[@]}" "$SERVER_USER@$WEB_SERVER_HOST" "mkdir -p -- '$WEB_ROOT_PATH'"
rsync "${RSYNC_OPTIONS[@]}" --delete \
    --filter='protect cgi-bin/' \
    --filter='protect .well-known/*.txt' \
    --filter='protect .well-known/assetlinks.json' \
    --filter='protect .well-known/apple-app-site-association' \
    --filter='protect .well-known/pki-validation/***' \
    -e "$WEB_RSYNC_SHELL" \
    "$SCRIPT_DIR/mobile/dist/" \
    "$SERVER_USER@$WEB_SERVER_HOST:$WEB_ROOT_PATH/"

printf "%bRunning strict production health checks...%b\n" "$YELLOW" "$NC"
check_200() {
    local url="$1"
    local label="$2"
    local status
    status="$(curl --silent --show-error --max-time 30 --output /dev/null --write-out '%{http_code}' "$url")"
    if [[ "$status" != "200" ]]; then
        printf "%b%s returned HTTP %s instead of 200: %s%b\n" "$RED" "$label" "$status" "$url" "$NC" >&2
        exit 1
    fi
    printf "Verified %s: %s\n" "$label" "$url"
}

check_200 "${WEB_ORIGIN%/}/" "web HTML"
check_200 "$PRIVACY_URL" "privacy policy"

# Fetch once more after all deployment work so API failure cannot be masked by
# the earlier artifact-generation request.
destinations_json="$(curl --fail --silent --show-error --max-time 30 "${API_ORIGIN%/}/api/destinations")"
node -e 'JSON.parse(process.argv[1])' "$destinations_json"
printf "Verified API JSON: %s/api/destinations\n" "${API_ORIGIN%/}"

health_json="$(curl --fail --silent --show-error --max-time 30 "${API_ORIGIN%/}/api/health")"
node -e '
const health = JSON.parse(process.argv[1]);
const pipeline = health.destination_pipeline;
if (!pipeline || !pipeline.place_provider_configured || !pipeline.geocoder_configured) {
  throw new Error("Destination pipeline providers are not configured: " + JSON.stringify(pipeline));
}
if (!pipeline.queue_worker_alive) {
  throw new Error("Queue worker heartbeat is stale or missing: " + JSON.stringify(pipeline));
}
' "$health_json"
printf "Verified destination pipeline health: %s/api/health\n" "${API_ORIGIN%/}"

printf "\n%bSplit deployment complete.%b\n" "$GREEN" "$NC"
printf "API document root: %s/public\n" "$API_APP_PATH"
printf "Web document root: %s\n" "$WEB_ROOT_PATH"
