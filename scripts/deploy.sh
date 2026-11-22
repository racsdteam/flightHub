#!/usr/bin/env bash
#
# RAC Flight Data Hub — deploy to https://systems.rac.co.rw/flighthub (D-049, D-056, D-061).
# Runbook: docs/DEPLOY.md.
#
# Run ON the server, in cPanel Terminal, as the account user sysrac — never root:
#
#     FLIGHTHUB_DEPLOY_AUTHORIZED=yes-<YYYY-MM-DD>-<initials> scripts/deploy.sh
#
# The DDL credentials (migrations and grants only, D-014):
#   FLIGHTHUB_DB_DDL_USER       required, from the operator's environment
#   FLIGHTHUB_DB_DDL_PASSWORD   from the operator's environment, or typed at the prompt (not
#                               echoed). Never stored, never written to a file, never cached,
#                               never exported by this script. Asked once.
#
#     scripts/deploy.sh --dry-run    prints the steps and changes nothing
#
# The order (D-056): every check first; then maintenance goes on BEFORE any file changes; the
# deployed commit is exactly the fetched commit whose token was checked (TARGET), reached with
# `git merge --ff-only TARGET`, never a second pull; a changed deploy.sh is re-run once, before
# maintenance. On the first install nothing is live: the code and vendor/ come first.
#
# Overrides for tests and unusual hosts: FLIGHTHUB_APP_DIR, FLIGHTHUB_DEPLOY_DIR, FLIGHTHUB_PHP,
# FLIGHTHUB_COMPOSER (composer's PHP file, run with FLIGHTHUB_PHP), FLIGHTHUB_JOB_WAIT_SECONDS.
# Set by this script for its own re-run only: FLIGHTHUB_DEPLOY_REEXEC, FLIGHTHUB_DEPLOY_TARGET.
#
# Nothing here depends on the script's own path: the re-run is a copy in the deploy folder,
# and every path comes from APP_DIR.

set -euo pipefail

# ─────────────────────────────────────────────────────────────────────────────────────────────
#  THE AUTHORISATION TOKEN (D-049).
#
#  FLIGHTHUB_DEPLOY_AUTHORIZED must equal this value, in the form yes-<YYYY-MM-DD>-<initials>.
#  The committed value is not in that form, so it refuses every deploy. Dany writes the real
#  token here in the commit that prepares a deploy, so the history records WHO authorised WHICH
#  deploy; the next deploy needs a new token in a new commit. Never an argument.
#
#  The value checked is the one in THIS FILE AS IT IS IN THE COMMIT BEING DEPLOYED (TARGET: the
#  upstream commit, fetched first; the fetch leaves the working tree alone), and TARGET is what
#  gets deployed — a push after the fetch changes nothing.
# ─────────────────────────────────────────────────────────────────────────────────────────────
DEPLOY_AUTHORISATION="not-yet-authorised"

APP_DIR="${FLIGHTHUB_APP_DIR:-/home/sysrac/flight-hub}"
DEPLOY_DIR="${FLIGHTHUB_DEPLOY_DIR:-/home/sysrac/flight-hub-deploy}"
PHP="${FLIGHTHUB_PHP:-/usr/local/bin/ea-php83}"
COMPOSER_BIN="${FLIGHTHUB_COMPOSER:-}"
JOB_WAIT_SECONDS="${FLIGHTHUB_JOB_WAIT_SECONDS:-120}"
CURRENT_STEP="startup"
DDL_USER=""
DDL_PASS=""
TARGET=""
PREVIOUS=""
BYPASS_VALUE=""
BYPASS_COOKIE=""
SERVED_BASE_PATH=""
HTTP_CODE=""
HTTP_LOCATION=""
BAD_COOKIES=""
FIRST_INSTALL="no"
FILES_CHANGED="no"

# Nothing from the operator's shell may reach the config cache or replace the .env: the DDL
# credentials travel only as an assignment prefix on the commands that need them.
unset DB_DDL_USERNAME DB_DDL_PASSWORD DB_USERNAME DB_PASSWORD

