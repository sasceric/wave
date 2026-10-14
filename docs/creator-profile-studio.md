# Creator profile editor and presentation

The account keeps its existing read-only view and Edit actions. Its creator tabs
are Basic information, Networks and audience, Selected work, Packages, FAQ and
Profile presentation. There is no separate collaborations tab. Existing reviews
remain under My reviews and on the public profile, with 10 published reviews per
page and the existing publication/eligibility rules.

The public profile has breadcrumbs and two top actions: a public presentation
preview and a campaign-invitation modal. The presentation only uses the displayed
creator's public data, never the viewer's private account summary. Invitation
campaigns load when the dialog opens; guests receive a sign-in action and creator
accounts receive the company-only explanation. Existing verified/approved company,
profile-readiness and campaign eligibility checks remain in force. Package requests
stay separate. Section links scroll to their content, and overflowing section links support horizontal scrolling without widening the
page. Audience selectors include platform icons. Packages are displayed together
without platform-filter tabs. Explicit availability is shown as a prominent pill
with a status dot; unspecified availability is not advertised as available.

## Stored fields

`creator.presentation` contains optional content languages, availability, a
conditional availability date and an introduction-video HTTPS link. An unspecified
availability is not advertised as available. These fields do not add approval or
profile-completion requirements.

Each social profile may contain followers, average views/plays/live viewers,
sample size, engagement percentage and calculation basis, up to five unique
audience countries and percentages, and the actual measurement date. Unknown
figures remain null. Editing a biography does not refresh the measurement date.
The statistics are explicitly self-reported. There are no statistics screenshots
or evidence uploads.

Each of up to four work entries supports a summary, source (Wave, external or
sample), eligible finished campaign, client and agency, brief, contribution,
deliverables, project date, original-post link, numerical results with period and
measurement date, one featured position, and public-display permission. Samples
cannot claim a client or a Wave campaign. Wave references require the creator's
own accepted offer on a finished campaign; they cannot inflate review totals.
The campaign picker excludes blocked and deleted counterparties.

Work uses existing compressed image uploads and YouTube/Vimeo video links. A
video may have an owned cover image; a work may have four additional images or
video links. Every image must belong to the creator and the portfolio folder.
Draft work is excluded from public JSON and its media files are private unless
the same file is also referenced by a public work. Replacing media associations
uses a transaction so repeated saves/reordering do not conflict with unique
portfolio positions. Removing an entry does not delete unrelated library files.

Packages additionally support content-only versus content-and-posting, fixed/from/
negotiated pricing, structured deliverables, delivery days, included revisions,
organic/advertising/agreed usage and conditional advertising duration. Wave does
not process payments. Existing private earnings and spending calculations remain
unchanged.

The presentation tab uses a two-column options/paper layout on desktop and stacks
on mobile. It reuses saved public fields, shared switches and the existing
multiselect, defaults to two public works and opens the same paper in a preview
modal. The modal opens the browser print dialog for printing or saving a PDF.
Network editors select one network at a time; package editors start with a compact
list and open one package. Validation reveals any hidden invalid network or
package before focusing its control. Public introduction fields are grouped
together, with birthday and telephone in a separate private-details card. It excludes
private email, phone, birthday and earnings. It is not a separate stored document
or a server-side PDF service.

## Statistics and migration

Apply `Version20261014090000` with the normal migration command. It adds the JSON
settings and `creator_profile_visit`, a daily counter without visitor identities
or IP addresses. Visits start accumulating after installation; there is no
historical backfill. Own visits and admin visits are excluded, and a session is
counted at most once per profile per UTC day. The public frontend records a visit
with a deferred, CSRF-protected POST after loading a visible profile.

`GET /api/me/profile-statistics?days=7|30|90` only returns the current creator's
views, inquiries, invitations and confirmed engagements. Both API responses and
frontend state are scoped to the current account. Account deletion removes visit
counts and clears presentation fields.

## Components and validation

The editor reuses SwitchField, MultiSelect, SingleSelect, SearchableSelect,
DatePicker, MediaUploadField, TagInput, AccountProfileCard and FormModal. Dates
remain ISO values in the API and `dd.MM.yyyy` in the interface. Copy is present in
all six locale catalogs. `CreatorPresentation` validates and whitelists extended
JSON; client-supplied verification flags and proof fields are discarded.

Relevant tests are `CreatorPresentationTest`, `CreatorPresentationProfileTest`,
the historical migration compatibility tests and
`frontend/tests/creatorPresentation.test.js`.
