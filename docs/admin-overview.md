# Admin overview and account loading

`AdminOverviewAnalytics.vue` displays the operational overview under Pregled.
GET `/api/admin/overview?days=7|30|90` requires a verified administrator and returns
private, uncached aggregate data. `Admin/OverviewStatistics` groups database rows
without hydrating profiles, applications or conversations. Dates are UTC and all
missing days are filled with zero. No migration, extra worker or external chart
library is required.

The snapshot includes:

- Current pending account approvals, unresolved reports and open support tickets.
- Daily new creator profiles and published campaigns, excluding deleted creator
  accounts. Company signup dates are not stored, so no company growth is invented.
- Applications and invitations sent in the selected period.
- Accepted collaboration offers confirmed in the period, excluding canceled work.
- Campaigns finished in the period using their completion timestamps.
- Campaigns published in the period grouped by their current status. This is a
  current distribution, not a historical cancellation/completion timeline.
- The five latest campaigns across all dates, linked to filtered admin lists.

The overview uses neutral metric cards, grouped applications/invitations bars,
a campaign-status donut, cumulative growth curves and a forest-green admin work
card. Bars use at most eight consecutive date buckets while retaining exact
original totals; cumulative curves avoid negative counts and invented peaks.
Charts have text summaries, keyboard tooltips and expandable daily tables. Dates use DD.MM.YYYY;
all interface copy exists in the six locale catalogs. Period/account changes and
unmounting invalidate old requests. Admin permission loss clears the snapshot.

`AccountView` now fetches only the opened marketplace section. The profile and new
campaign form don't fetch campaign activity, bookmarks or inquiries. The company
campaign list no longer fetches applicants for every campaign; campaign details
fetch only their own applicant list. Creator applications still combine applications,
offers and invitations. Bookmarks load on their page or when opening a relevant
brief. Section navigation preserves profile drafts while guarding stale responses;
authentication and mutations still use existing API/access checks. Simultaneous
session checks share an in-flight request only, separated by locale and invalidated
by authentication mutations; session responses are never cached.

The company campaign endpoint now fetches scalar campaign/creator identity pairs
instead of hydrating all applications and invitation messages just to build ID lists.

Validation: relevant backend suite (44 tests, 1,128 assertions), frontend regressions,
PHPStan, ESLint and production build passed. Desktop/mobile demo fixtures verified
period controls, responsive charts and readable daily data. Production latency has
not been measured; the improvement comes from removing unnecessary requests and
entity hydration. Changes remain local.

The design uses the Wave primary, accent, background, surface, secondary and text
tokens, with existing neutral card backgrounds. Frontend validation after the
redesign: 312 tests, ESLint and the production build passed.
