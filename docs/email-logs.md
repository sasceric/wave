# Email logs

Open **Admin → Tools → Logs**, then select `prod.mail-YYYY-MM-DD.log` (or `dev.mail-…` locally). The existing log selector, numbered pagination and refresh controls are reused. The mail-specific table shows date/time, recipients, subject, send status and server response code. **Email preview** opens the captured HTML/text, sender and recipients, response, exception class/message and nested cause. The email ID connects attempts of the same message.

## Storage — files only

- Audit rows: `var/log/<environment>.mail-YYYY-MM-DD.log`, one JSON line per transport attempt or skipped delivery/queue submission failure.
- Captured content: `var/log/email-previews/<environment>/<random-id>.enc`.
- There is no email-log entity, database table, migration or database query in the audit writer/transport. The existing delivery queue still uses its existing protected payload; audit data is not saved to the database.
- Content is captured before queuing from the rendered email, so changing a template later does not change its historical preview. Text/HTML and attachment names are captured; attachment binaries are not duplicated.
- An internal correlation header travels through the existing protected queue and is removed before delivery. Older jobs without snapshots still get attempt logs; a full HTML/text preview may be unavailable.
- JSON append uses an exclusive file lock. Logs and encrypted content files use mode `0600`; preview directories use `0700`. Keep `var/log` outside the public web root.
- Content encryption uses the existing `APP_SECRET` through `PayloadCipher`. Preserve that secret across deployments and backups. If it changes, old previews cannot be decrypted.
- Pagination caches only file offsets/timestamps and bounded metadata, not captured email bodies. Bodies are decrypted only on an admin-only preview request with `Cache-Control: private, no-store`.

## What statuses mean

- **Accepted for sending:** the configured transport returned successfully. SMTP often reports `250`; HTTP API transports can report their actual HTTP status. A response code is left blank if the transport does not report one. This is not proof of delivery to the recipient's inbox, Focused tab, or Spam folder.
- **Failed:** that particular transport attempt threw an exception. The failure message and nested cause are retained, with recognizable credentials redacted. SMTP server replies or HTTP provider responses are included when available. Errors larger than 16 KB are visibly marked truncated. No SMTP AUTH transcript or API request credentials are saved.
- **Queue failure:** the existing queue could not accept the email; the audit retains the error and captured preview.
- **Skipped:** a queued verification token is no longer valid or an unread-message reminder is no longer current. It was not sent.
- **Not sent:** a null/test transport or transport rejection returned without delivering. No invented successful HTTP status is shown.

A queued message waiting for its first attempt is visible in the existing queue tools, not yet in transport history. Retries append separate attempt rows with the same email ID; they do not overwrite the original failure. The log does not resend messages or bypass the existing queue's retry and token checks. Provider-side delivery/bounce/open webhooks are not part of this feature; use Brevo's transactional events for those outcomes.

## Preview safety

Admin authorization is required for both listing and previewing. The iframe reuses the shared email preview component with an empty sandbox and restrictive Content Security Policy. Captured previews block scripts, forms, remote resources and active links; verification/reset token fragments are hidden in the API preview. The encrypted captured original is unchanged. The two versioned Wave logo assets are embedded locally so the identity remains visible without fetching tracking pixels. Attachment names are listed; binary attachments cannot be opened from this view.

If a preview has expired, is unreadable or cannot be decrypted, the send/error log stays visible and the interface reports that the preview is unavailable. A logging failure never converts a successful delivery into a queue retry, avoiding duplicate sends; a generic `mail.audit.*_failed` diagnostic is emitted where possible.

## Deployment and retention

No new service, environment variable or database migration is needed. Deploy normally, rebuild the frontend/container and restart existing email queue workers so they load the transport decorator. Ensure the PHP web and worker processes run as the same service user and can write `var/log`. Preserve this directory across releases if historical logs should remain available.

The existing `LOG_RETENTION_DAYS` and `LogCleanupTask` apply. Daily mail logs match the existing rotated-log cleanup rule; encrypted previews are removed in bounded batches of 100. Newly queued or deduplicated-away snapshots that never send expire through the same cleanup. Keep the scheduled cleanup running. Existing logs cannot reconstruct email contents that were never captured.

The local Shopware 6.7 reference was inspected: its `MailSentEvent` exposes the sent message and its `MailErrorEvent` supplies exception/template context. Wave follows that success/error distinction using its own file-based tools rather than Shopware's database-backed log entry implementation.

## Validation (22.10.2026)

67 relevant backend tests passed against an isolated PostgreSQL database (1,071 assertions), including the real queue pipeline, synchronous delivery, SMTP/HTTP failures, retries, obsolete verification skips, file-only previews, retention, access checks and log-write failures. All 449 frontend tests passed; ESLint, PHPStan, the production build and container injection checks passed. The admin table and branded preview were verified in a local browser with example messages; no real email was sent. Screenshots: `output/email-logs-2026-10-22/`.
