# RAC Flight Data Hub — rules for Claude Code

## What this is
Independent service of Rwanda Airports Company (RAC IT), Kigali International Airport.
Collects KIA AODB data (Innov'ATM API), cleans it, keeps every change, detects changes and
distributes it to other RAC systems through its own API. First consumer: KIA PULSE.
Never depends on KIA PULSE: own repo, database, subdomain (flightdata.rac.co.rw), credentials.

## How we work
- Do only the batch you were given. No features, packages or screens it does not ask for;
  propose them in the report.
- Every batch ends with a report: what was done, deviations and why, command outputs,
  proposals, open questions.
- Decisions are in docs/DECISIONS.md (git-excluded). Follow them; flag conflicts; never
  change one yourself.
- The report lists every deviation from the prompt, however small, including anything
  added. Claim 'matches the spec' only after checking.
- Tests count only when run with composer test (PHP 8.3).

## Secrets
- Secrets live only in .env on the server. Never read, print, grep, summarise or copy any
  .env* file except .env.example. No secret in code, tests, fixtures, logs, commit messages
  or reports.
- .env.example lists every key with empty secret values.
- AODB credentials never exist on this Mac. Local work uses recorded fixtures; the live API
  is called only on the server.

## Git
- Never commit anything under docs/. Never git add -f. Never --no-verify.
- Hooks: .githooks/ (git config core.hooksPath .githooks).
- Push only when the batch says so.

## Production target
- Laravel 12, PHP 8.3 (/usr/local/bin/ea-php83): no PHP 8.4+ syntax. MariaDB 10.11.19.
  AlmaLinux / cPanel / CloudLinux, dedicated server at AOS.
- No Node and no SSH on the server. Deploy: maintenance on → git pull → composer install
  --no-dev → migrate as DDL user → grants (Dany, as root, when tables changed) → caches
  (route cache allowed: served at the subdomain root) → preflight → up.
  No Node anywhere: CSS is hand-written and committed (D-018).
- cPanel cron runs the scheduler every minute. No daemons, no long-running queue workers.
  Scheduled commands use withoutOverlapping and finish well inside their interval.
- App commands run as the cPanel account user, never root.

## Database
- Runtime user = default connection `mariadb`: data access only; NO UPDATE/DELETE on history
  tables (append-only, enforced by per-table grants AND triggers).
- DDL user = connection `mariadb_ddl`: migrations only
  (php artisan migrate --database=mariadb_ddl).
- Per-table GRANTs are run by Dany as root (WHM → phpMyAdmin). Any command that lists tables
  for grants reads them as the DDL user — the runtime user cannot see new tables.
- All times stored in UTC (connection time zone +00:00, app.timezone UTC); displayed in
  Africa/Kigali (UTC+2).
- Tests run on MariaDB (flighthub_test), never SQLite; migrations as DDL, queries as runtime.
- Local DB: the hub's own instance (scripts/local-db/, data in ~/mariadb-flighthub).
  Never touch ~/mariadb-kia data or KIA PULSE's databases.
- History tables (append-only): polls, raw_responses (DELETE only after 90 days, by
  trigger), flight_versions, change_events. Never write code or a migration that updates
  or deletes their rows; if ever needed, it is Dany's decision first.
- Every table has an entry in config/grants.php (deny-by-default). After a migration that
  adds a table: migrate flighthub and flighthub_test as DDL, then
  scripts/local-db/apply-grants.sh. In production Dany pastes hub:grants-sql output as
  root; hub:check-grants verifies.
- SHOW GRANTS output contains password hashes: never print it raw.
- Locally, as in production, each runtime user holds exactly one database.

## Data rules
- Flight identity = the AODB `id`. Never auto-merge. Suspected duplicates become quality
  flags; a person resolves "same as".
- Corrections only from reviewed mapping tables; raw and corrected values kept side by side;
  everything else is only flagged. Never move or invent times.
- Every field change is an append-only change event (flight, field, old value, new value,
  observed at, source). Change events are kept forever.
- Raw responses: stored only when content changes, compressed, kept 90 days.
- "Flights of a day" = Kigali calendar day; the API also accepts any from/to window
  (KIA PULSE's day is 07:00→07:00 Kigali, in one call).
- Data-quality thresholds (minutes): no actual after schedule 30; actual vs schedule gap 60;
  still IN_FLIGHT after estimate 30. Quality flags only — delay classification belongs to
  consumers or a computed field.
- Absent field = null; its disappearance is a change event. A flight that leaves the feed
  gets 'removed from feed' and is never deleted (D-020).
- Window membership: reference_time = scheduled, else estimated, else actual
  (ARR: sibt, eibt, eldt, aibt, aldt; DEP: sobt, eobt, tobt, etot, aobt, atot). Windows are [from, to)
  in UTC, at most 7 days (D-021).
- as_of = what the hub knew at that moment (flight_versions.valid_from), not what was true
  then (D-022).
- Change events: field = the AODB path (eobt, paxDetails.pmr…); arrays compare as one
  value; absent = null. source aodb for AODB values, correction for corrected served
  values.
- Removal: a flight missing from a poll is removed only if one of its OBT/TOT or
  LDT/IBT times lies at least 60 min inside the window AND /flights/v1/byid does not
  return it.

## AODB API
- Innov'ATM "Airport Keeper External API" v3.2.5; spec committed in
  resources/aodb/openapi-3.2.5.json. Base URL https://api.app.airport-keeper.com
  (https only); airport HRYR.
- POST /login {username, password} → the token is in the Authorization RESPONSE header
  (with or without "Bearer "). Every other call sends Authorization: Bearer <token>.
- GET /flights/v1/airport?airport=HRYR&from=…&to=… (yyyy-MM-ddTHH:mm:ssZ, both flows): a DEP
  is in the window if any of its OBT/TOT times is inside; an ARR if any of its LDT/IBT
  times is inside. GET /flights/v1/byid?airport=HRYR&ids=a,b,… for flights missing from a
  poll.
- Never call the live AODB API from this Mac: no credentials exist here (D-003). Every test
  uses Http::fake() with Http::preventStrayRequests().
- Never log, print, store unencrypted, or put in an exception message: the AODB password,
  the token, an Authorization header, or any /login request or response body.

## Security floor
- Every permission enforced server-side, deny-by-default, with tests for the refused cases.
- TOTP for every admin account; every admin action logged.
- Distribution API: one key per consumer, field-level scopes, every call logged.
- composer audit clean whenever dependencies change.
- Preflight warns when the TLS certificate expires within 14 days.

## UI (later batches)
Internal only. Blade + Livewire, no JavaScript build step. KIA PULSE look: dark theme,
IBM Plex Sans / Mono.
