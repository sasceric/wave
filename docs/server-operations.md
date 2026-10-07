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

The production workflow pauses the installed systemd dispatcher and consumers
before pulling code or replacing cache. An explicit `systemctl stop` waits for
shutdown and prevents `Restart=always` from relaunching a consumer during the
deployment. Previously active consumers and the timer resume only after migrations,
cache warmup, task registration and permission checks. A failed deployment stops
before resuming them; repair it and verify/restart the listed services manually.
Previously inactive units stay inactive. The workflow does not manage Supervisor
or cron; those installations must pause their manager/dispatcher explicitly.

Composer installation uses `--no-scripts`; cache clearing and asset installation
run explicitly once. All Symfony console commands run as `steelcodeweb` with
`APP_ENV=prod APP_DEBUG=0`. Existing `var/` ownership is repaired before those
commands, and the dumped environment is readable only by its owner/group. An
active `php8.4-fpm.service` is reloaded after warmup to refresh its environment and
opcode cache. No other FPM service name is assumed.

For emergency configuration changes, pause these same units, rebuild
`.env.local.php`, repair ownership, clear/warm cache as the web account, then
reload FPM and restart consumers. Do not run cache-building commands as root.

The current in-place deployment is not an atomic release switch. For schema
changes incompatible with a running consumer, stop the dispatcher and consumers
before pulling/deploying, then start them after migrations. Scheduler/job rows
retain work through restarts. Never truncate the Messenger table to unblock it.

### Missing container files or cache permission errors

A `Failed opening required .../Container.../getConsole_ErrorListenerService.php`
error means a console process references a container file that no longer exists.
The preceding warning and fatal error describe the same failure. A concurrent
cache replacement while a consumer is running is a likely cause; inspect the
deployment and worker journal around the timestamp to establish which process
failed. This log does not establish an application validation or database error.

`Permission denied` while writing `var/cache/prod/pools/system` establishes a
filesystem permissions problem. Symfony CLI and PHP-FPM must both be able to
write cache; for this host they should run as `steelcodeweb`. Fixing ownership
only at the end of deployment leaves a window where root-owned cache fails.

For recovery during a maintenance window, run as root on the documented systemd
installation (confirm the PHP-FPM service and pool user first):

```sh
cd /home/steelcodeweb/web/wave.ba/public_html
systemctl stop wave-scheduler.timer wave-scheduler.service
systemctl stop wave-worker@realtime wave-worker@mail wave-worker@push wave-worker@background
chown -R steelcodeweb:steelcodeweb var
chmod -R u+rwX var
# Preserve the broken compiled cache rather than deleting it; no DB/media changes.
if [ -d var/cache/prod ]; then
  mv var/cache/prod "var/cache/prod.recovery-$(date -u +%Y%m%dT%H%M%SZ)"
fi
runuser -u steelcodeweb -- env APP_ENV=prod APP_DEBUG=0 php8.4 bin/console cache:warmup --no-debug --no-interaction
systemctl reload php8.4-fpm
systemctl start wave-worker@realtime wave-worker@mail wave-worker@push wave-worker@background
systemctl start wave-scheduler.timer
systemctl is-active wave-worker@realtime wave-worker@mail wave-worker@push wave-worker@background wave-scheduler.timer
```

Do not run a second deployment or cache clear during recovery. If warmup fails,
resolve its original error before starting workers. Retain the old cache until
recovery is confirmed, then remove that recovery copy during normal maintenance.
Inspect new logs and load an authenticated page; verify jobs drain and realtime
delivery works. In-place deployments can still overlap live HTTP requests; an
atomic release switch or drained maintenance window is needed to eliminate that
remaining window. The workflow changes have not been applied to the live server
by this investigation.

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

`APP_ENV` selects the environment configuration automatically. Shared settings
live in `config/packages/`; `config/packages/dev/wave_workers.yaml` enables the
admin worker, `config/packages/prod/wave_workers.yaml` disables it, and tests
use their own overrides in `config/packages/test/`. See
[environment-specific configuration](environment-configuration.md) for the
layout, local synchronous fallback and production setup.
Open an admin page while signed in as `ROLE_ADMIN`: the browser calls CSRF-protected
bounded consume requests, shares a Web Lock across tabs where supported, and the
server also takes a PostgreSQL advisory lock across browsers. Hidden tabs and
logout stop the loop. The PHP session is released before consuming. Empty queues
wait two seconds between calls, errors back off, and only allowlisted transports
are consumed; `failed` is excluded. No inbox polling was introduced.

Closing the admin stops background progress unless CLI consumers also run.
For dev CLI processing, override `wave.admin_worker.enabled: false` in
`config/packages/dev/wave_workers.yaml`
and run the four consumers plus `app:scheduled-tasks dispatch` as needed.
Tests default to direct delivery but queue integration tests exercise actual
Doctrine transports in a disposable PostgreSQL database.
