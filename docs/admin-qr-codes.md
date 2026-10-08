# Admin QR codes

Administrators open **QR kodovi** (`/admin/qr`, localized under the existing
language prefixes). The page contains the shared paginated table and a **Novi QR
kod** button. Creation and editing use a compact modal with the existing form
fields. The separate preview modal offers PNG and SVG downloads.

Each record contains a label and an absolute HTTP(S) destination. The encoded
image contains a permanent `APP_BASE_URL/q/{token}` URL, not the destination.
Editing a destination keeps both the token and the QR image unchanged. The
public redirect reads the current record and responds with an uncached 302;
unknown tokens return 404. Redirect URLs are excluded from indexing. Generation
uses the local `qrcode` frontend dependency, without an external QR service.

The API requires `ROLE_ADMIN`, and creation/editing require the normal CSRF
token. User and moderator accounts cannot manage codes. Credentials in URLs,
unsupported URL schemes, and redirects to the module's own links are rejected.
The table shows total scans and the last scan time; clicking the count or the
statistics icon opens all-time location totals (top 100) and daily activity for
the last 30 UTC days. A scan means a GET open of the permanent link, including
repeat opens. It cannot distinguish a physical scan from a manually opened URL
or count unique people. HEAD, prefetch and known crawler/link-preview requests
are excluded. Unknown locations remain counted.

Statistics are stored as atomic daily aggregates in `wave_qr_scan_daily`, without
raw IPs, visitor identifiers, user-agent storage or GPS coordinates. Editing a
destination preserves its existing statistics. Migration `Version20261008160000`
adds the aggregate table. No external lookup is performed during redirects.
An analytics database error is logged but does not stop the redirect.

For approximate country/city, enable Cloudflare **Rules → Settings → Managed
Transforms → Add visitor location headers**. See
[Cloudflare's transform documentation](https://developers.cloudflare.com/rules/transform/managed-transforms/reference/).
The service accepts `CF-IPCountry` and `CF-IPCity` only when `REMOTE_ADDR` is a
trusted Cloudflare edge peer. `QR_LOCATION_TRUSTED_PROXIES` defaults to the
[published Cloudflare IPv4/IPv6 ranges](https://www.cloudflare.com/ips/), verified
on 2026-10-08. Update this allowlist if Cloudflare changes its ranges. Never trust
all IPs. If Nginx/Apache rewrites `REMOTE_ADDR` to the visitor address, preserve the
actual trusted edge address for PHP, or use a trusted local proxy which strips
untrusted location headers. Without this setup, scans still count but location
is shown as unknown. Location is approximate and can reflect a VPN or mobile
network rather than the scanner's actual city.

No deletion, external payment, or expiry behavior is included.

Deployment uses the normal Symfony/Vue build and Doctrine migration
`Version20261008120000`, which creates `wave_qr_link`. Set the production
`APP_BASE_URL` to `https://wave.ba` before generating printable codes. Keep that
origin available for the lifetime of printed codes; destination editing does not
change the domain encoded in an existing image. Local preview QR codes encode
the configured local origin and should not be printed for production use.

Validation covers admin access, CSRF, pagination, invalid URLs, destination
updates, public redirects, stable tokens, local image encoding, modal save
failures, and all six locale catalogs. The creation/edit modal and SVG/PNG
downloads were also checked in the local Chrome preview, including a 390px
mobile viewport.
