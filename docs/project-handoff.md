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
| Images, thumbnails and indexing | `MediaController.php`, `src/Service/MediaStorage.php`, `ImageUploadProcessor.php`, `MediaThumbnails.php`, `GenerateMediaThumbnailsCommand.php`; `docs/media-thumbnails.md` |
| Visual styles | `frontend/src/scss/` mirrors Vue component/view folders; `frontend/src/scss/global.scss` holds foundations; `docs/frontend-styles.md` |
| Localized navigation | `config/localized_routes.json`, `frontend/src/routePaths.js`, `frontend/src/router.js` |
| Translations | `frontend/src/locales/`, `src/Localization/` |
| SEO and server-rendered metadata | `src/Controller/FrontendController.php`, `SeoController.php`, `src/Service/SeoMetadataProvider.php`, `SitemapGenerator.php`, `frontend/src/lib/seo.js` |
| Service worker and app shell | `frontend/src/sw.js`, `frontend/vite.config.js`, `frontend/src/App.vue` |
| Tests | `tests/Controller/` plus API, OAuth, service, command, and migration tests |
| Deployment and server jobs | `.github/workflows/deploy-production.yml`, `docs/deployment.md`, `docs/server-operations.md`, `docs/mercure-server-setup.md` |
| Admin operational tools | `AdminToolsController.php`, `Background/TaskRegistry.php`, `Background/AdminWorker.php`, `QueueInspector.php`, `AdminLogReader.php`, `frontend/src/views/AdminToolsView.vue` |
| Environment configuration | `config/packages/dev/`, `prod/`, `test/`; `docs/environment-configuration.md` |
| Background processing | `docs/background-processing-plan.md`: FroshTools comparison, queue/task catalog, indexing, logging, admin controls and rollout |

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
- New JPEG/PNG/WebP uploads are processed centrally into lossless WebP, at most 600px wide, with proportional height, no cropping and no upscaling. Preserve alpha and apply JPEG EXIF orientation before measuring. Record the encoded MIME type and byte size. Existing media stays unchanged; preserve media ownership and public visibility checks.
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

The frontend tests use experimental VM modules. Node 20.19 and Node 22.20 have
intermittently crashed with `SIGSEGV` in that runner, including serial runs and
the GitHub Actions `adminTools.test.js` suite. Frontend CI therefore uses Node
24.19, and `npm --prefix frontend test` runs with `--test-concurrency=1` for
consistent local/CI execution. Use Node 24.19 for frontend validation. The
backend job's frontend build and production deployment remain on Node 22; no
production server runtime change is required for the test-runner fix.

Required static checks are now installed: `composer analyse` runs PHPStan level 5
with Doctrine metadata/DQL analysis, and `npm --prefix frontend run lint` runs ESLint
and Vue essential rules. `phpstan.neon` and `frontend/eslint.config.js` define the
checks. The metadata loader uses a disconnected PostgreSQL configuration, so it
needs no database credentials. PHPDoc is treated as a hint so runtime input
validation is still checked. There is no suppressed historical-error baseline.
The validation workflow also runs tests and the frontend production build.
See `docs/performance-baseline.md` for pagination, measured local performance,
the reproducible synthetic benchmark and deployment requirements.

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
Creators and campaigns directories use four columns on desktop and two on phones; homepage
carousels are separate from directory grids. Public directories load batches as described
below; account campaign management retains its own pagination.

The history `meta.firstUnreadId` identifies the viewer's first incoming unread message
across all pages. The Messages view preserves a visit's unread divider after read
acknowledgement, opens at its first loaded unread message, and offers “Load earlier
unread messages” if the boundary is outside the loaded page. Lazy-loaded older
pages move that divider back to the actual boundary without losing the scroll anchor.
Unread divider boundaries reset when switching/leaving a thread, and a later unseen
batch gets a fresh boundary. Migration `Version20261006170000` indexes unread lookups.

### Mobile chat keyboard

`MessagesView.vue` keeps one composer input mounted when the first message creates a
conversation. A primary pointer press on Send prevents focus transfer while the
input is focused. Mobile form submission also restores input focus synchronously
inside the Send gesture when the keyboard is open; never move that focus after the
network response, because the user may have moved elsewhere and iOS may reject it.

The mobile chat uses the visual viewport's height and keyboard pan offset. It keeps
an idle layout-height baseline for installed PWAs that resize every viewport metric,
clears the bottom safe-area inset while the keyboard is open, and ignores stale pan
offsets after it closes. Resize, scroll, input, focus and visibility events trigger
immediate/frame measurements plus three bounded checks through 750 ms for delayed
keyboard/emoji transitions. These checks never fetch messages or notifications and
are cancelled on hide/unmount. The composer remains in the mobile flex layout with
16px input text; the timeline supplies the scrollable area.

Regression coverage is in `frontend/tests/chatKeyboard.test.js`. Desktop Chrome
validation retained input focus after Send and kept both controls visible when the
viewport changed from 428×926 to 428×430, then 428×390. This simulates available
height, not the native iOS keyboard. After deploying, check the installed iPhone PWA:
send several messages with the keyboard open, switch between text and emoji, dismiss
and reopen the keyboard, and send the first message in a new conversation. Verify
focus, visible controls, and restoration of the bottom inset. This frontend fix needs
the normal production build/deployment; no environment or database changes.

### Lazy-loaded public directories