step() {
    CURRENT_STEP="$1"
    echo "==> $1"
}

short() {
    printf '%s' "${1:0:12}"
}

refuse() {
    trap - EXIT
    echo "" >&2
    echo "✗ $1" >&2
    if [ "$FILES_CHANGED" = "yes" ]; then
        echo "  First install: the code and vendor/ were put in place, but nothing is live (no maintenance was" >&2
        echo "  needed) and the database has not been touched. Fix the cause and re-run." >&2
    else
        echo "  Nothing has been changed: the site is still live and the database has not been touched." >&2
    fi
    exit 1
}

# Before maintenance: a failure stops the deploy; the site was never taken down.
before_maintenance_failure() {
    local code=$?
    [ "$code" -eq 0 ] && return 0
    echo "" >&2
    echo "✗ DEPLOY STOPPED during: ${CURRENT_STEP} (before maintenance)." >&2
    if [ "$FILES_CHANGED" = "yes" ]; then
        echo "  First install: nothing is live yet and the database was not touched. Fix the cause and re-run." >&2
    else
        echo "  Nothing has been changed: the site is still live and the database has not been touched." >&2
    fi
    exit "$code"
}

# ─────────────────────────────────────────────────────────────────────────────────────────────
#  ANY FAILURE AFTER `artisan down` LEAVES THE SITE IN MAINTENANCE MODE (as KIA PULSE's).
#
#  DOWN is visible at once and one `artisan up` reverses it. UP with a half-applied migration is
#  invisible and, for the append-only tables, permanent: their triggers are created by
#  migrations, and rows written while a trigger is missing cannot be removed afterwards. So
#  nothing lifts maintenance automatically; the handler names the step and says what changed.
# ─────────────────────────────────────────────────────────────────────────────────────────────
on_failure() {
    local code=$?
    [ "$code" -eq 0 ] && return 0

    echo ""
    echo "✗ DEPLOY FAILED during: ${CURRENT_STEP}"
    echo ""
    echo "  THE SITE IS STILL IN MAINTENANCE MODE, serving the 503 page. Nothing has been lifted"
    echo "  automatically, and nothing unverified is reachable."
    echo ""
    echo "  Previous commit: $PREVIOUS"
    echo "  Target commit:   $TARGET"
    echo ""
    if [ "$FILES_CHANGED" = "no" ]; then
        echo "  NOTHING CHANGED: the code is still the previous commit and the database was not touched."
        echo "  It is safe to serve again:"
        echo ""
        echo "      $PHP artisan up"
        echo ""
        exit "$code"
    fi

    case "$CURRENT_STEP" in
        "Migrations (as the DDL user)")
            echo "  FILES CHANGED (the code is the target commit) AND THE DATABASE WAS BEING WRITTEN WHEN THIS FAILED."
            echo "  Some migrations may have applied and others not. The append-only triggers are created by"
            echo "  migrations, so a partial run can leave a history table that accepts writes it should refuse."
            echo "  Check the state BEFORE deciding anything, and do NOT return the code to the previous commit:"
            echo "      read -rs P; DB_DDL_USERNAME=<DDL user> DB_DDL_PASSWORD=\"\$P\" $PHP artisan migrate:status --database=mariadb_ddl; unset P"
            ;;
        "Grants"|"Re-apply corrections"|"Caches"|"Serving check"|"Preflight"|"Up")
            echo "  FILES CHANGED (the code is the target commit). Migrations had completed, so the schema is"
            echo "  whole and matches the target commit: fix forward and re-run, do not return to the previous commit."
            if [ "$CURRENT_STEP" = "Grants" ]; then
                echo "  The runtime user's grants do not match config/grants.php yet: run the grants file as root"
                echo "  (phpMyAdmin), then $PHP artisan hub:check-grants."
            fi
            if [ "$CURRENT_STEP" = "Caches" ]; then
                echo ""
                echo "  IF EVERY ARTISAN COMMAND NOW FAILS AT BOOT, a frozen config cache is the cause and"
                echo "  config:clear cannot remove it (it boots the framework first). Delete it by hand:"
                echo "      rm $APP_DIR/bootstrap/cache/config.php"
                echo "  then $PHP artisan --version answers again."
            fi
            ;;
        *)
            echo "  FILES CHANGED (the code was being updated to the target commit), but this step runs BEFORE the"
            echo "  migrations: the database was NOT touched. To return to the previous commit:"
            echo ""
            echo "      cd $APP_DIR && git reset --hard $PREVIOUS"
            echo "      $PHP $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction"
            echo "      $PHP artisan config:clear"
            ;;
    esac
    echo ""
    echo "  When you have decided it is safe to serve, lift maintenance by hand:"
    echo ""
    echo "      $PHP artisan up"
    echo ""
    exit "$code"
}

