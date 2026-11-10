#!/bin/sh
# Give the runtime user its per-table grants (config/grants.php) on flighthub AND
# flighthub_test in one run (REVOKE ALL is global), as root over the socket.
# Run after migrating both databases as DDL.
set -eu

MARIADB_BASEDIR=${MARIADB_BASEDIR:-/opt/homebrew/opt/mariadb@10.11}
FLIGHTHUB_DB_HOME=${FLIGHTHUB_DB_HOME:-$HOME/mariadb-flighthub}
CNF=$FLIGHTHUB_DB_HOME/my.cnf
REPO=$(cd "$(dirname "$0")/../.." && pwd)

cd "$REPO"
sql=$(php artisan hub:grants-sql --database=flighthub --database=flighthub_test --host=127.0.0.1 --no-ansi) \
    || { echo "apply-grants: hub:grants-sql failed; nothing applied" >&2; exit 1; }

printf '%s\n' "$sql" | "$MARIADB_BASEDIR/bin/mariadb" --defaults-file="$CNF" --protocol=socket
echo "apply-grants: runtime grants applied on flighthub and flighthub_test"
