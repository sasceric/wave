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
  `composer.json`, and Node.js 22.12 or newer installed through NVM. NVM must
  be initialized by the deployment user's Bash startup configuration.
- A PHP-capable web server configured with document root
  `/home/steelcodeweb/web/wave.ba/public_html/public`. Do not expose the
  repository root as the web document root.
- A production environment file or server environment variables with
  `APP_ENV=prod`, `APP_DEBUG=0`, a unique `APP_SECRET`, `DATABASE_URL`, and
  the production mail, public-origin, Mercure, OAuth, and Web Push settings
  that are enabled for the site. Keep secrets out of GitHub workflow YAML and
  the repository.
- Write access for the PHP process to `var/cache/` and `var/media/`.

## Deployment steps

The workflow updates the checkout, runs Composer explicitly with PHP 8.4, and
sets `COMPOSER_ALLOW_SUPERUSER=1` so Composer does not disable Symfony Flex
when the SSH login is root. Keep the deployment checkout and locked
dependencies trusted; using a dedicated non-root deployment user is safer.
It starts an interactive Bash shell to load the deployment user's NVM setup,
runs `nvm use 22`, and executes `npm ci` and the Vue/PWA build. It then applies
Doctrine migrations with `php8.4` and clears the production cache with
`php8.4`. Each deployment runs serially and stops at the first failed command.
The Vue build preserves Symfony's `public/index.php`.

After configuring the secrets and server, run the workflow manually once to
verify the deployment environment before relying on pushes to `main`.