dry_run() {
    cat <<STEPS
scripts/deploy.sh --dry-run: the steps of a deploy (nothing is run).

  Preconditions (all before maintenance; any refusal changes nothing):
    · not root; PHP 8.3.x at $PHP; curl; composer (FLIGHTHUB_COMPOSER) runs with that PHP
    · origin is github-flighthub:… (the deploy key); the git working tree is clean
    · git fetch; TARGET = the upstream commit; HEAD is TARGET or its ancestor
    · FLIGHTHUB_DEPLOY_AUTHORIZED equals DEPLOY_AUTHORISATION in TARGET:scripts/deploy.sh
      (yes-<YYYY-MM-DD>-<initials>)
    · if TARGET's scripts/deploy.sh differs from HEAD's: run that version once, from a copy in
      $DEPLOY_DIR, for the same TARGET (before maintenance and before the DDL prompt)
    · FLIGHTHUB_DB_DDL_USER set; FLIGHTHUB_DB_DDL_PASSWORD from the environment or typed once (not echoed)
    · the DDL login works with the code on disk (hub:preflight --ddl-login)

  Every deploy after the first (vendor/ exists):
  1. Maintenance     artisan down --render="errors::503" --retry=15 --secret=<random, this deploy only>
                     (from here any failure leaves maintenance on and says what changed)
  2. Running jobs    wait up to ${JOB_WAIT_SECONDS} s for hub:poll / hub:reapply-corrections / hub:backfill;
                     still running: artisan up, nothing changed, stop
  3. Update code     git merge --ff-only TARGET (the commit whose token was checked; never a pull)
  4. Dependencies    composer install --no-dev --optimize-autoloader --no-interaction
  5. Config cache    artisan config:clear (so the DDL prefix below reaches the config)
  6. Migrations      DB_DDL_USERNAME=… DB_DDL_PASSWORD=… artisan migrate --database=mariadb_ddl --force
  7. Grants          hub:check-grants; when they differ: hub:grants-sql (DDL prefix) to
                     $DEPLOY_DIR/grants-<time>.sql (mode 600), pause until run as root, check again
  8. Re-apply        artisan hub:reapply-corrections
  9. Caches          config/route/view/event:clear, clear-compiled; config/view/event:cache;
                     route:cache only when hub.base_path is empty (never under /flighthub, D-061)
                     (never optimize:clear or cache:clear)
 10. Serving check   from config('app.url'), with the bypass cookie: APP_URL → 200|302 (or 301 to
                     APP_URL/), APP_URL/ → 200|302, APP_URL/api/v1/health → 401; no Set-Cookie
                     with a path outside the base path
 11. Preflight       DB_DDL_USERNAME=… DB_DDL_PASSWORD=… artisan hub:preflight (with the DDL checks)
 12. Up              artisan up

  First install (no vendor/, nothing live): update code (git merge --ff-only TARGET) →
  composer install → DDL login → maintenance → running jobs → 5 to 12 above.
STEPS
}

# One value of the application's config, as it will be served (the config cache once built).
app_config() {
    "$PHP" -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo (string) config($argv[1]);' "$1"
}

ddl_login() {
    DB_DDL_USERNAME="$DDL_USER" DB_DDL_PASSWORD="$DDL_PASS" "$PHP" artisan hub:preflight --ddl-login
}

