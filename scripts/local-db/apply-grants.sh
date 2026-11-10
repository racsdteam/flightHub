#!/bin/sh
# Give each runtime user its per-table grants (config/grants.php) on its own
# database, as root over the socket: flighthub_app on flighthub (.env), then
# flighthub_app_test on flighthub_test (.env.testing). Each run revokes and
# grants for its own user only. Run after migrating both databases as DDL.
set -eu

MARIADB_BASEDIR=${MARIADB_BASEDIR:-/opt/homebrew/opt/mariadb@10.11}
FLIGHTHUB_DB_HOME=${FLIGHTHUB_DB_HOME:-$HOME/mariadb-flighthub}
PHP83_BIN=${PHP83_BIN:-/opt/homebrew/opt/php@8.3/bin/php}
CNF=$FLIGHTHUB_DB_HOME/my.cnf
REPO=$(cd "$(dirname "$0")/../.." && pwd)

[ -x "$PHP83_BIN" ] || { echo "apply-grants: $PHP83_BIN not found (set PHP83_BIN)" >&2; exit 1; }

# apply LABEL [ARTISAN_OPTION]: grants SQL for one env file, piped to root.
apply() {
    sql=$("$PHP83_BIN" artisan hub:grants-sql --host=127.0.0.1 --no-ansi ${2:+"$2"}) \
        || { echo "apply-grants: hub:grants-sql failed ($1); nothing applied for it" >&2; exit 1; }
    printf '%s\n' "$sql" | "$MARIADB_BASEDIR/bin/mariadb" --defaults-file="$CNF" --protocol=socket
    echo "apply-grants: runtime grants applied ($1)"
}

cd "$REPO"
apply ".env"
apply ".env.testing" --env=testing
