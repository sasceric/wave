# Environment-specific configuration

Symfony selects configuration from `APP_ENV`. Wave uses its standard configuration
loader; no custom kernel code or additional package is needed.

| Directory | Loaded for | Purpose |
| --- | --- | --- |
| `config/packages/` | Every environment | Shared framework, database, Messenger routes and worker limits |
| `config/packages/dev/` | `APP_ENV=dev` | Development admin worker and debug logging |
| `config/packages/prod/` | `APP_ENV=prod` | CLI workers, production logging, database caches, secure session cookies and routing |
| `config/packages/test/` | `APP_ENV=test` | Direct delivery, null mail transport, separate test database, mock sessions and faster password hashing |

Shared package configuration loads first, followed by the selected environment's
package overrides. `APP_DEBUG` controls debugging; it does not select an environment.
Do not put credentials or machine-specific database URLs into these YAML files.
Keep them in ignored environment files or the deployment's environment variables.

## Local development with the admin worker

Keep these values in `.env.local` (the checked-in `.env` has the same development
and queue defaults):

```dotenv
APP_ENV=dev
APP_DEBUG=1
WAVE_QUEUE_ENABLED=true
```

`config/packages/dev/wave_workers.yaml` enables the development admin worker.
Start the normal Symfony/Vite servers and the Mercure hub when testing realtime.
Sign in as an administrator and leave an admin page open in a visible browser tab.
That tab processes queued jobs and dispatches due scheduled tasks through bounded,
CSRF-protected requests. You do not need systemd, Supervisor, cron or separate
Messenger consumer processes for this development mode.

The worker stops when the tab is hidden/closed, when you leave the admin area or
when you sign out. Jobs wait safely until it resumes. This mode cannot process
jobs continuously with no visible admin session. Queue schema migrations must
still be applied, and PostgreSQL is still required for the queue/locking services.
See [server operations](server-operations.md#development) for locking and limits.

### Local work without an admin tab

For ordinary feature development where queued delivery is unnecessary, use:

```dotenv
APP_ENV=dev
APP_DEBUG=1
WAVE_QUEUE_ENABLED=false
```

Existing synchronous fallbacks handle email, push, realtime publication and image
processing within the request. Directory listings use their source data fallback;
sitemap requests use the synchronous generator. This flag does not drain existing
queued messages or run the scheduled-task engine. To test asynchronous jobs,
retries, scheduling and queue tools, restore `WAVE_QUEUE_ENABLED=true` and use
the visible admin worker or CLI consumers.

For dev CLI consumers, set `wave.admin_worker.enabled: false` in
`config/packages/dev/wave_workers.yaml`, then use the commands in server operations.
Do not commit that temporary override if it is only your local preference.

## Production

Keep the server configured with:

```dotenv
APP_ENV=prod
APP_DEBUG=0
WAVE_QUEUE_ENABLED=true
WAVE_NOTIFICATIONS_ENABLED=true
```

`config/packages/prod/wave_workers.yaml` explicitly disables the browser admin
worker. The service also checks that its environment is `dev`, so a parameter
change cannot enable it in production. The server's systemd/Supervisor consumers
process the `realtime`, `mail`, `push` and `background` queues, and the timer/cron
entry dispatches scheduled tasks. Keep `APP_SECRET` stable: pending job payloads
are encrypted using it.

Follow [server operations](server-operations.md) for the services and deployment
commands. If you already installed those services, this directory reorganization
requires only the normal deployment/cache rebuild; effective production settings
are unchanged. Do not change the server to `APP_ENV=dev` to enable browser workers.

After changing environment values, restart local PHP processes as needed. On
production, regenerate the dumped environment if used, clear the production cache
and restart workers as described in the deployment guide. A generated
`.env.local.php` can otherwise keep the old values active.

## Adding configuration later

Put a default shared by all environments in `config/packages/<name>.yaml`.
Put its environment-specific override in
`config/packages/dev/<name>.yaml`, `config/packages/prod/<name>.yaml` or
`config/packages/test/<name>.yaml`. Symfony package files configure installed
extensions (for example `framework`, `monolog` and `doctrine`) and container
parameters. Register application services in `config/services.yaml` or its
standard environment-specific service configuration.

This split preserves the previous effective settings: development logging stays
verbose, production retains its existing cache/cookie/logging settings, and test
configuration retains the PostgreSQL-compatible database suffix and null mailer.
