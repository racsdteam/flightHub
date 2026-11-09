#!/bin/sh
# Show whether the hub's own local MariaDB instance is running, and where.
set -eu

MARIADB_BASEDIR=${MARIADB_BASEDIR:-/opt/homebrew/opt/mariadb@10.11}
FLIGHTHUB_DB_HOME=${FLIGHTHUB_DB_HOME:-$HOME/mariadb-flighthub}
CNF=$FLIGHTHUB_DB_HOME/my.cnf
PID=$FLIGHTHUB_DB_HOME/run/mariadb.pid

if [ ! -d "$FLIGHTHUB_DB_HOME/data" ]; then
    echo "status: not initialised ($FLIGHTHUB_DB_HOME/data missing)"
    exit 1
fi

port=$(sed -n 's/^port=//p' "$CNF" | head -n 1)
echo "home:   $FLIGHTHUB_DB_HOME"
echo "server: 127.0.0.1:$port, socket $FLIGHTHUB_DB_HOME/run/mariadb.sock"
echo "binary: $("$MARIADB_BASEDIR/bin/mariadbd" --version)"

if [ -f "$PID" ] && kill -0 "$(cat "$PID")" 2>/dev/null \
    && "$MARIADB_BASEDIR/bin/mariadb-admin" --defaults-file="$CNF" --protocol=socket ping >/dev/null 2>&1; then
    echo "status: running (pid $(cat "$PID"))"
else
    echo "status: stopped"
    exit 3
fi
