# Notification investigation — 2026-10-05

## Findings

Production was tested using the two accounts supplied by the user, with Safari as the sender and Chrome as the recipient.

- Both diagnostic messages reached Chrome's active service worker. `getNotifications()` contained both messages, and the subscription's VAPID public key matched the server configuration. The Chrome browser permission was granted.
- macOS System Settings showed both Google Chrome notification entries disabled. This prevents desktop banners even when Chrome receives and displays the Web Push notification internally. Enable **System Settings → Notifications → Google Chrome → Allow notifications** for the Chrome installation in use. No OS settings were changed during this investigation.
- Chrome's authenticated Mercure stream returned HTTP 200 and stayed connected, but its EventStream view did not receive the diagnostic message. Reloading/focusing the app reconciled messages through the existing API handlers.
- The documented server Caddyfile accepted only `Host: mercure.wave.ba`, while Symfony's private publication URL uses `Host: 127.0.0.1:3000`. A temporary probe using the installed Caddy/Mercure binary reproduced an empty HTTP 200 for the unmatched numeric host. Accepting both hosts returned the handler's response for both routes. Symfony's hub client previously accepted that empty response silently.

The hostname mismatch is confirmed in the documented configuration, reproduced locally, and also present in the production Caddyfile subsequently supplied by the user. The corrected copy-paste configuration was parsed successfully with the installed Mercure/Caddy binary. Applying it and verifying live delivery remain necessary. Caddy's [site-address documentation](https://caddyserver.com/docs/caddyfile/concepts#addresses) explains the Host matching behavior.

The attached log ends at 16:23 UTC, before the diagnostic pushes at 17:16 and 17:19 UTC. It contains older subscriber cookie/domain failures and cache warnings; it does not establish the publication result for the new tests. A missing publication warning did not rule out the empty-200 routing problem.

The user subsequently reported that adding `WAVE_NOTIFICATIONS_ENABLED=true` restored delivery. The current code defaults this flag to true, so an absent entry alone does not establish the cause in this checkout. Production compiled/process configuration or an older release may differ. Keep the flag explicit and use the effective-environment checks in [the server checklist](server-notifications.md).

## Changes

- Header, mobile navigation, and account sidebar share a reactive unread message count. The account sidebar now includes Messages with its badge, using existing translations. The unread count is also included in message links' accessible labels.
- Mercure message payloads update the opened chat and conversation preview immediately. Hidden chats do not fetch messages and mark them read on incoming events. Stale message responses cannot replace a newly selected conversation.
- Mercure reconnection preserves `lastEventID`; notification IDs suppress duplicate delivery. Pending authorization responses cannot open an obsolete connection after logout/connection cleanup.
- A push notification signals open windows through a service-worker message. The window fetches its own authorized inbox data, without broadcasting private message content between windows.
- Inbox reconciliation runs on push signals, Mercure connection/reconnection, returning to a visible/focused window, and network restoration. There is no periodic message or notification polling. The existing timer checks service-worker **software updates**, and the Mercure retry timer reconnects a failed stream; neither checks the inbox periodically.
- Empty Mercure publication IDs now produce the existing publication warning, making silent routing failures visible.
- Push clicks focus before navigation, request direct Vue routing from a resumed app over a MessageChannel, and try native navigation/another window if the request is unacknowledged or the client closes. A focus rejection on an installed iOS app does not skip the routing request. Same-origin validation also handles malformed targets. Message routing reacts when only the chat query changes on the reused Vue view; inaccessible targets do not select an unrelated chat.
- Background pushes include the recipient's full unread badge total. Supported installed apps update the home-screen badge through the Badging API; foreground counts/read actions reconcile it and logout clears it. In-app message and notification counts remain separate. The notification API counts all unread non-chat notifications, including ones beyond the 30 menu entries.

Initial page loading, event-triggered detail retrieval, and read acknowledgements still use the authenticated Symfony API. This preserves participant checks and makes reconnects recover correctly even if retained event history is incomplete.

## Server action