update_code() {
    FILES_CHANGED="yes"
    git merge --ff-only --quiet "$TARGET"
}

composer_install() {
    "$PHP" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
}

preconditions() {
    step "Preconditions"

    [ "$(id -u)" -ne 0 ] || refuse "Run as the cPanel account user (sysrac), never as root."
    [ -d "$APP_DIR" ] || refuse "The app directory $APP_DIR does not exist (FLIGHTHUB_APP_DIR)."
    cd "$APP_DIR"

    [ -x "$PHP" ] || refuse "PHP not found at $PHP (FLIGHTHUB_PHP)."
    local version
    version="$("$PHP" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" || refuse "PHP at $PHP does not run."
    [ "$version" = "8.3" ] || refuse "PHP 8.3 is required; $PHP is $version."
    command -v curl >/dev/null 2>&1 || refuse "curl is required for the serving check."
    if [ -z "$COMPOSER_BIN" ]; then
        COMPOSER_BIN="$(command -v composer || true)"
    fi
    [ -n "$COMPOSER_BIN" ] && "$PHP" "$COMPOSER_BIN" --version >/dev/null 2>&1 \
        || refuse "composer does not run with $PHP; set FLIGHTHUB_COMPOSER to composer's PHP file (e.g. /opt/cpanel/composer/bin/composer)."
    [[ "$JOB_WAIT_SECONDS" =~ ^[0-9]{1,4}$ ]] || refuse "FLIGHTHUB_JOB_WAIT_SECONDS must be a number of seconds."

    case "$(git remote get-url origin 2>/dev/null || true)" in
        github-flighthub:*) ;;
        *) refuse "origin must be github-flighthub:racsdteam/flightHub.git (the read-only deploy key, docs/DEPLOY.md step 3)." ;;
    esac
    [ -z "$(git status --porcelain)" ] || refuse "The git working tree is not clean (git status): commit nothing on the server; restore the files."

    # TARGET: the commit to deploy, fixed once. A re-run gets it from the first run and never
    # resolves the upstream again, so both deploy the same commit.
    if [ "${FLIGHTHUB_DEPLOY_REEXEC:-}" = "1" ]; then
        TARGET="${FLIGHTHUB_DEPLOY_TARGET:-}"
        [[ "$TARGET" =~ ^[0-9a-f]{40}$ ]] && git cat-file -e "${TARGET}^{commit}" 2>/dev/null \
            || refuse "The re-run has no valid FLIGHTHUB_DEPLOY_TARGET."
    else
        [ -z "${FLIGHTHUB_DEPLOY_TARGET:-}" ] || refuse "FLIGHTHUB_DEPLOY_TARGET is set by this script for its own re-run only; unset it."
        git fetch --quiet origin || refuse "git fetch from origin failed (deploy key, network)."
        TARGET="$(git rev-parse --verify --quiet '@{u}^{commit}' || true)"
        [[ "$TARGET" =~ ^[0-9a-f]{40}$ ]] || refuse "The branch has no upstream commit to deploy (git branch -u origin/main)."
    fi
    PREVIOUS="$(git rev-parse HEAD)"
    git merge-base --is-ancestor "$PREVIOUS" "$TARGET" \
        || refuse "HEAD ($(short "$PREVIOUS")) is not the fetched commit ($(short "$TARGET")) or its ancestor: the server has a commit of its own, or the branch was rewritten. Nothing is deployed."
    echo "  Deploying $(short "$TARGET") (now $(short "$PREVIOUS"))."

    local authorised
    authorised="$(git show "$TARGET:scripts/deploy.sh" 2>/dev/null | sed -n 's/^DEPLOY_AUTHORISATION="\(.*\)"$/\1/p' || true)"
    if [[ ! "${FLIGHTHUB_DEPLOY_AUTHORIZED:-}" =~ ^yes-[0-9]{4}-[0-9]{2}-[0-9]{2}-[a-z]{2,4}$ ]] \
        || [ "${FLIGHTHUB_DEPLOY_AUTHORIZED}" != "$authorised" ]; then
        cat >&2 <<'BLOCKED'
