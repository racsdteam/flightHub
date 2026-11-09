#!/bin/sh
# Stop the hub's own local MariaDB instance.
set -eu

MARIADB_BASEDIR=${MARIADB_BASEDIR:-/opt/homebrew/opt/mariadb@10.11}
FLIGHTHUB_DB_HOME=${FLIGHTHUB_DB_HOME:-$HOME/mariadb-flighthub}
CNF=$FLIGHTHUB_DB_HOME/my.cnf
PID=$FLIGHTHUB_DB_HOME/run/mariadb.pid

if ! { [ -f "$PID" ] && kill -0 "$(cat "$PID")" 2>/dev/null; }; then
    echo "stop: not running"
    exit 0
fi

pid=$(cat "$PID")
"$MARIADB_BASEDIR/bin/mariadb-admin" --defaults-file="$CNF" --protocol=socket shutdown

i=0
while kill -0 "$pid" 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 30 ]; then
        echo "stop: server (pid $pid) still running after 30s" >&2
        exit 1
    fi
    sleep 1
done
echo "stop: stopped"
