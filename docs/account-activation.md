# Account activation and campaign alerts

## Existing data and account fields

The shorter signup collects account type, name, email and an eight-character minimum password. Google/Apple completion requires account type and name; the provider supplies the verified email. Previous complete registration payloads are still accepted. Contact information, categories/industries, company type, birthday and every existing profile tab remain in the account editor. No profile data is removed or rewritten by the migration.

Login lands on `account-dashboard`, including incomplete accounts. Their overview shows email verification, contact/location and identity/category completion. Links open the existing editor with `tab` and a one-use `edit=1` query. The required-fields modal can return to the overview; marketplace routes and server approval/verification checks still apply. Portfolio, social profiles, offers and audience data remain optional. The existing admin approval process automatically reviews eligible accounts; there is no extra submission endpoint or fake submit action.

Approved accounts see real activity/financial/review/credit statistics and a next action. The visibility switch calls the existing CSRF-protected visibility endpoint. Approval never enables public visibility automatically. Existing visibility choices are preserved.

## Opt-in campaign emails

`GET/PUT /api/me/campaign-alerts` is creator-only, owner-scoped and non-cacheable. PUT requires CSRF and validates category values, country codes, daily/weekly frequency and an explicit boolean opt-in. No preference row means disabled. These emails are independently opted into, separate from device notifications.

`CampaignAlertTask` runs hourly through the existing scheduler, scanning at most 100 due preferences per batch. `CampaignAlertEmailMessage` uses the mail queue. Delivery locks and rereads preferences, verifies account eligibility and checks both directions of blocking, public company visibility, campaign status/deadline and existing applications. Empty windows send no email. Digests contain at most five new matching campaigns, never older campaigns from before opt-in or a scope change. Delivery advances the window only after successful delivery or a no-match/ineligible check. A repeated completed message does not resend its window.

The six localized email copies use `templates/emails/campaign_alert.html`, including campaign links and a link back to alert settings in Pregled. Failed transport sends roll back the window for normal queue retry. Like other SMTP sends, a crash after provider acceptance but before committing the delivery window can cause a retry; SMTP does not provide exactly-once delivery.

## Admin account funnel

The admin overview adds an account-type filter and a registration-cohort funnel for the selected 7/30/90-day period. A Doctrine flush listener records each reached milestone once: registered, email verified, required profile complete, approved, first creator application/company campaign. The display uses nested completed stages, so percentages always represent conversion from the previous stage in the same cohort.

Measurement starts when this code is deployed. Existing accounts are not backfilled with invented registration dates. Demo, staff and deleted accounts are excluded. No page visits, device tracking or abandoned-form counts are inferred. Stage records reference the account with cascading deletion, without storing names, emails or browser identifiers.

## Deployment and verification

Migration `Version20261016120000` creates only `campaign_alert_preference` and `account_milestone`. The normal production workflow migrates and registers scheduled tasks before restarting workers. Manual deployments must also run `app:scheduled-tasks register` and maintain the existing scheduler/background/mail workers. No new external service is needed.

Relevant tests: `AccountActivationTest`, `OAuthRegistrationFlowTest`, `EmailAccountFlowTest`, `CampaignReviewsTest`, `AdminToolsControllerTest`, `BackgroundPipelineTest`, and frontend `accountActivation`, `accountRegistration`, `personalOverview`, `adminOverview`, `accountProfile` suites. Database-backed queue tests require an isolated PostgreSQL test database; the checked-in SQLite environment cannot execute PostgreSQL advisory locks.
