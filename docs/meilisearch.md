# Wave public search and Meilisearch

Wave's global header keeps its existing input and category selector. Suggestions
are capped at **10 per category**. The displayed numbers are Meilisearch's exhaustive
`totalHits`, so 50 matching creators means a count of 50 and at most 10 suggestions.
Enter, the magnifier and “Pogledaj sve rezultate” open the localized search page
(`/pretraga?q=…` in Bosnian). Its All/Creators/Campaigns/Companies tabs retain the
query and show real totals. Every tab, including All, has **25 results per page**;
page and type are stored in the URL and browser back/forward works.

## Backend switch

`MEILISEARCH_ENABLED=true` uses Meilisearch. `MEILISEARCH_ENABLED=false` uses
`DatabaseSearch`, with the same suggestion limits, totals, API response and 25-row
pagination. `false` is the safe installation default. The database backend performs
SQL matching/counting and loads public summaries; the indexed backend below does
not. The switch is server-side and changes require environment/cache refresh and
worker restart when using compiled deployment configuration. When switching back
to Meilisearch after database-only operation, run a full index first: changes made
while disabled were not queued for the engine. Enabling Meilisearch with a temporary
CLI environment override allows warming the index while the live app still uses DB.
If enabled but unavailable, return 503; do not silently flip backends.

## Meilisearch data flow and limits

`PublicSearchController` proxies only validated parameters to `PublicSearch` and
`Meilisearch`. The browser never connects to Meilisearch or receives a key.
One `/multi-search` HTTP call reads the localized Wave index: three category queries
for suggestions, or three count-only queries plus one 25-hit query for a results page.
With the switch enabled, there is no SQL text matching, SQL counting, per-hit hydration or database fallback.

The application still reads its small transactional `search_index_change` security
fence and, for authenticated viewers, existing bidirectional personal blocks.
These are access checks, not database searches. Both hits and totals are filtered
by these checks in Meilisearch. Empty queries return an empty prompt. Unavailable
Meilisearch while enabled returns a localized 503 with Retry-After; it never silently
returns incomplete database search results. API requests are rate limited.

Documents contain only public result summaries, public media representations,
localized topics and descriptions. No login email, phone, password, private works,
messages, account settings or API credentials are indexed. Agencies appear once as
companies; their private creator participant is excluded. Hidden, unapproved and
deleted accounts and non-open campaigns are excluded. Campaign deadlines are also
filtered at search time, so expiry does not wait for the next daily job.

Campaign matching includes the full localized short summary and description.
Each field falls back to its original text when its translation is missing. After deploying a
change to indexed text, run `app:search:index` to refresh existing documents
immediately, or let the next daily reconciliation rebuild them.

Exact totals use `page` / `hitsPerPage`, **not** `estimatedTotalHits` or returned-row
length. Wave sets the engine's `pagination.maxTotalHits` to 1,000,000,000 so the
usual 1,000-hit default does not truncate totals or pagination. Exhaustive totals
have a cost for broad searches; monitor latency as the catalog grows. Blank queries
are not used as unrestricted public browse requests. Queries are limited to 200
characters; the API clamps neither totals nor the fixed page sizes.

## One local instance for Wave and Zeka

Yes. A single running process can host both projects. Wave's default index is
`wave_dev_search`; Zeka's reference plugin uses its own prefix and product/category
indexes. Wave commands never clear the instance or touch an index outside their
configured prefix. Use unique prefixes for development, staging and production,
and keys restricted to the exact Wave index. Different processes are also possible
with different ports and data directories. Never open one data directory from two
processes or point an old binary at another instance's newer database.

Your existing `/Users/suadasceric/meilisearch` binary reports **1.11.1**. The real
integration test was run against it on an isolated port and data directory, not
Zeka's index. The production installer pins **1.55.0** from the official release;
check release notes and perform the smoke test below before enabling production.
Do not upgrade Zeka's existing data directory as part of Wave installation.

Start your existing terminal instance as usual. Put these values in untracked
`.env.local` (or deployment environment); use your actual port and credentials:

```dotenv
MEILISEARCH_ENABLED=true
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_INDEX_PREFIX=wave_dev
MEILISEARCH_ADMIN_KEY=your_wave_scoped_indexing_key
MEILISEARCH_SEARCH_KEY=your_wave_scoped_search_key
MEILISEARCH_MASTER_KEY=
```

