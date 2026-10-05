# Production deployment

The `Deploy production` GitHub Actions workflow deploys `main` to
`/home/steelcodeweb/web/wave.ba/public_html/` after each push. It can also be
started manually from **Actions → Deploy production → Run workflow**.

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

## Deployment steps

The workflow updates the checkout, runs Composer explicitly with PHP 8.4, and
sets `COMPOSER_ALLOW_SUPERUSER=1` so Composer does not disable Symfony Flex
when the SSH login is root. Keep the deployment checkout and locked
dependencies trusted; using a dedicated non-root deployment user is safer.
After installing dependencies, it runs `composer dump-env prod` so web requests
use the compiled production environment instead of the development default in
`.env`. After cache clearing, it assigns `var/` to the Hestia site account
`steelcodeweb`, allowing PHP-FPM to write logs, cache, and media.
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
