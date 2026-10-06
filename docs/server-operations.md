# Server services, scheduled tasks and queues

Implemented on 2026-10-06. Examples use the Hestia account `steelcodeweb` and
`/home/steelcodeweb/web/wave.ba/public_html`. These are installation instructions;
Codex has not installed services or deployed changes on production.

## Services required

- Existing HTTPS web server and PHP 8.4 FPM, serving `public/`.
- PostgreSQL with `pdo_pgsql`; the existing database also stores durable jobs,
  queues and task state. Redis, RabbitMQ and Elasticsearch are not required.
- Existing Mercure Hub and reverse proxy. Keep its issuer/JWT/CORS configuration;
  see [Mercure setup](mercure-server-setup.md).
- SMTP/provider configuration and the existing Web Push VAPID pair.
- Four supervised Messenger consumers: `realtime`, `mail`, `push`, `background`.
- One scheduled-task dispatcher every 30 seconds. It queues work; consumers run it.
- Persistent writable `var/media`, `var/sitemaps`, logs/cache, backups and monitoring.

The `failed` transport has no automatic consumer. Inspect and retry individual
failures from **Admin → Tools → Queues → Failed jobs** after resolving their cause.
Never start `messenger:consume failed` as a permanent service.

## First deployment: step by step

### 1. Prepare configuration and service files before deploying

Verify `command -v php8.4`; templates assume `/usr/bin/php8.4`.
PHP CLI and FPM need GD with JPEG/PNG/WebP, EXIF, OpenSSL and `pdo_pgsql`.
CLI also needs `pcntl` for graceful signals. Use the same `.env.local`/compiled
environment and database as FPM. Do not copy secrets into unit files.

Keep the existing database, mail, Mercure and VAPID values. Set:

```dotenv
APP_ENV=prod
APP_DEBUG=0
WAVE_QUEUE_ENABLED=true
WAVE_NOTIFICATIONS_ENABLED=true
LOG_RETENTION_DAYS=14
```

Keep **APP_SECRET stable**. Pending job content is encrypted with a key derived
from this secret. Back it up with the database; changing it makes pending payloads
unreadable. Queue JSON contains only a job ID, and completed/discarded payloads
are cleared. `WAVE_QUEUE_ENABLED=false` is a temporary synchronous fallback,
not a way to process an existing backlog; workers must drain that backlog.

The development admin worker is disabled in prod by YAML **and** an environment
check. There is no admin-worker environment variable to enable on the server.
No additional Mercure Caddy setting is required by these jobs.

### 2. Deploy the application and migrate

Pushes to `main` run the existing deploy workflow. For the first cutover, schedule
a short maintenance window: the new release queues deliveries as soon as it is
active, and they wait safely until consumers start. To stage without asynchronous
delivery, set `WAVE_QUEUE_ENABLED=false`, deploy, install consumers, then enable
it and regenerate the environment/cache. Sitemap generation requires queues;
with the temporary fallback it uses the original synchronous generator.

The workflow installs dependencies, builds the SPA, applies migrations, clears
cache and registers missing tasks without resetting existing task state.
For manual deployment, run the same steps and:

```sh
cd /home/steelcodeweb/web/wave.ba/public_html
APP_ENV=prod php8.4 "$(command -v composer)" dump-env prod
APP_ENV=prod php8.4 bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod php8.4 bin/console cache:clear
APP_ENV=prod php8.4 bin/console app:scheduled-tasks register --no-interaction
sudo chown -R steelcodeweb:steelcodeweb var
sudo chmod -R u+rwX var
```

These migrations create `messenger_messages`, `background_job`,
`wave_scheduled_task`, `worker_heartbeat`, `directory_index` and expiry indexes.
Transport auto-setup is off: do not skip migrations. Back up PostgreSQL and
`var/media` before schema changes. Keep generated sitemap parts persistent too.

### 3. Install systemd consumers and dispatcher

Use systemd **or** the Supervisor alternative below, not both.
Copy the checked-in templates and verify user, paths and PHP executable:

```sh
sudo cp deploy/systemd/wave-worker@.service /etc/systemd/system/
sudo cp deploy/systemd/wave-scheduler.service /etc/systemd/system/
sudo cp deploy/systemd/wave-scheduler.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now wave-worker@realtime wave-worker@mail wave-worker@push wave-worker@background
sudo systemctl enable --now wave-scheduler.timer
sudo systemctl status wave-worker@realtime wave-worker@mail wave-worker@push wave-worker@background --no-pager
sudo systemctl list-timers wave-scheduler.timer
```

Workers restart after an hour or their memory limit and recover abandoned
Doctrine deliveries after one hour. Initial limits are 192MB per consumer;
raise the background worker to 256MB if image sizes warrant it. Four processes
are a starting arrangement, not a promise of capacity: measure CPU/RAM, oldest
pending age and PostgreSQL connections before increasing concurrency. Keep
realtime separate from images, bulk work and mail. A handler finishes its current
job before a time/memory limit takes effect; provider calls have bounded timeouts.

