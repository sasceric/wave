# Account activation and campaign alerts

## Existing data and account fields

The shorter signup collects account type, name, email and an eight-character minimum password. Google/Apple completion requires account type and name; the provider supplies the verified email. Previous complete registration payloads are still accepted. Contact information, categories/industries, company type, birthday and every existing profile tab remain in the account editor. No profile data is removed or rewritten by the migration.

The registration checkbox controls only the newsletter and starts unchecked.
Password/social signup default an omitted `newsletter` field to false and accept
only boolean choices. Profile completion tips remain enabled independently and
can be disabled later in email settings or through their unsubscribe link.
Existing preferences are preserved. See the [legal/product review](legal/review-2026-10-26.md)
for the consent and legal-document gaps that still need policy review.

Login lands on `account-dashboard`, including incomplete accounts. Their overview shows email verification, contact/location and identity/category completion. Links open the existing editor with `tab` and a one-use `edit=1` query. The required-fields modal can return to the overview; marketplace routes and server approval/verification checks still apply. Portfolio, offers and audience data remain optional. Social links, a bio/description and a profile photo/logo are requested by the manual pre-approval review policy; they are not new signup fields or an automatic approval gate. The existing admin approval process automatically reviews eligible accounts; there is no extra submission endpoint or fake submit action.

Approved accounts see real activity/financial/review/credit statistics and a next action. The visibility switch calls the existing CSRF-protected visibility endpoint. Individual and bulk admin approval make newly approved creator and company profiles public automatically. Email verification and required profile completion remain prerequisites. Owners can hide their profiles afterward; repeating approval for an already approved account preserves that later visibility choice. Existing approved profiles are not republished by a backfill.

Pending accounts no longer have a manual “Check status” button. The activation
panel refreshes the session on the existing inbox-resume event, triggered when
the app regains focus, reconnects or receives a push. It removes its listener on
unmount; there is no polling timer or additional submission action.

### Verification notice and manual reminders

Creator, company, campaign and approval admin lists include a sortable Created
column, with newest-first sorting by default and unknown dates at the end.
It shows full relative time for records less than 24 hours old, then the local
date and 24-hour time (`22.10.2026 u 14:13` in Bosnian). Creator dates use their
stored creation timestamp, campaigns use their publication timestamp, and
company/approval dates use the recorded `registered`
account milestone. Historic records without a recorded timestamp show a dash.
No dates are inferred from IDs or replaced with deployment time.

Signed-in accounts with `emailVerified=false` see a persistent, non-dismissible
notice above the page content on every route. It shows their address, reminds
them to check Other/Promotions/Spam, and offers the existing CSRF-protected resend
action. Sending another email does not hide the notice. It disappears only when
the session endpoint confirms verification, including after returning from the
email app. The activation checklist shares the same resend composable, which
coalesces simultaneous sends and discards responses after an account switch.

Approvals row actions include **Remind to verify email** for unverified accounts,
**Send pre-approval checklist** for pending accounts and **Send profile tip**.
Creator and company lists also offer the pre-approval checklist for pending account
owners. It explains that social links, a bio/description and a profile photo/logo
are needed before manual approval; it leaves approval and verification untouched.
As requested, administrators keep control of approval rather than adding a new
automatic content gate. Creator and company lists also
offer **Send profile tip** for their linked account owners, including approved
accounts. Profiles without an account cannot receive a reminder. Both use the
existing confirmation modal, showing the destination before sending. The modal links to the corresponding
editable email template. These are manual actions, not an automated email campaign.

`POST /api/admin/registrations/{id}/reminder` accepts `kind=verification|profile|pre_approval`,
requires an administrator session and CSRF, and rejects deleted, staff,
or otherwise ineligible recipients. Verification reminders additionally reject
approved or already verified accounts; pre-approval checklists reject approved accounts.
Profile tips also support approved accounts.
Each recipient/type is limited to one send
per 24 hours through `wave_registration_reminder`. Verification also shares the
normal per-address resend limiter. A verification reminder issues a new single-use
verification token without an expiry, preserving registration and resend links.
Using any one invalidates all verification links for that account. Password resets
still expire after one hour and replace earlier reset links. Security-token
metadata suppresses queued emails once their link is invalidated by successful
verification. Token cleanup retains verification links. A profile tip cannot
verify or approve an account and does not issue a token.

