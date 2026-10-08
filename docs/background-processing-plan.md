# Background processing and Tools implementation plan

Reviewed on 2026-10-06 against Wave source and the local FroshTools plugin at
`/Users/suadasceric/Projects/shopware67/custom/plugins/FroshTools`.
This document records the original audit and roadmap. The queue/task engine,
delivery, media, sitemap, directory projections, Tools controls, structured logging
and development worker are now implemented locally; see [server operations](server-operations.md)
for the current architecture and deployment steps. Sections below describing
"current" behavior refer to the pre-implementation audit. No production services
or data were changed. Existing server setup remains in [server operations](server-operations.md).

## Implementation status

The implemented catalog contains 16 message types, eight persistent scheduled tasks,
four processing transports and one failure transport. Every catalog entry has a
handler; zero-count rows are registered types rather than missing implementations.
Admin Tools supports registration, manual dispatch, schedule/inactive changes,
queue counts, targeted failed-job retry/discard and selected-file log details.
Development uses the admin worker; production uses supervised CLI consumers.

Delivery intent is transactional for API mutations. Mail, per-device push and
Mercure publication have durable retries. Media maintenance, thumbnails, directory
projection reconciliation, sitemap generation, reminders and cleanup use bounded
jobs. Listings request small card resources and use projection/source fallback;
chat sidebar unread counts are grouped rather than queried per conversation.

Public directories now use signed 30-item cursor batches, and the four large admin
catalog tables use server pagination/search/sort. The chat inbox uses merged
30-item cursor batches. PHPStan and ESLint are configured and passing. A local
synthetic PostgreSQL/API/chat/Messenger baseline is recorded in
`docs/performance-baseline.md`; it does not establish thousands-of-users capacity.
Trigram/GIN search tuning, production concurrency and provider/device smoke tests
remain separate measurements/deployment checks.

## 1. What exists, and what the screenshots show

Wave currently has **zero Messenger transports, zero queued message handlers,
and no persistent scheduler registry**. Composer does not require Messenger or
Scheduler. Email, Web Push, Mercure publication and thumbnail creation run in
the calling request/command. The previous Tools implementation inspects optional
transports and observes two console commands; it is not a queue engine.

Current recurring work is the campaign unread-reminder command and the recommended
cache-pruning command. Thumbnail backfill is already a bounded, resumable command,
but is not a scheduled task or Messenger job. Admin/moderator assignment and demo
seeding are administrative commands, not recurring jobs to register.

FroshTools has two distinct queue views in one table:

- `GenerateThumbnailsMessage` and similar rows are **message-type statistics**
  supplied by Shopware's increment gateway.
- `messenger.transport.async`, `low_priority` and `failed` are **transport counts**
  supplied by Messenger receivers.

Its `QueueController` combines these sources. These rows overlap: they must not
be added together as a total. Its reset action truncates the transport table.
Wave should provide targeted failed-job retry/discard actions rather than a
general reset that silently removes pending customer deliveries.

FroshTools `ScheduledTaskController` uses Shopware's task registry, database
entities and runner. Those facilities do not come with the plugin and cannot
be copied into Wave without implementing their equivalents.

Its log component selects a file, shows Date/Channel/Level/Message, and opens
the selected entry in a modal. Wave can reproduce that interaction while retaining
bounded cursor pagination. Log contents must render as text, including in the
modal; the plugin's `v-html` detail rendering is unsuitable for untrusted logs.

## 2. Audit findings and priorities

