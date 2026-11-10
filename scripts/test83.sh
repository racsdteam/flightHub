#!/bin/sh
# Run the test suite on PHP 8.3, the production version (D-017).
# Binary from PHP83_BIN (default Homebrew php@8.3, installed beside 8.5, not linked).
# Arguments are passed through to artisan test.
set -eu

PHP83_BIN=${PHP83_BIN:-/opt/homebrew/opt/php@8.3/bin/php}
REPO=$(cd "$(dirname "$0")/.." && pwd)

die() { echo "test83: $*" >&2; exit 1; }

[ -x "$PHP83_BIN" ] || die "$PHP83_BIN not found (install Homebrew php@8.3 or set PHP83_BIN)"

version=$("$PHP83_BIN" -r 'echo PHP_VERSION;')
case "$version" in
    8.3.*) ;;
    *) die "$PHP83_BIN reports PHP $version, not 8.3.x" ;;
esac
"$PHP83_BIN" -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);' || die "$PHP83_BIN has no pdo_mysql"

echo "test83: PHP $version ($PHP83_BIN)"
cd "$REPO"
"$PHP83_BIN" artisan config:clear --ansi
exec "$PHP83_BIN" artisan test "$@"
