# Server notification checklist

Use this checklist for the existing `wave.ba` server. The [Mercure installation guide](mercure-server-setup.md) contains the complete Caddyfile, systemd unit and Nginx proxy. Application releases follow [Production deployment](deployment.md).

Notifications now use durable background delivery: separate `realtime`, `push`
and `mail` consumers process accepted events. Before deploying this release,
follow [server operations](server-operations.md) to install the consumers and
scheduler; an available Mercure hub alone does not drain these queues. Existing
issuer, JWT, VAPID and Caddy settings remain applicable. Live chat still receives
Mercure events rather than polling for messages.

## Files and responsibilities

| File | Purpose | Apply changes with |
| --- | --- | --- |
| `/home/steelcodeweb/web/wave.ba/public_html/.env.local` | Symfony settings, notification flag, Mercure secret and Web Push keys | Regenerate compiled environment and clear Symfony cache |
| `/home/steelcodeweb/web/wave.ba/public_html/.env.local.php` | Generated production environment | `composer dump-env prod`; do not edit manually |
| `/etc/mercure/mercure.env` | Hub issuer and JWT keys | Restart `mercure` |
| `/etc/mercure/Caddyfile` | Hub routing, CORS, public identity and storage | Restart `mercure` |
| `/home/steelcodeweb/conf/web/mercure.wave.ba/nginx.ssl.conf_mercure` | Hestia HTTPS proxy | Validate and reload Nginx |

## Required Symfony settings

Set these explicitly in the **production server's** `.env.local`, preserving its PostgreSQL, mail, OAuth and other settings:

```dotenv
WAVE_NOTIFICATIONS_ENABLED=true
WAVE_QUEUE_ENABLED=true
MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=https://mercure.wave.ba/.well-known/mercure
MERCURE_ISSUER=https://wave.ba
WEB_PUSH_SUBJECT=mailto:notifications@wave.ba
```

Keep `MERCURE_JWT_SECRET` and the existing production `WEB_PUSH_PUBLIC_KEY` / `WEB_PUSH_PRIVATE_KEY` pair configured privately. These keys are separate: Mercure uses a shared JWT secret; device push uses VAPID. Keep the existing VAPID pair when fixing delivery, because changing its public key requires browsers/devices to subscribe again. Empty Web Push keys disable device delivery without disabling Mercure.

`WAVE_NOTIFICATIONS_ENABLED` gates **both Mercure publication and Web Push delivery**. When false, messages still save in PostgreSQL but send neither live events nor device pushes. Current code defaults to true when the flag is absent; keep it explicit in production and check the loaded value if behavior differs. Older deployments, compiled values or process overrides can differ from the edited file. Subscriber HTTP 200 does not prove that publication is enabled.

## Match the Hub settings

In `/etc/mercure/mercure.env`, `MERCURE_TRUSTED_ISSUERS` must be `https://wave.ba`. Both `MERCURE_PUBLISHER_JWT_KEY` and `MERCURE_SUBSCRIBER_JWT_KEY` must equal Symfony's existing `MERCURE_JWT_SECRET`.

The complete Caddyfile must use **both** site addresses:

```caddyfile
http://mercure.wave.ba:3000, http://127.0.0.1:3000 {
    bind 127.0.0.1
    # Keep the full Mercure handler from the installation guide here.
}
```

The proxy sends `Host: mercure.wave.ba`; Symfony publishes with `Host: 127.0.0.1:3000`. A hostname-only site can leave subscriptions connected while publications bypass Mercure and return an empty HTTP 200.

Inside the handler, preserve:

```caddyfile
cookie_name mercureAuthorization
resource_identifier https://mercure.wave.ba/.well-known/mercure
cors_origins https://wave.ba
```