| Area and source | Current behavior | Planned change | Priority |
| --- | --- | --- | --- |
| `src/Account/AccountEmailSender.php`; `ContactController.php` | SMTP/provider delivery blocks the caller; contact mail bypasses the account sender | One durable mail delivery service and retryable message for all paths | First |
| `AuthController.php`; `OAuthController.php` | Account/token persistence and mail delivery are separate steps | Commit token/account change and delivery intent together; preserve enumeration protection and throttles | First |
| `HomepageController.php` approval actions | Email is sent before marking approved; bulk approval sends mail in a loop | Atomic approval plus queued delivery; API reports accepted/queued, not delivered | First |
| `MarketplaceWorkflowController.php`; `CreatorInquiryController.php`; `CampaignInvitationController.php` | Business data is flushed before external deliveries; a later failure may leave committed work and a failed request | Transactional delivery intent, idempotent business commands, bounded bulk actions | First |
| `NotificationDelivery.php`; `WebPushNotificationSender.php` | Push fans out sequentially to every subscription; failures are logged without application retries | Independent per-device deliveries, bounded retry, expired-subscription removal | First |
| `RealtimeUpdatePublisher.php`; `ChatReadReceipt.php`; inquiry message publication | Live publication is synchronous; hub exceptions are logged and swallowed | Dedicated fast delivery queue, durable intent and stable event identifiers | First |
| `CreatorInquiryController.php::sendMessage` | Publishes an inquiry chat event to Mercure only; no Web Push call for this message path | Add the same per-device background delivery path and inquiry deep link as campaign chat | First |
| `SendUnreadMessageRemindersCommand.php` | Hydrates all overdue unread campaign messages, then groups/skips in PHP; counts and flushes per reminder | Select eligible recipient/thread pairs in SQL, claim bounded batches, enqueue one delivery per reminder generation | First |
| `MediaStorage.php`; `MediaThumbnails.php` | Upload normalizes a master and generates 96/320/480px variants; a missing variant is generated in an image GET | Keep validated 600px master creation at upload, queue derivatives; GET stays bounded | Second |
| `GenerateMediaThumbnailsCommand.php` | Keyset batches and ORM clearing already exist; failures only reach console output | Reuse cursor logic as background rebuild orchestration, record progress and failed items | Second |
| `SitemapGenerator.php`; `SeoController.php` | Each uncached sitemap request hydrates all public entities and builds one string with six locale URLs per entity | Batch generation into versioned files and a sitemap index; serve last completed generation | Second |
| `UserActionTokenManager.php` | Token issuance deletes all expired tokens during a request; expiry has no dedicated mapped index | Hourly bounded cleanup with an expiry index; retain immediate per-user token revocation | Second |
| `CreatorController.php` | JSON tags/platform text scans load all matching IDs; directory serialization includes detail fields and portfolio associations | PostgreSQL search/filter projection, card-specific response, useful indexes and cursor paging | Third |
| `CompanyController.php` | Correlated available-campaign counts participate in ranking on requests | Maintain a derived active-campaign count, indexed ranking, expiry reconciliation | Third |
| `CampaignController.php` | Multiple leading-wildcard text filters; full detail serializer for cards | Search projection and small card responses; retain authoritative date/visibility checks | Third |
| `CampaignMessagingController.php`; inquiry lists | Campaign sidebar loads all conversations and counts unread per conversation | Cursor-page threads and grouped unread counts; query work stays synchronous | Third |
| `HomepageController.php` admin dashboard and catalog updates | Loads entire catalogs/registrations; some updates loop through all catalog entities | Separate paginated admin APIs and bounded bulk database operations | Third |
| `monolog.yaml` | Rotated text logs; production main logger starts at WARNING, so routine successful operations are absent | Structured operational INFO channels, error logs, request correlation and retention | First, with foundation |

This is a static audit, not a production load test. Batch sizes, timings and worker
counts below are starting settings to validate, not measured capacity guarantees.

## 3. Proposed architecture

Keep the Symfony JSON API and Vue SPA in one deployment and keep PostgreSQL as
the durable queue/task store. Add version-compatible Symfony Messenger and
Doctrine transport packages; use migrations with transport auto-setup disabled
in production. Add a deliberate serializer with stable, versioned message
schemas and queryable message-type metadata. Do not deserialize arbitrary
PHP objects in the admin queue reader.

