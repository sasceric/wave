# Run all Wave services locally (macOS)

Project: `/Users/suadasceric/Projects/wave`.

Open the app at **http://127.0.0.1:5173** while developing. Use `127.0.0.1`
throughout; mixing it with `localhost` breaks shared session/Mercure cookies.
Keep each foreground service in its own terminal tab. These commands are for
local development, not the production server.

## Services and ports

| Service | Address | Purpose |
| --- | --- | --- |
| PostgreSQL 18 | `127.0.0.1:5432` | Application data, queues and scheduled-task state |
| PHP 8.4 backend | `http://127.0.0.1:8000` | Symfony API and built frontend |
| npm / Vite | `http://127.0.0.1:5173` | Frontend with hot reload; proxies `/api` to port 8000 |
| Mercure | `http://127.0.0.1:3000` | Live messages and notifications |
| Meilisearch | `http://127.0.0.1:7700` | Indexed search, when enabled |
| Messenger consumer(s) | No HTTP port | Process realtime, mail, push and background queues |
| Scheduled-task dispatcher | No HTTP port | Enqueue due tasks, including daily search reconciliation |
| Mailpit (optional) | SMTP `1025`, inbox `8025` | Capture development emails locally |

Queues use PostgreSQL. **Redis and RabbitMQ are not required.** Image compression
runs in PHP/GD; thumbnail work uses the background queue when queues are enabled.
There is no separate image-compression service to start. Local PHP's built-in
server handles the backend; PHP-FPM, Nginx and systemd are unnecessary here.

## 1. One-time setup

PHP 8.4 and PostgreSQL 18 are already installed on this Mac. For a fresh Mac with
Homebrew available:

```sh
brew install php@8.4 postgresql@18
```

Select PHP 8.4 and Node 24 in your shell. This Mac already uses nvm. Node 24 also
matches frontend test CI; the project's complete Node requirements are in
`frontend/package.json`.

```sh
cd /Users/suadasceric/Projects/wave
export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"
nvm install 24
nvm use 24
php -v
node -v
composer install
npm --prefix frontend ci
```

Use `nvm use 24` in each new npm terminal; `nvm install 24` is only needed once.
If `nvm` is not loaded in a new terminal, load the existing installation first:

```sh
export NVM_DIR="$HOME/.nvm"
. "$NVM_DIR/nvm.sh"
```

## 2. Start PostgreSQL

Homebrew runs PostgreSQL in the background, so this terminal can be reused:

```sh
brew services start postgresql@18
/opt/homebrew/opt/postgresql@18/bin/pg_isready -h 127.0.0.1 -p 5432
```

Expected: `accepting connections`. If it is already running, reuse it.

Keep the existing `DATABASE_URL` in the ignored `.env.local`. This checkout uses
the PostgreSQL database `wave` on port 5432. A fresh installation needs its own
existing PostgreSQL login and password, for example:

```dotenv
DATABASE_URL="postgresql://YOUR_DB_USER:YOUR_URL_ENCODED_PASSWORD@127.0.0.1:5432/wave?serverVersion=18&charset=utf8"
```

Do not replace working credentials with those placeholders. URL-encode special
characters in a database password. Once the connection is configured:

```sh
cd /Users/suadasceric/Projects/wave
/opt/homebrew/opt/php@8.4/bin/php bin/console doctrine:database:create --if-not-exists --env=dev
/opt/homebrew/opt/php@8.4/bin/php bin/console doctrine:migrations:migrate --no-interaction --env=dev
/opt/homebrew/opt/php@8.4/bin/php bin/console app:scheduled-tasks register --env=dev
```

Run migrations after pulling changes that add them. Registering tasks adds
missing schedules without resetting existing ones. No demo seed or database
reset is needed to start the existing local app.

## 3. Choose local environment settings

Edit the existing `.env.local`; **merge individual settings**, keeping your
database credentials, `APP_SECRET`, Mercure secret, mail settings and other keys.
Never paste a replacement over the whole file.

For all services, including asynchronous queues and Meilisearch:

```dotenv
APP_ENV=dev
APP_DEBUG=1
APP_BASE_URL=http://127.0.0.1:8000
WAVE_QUEUE_ENABLED=true
WAVE_NOTIFICATIONS_ENABLED=true

MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_ISSUER=https://wave.local

MEILISEARCH_ENABLED=true
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_INDEX_PREFIX=wave_dev
```

