# Public URL redirects and page titles

Admin → Marketing → Redirects manages exact public Wave paths. Rules are permanent
HTTP 301 redirects, with search, pagination, an editor, enable/disable and delete
actions. The campaign/creator table component supplies row selection and bulk deletion
with confirmation. Source, destination, status, origin and update-date columns sort on
the server across all pages; the fixed HTTP 301 and actions columns are not sortable. Only administrators can manage them; all writes require CSRF protection.
Source paths are unique. Redirect loops and private/system paths are rejected.
Destinations must be local public paths, optionally with query parameters.

Deleting a creator or company captures its original slug **before** anonymization
and records the public detail-to-directory redirect for each of the six locales.
Company deletion also records redirects for its retained campaigns. Admin campaign
deletion records the same six rules before removing the campaign. Redirects commit
in the deletion transaction. Existing rules that an administrator edited or disabled
are preserved. Changing visibility/approval, expiring or merely closing a record
does not create redirects; only deletion does. Shared account/campaign history and participant API access keep
their existing deletion policy; API and account URLs are never redirected.
If an existing custom rule would make a generated deletion redirect cyclic, that
new row is saved disabled for administrator review, without blocking deletion.

Canonical locale paths are stored. Existing English and old `/sr`, `/cnr`, `/sl`
aliases resolve to the same rules. Incoming query parameters are ignored by default;
the optional transfer setting retains them. Chains resolve to their final destination
in one response. Redirect responses and admin/lookup APIs are not cached so rule
changes can take effect immediately. Production document requests use Symfony 301s;
SPA links and the Vite preview use the public resolver. Initial production SPA
navigation skips a redundant lookup because Symfony already checked the document.

The feature records future deletions. Original creator/company slugs anonymized
before this feature cannot be reconstructed automatically; known older URLs can
be added manually. Redirecting a deleted detail page to its directory provides a
useful navigation fallback, but is not a guarantee of preserved Google ranking.
Prefer a genuinely relevant replacement when one exists. Google may treat broad
redirects as soft 404s: [Google’s guidance](https://support.google.com/webmasters/answer/2445990?hl=en).

Migration `Version20261012100000` creates `url_redirect` and is compatible with
PostgreSQL and SQLite. Run normal Doctrine migrations before serving the new code.
The migration has been applied to the local database only; these changes were not
pushed or deployed.

`config/seo_route_titles.json` shares route-title keys between Symfony and Vue.
Login, registration, recovery and private pages remain `noindex`; authentication
mode and session state determine the account title. All six locale catalogs supply
the text. Titles use `Page title | Wave`; the homepage uses `Wave | …`. Browser,
Open Graph and Twitter metadata use the same title, and normalization avoids adding
the brand twice. Profile/campaign titles keep their actual names.

Missing or unavailable public profiles and campaigns return localized 404 metadata.
The SPA leaves detail pages noindex until their record loads and clears canonical,
alternates and structured data when the record is missing.

Validation: the full isolated PostgreSQL backend suite, all frontend tests,
PHPStan, ESLint and the production build passed. A read-only desktop/mobile preview
with demo rows checked selection, confirmation, sorting and fixed action columns.
