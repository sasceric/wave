# Wave project handoff

Reviewed on 2026-10-05. This is a code orientation, not a runtime or production health check.

## Product and architecture

Wave connects creators and companies for brand collaborations. Public pages include creator, company, and campaign directories and profiles. Signed-in users manage profiles, applications, invitations, offers, bookmarks, and messages. Administrators approve registrations and manage homepage content, marketplace records, and localized email templates; moderators have catalog moderation tools.

Symfony serves a Vue SPA and same-origin JSON API in one deployment. `frontend/` contains the frontend source and its npm dependencies, but its build output is served by Symfony from `public/`. Do not introduce a separate deployment or Twig page rendering. Email HTML templates live in `templates/emails/` and use the custom renderer.

Declared dependencies: PHP 8.4+, Symfony 8.1, Doctrine ORM 3, Vue 3, Vue Router, Vue I18n, Vite 8, and vite-plugin-pwa. Frontend code uses JavaScript and Vue single-file components. The current local app uses PostgreSQL; some setup documentation and the checked-in test environment still default to SQLite. Validate database-backed changes against a separate PostgreSQL test database. Check local configuration privately before running commands rather than assuming the README describes current machine state.

## Where to work

| Concern | Starting points |
| --- | --- |
| API endpoints and workflows | `src/Controller/Api/` |
| JSON serialization and request/access helpers | `src/Api/`; especially `ApiAccess.php`, `JsonPayload.php`, and domain `*Resource.php` files |
| Database model | `src/Entity/`, `migrations/` |
| Registration, login, verification, password reset | `AuthController.php`, `src/Security/SessionAuthenticator.php`, `src/Account/` |
| Google and Apple login | `OAuthController.php`, `src/OAuth/` |
| Applications, shortlisting, and offers | `MarketplaceWorkflowController.php` |
| Campaign invitations and private chat | `CampaignInvitationController.php`, `CampaignMessagingController.php` |
| Notifications and live updates | `src/Service/NotificationDelivery.php`, `RealtimeUpdatePublisher.php`, `WebPushNotificationSender.php`; realtime/push API controllers |
| Device app badges and push links | `src/Service/UnreadInboxCounter.php`, `frontend/src/lib/appBadge.js`, `notificationNavigation.js`, `frontend/src/sw.js`, `MessagesView.vue`; `docs/server-notifications.md` |
| Page layouts and features | `frontend/src/views/` |
| Reusable UI | `frontend/src/components/shared/`, domain component directories |
| Session and shared frontend state | `frontend/src/composables/useCurrentUser.js` and other composables |
| API requests, CSRF, uploads, formatting | `frontend/src/lib/api.js` |
| Visual styles | `frontend/src/wave.css`, existing component styles |
| Localized navigation | `config/localized_routes.json`, `frontend/src/routePaths.js`, `frontend/src/router.js` |
| Translations | `frontend/src/locales/`, `src/Localization/` |
| SEO and server-rendered metadata | `src/Controller/FrontendController.php`, `SeoController.php`, `src/Service/SeoMetadataProvider.php`, `SitemapGenerator.php`, `frontend/src/lib/seo.js` |
| Service worker and app shell | `frontend/src/sw.js`, `frontend/vite.config.js`, `frontend/src/App.vue` |
| Tests | `tests/Controller/` plus API, OAuth, service, command, and migration tests |
| Deployment | `.github/workflows/deploy-production.yml`, `docs/deployment.md`, `docs/mercure-server-setup.md` |

Controller filenames in this table are under `src/Controller/Api/` unless a full path is shown.

## Behavior to preserve