Keep `MERCURE_JWT_SECRET` configured in `.env.local`. `MERCURE_ISSUER` is an issuer
identifier, not another website/server you need to run.

Meilisearch key settings are explained in step 4. At the time this guide was
written, this checkout had **both** `WAVE_QUEUE_ENABLED=false` and
`MEILISEARCH_ENABLED=false`. Start their services and configure indexing before
using the full setup above.

For simpler development, these are supported alternatives:

- `MEILISEARCH_ENABLED=false`: search uses PostgreSQL instead; Meilisearch is not
  needed by Wave. Leave a shared instance running if Zeka needs it.
- `WAVE_QUEUE_ENABLED=false`: ordinary email, push, realtime and media processing
  use synchronous fallbacks. This does **not** process an existing queue backlog
  or run scheduled tasks. Skip the consumer/dispatcher steps for this mode.

After editing environment/configuration, clear the dev cache and restart any
running backend and queue consumers:

```sh
cd /Users/suadasceric/Projects/wave
/opt/homebrew/opt/php@8.4/bin/php bin/console cache:clear --env=dev
```

## 4. Terminal A — Meilisearch

**If Meilisearch is already running for Zeka on port 7700, reuse that process.**
One instance supports both projects; Wave uses the separate `wave_dev_search`
index. Wave's keys must permit that index, not Zeka's indexes.

The existing binary is `/Users/suadasceric/meilisearch`, and the existing data
directory is `/Users/suadasceric/data.ms`. To start that instance:

```sh
cd /Users/suadasceric
./meilisearch --http-addr 127.0.0.1:7700 --db-path /Users/suadasceric/data.ms
```

Keep any existing `MEILI_MASTER_KEY` environment/configuration used by your normal
Zeka startup. The command above does not supply or replace that key. Do not start
a second process on the same data directory, change the data path accidentally,
or upgrade this binary/data directory just to start Wave.

In another terminal, check:

```sh
curl -fsS http://127.0.0.1:7700/health
```

Expected: `{"status":"available"}`.

### Meilisearch keys

For an existing authenticated instance, set the Wave-scoped keys in `.env.local`:

```dotenv
MEILISEARCH_ADMIN_KEY=YOUR_WAVE_INDEXING_KEY
MEILISEARCH_SEARCH_KEY=YOUR_WAVE_SEARCH_KEY
MEILISEARCH_MASTER_KEY=
```

To generate those scoped keys for the first time, temporarily set
`MEILISEARCH_MASTER_KEY` in `.env.local` to the **running instance's existing
master key**, keep `MEILISEARCH_INDEX_PREFIX=wave_dev`, then run:

```sh
cd /Users/suadasceric/Projects/wave
/opt/homebrew/opt/php@8.4/bin/php bin/console app:search:keys --output=var/wave-search-keys.local.env --env=dev
```

The output path must be a **new file**. Merge the two generated key values into
`.env.local` privately, clear `MEILISEARCH_MASTER_KEY` from the app configuration,
and remove that temporary key file after merging. Existing generated Wave keys
can simply be reused; do not generate new ones every day. Keys stay server-side,
never in `frontend/.env*` or `VITE_*` variables.

If your existing development instance is deliberately unauthenticated and bound
only to `127.0.0.1`, both client keys may be empty. Do not use this arrangement on
production or on a publicly accessible host.

## 5. Terminal B — Mercure

Install the project's native hub only if `var/mercure/mercure` is missing:

```sh
cd /Users/suadasceric/Projects/wave
./bin/install-mercure.sh
```

Daily startup:

```sh
cd /Users/suadasceric/Projects/wave
/opt/homebrew/opt/php@8.4/bin/php bin/mercure-local.php
```

The launcher reads `.env.local` and shares its configured secret/issuer with the
hub. Check it in another terminal:

```sh
curl -fsS http://127.0.0.1:3000/
```

Expected: `Wave local Mercure Hub`. The root is the health check; the private
`/.well-known/mercure` stream requires authentication. If `var/mercure.db` is
already open/locked, reuse the existing hub; do not delete the database file.

## 6. Terminal C — PHP 8.4 backend

```sh
cd /Users/suadasceric/Projects/wave
/opt/homebrew/opt/php@8.4/bin/php -S 127.0.0.1:8000 -t public public/router.php
```