PostgreSQL Doctrine transport supports LISTEN/NOTIFY. Separate queue names are
required for the proposed transports; consumers must handle delayed retries
and abandoned deliveries correctly. See [Symfony Messenger](https://symfony.com/doc/current/messenger.html#doctrine-transport).
Redis, RabbitMQ, Elasticsearch and a separate frontend server are not prerequisites.

### Delivery integrity

Use a transactional outbox/delivery record committed with the domain write.
The queued message references its stable job/delivery ID. Dispatch and claim
operations are transactional, use a uniqueness key, and can be recovered after
a process crash. A database write followed by a best-effort post-flush dispatch
does not close the gap between saving an action and sending its event.

Keep payloads small: IDs, versions, cursors and immutable event identifiers.
Store email content and exact push message references in protected delivery
records, not operational logs. Every handler checks for deleted/revoked entities
and validates whether the requested side effect is still relevant.

The initial cutover must explicitly configure Mailer bus behavior. Installing
Messenger changes Mailer integration; a custom mail handler must use the actual
transport, not accidentally call a Mailer that enqueues the same email again.
There must be one delivery path, not a wrapper queue plus an unintended second
queue. [Symfony Mailer async integration](https://symfony.com/doc/current/mailer.html#sending-messages-async)
documents the built-in `SendEmailMessage` alternative.

Verification/reset delivery retains its original token generation and expiration.
Before sending a delayed/retried email, check that its token is still valid and
has not been superseded or consumed. Do not generate a fresh token on every retry.
Expired security emails are skipped with a safe reason, and token-bearing payloads
are removed when their usefulness ends. Contact submissions need their own durable
protected delivery record because that endpoint currently saves no domain entity.

### Transport inventory (proposed)

| Transport | Work | Initial consumer arrangement |
| --- | --- | --- |
| `realtime` | Mercure events for chat, notifications and seen acknowledgements | One dedicated consumer; no mail, image or bulk work |
| `mail` | Account, contact, marketplace and reminder emails | One consumer, provider timeout and delivery rate limit |
| `push` | Device push notifications | One consumer, independent of realtime and mail |
| `background` | Thumbnails, media/search indexing, sitemap and maintenance | One consumer initially; bounded jobs, split CPU-heavy work if queue age warrants it |
| `failed` | Exhausted or permanent failures requiring inspection | No automatic consumer; targeted retry after resolving the cause |

Four running consumers are an initial design, not a requirement to allocate
four CPU cores. Check server RAM, CPU, PostgreSQL connections and traffic before
deployment. Fewer consumers can be used with an explicit latency tradeoff;
image jobs must not share the realtime consumer. No consumer per message class.

### Development admin worker (proposed, Shopware-style)

The requested development behavior is confirmed by the local Shopware source:
`Framework/Resources/config/packages/shopware.yaml` enables
`shopware.admin_worker.enable_admin_worker` by default. Its JavaScript admin worker
calls server consume/scheduled-task endpoints; PHP executes the actual handlers.
The local implementation uses a SharedWorker with a Worker fallback, transport
locks, worker time/memory limits and a delay after an empty queue response.
The [Shopware hosting guide](https://developer.shopware.com/docs/guides/hosting/infrastructure/message-queue.html)
recommends turning this off when supervised CLI consumers are installed.

Wave will support the same convenience **by default in development**, with
production and tests disabled by default. Proposed Symfony parameter configuration
(not a file to install before its services exist):

```yaml
# config/packages/wave_workers.yaml — proposed
parameters:
    wave.admin_worker.enabled: false
    wave.admin_worker.time_limit: 2
    wave.admin_worker.message_limit: 3
    wave.admin_worker.memory_limit: '128M'
    wave.admin_worker.idle_interval_ms: 2000
    wave.admin_worker.transports: ['realtime', 'mail', 'push', 'background']

when@dev:
    parameters:
        wave.admin_worker.enabled: true
```

These are Wave parameters, not Shopware's `shopware:` extension configuration.
Use Symfony environment overrides rather than a production flag that silently
inherits a development default. Independently require `kernel.environment=dev`
on the HTTP processing endpoints; enabling a parameter alone cannot turn this
development executor on in production.

Implementation requirements:

- Start only for an authenticated `ROLE_ADMIN` while the admin area is open.
  Backend configuration controls whether it starts. Stop scheduling requests on
  logout, role loss, last admin-tab closure or a disabled configuration. Display
  development worker mode/last activity and backlog in Tools. Without an open
  admin window or a local CLI consumer, development jobs remain queued.
- Use fixed POST endpoints with the existing session authentication and CSRF
  helper: one bounded consume action and one bounded due-task dispatch action.
  Take no arbitrary command, class or transport names from the browser. Exclude
  the `failed` transport from automatic processing.
- Share the same Messenger bus, serializer, handlers, delivery records, retry/
  failure listeners and scheduler claim service as CLI processing. Do not create
  a second simplified executor that loses acknowledgements, retries or audit hooks.
- Coordinate browser tabs with SharedWorker or equivalent leader coordination,
  with a supported fallback. Add a server-side global dev-consumer lock in `finally`
  cleanup and the normal atomic message claims; client coordination alone cannot
  prevent multiple admins from starting overlapping HTTP consumers.
- Release the PHP session lock after authentication/CSRF checks and before running
  handlers. Process a small overall batch, then return handled/queued counts and
  a suggested next delay. Return promptly on an empty queue; do not wait for
  PostgreSQL NOTIFY within a development HTTP request. Back off on empty queues,
  lock contention and errors; never issue overlapping consume requests per leader.
- Dispatch due tasks through the same persistent registry, approximately every
  30 seconds while active, independently of whether queue batches contained work.
  Redispatch coalesces missed occurrences rather than replaying every missed tick.
  Failed exhausted jobs remain visible for explicit retry.
- Time/message/memory limits stop between handlers; they cannot interrupt an
  image encode or slow provider call already running. Keep handler/provider work
  bounded. The documented `php -S` server serializes requests, so copying Shopware's
  20-second HTTP consumption window would delay local chat/login requests. Start
  with a 2-second/3-message soft budget, shorten on contention and prefer local CLI
  consumption for large backfills or latency testing.
- Browser polling here drives development server jobs, not inbox state. Chat and
  notification UI continue receiving Mercure events with no periodic inbox polling.
  Consumer mode never changes private topics, message visibility or PWA behavior.

Local developers can disable `wave.admin_worker.enabled` and run the proposed
CLI consumers/dispatcher instead. Avoid browser and CLI processing simultaneously
when comparing local performance; ordinary durable claims still protect against
accidental overlap. Production processing is independent of admin browser sessions:
systemd **or** Supervisor supervises CLI consumers, and a timer/managed scheduler
dispatches recurring tasks. Install one supervisor arrangement, not both.

Development tests must prove environment rejection in production, anonymous/
moderator/CSRF rejection, no overlapping tab consumers, session-lock release,
idle backoff, stop-on-logout and identical retry/failure semantics to CLI workers.

### Retry and idempotency policy

- Begin with five transient retries, exponential delay from 10 seconds up to
  15 minutes and jitter; respect provider Retry-After when available. Tune per
  handler. A permanently malformed payload or missing source is not a transient
  failure. Exhausted jobs go to `failed`, with a visible reason.
- Use provider timeouts and ensure redelivery timeout exceeds the longest bounded
  handler duration. Test worker termination both before and after the side effect.
- SMTP success means provider acceptance, not inbox delivery. SMTP cannot offer
  exactly-once delivery if a worker dies after acceptance and before recording
  success. Deduplication limits routine duplicates but cannot remove that crash
  window; use provider idempotency where supported.
- Push retries operate per subscription. A failure on one device must not resend
  successful deliveries to all other devices. Remove confirmed expired endpoints;
  do not repeatedly retry them. Freeze the exact triggering chat message instead
  of the current sender's latest message, which the sender currently queries.
- Preserve event ordering per thread/recipient where it matters. Stable IDs and
  message IDs let the client ignore duplicates/older previews. Fresh badge totals
  and event sequence numbers prevent a delayed push from restoring an old count.
- Worker failures must throw retryable exceptions; logging and returning normally
  would acknowledge a failed delivery.
- Bound the enqueue rate, coalesce repeated reindex/sitemap requests, and alert
  on backlog age. A queue moves work out of PHP requests; it does not make CPU,
  disk or database costs disappear.

## 4. Proposed message catalog

These are real implementations to build, not fake zero-count rows to add now.
Names ending in Task below describe scheduled work dispatched through Messenger.

| Message/type | Trigger and purpose | Transport and initial bound |
| --- | --- | --- |
| `SendEmailMessage` | Durable delivery ID; verification, reset, registration, approval, contact, application, inquiry, hired and unread-reminder mail | `mail`; one delivery |
| `SendWebPushMessage` | Notification/message ID plus subscription/delivery ID; exact content, per-device retry | `push`; one device |
| `PublishRealtimeMessage` | Persisted private event for chat/notification/read acknowledgement | `realtime`; one event/recipient |
| `UnreadMessageReminderTask` | Find due recipient/thread pairs without hydrating a backlog | `background`; 100 pairs, cursor/continuation |
| `GenerateThumbnailsMessage` | New upload, missing variant or explicit rebuild; existing versioned atomic files/locks reused | `background`; one media, three supported widths |
| `MediaIndexingMessage` | Index dimensions/MIME/size and derivative state for legacy media or changed variant version | `background`; up to 50 media IDs, then enqueue per-media generation |
| `CreatorIndexingMessage` | Build normalized tags/platforms/categories, searchable locale text and minimal public card data | `background`; 100 IDs, version checks |
| `CampaignIndexingMessage` | Search/card projection; changes invalidate related company statistics and sitemap | `background`; 100 IDs, version checks |
| `CompanyIndexingMessage` | Search/card projection and active campaign count/ranking; triggered by company/campaign/visibility changes | `background`; 100 IDs, version checks |
| `SitemapGenerateTask` | Start/coalesce a build; scalar queries and continuation jobs write split locale-aware XML files | `background`; 500 source rows per batch |
| `LogCleanupTask` | Enforce application-log retention even if no new log is written; prune old completed run history | `background`; 100 eligible files/500 completed history rows |
| `CachePruneTask` | Daily pruning of expired cache entries including log snapshot cursors | `background`; use existing prune API; measure scan time, separate pools if it exceeds job budget |
| `ExpiredTokenCleanupTask` | Remove expired action tokens outside account requests | `background`; 500 rows per delete/continuation |
| `MediaMaintenanceTask` | Discover stale temporary thumbnail files, missing derivatives and filesystem/database inconsistencies | `background`; 100 media entries or files, persistent cursor |
| `IndexReconcileTask` | Repair stale/missing projections after missed changes or a deployment; not a nightly full rewrite | `background`; 500 source records per invocation/continuation |
| `QueueMaintenanceTask` | Recover abandoned outbox claims and prune terminal successful delivery metadata | `background`; 500 rows; never silently remove pending/failed work |

`SendEmailMessage` is the proposed application delivery wrapper (ID-only), unless
implementation chooses Symfony's built-in class with an equivalent protected
outbox/delivery ledger. The admin catalog must display the actual chosen class,
not list both as if there were two independent email queues.

Media maintenance initially reports unreferenced master files instead of deleting
them. Uploaded-but-not-yet-attached images can be legitimate. Any future orphan
deletion needs a grace period, complete reference checks and a deliberate policy.
Do not invent jobs that delete chats, notifications, accounts or business records
without a product retention rule. Missing subscription age/last-success metadata
also means age alone is not currently evidence that a push subscription is invalid.

## 5. Proposed scheduler registry and admin controls

A persistent registry is justified by the requested editable statuses and manual
execution. Symfony Scheduler alone supplies recurrence; it does not automatically
provide a Shopware-style admin task registry. Avoid maintaining two competing
schedule sources.

Use a small PostgreSQL registry of explicitly tagged/allowlisted task definitions
and a CLI dispatcher, with Messenger doing the work. Reuse/migrate the existing
`scheduled_task_state` observations as execution history; do not misinterpret
previous command monitoring as a registered schedule.

Store task name/type, interval/UTC schedule, enabled state, next due time,
last queued/start/finish times, run ID, lease and last outcome. Keep runs in a
separate bounded history table with duration, retry count and sanitized error.
Index due enabled tasks. Unique active run/occurrence keys and transactional
claims (`FOR UPDATE SKIP LOCKED` where appropriate) prevent duplicate dispatch.

Run `app:scheduled-tasks:dispatch --once` from a systemd timer every 30 seconds.
It claims only due tasks, commits delivery intent and returns; it does not run
heavy handlers. Dispatch outages create a visible overdue state, not a false
successful next-run display. Recover stale claims conservatively; a lease expiry
alone does not prove an external side effect never occurred.

| Registered task | Initial recurrence | Notes |
| --- | --- | --- |
| `UnreadMessageReminderTask` | Hourly | Messages qualify after one hour unread; delivery can wait until the next hourly check. Direct inquiries remain an explicitly separate feature |
| `SitemapGenerateTask` | Hourly fallback, plus coalesced content-change trigger | Rebuild only when dirty or expiry affects visibility |
| `ExpiredTokenCleanupTask` | Hourly | Add expiry index and bounded deletes |
| `QueueMaintenanceTask` | Every 15 minutes | Claim recovery and terminal metadata retention |
| `LogCleanupTask` | Every 24 hours | Existing 14-day log setting; active files excluded |
| `CachePruneTask` | Every 24 hours | Replaces the direct prune timer when migrated |
| `MediaMaintenanceTask` | Every 24 hours | Incremental/reporting; no automatic master deletion |
| `IndexReconcileTask` | Every 24 hours | Incremental sweep; event-driven indexing remains primary |

Creator/campaign/company indexing and thumbnail generation are event-triggered
messages, not full rebuilds scheduled every minute. Full rebuild is a separate
explicit action with progress and a resumable cursor.

Tasks tab requirements:

- Name, interval, last execution, persisted next execution, state, last outcome.
- **Register all:** idempotently discover known task definitions and add missing
  records. Preserve existing inactive status, custom interval and next run.
- **Start manually:** enqueue a task run and return 202; never execute image,
  email or cleanup work within the admin HTTP request. Refuse duplicate active runs.
- **Scheduled:** enable normal recurrence. **Scheduled immediately:** enable and
  set next due to now. **Inactive:** prevent future dispatch; do not pretend this
  cancels a running job. Explain each action in the interface.
- Typical run lifecycle: scheduled -> queued -> running -> scheduled; exhausted
  failure records a failed outcome and exposes retry/re-schedule. Admin disabling
  a running task stays inactive after that run finishes. Record schedule state
  separately from last result so successful history does not re-enable a task.
- Every mutation uses `ROLE_ADMIN`, existing CSRF protection, fixed task IDs,
  validated values and audit context. No shell strings, arbitrary PHP class names
  or service control supplied by the browser.

At cutover remove the old reminder cron and direct cache-prune timer **after**
the registry, dispatcher and workers are enabled and checked. Keep one schedule
owner for each job to prevent duplicate execution.

## 6. Queue tab: message types and actual queue size

Show a message-type table with **Name / Size**, matching the screenshot, and a
transport table with the actual queue names. Show registered implemented message
types even at zero, plus unknown types if actually present. Mark future/unimplemented
types as part of the plan only, not registered queue entries.

Define counts explicitly: pending total includes ready/delayed/in-flight work;
failed entries have a separate total. Expose a breakdown and oldest pending age
without requiring users to open payloads. Do not add type counts and transport
counts together. A zero queue does not prove a worker is healthy.

Use grouped PostgreSQL queries over transport rows and stable serialized type
metadata, with suitable indexes; no payload deserialization or per-class table
scan. Verify the chosen serializer headers/type expression before creating its
index. Avoid maintaining only increment/decrement counters, which can drift after
crashes/retries. Cache aggregate counts briefly if measured backlog sizes make
manual refresh expensive. Include last snapshot time.

Add worker identity, queues consumed, last heartbeat, memory and last handled
time. Heartbeats should be coalesced (for example once per 30 seconds), not a
database write for every idle loop. Show stale/unknown honestly. Failed-job detail
shows a redacted error and metadata; sensitive email body/token/endpoint material
is not part of the Tools response. Retry/discard actions are targeted and audited.

## 7. Real indexing, pagination and caching

An indexer needs a query/read-model consumer. Adding a class named CreatorIndexing
without changing expensive listing queries does not improve performance.

First reduce listing responses to the fields used by cards. Creator cards use
social profiles and package prices, so retain a small summary rather than blindly
removing those fields; omit full FAQ/portfolio and long descriptions. Campaign
cards similarly need channels/budget/date but not full detail descriptions.
Keep profile/detail responses unchanged. Remove extra hydration before benchmarking.

Build PostgreSQL-backed projections for normalized creator tags/platforms,
locale-aware searchable content and company availability statistics. Consider
indexed trigram matching to preserve current substring behavior, and full-text
search only when its token/ranking semantics fit the product. Account for short
queries and the six supported locales. No Elasticsearch deployment is needed for
this first step. See [PostgreSQL text search](https://www.postgresql.org/docs/current/textsearch-intro.html)
and [trigram indexes](https://www.postgresql.org/docs/current/pgtrgm.html).

Queue indexing on profile/admin edits, approval/visibility changes, campaign
creation/edits and media changes. Coalesce by aggregate ID/version; a delayed older
  job cannot overwrite a newer projection. Reindex related campaigns/company counts
when an owner changes visibility. Preserve authoritative approval/hidden/date
conditions in reads: stale projections or cached lists must not expose hidden
accounts, private media or expired campaigns.

Use stable keyset cursors for 30-item directory batches, including all ordering
fields and the final ID. Company availability is mutable, so use a ranking snapshot
version when necessary. Avoid recomputing exact totals for every scroll; obtain
the first total once or cache it, and use limit+1 to determine further pages.
Validate API compatibility with the existing `useInfiniteDirectory` composable.

Public cache keys include locale, normalized filters, cursor/ranking version and
visibility/content generation. Use bounded TTLs, stampede protection and explicit
invalidation. Private APIs remain unshared. Visibility changes synchronously
invalidate relevant public generations before a queued reindex completes.
Do not increase media cache lifetime without preserving visibility revocation
semantics; current image requests authorize before conditional responses.

Campaign expiry is already enforced by request-time date checks. An expiry job
is useful for derived counts and sitemap invalidation, not necessary to hide a
finished campaign. Date-driven index reconciliation must respect the app's business
timezone independently of the UTC timer clock.

Cursor-page conversations and batch unread counts for the visible page instead of
one query per thread. Keep message writes, read acknowledgements and ownership
checks synchronous; delivery of their Mercure events can be asynchronous.
The existing 50-message history cursor and indexes should be preserved.

## 8. Media and sitemap implementation details

Preserve upload validation, EXIF orientation, proportional lossless WebP masters
at max 600px, alpha and no cropping/upscaling. Async derivatives do not justify
returning an unvalidated raw upload. Return dimensions and a derivative-pending
state; placeholders keep card space stable. The simplest first version keeps
master normalization synchronous and queues only derivatives.

Reuse per-media locks, versioned directories and temp-file atomic rename. GET
requests authorize, return an available master/placeholder when derivatives are
pending, and coalesce a generation request; they should not decode images and
block on an exclusive generation lock. Delete obsolete jobs harmlessly when
media is removed. Compensate written masters if database persistence fails.
Backfill stores an upper ID bound and persistent cursor, so concurrent new uploads
do not extend the rebuild forever. Retry failed items separately from progress.

Generate sitemaps with scalar/keyset queries and streaming writes. Group into
files with at most 50,000 URLs and 50 MB uncompressed, respecting the
[sitemap protocol](https://www.sitemaps.org/protocol.html). Six localized URLs per
entity count as six URLs; alternate links increase byte size too. Build in a
temporary versioned directory and switch the completed index atomically. The
index and files must reference the same generation; never expose half a build.
Expose last generation time, dirty state and errors in Tools. Preserve existing
hidden/unapproved/expired filters, localized alternates and public route behavior.

## 9. Structured logs and the requested viewer

Provide dedicated channels: `delivery`, `messenger`, `scheduler`, `media`,
`indexing`, `seo`, and `audit`, alongside general application/errors/deprecations.
Record operational lifecycle events at INFO, retryable problems at WARNING and
terminal failures at ERROR. Production DEBUG/request/SQL tracing is temporary,
sampled and explicitly configured; permanently logging every SQL query/request
would undermine the optimization goal.

Each entry has timestamp, level, channel, event code, short message, request/job/run
correlation IDs, relevant entity IDs, attempt, outcome, duration and bounded safe
context. Worker lifecycle events use the same correlation IDs as the originating
request. Success logs occur after provider acceptance/atomic completion, not merely
after enqueue. Cleanups log counts and a summary, not thousands of per-row lines.
Retry/final-failure hooks and domain summaries must avoid duplicate spam.

Use single-line JSON log records so context and multiline exceptions remain one
logical row. Parse JSON and legacy Monolog text in the reader. Redact before writing
and again before the admin response. Never record passwords, authorization/cookies,
JWTs, reset/verification tokens, private keys, full email bodies, chat bodies,
push endpoint credentials, or complete request/SQL payloads. Log identifiers and
safe error codes instead. Protected delivery records are distinct from logs.

Viewer requirements:

- File selector and refresh toolbar; initially no log loaded until selection,
  matching the requested screenshots. If an All logs option remains useful,
  make it an explicit selection rather than fetching all by default.
- Date, Channel, Level and **one-line ellipsized Message** columns. Click/keyboard
  activation opens a responsive modal with complete available redacted record,
  context and exception. Reuse accessible dialog patterns, focus trapping,
  Escape close and return focus.
- 25/50/100 rows, numbered pagination with direct last-page access, stable snapshots
  and rotation/expiry notices. A compact cached timestamp/byte-offset index computes
  totals once per file version and extends on append. Page requests read only the
  selected bounded records; last-page jumps traverse the index from the oldest end.
  Full detail is separately bounded; label truncation rather than claiming an 8KB
  preview is the entire original exception. The older cursor API remains compatible.
- Keep current path allowlisting, symlink rejection, `ROLE_ADMIN`, private/no-store
  responses and safe text rendering. Compressed host archives and journald remain
  separate unless a dedicated access-limited reader is built.

Retention starts with existing `LOG_RETENTION_DAYS=14`, including dedicated channels.
Only known old application files are eligible; never delete the active day's files,
arbitrary server paths or journals. Retain completed job summaries for a proposed
30 days; failed work stays actionable with sensitive payload expiry governed by
its purpose. Log cleanup and host logrotate must have compatible ownership rules.
Measure disk growth and add size limits/alerts; do not assume 14 days bounds bytes.

## 10. Delivery phases and acceptance checks

1. **Foundation and Tools interaction:** dependency/config migrations, outbox/jobs,
   task registry/dispatcher, typed queue counts, worker heartbeat and structured
   logging, including the development-only admin worker and production CLI mode.
   Update the three existing tabs, task actions and log modal. Deliver a
   reusable job catalog and explicit phase availability, not placeholder counts.
2. **Reliable delivery:** migrate every account/contact/marketplace mail path,
   per-device push and dedicated realtime publication. Bound reminder selection.
   Change approval/contact UI wording for asynchronous acceptance in all six locales.
3. **Media and SEO:** queued thumbnails and resumable backfill, master fallback,
   compensation, incremental media maintenance, split generated sitemaps.
4. **Listings and large accounts:** slim card resources, normalized search/read
   projections, measured indexes, 30-item keyset loading, cached generations,
   conversation pagination and paginated admin catalogs.
5. **Production cutover:** service units, schedule registration, worker deploy
   reloads, remove replaced cron/timers, backfill gradually, load/failure testing
   and tuning. Enable each asynchronous flow only once consumers are healthy.

Required tests use **isolated PostgreSQL**, never the account database or a SQLite
substitute: rollback produces no delivery; worker crashes recover; duplicate jobs
do not repeat completed local work; concurrent scheduler/manual runs do not overlap;
inactive tasks stay inactive; register preserves settings; transient failures retry;
permanent failures are visible; counts remain correct across delay/retry/failure;
admin/moderator/CSRF boundaries hold. Verify SMTP's unavoidable acknowledgement
window with a fake provider, not real account emails.

Media tests cover alpha/aspect ratio, pending GET behavior, delete-during-job,
variant versions and regeneration. Index/cache tests cover hidden/unapproved owners,
campaign midnight expiry, stale jobs, locale/filter parity, mutation invalidation
and cursor stability. Sitemap tests cover splitting and atomic generation. Log
tests cover JSON/legacy entries, secrets, malformed data, rotation and bounded
details. Frontend tests cover accessible menus/modal and async feedback in six locales.

Benchmark with representative synthetic datasets (for example 10,000 creators,
companies/campaigns in realistic proportions, and a large chat/reminder backlog)
and explain query plans. Record SQL count, rows scanned, API p50/p95, worker duration,
queue oldest age, CPU/RAM and bytes transferred before/after. Agree latency goals
from the server baseline; passing unit tests does not prove thousand-user capacity.
Run `composer analyse`, `npm --prefix frontend run lint`, the frontend build and
relevant tests. The local baseline and reproducible script are documented in
`docs/performance-baseline.md`; production concurrency/provider timings still need
representative measurements.

## 11. Server/deployment work after implementation

Keep Mercure supervised as today. Add systemd units for the named Messenger
consumers with PHP 8.4, the web account, project working directory, production
environment, time/memory limits and automatic restart. Add the short dispatcher
timer. Queue rows, delivery intent and task/run state are PostgreSQL data and belong
in backups. Generated sitemaps and media masters need persistent deployment paths;
thumbnails can be rebuilt. In production, browser-based queue consumption is
disabled and processing continues with no administrator logged in. Supervisor
is an alternative to systemd for consumers, using the same queue names, limits,
restart behavior and graceful deployment lifecycle.

Deployment needs migrations and task registration, compatible workers, graceful
stop/restart after new code, writable files and a heartbeat/backlog smoke check.
Existing workflow currently does none of the worker lifecycle steps. Use additive
schema/message versions and staged feature flags so old workers cannot consume
incompatible new payloads. Suspend incompatible workers before rollout if no
compatible transition exists. If rollback is needed, preserve queued work for
inspection; do not enable synchronous plus asynchronous delivery for the same event.

SMTP/VAPID/Mercure secrets remain server-side and unchanged unless configuration
specifically requires it. New variables/units/commands must be documented with the
implemented names; do not install speculative worker commands from this plan now.

## Reference files examined

Wave: Composer/config/deployment workflow; all controller, service, command and
entity inventories; account email/token flows; notification/push/realtime/read
flows; media upload/thumbnail/backfill; sitemap/SEO; public and admin catalog queries;
directory composable/cards; current Tools services and tests. Key paths are named
in the audit table above and [project handoff](project-handoff.md).

FroshTools: `src/Controller/{QueueController,ScheduledTaskController,LogController}.php`,
`src/Components/LineReader.php` and
`src/Resources/app/administration/src/module/frosh-tools/component/frosh-tools-tab-logs/`.
It is a local reference, not a dependency to install into Wave.

Shopware core/administration references for the development worker:
`Framework/Resources/config/packages/shopware.yaml`,
`Framework/DependencyInjection/Configuration.php`,
`Framework/MessageQueue/Api/{ConsumeMessagesController,ScheduledTaskController}.php`,
`Resources/app/administration/src/core/worker/admin-worker.js` and
`Resources/app/administration/src/app/init-post/worker.init.ts` in the local
`vendor/shopware/` checkout.
