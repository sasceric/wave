# Personal account overview

`AccountDashboardView.vue` serves the localized personal Pregled route
(`/racun/pregled` in Bosnian, `/en/account/overview` in English). The existing
admin overview remains a separate platform-wide page.

Creators see actionable invitations, unanswered hiring offers, active confirmed
collaborations and the unique campaign activity count. Companies see open
campaigns, pending applications, unanswered offers and active confirmed
collaborations. Both see five recent campaigns, their published rating and private
agreed totals for finished collaborations. These totals describe agreed amounts;
Wave does not process payments. Canceled work is excluded from those totals.

GET `/api/me/dashboard` uses `Account/PersonalOverview` and small aggregate SQL
queries scoped to the authenticated profile owner. It returns the current user ID
and private, non-cacheable data. Blocked or deleted counterpart accounts do not
appear in activity queries. Invitations and applications for the same creator and
campaign appear once. No conversation history is fetched, and no polling or
additional server service is required.

Password and completed social sign-in land on Pregled. Incomplete profiles land
on the account editor. Existing support-return and saved-campaign deep links remain
supported. `lib/accountAccess.js` sends incomplete participants back to their
account settings when they attempt other pages, while preserving support,
verification and legal pages. Required fields are the existing `hasCompleteProfile`
fields: name/company name, topic/industry, phone, city and country. Optional social
profiles, packages, biography and portfolio do not become mandatory.

Approval and verified email still gate marketplace account features and messages.
Complete accounts awaiting approval can use Pregled to check the three onboarding
steps. The API retains its existing role/approval/verification checks; the visibility
endpoint additionally requires a complete profile and verified email. Keeping an
eligible profile hidden is a personal choice and does not disable collaboration.
Staff keep access to their moderation/admin tools.

The sidebar starts with personal Pregled, followed by the account's campaign and
collaboration pages, saved campaigns for creators, and reviews. Account settings,
tickets, blocks and logout remain separate; administration and marketing links
remain grouped for staff.
