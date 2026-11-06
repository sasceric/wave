# Creator referrals and ambassadors

Creators share `/?ref=CODE` from **Pozovi kreatore**. The visitor's browser remembers
the code for 30 days and sends it with a new creator registration, including Google
and Apple registration. Existing sign-ins and company registrations do not create
referrals. Codes are normalized, and each referred account can have only one
referrer. A creator cannot refer the same account or email address.

A referral counts after email verification and account approval. Same-address,
repeated-address and similar-email referrals wait for an administrator's decision.
Rejected referrals, deleted accounts and accounts that became companies do not
count. Address checks store an HMAC, not the raw address.

The milestones are 20, 35 and 50 qualifying creators. Reaching a milestone creates
one pending reward per creator/tier, queues the creator's editable
`referral_tier_reached` email and notifies administrators. An administrator activates
the reward from **Preporuke**; the server rechecks eligibility and the current
referral count. Activation and referral decisions share an inviter row lock.
Repeated activation does not extend featured placement or create another notice.

Approved rewards set the public ambassador badge and add 14 days, one month or
three months of featured placement. Existing featured time is extended from its
current end; permanently featured profiles stay permanent. Activating a lower tier
does not downgrade a higher badge. Active, approved creator ambassadors receive
notifications for newly created public campaigns. Multiple rewards produce one
notification per recipient. Hidden companies do not broadcast campaign details.

## API and access

- `GET /api/referrals/{code}` returns only an approved inviter's public name/avatar.
- `GET /api/me/referrals` returns the signed-in creator's code, progress and latest
  100 referrals; no referred email addresses or network hashes are exposed.
- `GET /api/admin/referrals` lists ambassadors, pending rewards and flagged referrals.
- `POST /api/admin/referrals/{id}/decision` and
  `POST /api/admin/referral-rewards/{id}/approve` require admin access and CSRF.

The creator/admin pages clear their data when accounts change and discard stale
requests and QR results. Interface and email copy use the existing locale catalogs.

## Deployment

Commit both `src/Entity/CreatorReferral.php` and `src/Entity/ReferralReward.php`
together with `migrations/Version20261107100000.php`. Missing these files breaks
PHPStan and Doctrine in CI, even if local tests can find untracked copies.

Use the normal deployment workflow: it applies the additive PostgreSQL migration
before resuming workers and serving the new API/SPA. The migration adds nullable
referral fields to existing users, initializes creator ambassador tiers to zero,
and creates referral/reward tables with unique constraints and foreign keys.
Existing users, profiles, campaign records and URLs are preserved. No new scheduler
or worker service is required; mail and notifications use the existing delivery.
