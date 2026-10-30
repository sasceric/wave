# Admin collaboration reporting

The admin sidebar's **Saradnje** page (`/admin/saradnje` in Bosnian) reports direct
service inquiries and campaign participation. It is read-only. Its JSON endpoint,
`GET /api/admin/collaborations`, requires a verified administrator. The activity
counts are only added to the existing admin catalogs, never public profile resources.
No schema migration or new background worker is needed.

## Counts and statuses

- A direct inquiry counts once, including inquiries with several selected packages
  or multiple offer revisions. Package titles come from the saved inquiry snapshot.
- Accepting an inquiry opens discussion. Only the latest accepted offer counts as
  an active engagement, and only while the work remains unfinished. Completed
  work is counted separately.
- Active accepted offers display their saved work stage: in progress, ready for
  review or changes requested. The active-engagement filter includes all three.
  Confirmed completion takes precedence over any older work-stage value.
- Individual work completion counts immediately, even if its campaign remains open.
  Finished campaigns also support the existing legacy completion behavior.
- Campaign application counts exclude participants hired through an accepted
  invitation. Active engagement counts include both sources and exclude completed work. The campaign tab can filter
  by application or invitation origin. Pending invitations without an application
  record remain in the existing invitation workflow, outside this report.
- Agencies have separate provider activity and client/owned-campaign activity.
- An agreed amount is the accepted offer amount, not verified payment or revenue.
  Wave does not process payments. No totals combine different currencies.
- Deleted accounts use the existing anonymous label. Message bodies and email
  addresses are not queried or returned.

Click counts in the admin creator, company or campaign table to open the scoped
report. A company's campaign count opens the admin campaign catalog filtered to
that company. Counts are fetched in batches for the visible catalog page.

The detailed report uses server-side search, sorting and pagination (25/50/100).
The three summary shortcuts are disjoint: open requests (pending, discussing or
offered), active engagements (accepted, unfinished work) and completed work.
The page opens on open requests. The status selector can still show the entire
history, including rejected and cancelled records. Catalog package-request and
active-engagement counters use the same definitions; campaign application counts
remain the total submitted applications, linked to the all-status application history.

Date filters apply to the inquiry/application creation date in UTC. Summary cards
respect participant, date, search and origin filters, but ignore the selected status
so they remain useful shortcuts. API responses are private and not cacheable.

Implementation: `src/Admin/Collaborations.php`,
`src/Controller/Api/AdminCollaborationsController.php`, `src/Api/AdminCatalog.php`,
`frontend/src/views/AdminCollaborationsView.vue` and
`frontend/src/components/admin/AdminCollaborationCounts.vue`.

## Dashboard charts

The existing admin overview uses Chart.js 4 (MIT) for its activity bar chart,
campaign-status doughnut and cumulative growth lines. The selective registration
module is `frontend/src/lib/adminCharts.js`; `AdminChart.vue` owns chart lifecycle,
responsive sizing and reduced-motion support. The component is imported through
lazy admin views. Existing accessible data tables remain available alongside charts.
No external chart service receives data.

## Validation

`AdminCollaborationsTest.php` covers administrator access, private responses,
status/count semantics, multiple packages and offer revisions, open-campaign work
completion, agency roles, invitation origin, dates, pagination and public resource
exclusion. `frontend/tests/adminCollaborations.test.js` covers account changes,
stale requests, query filters, API failures and chart cleanup/fallback.
