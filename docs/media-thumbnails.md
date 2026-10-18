# Listing images, skeletons and thumbnails

Creators, campaigns and companies continue to fetch directory records in batches
of 30. Their grids show six skeleton cards for the first request and two appended
skeletons when loading another batch. Existing cards remain mounted. Skeletons
reuse the real card geometry, image slots reserve their space, and reduced-motion
preferences disable shimmer and fades. The shared `CardImage.vue` uses native lazy
loading, asynchronous decoding, responsive `srcset`/`sizes`, a loading placeholder,
and a settled logo fallback on image errors. Cached images reveal immediately.
Creator and campaign directory cards use four columns above 760px and two on
mobile, including skeletons. Their image `sizes` matches the grid width so the
browser selects an appropriate 96/320/480px thumbnail for its pixel density.
These variants remain useful; restoring the grid requires no thumbnail deletion
or regeneration.

Campaign directory default sorting (`recommended`) matches the homepage:
featured campaigns first, then publication date descending and ID descending
within each group. Explicit `newest` and `closing` selections retain their own
orders. Offset and cursor pagination share this ordering; the recommended cursor
uses a `published` position key to reject cursors issued with the former
closing-date order. Migration `Version20261018180000` adds the matching campaign
index without changing records or existing images.

Campaign cards share the compact layout across directories, home and profile
campaign rows: the original warm white background and content panel, proposal
ranges above the company name, and a date/hiring icon row below. One native
localized link covers the whole card; the bookmark remains an independent button
above that link. It preserves sign-in return links and confirmed bookmark removal.
The hiring icon has the full localized count as its accessible label. Loading
skeletons use the same card layout without the former action button.

## Loading across the app

`SkeletonBlock.vue` supplies the shared shimmer primitive; `LoadingSkeleton.vue`
uses it for account details, profile pages, campaign details, dashboard cards,
list rows and message bubbles. `InboxSkeleton.vue` reserves the chat workspace,
including the conversation list, thread header, timeline and composer. Home uses
its existing card grids and mobile carousels for both real and skeleton cards.
Admin Tools renders skeleton cells inside its real table while an empty page loads;
email templates and moderation use the same shared loading layouts. The
notification menu reserves list rows until its existing first request completes.

Skeletons appear only while their existing data requests are pending. They do not
add polling, extra API calls or artificial delays. Static pages display directly.
Loaded content stays visible during home/inbox/dashboard refreshes. Account waits
for its session and activity data before showing the dashboard, preventing a flash
of sign-in or empty activity lists. Chat history removes placeholders before
restoring the retained message's scroll position, including on request errors.
Loading announcements use the existing localized labels or `app.loading` in all
six locales. Placeholder shapes are hidden from screen readers, have no interactive
controls, and shimmer is disabled for reduced-motion preferences.

## Image storage and indexing

### Upload guidance and previews

Profile and portfolio image fields show persistent recommendations below the
upload control, including after selection: creator photos and company logos
600×600, company/agency covers 1920×640, campaign covers 1200×800, and landscape
portfolio images 600×400 pixels. These are recommendations, not new validation
requirements. Accepted formats and the existing 10 MB limit remain visible.
All seven locale catalogs contain the guidance.

Company and campaign cover controls offer expandable examples of desktop/mobile
page and listing crops. The shared preview uses the selected draft or saved image;
opening it never uploads a profile draft or modifies media. Preview frames show
representative crops rather than complete pages; final crops can vary with screen
width and page content. Brand and agency previews account for their different
mobile cover layouts.

On mobile (up to 760px), campaign listing images and the separate campaign-page
photo use 3:2 proportions, matching the campaign upload recommendation. The page
photo sits above its text and application card. Desktop campaign image dimensions,
stored masters, thumbnail sizes and existing uploads are unchanged.