Check it in another terminal:

```sh
curl -fsS http://127.0.0.1:8000/api/health
```

Keep port **8000**: Vite's proxy is configured to use it. The PHP command must
include `public/router.php` so direct SPA routes work.

## 7. Terminal D — npm / Vite frontend

```sh
cd /Users/suadasceric/Projects/wave
nvm use 24
npm --prefix frontend run dev -- --host 127.0.0.1 --port 5173 --strictPort
```

Open **http://127.0.0.1:5173**. Both this terminal and the PHP backend must remain
running. `--strictPort` avoids silently moving the frontend to a port absent from
Mercure's allowed origins.

For the built app instead of hot reload:

```sh
cd /Users/suadasceric/Projects/wave
nvm use 24
npm --prefix frontend run build
```

Then open **http://127.0.0.1:8000**, keeping the backend and other required
services running. Vite itself is not needed in this mode.

## 8. Process the queues — choose one mode

With `WAVE_QUEUE_ENABLED=true`, enqueued jobs need a consumer. Choose the browser
admin worker **or** CLI processing below.

### Option A — visible admin tab (fewest terminals)

Leave the existing development setting enabled in
`config/packages/dev/wave_workers.yaml`:

```yaml
parameters:
    wave.admin_worker.enabled: true
```

Sign in as an admin and leave an admin page visible. It processes all four queues
and dispatches due scheduled tasks. **No separate consumer or scheduler terminal
is needed.** Hiding/closing the tab, leaving admin or signing out pauses this
worker; jobs wait until it resumes.

### Option B — Terminal E: CLI consumer (no admin tab required)

For independent CLI processing, temporarily change the same development setting
to `false`, then clear the dev cache. This disables browser consumption; do not
commit the temporary setting if it is only your local preference.

```yaml
parameters:
    wave.admin_worker.enabled: false
```

```sh
cd /Users/suadasceric/Projects/wave
/opt/homebrew/opt/php@8.4/bin/php bin/console cache:clear --env=dev
/opt/homebrew/opt/php@8.4/bin/php bin/console messenger:consume realtime mail push background --memory-limit=256M --sleep=1 --env=dev -v
```

One consumer handles all four queues for convenient local development. A long
image/indexing job can delay other jobs in this combined process. To reproduce
the server's separation, run these **in four separate terminals instead**:

```sh
/opt/homebrew/opt/php@8.4/bin/php bin/console messenger:consume realtime --memory-limit=192M --env=dev -v
```

```sh
/opt/homebrew/opt/php@8.4/bin/php bin/console messenger:consume mail --memory-limit=192M --env=dev -v
```

```sh
/opt/homebrew/opt/php@8.4/bin/php bin/console messenger:consume push --memory-limit=192M --env=dev -v
```

```sh
/opt/homebrew/opt/php@8.4/bin/php bin/console messenger:consume background --memory-limit=256M --env=dev -v
```

Run each from `/Users/suadasceric/Projects/wave`. Restart a consumer if it exits
at its memory limit or after changing PHP code/environment. Do not consume the
`failed` queue automatically; inspect failures in Admin → Tools → Queues.

### Terminal F — scheduler, only with CLI processing

```sh
cd /Users/suadasceric/Projects/wave
/opt/homebrew/opt/php@8.4/bin/php bin/console app:scheduled-tasks register --env=dev
while true; do
    /opt/homebrew/opt/php@8.4/bin/php bin/console app:scheduled-tasks dispatch --env=dev --no-interaction
    sleep 30
done
```

Dispatching only queues tasks that are due; consumers do the work. This includes
`SearchReconcileTask` every 24 hours, plus the registered media, directory-index,
sitemap, reminder and cleanup tasks. No additional daily search cron is needed.
This terminal must remain running for automatic scheduling; run the dispatch
command once manually if you only want a single due-task check.

## 9. Initial and manual indexing

After Meilisearch starts, its keys are configured and
`MEILISEARCH_ENABLED=true`, build Wave's initial index from another terminal:

```sh
cd /Users/suadasceric/Projects/wave
/opt/homebrew/opt/php@8.4/bin/php bin/console app:search:index --sync --env=dev
```

This indexes in batches and waits for completion without needing a consumer.
Use it after first setup, changing indexed fields, or switching back from
database search. For subsequent indexing through the background queue:

