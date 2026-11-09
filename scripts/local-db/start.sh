#!/bin/sh
# Start the hub's own local MariaDB instance in the background.
set -eu

MARIADB_BASEDIR=${MARIADB_BASEDIR:-/opt/homebrew/opt/mariadb@10.11}
FLIGHTHUB_DB_HOME=${FLIGHTHUB_DB_HOME:-$HOME/mariadb-flighthub}
CNF=$FLIGHTHUB_DB_HOME/my.cnf
PID=$FLIGHTHUB_DB_HOME/run/mariadb.pid

[ -d "$FLIGHTHUB_DB_HOME/data" ] || { echo "start: not initialised; run scripts/local-db/init.sh" >&2; exit 1; }

if [ -f "$PID" ] && kill -0 "$(cat "$PID")" 2>/dev/null; then
    echo "start: already running (pid $(cat "$PID"))"
    exit 0
fi

nohup "$MARIADB_BASEDIR/bin/mariadbd" --defaults-file="$CNF" \
    >>"$FLIGHTHUB_DB_HOME/log/mariadbd.out" 2>&1 &

i=0
until "$MARIADB_BASEDIR/bin/mariadb-admin" --defaults-file="$CNF" --protocol=socket ping >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge 30 ]; then
        echo "start: server did not come up; see $FLIGHTHUB_DB_HOME/log/error.log" >&2
        exit 1
    fi
    sleep 1
done
echo "start: running (pid $(cat "$PID"))"
