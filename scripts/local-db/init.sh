#!/bin/sh
# Initialise the hub's own local MariaDB instance (one time).
# Binaries come from MARIADB_BASEDIR; data, my.cnf, socket, pid and logs live
# under FLIGHTHUB_DB_HOME, outside the repo. Root is reachable by unix socket only.
# Generates the DDL and runtime passwords and writes them straight into .env and
# .env.testing (both git-ignored). Passwords are never printed.
set -eu

MARIADB_BASEDIR=${MARIADB_BASEDIR:-/opt/homebrew/opt/mariadb@10.11}
FLIGHTHUB_DB_HOME=${FLIGHTHUB_DB_HOME:-$HOME/mariadb-flighthub}
FLIGHTHUB_DB_PORT=${FLIGHTHUB_DB_PORT:-3317}
CNF=$FLIGHTHUB_DB_HOME/my.cnf
DATA=$FLIGHTHUB_DB_HOME/data
REPO=$(cd "$(dirname "$0")/../.." && pwd)

die() { echo "init: $*" >&2; exit 1; }

for bin in mariadbd mariadb mariadb-install-db mariadb-admin; do
    [ -x "$MARIADB_BASEDIR/bin/$bin" ] || die "$MARIADB_BASEDIR/bin/$bin not found (set MARIADB_BASEDIR)"
done
[ -e "$DATA" ] && die "$DATA already exists; refusing to re-initialise"
[ "$FLIGHTHUB_DB_PORT" = 3306 ] && die "port 3306 is not allowed"
if lsof -nP -iTCP:"$FLIGHTHUB_DB_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    die "port $FLIGHTHUB_DB_PORT is already in use"
fi

mkdir -p "$FLIGHTHUB_DB_HOME/run" "$FLIGHTHUB_DB_HOME/log"
cat > "$CNF" <<EOF
[client]
port=$FLIGHTHUB_DB_PORT
socket=$FLIGHTHUB_DB_HOME/run/mariadb.sock

[mariadbd]
basedir=$MARIADB_BASEDIR
datadir=$DATA
bind-address=127.0.0.1
port=$FLIGHTHUB_DB_PORT
socket=$FLIGHTHUB_DB_HOME/run/mariadb.sock
pid-file=$FLIGHTHUB_DB_HOME/run/mariadb.pid
log-error=$FLIGHTHUB_DB_HOME/log/error.log
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
default-time-zone='+00:00'
EOF

echo "init: initialising $DATA"
"$MARIADB_BASEDIR/bin/mariadb-install-db" --defaults-file="$CNF" \
    --basedir="$MARIADB_BASEDIR" --datadir="$DATA" \
    --auth-root-authentication-method=socket --auth-root-socket-user="$(id -un)" \
    --skip-test-db >"$FLIGHTHUB_DB_HOME/log/install-db.log" 2>&1 \
    || die "mariadb-install-db failed, see $FLIGHTHUB_DB_HOME/log/install-db.log"

FLIGHTHUB_DB_HOME=$FLIGHTHUB_DB_HOME MARIADB_BASEDIR=$MARIADB_BASEDIR sh "$REPO/scripts/local-db/start.sh"

gen_pw() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32; }
ddl_pw=$(gen_pw)
app_pw=$(gen_pw)
app_test_pw=$(gen_pw)

echo "init: creating databases and users"
"$MARIADB_BASEDIR/bin/mariadb" --defaults-file="$CNF" --protocol=socket <<EOF
CREATE DATABASE flighthub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE flighthub_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'flighthub_ddl'@'127.0.0.1' IDENTIFIED BY '$ddl_pw';
GRANT ALL PRIVILEGES ON \`flighthub\`.* TO 'flighthub_ddl'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`flighthub_test\`.* TO 'flighthub_ddl'@'127.0.0.1';
CREATE USER 'flighthub_app'@'127.0.0.1' IDENTIFIED BY '$app_pw';
CREATE USER 'flighthub_app_test'@'127.0.0.1' IDENTIFIED BY '$app_test_pw';
EOF

# set_env FILE KEY: sets KEY to $SET_ENV_VALUE in FILE (replaces or appends).
set_env() {
    SET_ENV_NAME=$2 awk '
        BEGIN { k = ENVIRON["SET_ENV_NAME"]; v = ENVIRON["SET_ENV_VALUE"]; done = 0 }
        index($0, k "=") == 1 { print k "=" v; done = 1; next }
        { print }
        END { if (!done) print k "=" v }
    ' "$1" > "$1.tmp"
    mv "$1.tmp" "$1"
}

# write_env FILE DATABASE APP_ENV RUNTIME_USER RUNTIME_PW: creates FILE from
# .env.example (with a new APP_KEY) when missing, then writes the database
# settings into it. Each database has its own runtime user, as in production.
write_env() {
    if [ ! -e "$1" ]; then
        cp "$REPO/.env.example" "$1"
        SET_ENV_VALUE="base64:$(php -r 'echo base64_encode(random_bytes(32));')" set_env "$1" APP_KEY
    fi
    chmod 600 "$1"
    SET_ENV_VALUE=$3 set_env "$1" APP_ENV
    SET_ENV_VALUE=mariadb set_env "$1" DB_CONNECTION
    SET_ENV_VALUE=127.0.0.1 set_env "$1" DB_HOST
    SET_ENV_VALUE=$FLIGHTHUB_DB_PORT set_env "$1" DB_PORT
    SET_ENV_VALUE=$2 set_env "$1" DB_DATABASE
    SET_ENV_VALUE=$4 set_env "$1" DB_USERNAME
    SET_ENV_VALUE=$5 set_env "$1" DB_PASSWORD
    SET_ENV_VALUE=flighthub_ddl set_env "$1" DB_DDL_USERNAME
    SET_ENV_VALUE=$ddl_pw set_env "$1" DB_DDL_PASSWORD
}

umask 077
write_env "$REPO/.env" flighthub local flighthub_app "$app_pw"
write_env "$REPO/.env.testing" flighthub_test testing flighthub_app_test "$app_test_pw"

echo "init: done. Databases flighthub and flighthub_test on 127.0.0.1:$FLIGHTHUB_DB_PORT;"
echo "init: credentials written to .env and .env.testing (not shown)."
echo "init: the runtime users have no privileges yet: migrate both databases as DDL, then run"
echo "init: scripts/local-db/apply-grants.sh (see README)."
