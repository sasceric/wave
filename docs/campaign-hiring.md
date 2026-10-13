# Campaign invitations and confirmed hiring

Company campaign creator lists combine applications and invitations. Invitations
without applications use virtual rows and show Invited, with the accepted invitation's
conversation link. Once an offer creates an application, its row replaces the invitation
row, avoiding duplicates. Declined invitations are shown separately; they do not prevent
a later voluntary application. Pending and accepted invitations cannot be bypassed through
the public application API.

An accepted invitation permits discussion without an application or application-credit
charge. The company uses Hire in the conversation or invited creator details to confirm
the negotiated amount and send an offer. Amounts can be outside the advertised budget,
within the existing positive whole-unit amount storage limit (1–10,000,000).
The creator reviews that exact amount and explicitly accepts before becoming hired.
Existing offer notifications and the creator-hired email remain in use. Campaign row
locks serialize offer creation and acceptance; creator capacity, ownership, CSRF,
verification, approval, deletion and personal block checks remain enforced.

Trusted offer_sent, hired and offer_declined chat events use CampaignMessage.event JSON
metadata. The normal message POST cannot set event metadata. Events persist their amount,
currency and campaign snapshots and offer ID. The client renders localized event text and
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
Compact accept/reject controls confirm the actual offer amount before responding.
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
No additional migration is required for these string statuses.

Validation: 293 backend tests (274,894 assertions), 306 frontend tests, PHPStan,
ESLint and production build passed. Desktop/mobile mock fixtures verified the
unified list, finished reviews, negotiated acceptance and cancellation; Hire and
View campaign share identical 43px button styling. Screenshots are in
`output/hiring-preview/`.
