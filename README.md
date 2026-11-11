# RAC Flight Data Hub

Collects, cleans and versions Kigali International Airport AODB flight data and
distributes it to other RAC systems through its own API.

## Local setup

1. Enable the versioned git hooks:

       git config core.hooksPath .githooks

2. Install PHP dependencies:

       composer install

3. Create and start the hub's own local MariaDB (data in `~/mariadb-flighthub`,
   port 3317). `init.sh` runs once and writes `.env` and `.env.testing`:

       scripts/local-db/init.sh
       scripts/local-db/start.sh     # also: stop.sh, status.sh

   Binaries come from `MARIADB_BASEDIR` (default `/opt/homebrew/opt/mariadb@10.11`);
   the instance home can be changed with `FLIGHTHUB_DB_HOME`.

4. Run migrations as the DDL user (never as the runtime user) on both databases, then
   give each runtime user its per-table grants (config/grants.php). Locally, as in
   production, each runtime user holds exactly one database: `flighthub_app` →
   `flighthub` (.env), `flighthub_app_test` → `flighthub_test` (.env.testing).

       php artisan migrate --database=mariadb_ddl
       php artisan migrate --database=mariadb_ddl --env=testing
       scripts/local-db/apply-grants.sh

   After a migration that adds a table: add it to config/grants.php, migrate both
   databases as DDL (the two migrate commands above), then run apply-grants.sh.
   Verify with `php artisan hub:check-grants` and `php artisan hub:check-grants --env=testing`.

5. Poll locally without the live API (no AODB credentials exist on this Mac): the
   fixture client serves the newest file in `tests/Fixtures/aodb/`. It is refused when
   APP_ENV=production.

       AODB_CLIENT=fixture php artisan hub:poll

   A second run with the same fixture reports "content unchanged" and writes only
   `last_seen_at`. The flights land in the dev database `flighthub`.

6. Run the tests on PHP 8.3 (Homebrew php@8.3, or set `PHP83_BIN`; MariaDB database
   `flighthub_test`, never SQLite):

       composer test
