# Voluntary campaigns

A campaign has an explicit `compensationType`: `paid` (default) or `voluntary`.
Choose **Vrsta saradnje → Dobrovoljna saradnja — bez naknade** when creating a campaign.
Cards, public details, search results, account screens and chat offers display **Bez naknade**.
The normal application, conversation, offer acceptance, delivery, revision, completion and review process remains available to creators and agencies.

## Rules

- Paid campaigns require positive integer `budgetMin` and `budgetMax`. Paid hiring offers must be positive.
- Voluntary campaigns require both budgets to be zero; hiring offers must be zero. The UI hides monetary fields and sends zero explicitly.
- A no-fee hiring offer still requires a delivery date and message, and the provider must accept it. Shortlisting alone does not hire anyone.
- Agency applications can describe scope/team/timeline but cannot propose a monetary amount on a voluntary campaign.
- The campaign type cannot change after the first application or invitation, or after cancellation/completion. This preserves the published terms and offer history.
- Existing campaign records remain paid. An old client omitting the type on update preserves the existing campaign type.
- Direct collaboration inquiries remain paid-only. Existing access, email verification, approval, CSRF, participant and platform-credit rules remain in force.
- A voluntary engagement contributes zero money to financial totals; it can still earn a normal collaboration review after confirmed completion.
- Campaign channels now include Facebook alongside Instagram, TikTok and YouTube.

## Deployment

Run the standard deployment, including Doctrine migration `Version20261022150000`, before serving the updated application. It adds `campaign.compensation_type` with default `paid`; it does not rewrite budgets or offers. Rollback is intentionally irreversible to retain historical collaboration terms.

If Meilisearch is enabled, refresh its displayed attributes and existing search documents once after deployment:

```sh
sudo -u steelcodeweb env APP_ENV=prod php8.4 bin/console app:search:index --sync
```

The normal queued updates and scheduled reconciliation continue afterward. With Meilisearch disabled, database search returns the same explicit type immediately.

## Wave community campaign draft

The copy and 3:2 banner are in `output/wave-community-campaign-2026-10-22/`. Select voluntary collaboration and upload `wave-community-banner.png`. Set the participant count and application deadline before publishing. The draft is not automatically inserted or published, and no paid campaign is converted to voluntary.

## Validation (22.10.2026)

- Frontend: 445 tests passed; ESLint and production build passed.
- PHPStan passed.
- PostgreSQL: 25 campaign workflow, review, search and migration tests passed (570 assertions).
- SQLite: all four focused voluntary campaign/migration tests passed (93 assertions). The broader selection has two existing chat-list errors because those queries use PostgreSQL lateral joins; the same selection passes on PostgreSQL.
- Browser: an isolated local company selected voluntary campaign terms and sent a no-fee offer using the shared date picker. The chat showed **Bez naknade** and **29.10.2026**. Screenshots are saved with the campaign draft assets. No production accounts, campaign data or messages were changed.