REFUSING TO DEPLOY: this deploy is not yet authorised (D-049).

The script accepts exactly one token: DEPLOY_AUTHORISATION in scripts/deploy.sh of the commit
being deployed (the upstream commit, fetched just now). Write it in the commit that prepares the
deploy:

    DEPLOY_AUTHORISATION="yes-<YYYY-MM-DD>-<initials>"

Commit that change and push it (no pull by hand on the server), then run on the server:

    FLIGHTHUB_DEPLOY_AUTHORIZED=yes-<YYYY-MM-DD>-<initials> scripts/deploy.sh

The token is a record of WHO authorised WHICH deploy, not a permanent bypass: the next deploy
needs a new token in a new commit. It is never passed as an argument.
BLOCKED
        trap - EXIT
        exit 1
    fi

    # A changed deploy.sh is re-run once, BEFORE maintenance and before the DDL prompt, from a
    # copy of TARGET's version in the deploy folder (the working tree is not touched yet).
    if [ "${FLIGHTHUB_DEPLOY_REEXEC:-}" != "1" ] \
        && [ "$(git rev-parse "$TARGET:scripts/deploy.sh")" != "$(git rev-parse "HEAD:scripts/deploy.sh")" ]; then
        mkdir -p "$DEPLOY_DIR"
        chmod 700 "$DEPLOY_DIR"
        local rerun
        rerun="$(mktemp "$DEPLOY_DIR/deploy-rerun.XXXXXX")"
        git show "$TARGET:scripts/deploy.sh" > "$rerun"
        chmod 700 "$rerun"
        echo "  scripts/deploy.sh differs in the commit being deployed: running that version once ($rerun)."
        trap - EXIT
        FLIGHTHUB_DEPLOY_REEXEC=1 FLIGHTHUB_DEPLOY_TARGET="$TARGET" exec bash "$rerun"
    fi

    # The DDL credentials are checked HERE, before maintenance, as KIA PULSE learned: a missing
    # variable found after `artisan down` is an outage with no purpose. Not exported, not reused
    # beyond the assignment prefixes below.
    DDL_USER="${FLIGHTHUB_DB_DDL_USER:-}"
    [ -n "$DDL_USER" ] || refuse "Set FLIGHTHUB_DB_DDL_USER (the DDL user, e.g. sysrac_fhddl): required before maintenance goes on."
    if [ -n "${FLIGHTHUB_DB_DDL_PASSWORD:-}" ]; then
        DDL_PASS="$FLIGHTHUB_DB_DDL_PASSWORD"
    else
        printf 'DDL password for %s (typed, not shown): ' "$DDL_USER" >&2
        IFS= read -rs DDL_PASS || DDL_PASS=""
        printf '\n' >&2
    fi
    unset FLIGHTHUB_DB_DDL_PASSWORD
    [ -n "$DDL_PASS" ] || refuse "No DDL password (FLIGHTHUB_DB_DDL_PASSWORD or the prompt): required before maintenance goes on."

    if [ -f "$APP_DIR/vendor/autoload.php" ]; then
        # A wrong password is found now, with the code on disk, not at the migrations.
        ddl_login >/dev/null || refuse "The DDL login failed with these credentials (hub:preflight --ddl-login)."
    else
        FIRST_INSTALL="yes"
    fi
}

maintenance_on() {
    step "Maintenance mode"
    # `--secret` is a bypass for THIS deploy only, used by the serving check; it dies with `up`.
    # The trap is armed AFTER this on purpose: if `down` failed, the site is not in maintenance.
    BYPASS_VALUE="$("$PHP" -r 'echo bin2hex(random_bytes(16));')"
    if ! "$PHP" artisan down --render="errors::503" --retry=15 --secret="$BYPASS_VALUE" >/dev/null; then
        echo "" >&2
        if [ "$FILES_CHANGED" = "yes" ]; then
            echo "✗ Could not enter maintenance mode. First install: nothing is live and the database has not" >&2
            echo "  been touched. Fix the cause and re-run scripts/deploy.sh." >&2
        else
            echo "✗ Could not enter maintenance mode. NOTHING has been changed: the site is still live and the" >&2
            echo "  database has not been touched. Fix the cause and re-run scripts/deploy.sh." >&2
        fi
        trap - EXIT
        exit 1
    fi
    trap on_failure EXIT
}