Use [the server checklist](server-notifications.md) for the notification flag, compiled environment, existing JWT/VAPID keys, reloads and validation, and the corrected full configuration in `docs/mercure-server-setup.md`. In particular, the local hub site must accept both addresses:

```caddyfile
http://mercure.wave.ba:3000, http://127.0.0.1:3000 {
    bind 127.0.0.1
    # Keep the existing Mercure handler and authorization configuration here.
}
```

Preserve `resource_identifier https://mercure.wave.ba/.well-known/mercure`, matching Symfony's public hub URL. Validate the complete Caddyfile and restart the Mercure service after updating it.

An unauthenticated POST to Symfony's exact private publication URL should reach Mercure and return 401, rather than an empty 200. Then verify an authenticated publication returns an event ID and appears in the recipient's browser EventStream. A connected subscriber alone is insufficient verification.

Deploy the application changes and accept the PWA update/reload existing tabs to activate the new frontend and service worker. Nothing was pushed or deployed during this investigation.

## Validation

- Frontend regression tests: 32 passed. They cover event-driven reconciliation without polling, hidden/offline handling, refresh coalescing, cleanup/recovery, stale chat responses, immediate message/preview updates, shared badges, reconnect cursors/deduplication, authorization cleanup, push-to-window signaling, notification route changes, click recovery, resumed-app routing/acknowledgement (including rejected focus), background badges and denied/unsupported badge APIs.
- Relevant backend tests: 5 passed, 114 assertions on an isolated local PostgreSQL database. This includes publication response handling, conversation/notification workflow, realtime/push access scope, full counts above 30, recipient isolation, read clearing and avoiding duplicate chat counts. The database was created only for this check and removed afterward; the existing Wave database was not changed by these automated tests. An earlier run used temporary SQLite because the checked-in test environment defaults to SQLite; PostgreSQL coverage was subsequently completed at the user's request. The workflow test initially failed because the local mail sender address was empty; rerunning with a test-only `MAIL_FROM_ADDRESS` passed.
- Frontend production build: passed. The build emits an existing PWA bundler deprecation warning about `inlineDynamicImports`.
- PHPStan and ESLint remain unavailable: neither is installed/configured in the project. Those required checks were not run.
- Local browser tests used the supplied creator/company accounts and the existing PHP, Vite, and Mercure services. Actual Mercure payloads were observed in Chrome; header and account sidebar both showed two unread messages. Opening the chat cleared the count. Messages then appeared in the opened Chrome and Safari chats in both directions without a reload. A message to a different conversation updated its preview, its number badge, and the header count without replacing the selected chat; opening that conversation cleared the unread count.

The local mobile viewport (390 × 844) showed the conversation unread badge and bottom message icon count increase to 1 immediately after a Safari message. Opening the chat cleared it. Clicking an existing in-app notification while another chat was open selected its target conversation and displayed the matching messages. The native service-worker click path is covered by regression tests, including focus-before-navigation, a closed client, null navigation, cold launch and unsafe/malformed URL handling.

The resumed-app routing signal was also dispatched locally through Chrome DevTools while the other chat was open. The app acknowledged `WAVE_NOTIFICATION_OPENED`, preserved the conversation query through the localized redirect, and displayed the matching campaign's messages. This verifies the app listener/router together; it does not simulate iOS scene restoration. The user identified the affected device as an iPhone 13 Pro Max and reported OS version `27.7`; that version was not independently inspected. WebKit has [a report of similar installed-PWA navigation loss](https://bugs.webkit.org/show_bug.cgi?id=263687), but the phone-specific cause remains unverified.

Diagnostic test messages remain in the supplied conversations. Local Web Push banner/click delivery was not tested: Safari's tested HTTP loopback session did not expose device-push controls, and macOS Chrome notifications were disabled at the earlier OS inspection. Production push receipt was already verified directly in Chrome's service worker. Physical phone launch behavior and home-screen badges still need testing after deployment and activation of the updated worker; the responsive viewport is not a physical device test.
