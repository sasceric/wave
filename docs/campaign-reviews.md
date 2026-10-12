# Campaign reviews and private collaboration totals

Creators and companies can leave one review per accepted campaign collaboration
when the company marks the campaign `finished`. Shortlisting or accepting an
invitation does not qualify: the application must have an accepted offer.

Feedback consists of 1–5 whole stars and 10–2,000 characters of plain text.
The window lasts 14 days from `Campaign.finishedAt`. Reviews are immutable and
remain hidden until both participants submit or the window expires. Both the
public list and average use the same publication condition, so an unpublished
score cannot be inferred from the average or review count. Publication is evaluated
at read time; there is no new scheduled job or Mercure setup.

This follows the basic double-blind publication pattern described in
[Upwork's end-of-contract feedback documentation](https://support.upwork.com/hc/en-us/articles/211062188-How-to-leave-an-end-of-contract-review-for-your-freelancer).
Wave uses a simple overall rating; it does not implement Upwork's private feedback,
job-success score, feedback revision, or payment processing.

## Interface

- Creator applications and offers expose the review modal for finished, accepted
  work. Companies review accepted creators from the campaign applicants table.
- Public creator and company profiles show their average, number of completed
  campaigns, and a paginated list of published reviews. Directory cards show the
  average and review count. Empty averages display a dash rather than a fabricated
  rating. A company's completed campaign count is distinct campaigns, even if
  several creators participated.
- The private account summary shows creator earnings or company spending. The
  admin creator/company tables also show ratings and financial totals. Public
  profile and directory responses never contain financial totals.
- Totals are sums of accepted offer amounts on finished campaigns, grouped by
  currency. They are agreed amounts, not proof of payment or a payment ledger;
  no currency conversion is performed. Wave does not collect or verify payments.
- The account summary includes a password-change modal requiring current password,
  a different new password of at least 12 characters, and matching confirmation.
  Social-only users can email themselves a password recovery link first.

## Access and API

`CollaborationFeedback` centralizes eligibility, publication, aggregates and
resources. Reviews contain foreign keys to application, author and recipient.
The application/author unique constraint and application row lock prevent duplicate
or conflicting submissions. POST requires the existing CSRF token and an approved,
verified participant who owns the creator or company in that application. Deleted
participants cannot submit reviews. Review bodies are rendered as escaped text.

- GET/POST `/api/me/applications/{id}/review`: own feedback and published counterpart
  feedback; POST validates and submits. Another participant's hidden feedback is
  never returned.
- GET `/api/creators/{slug}/reviews`, `/api/companies/{slug}/reviews`: ten records
  with an ID `before` cursor and public summary. Existing profile visibility rules
  apply to both endpoints.
- GET `/api/me/collaboration-summary`: authenticated owner's financial totals and
  rating summary, with `private, no-store` and an owner ID to discard responses
  for a different session. Admin aggregate columns use the existing admin-only
  catalog endpoint and batch queries.
- PUT `/api/me/password`: current/new/confirmation password fields, CSRF protection,
  five attempts per account per 15 minutes, and password hashing through Symfony.
  Successful changes revoke password-reset tokens, renew the current login/CSRF,
  and invalidate other sessions on their next security refresh. Approval and email
  verification state are unchanged.

Finishing is permanent, preserving the feedback deadline and completed totals.
The company cannot change the denomination after an offer is accepted. Other
campaign details remain editable through the existing editor. Account deletion
keeps anonymous numerical shared history, erases the deleted author's review
comment, and resolves their name to the existing deleted-account label.

## Migration and validation

Migration `Version20261013100000` adds `campaign.finished_at` and `campaign_review`
with indexes, foreign keys and a 1–5 rating constraint. Existing finished campaigns
receive the deployment timestamp, giving their accepted participants a full initial
feedback window. Apply this migration before serving the new application build.
The migration is applied to the local development database. Production uses the
normal Doctrine migration step; this work has not been pushed or deployed.

Regression coverage includes two-way and expired publication, duplicate and
unauthorized submissions, score validation, cursor paging, hidden score aggregates,
private totals across currencies, completion/currency locks, password validation,
reset token revocation and session continuity. Migration tests execute on both
SQLite and isolated PostgreSQL. Frontend tests cover draft handling, duplicate
submissions, stale account responses and password field cleanup.

Review submission creates a private notification for the other participant. The notification opens the exact collaboration’s review dialog from the company campaign detail or creator applications page; it does not include the hidden score or comment. Once the counterpart has already reviewed, the notification explains that both reviews are public. Migration `Version20261013120000` adds this collaboration link. Company campaign actions offer Close and Finish with confirmation; closing stops applications, while finishing starts the review deadline permanently. Applicant biographies use the shared sanitized rich-text renderer.
