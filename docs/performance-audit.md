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

## Follow-up audit — 8 October, 17:48 CEST

A fresh [production PageSpeed report](https://pagespeed.web.dev/analysis/https-wave-ba/1nm37vpr01?form_factor=desktop)
scored 95 performance on desktop and mobile. Desktop scored 91 accessibility
and passed 3/4 applicable agent checks; mobile scored 96 accessibility and passed
4/4. Both scored 100 best practices and SEO. Desktop's failing agent check was
an unsupported `aria-expanded` attribute on the header search input.

The expansion state now belongs to the search button. The search input retains
its association with the results panel, and keyboard result focus and Escape
behavior remain intact. Desktop and mobile search interactions were checked.

Homepage cards now declare their actual four-column desktop and horizontal
mobile sizes. Contact, audience and FAQ illustrations use responsive local
variants; small journey illustrations and built-in placeholders also have
smaller sources. The existing original images and fonts are unchanged. New WebP
variants were resized proportionally using cwebp quality 82; no image was upscaled.

The six exact brand tokens remain unchanged. Text-only contrast variants are
`--muted-text: #5C7167`, `--coral-heading: #BE7058` and `--coral-text: #A95E49`.
They address faint captions and accent text; artwork retains the original palette.
The company audience action now uses the same original forest button style as
the creator action. Contrast was evaluated against the
[WCAG text contrast thresholds](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html).

Final Lighthouse 13.5 results used the compiled app and a temporary production-mode
Symfony server behind local gzip compression, with email disabled:

| Check | Desktop | Mobile |
| --- | --- | --- |
| Performance | 99 | 90 |
| Accessibility | 100 | 100 |
| Best practices | 100 | 100 |
| SEO | 100 | 100 |
| Applicable agent checks | 4/4 | 4/4 |
| Largest contentful paint | 0.8 s | 3.4 s |
| Total blocking time | 0 ms | 90 ms |
| Layout shift | 0 | 0.001 |
| Transferred resources | 661 KiB | 691 KiB |

A separate same-server mobile comparison before the final placeholder changes
improved performance from 82 to 85, LCP from 4.4 s to 4.1 s, and blocking time
from 160 ms to 100 ms; host benchmark indexes were 3850 and 3827. This static
comparison omitted Symfony metadata and is only a performance comparison.
Local scores do not establish a production improvement or a guaranteed 100.
These changes have not been deployed; rerun production PageSpeed after deployment.

Validation: 209 frontend tests, ESLint, production build, and diff whitespace
checks passed. The accompanying CI migration test repair passed all 220 backend
tests (272,598 assertions) on an isolated PostgreSQL database, plus PHPStan.


## GA and mobile follow-up — 8 October, 21:15 CEST

The user's [live mobile report](https://pagespeed.web.dev/analysis/https-wave-ba/ev4ydw8i6m?form_factor=mobile)
scored 94 performance and 100 for accessibility, best practices and SEO, with
4/4 applicable agent checks. LCP was 3.0 seconds, blocking time 20 ms and layout
shift zero. These are the deployed measurements, before the change below.

GA stream 16044159577 uses `G-VHWT5SS3MW`, matching the live bundle. Google's
installation test reported “Your Google tag was detected on your website.”
Realtime pages showed Wave home, creator and campaign page views (24 views and
3 active users in the inspected 30-minute window, including diagnostic visits).
The inactive-collection banner was still visible in stream settings; it was not
confirmed cleared. The live tag uses the correct gtag Arguments queue, waits
for analytics consent, and sends explicit SPA page views. No analytics settings
or consent behavior were changed during this follow-up.

Symfony now reads Vite's generated asset manifest to include the existing
homepage stylesheet in the initial HTML for all six home URLs. Other routes
retain lazy loading. This avoids waiting for the homepage JavaScript before
requesting its CSS; Vite reuses the stylesheet instead of requesting it twice.
A preload-only experiment was discarded because it did not improve the result.

A warmed, sequential local production comparison used Lighthouse 13.5 and the
same gzip proxy. Host benchmarks were 3799 before and 3831 after:

| Mobile lab metric | Before | After |
| --- | --- | --- |
| Performance | 88 | 89 |
| LCP | 3.67 s | 3.55 s |
| Blocking time | 99 ms | 86 ms |
| FCP | 1.72 s | 1.81 s |
| Layout shift | 0 | 0 |
| Accessibility / best practices / SEO | 100 / 100 / 100 | 100 / 100 / 100 |

This small local improvement does not predict a production score. The mobile
slider layout, partial next card, fonts, user uploads, compression and thumbnail
settings are unchanged. Deploy and rerun the live report to measure the effect.

Validation: 16 backend tests (781 assertions) covering SEO, all six homepage
URLs and manifest fallbacks, 216 frontend tests, PHPStan, ESLint, the production
build and diff whitespace checks passed. The mobile next-card preview was also
verified visually.
