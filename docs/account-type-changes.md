# Account type changes and private company details

Settings includes a request form for creator → brand/agency and brand/agency →
creator changes. It does not create another login. Admin → Account type requests
lists pending and historical requests, with approval, rejection and review notes.
Owners may withdraw a pending request. There can be only one pending request per
account, enforced by both a row lock and a PostgreSQL partial unique index.

`AccountTypeController` exposes:

- `GET/POST /api/me/account-type-requests` — owner history and submission.
- `DELETE /api/me/account-type-requests/{id}` — withdraw, without deleting history.
- `GET /api/admin/account-type-requests` — admin-only status-filtered pagination.
- `PUT /api/admin/account-type-requests/{id}` — approve/reject with a response.
- `PUT /api/me/company-details` — owner-only optional company ID, VAT ID and
  owner's full name. These fields appear in private company resources and admin
  profile review, never public profiles, search or listing cards.

Mutations require CSRF; submission also requires verified email. Approval locks the
request and owner in one PostgreSQL transaction, refreshes their current data and
refuses stale/already reviewed requests. It preserves approval/verification flags,
passwords, OAuth identities, credits, notification choices and the user ID.

`AccountTypeChanges` merges descriptions, categories and compatible social links,
uses the requested new display name and carries across the profile picture when
the target has none. Existing target details take precedence over source fallback
values. The same creator/provider record retains portfolio, packages, FAQs,
presentation and all existing foreign-key history. Brand-only fields are retained
on the company's archived record; they are not made public on a creator profile.
Already associated profile images remain valid when saving after a switch, even
when their original upload folder belongs to the previous account type.

A former creator is marked as a private provider when switching to a company.
A former company's profile is archived when switching to a creator and excluded
from public directory/facets, search, sitemap and SEO metadata. Neither profile nor
its collaborations is deleted. Switching back reuses the existing records rather
than duplicating them. Completed campaign and direct-chat history continues to use
the same participant/owner IDs; client reviews are not relabelled as provider reviews.

Approval waits when the target would remove tools needed for active work:
creator/provider → brand requires accepted provider work to be completed; company
→ creator requires owned open campaigns and accepted client work to be finished.
Agencies retain both tool sets. Rejection requires an explanatory note.

Approvals and rejections persist a bell notification and use existing notification
and push delivery; disabled device notifications do not remove the bell record.
New requests notify admins. Destinations open the request list or owner Settings.
The app refreshes its current-user resource on a newly received switch notice.
Account deletion removes request text/history and clears private company fields.

Deploy migration `Version20261027160000` through the existing production workflow.
It adds nullable company details, a default-false archive flag and the request table;
existing account types are unchanged. Rollback refuses to discard saved details or
request history.
