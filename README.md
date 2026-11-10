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

4. Run migrations as the DDL user (never as the runtime user):

       php artisan migrate --database=mariadb_ddl

5. Run the tests on PHP 8.3 (Homebrew php@8.3, or set `PHP83_BIN`; MariaDB database
   `flighthub_test`, never SQLite):

       composer test
