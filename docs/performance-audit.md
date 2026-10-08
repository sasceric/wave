# Homepage performance audit — 2026-10-08

The production PageSpeed report supplied by the user scored 68 performance,
91 accessibility, 96 best practices, 100 SEO, and passed 2 of 4 applicable
agentic browsing checks. These are lab measurements, not field data.

The current changes preserve DM Sans, DM Serif Display, and La Belle Aurore,
serve their WOFF2 files locally, preload the primary font subsets and responsive
hero image, and reduce image and startup JavaScript downloads. Upload masters
remain lossless; only regenerated v2 thumbnails use quality 82. Locale catalogs
are precompiled and loaded per language. Guest session discovery returns a
successful empty response instead of a normal-guest 401 console error.

Pinch zoom, text contrast, llms.txt, and the resource discovery catalog are
corrected. The catalog intentionally lists no agent tools because the app does
not expose any. The hero Campaigns link uses the existing `app.campaigns` key
in all six languages.

Validation: 191 frontend tests, 199 backend tests against isolated PostgreSQL,
frontend production build, ESLint, and PHPStan passed. The campaign label was
also checked in the browser.

Local Lighthouse 13.5 checks used the production Symfony environment behind
a gzip proxy, with standard mobile simulation. Accessibility, best practices,
and SEO scored 100; all four applicable agentic checks passed. Performance
remains unfinished: a previous iteration scored 90, while the final local run
scored 78 (FCP 1.7s, LCP 3.6s, TBT 460ms, CLS 0.001). The host benchmark
fell from about 2540 to 822 between runs, so these local performance scores
are not reliable production predictions. An experiment that inlined shared
CSS and preloaded the entire homepage dependency tree was removed.

Before measuring production again, deploy using the documented deployment
workflow and backfill the new thumbnail version as described in
[media-thumbnails.md](media-thumbnails.md). Then rerun the supplied PageSpeed
report against wave.ba. No production deployment or 100 performance result is
claimed by this audit.

After the audit, the original muted green palette was restored at the user's
request (`--muted: #77847e`, eyebrow labels `#6b8378`, and campaign-card secondary
text). The primary forest green remains `#173d36`. The earlier accessibility
score was measured before this restoration and needs rechecking; it is not a
claim about the final palette. The darker accent-text token was also removed;
shared accent text uses the user-confirmed coral `#D98368`.

## Controlled comparison and log follow-up

A sequential comparison of HEAD and the updated compiled frontend used the same
read-only static gzip server, local API, clean Chrome, and Lighthouse 13.5 mobile
settings. Host benchmark indexes were comparable (3804 before, 3761 after).
Performance improved from 62 to 84; FCP from 3.9s to 1.7s, LCP from 6.4s to 4.0s,
and TBT from 240ms to 170ms. Transferred bytes fell from 956,450 to 744,014.
This is one lab pair, serves the SPA without Symfony metadata injection, and
measures a compiled build rather than Vite hot-reload preview or deployed wave.ba.
It confirms a local improvement, not a 100 performance score or production result.

The log follow-up fixed repeated Doctrine QueryBuilder sort-direction deprecations
in chat history, admin catalogs, newsletter listings, and thumbnail generation.
The chat brief now waits for authorized full thread metadata before rendering;
compact inbox rows do not contain deliverables or channels. A template regression
test covers delayed metadata and summary refreshes. Chat location now uses the
existing city/country formatter. Both campaign and inquiry chats were checked in
the local browser. Fresh application requests after these fixes produced no
warning, error, or deprecation entries in today's development log.

Historical database errors came from missing migration fields; the inspected
local schema now contains them. Test-log warnings from failure-injection cases
are intentional. Historical logs remain intact; errors are not filtered away.
Production logs and cache permissions were not rechecked on the live server.
The LastPass WebSocket failure is from an extension; the install-banner message
is expected when the app stores the install prompt for its existing install button.
