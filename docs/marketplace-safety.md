# Marketplace reports and personal blocks

Verified, approved participants can report creator profiles, company profiles or
campaigns using two levels from `config/report_reasons.json`: a category and a
specific reason. “Other” requires a written explanation. Optional screenshots use
the private `report-evidence` media folder: at most three JPEG/PNG/WebP images,
10 MB each. Existing upload validation, decoded-memory guards and WebP compression
apply; only this folder uses a 1600px cap for screenshot readability. Reporters and
admins can access evidence; the reported account cannot. Linked evidence cannot
be deleted independently of its report, and account deletion removes owned files
through the existing cleanup workflow.

Reports do not automatically suspend accounts or delete content. An administrator
reviews the report, selects In review, Resolved or Dismissed, and writes an outcome
message for final decisions. Record versions reject concurrent or stale updates.
Duplicate open reports by the same reporter/target are prevented, and submissions
are capped at ten per hour. Receipt and admin updates persist as private notices,
ordered by report version, until the reporter acknowledges their modal. There are
no report emails, bell entries or device pushes. Private Mercure events prompt a
refresh; login/focus recovers missed updates without periodic polling.

## Administration and interface

The account sidebar includes My reviews and a personal blocked-account list for
creators, companies and admins. Reviews pair received and given feedback in one row
per collaboration. Received ratings and comments stay hidden until both parties
review or the 14-day publication window expires. Only the signed-in account's
collaborations are returned; admins do not receive a global review or block list.
The personal tables use the existing search field, sorting, pagination, rows-per-page
controls and fixed three-dot actions. Blocks can only be removed by the account
that initiated them; a reciprocal block still restricts both sides.

Content/account reports remain admin-only. Report moderation retains its authorized
visibility and resolution controls.
Public reviews use numbered pages of ten, retaining the older ID cursor API for
compatibility. Existing double-blind publication and aggregate conditions remain.
The refreshed cards use stars, initials, campaign context and localized dates.

## Block semantics and access

A personal block hides the two accounts from each other in authenticated public
profiles, directories, homepage recommendations, company campaigns and bookmarks.
Either direction restricts both sides. Existing applications, offers, invitations,
inquiries and chats are retained as anonymous read-only history. Public profile
links, images and campaign metadata are removed from that history; agreed financial
amounts and past message text remain. Blocking does not change accepted offers,
ratings, campaign status or private collaboration totals.

`BlockPolicy` centralizes pair checks and directory predicates. `BlockSubscriber`
checks direct profile/media access and mutation routes, including applications,
offers, invitations, inquiry decisions and messages. It redacts nested history
resources for both creator account pages and company applicant lists. The APIs
preserve original participant/ownership/CSRF checks. Authenticated JSON responses
are private/no-store; the SPA clears view state when the account or block changes.
Queued realtime events, pushes and unread reminder emails check current blocks
before delivery. Blocked chat history does not contribute unread counts.

Admin moderation lists retain full authorized visibility. Personal blocks apply to
signed-in account views; they are not platform-wide account suspensions or removal
of publicly accessible content. Reporter identity is never sent to the reported
account through this module.

## Routes and schema

- POST `/api/me/safety/{creator|company|campaign}/{slug}/report`.
- POST `/api/me/safety/{creator|company|campaign}/{slug}/block` (campaign blocks its company).
- GET `/api/me/safety/blocks`, DELETE `/api/me/safety/blocks/{id}`.
- GET `/api/me/safety/notices`, POST `/api/me/safety/notices/{id}/read`.
- GET `/api/me/safety/reviews`, GET `/api/admin/safety/reports`.
- PUT `/api/admin/safety/reports/{id}`.

Migration `Version20261013130000` adds `marketplace_report`, its evidence relation,
`report_notice` and `user_block`. It is applied locally and verified on PostgreSQL
and SQLite. Existing email and realtime workers remain sufficient; no new Mercure
configuration or environment variable is needed. Nothing has been pushed or deployed.

Validation of the account-list correction: the full backend suite passed (287 tests,
274,749 assertions), followed by nine focused safety/migration tests (122 assertions)
after the final publication refinements. All 289 frontend tests, PHPStan, ESLint and
the production build passed. Desktop and mobile fixtures verify paired review rows,
personal block navigation, fixed actions and the shared search field. Screenshots
are under `output/safety-preview/`; fixture API requests never reached the backend.