# A poll, re-apply or backfill that started before maintenance may still be writing; the scheduler
# starts none in maintenance mode. No file changes until it has finished.
wait_for_jobs() {
    step "Waiting for running jobs"
    local waited=0
    while pgrep -u "$(id -u)" -f "artisan'? hub:(poll|reapply-corrections|backfill)" >/dev/null 2>&1; do
        if [ "$waited" -ge "$JOB_WAIT_SECONDS" ]; then
            echo "" >&2
            echo "✗ A hub:poll, hub:reapply-corrections or hub:backfill is still running after ${JOB_WAIT_SECONDS} s." >&2
            "$PHP" artisan up >/dev/null
            trap - EXIT
            if [ "$FILES_CHANGED" = "yes" ]; then
                echo "  First install: nothing is live and the database has not been touched; maintenance has been lifted." >&2
            else
                echo "  NOTHING has been changed (code and database as before); maintenance has been lifted." >&2
            fi
            echo "  Re-run scripts/deploy.sh when the job has finished." >&2
            exit 1
        fi
        if [ "$waited" -eq 0 ]; then
            echo "  A poll, re-apply or backfill is running: waiting for it (up to ${JOB_WAIT_SECONDS} s)."
        fi
        sleep 1
        waited=$((waited + 1))
    done
    echo "  No job running."
}

# One request through the web server with the bypass cookie. Sets HTTP_CODE, HTTP_LOCATION and
# BAD_COOKIES: every Set-Cookie whose Path is not the base path or under it (KIA PULSE and three
# other apps share the host, D-061), named with its path, never its value.
fetch() {
    local out headers line lower name path
    out="$(curl -sS --max-time 20 -b "laravel_maintenance=$BYPASS_COOKIE" -D - -o /dev/null -w 'HTTP_CODE=%{http_code}' "$1" 2>/dev/null || true)"
    case "$out" in
        *HTTP_CODE=*) HTTP_CODE="${out##*HTTP_CODE=}"; headers="${out%HTTP_CODE=*}" ;;
        *) HTTP_CODE=""; headers="" ;;
    esac
    HTTP_CODE="${HTTP_CODE:-000}"
    HTTP_LOCATION=""
    BAD_COOKIES=""
    while IFS= read -r line; do
        line="${line%$'\r'}"
        lower="$(printf '%s' "$line" | tr '[:upper:]' '[:lower:]')"
        case "$lower" in
            location:*)
                HTTP_LOCATION="${line#*:}"
                HTTP_LOCATION="${HTTP_LOCATION# }"
                ;;
            set-cookie:*)
                name="${line#*:}"
                name="${name# }"
                name="${name%%=*}"
                path="$(printf '%s' "$line" | sed -n 's/.*;[[:space:]]*[Pp][Aa][Tt][Hh]=\([^;]*\).*/\1/p')"
                path="${path%"${path##*[![:space:]]}"}"
                if [ -n "$SERVED_BASE_PATH" ] && [ "$path" != "$SERVED_BASE_PATH" ] && [ "${path#"$SERVED_BASE_PATH"/}" = "$path" ]; then
                    BAD_COOKIES="$BAD_COOKIES $name (path=${path:-none})"
                fi
                ;;
        esac
    done <<HEADERS
$headers
HEADERS
}

# The answer of one URL must set no cookie outside the base path.
check_cookies() {
    if [ -n "$BAD_COOKIES" ]; then
        echo "" >&2
        echo "✗ $1 set a cookie outside $SERVED_BASE_PATH:$BAD_COOKIES" >&2
        echo "  The hub's cookies must stay under its base path (KIA PULSE and other apps share the host):" >&2
        echo "  SESSION_PATH=$SERVED_BASE_PATH and SESSION_COOKIE=flighthub_session in .env (docs/DEPLOY.md step 5)." >&2
        exit 1
    fi
}

