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

5. Poll locally without the live API: the fixture client serves the newest file in
   `tests/Fixtures/aodb/`. It is refused when APP_ENV=production. (Live polls: Dany only,
   see "Local testing" below.)

       AODB_CLIENT=fixture php artisan hub:poll

   A second run with the same fixture reports "content unchanged" and writes only
   `last_seen_at`. The flights land in the dev database `flighthub`.

6. Reference data (airport_code_corrections, aircraft_types, stands) is entered in the admin
   screens (Reference, admin and data steward): the signed-in user is recorded as reviewer.
   Every change marks "re-apply pending"; the scheduler runs

       php artisan hub:reapply-corrections --if-pending

   every 5 minutes, which re-evaluates every flight's served values against the active
   reference data (new version and correction events where a value changes; a second run
   writes nothing). `php artisan hub:reapply-corrections` (without the option) runs it now.

7. Run the tests on PHP 8.3 (Homebrew php@8.3, or set `PHP83_BIN`; MariaDB database
   `flighthub_test`, never SQLite):

       composer test

## Local testing

The whole hub runs on this Mac against its own local database. Use PHP 8.3
(`PHP=/opt/homebrew/opt/php@8.3/bin/php` below).

1. Start the database (and check it):

       scripts/local-db/start.sh
       scripts/local-db/status.sh

2. Create an admin account. The temporary password is printed once; the first sign-in
   asks for a new password and TOTP enrolment:

       $PHP artisan hub:admin-create you@rac.co.rw "Your Name" --role=admin

3. Serve on PHP 8.3 at http://127.0.0.1:8000 (the local server listed in the API
   document):

       $PHP artisan serve --host=127.0.0.1 --port=8000

4. Choose the data source:
   - **Fixture mode**: the newest file in `tests/Fixtures/aodb/`, no network. An
     environment variable wins over `.env`:

         AODB_CLIENT=fixture $PHP artisan hub:poll

   - **Live mode** (Dany only, D-050): `AODB_CLIENT=http` and the real AODB credentials in
     your local `.env` (`chmod 600 .env`; git-ignored; refused by the pre-commit hook;
     never in an iCloud-synced folder). Only Dany runs live polls; Claude Code never runs a
     command that reaches the AODB and cannot read `.env`.

5. Run the scheduler in the foreground (hub:poll every 5 minutes, check-feed, re-apply,
   prunes; Ctrl-C stops it). It polls with the client chosen above, so prefix it with
   `AODB_CLIENT=fixture` to stay offline:

       AODB_CLIENT=fixture $PHP artisan schedule:work

6. Create a consumer and a key (or use the Consumers screen). The key is printed once:

       $PHP artisan hub:consumer-create local-test "Local test" --groups=route,schedule,times,quality --by="Your Name"
       $PHP artisan hub:key-issue local-test --label="local testing" --by="Your Name"

7. Open http://127.0.0.1:8000/api/docs, click **Authorize**, paste the key, then
   **Try it out**. The page keeps the key in memory only; every call is logged in
   `api_calls` like any other. The document shown is the public one, `/api/openapi.yaml`
   (`resources/api/public-v1.openapi.yaml`, with `APP_URL` + `/api/v1` as its server, so
   `APP_URL` must be the address you open, e.g. `http://127.0.0.1:8000`). The complete internal
   document, `resources/api/flighthub-v1.openapi.yaml`, is not served.
