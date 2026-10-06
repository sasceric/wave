# Listing images, skeletons and thumbnails

Creators, campaigns and companies continue to fetch directory records in batches
of 30. Their grids show six skeleton cards for the first request and two appended
skeletons when loading another batch. Existing cards remain mounted. Skeletons
reuse the real card geometry, image slots reserve their space, and reduced-motion
preferences disable shimmer and fades. The shared `CardImage.vue` uses native lazy
loading, asynchronous decoding, responsive `srcset`/`sizes`, a loading placeholder,
and a settled logo fallback on image errors. Cached images reveal immediately.

## Image storage and indexing

`ImageUploadProcessor` still creates a lossless WebP master at most 600px wide,
without cropping or upscaling, preserving alpha and JPEG orientation. New uploads
also generate lossless WebP thumbnails at fixed maximum widths of **96, 320 and
480px**. Each thumbnail keeps proportional height. Directory cards use thumbnail
URLs; profile detail views retain the master URL.

Migration `Version20261006180000` adds nullable `media.width` and `media.height`.
These record the source's oriented dimensions. New uploads populate them
immediately; the generation command backfills existing records. API image
resources derive actual responsive widths from these columns, including images
smaller than a configured variant. Resource serialization performs no image
processing or filesystem inspection. Directory queries fetch image associations
in batches rather than performing one media query per card. Creator portfolios
are fetched separately for the bounded page to preserve pagination of their
collection association.

`MediaThumbnails` stores derivatives outside the web root:

```text
var/media/thumbnails/v1/{sha256-of-source-storage-path}/{96|320|480}.webp
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

Existing media is supported without replacing its source. Missing variants can
be generated on their first authorized request, but pre-generating the library
avoids image processing during directory browsing. The resumable command scans
by the indexed media primary key, with bounded ORM batches, and clears the entity
manager between batches. It processes new images automatically only during their
normal upload; there is no recurring image polling or indexing job.

Seeded Unsplash URLs use that provider's existing resize parameters for the same
three listing sizes. Arbitrary external image URLs retain their provider's file;
Wave does not download, proxy or index arbitrary remote URLs. Lossless generation
and local caching apply to images in Wave's owned media library.

## Caching and access

`GET /api/media/{id}/thumbnail/v1/{96|320|480}` uses the same approval, account
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
2. Deploy normally. The existing workflow runs migration
   `Version20261006180000`, builds the frontend/PWA, and assigns `var` ownership
   to `steelcodeweb`. No new environment variable, queue, Redis service, Caddy
   rule or web-server thumbnail route is needed.
3. Generate thumbnails for existing uploads **as the web account**, so generated
   directories and locks remain writable by future web requests:

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
   Uploaded card images should request `/thumbnail/v1/…` URLs, with WebP MIME
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