root_hint() {
    case "$1" in
        405) echo "  405 is the route-cache or DirectoryIndex signature: under a base path there must be no route" >&2
             echo "  cache ($PHP artisan route:clear), and public/.htaccess needs DirectoryIndex index.php." >&2 ;;
        404) echo "  404 means the web server has nothing at this address: check the symlink" >&2
             echo "  public_html$SERVED_BASE_PATH → $APP_DIR/public (docs/DEPLOY.md steps 1 and 4)." >&2 ;;
        500) echo "  500 means the application failed while serving: read storage/logs/laravel.log." >&2 ;;
        503) echo "  503 means the bypass cookie was refused: the request met the maintenance page." >&2 ;;
        000) echo "  000 means the request never completed: DNS, TLS or the web server, not the application." >&2 ;;
    esac
}

# A real request through the web server, before anybody else can make one: the CLI checks never
# ask the web server anything. Every address comes from config('app.url') (APP_URL, base path
# included). The bypass is a cookie made by the framework's own MaintenanceModeBypassCookie (KIA
# PULSE's approach; `down --secret`'s /<secret> address does not work under a base path): the
# prerendered 503 page and the middleware both accept it, whatever the path.
serving_check() {
    local live_url
    live_url="$(app_config app.url)"
    live_url="${live_url%/}"
    SERVED_BASE_PATH="$(app_config hub.base_path)"
    BYPASS_COOKIE="$("$PHP" scripts/maintenance-bypass-cookie.php "$BYPASS_VALUE")"

    # APP_URL itself: the web server may first add the slash (mod_dir: 301 to APP_URL/).
    fetch "$live_url"
    case "$HTTP_CODE" in
        200|302) echo "  $live_url answered $HTTP_CODE." ;;
        301|308)
            if [ "$HTTP_LOCATION" = "$live_url/" ]; then
                echo "  $live_url answered $HTTP_CODE to $live_url/ (the web server adds the slash)."
            else
                echo "" >&2
                echo "✗ $live_url answered $HTTP_CODE to another address; expected 200, 302, or a redirect to $live_url/." >&2
                exit 1
            fi
            ;;
        *)
            echo "" >&2
            echo "✗ $live_url answered $HTTP_CODE through the web server, after the caches were rebuilt." >&2
            root_hint "$HTTP_CODE"
            exit 1
            ;;
    esac
    check_cookies "$live_url"

    fetch "$live_url/"
    case "$HTTP_CODE" in
        200|302) echo "  $live_url/ answered $HTTP_CODE: the application is serving." ;;
        *)
            echo "" >&2
            echo "✗ $live_url/ answered $HTTP_CODE through the web server, after the caches were rebuilt." >&2
            root_hint "$HTTP_CODE"
            exit 1
            ;;
    esac
    check_cookies "$live_url/"

    fetch "$live_url/api/v1/health"
    if [ "$HTTP_CODE" != "401" ]; then
        echo "" >&2
        echo "✗ $live_url/api/v1/health without a key answered $HTTP_CODE; expected 401 (the API refuses a call with no key)." >&2
        case "$HTTP_CODE" in
            200) echo "  200 without a key means the API is not checking keys: do NOT lift maintenance." >&2 ;;
            404) echo "  404 means the API routes are missing (routes/api.php, bootstrap/app.php)." >&2 ;;
            500) echo "  500 means the application failed while serving: read storage/logs/laravel.log." >&2 ;;
            000) echo "  000 means the request never completed: DNS, TLS or the web server, not the application." >&2 ;;
        esac
        exit 1
    fi
    check_cookies "$live_url/api/v1/health"
    echo "  $live_url/api/v1/health answered 401 without a key, as it must."
    if [ -n "$SERVED_BASE_PATH" ]; then
        echo "  No cookie outside $SERVED_BASE_PATH."
    fi
}

