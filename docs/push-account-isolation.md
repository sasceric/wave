# Device notifications and account switching

## Cause

The previous logout path invalidated the Symfony session but left the browser's
Web Push endpoint assigned to that account. Login read `getSubscription()` and
showed push as enabled without registering the endpoint for the new account.
A device could therefore display a previous account's message preview; clicking
it under the new account correctly failed the conversation ownership check.
This applies to normal browsers and installed PWAs.

## Fix

- Both header and account-sidebar logout use the shared `logoutAccount` helper.
  It supplies this device's endpoint to authenticated, CSRF-protected logout and
  closes existing browser notifications after success.
- Symfony remembers the endpoint hash registered by the session. Logout removes
  only matching subscriptions owned by that user, before session invalidation.
  Other devices remain subscribed. Clients can also supply an older endpoint
  that predates session tracking; another user's endpoint cannot be removed.
- Login re-registers any existing browser subscription for the current user.
  Request versions discard stale completions. The POST carries the expected
  user ID; Symfony rejects requests arriving after another account has signed in.
- Push payloads include the recipient ID in both direct and queued delivery.
  The service worker checks the live same-origin session with `no-store` and a
  five-second deadline before showing content or changing the badge. Logged-out,
  different-account, disabled, legacy unidentified and unverifiable pushes are
  suppressed. Offline verification fails closed rather than exposing a preview.
- Notification clicks repeat that check. Old notifications open the homepage
  instead of trying the old account's private conversation.
- Notification lists, unread counts and realtime authorization identify their
  account. The app rejects mismatched responses and refreshes its session.
  Realtime payloads identify their recipient; closed connection/version checks
  remain in place. Conversation ownership and notification read checks remain.
- Queued deliveries already check subscription ownership. Reassignment or removal
  means an old queued job cannot send to the new owner's subscription.

## Validation and rollout

Automated tests cover logout/reassignment, another user's endpoint, old queued
jobs, stale requests, worker delivery filtering and old notification clicks.
Tests use an isolated PostgreSQL database and simulated worker/browser APIs;
physical iPhone push delivery has not been reproduced locally.

No new migration or Mercure configuration is needed. Deploy the Symfony code and
frontend together, restart background workers using the normal deployment flow,
and activate the new service worker in the browser/PWA. The fix is not live until
that release and worker update. Existing devices rebind on the next authenticated
app load. Pushes already accepted by a provider are filtered by the new worker.

Final local validation: 260 backend tests (274,185 assertions), 274 frontend tests,
PHPStan, ESLint and the production frontend build passed. The backend suite ran in
`wave_redirect_work_test` with a null mail transport; production accounts and
subscriptions were not modified. Live browser/PWA delivery remains a release check.