`ImageUploadProcessor` creates a lossless WebP master at most 1920px wide for
company/campaign covers, 1600px for report evidence, and 600px for other images.
Smaller images keep their dimensions. Processing preserves aspect ratio, alpha
and JPEG orientation, without cropping or upscaling. Existing uploads are unchanged. New uploads
queue generation of WebP thumbnails at quality 82 at fixed maximum widths of **96, 320 and
480px**. Each thumbnail keeps proportional height. Directory cards use thumbnail
URLs; profile detail views retain the master URL.

Migration `Version20261006180000` adds nullable `media.width` and `media.height`.
These record the source's oriented dimensions. New uploads populate them
immediately; the generation command backfills existing records. API image
resources derive actual responsive widths from these columns, including images
smaller than a configured variant. Resource serialization performs no image
processing or filesystem inspection. Directory queries fetch image associations
in batches rather than performing one media query per card. Full creator responses fetch portfolios separately for the bounded page;
listing card responses omit portfolio associations and detail fields.

`MediaThumbnails` stores derivatives outside the web root:

```text
var/media/thumbnails/v2/{sha256-of-source-storage-path}/{96|320|480}.webp
```

The fixed sizes prevent arbitrary client-requested transformations. Each media
source has a filesystem lock; waiting generators recheck files after acquiring it.
Encoding uses temporary files in the same directory, followed by atomic renames.
Existing thumbnails are reused. Upload failure cleans up its master and
thumbnails; media deletion removes all derivative versions. Uploads have unique,
immutable source paths. Changing the thumbnail recipe requires a new
`ImageVariants::VERSION` so its URL and storage namespace invalidate old browser
and server caches together. Old derivative versions are removed when the owning
media is deleted.

## Removing unused uploads

Profile saves remove obsolete avatar, company logo/cover and portfolio media
records after all replacement associations have been saved. Campaign edits do
the same for replaced or removed covers. This is handled by the server, including
portfolio project covers and extra gallery images; it does not depend on a second
browser request. Reordering projects or reusing an image keeps its file.

`UnusedMediaCleanup` checks all avatar, logo, company/campaign cover, portfolio
and private report references before removing a media record. Database deletion
and the `stored_file_deletion` retry entry share the save transaction. The response
subscriber removes the source file and every thumbnail version only after commit;
a rollback keeps both the media record and its files. Failed filesystem deletions
remain queued for `app:media:cleanup-deleted` or daily maintenance to retry.

The existing daily `MediaMaintenanceTask` and its bounded `MediaIndexingMessage`
batches also remove unreferenced uploads older than 24 hours, covering abandoned
forms and previously orphaned media records. Fresh uploads have this grace period
so an unfinished form can still attach them. Hidden, pending and private profiles
retain their referenced images. External image URLs and bundled site assets are
not managed uploads and are not deleted. Run Media Maintenance in Admin Tools to
start the same cleanup immediately; the background worker must be running.

Existing media is supported without replacing its source. With queues enabled,
a missing variant queues a deduplicated repair and temporarily serves the master;
image GET requests do not resize pixels. `MediaMaintenanceTask` scans bounded
primary-key batches daily and enqueues `MediaIndexingMessage` continuations;
individual derivatives use `GenerateThumbnailsMessage`. Start maintenance manually
in Admin Tools for the initial library backfill. The direct resumable console
command below remains available for diagnostics. The temporary synchronous fallback
(`WAVE_QUEUE_ENABLED=false`) can still generate a missing variant on request.

Seeded Unsplash URLs use that provider's existing resize parameters for the same
three listing sizes. Arbitrary external image URLs retain their provider's file;
Wave does not download, proxy or index arbitrary remote URLs. Compressed derivatives and local caching apply to images in Wave's owned media library.

## Caching and access

`GET /api/media/{id}/thumbnail/v2/{96|320|480}` uses the same approval, account
visibility, media reference and ownership checks as the master file endpoint.
Authorization runs before thumbnail lookup and before conditional cache handling.
Unknown versions/sizes are rejected. A guessed thumbnail URL or matching ETag
cannot bypass a hidden/unapproved/private media check.

