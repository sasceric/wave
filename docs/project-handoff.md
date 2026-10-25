# Wave project handoff

Reviewed on 2026-10-05. This is a code orientation, not a runtime or production health check.

## Product and architecture

Wave connects creators and companies for brand collaborations. Public pages include creator, company, and campaign directories and profiles. Signed-in users manage profiles, applications, invitations, offers, bookmarks, and messages. Administrators approve registrations and manage homepage content, marketplace records, and localized email templates; moderators have catalog moderation tools.

Symfony serves a Vue SPA and same-origin JSON API in one deployment. `frontend/` contains the frontend source and its npm dependencies, but its build output is served by Symfony from `public/`. Do not introduce a separate deployment or Twig page rendering. Email HTML templates live in `templates/emails/` and use the custom renderer.

Declared dependencies: PHP 8.4+, Symfony 8.1, Doctrine ORM 3, Vue 3, Vue Router, Vue I18n, Vite 8, and vite-plugin-pwa. Frontend code uses JavaScript and Vue single-file components. The current local app uses PostgreSQL; some setup documentation and the checked-in test environment still default to SQLite. Validate database-backed changes against a separate PostgreSQL test database. Check local configuration privately before running commands rather than assuming the README describes current machine state.

## Where to work

Support tickets use `SupportTicketController`, `SupportInboxController`, `SupportTicket`,
`SupportTicketMessage`, and `Support/`
for guest submission, receipts, private uploads and capability-link tracking.
`SupportView.vue` handles the landing page and three-step form; `TicketInboxView.vue` shares the cursor-based admin/customer inbox and reply
history. Signed-in reports store account ownership; guests match by email. Public
replies notify by email and bell, with no live conversation subscription. See
[support tickets](support-tickets.md) for limits, file compression, deployment
and support access rules.

Creator profiles support optional multiple creator types (influencer, UGC creator,
photographer, videographer, model, actor, streaming and podcasts), independently
of topics and social platforms.
The shared catalog is `config/creator_types.json`; `Account/CreatorTypes.php` validates
profile writes and directory filters. `creatorTypes` is exposed by full/card creator
resources and accepts a JSON array in GET `/api/creators`. Selected types match any
of those types, combined with the other filters, and are part of the signed cursor
scope. Migration `Version20261008150000` adds an empty JSON list for existing profiles;
omitted profile fields preserve the stored types. The account editor and public
profile labels use all seven locale catalogs. New types reuse the shared catalog
without a schema migration.

The guides index map uses local Natural Earth v5.1.2 country boundaries in
`frontend/src/assets/creator-region-map.svg`, with a shared Mercator projection.
The source and extent are documented in the SVG; preserve its geographic outlines
when changing map colors. Guide cards have no numbered badges.

`SeoGuidesView.vue` serves both company guides (`/vodici`) and creator guides
(`/vodici-za-kreatore`), including three creator articles about profiles, packages
and applications. `/kako-funkcionise` uses `HowItWorksView.vue` for both audiences.
All six locales share route mappings and catalog-backed server-readable content
through `RegionalSeoContent.php`; both sitemap generators include these pages.
Creators-directory guide/country links live below desktop filters and are hidden
on mobile. The shared guides layout keeps the 1400px container. Guide articles use icon step
cards and compact related-guide links; their regional panel reuses the same map.
`SeoHeroArtwork.vue` shares the decorative artwork across guide and how-it-works
headers. The how-it-works page has audience workflow cards and a collaboration
call to action; public guides do not describe internal credit settings.

`SeoGuidesView.vue` maps the four published YouTube tutorials to the creator and
company guide indexes and their relevant articles. `GuideVideo.vue` shows local
posters from `frontend/public/images/tutorials/` and loads a responsive
youtube-nocookie.com player only after a click. Labels use all seven locales;
the recordings are in Bosnian. The footer channel link lives in `App.vue`.

Admin QR scan counts use `QrScanStatistics.php` and `wave_qr_scan_daily`, added by
`Version20261008160000`. Counts are daily location aggregates, not visitor records.
Cloudflare location headers are accepted only from allowlisted edge peers; see
`docs/admin-qr-codes.md` for configuration and counting semantics.

