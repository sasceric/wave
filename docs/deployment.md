# Production deployment

The `Deploy production` GitHub Actions workflow deploys `main` to
`/home/steelcodeweb/web/wave.ba/public_html/` after each push. It can also be
started manually from **Actions → Deploy production → Run workflow**.

For required running services, the reminder schedule, thumbnail backfill,
backups, supervised consumers and scheduled-task setup, see
[server services, jobs and queues](server-operations.md). The deploy workflow
does not provision those host services or schedules.

## GitHub Actions secrets

Add these repository secrets under **Settings → Secrets and variables →
Actions**:

| Secret | Value |
| --- | --- |
| `PRODUCTION_SSH_HOST` | Production server hostname or IP address |
| `PRODUCTION_SSH_USER` | SSH user that owns or can update the Wave checkout |
| `PRODUCTION_SSH_PRIVATE_KEY` | Private SSH key for that user |
| `PRODUCTION_SSH_PORT` | SSH port, usually `22` |
| `PRODUCTION_SSH_FINGERPRINT` | SSH host-key fingerprint (`SHA256:...`) |

Install the matching public key in the deployment user's
`~/.ssh/authorized_keys`. The user must be able to run `git pull`, Composer,
`php8.4 bin/console`, npm, and Doctrine migrations in the deployment directory
without an interactive password prompt. The checkout must have `origin`
pointing to this repository and a `main` branch.

## Server requirements

- PHP 8.4 available as `php8.4`, with the extensions required by
  `composer.json`, Composer, and Node.js 22.12+ with npm available directly on
  the SSH deployment user's `PATH`.
- PHP GD with JPEG, PNG and WebP support, the `IMG_WEBP_LOSSLESS` constant,
  and EXIF enabled for both the deployment CLI and the website's PHP-FPM
  version. Composer checks the GD/EXIF extensions; the GD build must also
  include WebP encoding support. New avatars, portfolio images, company logos
  and campaign covers are saved as lossless WebP with a maximum width of
  600px, proportional height and no cropping or upscaling. JPEG EXIF rotation
  is applied before resizing; transparency is preserved. Existing uploads
  retain their original files. No new environment variables or database
  migrations are needed for this processing change.
- A PHP-capable web server configured with document root
  `/home/steelcodeweb/web/wave.ba/public_html/public`. Do not expose the
  repository root as the web document root. Apache uses the checked-in
  `public/.htaccess` front-controller rewrite. Nginx must route missing files
  to `index.php` (for example, `try_files $uri /index.php$is_args$args;` in
  the existing request location); keep the existing PHP handler for
  `index.php`.
- A production environment file or server environment variables with
  `APP_ENV=prod`, `APP_DEBUG=0`, a unique `APP_SECRET`, `DATABASE_URL`, and
  the production mail, public-origin, Mercure, OAuth, and Web Push settings
  that are enabled for the site. Keep secrets out of GitHub workflow YAML and
  the repository. `LOG_RETENTION_DAYS` defaults to `14`; it controls how many
  daily application and deprecation log files Monolog retains in `var/log/`.
- Write access for the PHP process to `var/cache/`, `var/log/`, and
  `var/media/`.
- Symfony issues an HTTP-only session cookie with a 90-day lifetime and retains
  server-side sessions for the same period. Make sure PHP-FPM uses a writable,
  persistent session store and that any host-level session cleanup does not
  delete sessions sooner. Users with an existing session cookie should sign in
  once after deploying this change so the browser receives the persistent
  cookie. Users can still be signed out after the retention period, by logout,
  or if the account/session is revoked; do not treat this as permanent
  authentication.

Set `WAVE_NOTIFICATIONS_ENABLED=true` explicitly for production delivery. Configure matching Mercure issuer/JWT values and the existing production Web Push VAPID pair. The [server notification checklist](server-notifications.md) contains exact values, environment-refresh commands and verification steps; [Mercure server setup](mercure-server-setup.md) contains the full Hub/proxy configuration.

Before deploying image processing, check the CLI capabilities:

```bash
php8.4 -r 'var_export(["gd" => extension_loaded("gd"), "exif" => extension_loaded("exif"), "jpeg" => function_exists("gd_info") && (gd_info()["JPEG Support"] ?? false), "png" => function_exists("gd_info") && (gd_info()["PNG Support"] ?? false), "webp" => function_exists("gd_info") && (gd_info()["WebP Support"] ?? false), "lossless" => defined("IMG_WEBP_LOSSLESS")]);'
```