On a deliberately unsecured loopback-only development instance, both client keys
may be empty. Never use that configuration on production. No `VITE_` key is used.
The master key is only for bootstrapping scoped keys; normal PHP requests/workers
use the indexing and search keys.

## Manual indexing

Apply migration `Version20261018010000` first. It adds the small invalidation fence.
Run PHP as your normal local user, using the documented PHP 8.4 executable:

```sh
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:search:index
```

The default command configures only the selected Wave index and queues a full
reconciliation. A running background consumer processes the queued batches:

```sh
php bin/console messenger:consume background --time-limit=3600 --memory-limit=256M
```

For initial setup or debugging without a worker:

```sh
php bin/console app:search:index --sync
```

This also uses bounded batches of 100, waits for each engine task to succeed, and
sweeps stale documents. `--configure-only` updates index settings without importing
records. Repeated indexing upserts deterministic entity/locale IDs. There is no
`delete all indexes` option. A failed engine task makes the command fail instead
of reporting success merely because Meilisearch accepted the HTTP request.

## Automatic updates and 24-hour queue reconciliation

`SearchIndexSubscriber` records a dirty key and queues `SearchIndexBatchMessage`
after creator, company, user, campaign and company-industry changes. Company or
owner visibility changes include that company's campaigns; agency studio changes
also update the public company. Dirty keys exclude old summaries from results
immediately after commit, even if the worker is down. Only a confirmed indexing
or deletion task clears the dirty key. A failure leaves the key in place and uses
the existing Messenger retry/failure workflow. Advisory locks serialize source
writes and indexing acknowledgements so a newer invalidation cannot be lost.

`SearchReconcileTask` is registered in the existing `TaskRegistry` with an interval
of **86400 seconds**, scheduled relative to registration/previous dispatch. It
processes 100 source rows, queues one continuation, and uses a numeric keyset scan
of indexed documents to repair stale/hard-deleted records. It covers all three
catalogs and all seven locales. Category label changes also queue reconciliation.
The parent scheduled task records dispatch/batch completion; continuations remain
visible as separate jobs in Admin → Tools → Queues. Check the queue, not just the
parent task timestamp, to confirm the entire catalog has finished.

```sh
php bin/console app:scheduled-tasks register
php bin/console app:scheduled-tasks dispatch
```

Production's existing once-per-minute dispatcher and `background` consumer handle
it; **do not add another daily cron**. Registering is idempotent and does not reset
existing task schedules. When the Meilisearch backend is disabled, this scheduled task does no engine work.
The app must have `WAVE_QUEUE_ENABLED=true` in production. The existing local admin
worker can consume background jobs while testing, or use the CLI consumer above.
If the privacy backlog exceeds 5,000 dirty entities, search fails closed with 503
until indexing catches up, rather than sending an unbounded safety filter.

## Hetzner ARM64 server installation

These are prepared instructions, not operations already run on your server.
Run the installer as root on the server from the deployed repository:

```sh
sudo sh bin/install-meilisearch.sh
sudo systemctl status wave-meilisearch --no-pager
curl --fail http://127.0.0.1:7700/health
```

The installer selects ARM64 or AMD64, downloads the pinned official binary, checks
its SHA-256, and creates a dedicated `wave-meilisearch` user and systemd service.
It leaves the existing master key intact on reruns and refuses to replace a
different installed Wave engine version. Paths:

- Binary: `/usr/local/bin/wave-meilisearch`
- State: `/var/lib/wave-meilisearch/data.ms`
- Secret service environment: `/etc/wave/meilisearch.env`, root-owned, mode 0640
- Unit: `deploy/wave-meilisearch.service` → `/etc/systemd/system/`

The service binds **127.0.0.1:7700**. Do not open port 7700 in Hetzner/firewall or
reverse-proxy it to the public internet. Symfony on the same server is the proxy.
Initial resource settings limit indexing to 1 GiB and two threads, with systemd
memory high/max at 2/3 GiB; adjust after measuring catalog size and indexing time.
Runtime data lives outside release directories and survives deployments.

### Keys and application cutover

Use prefix `wave_prod`, never the local `wave_dev` prefix. Securely copy the service's
`MEILI_MASTER_KEY` value into `MEILISEARCH_MASTER_KEY` in the application's untracked
`.env.local` temporarily. Do not paste keys into commits, terminal history or chat.
Set the host/prefix and run as the web account (adapt account/path if different):