- Authentication uses session cookies. Mutating API calls require `X-CSRF-Token`; use the existing API client and backend access helpers. Enforce ownership and participant checks on the server.
- Marketplace actions generally require admin approval and verified email. `ApiAccess` centralizes role/approval checks, with verification requested by the relevant endpoint.
- Shortlisting an application opens a campaign/creator conversation. An invitation opens its conversation after the creator accepts. Offers have their own acceptance/rejection flow.
- Mercure delivers private per-user live events containing notification identifiers and, for campaign chat, the new message. The frontend applies messages immediately; explicit CSRF-protected POST `/api/me/conversations/{id}/read` and `/api/me/inquiries/{id}/read` acknowledge the latest rendered message only in a visible chat at the bottom. Private `chat_read` events update outgoing seen checks. Direct inquiry message events also use the private user stream. Web Push provides background device notifications and signals open app windows to reconcile their inbox. There is no periodic inbox polling.
- Unread chat reminder emails omit message text. The scheduled command is `app:send-unread-message-reminders`; the README explains timing and deduplication.
- The PWA caches static app assets, not private API content. Preserve the service worker's API exclusions.
- Supported frontend locales are `bs` (default), `hr`, `sr` (Latin), `sl`, `en`, and `cnr` (Montenegrin). Older documentation lists only five; the current `i18n.js` loads all six. Keep new interface copy in the catalogs and update localized routes consistently when adding pages.
- Reuse existing UI components and design styles before adding new patterns.
- Schema changes need Doctrine migrations. Historical SQLite migrations have a PostgreSQL compatibility layer in `src/Migration/`; avoid manual schema baselining.
- Edit source assets in `frontend/public/` and frontend source files, then rebuild. The Vite build writes into `public/` and preserves Symfony's entry point; generated bundles are not the place to edit features.

## Local development

From the repository root, the documented macOS PHP selection is:

```sh
export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"
```

Use a Node version satisfying `frontend/package.json` (Node 22.20 is the README's verified example). For a new checkout:

```sh
composer install
npm --prefix frontend ci
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:seed-demo-data
npm --prefix frontend run build
```

Run the backend and frontend in separate terminals:

```sh
php -S 127.0.0.1:8000 -t public public/router.php
```

```sh
npm --prefix frontend run dev -- --host 127.0.0.1
```

Open `http://127.0.0.1:5173` for hot reload or `http://127.0.0.1:8000` for the built app. Vite proxies `/api` to port 8000. Use `127.0.0.1` consistently because session and Mercure subscription cookies are host-scoped.

For realtime features, install the native hub once with `./bin/install-mercure.sh`, then run `php bin/mercure-local.php` in another terminal. Reuse an existing hub rather than starting a second process against the same Bolt database. See the README for mail, OAuth, VAPID, and Mercure configuration. Keep credentials and private keys out of documentation and commits.

## Validation and continuation notes

Backend tests: `php bin/phpunit`. Frontend regression tests: `npm --prefix frontend test`. Frontend production build: `npm --prefix frontend run build`. Inspect test database setup before running database-backed tests; use an isolated test database rather than the local account database.

Existing `.github/copilot-instructions.md` requires PHPStan for PHP changes and ESLint for JavaScript/Vue changes. At review time, neither was declared in the corresponding package manifest, and no project configuration was found outside dependency directories. This is a tooling gap to report or resolve when making code changes; do not claim those checks passed.

The production workflow deploys on pushes to `main`, as well as manual dispatch. Inspect it before pushing changes intended only for local review.

At the start of the orientation review, there were existing uncommitted changes to `docs/mercure-server-setup.md` and a staged addition with a working-tree deletion of `frontend/public/favicon.svg` (`AD` in `git status`). Preserve user work and recheck status before editing or committing. The subsequent notification investigation changed application code and added tests; see `docs/notification-investigation.md` for findings, validation, and the remaining production configuration work.

For the next Codex app session, use the repository root as the project directory. Project instructions live in `AGENTS.md`. Read this handoff and the relevant README sections when orientation is needed, then inspect `git status` and the files for the requested feature before changing code.

### Cursor-based chat history

`ChatMessageHistory` loads at most 50 messages by default (maximum 100). Both chat GET
endpoints accept exclusive `before` or `after` message-ID cursors and return ascending
messages, paging `meta`, and the latest outgoing `readReceipt` watermark. GET requests
are read-only. `ChatReadReceipt` bulk-updates incoming unread messages through a cursor
that belongs to the authorized thread; it does not create notifications for seen events.
The Vue Messages view prepends older pages with a retained DOM anchor, merges by ID,
and catches up with bounded `after` pages after a reconnect. New incoming messages do
not force scroll while reading history. There is no online/away/offline presence service.
Creators and campaigns directories use two columns on phones and desktop with existing
server pagination (30/60/90); homepage carousels are separate from directory grids.

The history `meta.firstUnreadId` identifies the viewer's first incoming unread message
across all pages. The Messages view preserves a visit's unread divider after read
acknowledgement, opens at its first loaded unread message, and offers “Load earlier
unread messages” if the boundary is outside the loaded page. Lazy-loaded older
pages move that divider back to the actual boundary without losing the scroll anchor.
Unread divider boundaries reset when switching/leaving a thread, and a later unseen
batch gets a fresh boundary. Migration `Version20261006170000` indexes unread lookups.
