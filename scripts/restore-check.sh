#!/usr/bin/env bash
#
# RAC Flight Data Hub — restore check (D-047). Runbook: docs/DEPLOY.md.
#
#   scripts/restore-check.sh /home/sysrac/flight-hub-backups/flighthub-<stamp>.sql.gz
#
# Loads a dump made by scripts/backup.sh into a SCRATCH database and compares the row count of every
# table, and the trigger count, with the dump's manifest. Exit 0 when everything matches.
#
# The scratch database (sysrac_fhrestore) and its own user, with ALL PRIVILEGES on it and on nothing
# else, are created in cPanel → MySQL Databases. That user's credentials live only in
# /home/sysrac/.flighthub-restore.cnf (mode 600, outside the repository), passed with
# --defaults-extra-file. The production database is refused by name. DEFINER clauses are removed
# while loading, so the triggers are created by the scratch user (no SUPER needed).
#
# Overrides (tests): FLIGHTHUB_RESTORE_DB, FLIGHTHUB_RESTORE_CNF, FLIGHTHUB_BACKUP_DB,
# FLIGHTHUB_APP_DIR, FLIGHTHUB_MYSQL.

set -euo pipefail

DUMP="${1:-}"
RESTORE_DB="${FLIGHTHUB_RESTORE_DB:-sysrac_fhrestore}"
PROD_DB="${FLIGHTHUB_BACKUP_DB:-sysrac_flighthub}"
CNF="${FLIGHTHUB_RESTORE_CNF:-/home/sysrac/.flighthub-restore.cnf}"
APP_DIR="${FLIGHTHUB_APP_DIR:-/home/sysrac/flight-hub}"
CLIENT="${FLIGHTHUB_MYSQL:-$(command -v mariadb || command -v mysql || true)}"

fail() {
    echo "restore-check.sh: $1" >&2
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

query() {
    "$CLIENT" --defaults-extra-file="$CNF" -N -B -e "$1" "$RESTORE_DB"
}

[ -n "$DUMP" ] && [ -f "$DUMP" ] && [[ "$DUMP" == *.sql.gz ]] || fail "usage: scripts/restore-check.sh <flighthub-<stamp>.sql.gz>"
MANIFEST="${DUMP%.sql.gz}.manifest"
[ -f "$MANIFEST" ] || fail "no manifest beside the dump ($MANIFEST)."
[[ "$RESTORE_DB" =~ ^[A-Za-z0-9_]{1,64}$ ]] || fail "invalid scratch database name (FLIGHTHUB_RESTORE_DB)."
[ "$RESTORE_DB" != "$PROD_DB" ] || fail "refusing: the scratch database is the production database ($PROD_DB)."
case "$RESTORE_DB" in
    *restore*|*scratch*) ;;
    *) fail "refusing: the scratch database name must contain 'restore' or 'scratch' ($RESTORE_DB)." ;;
esac
[ -n "$CLIENT" ] || fail "the mysql client was not found (FLIGHTHUB_MYSQL)."
[ -f "$CNF" ] || fail "the credentials file $CNF is missing."
[ "$(mode_of "$CNF")" = "600" ] || fail "the credentials file $CNF must be mode 600."
case "$(cd "$(dirname "$CNF")" && pwd -P)/" in
    "$(cd "$APP_DIR" 2>/dev/null && pwd -P || echo "$APP_DIR")"/*) fail "the credentials file must be outside the repository ($APP_DIR)." ;;
esac

EXPECTED_SHA="$(sed -n 's/^sha256: //p' "$MANIFEST")"
[ "$EXPECTED_SHA" = "$(sha256_of "$DUMP")" ] || fail "the dump's sha256 differs from its manifest: the file is damaged or not the one the manifest describes."

echo "restore-check.sh: loading $(basename "$DUMP") into ${RESTORE_DB}…"
gzip -dc "$DUMP" \
    | sed -E 's#/\*!50017 DEFINER=`[^`]*`@`[^`]*`\*/ ##g; s#DEFINER=`[^`]*`@`[^`]*` ##g' \
    | "$CLIENT" --defaults-extra-file="$CNF" "$RESTORE_DB"

DIFFERENCES=0
TABLES=0
while read -r _ table expected; do
    [[ "$table" =~ ^[A-Za-z0-9_]{1,64}$ ]] || fail "unexpected table name in the manifest."
    actual="$(query "SELECT COUNT(*) FROM \`$table\`")"
    TABLES=$((TABLES + 1))
    if [ "$actual" = "$expected" ]; then
        printf '  ok    %-28s %s rows\n' "$table" "$actual"
    else
        printf '  DIFF  %-28s %s rows in the manifest, %s restored\n' "$table" "$expected" "$actual"
        DIFFERENCES=$((DIFFERENCES + 1))
    fi
done < <(grep '^table ' "$MANIFEST")

expected_triggers="$(sed -n 's/^triggers //p' "$MANIFEST")"
actual_triggers="$(query "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()")"
if [ "$actual_triggers" = "$expected_triggers" ]; then
    printf '  ok    %-28s %s\n' "triggers" "$actual_triggers"
else
    printf '  DIFF  %-28s %s in the manifest, %s restored\n' "triggers" "$expected_triggers" "$actual_triggers"
    DIFFERENCES=$((DIFFERENCES + 1))
fi

if [ "$DIFFERENCES" -gt 0 ]; then
    echo "restore-check.sh: $DIFFERENCES difference(s) over $TABLES tables and the triggers: the restore does NOT match." >&2
    exit 1
fi
echo "restore-check.sh: the restore matches the manifest ($TABLES tables and the triggers)."
