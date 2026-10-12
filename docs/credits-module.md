# Credits module

## Product behavior

The site starts in free mode. Accounts display Unlimited; saved balances and history remain intact. Administrators can enable paid mode, turn it off again, and edit the price per credit and the costs of applying and publishing. Defaults: 0.20 KM per credit, 10 credits per application, 30 per campaign. Packs contain 50, 100 or 200 credits.

On every transition from free to paid, each existing account receives the configured free grant for that activation, and an announcement email is queued in its preferred language. Saving settings while already in paid mode does not repeat the grant or email. New accounts do not receive grants from previous activations. Existing campaigns and applications are never charged retroactively. Editing a campaign, accepting invitations, messaging and package inquiries remain free.

The first version uses admin-issued codes after payment is handled separately. An administrator generates a single-use eight-character alphanumeric code for a pack and gives it to the purchaser. No payment gateway or automated checkout is implied. Prices are snapshotted when a code is issued. New full codes are stored encrypted with the existing application payload cipher, alongside their redemption hash. Administrators can view them in history; redemption is atomic. Historical codes that only have a hash remain valid, but their full value cannot be recovered or emailed through assignment. Codes can be redeemed even in free mode, with balances saved for future paid use. Unredeemed codes can be revoked.

In free mode, the account screen shows only the unlimited balance summary, free-mode explanation, and any saved balance. Costs, history (including its link), purchasing, and code entry are hidden. In paid mode, all these sections appear; packages show their prices with an explicitly disabled “Online purchases coming soon” button until checkout is integrated. Selection only previews the package; it creates no order, payment, or credits. The adjacent code card explains the current manual purchase flow. History can be filtered to added or spent credits; filtering happens on the server before pagination, and the balance always includes all transactions.

## Future purchase flow

Keep the same package selection and replace the placeholder with hosted checkout when a payment provider is selected. Create an authenticated order with a server-calculated price snapshot, redirect to the provider, then add credits only after a verified payment webhook. Use a unique provider payment ID to prevent duplicate credits on webhook retries, and display the receipt and purchase in history. The return-to-site page should show pending or confirmed status from the server. Codes can remain available for manual payments and promotions. Provider choice and any tax/receipt requirements are separate decisions before integrating checkout.

## Implementation

- Doctrine tables for singleton settings, wallets, immutable credit entries, vouchers and announcement outbox; PostgreSQL and SQLite compatible migration.
- Shared backend service serializes mode changes and spending, prevents negative balances and commits the debit with the successful campaign/application. Invalid, duplicate or failed submissions cost nothing.
- Admin settings with explicit confirmation before each paid activation, configurable welcome grant, costs, prices, code generation, voucher history using the shared sortable, paginated table with fixed actions, bulk deletion, and assignment.
- Account credits page with balance, pack prices, paginated history, redemption form and accessible success modal with role-specific next action.
- Localized balance/cost hints on campaign publishing and application forms, with a link to credits when funds are insufficient.
- Editable activation and personalized code-assignment emails in the existing six-language email-template administration. Announcement jobs use the existing durable background queue and an outbox to avoid duplicate enqueueing on retry.
- Security: existing admin/account access and CSRF checks, redemption rate limiting, one-use codes, version checks for stale admin saves.

## Validation and rollout

Test free/paid mode, grants exactly once per activation, atomic redemption and spending, rejected requests without charges, settings bounds, permissions and announcement templates. Run backend tests, PHPStan, frontend tests, ESLint and production build. Apply the migration before deployment; do not enable paid mode as part of deployment. The existing mail queue workers must be running to deliver activation announcements. Test a code end to end before deciding to activate paid mode in production. Online payment collection can be added later once a provider is selected.

The migration was applied locally with free mode preserved. An isolated PostgreSQL schema check verified activation, redemption, debit history, and reactivation. Relevant backend coverage includes credits, marketplace workflows, email templates, queue inspection, and migration compatibility. The broader backend suite has pre-existing failures in PostgreSQL-specific SQL when run against its SQLite test database.

Final checks: 44 relevant backend tests (1,278 assertions), all 181 frontend tests, PHPStan, ESLint, and the production frontend build passed. Desktop and mobile previews verified the settings page, redemption modal, and mobile layout.

After the account-page redesign: all 182 frontend tests and all 10 credits backend tests passed, including history filtering before pagination. PHPStan, ESLint, and the production build passed. Desktop package selection, history filtering, and mobile redemption were checked using isolated preview data; no live payments or paid-mode activation occurred.

## Code assignment and deletion

The administrator chooses a creator or company, then selects a recipient by name/email from searchable batches of 20. The selection uses the user ID, not the profile ID. Assignment reserves the code for that account and sends its name, code and credit amount through the existing email queue. It does not alter the wallet or create a credit entry. Only successful redemption adds credits. Other accounts cannot redeem a reserved code; an identical assignment retry does not send another email.

Statuses are Created, Assigned and Used, plus Revoked for existing revoked codes. Assignment and email enqueueing share the credit transaction; enqueue failure rolls the reservation back. Deleted unused codes stop working. Deleting a used code removes its voucher record but preserves credited balances and immutable redemption history. New codes require migration `Version20261013110000` and a stable `APP_SECRET` to decrypt their full value. Keep the existing email workers running.