```sh
sudo -u steelcodeweb env APP_ENV=prod php8.4 bin/console app:search:keys --output=var/wave-search-keys.env
```

The command writes a **0600 dotenv file**, never keys to stdout. Merge its two lines
into `.env.local` securely, remove the temporary bootstrap master variable, and
remove the temporary key file once configured. The indexing key is scoped to
`wave_prod_search` and only the needed index/settings/documents/search/task actions;
the separate search key permits search only on that same index. If using a compiled
`.env.local.php`, regenerate it and clear cache after **each** environment change.

Before activating the feature, follow the existing deployment/worker pause process
in `docs/deployment.md` and `docs/server-operations.md`. Deploy backend/frontend
and migration together, configure the environment, and initialize the index:

```sh
sudo -u steelcodeweb env APP_ENV=prod php8.4 bin/console doctrine:migrations:migrate --no-interaction
sudo -u steelcodeweb env APP_ENV=prod MEILISEARCH_ENABLED=true php8.4 bin/console app:search:index --sync
sudo -u steelcodeweb env APP_ENV=prod php8.4 bin/console app:scheduled-tasks register
```

Then resume the existing consumers/dispatcher. Keep the backend switch false until service, credentials, settings and initial
indexing are ready. You can warm the index with `MEILISEARCH_ENABLED=true` on the
CLI command, then switch the live app to true.
Run a queued full reconciliation once more after cutover if public data changed
during the initial import. Old workers must be restarted to load the new handlers
and environment. No additional queue transport, Redis, Node server or frontend
Meilisearch SDK is needed.

### Smoke test, maintenance and rollback

1. Search a known visible creator, company and campaign in all needed languages.
2. Confirm `/api/search?q=...&mode=suggest` has correct totals and at most 10 hits per
   bucket; `/api/search?q=...&page=2` has at most 25 and distinct results from page 1.
3. Hide a profile and close a campaign: they must disappear from hits and counts,
   including before the indexing job completes. Check blocked-user filtering.
4. Inspect Admin → Tools for the new scheduled task, jobs, retries and failures.
5. Inspect `journalctl -u wave-meilisearch` and PHP background logs; never log keys
   or complete provider request/response transcripts.

Search pages and legacy directory `?q=` URLs use `noindex, follow` in both server
HTML and SPA metadata. Search routes are excluded from the sitemap and hreflang;
normal directories/profiles retain their existing indexing/canonical behavior.
Do not block the search route in robots.txt, so crawlers can read its noindex tag.

Back up `/etc/wave/meilisearch.env` securely. The search index is derived data and
can be rebuilt from PostgreSQL; for engine upgrades use the official dump/restore
procedure or rebuild a fresh dedicated Wave instance. Never reuse another
project's database path. Rolling back the application can leave the service/index
running safely; the previous app does not consume it. Setting `MEILISEARCH_ENABLED=false` explicitly restores database search. Restore PostgreSQL and rebuild
search if restoring a database backup.

### Validation commands

```sh
php bin/phpunit tests/Search/PublicSearchTest.php
# Use a dedicated disposable PostgreSQL test database; Symfony adds _test.
WAVE_SEARCH_TEST_HOST=http://127.0.0.1:17770 php bin/phpunit tests/Search/MeilisearchIntegrationTest.php
npm --prefix frontend test
npm --prefix frontend run lint
npm --prefix frontend run build
composer analyse
```

The real-engine test creates/removes a randomly named `wave_test_*_search` index
and refuses a non-PostgreSQL/non-`_test` database. Other tests do not need a running
engine. Relevant implementation: `src/Search/`, `SearchIndexSubscriber`,
`SearchIndexCommand`, `SearchKeysCommand`, `SearchView.vue`, and shared result cards,
tabs and pagination.

References: [search pagination](https://www.meilisearch.com/docs/reference/api/search/search-with-post),
[multi-search](https://www.meilisearch.com/docs/reference/api/multi-search/perform-a-multi-search),
[security](https://www.meilisearch.com/docs/resources/self_hosting/security/basic_security),
[pinned production release](https://github.com/meilisearch/meilisearch/releases/tag/v1.55.0).
