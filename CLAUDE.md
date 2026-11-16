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
- Real AODB credentials exist only in the server's .env and, for Dany's own local testing,
  in his local .env (D-050: mode 600, git-ignored, blocked by the pre-commit hook, never in
  an iCloud-synced folder). .claude/settings.json denies Claude Code reading or editing
  .env, .env.testing, .env.local and .env.production. Claude Code works with recorded
  fixtures only.

## Git
- Never commit anything under docs/. Never git add -f. Never --no-verify.
- Hooks: .githooks/ (git config core.hooksPath .githooks).
- Commit messages and pull-request descriptions contain only Dany's text: a subject line
  and an optional body. No trailer lines of the form "Something-by: …", no tool or AI
  attribution of any kind. The author is Dany. (.claude/settings.json turns attribution
  off; .githooks/commit-msg refuses it.)
- Push only when the batch says so.

## Production target
- Laravel 12, PHP 8.3 (/usr/local/bin/ea-php83): no PHP 8.4+ syntax. MariaDB 10.11.19.
  AlmaLinux / cPanel / CloudLinux, dedicated server at AOS.
- No Node and no SSH on the server. Deploy: maintenance on → git pull → composer install
  --no-dev → migrate as DDL user → grants (Dany, as root, when tables changed) →
  php artisan hub:reapply-corrections (whenever reference data changed) → caches (route
  cache allowed: served at the subdomain root) → preflight → up. First deploy only: create
  the first admin with php artisan hub:admin-create {email} {name} --role=admin (temporary
  password printed once).
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
- Hub-owned fields use the "hub." prefix in change events (hub.sameAs, hub.rotationNext,
  hub.rotationPrev); their values are aodb_ids, never internal ids.
- Rotations: arrival → next departure, departure → previous arrival, for flights in the
  poll window, in the poll transaction. linkedId to a known flight wins (source aodb);
  otherwise by registration: the next departure with the same reg after the arrival's
  in-block time (aibt, else eibt, else sibt), within 24 h, with no other arrival of that
  reg in between (source system). A link that no longer holds is removed (event with null).
