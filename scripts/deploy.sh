#!/usr/bin/env bash
#
# RAC Flight Data Hub — deploy to flightdata.rac.co.rw (D-049). Runbook: docs/DEPLOY.md.
#
# Run ON the server, in cPanel Terminal, as the account user sysrac — never root:
#
#     FLIGHTHUB_DEPLOY_AUTHORIZED=yes-<YYYY-MM-DD>-<initials> scripts/deploy.sh
#
# The DDL credentials (migrations and grants only, D-014):
#   FLIGHTHUB_DB_DDL_USER       required, from the operator's environment
#   FLIGHTHUB_DB_DDL_PASSWORD   from the operator's environment, or typed at the prompt (not
#                               echoed). Never stored, never written to a file, never cached,
#                               never exported by this script.
#
#     scripts/deploy.sh --dry-run    prints the steps and changes nothing
#
# Overrides for tests and unusual hosts: FLIGHTHUB_APP_DIR, FLIGHTHUB_DEPLOY_DIR, FLIGHTHUB_PHP,
# FLIGHTHUB_COMPOSER (composer's PHP file, run with FLIGHTHUB_PHP).
#
# Mirrors KIA PULSE's deploy.sh: the authorisation token, the preconditions before maintenance,
# the trap armed after `artisan down` that leaves maintenance on, the serving check through the
# public URL with a bypass cookie, preflight before `artisan up`.

set -euo pipefail

# ─────────────────────────────────────────────────────────────────────────────────────────────
#  THE AUTHORISATION TOKEN (D-049).
#
#  FLIGHTHUB_DEPLOY_AUTHORIZED must equal this value, in the form yes-<YYYY-MM-DD>-<initials>.
#  The committed value is not in that form, so it refuses every deploy. Dany writes the real
#  token here in the commit that prepares a deploy, so the history records WHO authorised WHICH
#  deploy; the next deploy needs a new token in a new commit. Never an argument.
#
#  The value checked is the one in THIS FILE AS IT IS IN THE COMMIT BEING DEPLOYED (origin's
#  branch, fetched first; the fetch leaves the working tree alone). Checked against the copy on
#  disk, the commit carrying the token could only deploy after a manual pull, and a changed
#  deploy.sh could never be re-run by the pull below.
# ─────────────────────────────────────────────────────────────────────────────────────────────
DEPLOY_AUTHORISATION="not-yet-authorised"

APP_DIR="${FLIGHTHUB_APP_DIR:-/home/sysrac/flight-hub}"
DEPLOY_DIR="${FLIGHTHUB_DEPLOY_DIR:-/home/sysrac/flight-hub-deploy}"
PHP="${FLIGHTHUB_PHP:-/usr/local/bin/ea-php83}"
COMPOSER_BIN="${FLIGHTHUB_COMPOSER:-}"
CURRENT_STEP="startup"
DDL_USER=""
DDL_PASS=""
DDL_VERIFIED="no"
PULLED="no"

# Nothing from the operator's shell may reach the config cache or replace the .env: the DDL
# credentials travel only as an assignment prefix on the three commands that need them.
unset DB_DDL_USERNAME DB_DDL_PASSWORD DB_USERNAME DB_PASSWORD

step() {
    CURRENT_STEP="$1"
    echo "==> $1"
}