main() {
    case "${1:-}" in
        --dry-run) dry_run; exit 0 ;;
        "") ;;
        *) echo "usage: scripts/deploy.sh [--dry-run]   (the token is never an argument)" >&2; exit 2 ;;
    esac

    trap before_maintenance_failure EXIT
    preconditions

    if [ "$FIRST_INSTALL" = "yes" ]; then
        # Nothing is live yet: the code and vendor/ first (artisan needs them), then the DDL login.
        step "Updating the code (first install)"
        update_code
        step "Dependencies (first install)"
        composer_install
        step "DDL login (first install)"
        ddl_login >/dev/null || refuse "The DDL login failed with these credentials (hub:preflight --ddl-login)."
        maintenance_on
        wait_for_jobs
    else
        maintenance_on
        wait_for_jobs
        step "Updating the code"
        update_code
        step "Dependencies (production only)"
        composer_install
    fi

    step "Clearing the config cache"
    # A config cache from the previous deploy would make Laravel ignore the DDL assignment prefix
    # below (a cached config is never re-read from the environment).
    "$PHP" artisan config:clear

    step "Migrations (as the DDL user)"
    DB_DDL_USERNAME="$DDL_USER" DB_DDL_PASSWORD="$DDL_PASS" "$PHP" artisan migrate --database=mariadb_ddl --force

    step "Grants"
    # The runtime user's grants are compared with config/grants.php on every deploy. They differ
    # when the set of tables changed (always on the first install): then root must run the SQL.
    if "$PHP" artisan hub:check-grants; then
        echo "  The runtime grants match config/grants.php."
    else
        mkdir -p "$DEPLOY_DIR"
        chmod 700 "$DEPLOY_DIR"
        local grants_file
        grants_file="$DEPLOY_DIR/grants-$(date -u +%Y%m%dT%H%M%SZ).sql"
        (umask 077 && DB_DDL_USERNAME="$DDL_USER" DB_DDL_PASSWORD="$DDL_PASS" "$PHP" artisan hub:grants-sql > "$grants_file")
        chmod 600 "$grants_file"
        echo ""
        echo "  The grants changed. As root (WHM → phpMyAdmin → SQL), paste and run the whole file:"
        echo "      $grants_file"
        printf '  Press Enter once it has run... '
        read -r _ || true
        echo ""
        "$PHP" artisan hub:check-grants
    fi

    step "Re-apply corrections"
    "$PHP" artisan hub:reapply-corrections

    step "Caches"
    # CLEAR, THEN BUILD. The route cache is cleared always and built only when the hub is served
    # at a domain root: under a base path (/flighthub, D-061) a route cache answers 405 on the
    # base path, as KIA PULSE found under /pulse.
    # NOT optimize:clear and NOT cache:clear: they empty the cache store, which holds the
    # scheduler locks, the rate limits, the AODB token and the AODB pause state.
    "$PHP" artisan config:clear
    "$PHP" artisan route:clear
    "$PHP" artisan view:clear
    "$PHP" artisan event:clear
    "$PHP" artisan clear-compiled
    "$PHP" artisan config:cache
    local base_path
    base_path="$(app_config hub.base_path)"
    if [ -z "$base_path" ]; then
        "$PHP" artisan route:cache
    else
        echo "  No route cache: the hub is served under $base_path (D-061)."
    fi
    "$PHP" artisan view:cache
    "$PHP" artisan event:cache

    step "Serving check"
    serving_check

    step "Preflight"
    if ! DB_DDL_USERNAME="$DDL_USER" DB_DDL_PASSWORD="$DDL_PASS" "$PHP" artisan hub:preflight; then
        echo ""
        echo "  Fix every FAIL above, then re-run scripts/deploy.sh. Do NOT run 'artisan up' by hand to get"
        echo "  past this: each check is a property the hub promises RAC's systems."
        exit 1
    fi

    step "Up"
    "$PHP" artisan up
    trap - EXIT

    echo "==> Done. Deployed $(short "$TARGET") (was $(short "$PREVIOUS"))."
}

main "$@"