- Type fill (D-033): a missing served ICAO type, type name or wake category comes from the
  active aircraft_types row (the airline's own row, else '*'); twins keep the AODB value;
  correction events source correction, source_ref "aircraft_types:<id>". Capacity is never
  filled.
- Reference rows (airport_code_corrections, aircraft_types, stands) are entered in the admin
  screens (Reference; reviewer = the signed-in user, reviewed_on = today), no longer by
  migration (D-028's interim path ends). Every change marks "re-apply pending"; the
  scheduled hub:reapply-corrections --if-pending (every 5 minutes) applies it, never inside
  a web request.
- GHR contact stripping (D-035): ghrOperatorDetails email, phone, address, zipcode and city
  are removed before a record is stored, compared or put in an event; name and id stay;
  raw responses keep them for 90 days.
- Quality flags have a state (open / cleared, set by the hub) and a resolution (fixed,
  ignored, reported, same_as — set by a person). Flags are never deleted; their history is
  quality_flag_events (append-only).

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
- Claude Code never runs a command that reaches the live AODB API (hub:poll or hub:backfill
  with AODB_CLIENT=http, curl to the AODB). Dany runs live polls himself (D-050). Every test
  uses Http::fake() with Http::preventStrayRequests().
- Never log, print, store unencrypted, or put in an exception message: the AODB password,
  the token, an Authorization header, or any /login request or response body.

## Distribution API
- /api/v1 (routes/api.php, registered in bootstrap/app.php; no Sanctum, no Passport): the
  hub's own keys, "fdh_" + 8-char prefix + "_" + random; only the SHA-256 is stored; the key
  is shown once (hub:key-issue).
- The key is accepted in the Authorization header only (Bearer); a key in the query string
  is ignored. Every refusal (missing, malformed, unknown, wrong, revoked, expired, inactive
  consumer) is the same 401 {"error": "UNAUTHORIZED"}. Errors are JSON
  {"error", "message"}, never a stack trace.
- Field groups (config/api_fields.php, D-039) are deny-by-default: a field in no group is
  never served, new AODB fields included; identity is always given. The same filter applies
  to flights, history, first_seen values and /changes.
- /changes: meta.nextCursor = the last event id SCANNED (not the last returned), so events
  a consumer may not see never block it.
- CORS is off (config/cors.php, paths []): no response carries Access-Control-Allow-Origin;
  consumers call server to server, /api/docs from the same origin (tests/Feature/Api/CorsTest).
- No internal ids in responses: flights are named by their AODB id, hub links carry AODB
  ids, reference rows their natural keys.
- Every call is logged in api_calls (append-only, 1 year, D-036), never the key or the
  Authorization header.
- Documentation (D-051): resources/api/flighthub-v1.openapi.yaml (OpenAPI 3.1, hand-written)
  is served at /api/openapi.yaml and shown by Swagger UI (public/vendor/swagger-ui, served by
  the hub) at /api/docs: public, read-only, no session (routes/docs.php, outside the web
  group). Every API change updates the document; tests/Feature/ApiDocs check it against the
  routes, the field groups and real responses.

## Admin screens
- Fortify (sign-in only; its routes are registered in routes/web.php) + Livewire, Blade, one
  layout, public/css/hub.css, fonts in public/fonts. No outside URL at run time (no CDN, no
  Google Fonts, no password-breach API).
- Roles (D-040) as Gate abilities, deny-by-default (App\Admin\Abilities): view-hub,
  manage-quality, manage-reference (admin, steward); manage-consumers, manage-users,
  view-audit (admin). Every web route carries a can:… middleware except sign-in, two-factor
  and sign-out (RouteAbilitiesTest).
- TOTP is mandatory: no page before enrolment; recovery codes shown once, single-use. A user
  flagged must_change_password changes it before any page. Passwords: ≥ 12 characters, not
  the email. 5 wrong passwords or TOTP codes in a row lock the account 15 minutes; the
  message never says whether the email exists. Sessions: database, 30 minutes idle,
  SameSite=strict, HttpOnly, Secure in production (D-043). Users are deactivated, never
  deleted; deactivation ends their sessions.
- Audited (admin_audit, append-only, kept forever): sign-in success / failure / lock, TOTP
  enrolled / failed / recovery code used, password changed, sign-out, user created / reset /
  deactivated, stale and recovery alerts, quality resolutions.
- Never in the audit log or any log: passwords, TOTP secrets or codes, recovery codes, API
  keys, session ids.
- CSP: default-src 'none'; scripts and styles only from the hub or with the per-request
  nonce; no 'unsafe-eval', no 'unsafe-inline' (Livewire's CSP-safe build). No inline style
  attributes or event handlers in templates; the own scripts are public/js/hub.js (copy,
  .txt download, dialog focus / Escape) and public/js/api-docs.js (starts Swagger UI on /api/docs).
- Screen map (each route carries its ability; every Livewire screen re-checks it on every
  request, App\Livewire\AdminPage::boot):
  Feed health /, Flights /flights and /flights/{aodbId}, Quality /quality and
  /quality/{flag} — view-hub (resolving a flag: manage-quality);
  Reference /reference/airport-codes, /reference/aircraft-types, /reference/stands —
  manage-reference; Consumers /consumers, /consumers/{code}, /consumers/{code}/usage —
  manage-consumers; Users /users — manage-users; Audit /audit — view-audit.
- Sign-in throttle per IP: 20 failed sign-in steps (password or TOTP) in a minute → every
  sign-in attempt from that IP gets 429 for the rest of the minute (audited once per block).
- Shown once, never stored in the session, the Livewire snapshot, the audit or logs: API
  keys (only the hash is stored) and temporary passwords.

## Security floor
- Every permission enforced server-side, deny-by-default, with tests for the refused cases.
- TOTP for every admin account; every admin action logged.
- Distribution API: one key per consumer, field-level scopes, every call logged.
- composer audit clean whenever dependencies change.
- Preflight warns when the TLS certificate expires within 14 days.

## UI
Internal only. Blade + Livewire, no JavaScript build step. The hub's own visual identity from
the design mockups of 2026-11-16 (D-052; docs/screens/design/, docs/DESIGN-ui.md): light theme.
- Look only in UI batches: views, layouts, CSS, icons, display-only view data. Read-only displays
  and filters over stored data on existing screens are allowed; no new pages, writes, fields,
  permissions or features.
- One hand-written stylesheet, public/css/hub.css, design tokens as custom properties at its top
  (colours changed for contrast are marked "AA:"). No Node, framework, CDN or outside URL; own
  scripts only public/js/hub.js and public/js/api-docs.js.
- CSP unchanged: no inline styles or style attributes, no inline scripts or handlers. Charts are
  server-rendered SVG (sizes as attributes) or <meter>/<progress>. tests/Feature/Admin/ViewsCspTest
  checks every view.
- Text uses the system UI font; codes, times and ids IBM Plex Mono (public/fonts, OFL).
- No logos: airline codes as badges; the product mark is the text "RAC Flight Data Hub".
- WCAG AA for every text/background pair; status never by colour alone (icon + word); visible
  2 px focus ring; prefers-reduced-motion respected.
- Desktop first (1366–1920 px, usable at 1280 px). Real data only, never mockup text or numbers.