refuse() {
    trap - EXIT
    echo "" >&2
    echo "✗ $1" >&2
    if [ "$PULLED" = "yes" ]; then
        echo "  The site was NOT put in maintenance and the database was not touched, but git pull and" >&2
        echo "  composer already ran: the live site now runs the pulled code. Fix the cause and re-run." >&2
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
    if [ "$PULLED" = "yes" ]; then
        echo "  The site is live and the database was not touched, but the code was already pulled: the live" >&2
        echo "  site runs it now. Fix the cause and re-run scripts/deploy.sh." >&2
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
#  nothing lifts maintenance automatically; the handler names the step, because whether the
#  database was touched depends on it.
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
    case "$CURRENT_STEP" in
        "Migrations (as the DDL user)")
            echo "  THE DATABASE WAS BEING WRITTEN WHEN THIS FAILED. Some migrations may have applied and others"
            echo "  not. The append-only triggers are created by migrations, so a partial run can leave a history"
            echo "  table that accepts writes it should refuse. Check the state BEFORE deciding anything:"
            echo "      read -rs P; DB_DDL_USERNAME=<DDL user> DB_DDL_PASSWORD=\"\$P\" $PHP artisan migrate:status --database=mariadb_ddl; unset P"
            ;;
        "Grants")
            echo "  Migrations had completed, so the schema is whole. The runtime user's grants do not match"
            echo "  config/grants.php yet: run the grants file as root (phpMyAdmin), then $PHP artisan hub:check-grants."
            ;;
        "Re-apply corrections"|"Caches"|"Serving check"|"Preflight"|"Up")
            echo "  Migrations had completed, so the schema is whole. The failure is after the database was written."
            if [ "$CURRENT_STEP" = "Caches" ]; then
                echo ""
                echo "  IF EVERY ARTISAN COMMAND NOW FAILS AT BOOT, a frozen config cache is the cause and"
                echo "  config:clear cannot remove it (it boots the framework first). Delete it by hand:"
                echo "      rm $APP_DIR/bootstrap/cache/config.php"
                echo "  then $PHP artisan --version answers again. Fix the .env and re-run this script."
            fi
            ;;
        *)
            echo "  This step runs BEFORE any migration, so the database was not touched."
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
    · git fetch; FLIGHTHUB_DEPLOY_AUTHORIZED equals DEPLOY_AUTHORISATION in scripts/deploy.sh of the commit
      being deployed (yes-<YYYY-MM-DD>-<initials>)
    · FLIGHTHUB_DB_DDL_USER set; FLIGHTHUB_DB_DDL_PASSWORD from the environment or typed (not echoed)
    · the DDL login works (hub:preflight --ddl-login; on the first install after composer)
  1. Pull            git pull --ff-only; if scripts/deploy.sh changed, re-run the new one once
  2. Dependencies    composer install --no-dev --optimize-autoloader --no-interaction
  3. Maintenance     artisan down --render="errors::503" --retry=15 --secret=<random, this deploy only>
                     (from here any failure leaves maintenance on and names the step)
  4. Config cache    artisan config:clear (so the DDL prefix below reaches the config)
  5. Migrations      DB_DDL_USERNAME=… DB_DDL_PASSWORD=… artisan migrate --database=mariadb_ddl --force
  6. Grants          hub:check-grants; when they differ: hub:grants-sql (DDL prefix) to
                     $DEPLOY_DIR/grants-<time>.sql (mode 600), pause until run as root, check again
  7. Re-apply        artisan hub:reapply-corrections
  8. Caches          config/route/view/event:clear, clear-compiled; config/route/view/event:cache
                     (never optimize:clear or cache:clear)
  9. Serving check   through APP_URL with the bypass cookie: GET / → 200|302, GET /api/v1/health → 401
 10. Preflight       DB_DDL_USERNAME=… DB_DDL_PASSWORD=… artisan hub:preflight (with the DDL checks)
 11. Up              artisan up
STEPS
}

script_sum() {
    cksum < "$APP_DIR/scripts/deploy.sh"
}

ddl_login() {
    DB_DDL_USERNAME="$DDL_USER" DB_DDL_PASSWORD="$DDL_PASS" "$PHP" artisan hub:preflight --ddl-login
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

    case "$(git remote get-url origin 2>/dev/null || true)" in
        github-flighthub:*) ;;
        *) refuse "origin must be github-flighthub:racsdteam/flightHub.git (the read-only deploy key, docs/DEPLOY.md step 3)." ;;
    esac
    [ -z "$(git status --porcelain)" ] || refuse "The git working tree is not clean (git status): commit nothing on the server; restore the files."

    git fetch --quiet origin || refuse "git fetch from origin failed (deploy key, network)."
    local authorised
    authorised="$(git show '@{u}:scripts/deploy.sh' 2>/dev/null | sed -n 's/^DEPLOY_AUTHORISATION="\(.*\)"$/\1/p' || true)"
    if [[ ! "${FLIGHTHUB_DEPLOY_AUTHORIZED:-}" =~ ^yes-[0-9]{4}-[0-9]{2}-[0-9]{2}-[a-z]{2,4}$ ]] \
        || [ "${FLIGHTHUB_DEPLOY_AUTHORIZED}" != "$authorised" ]; then
        cat >&2 <<'BLOCKED'
REFUSING TO DEPLOY: this deploy is not yet authorised (D-049).

