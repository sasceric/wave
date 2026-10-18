# Campaign invitations and confirmed hiring

Public campaign cards (including home, company profiles and saved campaigns) and
campaign details show proposal ranges: fewer than 5, up to 10, 10–20, 20–50 and
50+. Boundaries are 0–4, 5–10, 11–20, 21–50 and 51+. The API only exposes a range
key, never an exact public application count. `campaign.application_count` stores the
full total, initialized to zero and incremented atomically by one in the same
transaction as each successful submission. Public resources derive their range from
this column; no application-count query or extra browser request runs on page loads.
Rejected/canceled applications remain historical submissions. Invitations and offer
applications created from invitations do not increment it. A declined invitation
followed by a voluntary application counts normally. Duplicate or failed submissions
cannot increase the total. The showcase upsert increments only for newly inserted
applications. The column is excluded from ORM updates to prevent stale campaign
edits overwriting concurrent increments.

Migration `Version20261016150000` adds the column and backfills existing genuine
applications once (including rejected applications and excluding invitation-based
hires). Run migrations during deployment before serving the new backend. The owner's
full applicant list is unchanged; a successful application response also refreshes the
displayed range.

Company campaign creator lists combine applications and invitations. Invitations
without applications use virtual rows and show Invited, with the accepted invitation's
conversation link. Once an offer creates an application, its row replaces the invitation
row, avoiding duplicates. Declined invitations are shown separately; they do not prevent
a later voluntary application. Pending and accepted invitations cannot be bypassed through
the public application API.

Accepting an application only sets it to `shortlisted` and opens the campaign chat.
The applicant dialog has no price form. An accepted invitation likewise permits discussion
without an application or application-credit charge. After negotiation, the company uses
Hire in the conversation sidebar to send the agreed amount, delivery deadline and message. Amounts can be outside the advertised budget,
within the existing positive whole-unit amount storage limit (1–10,000,000).
The delivery deadline is a required ISO date (`YYYY-MM-DD`), today or later, on both
POST `/api/company/conversations/{id}/hire` and the retained application-offer API.
The creator reviews the exact amount and deadline and explicitly accepts before becoming
hired. An overdue dated offer cannot be accepted; reject it and agree on revised terms.
Legacy offers with no deadline remain readable and can still be answered.

Declining an offer returns the application to discussion (`shortlisted`) and leaves both
participants able to message. The company may send another offer. Each revision has its
own ID, amount, deadline, response and message; old declined offers cannot be accepted or
changed. Pending, accepted and canceled offers cannot be replaced. Only the current offer
is returned in the account offer list and application resource; historical snapshots remain
in the chat. Financial totals continue counting accepted offers only.

Migration `Version20261018200000` adds nullable `campaign_offer.due_date`, changes offers
to many per application, and adds `campaign_application.offer_id` for the current terms.
It backfills that pointer and returns legacy `offer_declined` applications to discussion,
without changing old offer amounts or responses. The migration intentionally has no
lossy rollback: restore a backup if the old one-offer schema must be recovered. Deploy the
migration before the new API. Chat access also recognizes legacy `offer_declined` statuses.
Existing offer notifications and the creator-hired email remain in use. Campaign row
locks serialize offer creation and acceptance; creator capacity, ownership, CSRF,
verification, approval, deletion and personal block checks remain enforced.

Trusted offer_sent, hired and offer_declined chat events use CampaignMessage.event JSON
metadata. The normal message POST cannot set event metadata. Events persist their amount,
currency, delivery deadline, offer message (for offer_sent), campaign snapshots and offer ID. The client renders localized event text and
campaign links; company links open the private campaign management page, creator links
open the public campaign. Blocked history masks event campaign identity and links.
The chat campaign brief uses the existing sanitized RichTextContent component.

Migration Version20261013150000 adds one nullable event column and preserves existing
messages. PostgreSQL and SQLite up/down tests passed; the migration is applied locally.
No deployment or push was performed.

## Unified creator campaign activity

Creators use Moje prijave for applications, invitations, offers and hired work.
`campaignActivity.js` joins these account-scoped records by campaign ID, with the
application owning the offer and the invitation supplying campaigns without an
application. The original IDs are retained for responses, cancellation and reviews.
Old localized offer/invitation paths redirect within the SPA to applications,
preserving query parameters. Notifications and pushes target this unified list.
Direct service inquiries remain separate.

