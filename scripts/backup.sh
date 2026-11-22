#!/usr/bin/env bash
#
# RAC Flight Data Hub — nightly database backup (D-047). Runbook: docs/DEPLOY.md.
#
# cPanel cron, as the account user sysrac:
#   30 1 * * * /home/sysrac/flight-hub/scripts/backup.sh >> /home/sysrac/backups/flighthub/backup.log 2>&1
#
# Dumps sysrac_flighthub as sysrac_fhbackup (SELECT, SHOW VIEW, LOCK TABLES, TRIGGER only) with
# mysqldump --single-transaction --triggers, gzipped, into /home/sysrac/backups/flighthub/, and keeps
# the 14 newest. The credentials live only in /home/sysrac/.flighthub-backup.cnf (mode 600, outside
# the repository), passed with --defaults-extra-file — never on the command line, where ps shows them:
#
#   [client]
#   user=sysrac_fhbackup
#   password=            # the sysrac_fhbackup password, typed into this file on the server
#
# Beside each dump, a manifest: time (UTC), git commit, MariaDB version, size, sha256, the trigger
# count and the row count of every table (counted in the dump itself, one INSERT per row, so the
# counts are exactly the dump's snapshot). scripts/restore-check.sh compares a restore with it.
# The off-server copy (D-047, a go-live blocker) is agreed with AOS; this script makes the local one.
#
# Overrides (tests): FLIGHTHUB_BACKUP_DB, FLIGHTHUB_BACKUP_CNF, FLIGHTHUB_BACKUP_DIR,
# FLIGHTHUB_BACKUP_KEEP, FLIGHTHUB_APP_DIR, FLIGHTHUB_MYSQLDUMP.

set -euo pipefail

DB="${FLIGHTHUB_BACKUP_DB:-sysrac_flighthub}"
CNF="${FLIGHTHUB_BACKUP_CNF:-/home/sysrac/.flighthub-backup.cnf}"
DIR="${FLIGHTHUB_BACKUP_DIR:-/home/sysrac/backups/flighthub}"
APP_DIR="${FLIGHTHUB_APP_DIR:-/home/sysrac/flight-hub}"
KEEP="${FLIGHTHUB_BACKUP_KEEP:-14}"
DUMP_BIN="${FLIGHTHUB_MYSQLDUMP:-$(command -v mariadb-dump || command -v mysqldump || true)}"

fail() {
    echo "backup.sh: $1" >&2
    exit 1
}

mode_of() {
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | cut -d' ' -f1
    else
        shasum -a 256 "$1" | cut -d' ' -f1
    fi
}

[[ "$DB" =~ ^[A-Za-z0-9_]{1,64}$ ]] || fail "invalid database name (FLIGHTHUB_BACKUP_DB)."
[[ "$KEEP" =~ ^[0-9]{1,3}$ ]] && [ "$KEEP" -ge 1 ] || fail "invalid FLIGHTHUB_BACKUP_KEEP."
[ -n "$DUMP_BIN" ] || fail "mysqldump not found (FLIGHTHUB_MYSQLDUMP)."
[ -f "$CNF" ] || fail "the credentials file $CNF is missing (docs/DEPLOY.md, backups)."
[ "$(mode_of "$CNF")" = "600" ] || fail "the credentials file $CNF must be mode 600."
case "$(cd "$(dirname "$CNF")" && pwd -P)/" in
    "$(cd "$APP_DIR" 2>/dev/null && pwd -P || echo "$APP_DIR")"/*) fail "the credentials file must be outside the repository ($APP_DIR)." ;;
esac

mkdir -p "$DIR"
chmod 700 "$DIR"
umask 077
TIME_UTC="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BASE="$DIR/flighthub-$STAMP"
PART="$BASE.sql.gz.partial"
trap 'rm -f "$PART" "$BASE.manifest.partial"' EXIT

# One INSERT per row (--skip-extended-insert): exact row counts below; binary as hex.
"$DUMP_BIN" --defaults-extra-file="$CNF" --single-transaction --triggers --skip-extended-insert --hex-blob \
    --no-tablespaces "$DB" | gzip -6 > "$PART"
gzip -t "$PART"

# One pass over the dump: the server version, the trigger count, the row count of every table.
STATS="$(gzip -dc "$PART" | awk -F'`' '
    /^-- Server version/ { v = $0; sub(/^-- Server version[ \t]*/, "", v) }
    /^CREATE TABLE `/    { if (!($2 in rows)) rows[$2] = 0 }
    /^INSERT INTO `/     { rows[$2]++ }
    /^\/\*!50003 CREATE\*\/.*TRIGGER/ { triggers++ }
    END {
        print "mariadb_version: " (v == "" ? "unknown" : v)
        print "triggers " (triggers + 0)
        for (t in rows) print "table " t " " rows[t]
    }')"
TABLES="$(printf '%s\n' "$STATS" | grep -c '^table ' || true)"
[ "$TABLES" -gt 0 ] || fail "the dump holds no table: refusing to keep it."

SIZE="$(wc -c < "$PART" | tr -d ' ')"
SHA="$(sha256_of "$PART")"
{
    echo "# RAC Flight Data Hub backup manifest (scripts/backup.sh); restore check: scripts/restore-check.sh"
    echo "file: flighthub-$STAMP.sql.gz"
    echo "time_utc: $TIME_UTC"
    echo "database: $DB"
    echo "git_commit: $(git -C "$APP_DIR" rev-parse HEAD 2>/dev/null || echo unknown)"
    printf '%s\n' "$STATS" | grep '^mariadb_version: '
    echo "size_bytes: $SIZE"
    echo "sha256: $SHA"
    printf '%s\n' "$STATS" | grep '^triggers '
    printf '%s\n' "$STATS" | grep '^table ' | sort
} > "$BASE.manifest.partial"
mv "$PART" "$BASE.sql.gz"
mv "$BASE.manifest.partial" "$BASE.manifest"

# Keep the newest KEEP dumps (names sort by time); a dump goes with its manifest.
REMOVED=0
for old in $( { ls -1 "$DIR" | grep -E '^flighthub-[0-9]{8}T[0-9]{6}Z\.sql\.gz$' || true; } | sort -r | tail -n +"$((KEEP + 1))"); do
    rm -f "$DIR/$old" "$DIR/${old%.sql.gz}.manifest"
    REMOVED=$((REMOVED + 1))
done

echo "backup.sh: $TIME_UTC flighthub-$STAMP.sql.gz, $SIZE bytes, sha256 $SHA, $TABLES tables; $REMOVED old dump(s) removed, $KEEP kept at most."
