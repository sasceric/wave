# MVP release audit — 2026-10-08

## Current verdict

The core marketplace is implemented. No additional product module is required
for a free MVP. Release sign-off is pending the remaining browser workflows and
production operational checks below. Passing local tests is not evidence that
production mail, workers, Mercure, or device push are configured correctly.

The audit has not deployed, committed, or pushed changes. Existing working-tree
changes were preserved. Tests use disposable PostgreSQL databases, captured mail
with a null transport, separate upload storage, and a separate Mercure hub.
Existing local accounts and production data were not used for browser mutations.

## Implemented scope

- Registration, sessions, email verification, password recovery, optional OAuth,
  administrator approval, and ownership/participant checks.
- Creator/company directories and search, profiles, rich descriptions, social
  channels, creator portfolio, priced service packages, and FAQs.
- Company campaign creation/editing, categories/platform multiselects, country
  and city, deliverables, budgets, deadlines, and hiring progress.
- Applications, company offers, creator invitations, package inquiries, private
  conversations, closed-chat restrictions, bookmarks, and paginated activity.
- In-app notifications, preferences, editable localized emails, realtime updates,
  and Web Push integration.
- Six languages, responsive layouts, and installable PWA.
- Admin approvals, catalogs, homepage content, email templates, subscribers,
  queues, scheduled tasks, logs, and search-history logging.
- Credits: free mode by default; configurable paid mode, activation grants and
  emails, costs/prices, manual vouchers, redemption, and transaction history.
  Online payment remains an explicitly disabled placeholder. Keep paid mode off
  for a free MVP; checkout integration is not a release prerequisite.

## Automated checks completed

| Check | Result |
| --- | --- |
| Fresh PostgreSQL migration chain | Passed: 46 migrations, 288 statements |
| Full PHPUnit suite on isolated PostgreSQL | Passed: 196 tests, 271,690 assertions |
| Frontend regression suite | Passed: 188 tests |
| PHPStan (`composer analyse`) | Passed |
| ESLint | Passed |
| Production SPA/PWA build | Passed |
| Diff whitespace | Passed |

Backend coverage includes authentication, validation, CSRF, approval and
verification gates, media ownership and resizing, applications/offers and slot
limits, invitations, inquiry acceptance, participant-only chat, closed/finished
campaigns, creator decline notifications, bookmarks, inbox pagination, read
receipts, notification preferences, OAuth flows with test providers, credits
transactions and duplicate prevention, admin tools, email templates, search
logging, SEO, and newsletter confirmation. Frontend tests cover account forms,
validation reset, directories/filters, activity tables/pagination, campaign
editing, chat input/read state, mobile keyboard behavior, notifications, search,
credits, shared controls, loading, and service workers.

These tests do not replace browser journeys or actual provider/device delivery.

## Browser checks completed

Used independent sessions: Codex in-app browser for creators and Chrome for the
company. Three synthetic accounts were registered through the actual forms:
two creators and one company. No fixture API responses were used.

| Journey | Observed result |
| --- | --- |
| Creator registration | Account and session created; pending approval notice shown |
| Company registration | Correct company form and account created |
| Second creator registration | Separate account created for multiple-applicant testing |
| Email verification | All three actual captured-email links confirmed successfully |
| Company logout and login | Session cleared and restored through the login form |
| Creator rich description/title/tags | Saved and rendered as content rather than raw HTML |
| Creator service package | Instagram package saved with description and 200 KM price |
| Creator FAQ | Question and answer saved |
| Creator social channel | TikTok username and 1,200 followers saved |
| Company rich description | Saved and rendered successfully |
| Account dropdown | Name, profile link, language, and logout controls visible |
| Campaign form | Rich editor, country/city, all three platforms, and multiple deliverables usable |
| Unapproved campaign publishing | Publish button correctly disabled |
| Unapproved portfolio upload | Backend correctly rejected the upload with the approval notice |
| Narrow account layout | No horizontal page overflow at the observed 670 px viewport |

### Fixes made during the audit

- Campaign country selector lacked required placeholder and empty-result props;
  the form displayed “undefined”. Added the existing localized strings and
  verified country selection in the rebuilt browser form.
- Updated the admin-tools queue assertion from 21 to 22 rows to include the
  credits announcement message type. The full backend suite then passed.

The test-server environment initially selected the wrong database name; this was
an isolated audit-harness problem, corrected before registration. It was not an
application defect. No schema reset or test registration ran against the normal
local account database.

### Browser work still pending

Automatic approval review rejected granting administrator access to the synthetic
company account without explicit approval. Permission was requested for that
exact grant in the disposable audit database. Do not bypass that restriction.
This prevents testing approval and the following authenticated browser actions:

1. Approve both creators and company; upload avatar, company cover and portfolio.
2. Publish/edit a campaign; search/filter it and apply from both creators.
3. Shortlist, offer, accept/hire, and verify hired/required counts.
4. Invite a creator directly; accept and decline separate invitations and inspect
   company notifications. Check all four account activity flows.
5. Search the creator, order a service package, accept/reject inquiries, and verify
   participant-only chat and notifications across both browser sessions.
6. Exchange messages live; verify input clearing, no stale required-field errors,
   unread/read behavior, and closed/finished conversations disabling sending.
7. Finish the campaign and verify unhired applicants are rejected silently while
   hires remain. Check bookmarks, search-result navigation, activity pagination
   with more than ten entries, and mobile layouts.
8. Exercise admin tools and actual credits free/paid activation, grants, email,
   voucher redemption, insufficient balance and paid/free switching locally.

Do not describe these journeys as browser-tested yet; their automated tests pass.

## Before releasing

- Complete the pending two-browser workflows and resolve any defects found.
- Back up PostgreSQL and persistent media, establish a tested restore path, then
  apply migrations. Preserve APP_SECRET and existing provider keys.
- Confirm production mail sender and actual verification/reset/marketplace email
  delivery. Captured local emails establish content and generation only.
- Confirm the four supervised queue consumers and scheduled dispatcher, failures,
  worker heartbeats, and pending queues draining. See `server-operations.md`.
- Confirm HTTPS/public origin, Mercure issuer and cookie/CORS settings, live chat
  delivery, stable Web Push keys, and push opening the intended conversation.
- Test actual iPhone/Android installation, notifications, keyboard behavior,
  offline/startup/update behavior. Desktop responsive inspection cannot certify
  real device behavior.
- Verify any enabled Google/Apple provider using real configured credentials;
  test-provider coverage is not a live OAuth acceptance test.
- Confirm media/log/cache permissions and required PHP extensions; verify deployed
  app-shell/service-worker cache headers and build freshness.
- Keep credits disabled for the free launch. Manual code issuance is available
  when paid mode is intentionally activated later; no online checkout exists yet.

Deployment and operational instructions: `deployment.md`, `server-operations.md`,
`server-notifications.md`, and `mercure-server-setup.md`.