Publicly referenced approved media uses a five-minute HTTP cache lifetime with
`must-revalidate`, `ETag` and `Last-Modified`. Anonymous responses may be publicly
cached; signed-in responses use a private browser cache with the same lifetime.
`Vary: Cookie` separates credential contexts. A conditional request can return
304 after access is checked, saving image bytes. Symfony's automatic session
cache rewriting is disabled only for these public image bytes so it does not
reset the browser cache lifetime to zero. Private or unassociated uploads use
`private, no-store` and never return 304.

Visibility changes take effect on the next server request; previously downloaded
public images may remain in a browser cache for up to five minutes. Images cannot
be recalled after a user has downloaded them. There is no immutable year-long
cache policy for media whose profile visibility can change.

The PWA service worker continues to precache application assets only. It does not
cache API media responses or private API data separately; media uses the browser's
HTTP cache and the server's persistent thumbnail files. This avoids an offline
service-worker cache serving private images after account/session changes.

## Server rollout

1. Ensure PHP 8.4 GD supports JPEG/PNG/WebP and EXIF is enabled, as described in
   [the deployment guide](deployment.md). Use at least 256M of PHP-FPM processing
   memory for typical 12-megapixel photos. Keep `var/media` writable by the web
   process and persistent across deployments; include it in media backups.
2. Follow [server operations](server-operations.md) to deploy migrations and
   install the Messenger consumers and scheduled-task dispatcher. Keep
   `WAVE_QUEUE_ENABLED=true` for asynchronous generation. The workflow builds the
   SPA/PWA and assigns `var` ownership to `steelcodeweb`. Redis and additional
   web-server thumbnail routes are not required.
3. Start `MediaMaintenanceTask` manually in **Admin → Tools → Scheduled tasks**
   to enqueue the initial backfill. Alternatively, generate directly with the
   diagnostic command **as the web account**, preserving directory ownership:

   ```bash
   cd /home/steelcodeweb/web/wave.ba/public_html
   sudo -u steelcodeweb env APP_ENV=prod php8.4 bin/console app:media:generate-thumbnails --batch-size=50
   ```

   For a large library, limit each invocation and resume from its reported ID:

   ```bash
   sudo -u steelcodeweb env APP_ENV=prod php8.4 bin/console app:media:generate-thumbnails --batch-size=50 --limit=500
   # Replace 123 with the last processed media ID printed by the preceding run.
   sudo -u steelcodeweb env APP_ENV=prod php8.4 bin/console app:media:generate-thumbnails --batch-size=50 --limit=500 --after-id=123
   ```

   Running the command again safely reuses existing files. `--force` regenerates
   current-version derivatives atomically. Missing/corrupt/oversized sources are
   reported by media ID; later records still process and the command returns a
   failure status. Retry failed records after repairing their sources, starting
   before the failed ID; the printed resume cursor is the last **processed** ID,
   including failures. Do not delete source images when clearing thumbnails.
4. Reopen the site/PWA after deployment. On a slow connection, verify skeletons
   on all three directories and stable existing cards during the next batch.
   Uploaded card images should request `/thumbnail/v2/…` URLs, with WebP MIME
   type. Revisit the page to confirm HTTP cache hits or 304 revalidation. Check
   a hidden/unapproved profile with a fresh request: thumbnail access must follow
   the same visibility rules as its master image.

## Validation

`MediaStorageTest` covers master resizing, no extra encoding loss, transparency,
orientation and decoding limits. `MediaThumbnailsTest` covers fixed variants,
cache reuse, rebuilding, source preservation, alpha, small-image responsive
metadata and cleanup. `GenerateMediaThumbnailsCommandTest` covers bounded/resumable
indexing, idempotence, forcing and missing-source failures. Marketplace controller
tests cover every upload folder plus thumbnail visibility, conditional responses,
cache headers, invalid variants and media deletion. Directory paging tests still
cover 30/30/1/empty batches and owner visibility on PostgreSQL.

Frontend regressions cover cache/load/error transitions and trusted-provider
resizing, alongside existing directory append/filter/error behavior. Validate
with `npm --prefix frontend test` and `npm --prefix frontend run build`.
PHPStan and ESLint remain unavailable in the project manifests/configuration.