The `pre_approval`, `profile_tip` and `registered` templates use the responsive
`profile_preparation.html` layout: a three-step photo/bio/social checklist and a
clearly labelled 100% completion goal. The registration-pending and profile-tip
copy encourages building a useful portfolio without promising jobs or automatic
approval. The pre-approval copy states the manual review requirements explicitly.
All seven languages are editable in the existing email editor, including checklist
headings and text. Saved subject/HTML/copy customizations remain authoritative.
Plain-text emails include the same checklist and requirements. Verification
reminders keep `account_action.html` and their existing security-token behavior.
All use escaped variables, `recipientName` and the recipient's saved language.
Sending uses the existing mail queue. Migration `Version20261022140000` makes
token expiry nullable and removes the expiry from retained verification links
for unverified, active accounts. Already
deleted tokens cannot be recovered. Password-reset rows retain their expiry.
Apply the migration and restart existing workers with the normal deployment; no
additional worker, scheduler or SMTP credential is required. Existing custom
verification templates should be checked for hardcoded 24-hour expiry wording.

See [email delivery](email-delivery.md) for Focused/Other sorting and authentication
checks. Neither reminder delivery nor inbox placement is guaranteed by queueing.

### Returning an account to pending approval

Approved creator and company rows offer **Return to pending approval**. The admin
confirms the account and recipient before POST `/api/admin/registrations/{id}/unapprove`.
The endpoint requires an administrator and CSRF, rejects staff/deleted accounts,
locks the user row and changes `approved=false` and `hide_my_account=true`.
The profile then appears in Approvals instead of the approved catalog. Existing
profile data, email verification, slugs and shared collaboration history remain.
Normal approval can publish the profile again after the administrator reviews it.

The `approval_revoked` template is editable in the email editor in all seven locales.
It explains the return to pending and asks for photo/logo, bio, social profiles and
creator types; company accounts receive a company-type tip instead. A repeat call
on an already pending account is a no-op and does not send another email. The
approval change and production mail job are transactional; a transport failure
rolls the change back. No account is automatically reapproved based on completion.

### Plain profile names

`PlainProfileName` applies Unicode NFKC to creator and company names. This converts
pasted mathematical bold/italic font variants into normal letters while keeping
real accents and scripts. Registration, OAuth creation and profile edits normalize
before validation/storage. Profile inputs normalize on blur, and entity getters
also display historical names plainly before the migration runs.

Migration `Version20261030150000` normalizes existing creator/company names without
changing IDs, slugs, or URLs. It skips unusually expanding values over the existing
120-character storage limit instead of truncating them. It does not reconstruct
previous decorative variants on rollback. Apply it through the normal deploy;
refresh the search index through the existing indexing workflow after data backfills.

## Opt-in campaign emails

`GET/PUT /api/me/campaign-alerts` is creator-only, owner-scoped and non-cacheable. PUT requires CSRF and validates category values, country codes, daily/weekly frequency and an explicit boolean opt-in. No preference row means disabled. These emails are independently opted into, separate from device notifications.

`CampaignAlertTask` runs hourly through the existing scheduler, scanning at most 100 due preferences per batch. `CampaignAlertEmailMessage` uses the mail queue. Delivery locks and rereads preferences, verifies account eligibility and checks both directions of blocking, public company visibility, campaign status/deadline and existing applications. Empty windows send no email. Digests contain at most five new matching campaigns, never older campaigns from before opt-in or a scope change. Delivery advances the window only after successful delivery or a no-match/ineligible check. A repeated completed message does not resend its window.

The six localized email copies use `templates/emails/campaign_alert.html`, including campaign links and a link back to alert settings in Pregled. Failed transport sends roll back the window for normal queue retry. Like other SMTP sends, a crash after provider acceptance but before committing the delivery window can cause a retry; SMTP does not provide exactly-once delivery.