Directory grids show six initial skeleton cards and two append skeletons while
loading. Shared `SkeletonBlock`, `LoadingSkeleton` and `InboxSkeleton` components
cover home, account, profiles, campaign details, chat and admin loading states;
see `docs/media-thumbnails.md` for behavior and accessibility. `CardImage.vue` preserves media slots, lazy-loads responsive images,
reveals cache hits immediately and settles errors on a logo fallback. Skeletons
reuse card styles and respect reduced motion. Uploaded listing images use
96/320/480px lossless WebP variants via the authorized media controller; resources
include dimensions and `srcset` metadata without filesystem work. Thumbnail files
persist under `var/media/thumbnails/v1/` with locks and atomic writes. New uploads
warm variants automatically. Migration `Version20261006180000` records nullable
media dimensions, and `app:media:generate-thumbnails` backfills existing images in
resumable ID batches. See `docs/media-thumbnails.md` for caching/access behavior,
external provider fallback, deletion cleanup, deployment commands and tests.

Creators, campaigns and companies use `useInfiniteDirectory.js` and the shared
`DirectoryLoadMore.vue` sentinel. The first request loads 30 records; approaching
200px from the list's end loads the next 30 using `pagination=cursor` and an opaque
`cursor`. `DirectoryCursor` signs each position and binds it to filters and locale.
The first page returns the total; later pages use a one-record lookahead instead
of another count. The loader preserves cards during loading/errors, prevents
overlapping requests, deduplicates by ID and invalidates stale filter/locale
responses. `hasMore=false` shows the localized end message in all six locales.
Existing offset API callers are supported. The Load more/retry button provides
keyboard access and an observer fallback. There is no periodic directory polling;
the observer disconnects while loading, on errors, at completion and on unmount.
Admin catalogs use `AdminCatalog` through `/api/admin/catalog/{kind}` with server
search/sort and 25/50/100 row pages. Homepage selectors request bounded candidate
pages, retaining selected records separately. Overview fetches metrics only.

Public APIs enforce visibility before counting and paging: hidden or unapproved
creator/company owners are excluded, and campaigns must be open, unexpired and
belong to a visible approved company (ownerless seeded catalog entries retain their
existing public behavior). Company ranking now uses the ID as its final tie-breaker,
as creator and campaign ordering already did. No schema or environment changes are
needed. Frontend regressions cover appending, stale responses, retries, deduplication,
and observer lifecycle. `ApiControllerTest` covers 61 visible entries per directory
across 30/30/1/empty batches, including hidden/unapproved accounts and closed/expired
campaigns, against a separate temporary PostgreSQL database.

## Background jobs (2026-10-06)

`src/Background` and `JobHandler` implement protected durable jobs and real
Messenger processing. Four Doctrine queue names plus `failed` use the existing
PostgreSQL connection; `ApiTransactionSubscriber` commits API domain writes and
delivery intents together. `QueuedMailer` decorates Mailer with its bus explicitly
disabled; the worker calls the actual transport. Keep APP_SECRET stable.

`TaskRegistry` owns eight task definitions. Commands: `app:scheduled-tasks register`
and `app:scheduled-tasks dispatch`. Development admin pages consume bounded
requests via `useAdminWorker`; prod requires the checked-in systemd/Supervisor
examples. See `docs/server-operations.md`; do not install old reminder/cache cron
jobs alongside the new dispatcher.

Index projections are invalidated on source mutations and queried with source
fallbacks; source visibility/date checks remain authoritative. Media masters are
normalized synchronously; derivative creation, repair and backfills are queued.
Sitemap generation streams scalar batches to immutable split files and swaps the
index after success. Logs retain bounded reverse cursor paging and modal details.

The old ScheduledTaskMonitor observes direct legacy command invocations only;
new Tools task state comes from TaskRegistry, not that command observer.

## Cursor-based inbox loading (2026-10-06)

`InboxController` and `InboxHistory` expose `GET /api/me/inbox` for a merged
campaign/direct-inquiry inbox. The default batch is 30 (maximum 100), ordered by
latest activity descending, type ascending and ID descending. Opaque cursors retain
timestamp precision and avoid offset shifts. Search (`q`, maximum 200 characters)
and `filter=unread` apply on the server across the user's entire inbox; only accepted
inquiries appear. PostgreSQL lateral queries retrieve inquiry previews in the same
query, and list resources omit full campaign briefs and message history.

`GET /api/me/inbox/{campaign|inquiry}/{id}` resolves one authorized thread for
notification links and full chat details, even outside loaded/filtered batches.
`GET /api/me/inbox/unread` returns the aggregate message count without serializing
conversations. The existing conversation/inquiry endpoints remain compatible with
account workflows. The new endpoints use participant approval/verification helpers
and enforce ownership; GET requests do not acknowledge messages.

`MessagesView.vue` appends batches through the shared `DirectoryLoadMore` sentinel,
which accepts the inbox's scroll container as its observer root. Existing row
skeletons reserve space. Search debounces for 250ms and invalidates stale requests
immediately; retries retain their cursor and loaded rows. Selected chat details
survive filter changes. Mercure applies known chats directly and retrieves only
the authorized metadata of an unloaded chat. Event-driven catch-up refreshes the
head while retaining older rows/cursors; no inbox polling was introduced. Local
thread revisions prevent a slow batch from undoing live previews or read receipts.

Migration `Version20261006210000` adds conversation activity and latest inquiry
message indexes. Normal GitHub deployment applies it; no environment, scheduler or
worker changes are needed. Validate against PostgreSQL. Further work remains on
public-directory cursors, admin catalog pagination and representative load testing.