Remove the old five-minute `app:send-unread-message-reminders` cron/timer and
daily `cache:pool:prune` timer when the new dispatcher is active. Running both
creates unnecessary duplicate schedules. Their console commands remain available
for diagnostics; the new registry is the production scheduler.

### 4. Build initial indexes, media variants and sitemap

Sign in as an admin, open **Tools → Scheduled tasks**, and start
`IndexReconcileTask`, `MediaMaintenanceTask` and `SitemapGenerateTask` manually.
They enqueue bounded continuations; the button does not execute work in the API.
Normal profile/campaign changes subsequently enqueue their indexing updates.

Check:

```sh
APP_ENV=prod php8.4 bin/console messenger:stats --env=prod
sudo journalctl -u wave-worker@realtime -u wave-worker@mail -u wave-worker@push -u wave-worker@background -n 100 --no-pager
sudo journalctl -u wave-scheduler -n 50 --no-pager
curl -I https://wave.ba/sitemap.xml
```

Before its first completed generation, `/sitemap.xml` returns 503 with Retry-After.
Afterward it serves the last complete index and immutable, split XML parts.
Check the actual XML as well as its HTTP status. No image GET resizes pixels:
when a variant is missing it queues a repair and temporarily serves the master.

### 5. Check application behavior and failure handling

Use two test accounts to verify a new message reaches the open chat/sidebar via
Mercure, a background push opens the intended chat, and email arrives. API success
means a delivery has been accepted into PostgreSQL, not accepted by the provider.
Verify pending counts decrease, task last outcomes update, failed jobs remain
visible, and **Logs** show structured operational records. The new INFO log is
`prod.background-YYYY-MM-DD.log`; main warnings/errors remain in the main log.

Message-type rows and transport rows describe overlapping messages. Do not add
them. Counts include delayed retries and claimed messages; `inFlight` means
claimed, not proof that a process is alive. Heartbeats are current within 90s.
A long-running job may delay its heartbeat; use systemd/journal for confirmation.

Retry policy is five retries with exponential delay and jitter, starting at 10s.
Expired/revoked security emails and obsolete unread reminders are skipped.
Push subscriptions rejected as expired are removed. Permanent failures go directly
to `failed`; transient failures retry and then go to `failed`. A provider may have
accepted a delivery before a process crashes: external delivery is at-least-once,
so occasional duplicate mail/push remains possible. Completed jobs are idempotent
on redelivery. Stable Mercure event IDs and chat message IDs permit client dedupe.

Discard intentionally removes that failed delivery's protected content; retry
queues the same protected job, it does not run a handler inside the admin request.
Pending/failed jobs are never purged by queue maintenance. Completed/discarded
history expires after 30 days; expired tokens and old rotated logs are removed in
bounded jobs. Keep secrets, message content and provider endpoints out of logs.
Do not log every message body or every heartbeat when thousands of users connect.

## Later deployments

The workflow runs `messenger:stop-workers` after migration/cache preparation.
Consumers finish their current job, exit and are restarted by systemd/Supervisor.
The worker stop signal uses `cache.app`; do not switch to a per-release cache path
without arranging an equivalent restart. For emergency configuration changes,
rebuild `.env.local.php`, clear cache, then restart the four units explicitly.

The current in-place deployment is not an atomic release switch. For schema
changes incompatible with a running consumer, stop the dispatcher and consumers
before pulling/deploying, then start them after migrations. Scheduler/job rows
retain work through restarts. Never truncate the Messenger table to unblock it.

## Supervisor alternative

Install [the checked-in Supervisor configuration](../deploy/supervisor/wave-workers.conf)
under `/etc/supervisor/conf.d/`, then run `supervisorctl reread`,
`supervisorctl update` and `supervisorctl status`. Retain the systemd dispatcher
timer or replace it with this web-account cron entry:

```cron
* * * * * cd /home/steelcodeweb/web/wave.ba/public_html && APP_ENV=prod /usr/bin/php8.4 bin/console app:scheduled-tasks dispatch --env=prod --no-debug --no-interaction
```

Cron dispatches every minute rather than 30 seconds. PostgreSQL row locks prevent
duplicate claims if dispatcher invocations overlap. Configure rotated Supervisor
stdout logs and do not run the old reminder/cache schedules alongside this entry.

## Development

`config/packages/wave_workers.yaml` enables the admin worker only in `dev`.
Open an admin page while signed in as `ROLE_ADMIN`: the browser calls CSRF-protected
bounded consume requests, shares a Web Lock across tabs where supported, and the
server also takes a PostgreSQL advisory lock across browsers. Hidden tabs and
logout stop the loop. The PHP session is released before consuming. Empty queues
wait two seconds between calls, errors back off, and only allowlisted transports
are consumed; `failed` is excluded. No inbox polling was introduced.

Closing the admin stops background progress unless CLI consumers also run.
For dev CLI processing, override `wave.admin_worker.enabled: false` in dev YAML
and run the four consumers plus `app:scheduled-tasks dispatch` as needed.
Tests default to direct delivery but queue integration tests exercise actual
Doctrine transports in a disposable PostgreSQL database.