| Concern | Starting points |
| --- | --- |
| API endpoints and workflows | `src/Controller/Api/` |
| JSON serialization and request/access helpers | `src/Api/`; especially `ApiAccess.php`, `JsonPayload.php`, and domain `*Resource.php` files |
| Database model | `src/Entity/`, `migrations/` |
| Registration, login, verification, password reset | `AuthController.php`, `src/Security/SessionAuthenticator.php`, `src/Account/` |
| Google and Apple login | `OAuthController.php`, `src/OAuth/` |
| Applications, shortlisting, and offers | `MarketplaceWorkflowController.php` |
| Newsletter subscriptions and confirmation email | `NewsletterSubscriberController.php`, `src/Newsletter/`, `newsletter_subscriber` migration; admins review signups under Marketing → Subscribers |
| Campaign invitations and private chat | `CampaignInvitationController.php`, `CampaignMessagingController.php` |
| Notifications and live updates | `src/Service/NotificationDelivery.php`, `RealtimeUpdatePublisher.php`, `WebPushNotificationSender.php`; realtime/push API controllers |
| Device app badges and push links | `src/Service/UnreadInboxCounter.php`, `frontend/src/lib/appBadge.js`, `notificationNavigation.js`, `frontend/src/sw.js`, `MessagesView.vue`; `docs/server-notifications.md` |
| Page layouts and features | `frontend/src/views/` |
| Reusable UI | `frontend/src/components/shared/`, domain component directories |
| Fictional presentation accounts and repeatable showcase import | `SeedPresentationDemoCommand.php`, `Demo/PresentationDemoSeeder.php`, `docs/presentation-demo.md` |
| Creator profile, audience, work and PDF presentation | `CreatorStudioPanel.vue`, `CreatorPresentation.php`, `docs/creator-profile-studio.md` |
| Session and shared frontend state | `frontend/src/composables/useCurrentUser.js` and other composables |
| API requests, CSRF, uploads, formatting | `frontend/src/lib/api.js` |
| Images, thumbnails and indexing | `MediaController.php`, `src/Service/MediaStorage.php`, `ImageUploadProcessor.php`, `MediaThumbnails.php`, `GenerateMediaThumbnailsCommand.php`; `docs/media-thumbnails.md` |
| Visual styles | `frontend/src/scss/` mirrors Vue component/view folders; `frontend/src/scss/global.scss` holds foundations; `docs/frontend-styles.md` |
| Localized navigation | `config/localized_routes.json`, `frontend/src/routePaths.js`, `frontend/src/router.js` |
| Translations | `frontend/src/locales/`, `src/Localization/`, `config/translations/de.json`; [German localization](structured-data.md#german) |
| Country SEO and guides | `RegionalSeoContent.php`, `CountryCreatorsView.vue`, `SeoGuidesView.vue`, `config/seo_countries.json`; `docs/seo-keyword-plan.md` |
| SEO and server-rendered metadata | `src/Controller/FrontendController.php`, `SeoController.php`, `src/Service/SeoMetadataProvider.php`, `ProfileStructuredData.php`, `SitemapGenerator.php`, `frontend/src/lib/seo.js`; [schema coverage](structured-data.md) |
| Service worker and app shell | `frontend/src/sw.js`, `frontend/vite.config.js`, `frontend/src/App.vue` |
| Tests | `tests/Controller/` plus API, OAuth, service, command, and migration tests |
| Deployment and server jobs | `.github/workflows/deploy-production.yml`, `docs/deployment.md`, `docs/server-operations.md`, `docs/mercure-server-setup.md` |
| Admin operational tools | `AdminToolsController.php`, `Background/TaskRegistry.php`, `Background/AdminWorker.php`, `QueueInspector.php`, `AdminLogReader.php`, `frontend/src/views/AdminToolsView.vue` |
| Admin QR links | `AdminQrController.php`, `src/Controller/QrRedirectController.php`, `src/Entity/QrLink.php`, `frontend/src/views/AdminQrView.vue`; `docs/admin-qr-codes.md` |
| Environment configuration | `config/packages/dev/`, `prod/`, `test/`; `docs/environment-configuration.md` |
| Background processing | `docs/background-processing-plan.md`: FroshTools comparison, queue/task catalog, indexing, logging, admin controls and rollout |

Controller filenames in this table are under `src/Controller/Api/` unless a full path is shown.

## Behavior to preserve

- Agency companies also offer services and apply to campaigns without becoming creator accounts. `AgencyProfiles` reuses private studio/collaboration participants marked `creator.agency_profile`; exclude those from all public creator discovery and SEO. Keep per-thread client/provider identity and separate public review roles. See [agency service profiles](agency-service-profiles.md) and migrations `Version20261017120000` / `Version20261017210000`. Structured services live in `company.services`; the latter migration preserves old labels. Creator/agency tabs, public packages and FAQs share `ProfileTabs`, `ProfilePackages`, `ProfileFaqs` and `ProfileFaqFields`.

- Public deletion redirects live in `UrlRedirects`, `UrlRedirect`,
  `AdminRedirectController` and `AdminRedirectsView.vue`. They capture original
  slugs before anonymization, add canonical and historical alias rules in the deletion transaction,
  and preserve administrator overrides. Symfony serves 301s; the Vue route guard
  resolves SPA links. Keep APIs, account/admin routes and private history exempt.
  Public details share `/kreatori`, `/kompanije` and `/kampanje` paths through
  `config/shared_public_routes.json`; directories remain localized. Owners edit
  their slug with a first-change exemption and a 14-day cooldown. Permanent slug
  reservations and direct 301 history use `PublicUrlSlugs`, `PublicUrlRedirectWriter`
  and `PublicUrlController`. Both sitemap generators emit details once.
  See `docs/url-redirects.md` and migrations `Version20261012100000` and
  `Version20261013190000`.
- Route titles share `config/seo_route_titles.json` between Symfony and Vue.
  Login/register/recovery modes have explicit localized titles; known private
  routes must not fall through to the not-found title. Titles use `Page | Wave`,
  with the brand first on home. Private routes remain noindex.

- Authentication uses session cookies. Mutating API calls require `X-CSRF-Token`; use the existing API client and backend access helpers. Enforce ownership and participant checks on the server.
- Marketplace actions generally require admin approval and verified email. `ApiAccess` centralizes role/approval checks, with verification requested by the relevant endpoint.
- Individual and bulk admin approval publish newly approved creator and company profiles automatically. Verified email and required profile completion remain mandatory. Owners can hide profiles afterward; re-approving an already approved account preserves that choice. See [account activation](account-activation.md).
- Shortlisting an application opens a campaign/creator conversation. An invitation opens its conversation after the creator accepts. Offers have their own acceptance/rejection flow.
- Mercure delivers private per-user live events containing notification identifiers and, for campaign chat, the new message. The frontend applies messages immediately; explicit CSRF-protected POST `/api/me/conversations/{id}/read` and `/api/me/inquiries/{id}/read` acknowledge the latest rendered message only in a visible chat at the bottom. Private `chat_read` events update outgoing seen checks. Direct inquiry message events also use the private user stream. Web Push provides background device notifications and signals open app windows to reconcile their inbox. There is no periodic inbox polling.
- Unread chat reminder emails omit message text. The scheduled command is `app:send-unread-message-reminders`; the README explains timing and deduplication.
- The PWA caches static app assets, not private API content. Preserve the service worker's API exclusions.
- Installed PWAs support pull-to-refresh through `PullToRefresh.vue` and
  `lib/pullToRefresh.js`. A single-finger downward pull of at least 96px from
  the document top reloads the current URL on release. Browser tabs keep their
  existing behavior. Nested scrolling, horizontal carousels, pinch zoom,
  offline state and open dialogs do not trigger the gesture. Editing a form or
  draft blocks refresh conservatively until that form leaves the DOM; custom
  select clicks and dropped uploads are included. No worker or server setup is
  needed. Validate native gestures on installed iOS/Android apps before release.
- The full-screen startup splash lives in `frontend/index.html` with critical SCSS in `frontend/src/scss/startup.scss`, so it displays before Vue loads. `main.js` restores the session once and removes the splash after the initial route and session settle, with a ten-second fallback for stalled requests. Later navigation, page requests, and chat history use their existing skeletons; do not wait for images, realtime connections, or service-worker updates to dismiss startup.
- Canonical prefixes are configured separately in `config/localized_route_prefixes.json`: `/rs`, `/me` and `/si` permanently replace `/sr`, `/cnr` and `/sl`; locale identifiers do not change.
- Supported locales are `bs` (default), `hr`, `sr` (Latin), `sl`, `en`, `cnr` (Montenegrin), and `de` (German). The current `i18n.js` loads all seven. Keep new interface copy in the catalogs and update localized routes consistently when adding pages. Historical six-locale snapshots remain unchanged; German standard area labels are added by `Version20261016160000`.
- Reuse existing UI components and design styles before adding new patterns.
- Preserve the exact brand palette and fonts documented in `docs/frontend-styles.md`;
  performance and SEO changes must not replace them.
- New JPEG/PNG/WebP uploads are processed centrally into lossless WebP, at most 1920px wide for company/campaign covers, 1600px for report evidence, and 600px for other images, with proportional height, no cropping and no upscaling. Preserve alpha and apply JPEG EXIF orientation before measuring. Record the encoded MIME type and byte size. Existing media stays unchanged; preserve media ownership and public visibility checks.
- `UnusedMediaCleanup` removes replaced profile/campaign images and removed portfolio images only when no references remain. Save transactions enqueue physical deletion through `StoredFileCleanup`; sources and all thumbnails are removed after commit, with retries on failure. Daily media maintenance also cleans unattached uploads older than 24 hours in its existing bounded batches. See `docs/media-thumbnails.md`.
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
The shared `formValidation` directive validates every submit, then disarms automatic
revalidation after a valid submit. This lets successful chat, invitation and campaign
handlers clear their drafts without showing false required-field errors. Invalid
submissions retain live correction feedback; native form resets clear validation
messages and accessibility error state. `formValidation.test.js` covers native and
custom fields, valid submit/clear cycles, invalid retries and resets.
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

Chat replies use `replyToId` on both message POST endpoints and validate that the
original belongs to the same thread. `ChatMessageActionController` handles heart
reactions and own-message PATCH/DELETE requests, enforcing a ten-minute window;
deletions clear the body and preserve a history placeholder. Private reaction and
edit events update existing messages without incrementing unread counts. Shared
`ChatMessageBubble`, `ChatMessageText`, `ChatLinkCard` and `ActionMenu` components
provide swipe/long-press actions, safe linkification and standalone preview cards.
`ChatLinkPreviewController` authorizes each preview against its message; metadata
and raster images use bounded public-network HTTP fetches and application cache,
with no database storage. Preview loading is lazy and limited to two concurrent
frontend requests. Migrations `Version20261025140000` and `Version20261025150000`
add the reply, reaction and edit/deletion fields.
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
96/320/480px WebP variants at quality 82 via the authorized media controller; resources
include dimensions and `srcset` metadata without filesystem work. Thumbnail files
persist under `var/media/thumbnails/v2/` with locks and atomic writes. New uploads
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

The Tools register-all button resets each known task's next execution to one
current UTC timestamp plus its stored interval. Daily maintenance tasks share
an 86400-second interval; they do not use fixed UTC clock times. Task lists sort
by next execution ascending, and queue lists sort by message count descending.

Index projections are invalidated on source mutations and queried with source
fallbacks; source visibility/date checks remain authoritative. Media masters are
normalized synchronously; derivative creation, repair and backfills are queued.
Sitemap generation streams scalar batches to immutable split files and swaps the
index after success. Logs support numbered pagination using a stable snapshot
and cached timestamp/offset indexes, plus modal details. Legacy reverse cursor
paging remains available to API consumers.

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

The desktop header lazy-loads `MessagesPanel.vue` on demand using
`/api/me/inbox?limit=5&filter=all`. It groups the latest chats by local calendar
date, displays each thread's unread count, and refreshes from existing inbox/live
events without polling or read acknowledgements. Individual rows open explicit
conversation/inquiry links. “Show all messages” and direct inbox navigation clear
the selection immediately; only an explicit chat link opens message history.
The mobile notification panel uses a compact header with icon controls.

Migration `Version20261006210000` adds conversation activity and latest inquiry
message indexes. Normal GitHub deployment applies it; no environment, scheduler or
worker changes are needed. Validate against PostgreSQL. Further work remains on
public-directory cursors, admin catalog pagination and representative load testing.

### Companies directory design (2026-10-07)

`CompaniesView.vue` uses the shared `DirectoryHero`, `DirectoryToolbar`, `DirectorySearch`, and `DirectoryFilterPanel` components, with matching SCSS under `scss/components/shared/`. Its view SCSS provides the photographic art and four-column company card layout. Mobile uses single-column cards, horizontally scrollable filter controls, a visible sort control and a modal filter sheet with focus handling and background scroll locking. Grid/list selection applies to desktop. The directory uses the same `.page-width` container as home. The search toolbar sticks below the header on desktop and mobile, moving to the top when the mobile header hides on downward scroll. A bordered card and shadow highlight the sticky state. Industry and country multiselects live only in the sidebar/mobile sheet. Status and sorting use the shared `SingleSelect.vue` component with matching SCSS, keyboard navigation, no search field, and one selected value.

`GET /api/companies/filters` returns industry and country counts from visible, approved companies, using actual company industry values rather than creator categories. Industry labels respect company translations. The directory accepts JSON arrays in `industries` and `countries` (two-letter country codes), and binds both filters into its cursor scope. Country selections match any selected country; the legacy single `country` parameter remains supported. Filters use the existing industry, owner city/country, verified and featured fields; there are no new company-size or collaboration-type fields.

Card responses include a bounded plain-text `summary` and a responsive `coverImage` from an active campaign, fetched as a batch. Companies without a campaign image use decorative industry illustrations; missing descriptions and locations remain omitted. Images use the existing lazy-loading component and thumbnail endpoints. The static product hero is WebP. No migration or worker configuration is required for these changes.

Companies use searchable area multiselects backed by the shared admin-managed
`MarketplaceCategory` catalog (all six locales) and the indexed `company_industry` relation. The primary
`company.industry` remains for compatibility. Company covers are owned media in
`company-cover`; listing cards use cover thumbnails or the bundled
`/images/company-cover.webp` fallback. Creator profiles use account city; their
old separate location column is removed by `Version20261007014000`. Standalone
catalog creators retain a city fallback. Country filters are labelled “Država”.


### Creators directory design (2026-10-07)

`CreatorsView.vue` shares the companies hero, search toolbar, sticky card behavior,
and accessible mobile filter sheet. `CreatorCard.vue` remains the same photo-overlay
card on desktop and mobile: four columns on desktop and two on mobile. The desktop
list option changes the column layout without introducing another card component.
View styling lives in `scss/views/CreatorsView.scss`.

Filters use existing categories, account country/city (with the standalone creator
city fallback), social profile platforms, and follower counts. Categories, countries,
and platforms accept multiple selections; filters are combined across groups.
Only the currently supported TikTok, Instagram, and YouTube platforms are offered.
Audience bands use the largest channel: small is 1–9,999, medium 10,000–49,999,
and large 50,000+. Missing follower counts do not match an audience band.

The age filter reuses `RangeSlider.vue` with limits/defaults of 18–70 and one-year
steps, on both the main and country creator directories. The default full range
is neutral; narrowing it sends inclusive `ageMin`/`ageMax` bounds to the API.
Filtered results require a saved birthday, which remains private. Age is evaluated
against the current UTC date using birthday cutoffs (including leap-year handling),
before counting/pagination; both bounds are part of the signed cursor scope.

`GET /api/creators/filters` supplies country counts for visible, approved creators.
`GET /api/creators` accepts JSON arrays in `categories`, `countries`, and `platforms`,
plus `city`, `audience`, and `sort` (`newest`, `followers`, or `name`). Legacy single
`category`/`platform` parameters and the default alphabetical order remain supported.
The directory explicitly requests newest-first and loads 30 cards per cursor batch.
Filtering and sorting happen in PostgreSQL before bounded page hydration; follower
metrics are calculated only for audience filtering or follower sorting. Cursors
bind the filters and sort order, with creator ID as a stable tie breaker. Hidden and
unapproved profiles are excluded from results and country counts. This creator
redesign adds no entity fields, migration, environment settings, or worker requirements.

Creators without an uploaded/legacy avatar use `/images/creator-placeholder.webp`
in cards, the public profile, and the account profile summary. The supplied WebP
source lives in `frontend/public/images/`; Vite copies it into Symfony's public
directory during builds. `CREATOR_PLACEHOLDER` in `lib/marketplace.js` keeps this
presentation fallback separate from saved profile/media data.

The shared `CampaignCard.vue` uses `/images/share.webp` when no campaign cover is
present. This applies to campaign listings, the home slider, company profiles,
and saved campaigns through the same component. Uploaded cover images and their
responsive thumbnails retain priority; fallback images use `CardImage` lazy loading.


### Shared areas catalog (2026-10-07)

Creators, companies, and campaigns use one list called “Oblasti” (localized in all
six catalogs), managed on the existing admin catalog page. `MarketplaceAreaCatalog`
reads active `MarketplaceCategory` rows in admin-defined order. The legacy
`/api/marketplace/company-industries` endpoint remains as an alias for the same
list. Account/registration components reuse the categories response for both
profile types rather than requesting a separate industry catalog. Company filter
facets use these choices plus legacy values still used by visible companies;
existing custom selections are retained.

Migration `Version20261007020000` expands the starter catalog to 48 areas from the
frozen six-locale snapshot `migrations/data/marketplace-areas-20261007.json`. It only
inserts missing values, compares keys case-insensitively, and appends them after
existing positions. Existing labels, active flags, positions, and profile/campaign
selections remain intact. The migration can safely plan without a live schema,
is compatible with PostgreSQL, and is idempotent. Admins can add, rename, reorder,
or deactivate choices later. Deactivation removes a choice from new selections;
labels remain available for existing profiles. Public company, creator, campaign,
account, and home responses resolve labels from the shared catalog in batches.

Production must run Doctrine migrations as part of the normal deployment; clearing
cache alone will not insert the new areas. No environment or worker changes are
required. Locally, the additive catalog migration has been applied to PostgreSQL.

### Campaign directory design (2026-10-07)

`CampaignsView.vue` shares the creators' `DirectoryHero`, `DirectoryHeroCollage`,
`DirectoryToolbar`, and `DirectoryFilterPanel`. Its search card sticks on desktop
and mobile; mobile filters open in the same accessible bottom sheet. The heading
uses existing local campaign/product/content-production images. Campaign cards
retain their shared component, four desktop columns and two mobile columns, image
fallbacks, lazy loading, skeletons and 30-item cursor batches.

Campaign filters use existing `categories` (with the primary `category` fallback), `channels`, `city`, `countryCode`, `currency`,
`budgetMin` and `budgetMax` fields. Areas come from the shared admin catalog.
City and country filter the campaign’s own geography. Country choices come from visible, open campaigns through `/api/campaigns/filters`. The `countries` parameter accepts a JSON array of ISO country codes.
Budget ranges overlap the offered range and require a selected currency. Sorting
supports recommended (featured, then closing date), newest, and closing soon,
with stable ID tie-breaks. Signed cursors bind every filter and sort. The API
hydrates only the selected batch, retains the legacy single-category/company/
featured query parameters and excludes expired/closed campaigns and hidden or
unapproved companies. This page change adds no migration or server configuration;
it uses the normal backend/frontend deployment.

### Shared form controls and campaign geography (2026-10-07)

`v-form-validation` supplies required stars, red inline submit errors, and localized
fallback placeholders across SPA forms. Custom inputs expose validation metadata;
server validation and access checks remain authoritative. Keep placeholders and
labels at regular weight. `TagInput` commits chips with Enter; `DatePicker` wraps
Vue Datepicker with date-fns locales; `ImageUploadControl` shares upload placeholders.
`RichTextEditor` uses the basic Quill Snow toolbar. Campaign descriptions are
sanitized on the server and rendered through `RichTextContent`. Empty creator
headlines stay empty rather than falling back to HTML biographies.

Migration `Version20261007040000` adds the optional, private creator birthday;
`Version20261007050000` replaces campaign location with city/country_code and adds
JSON categories. Recognizable legacy country names are converted; other text is
preserved in city without inventing a target country. The primary category is the
fallback for existing campaigns. Company campaign creation/editing uses area
multiselect, country picker, city, rich description, and individual deliverables
with add/remove controls. Deploy both migrations before serving the new API.

Campaign budget filters use the shared dual-handle `RangeSlider`: 0–30,000, initially
0–10,000, disabled until currency is selected. Creator and company city search lives
only in the sticky toolbar. Companies use four desktop columns. Shared creator cards
show the existing featured flag on home and directory listings.

All admin table footers use `AdminPagination`; task/failed-job actions reuse
`AdminRowActions`. Logs retain cursor paging and can revisit already loaded page
cursors. Tools queues sort by message count descending, and scheduled tasks by
next execution ascending before pagination, with missing dates last and stable
name tie-breaks. Task badges distinguish scheduled/success (green), queued/overdue
(amber), running (blue), failed (red), and inactive/unregistered (gray).
The account sidebar hides directory/language controls on desktop; mobile
shows a full-width language picker and Creators. Profile, Messages, Notifications,
and the duplicate creator FAQs entry are omitted from sidebar navigation.

### Account activity tables and closed campaign chats (2026-10-07)

`CreatorCampaignActivity.vue` shares the table, status filters, search and numbered
pagination for offers, applications, direct inquiries and campaign invitations. It pages the already
loaded account records at ten items per page; the existing API endpoints and
ownership checks are unchanged. Chat actions target the exact application
conversation ID or accepted inquiry ID. Company inquiries show their creator
counterpart. Pending offers and creator inquiries retain accept/reject actions.

Campaign details open in a centered native dialog with cover, brand, sanitized
brief and campaign facts. It has no detail tabs or application submission action.
Inquiry dialogs use their own message and selected packages rather than campaign
fields. Mobile tables become stacked rows, with pagination and controls retained.

Company campaign lists use `CompanyCampaignList.vue` with cover images, campaign
facts, hiring progress, status filters, search and a shared three-dot edit menu.
`CompanyCampaignDetail.vue` wraps the brief in an account card and shows searchable
applicants without status filter buttons. Both tables use the same ten-item numbered
pagination and clamp their page when filtered/refreshed results shrink. Applicant
Chat links target the existing conversation ID; View opens the existing applicant
dialog. Campaign pages omit the profile summary; detail/edit pages include a back
link to the campaign list. Editing preserves the existing form and API, with card
radii, colors and typography matching the detail view. New copy is in all six locales.
Campaign create/edit channels use the shared required multiselect for Instagram,
TikTok and YouTube, preserving the channels array used by the API. Company inquiry
pages also omit the profile summary; creator activity pages omit it as well; the summary remains on the account profile page.

Invitation tables show
cover images, channels, company, areas, budget, deadline and hiring progress.
`/me/invitations` includes the full campaign resource and the accepted invitation's
conversation ID, scoped to its creator. Pending invitations to closed, finished or
past-deadline campaigns are shown as expired and cannot be answered. Accepted chats
remain accessible. The shared details dialog and three-dot menu retain access to
the brief. Saved campaigns use the existing cards in a four-column desktop grid,
two columns on tablets and one on mobile, inside the matching account card.

Closed campaigns disable chat only for participants without an accepted hire.
Hired creators/agencies and the client company continue chatting and reviewing work.
Finished/cancelled campaigns remain read-only. `CampaignWorkAction` shares the account
modals and rating form; see `docs/campaign-hiring.md` for individual submission, required
change requests, completion and the overall campaign finalization gate. Explicit campaign
`status=finished` or `status=cancelled` blocks chat; closing applications does not stop accepted hires. Passing the application deadline does not end an
existing collaboration. No database migration or additional configuration is needed.

### Finished campaigns and creator declines

Company campaign editing supports `open`, `closed`, and `finished`. Saving `finished`
rejects every unhired application and pending payment offer in the same flush, while
preserving accepted hires and offers. This automatic rejection creates no notifications
or emails. A closed campaign alone does not reject applicants. Closed/finished chat
history remains readable, but sending is disabled in both the UI and API. Finished
campaigns cannot shortlist, issue offers, accept offers or respond to invitations;
they can be finalized after their application deadline has passed.

A creator's explicit invitation decline, offer decline, or collaboration inquiry
rejection does notify the company. Inquiry rejection uses `creator_inquiry_rejected`
and has copy in all six locales. Company campaign events open its campaign management
page; inquiry events open the inquiry list. Conversation-linked notifications continue
to open the exact chat. Notification creation and frontend routing are covered by
`MarketplaceWorkflowTest`, `CreatorCollaborationTest`, and `notificationNavigation.test.js`.


### Hiring progress and notification preferences

Campaign responses include `hiredCount`, derived from accepted payment offers by
`CampaignHiredCounts`. List endpoints aggregate it in one query, and the same
progress component is used on cards, campaign details, account activity and chat.
Accepting an invitation alone does not count as a hire.

`User.notificationsEnabled` defaults to true; migration `Version20261007170000`
adds its column. PUT `/api/me/notifications/settings` requires a verified participant,
a CSRF token and a boolean `enabled`. Disabling it suppresses notification badges,
realtime notification delivery and device pushes (including already queued pushes).
Notification history remains stored; chat messages and email delivery are unchanged.
The bell loads five records at a time with a stable `before` ID cursor. Disabled
panels show informational examples, not fabricated notifications.

The account summary stacks matching visibility and notification switches. Header
account menus use the profile image/company logo, falling back to the Wave mark,
and show a desktop name plus profile, language and sign-out controls. Signed-in
language selection lives in this dropdown. Creator profile content and sidebar
are independent columns, with the biography below the decorative hero.

### Account deletion and shared history (2026-10-10)

`src/Account/AccountDeletion.php` is the single deletion policy used by admin bulk
profile/registration deletion and `DELETE /api/me/account`. Account settings expose
it through `AccountDeletionAction.vue` and the shared confirmation dialog in all
six locales. Operator accounts cannot use this flow.

Deletion anonymizes the minimal user/profile references instead of deleting their
foreign keys: shared campaign applications, offers, invitations and message bodies
remain for other participants. Company campaigns close; API resources return
localized deleted-account labels and `readOnly`. Message/offer/invitation/inquiry
writes are blocked for deleted participants. Closed campaign briefs are accessible
only to existing participants through the private history fallback.

Personal fields, OAuth identities, action tokens, subscriptions, bookmarks,
private support tickets and owned media are erased in one transaction. Queued
personal deliveries are discarded and directory projections invalidated. The
`StoredFileCleanup` journal records files before row removal; its response subscriber
runs after API commit, and `MediaMaintenanceTask` retries failures. Originals,
versioned thumbnails and complete private support directories are removed. See
`docs/deployment.md` for migration `Version20261010100000` and the manual retry
command. Do not replace this flow with `EntityManager::remove(User)` or cascades,
which would destroy other participants' history.

### Push account isolation

See `docs/push-account-isolation.md`. Logout detaches this device's push endpoint
before session invalidation; login rebinds existing endpoints. The worker checks
recipient ID against the uncached live session before displaying/clicking pushes.
Mismatched inbox/realtime responses refresh the app session. Ship backend and
frontend together and activate the updated service worker; no migration is needed.

### Campaign feedback and private totals (2026-10-13)

See [campaign reviews](campaign-reviews.md). `CampaignReviewController` and
`Reviews/CollaborationFeedback` implement two-way, 14-day double-blind reviews for
finished campaigns with accepted offers. Public averages and lists exclude hidden
feedback. Creator/company public profiles and cards show ratings; private account
summaries and admin catalogs show agreed totals grouped by currency. Completed
campaigns cannot reopen, and accepted offer currencies cannot change. Migration
`Version20261013100000` adds the completion timestamp and review table; it is applied
locally. Password changes use PUT `/api/me/password`, the existing CSRF/API client,
current-password verification and a dedicated rate limiter. No new server service
or worker configuration is required.

### Credit code assignment and review notifications (2026-10-13)

Admin voucher history now uses `AdminDataTable` with server pagination/sorting,
full encrypted codes, deletion and recipient assignment. `CreditRecipients` returns
signed cursor batches of 20 user IDs with name/email; `CreditRecipientSelect` provides
search and scroll loading. Assignment reserves a code for one account, sends the
editable `credit_code_assigned` email, and leaves balances untouched until redemption.
See [credits module](credits-module.md). Migration `Version20261013110000` is applied
locally; historic hashed-only codes remain valid, with full-code assignment unavailable.

Reviews notify only the other collaboration participant and open the exact review
form through `?review=<applicationId>`. Score/comment are absent from notifications.
`Version20261013120000` adds the notification application link and is applied locally.
Company campaign actions have confirmed Close and Finish operations using the
ownership-protected status endpoint. Applicant biographies use `RichTextContent`.
Validation: 278 backend tests (274,565 assertions), 283 frontend tests, PHPStan,
ESLint and production build passed. SQLite/PostgreSQL migration tests passed; local
UI fixtures verified assignment on mobile, status actions and formatted biography.

### Marketplace safety and review pagination (2026-10-13)

See [marketplace safety](marketplace-safety.md). `Safety/BlockPolicy`,
`BlockSubscriber`, `MarketplaceSafety` and `MarketplaceSafetyController` implement
two-level reports, private compressed screenshots, durable modal-only outcomes,
and personal blocks in either direction. The shared `AdminSafetyView` provides
personal review pairs (received and given feedback per collaboration), personal
blocks under Settings and admin-only reports. Admins see their own reviews and
initiated blocks, with no global review/block list. History remains anonymous/read-only.
Current block checks
also cover queued realtime, push and unread reminders. The SPA resets view content
on account/block changes. Migration `Version20261013130000` is applied locally.
Profile review cards use numbered pages of ten; the old review cursor remains valid.


Admin table footers show the matching dataset total, using server pagination totals
rather than page lengths. `AdminTableResults.vue` also distinguishes loaded rows
when a cursor API has no total. `AdminNavigationController` provides admin-only,
private counts for pending approvals, unresolved reports and non-resolved tickets.
`useAdminNavigationCounts` shares requests between sidebar instances, clears state
on account changes and refreshes after relevant successful mutations or window focus.
Reports use a label-free 36px status filter matching the table search field.


Campaign invitations appear alongside applications in company creator lists as
virtual invitation rows until an offer creates the actual application. Accepted
invitations permit chat hiring without another application. `CampaignOfferTimeline`
and `CampaignMessage.event` persist negotiated offer and hiring snapshots. Company
chat links open private campaign management; creators explicitly accept the amount
before hired counts increase. See [campaign hiring](campaign-hiring.md) and migration
`Version20261013150000`, which is applied locally.

Creator campaign navigation now combines invitations, offers and applications in
Moje prijave, one row per campaign. Legacy offer/invitation paths redirect there
while service inquiries remain separate. Current collaboration labels distinguish
Hired, Finished and Canceled. `CampaignCancellation` retains history, excludes
canceled amounts from private totals and makes the chat read-only. Either
participant may cancel accepted work before completion; only the owning company
can cancel the entire campaign. No extra migration is needed for cancellation.

### Account loading and admin overview

Account marketplace data loads per section; campaign lists no longer fetch every
campaign's applicants. Opening a brief loads bookmark state when needed. Simultaneous
session checks share an in-flight request without response caching and are invalidated
by authentication mutations. Company campaign participant ID lists use scalar queries.
`AdminOverviewAnalytics.vue`, `AdminOverviewController` and `Admin/OverviewStatistics`
provide 7/30/90-day aggregate charts and current admin work counts. Creator growth,
new campaigns, applications, invitations, confirmed hires, completions and campaign
status distributions are supported. Company signup dates are unavailable, so only
company totals are shown. See [admin overview](admin-overview.md).

### Personal overview and profile types (2026-10-13)

See [personal overview](personal-overview.md). The localized account-dashboard route
uses an owner-scoped aggregate endpoint, recent campaign activity, private completed
collaboration totals and published rating. Sign-in lands there; incomplete profiles
return to settings, and approval/verification gate marketplace account features.
Personal Pregled is the first sidebar item, separate from the admin platform overview.

Creator types include `streaming` and `podcasts`. Company types use the shared
`config/company_types.json` catalog (`brand`, `agency`), exposed as `companyType`
in profile/card responses. Password/social registration and the account editor
support it; omitting the field preserves the existing type. GET `/api/companies`
accepts a JSON `companyTypes` array, matching either selected type; the selection is
part of its signed cursor scope. Type facets honor visibility and personal blocks.
Migration `Version20261013170000` defaults existing companies to brand and is applied
locally. All copy and route titles are present in six locales.

## Account activation (October 2026)

See `docs/account-activation.md`: signup now collects identity/credentials first (eight-character password minimum); all existing profile fields remain in account setup. Incomplete accounts can use Pregled. New opt-in campaign digests use the existing scheduler/mail queue, and admin cohort analytics record real account milestones without backfilling old registrations. Migration `Version20261016120000` adds only two new tables.

## Public Meilisearch search

See [Meilisearch](meilisearch.md) for the server installer, environment keys,
local coexistence with Zeka, bootstrap/manual commands, 24-hour batched queue task,
security invalidation fence and integration tests. `PublicSearch` serves global
suggestions (10 per category, exact totals) and the localized `SearchView` (25 per
page, including All); SQL directory filtering remains for normal directory pages.
New search and legacy directory `?q=` URLs are noindex/follow. MEILISEARCH_ENABLED=false uses database search by default; true selects the indexed
backend after service, scoped keys, migration and initial import are configured.

Direct inquiry work uses the same `CollaborationWork` entity trait and
`CampaignWorkAction` as campaign hires (`InquiryHiringController::work`). Providers must
submit before client review. Campaign discussion reopening lives in
`CampaignDiscussionController` and `DiscussionReopeningAction`: explicit provider
consent restores discussion, never the cancelled hire. See `docs/campaign-hiring.md`.
Migrations `20261019010000` and `20261019020000` must run before serving these changes.

Meta browser tracking is in `frontend/src/lib/metaTracking.js`, called by the existing
SEO page-view path and successful `AccountAccessPanel` signup responses. Cookie consent
v3 keeps Marketing independent of Analytics and never upgrades old analytics consent to
marketing. Pixel code is host-gated and disabled by default without explicit Vite config.
See `docs/meta-tracking.md` for the Wave Pixel ID, server build settings and validation.
No CAPI token or server forwarding is part of this integration.

### Email attempt history

`src/Mail/MailLog.php` writes JSON audit rows and encrypted rendered-content snapshots to files under `var/log`; there is no database log table. `LoggedMailTransport` decorates `mailer.transports`, covering synchronous and queued sends; `QueuedMailer` captures content before enqueue and `JobExecutor` logs obsolete-token/reminder skips. Tools → Logs selects daily `*.mail-*.log` files and opens private HTML/text previews through the existing admin authorization. The shared `EmailPreviewFrame` blocks active content. See [email logs](email-logs.md) for retention and deployment.
