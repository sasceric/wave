# Support tickets

The support icon in the existing header opens `/podrska` (localized in all six
languages). Guests and signed-in users can report problems, ask questions or
suggest improvements. Tickets use a three-step contact/details/review flow and
show the shared success modal only after the API confirms persistence.

`POST /api/support/tickets` requires the existing session CSRF token and allows
five submissions per IP per hour. Contact details, kind, category, subject and
20–2000-character description are validated on the server. A random submission
key lets retries return the same ticket without sending another receipt.

Up to three PNG/JPEG/WebP/GIF/MP4/PDF attachments are accepted, each at most 10 MiB.
The server checks upload success, actual MIME type and byte size. JPEG, PNG and
WebP images use the existing image processor: proportional, uncropped WebP up to
2560 px wide, quality 85 for JPEG photos and lossless encoding for screenshots.
This keeps screenshot text readable; GIFs retain their original animation, and
video/PDF files remain in their native format. Files receive
random storage names under `var/support`, outside the public directory. The
download endpoints check ticket ownership or `ROLE_ADMIN` on every request,
including reply files. Internal-note attachments are admin-only. Downloads use
`nosniff` and `no-store`; knowing a ticket/message/file ID never grants access.
Image attachments open in a native modal with a download action. Their `/preview`
endpoints use the same ownership checks and return only validated image MIME
types inline; PDFs and videos keep the download behavior.

Receipts use the existing mailer/queue and branded email renderer. They contain
a ticket number and an unguessable private tracking link, without the report or
attachments. If receipt delivery cannot be queued, the ticket remains saved and
the UI explicitly asks the reporter to save their tracking link. A success flag
means the mailer accepted the receipt, not that the recipient's inbox delivered it.
Tracking secrets are in the link fragment to keep them out of initial page
requests and referer headers; CSRF-protected lookup sends the secret in a POST
body. Tracking shows the current status, submission date and cursor-paged public
replies, with no contact details, private files or internal notes.

## Accounts and the support inbox

Signed-in submissions store the authenticated user as owner, regardless of the
contact email entered in the form. Guest submissions match an existing account
by normalized email. Migration `Version20261009110000` also links older reports
by email. Reports created before registration can subsequently be claimed only
by the account with that verified email; an existing owner is never replaced.

Customers open `/racun/tiketi` from **Moji tiketi** in their account navigation.
They can see and reply only to their own reports. Admins open `/admin/tiketi`
and see every report, including unmatched guest reports. Support access remains
available for signed-in accounts awaiting verification/approval so they can report
account problems; marketplace access rules are unchanged. Moderators have no
support staff privileges.

Both inboxes use a 30-row cursor list ordered by latest public activity and ID,
with search and status counts. Conversation history initially loads the latest
30 replies, then older batches on scroll or the accessible load button. The base
inbox never selects a ticket automatically; notification/email links select the
explicit ticket ID. Admins can change status, priority, category and assignment,
and write internal notes. Customers cannot see notes, note attachments or their
content in list previews. A customer reply reopens a resolved report.
Both inboxes reuse the existing account/admin sidebar. Status changes create
public timeline entries, including automatic changes after a reply. Selecting
the same status again creates no duplicate entry or alert. The stored status
code is localized when the inbox renders it; reply retries reuse the same event.

Replies require CSRF and an idempotency key and allow 30 new replies per user per
hour. They reuse the original upload validation/compression limits. Public replies
send persisted notifications and queued branded emails containing a ticket link,
without copying the reply body or attachments into the email. New reports notify
support admins; customer replies notify the assigned admin or all admins if
unassigned. Admin replies/status changes notify the owner (or the original guest
email if there is no owner). Internal notes send no customer alerts. Existing
notification delivery updates the bell; ticket history has no live subscription,
typing/presence feature or polling. Refresh explicitly to retrieve new history.
Read acknowledgements clear only that viewer's alerts through the notification ID
observed at open time, preserving any newer alerts.

## Deployment and operations

Normal deployment applies `Version20261009090000`, `Version20261009110000`,
and `Version20261009160000` (status timeline metadata), then builds the frontend.
No new environment settings, workers or external storage services are required.
The existing mail consumer handles receipts and reply alerts. Ensure the PHP-FPM account can
write `var/support`; the production workflow already assigns `var/` to
`steelcodeweb`. Include `var/support` with the existing persistent-file backups.

PHP's `upload_max_filesize` should be at least `10M` and `post_max_size` at least
`32M` for three maximum-sized files plus form metadata; align the proxy request
body limit. Smaller host limits cause submission validation to reject the upload.
Do not expose `var/support` through an Nginx/Apache alias. Support records and
attachments contain personal information and should be retained only as long
as needed for support; this initial module does not automatically delete them.

Validation includes guest/CSRF/rate-limit flows, retries, receipt localization,
tracking privacy, actual MIME/size/count rejection, guest/user/moderator admin
access rejection, download headers, cursor pagination, history batches, owner isolation, private
notes, reply compression, notification links/read watermarks, status editing,
frontend stale-response protection and step/retry behavior.
