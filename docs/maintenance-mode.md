# Maintenance mode

The public Bosnian URL is `/odrzavanje`. `/maintenance` remains a temporary
302 alias; neither route creates a saved redirect record.

Admins toggle **Settings → Platform maintenance**. It is off by default and does
not change feature flags, approval, account visibility or saved redirects.
`PlatformSettings` maps a single configuration row; `MaintenanceMode` reads it
fresh and updates it atomically with PostgreSQL `ON CONFLICT`. There is no worker,
new scheduled task or cross-request configuration cache.

- `GET /api/maintenance` returns `enabled` and the authenticated admin's `bypass`.
- `PUT /api/admin/maintenance` accepts a strict boolean `enabled`. It requires
  existing admin authorization and CSRF protection. Moderators cannot enable or
  bypass maintenance merely because they are moderators.
- The request subscriber runs after the firewall and before controllers and
  directory throttling. Non-admin document requests temporarily redirect to
  `/odrzavanje` with HTTP 302 and private/no-store headers. No redirect records
  are inserted, updated or removed; normal redirect handling resumes when off.
- The maintenance document uses the same built Vue app with HTTP 503,
  `Retry-After: 60`, private/no-store and noindex headers. It also includes readable
  server-rendered fallback copy inside the SPA mount. Public API requests return
  JSON 503 with `code: maintenance`, rather than a redirect or a successful write.
- Admins keep normal access. Sign-in, session/CSRF, OAuth sign-in and password
  recovery remain reachable, as do health checks, robots.txt and static assets.
  All existing authentication, rate limits and ownership checks still apply.
- Vue checks fresh status before navigation, including shared profile routes,
  and handles the API maintenance code for tabs already open. The maintenance
  page hides ordinary navigation/footer, offers a retry action, Wave contact and
  admin sign-in. A signed-in ordinary user can sign out through that action to
  sign in as an admin. Admins see a banner while the mode is enabled and can
  preview the page even when it is off. Visitors return home from `/odrzavanje`
  when the mode is off. Background job workers continue their normal work.

Migration `Version20261027180000` creates the table and an off-by-default row.
Apply it before serving this backend. Rollback refuses to remove an active
maintenance configuration. The mode is application-level maintenance and still
requires the application and PostgreSQL to be running.

The service worker already uses network-first document navigation and does not
cache these responses or private APIs. Native frontend assets stay available.
As with all emergency modes, reverse-proxy rules must respect private/no-store;
this setting does not purge an independently configured external HTML cache.