`resource_identifier` must equal `MERCURE_PUBLIC_URL`. Keep the persistent Bolt database at `/var/lib/mercure/mercure.db` and TCP 3000 restricted to loopback. Use the [full Caddyfile](mercure-server-setup.md#3-configure-the-hub-and-its-secret) rather than replacing it with these partial examples.

## Apply configuration changes

After editing Symfony's `.env.local`, run from the checkout using the deployment account. These match the production workflow, including its Composer setting for root SSH deployments:

```sh
cd /home/steelcodeweb/web/wave.ba/public_html
COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=prod php8.4 "$(command -v composer)" dump-env prod
APP_ENV=prod php8.4 bin/console cache:clear
```

**Clearing cache alone does not regenerate `.env.local.php`.** The normal deployment workflow performs both steps. If commands run as root, restore writable-directory ownership as the workflow does:

```sh
sudo chown -R steelcodeweb:steelcodeweb /home/steelcodeweb/web/wave.ba/public_html/var
sudo chmod -R u+rwX /home/steelcodeweb/web/wave.ba/public_html/var
```

Inspect only the loaded, non-secret notification flag in this CLI environment:

```sh
APP_ENV=prod php8.4 -r 'require "vendor/autoload.php"; (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env"); $value = $_SERVER["WAVE_NOTIFICATIONS_ENABLED"] ?? $_ENV["WAVE_NOTIFICATIONS_ENABLED"] ?? "true"; echo "WAVE_NOTIFICATIONS_ENABLED=".(filter_var($value, FILTER_VALIDATE_BOOLEAN) ? "true" : "false"), PHP_EOL;'
```

Expect `WAVE_NOTIFICATIONS_ENABLED=true`. If web requests differ, check PHP-FPM/process environment overrides and reload the relevant PHP-FPM service after changing those overrides. Do not print the entire environment or private keys in diagnostics.

After changing either Hub file:

```sh
sudo systemctl restart mercure
sudo systemctl status mercure --no-pager
sudo journalctl -u mercure -n 50 --no-pager
```

After changing the Nginx proxy:

```sh
sudo nginx -t && sudo systemctl reload nginx
```

Frontend/service-worker fixes need an application deployment. Existing browsers must accept the Wave update or reload to activate the new app and worker. `.env.local` changes alone do not deploy frontend code. See [app-shell caching](deployment.md) if clients keep an old version.

## Verify delivery, display and click separately

Check the private publisher route without overriding its Host header:

```sh
curl -i --max-time 3 -X POST http://127.0.0.1:3000/.well-known/mercure
```

Without a publisher JWT, expect **401 Unauthorized**. An empty 200 indicates a routing problem; connection refusal indicates the Hub is down or listening elsewhere. This probe proves the request reaches authorization, not that a real message is delivered.

Then use two approved, email-verified participant accounts in separate browsers:

1. Leave the recipient on its account page and send one diagnostic message. Header and account-sidebar unread counts should increase without reloading.
2. Open a different conversation and send another message to the first one. Its preview and number badge should update while the selected chat stays selected.
3. Open the notified chat. Messages should display and unread counts should clear.
4. Keep that chat open and send another message. It should appear immediately. Browser Network tools must show an actual Mercure EventStream message; HTTP 200 and heartbeats alone are insufficient.
5. Enable device notifications and put the app in the background. Verify the OS notification appears and that tapping it opens the intended conversation, including when another chat is already open.
6. On a supported installed mobile app, send two unread messages while Wave is closed. The home-screen badge should reflect the total unread messages plus unread non-chat notifications. Open and read them, then return home; the badge should decrease or clear. In Wave, the bell counts non-chat notifications and the message icon counts unread messages separately.

Normal live updates come from Mercure events. Push also signals open windows, and focus/reconnect events reconcile authorized data. There is no periodic message/notification polling. Device push is opt-in per browser/device and needs both website and OS permission. On macOS, check **System Settings → Notifications → Google Chrome → Allow notifications**. Blocking OS banners does not block Mercure chat updates.

## Mobile badges and push links

The server includes an authoritative `badgeCount` in each Web Push payload. The service worker uses the Badging API when available, alongside the visible OS notification. The `badge` image in a browser notification is an icon, not this unread number. Chat notification records are excluded from the notification count because their unread messages are already counted. The bell API returns the full unread total even though its menu displays at most 30 notifications.

On iPhone/iPad, Wave must be added to the Home Screen, iOS/iPadOS must be 16.4 or later, and notification permission must be granted. **Settings → Notifications → Wave → Badges** must also be enabled. Safari tabs do not have a home-screen app badge. See [WebKit's Badging API documentation](https://webkit.org/blog/14112/badging-for-home-screen-web-apps/). Chrome on Android does not expose a controllable numeric badge through this API; installed web apps instead receive the platform's notification badge/dot, as described in [Chrome's badging documentation](https://developer.chrome.com/docs/capabilities/web-apis/badging-api). Other browsers/launchers may represent badges differently; unsupported or denied badging does not stop notifications or in-app counters.

The foreground app reconciles badge counts after authorized inbox loading, push/reconnect/focus events and read actions, and clears the badge on logout. A closed device receives an updated count with its next visible push; reading on another device does not send an invisible badge-only push. The next app opening reconciles the count. This preserves the user-visible Web Push requirement and avoids polling.

Message pushes target `/messages?conversation=<id>`; localized redirects preserve the conversation query. The worker first focuses an existing window, then sends `WAVE_OPEN_NOTIFICATION` over a MessageChannel so a resumed installed app can route directly through Vue. Even if an iOS client rejects focus during resume, it can still acknowledge the routing message. If the app does not acknowledge within 1.5 seconds, the worker falls back to native navigation and opening a window when necessary. This deadline is a single click acknowledgement, not message polling. A newly opened/reused client also receives the routing message.

An already-mounted Messages view reacts to changed conversation/inquiry queries. An inaccessible target leaves the inbox unselected instead of opening a different chat. Test a foreground app on another chat, a backgrounded app after locking the phone, and a fully closed app after activating the new service worker. [WebKit bug 263687](https://bugs.webkit.org/show_bug.cgi?id=263687) describes installed iOS apps opening from a push without applying the target URL. It supports investigating this path, but does not confirm the cause on a particular phone/version. Device-specific launch behavior still needs a real phone check after deployment.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Message saves, but no event or push | Effective notification flag, compiled `.env.local.php`, process overrides and deployed version |
| Subscriber connects but receives no messages | Publisher's loopback Host route, delivery flag and actual publication/event |
| Mercure returns 401 with app authorization | Matching issuer/JWT keys, public resource identity, subscriber cookie and approved/verified session |
| CORS error or EventSource fails | Exact CORS origin, Nginx include, TLS and subscription-cookie scope |
| Live chat works but push does not | VAPID pair, device subscription, website permission and OS notification settings |
| Push arrives but tapping opens the wrong view | Notification URL/query, deployed service worker, current login and navigation with another chat already open |
| Home-screen badge is missing | Installed app, platform Badging API support, notification permission, OS Badges setting and deployed worker/payload version |
| Older app code persists | App-shell caching and acceptance of the PWA update |
| Cache-write warnings | Ownership/write access of `var/cache/`, `var/log/` and deployment account |

Hub logs: `journalctl -u mercure`. Application warnings rotate under `/home/steelcodeweb/web/wave.ba/public_html/var/log/prod-YYYY-MM-DD.log`. Relevant messages include `Unable to publish a Wave realtime update.`, `Web Push VAPID configuration is invalid.`, `Unable to send a Wave Web Push notification.` and `A Wave Web Push notification was rejected.` Record test time and which stage failed; keep secrets and private message text out of shared diagnostics.
