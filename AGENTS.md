# Wave project instructions

Use `docs/project-handoff.md` for architecture, key code paths, local commands, and known tooling gaps when working in an unfamiliar area. Use the relevant README sections for setup and integration configuration.

## Architecture and implementation

- Preserve the single-deployment Symfony JSON API and Vue SPA architecture. Frontend source lives in `frontend/` and builds into Symfony's `public/`; do not add Twig page views or a separate frontend deployment.
- Reuse existing shared components and design styles. Keep UI changes accessible, responsive, and consistent with surrounding views.
- Keep interface copy in all six existing locale catalogs: `bs`, `hr`, `sr`, `sl`, `en`, and `cnr`. Keep localized route mappings consistent when adding pages.
- Use the existing API client, CSRF protection, and backend access helpers. Preserve account approval, email verification, ownership, and conversation participant checks.
- Manage schema changes with Doctrine migrations and preserve PostgreSQL compatibility for historical SQLite migrations.
- Follow `.editorconfig` and surrounding formatting. Keep logic and templates readable.

## Validation

- Run relevant backend tests with `php bin/phpunit` and validate frontend changes with `npm --prefix frontend run build`.
- The existing repository coding policy requires PHPStan for PHP changes and ESLint for JavaScript/Vue changes. Neither is currently declared/configured in the project manifests. Report unavailable checks explicitly; do not claim they passed.
- For documentation-only changes, check the diff and whitespace; application tests are not necessary.

## Local and deployment context

- The documented macOS PHP executable is under `/opt/homebrew/opt/php@8.4/bin`. Use the Node requirements in `frontend/package.json`.
- Local backend: `php -S 127.0.0.1:8000 -t public public/router.php`. Frontend: `npm --prefix frontend run dev -- --host 127.0.0.1`. Use `127.0.0.1` consistently for session and Mercure cookies.
- Check current Git status and preserve existing user changes. Keep secrets and private keys out of commits and tool output.
- Consult `docs/deployment.md` when preparing deployment. Pushes to `main` trigger the production workflow.
