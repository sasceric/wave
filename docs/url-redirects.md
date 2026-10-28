# Public URL redirects and page titles

Admin → Marketing → Redirects manages exact public Wave paths. Rules are permanent
HTTP 301 redirects, with search, pagination, an editor, enable/disable and delete
actions. The campaign/creator table component supplies row selection and bulk deletion
with confirmation. Source, destination, status, origin and update-date columns sort on
the server across all pages; the fixed HTTP 301 and actions columns are not sortable. Only administrators can manage them; all writes require CSRF protection.
Source paths are unique. Redirect loops and private/system paths are rejected.
Destinations must be local public paths, optionally with query parameters.

## Authentication URLs

Login and registration have dedicated localized routes, shared by links, language
switching, OAuth callbacks and server metadata:

| Language | Login | Registration |
| --- | --- | --- |
| Bosnian | `/prijava` | `/registracija` |
| Croatian | `/hr/prijava` | `/hr/registracija` |
| Serbian | `/rs/prijava` | `/rs/registracija` |
| Montenegrin | `/me/prijava` | `/me/registracija` |
| Slovenian | `/si/prijava` | `/si/registracija` |
| English | `/en/login` | `/en/register` |
| German | `/de/anmelden` | `/de/registrieren` |

Password-reset requests use a separate localized `forgot-password` route (Bosnian
`/zaboravljena-lozinka`), distinct from the existing token-based reset page.
Old account URLs with `mode=login`, `mode=register` or `mode=reset` redirect to the
corresponding page. Symfony sends a 301 for document requests; the SPA performs
the equivalent replacement. Other query parameters, including account type,
bookmark intent, OAuth status and return destination, remain intact. These built-in
aliases require no migration and remain protected from editable public rules.
Deploy the API and SPA together. All auth pages remain noindex and outside the
sitemap and public analytics.

## Public profile URLs

Creator, company and campaign details use one shared public path in every language:
`/kreatori/{slug}`, `/kompanije/{slug}`, `/kampanje/{slug}`. Directory, country and
editorial pages retain their localized URLs. `config/shared_public_routes.json`
defines the shared detail paths; `config/localized_routes.json` retains historical
language mappings for aliases. Selecting another UI language on a shared detail
page keeps its URL. Detail metadata has one canonical URL and no duplicate
hreflang entries; both sitemap generators list each detail once.

Readable slugs use the name/title, with the first available numeric suffix on a
collision (`amina-kovac`, `amina-kovac-1`, …). `PublicUrlSlugs` permanently reserves
current and historical slugs per entity kind, including deleted records, to avoid
assigning another account a URL that still redirects. Creation and edits use the
same allocation service. Database uniqueness handles concurrent allocation.

Owners can edit only the slug in `PublicUrlAction`, below password settings in the
profile's right-hand controls. Campaign owners have the same control on their
campaign details. The modal shows the full public URL and immutable path prefix.
The first manual change is immediate; later changes require 14 days, enforced on
the server. Saving the same slug does not consume the interval. Writes require
CSRF and ownership checks. Profile metadata can be edited before approval without
making that account public.

`PublicUrlRedirectWriter` saves the old canonical URL and historical language
aliases as 301 rules. Subsequent changes retarget earlier rules directly to the
latest URL, preserving administrator overrides and query-transfer settings.
`UrlRedirects` also follows implicit language aliases when resolving a chain or
checking for cycles. Exact alias sources remain editable independently in admin.

Deleting a creator or company captures its original slug before anonymization
and records canonical and historical detail-to-directory redirects. Company
deletion also redirects retained campaigns; admin campaign deletion records the
same aliases before removal. Redirects commit in the deletion transaction.
Existing administrator-edited or disabled rules are preserved. Changing
visibility/approval or closing a campaign creates no deletion redirect. Shared
history and participant access retain their existing deletion policy; API and
private account URLs are never redirected (the public profile guides below are an explicit exception). A generated deletion rule that would create a
cycle is saved disabled for administrator review without blocking deletion.

Incoming query parameters are ignored by default; the optional transfer setting
retains them. Chains resolve to their final destination in one response. Redirect
responses and admin/lookup APIs are not cached, so changes take effect immediately.
Production documents use Symfony 301s; SPA navigation and Vite use the public
resolver. Initial production SPA navigation skips the redundant resolver request.