## Admin account funnel

The Creators, Companies and Approvals tables also show each profile's current
saved sections, with counts for networks, services, portfolio work, packages and
FAQs where applicable. Required contact/identity, description and image fields
are marked separately. These describe saved data, not approval eligibility or
verification of self-reported metrics. A shared 96px thumbnail appears beside the
name, with the existing image placeholder when absent or unavailable.

The row action menu includes **Review submitted data**, public-profile preview,
hide/show, approval where applicable, and deletion. Review fetches one private,
admin-only detail response on demand, reusing the account serializers with private
portfolio items included; passwords/tokens are never returned. The dialog clears
on close/account change and ignores stale requests. Ownerless imports can be
reviewed but cannot use account visibility controls. Agency participants remain
excluded from the creator list.

Admin visibility writes require the existing session, admin role and CSRF token.
Hiding/showing changes only the account's existing visibility preference, so the
usual indexing/visibility listeners apply. Showing requires approval, verified
email and the required profile fields; it cannot approve an account implicitly.
New approval still publishes the profile as documented above. No migration,
new tracking writes or background service is required for these admin controls.

The admin overview adds an account-type filter and a registration-cohort funnel for the selected 7/30/90-day period. A Doctrine flush listener records each reached milestone once: registered, email verified, required profile complete, approved, first creator application/company campaign. The display uses nested completed stages, so percentages always represent conversion from the previous stage in the same cohort.

The filter includes All, Creators, Companies, Brands and Agencies. Companies
includes both brands and agencies; an agency is one company account even after
its private service participant is created. The company registration breakdown
uses the same real-account registration cohort, with current saved company type
(switching type moves its classification without adding another registration).
An agency's first application or published campaign reaches `first_action`
once per account, even when it performs both actions.

Measurement starts when this code is deployed. Existing accounts are not backfilled with invented registration dates. Demo, staff and deleted accounts are excluded. No page visits, device tracking or abandoned-form counts are inferred. Stage records reference the account with cascading deletion, without storing names, emails or browser identifiers.

The separate **Creator profile completion** section reads current saved profiles,
including accounts created before milestone measurement. It shows counts and
percentages with at least one social network, portfolio work or package, plus
accounts with all three. These match the optional setup checklist, including
saved private portfolio work and negotiable packages; they do not assert that
audience statistics are verified or that content is publicly visible. Removing
the saved items reduces the current count. All real creator accounts are in the
denominator, including pending and hidden accounts; demo, staff, deleted and
ownerless imported records are excluded. The section is explicitly independent
of the period/account-type filter used by the registration funnel. One aggregate
query runs as part of the existing admin overview request; no migration, event
tracking writes, browser tracking requests or new background task is needed.

The separate **Agency profile completion** section counts saved services, work
samples and packages, plus agencies with all three. Its denominator includes all
real company accounts currently set to Agency, including agencies without a
service participant or any optional content. Hidden and pending agencies count;
demo, staff and deleted accounts do not. Switching back to Brand removes the
account from this current agency report without deleting its studio data.
Creator completion excludes agency participants. Both completion sections are
independent of the registration period and filter and do not change approval
requirements. Agency completion adds one aggregate query to the existing admin
request, with no new tables, tracking writes or client requests.

## Deployment and verification

Migration `Version20261016120000` creates only `campaign_alert_preference` and `account_milestone`. The normal production workflow migrates and registers scheduled tasks before restarting workers. Manual deployments must also run `app:scheduled-tasks register` and maintain the existing scheduler/background/mail workers. No new external service is needed.

Relevant tests: `AccountActivationTest`, `OAuthRegistrationFlowTest`, `EmailAccountFlowTest`, `CampaignReviewsTest`, `AdminToolsControllerTest`, `BackgroundPipelineTest`, and frontend `accountActivation`, `accountRegistration`, `personalOverview`, `adminOverview`, `accountProfile` suites. Database-backed queue tests require an isolated PostgreSQL test database; the checked-in SQLite environment cannot execute PostgreSQL advisory locks.