The script accepts exactly one token: DEPLOY_AUTHORISATION in scripts/deploy.sh of the commit being
deployed (origin's branch, fetched just now). Write it in the commit that prepares the deploy:

    DEPLOY_AUTHORISATION="yes-<YYYY-MM-DD>-<initials>"

Commit that change and push it (no pull by hand on the server), then run on the server:

    FLIGHTHUB_DEPLOY_AUTHORIZED=yes-<YYYY-MM-DD>-<initials> scripts/deploy.sh

The token is a record of WHO authorised WHICH deploy, not a permanent bypass: the next deploy
needs a new token in a new commit. It is never passed as an argument.
BLOCKED
        trap - EXIT
        exit 1
    fi

    # The DDL credentials are checked HERE, before maintenance, as KIA PULSE learned: a missing
    # variable found after `artisan down` is an outage with no purpose. Not exported, not reused
    # beyond the three assignment prefixes below.
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

    # A wrong password is found now, not at the migrations. On the first install there is no
    # vendor/ yet: checked after composer, still before maintenance.
    if [ -f "$APP_DIR/vendor/autoload.php" ]; then
        ddl_login >/dev/null || refuse "The DDL login failed with these credentials (hub:preflight --ddl-login)."
        DDL_VERIFIED="yes"
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

    step "Pull"
    local before after
    before="$(script_sum)"
    git pull --ff-only
    PULLED="yes"
    after="$(script_sum)"
    if [ "$before" != "$after" ]; then
        if [ "${FLIGHTHUB_DEPLOY_REEXEC:-}" = "1" ]; then
            echo "  scripts/deploy.sh changed again after the re-run; continuing with this copy (no second re-run)."
        else
            echo "  scripts/deploy.sh changed in this pull: re-running the new version once (preconditions again)."
            trap - EXIT
            FLIGHTHUB_DEPLOY_REEXEC=1 exec bash "$APP_DIR/scripts/deploy.sh"
        fi
    fi

    step "Dependencies (production only)"
    "$PHP" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

    if [ "$DDL_VERIFIED" != "yes" ]; then
        step "DDL login (first install)"
        ddl_login >/dev/null || refuse "The DDL login failed with these credentials (hub:preflight --ddl-login)."
        DDL_VERIFIED="yes"
    fi

    step "Maintenance mode"
    # `--secret` is a bypass for THIS deploy only, used by the serving check; it dies with `up`.
    # The trap is armed AFTER this on purpose: if `down` failed, the site is not in maintenance.
    local bypass_value
    bypass_value="$("$PHP" -r 'echo bin2hex(random_bytes(16));')"
    if ! "$PHP" artisan down --render="errors::503" --retry=15 --secret="$bypass_value" >/dev/null; then
        echo "" >&2
        echo "✗ Could not enter maintenance mode. The database has NOT been touched and the site is still live" >&2
        echo "  (running the pulled code). Fix the cause and re-run scripts/deploy.sh." >&2
        trap - EXIT
        exit 1
    fi
    trap on_failure EXIT

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
    # CLEAR, THEN BUILD. Route cache allowed: the hub is served at the subdomain root (D-011).
    # NOT optimize:clear and NOT cache:clear: they empty the cache store, which holds the
    # scheduler locks, the rate limits, the AODB token and the AODB pause state.
    "$PHP" artisan config:clear
    "$PHP" artisan route:clear
    "$PHP" artisan view:clear
    "$PHP" artisan event:clear
    "$PHP" artisan clear-compiled
    "$PHP" artisan config:cache
    "$PHP" artisan route:cache
    "$PHP" artisan view:cache
    "$PHP" artisan event:cache

    step "Serving check"
    # A real request through the web server, before anybody else can make one: the CLI checks
    # above never ask the web server anything. The bypass is a cookie made by the framework's
    # own MaintenanceModeBypassCookie (KIA PULSE's approach): the prerendered 503 page and the
    # middleware both accept it, whatever the path.
    local live_url bypass root health
    live_url="$("$PHP" -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo rtrim((string) config("app.url"), "/");')"
    bypass="$("$PHP" scripts/maintenance-bypass-cookie.php "$bypass_value")"
    root="$(curl -sS --max-time 20 -b "laravel_maintenance=$bypass" -o /dev/null -w '%{http_code}' "$live_url/" 2>/dev/null || true)"
    health="$(curl -sS --max-time 20 -b "laravel_maintenance=$bypass" -o /dev/null -w '%{http_code}' "$live_url/api/v1/health" 2>/dev/null || true)"
    root="${root:-000}"
    health="${health:-000}"
    case "$root" in
        200|302) echo "  $live_url/ answered $root: the application is serving." ;;
        *)
            echo "" >&2
            echo "✗ $live_url/ answered $root through the web server, after the caches were rebuilt." >&2
            case "$root" in
                405) echo "  405 on / is the route-cache signature: $PHP artisan route:clear, then investigate." >&2 ;;
                500) echo "  500 means the application failed while serving: read storage/logs/laravel.log." >&2 ;;
                503) echo "  503 means the bypass cookie was refused: the request met the maintenance page." >&2 ;;
                000) echo "  000 means the request never completed: DNS, TLS or the web server, not the application." >&2 ;;
            esac
            exit 1
            ;;
    esac
    if [ "$health" != "401" ]; then
        echo "" >&2
        echo "✗ $live_url/api/v1/health without a key answered $health; expected 401 (the API refuses a call with no key)." >&2
        case "$health" in
            200) echo "  200 without a key means the API is not checking keys: do NOT lift maintenance." >&2 ;;
            404) echo "  404 means the API routes are missing (routes/api.php, bootstrap/app.php)." >&2 ;;
            500) echo "  500 means the application failed while serving: read storage/logs/laravel.log." >&2 ;;
            000) echo "  000 means the request never completed: DNS, TLS or the web server, not the application." >&2 ;;
        esac
        exit 1
    fi
    echo "  $live_url/api/v1/health answered 401 without a key, as it must."

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

    echo "==> Done. Deploy complete."
}

main "$@"
