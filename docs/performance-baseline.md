# Directory and admin pagination performance baseline

Measured locally on 2026-10-06 with PHP 8.4.25 and PostgreSQL 18.4 (Homebrew).
The repeatable script is `tools/benchmark.php`. It creates an owned, random PostgreSQL
**test database**, verifies its name before creating the schema, and drops it and its
private Symfony cache directory on exit. Local account data is never seeded or reset.

## Implemented behavior

- Creators, campaigns and companies load 30 cards at a time using signed,
  filter/locale-bound keyset cursors. Existing offset API callers remain supported.
- The first page returns a filtered total. Later pages use a one-record lookahead,
  return `hasMore`/`nextCursor`, and skip the count query. The count is a first-page
  snapshot; live inserts/deletions can change it during a visit.
- A cursor preserves the existing directory order and final ID tie-breaker. It
  prevents offset shifts when an earlier row is inserted or deleted. It is not a
  frozen snapshot: edits to a row's name, featured flag, closing date or company
  campaign count can move that row. The client deduplicates by ID.
- Admin creators, companies, campaigns and pending registrations use server
  pagination, search and allowlisted sorting, with 25/50/100 rows. Deleted last-page
  records cause the server to clamp the page. Overview requests return metrics
  without fetching catalogs. Homepage selectors search/load pages while keeping
  selected IDs and labels; curation writes only hydrate selected/previously featured
  entities so Doctrine indexing events are preserved.
- Migration `Version20261006220000` indexes creator ordering, campaign ordering
  and company available-campaign counts. The query adds a leading range bound
  to help PostgreSQL use the creator sort index.
- PHPStan level 5 (including Doctrine mapping/DQL checks) and ESLint's recommended
  JavaScript/essential Vue checks are configured. The GitHub validation workflow
  runs them with the backend tests, frontend tests and production build.

## Dataset and method

10,000 creators, 10,000 companies, 10,000 open campaigns (one per company), and
10,000 messages in one conversation. Profiles are synthetic, mostly ownerless,
with small payloads and no uploaded media. Campaign closing dates and featured
flags vary. Each operation is warmed once and sampled 20 times sequentially.
API rows measure Symfony's request/JSON path, without a web server, network or
browser. SQL-only rows isolate the database operation. Deep paging is at row 8,000.
The query-count middleware counts SQL without retaining parameter values.

| Operation | p50 (ms) | p95 (ms) | SQL queries | JSON bytes |
| --- | ---: | ---: | ---: | ---: |
| creators_first | 21.1 | 36.78 | 4 | 7101 |
| creators_continuation | 21.9 | 23.51 | 2 | 7105 |
| campaigns_first | 36.45 | 39.69 | 3 | 18300 |
| campaigns_continuation | 28.42 | 29.38 | 2 | 18399 |
| companies_first | 17.08 | 17.8 | 4 | 6109 |
| companies_continuation | 18.1 | 19.21 | 3 | 6125 |
| creators_deep_api | 6.43 | 7.17 | — | — |
| creator_deep_cursor_indexed | 0.18 | 0.25 | — | — |
| creator_deep_offset_indexed | 0.66 | 0.68 | — | — |
| creator_deep_cursor_without_new_index | 3.38 | 3.61 | — | — |
| creator_deep_offset_without_new_index | 79.22 | 82.04 | — | — |
| chat_latest_50 | 0.15 | 0.17 | — | — |

The deep creator cursor/index plan reads 31 output rows and a small number of shared
buffers; the offset plan still visits the preceding 8,000 entries. The comparison
without the index is performed only inside the disposable database, then the index
is recreated. Full EXPLAIN ANALYZE/BUFFERS plans are recorded in
[the measured JSON](benchmarks/2026-10-06.json) and emitted by the script.

Symfony's actual Messenger worker completed **10 jobs / 1,000 creator indexes** in
338.53 ms; enqueueing the 10 jobs took 30.91 ms.
The worker exercises protected payloads, advisory locking, the real indexing
handler, job state transitions, worker events and acknowledgements. All ten jobs
were verified completed. Peak PHP allocation was 48.5 MiB for the run.
No email/push providers or Mercure network deliveries are called.

## Reproduce locally

Install development dependencies and use a PostgreSQL account with CREATE DATABASE
permission. If the application's account lacks it, supply a separate local
`WAVE_BENCHMARK_ADMIN_DSN` privately; do not commit the value.

```sh
php tools/benchmark.php --size=10000 --samples=20 --output=/tmp/wave-performance.json
```

The script refuses `APP_ENV=prod`. Do not run load experiments on the live server.
The benchmark is optional and is not part of normal deployment or CI.

## Limits and next measurements

These numbers are a local baseline, not a thousands-of-users capacity claim.
Concurrent requests, authenticated owner-heavy datasets, long/media-rich payloads,
filtered `%search%` queries, a broad reminder backlog, queue oldest age, CPU usage,
provider latency/retries, disk thumbnail generation and mobile device latency need
separate representative runs. Company ranking still sorts computed campaign
counts; source fallback is supported by the new campaign index, and background
projections avoid recalculating fresh counts. Keep observing this query as catalogs
grow. Trigram/GIN search indexes and more consumers should follow measured production
bottlenecks rather than be enabled without evidence.

## Deploy

Use the normal production deployment. It installs locked dependencies, builds the
SPA, runs the new migration, clears cache, registers tasks and stops workers so
systemd restarts them with the updated code. No new environment flag or systemd
unit is required. A manual deployment must include the migration and worker reload;
cache clear alone does not create the indexes. Preserve APP_SECRET across releases.

## Validation

The full backend suite passed on a disposable PostgreSQL database: 154 tests,
including cursor ties, insert/delete stability, filter/locale rejection and the
index migration round-trip. The four admin integration tests passed again after
literal-search and media-join refinements. All 102 frontend tests passed, including remote-table
paging, response races, homepage selection retention and profile update events.
PHPStan, ESLint and the production build passed. No production load or phone-device
verification is implied by these checks.