```sh
/opt/homebrew/opt/php@8.4/bin/php bin/console app:search:index --env=dev
```

That command reports **queued**, not finished: the background consumer/admin
worker must process its batches. Normal profile/campaign changes enqueue updates
automatically while Meilisearch is enabled.

For existing images that need thumbnail generation (not required every startup):

```sh
/opt/homebrew/opt/php@8.4/bin/php bin/console app:media:generate-thumbnails --batch-size=100 --env=dev
```

## 10. Optional — local email inbox with Mailpit

If `.env.local` already uses a working SMTP provider, no extra local mail service
is needed. To capture emails locally instead, with Docker Desktop running, use
another terminal:

```sh
docker run --rm --name wave-mailpit -p 127.0.0.1:1025:1025 -p 127.0.0.1:8025:8025 axllent/mailpit
```

Set these values in `.env.local`, then restart the backend and consumers:

```dotenv
MAILER_DSN=smtp://127.0.0.1:1025
MAIL_FROM_ADDRESS=no-reply@wave.local
MAIL_FROM_NAME=Wave
```

Open **http://127.0.0.1:8025** to read captured mail. If queues are enabled, mail
waits until the `mail` consumer/admin worker runs. The existing Compose file can
also start Mailpit, but assigns dynamic host ports; the command above fixes them
to the values in this guide. Docker is optional for the rest of this native setup.

Web Push uses the configured VAPID public/private keys and the browser's push
service; there is no local push daemon. Keep the existing keys in `.env.local`.
Enable device notifications from a signed-in account to test them. OAuth also
uses provider credentials/callback configuration rather than another daemon.

## Daily startup checklist

1. Start/reuse PostgreSQL (step 2).
2. Start/reuse Meilisearch (A), if enabled.
3. Start/reuse Mercure (B).
4. Start PHP 8.4 (C).
5. Start Vite (D), then open `http://127.0.0.1:5173`.
6. With queues enabled, keep a visible admin tab **or** run consumer E and
   scheduler F. Start Mailpit too if using local email capture.

Dependencies, key generation, migrations and initial indexing are not daily
startup steps unless something changed.

## Health checks and common problems

Run these from the project directory in another terminal:

```sh
/opt/homebrew/opt/postgresql@18/bin/pg_isready -h 127.0.0.1 -p 5432
curl -fsS http://127.0.0.1:8000/api/health
curl -fsS http://127.0.0.1:3000/
curl -fsS http://127.0.0.1:7700/health
/opt/homebrew/opt/php@8.4/bin/php bin/console messenger:stats --env=dev
```

- **Port occupied:** identify the listener; reuse a known service rather than
  killing it. For example: `lsof -nP -iTCP:7700 -sTCP:LISTEN`.
- **API requests fail in Vite:** confirm the backend is on port 8000 and use
  `127.0.0.1` for both browser and services.
- **No realtime:** check Mercure is running, its secret/issuer match the backend,
  and the realtime queue is being consumed. Sign in again after changing hosts.
- **Emails/updates/indexing stuck:** with queues enabled, check the consumer/admin
  tab and failed jobs. Starting Meilisearch does not process Wave's message queue.
- **Search 503:** check Meilisearch health, scoped keys and index permissions;
  finish the initial index. Enabled search does not silently fall back to SQL.
- **Search changes missing after enabling:** run `app:search:index --sync` again;
  changes made while Meilisearch was disabled need a fresh index.
- **Environment edits ignored:** restart long-running PHP/worker processes and
  clear the dev cache. Check for a generated `.env.local.php` left by `dump-env`;
  local development normally reads the text dotenv files. Never use production
  configuration/database credentials for this local setup.
- **Old frontend:** use port 5173 for hot reload, or rebuild before opening port
  8000. Use a separate browser profile when comparing local and live behavior.

## Stop local services

Press **Ctrl+C** in the frontend, backend, Mercure, Meilisearch, consumer,
scheduler and optional Mailpit terminals you started. Stop consumers/scheduler
before stopping PostgreSQL. Do not stop a shared Meilisearch process while Zeka
still needs it.

PostgreSQL may stay running for other projects. To stop its Homebrew service:

```sh
brew services stop postgresql@18
```

Do not delete `data.ms`, `var/mercure.db`, `var/media` or database files as a way
to stop/restart services; those contain persistent data.