All entries must be `true`. Have the hosting provider enable missing extensions
for PHP 8.4 CLI and PHP-FPM and reload PHP-FPM when its configuration changes.
Once those capabilities are present, the normal push/deploy workflow is enough.
Keep `upload_max_filesize` at least `10M` and `post_max_size` above `10M`.
Use a PHP-FPM `memory_limit` of at least `256M` for typical 12-megapixel phone
photos; higher-resolution sources may require more processing memory.
Uploads above 64 megapixels, images exceeding available PHP processing memory,
and proportional outputs taller than WebP's 16,383px limit are rejected before
decoding rather than cropped. A 10 MB compressed file can still require much
more memory when decoded. Animated WebP uploads are not supported by GD and
are rejected. After deployment, upload a large JPEG/PNG and verify that its
`/api/media/{id}/file` response is `image/webp`, at most 600px wide and uncropped.
See [PHP's WebP constants](https://www.php.net/manual/en/image.constants.php)
for the lossless encoding mode; resizing itself still reduces pixel resolution.

## Admin operational tools

The Tools/background-processing release adds durable PostgreSQL queues, protected
job records, a task registry and directory projections. **Production needs
supervised consumers and the scheduled-task dispatcher before queued deliveries
can progress.** Follow [the first-deployment instructions](server-operations.md#first-deployment-step-by-step).
The workflow migrates, registers missing tasks, builds the frontend and requests
a graceful consumer restart; it does not install or enable systemd/Supervisor.


## Listing images and skeletons

For directory skeletons and cached image thumbnails, the normal workflow applies
`Version20261006180000`. After deployment, run the bounded generation command as
`steelcodeweb` to index existing image dimensions and pre-generate the media
library. See [listing media rollout](media-thumbnails.md#server-rollout) for exact
commands, resuming large libraries, cache behavior and verification. New uploads
generate their variants automatically; no new environment configuration is needed.

## Chat history and seen receipts

Migration `Version20261006160000` adds `(conversation_id, id)` and
`(inquiry_id, id)` history indexes, partial indexes for outgoing read watermarks, and nullable
`inquiry_message.read_at`.
The normal production workflow builds the SPA and runs this migration; no new
`.env.local` or Caddy settings are needed. Keep the existing Mercure configuration
working: seen receipts and direct inquiry messages use the same private user topics.
Migration `Version20261006170000` adds partial unread-message indexes for the
new-message divider, so finding the first unread message uses an index rather than
loading the whole chat. The workflow applies both migrations.
After deployment, reopen installed PWAs so they receive the updated app bundle.

For a manual deployment, apply the migration with
`APP_ENV=prod php8.4 bin/console doctrine:migrations:migrate --no-interaction`
before allowing requests using the new API. The indexes are ordinary transactional
indexes; PostgreSQL may briefly block writes on large message tables while creating
them. Schedule a maintenance window if message tables are already large.

The UI loads the latest 50 messages and another 50 when scrolling up. The API caps
pages at 100. New messages and seen events arrive via Mercure, with cursor catch-up
on reconnect/visibility changes and no periodic message polling. Fetching history
never marks messages read: the visible chat acknowledges its rendered latest message
via a CSRF-protected `/read` request. An open chat scrolled into older history leaves
new messages unread until the user returns to the bottom.

Online/away/offline presence is not enabled. There is no shared presence store in the
current deployment. Adding it for many users requires shared expiring session leases
(e.g. Redis), multiple-device handling, and bounded activity updates; filesystem cache
and per-contact polling should not be used for this feature.

## Deployment steps

The workflow updates the checkout, runs Composer explicitly with PHP 8.4, and
sets `COMPOSER_ALLOW_SUPERUSER=1` so Composer does not disable Symfony Flex
when the SSH login is root. Keep the deployment checkout and locked
dependencies trusted; using a dedicated non-root deployment user is safer.
After installing dependencies, it runs `composer dump-env prod` so web requests
use the compiled production environment instead of the development default in
`.env`. After cache clearing, it assigns
`/home/steelcodeweb/web/wave.ba/public_html/var` to `steelcodeweb:steelcodeweb`
and ensures the owner can write there, including logs, cache, and media.
The deploy step preserves server-local frontend environment files such as
`frontend/.env.production`. If `composer.lock` has local edits, it saves a patch
under the ignored `var/deploy-backups/` directory and restores the tracked
lockfile before pulling; production dependency changes belong in Git, not in a
server-side `composer update`.
It verifies that the active Node version is 22.12+, then executes `npm ci` and
the Vue/PWA build directly from the deployment user's `PATH`. It then applies
Doctrine migrations with `php8.4` and clears the production cache with
`php8.4`. Each deployment runs serially and stops at the first failed command.
The Vue build preserves Symfony's `public/index.php`. The public GA4 measurement
ID is configured in `frontend/.env.production` on the production server and is
embedded during the Vite build; changing that file alone does not update the
served bundle. Google Analytics loads only after Analytics consent; setup and
verification steps are in the README's Search engine metadata section.

The app shell and service-worker scripts must be revalidated rather than cached
as immutable assets. Apache deployments get `Cache-Control: no-cache,
must-revalidate, max-age=0` from `public/.htaccess` for `index.html`, `sw.js`,
`sw.mjs`, `registerSW.js`, and `manifest.webmanifest`. If Nginx serves or
overrides those files, configure the equivalent response headers there and
disable any long `expires` rule for those paths. For example, in the domain's
Nginx server block, exact-match locations can override a generic static-file
cache rule:

```nginx
location = /index.html {
    expires off;
    add_header Cache-Control "no-cache, must-revalidate, max-age=0" always;
}

location = /sw.js {
    expires off;
    add_header Cache-Control "no-cache, must-revalidate, max-age=0" always;
}

location = /sw.mjs {
    expires off;
    add_header Cache-Control "no-cache, must-revalidate, max-age=0" always;
}

location = /registerSW.js {
    expires off;
    add_header Cache-Control "no-cache, must-revalidate, max-age=0" always;
}

location = /manifest.webmanifest {
    expires off;
    add_header Cache-Control "no-cache, must-revalidate, max-age=0" always;
}
```

Verify the live response headers after changing Nginx. In particular,
`/index.html` and `/sw.js` must not return a long `max-age`. Hashed files under
`public/build/assets/` can retain long-lived immutable caching.

#### HestiaCP on Debian

Hestia's default Nginx templates include `nginx.conf_*` and
`nginx.ssl.conf_*` files from the domain's configuration directory. Add a
separate exact-match location for each app-shell file in both HTTP and HTTPS
server blocks. This avoids editing generated vhosts or Hestia's global
templates, and overrides the template's generic `expires max` rule.

For this deployment, the Hestia account is `steelcodeweb` and the document root
is `/home/steelcodeweb/web/wave.ba/public_html/public`. Create a uniquely named
snippet:

```sh
sudo install -d -o steelcodeweb -g steelcodeweb /home/steelcodeweb/conf/web/wave.ba
sudo tee /home/steelcodeweb/conf/web/wave.ba/nginx.conf_pwa-cache >/dev/null <<'NGINX'
location = /index.html {
    root /home/steelcodeweb/web/wave.ba/public_html/public;
    try_files $uri =404;
    expires off;
    add_header Cache-Control "no-cache, must-revalidate, max-age=0" always;
    add_header Expires "0" always;
}

location = /sw.js {
    root /home/steelcodeweb/web/wave.ba/public_html/public;
    try_files $uri =404;
    expires off;
    add_header Cache-Control "no-cache, must-revalidate, max-age=0" always;
    add_header Expires "0" always;
}

location = /registerSW.js {
    root /home/steelcodeweb/web/wave.ba/public_html/public;
    try_files $uri =404;
    expires off;
    add_header Cache-Control "no-cache, must-revalidate, max-age=0" always;
    add_header Expires "0" always;
}

location = /manifest.webmanifest {
    root /home/steelcodeweb/web/wave.ba/public_html/public;
    try_files $uri =404;
    expires off;
    add_header Cache-Control "no-cache, must-revalidate, max-age=0" always;
    add_header Expires "0" always;
}
NGINX
sudo cp /home/steelcodeweb/conf/web/wave.ba/nginx.conf_pwa-cache \
  /home/steelcodeweb/conf/web/wave.ba/nginx.ssl.conf_pwa-cache
sudo chown steelcodeweb:steelcodeweb /home/steelcodeweb/conf/web/wave.ba/nginx.conf_pwa-cache \
  /home/steelcodeweb/conf/web/wave.ba/nginx.ssl.conf_pwa-cache
sudo chmod 644 /home/steelcodeweb/conf/web/wave.ba/nginx.conf_pwa-cache \
  /home/steelcodeweb/conf/web/wave.ba/nginx.ssl.conf_pwa-cache
sudo nginx -t && sudo systemctl reload nginx
```

Verify the public HTTPS responses; each listed file should return the
`no-cache, must-revalidate, max-age=0` policy (not a long `max-age`):

```sh
for path in /index.html /sw.js /registerSW.js /manifest.webmanifest; do
    printf '\n--- %s ---\n' "$path"
    curl -sSI "https://wave.ba$path" |
        grep -iE '^(HTTP/|Cache-Control:|Expires:|Last-Modified:)'
done
```

Once those headers are correct, open the production site or installed PWA while
online. The browser can then revalidate the worker and fetch the new app assets;
clearing site storage should not be necessary.

After configuring the web server, verify that
`https://wave.ba/api/health` returns JSON such as
`{"status":"ok","service":"wave-api"}`. If `/index.php/api/health` works but
`/api/health` returns the hosting provider's HTML 404 page, PHP is running but
the web server is not applying the front-controller rewrite. Fix the rewrite
before troubleshooting API routes or the database.

Symfony application logs are written to daily files under `var/log/`, separated
by environment (`dev-YYYY-MM-DD.log` and `prod-YYYY-MM-DD.log`). Production
application warnings and errors, including OAuth callback failures, are written
to the `prod` log. The rotating handler removes older files when it rotates;
set a positive `LOG_RETENTION_DAYS` value in `.env.local` or the server
environment to change the retention window.

The historical migrations were generated with SQLite-specific SQL. The
compatibility layer adapts those statements and table changes for PostgreSQL,
so a new PostgreSQL installation should run the normal migration chain; do not
create the schema manually or baseline unapplied migrations. If a PostgreSQL
migration previously failed on `AUTOINCREMENT`, deploy the compatibility
update, check `php8.4 bin/console doctrine:migrations:status`, then rerun
`php8.4 bin/console doctrine:migrations:migrate --no-interaction`. Back up
production data before applying migrations.

After configuring the secrets and server, run the workflow manually once to
verify the deployment environment before relying on pushes to `main`.