Statuses describe the current collaboration stage: Applied, Invited, In discussion,
Offer awaiting response, Hired, Finished, Rejected, Closed or Canceled. An accepted
invitation is never treated as a hire. Finished accepted work shows Finished and
keeps its review action. A closed campaign with an active hire still shows Hired;
closing applications alone does not complete the work. Agreed amounts appear in
the compensation column; campaigns without offers show the advertised range.
Compact accept/reject controls confirm the actual offer amount and delivery deadline before responding. Declining an offer returns the row to In discussion.
The shared three-dot menu includes View, Chat, Review and Cancel as appropriate.

## Cancellation

The company may cancel its campaign before completion through its confirmed status
action. Either participant may cancel their accepted collaboration through
POST `/api/me/offers/{id}/cancel`. Ownership, CSRF, approval, email verification,
deleted-account and personal-block checks apply. Campaign row locks serialize
cancellation against offer creation and acceptance. Repeated cancellations are
idempotent. Finished and canceled campaigns remain terminal.

Whole-campaign cancellation cancels applications, active/pending offers and active
invitations. Canceling an individual collaboration affects only its application and
offer and frees the hiring place. Stored agreed amounts remain available in history;
financial totals include only accepted offers on finished campaigns, so canceled
work contributes nothing. Canceled work cannot receive campaign reviews. Trusted
cancellation events appear in retained read-only chats and notify the counterpart.
An incoming event immediately disables the composer while metadata refreshes.
Cancellation requires a trimmed 10–1,000 character reason, retained on the campaign
or individual offer and in the trusted chat event. Invalid reasons leave the collaboration
unchanged; repeated cancellation does not overwrite its reason. Migration
`Version20261018210000` adds nullable reason columns, preserving legacy history. The chat
sidebar reuses the cancellation form with a full-width red outline action.

Validation: 293 backend tests (274,894 assertions), 306 frontend tests, PHPStan,
ESLint and production build passed. Desktop/mobile mock fixtures verified the
unified list, finished reviews, negotiated acceptance and cancellation; Hire and
View campaign share identical 43px button styling. Screenshots are in
`output/hiring-preview/`.

## Negotiation workflow validation (2026-10-18)

The PostgreSQL regression covers application acceptance without an offer, both participants
chatting after a decline (including legacy declined states), a new offer ID for revised
terms, rejection of stale/duplicate offers and unauthorized participants, exact delivery
deadline snapshots, and hired counts increasing only after confirmation. Agency coverage
checks that revisions do not duplicate service application or offer dashboard counts. A
transaction-local PostgreSQL migration test verifies backfill and cascading historical
offer deletion. The full suite passes 388 backend tests (277,426 assertions; the optional
Meilisearch integration test is skipped without an isolated instance), and 410 frontend
tests. PHPStan, ESLint and production build pass. The shared hiring form uses the account
`DatePicker` and `FormModal`; the modal preserves the datepicker's icon padding and inline
required marker. `CampaignHiringProgress` owns its compact 12px default typography.

## Direct collaboration inquiries

The initial public-profile inquiry asks for packages and a message, without a proposed
amount or currency. Accepting the inquiry opens discussion. Only the client company can
issue formal terms from its chat sidebar, using the same hiring form, datepicker and
cancellation component as campaign chat. The provider explicitly accepts or declines.
Declining keeps discussion open, and a revised offer gets a new ID. Cancellation requires
a reason and makes both participants' conversation read-only while preserving history.

`InquiryHiringController` provides POST `/api/company/inquiries/{id}/hire`,
`/api/me/inquiry-offers/{id}/respond` and `/api/me/inquiry-offers/{id}/cancel`.
Migration `Version20261018220000` adds offer history, the current-offer pointer and trusted
inquiry-message event metadata. Run both new migrations before serving the new API.
Agency providers retain their existing company identity. Ownership, latest-offer checks,
CSRF, approval, verified email, deletion, personal blocks and inquiry row locks apply.
Pending/active direct engagements are included in private overview counts. Direct
inquiries do not create campaign hires, completed campaign earnings or campaign reviews.

## Repeatable browser regression

See [browser regression setup](browser-tests.md). `npm --prefix frontend run test:browser`
uses real Chrome and WebKit sessions, Symfony and a private Mercure hub without mocked
API responses. It checks campaign acceptance without a price, two-way live chat, declined
and revised offers, stale-offer rejection, confirmed hiring totals, mandatory cancellation
reasons and read-only history. It repeats negotiation/hiring/cancellation for a direct
inquiry through the same shared UI, and checks calendar text/required-marker spacing and
sidebar count sizing. This is Chrome plus the Safari engine, not automation of native Safari.