The feature records future deletions. Original creator/company slugs anonymized
before this feature cannot be reconstructed automatically; known older URLs can
be added manually. Redirecting a deleted detail page to its directory provides a
useful navigation fallback, but is not a guarantee of preserved Google ranking.
Prefer a genuinely relevant replacement when one exists. Google may treat broad
redirects as soft 404s: [Google’s guidance](https://support.google.com/webmasters/answer/2445990?hl=en).

Migration `Version20261012100000` creates `url_redirect` and is compatible with
PostgreSQL and SQLite. Run normal Doctrine migrations before serving the new code.
Migration `Version20261013190000` adds permanent slug reservations and nullable
last-change timestamps. Its data upgrade converts generated six-character hash
suffixes to readable slugs and creates old-to-new redirects, while preserving
custom slugs and leaving the first manual edit available. It preserves directory
search projections because those contain no URLs. PostgreSQL and SQLite upgrade
paths are tested. Cached sitemap indexes without the shared-URL format marker are
regenerated through the existing sitemap task; normal workers must be running.
Both migrations have been applied locally only; this update has not been pushed
or deployed.

`config/seo_route_titles.json` shares route-title keys between Symfony and Vue.
Login, registration, recovery and private pages remain `noindex`; authentication
mode and session state determine the account title. All six locale catalogs supply
the text. Titles use `Page title | Wave`; the homepage uses `Wave | …`. Browser,
Open Graph and Twitter metadata use the same title, and normalization avoids adding
the brand twice. Profile/campaign titles keep their actual names.

Missing or unavailable public profiles and campaigns return localized 404 metadata.
The SPA leaves detail pages noindex until their record loads and clears canonical,
alternates and structured data when the record is missing.

## Confirmed legacy profile repair (2026-10-16)

The owner confirmed that `/kreatori/suad-asceric-de986b` and its old language
variants belong to the current `/kreatori/suad-asceric` profile. Production was
returning 301s to creator directories for those old URLs. Local history has
different generated suffixes, so this association cannot be reconstructed by
rerunning the general slug migration or matching names.

Migration `Version20261016170000` repairs this exact confirmed association when
the current, non-deleted target profile exists. It changes enabled, generated
directory redirects to the profile, fills missing historical aliases, reserves
the old slug and preserves query settings. Manual rules, disabled rules, other
destinations and unrelated profiles are preserved. It is repeatable and tested
on PostgreSQL and SQLite. No runtime name matching or broad hash-prefix fallback
is introduced. Future URL changes continue to collapse incoming redirects.

The normal deployment applies the repair; it is not a production change until
that deployment runs. Afterward verify both old Bosnian and Croatian URLs return
a single HTTP 301 to `/kreatori/suad-asceric`, then inspect the current URL in
Search Console. Google may continue displaying old URLs/titles until it recrawls.

Validation: the full isolated PostgreSQL backend suite, all frontend tests,
PHPStan, ESLint and the production build passed. A read-only desktop/mobile preview
with demo rows checked selection, confirmation, sorting and fixed action columns.

## Public profile guide URLs (2026-10-28)

Each guide has a public, audience-specific URL. Bosnian paths are:

- `/racun/vodic-za-profil/kreator`
- `/racun/vodic-za-profil/agencija`
- `/racun/vodic-za-profil/brend`

The seven locale maps use `profile-guide-creator`, `profile-guide-agency` and
`profile-guide-brand`. The URL determines the content for guests and signed-in
accounts alike. Audience tabs are ordinary links. Profile strength cards and
profile reminder emails link straight to the matching guide. Canonical URLs,
language alternates, server-readable guide text and both sitemap generators use
these paths. Robots rules allow only the public guide namespace inside otherwise private
account paths. Cached sitemap indexes refresh through the existing sitemap task.

Migration `Version20261028100000` seeds 28 rows in `url_redirect`: the old base
path and its three `?type=creator|agency|brand` URLs in each language. Base URLs
without a supported type go to the creator guide. Old locale prefixes resolve to
their current counterparts. Query-specific rules take precedence over the base
rule; unrelated tracking parameters are discarded by default. Admins can change
or disable these rules in the existing Redirects table. An explicitly disabled
query rule does not fall through to the base rule. HTTP GET/HEAD requests return
301s; SPA navigation uses the same resolver.

Query-bearing sources are permitted only for the retired profile-guide type
selector. Other private account routes and arbitrary query sources remain
protected. Redirect validation follows these specific rules when detecting
loops. The migration preserves existing rules and is repeatable. Rollback removes
only unchanged generated rules. Deploy the migration and API/SPA together.
