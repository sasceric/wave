# Admin email templates

The redesign retains the existing template/language configuration in `email_template`.
It does not introduce a sent-email history or delivery-event table. Delivery attempts
and encrypted rendered previews continue through the existing file-based
[email logs](email-logs.md).

## Editing

The grouped catalog lists 20 supported templates. Recipient, trigger and frequency
explain existing application behavior; changing wording does not change those
triggers. Search and current-language customized/default filters narrow the list.
Language dots show customizations in other locales independently.

- **Tekst** changes supported wording fields without copying the built-in layout.
  Runtime data such as recipient names, URLs, campaign information and the
  stage-specific profile reminder message remain controlled by the application.
- **HTML (napredno)** changes the layout. Known variable buttons insert at the
  cursor. Unknown variables and active markup are refused by the API.
- The sandboxed preview updates as the draft changes. Desktop and mobile retain
  actual 640px and 375px email viewports, scaled to the available editor column.
  Only Wave's two bundled brand images are allowed; external tracking images,
  scripts, forms and parent-page access remain blocked.
- Sender details come from the configured server sender rather than a hardcoded
  display address. Subject variables are rendered consistently in preview, test
  email and actual delivery; control characters cannot create header line breaks.
- **Sačuvaj** persists only the selected language. **Pošalji test meni** uses the
  unsaved draft and example data, to the signed-in administrator's own address;
  it does not save the draft or accept a supplied recipient address. It uses the
  normal mail pipeline and requires verified admin access, CSRF and the existing
  test-send rate limiter.
- **Vrati zadano** confirms before deleting the selected locale's overrides.
  Other languages remain untouched. It also discards changes to an unsaved default.

Template/language changes and navigation protect dirty drafts. Browser refresh
uses the browser's unsaved-change warning. Requests in progress disable editing;
old language responses cannot replace the latest selection or save another
language's draft. Failed language loads provide a retry without exposing stale copy.

## Storage and deployment

`Version20261026150000` is the PostgreSQL migration for this redesign. It makes
`subject` and `html_body` nullable and adds nullable JSON `copy_overrides`. Existing
subjects and HTML are preserved. Null means use the current built-in default;
text-only changes therefore keep receiving later shared layout improvements.
Saving all defaults removes the unnecessary customization row.

Deploy backend/frontend together, run migrations and restart existing workers so
all delivery paths load the wording overrides. No additional service is needed.
Rollback refuses to discard text-only customizations automatically: export and
resolve them before restoring the former non-null schema.

## Completion checks (26 October 2026)

The prototype's grouped list, trigger/recipient/frequency facts, seven language
chips, statuses, text/HTML tabs, live preview, device switch, reset/test/save actions
are implemented. Completion fixes cover request races, unsaved navigation,
preview brand images, configured sender, consistent subject rendering and safe
rollback. PostgreSQL integration tests cover editing, delivery, language isolation,
permissions, reset and migration preservation. No real email is sent by these tests.

Validation result: 65 PostgreSQL integration tests passed (1,063 assertions),
including the migration and affected delivery paths; 510 frontend tests passed.
PHPStan, ESLint, production build and whitespace checks passed. Browser checks
covered the 1440px desktop layout, 390px mobile overflow, search, template/language
switching, draft cancellation/discard and preview widths. The existing local
PostgreSQL migration was confirmed applied. Test delivery used a null transport;
no real messages were sent. Screenshot: `output/email-template-redesign-2026-10-26/desktop.png`.
