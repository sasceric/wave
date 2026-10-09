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
admin download endpoint checks `ROLE_ADMIN` and serves them as downloads with
`nosniff` and `no-store`; moderators and regular users cannot access them.

Receipts use the existing mailer/queue and branded email renderer. They contain
a ticket number and an unguessable private tracking link, without the report or
attachments. If receipt delivery cannot be queued, the ticket remains saved and
the UI explicitly asks the reporter to save their tracking link. A success flag
means the mailer accepted the receipt, not that the recipient's inbox delivered it.
Tracking secrets are in the link fragment to keep them out of initial page
requests and referer headers; CSRF-protected lookup sends the secret in a POST
body. Tracking returns only number, subject, received status and submission date.

Admins get a newest-first, server-paginated read-only list at `/admin/tiketi`,
with a three-dot details action and private downloads. This first version does
not add replies, assignment or status editing. Every report is `received`.

## Deployment and operations

Normal deployment applies `Version20261009090000` and builds the frontend.
No new environment settings, workers or external storage services are required.
The existing mail consumer handles receipts. Ensure the PHP-FPM account can
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
access rejection, download headers, pagination and frontend step/retry behavior.
