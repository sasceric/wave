# Meta advertising measurement

Wave uses a consent-controlled browser Pixel. No Conversions API access token,
server-side event forwarding or advertising campaign is configured by this change.
The Pixel/Dataset ID is a public browser identifier, not a secret.

## Configuration

Create a website dataset in Meta Events Manager for the Wave business portfolio.
The account owner must accept Meta Business Tools Terms. Copy its Pixel ID from
the manual installation code or dataset settings; do not paste Meta's base code
or a noscript image into Wave's HTML. Those would bypass Wave's consent gate.

Put these variables in `frontend/.env.production` **on the production server**:

```dotenv
VITE_META_PIXEL_ENABLED=true
VITE_META_PIXEL_ID=1845189730242577
VITE_META_PIXEL_HOSTS=wave.ba,www.wave.ba
```

This is the Wave Pixel ID read from Events Manager on 2026-10-21. Keep an existing
`VITE_GA_MEASUREMENT_ID` setting. `.env.production` is ignored by Git and will not
be transferred by a code push. The deployment workflow builds on the server, so
server configuration is required. Symfony's root `.env.local` is not read by Vite.

Rebuild after configuration changes:

```bash
npm --prefix frontend run build
```

Set `VITE_META_PIXEL_ENABLED=false` and rebuild to disable the integration.
A missing/invalid ID also disables it. Both development and production require an
explicit allowed hostname; defaults are the two Wave production hosts. Do not add
localhost/staging to the real Pixel's allowlist. Use a separate test dataset if
live sandbox testing is necessary. Never put a Conversions API token, application
secret or private key in any `VITE_` variable. See `frontend/.env.example`.

## Consent and data

The existing cookie banner has independent Analytics and Marketing choices.
`wave-cookie-consent-v3` records them separately. Old v2 analytics permission is
preserved but never implies Marketing permission; the banner asks again. Rejection
loads no Meta script. Storage failures default to no permission. Other open tabs
observe preference changes through the storage event.

Marketing consent loads `connect.facebook.net/en_US/fbevents.js` only when an
eligible event is available. Automatic configuration is disabled in the Pixel
initialization; automatic advanced matching is not enabled by Wave. In Events
Manager, keep automatic advanced matching and automatic event detection disabled,
and do not add Event Setup Tool click rules or a second Pixel integration through
Google Tag Manager. Otherwise Meta-side settings may collect additional events.

Revocation sends the SDK's consent-revoke command, removes pending event/grant
commands if the SDK is still loading, stops Wave's future events, and expires
`_fbp`/`_fbc` cookies on accessible host/domain scopes. It cannot erase events
already received by Meta or third-party cookies outside this domain.

The SDK inherently receives technical browser/network data including IP, URL and
referrer. The application deliberately adds no names, email addresses, phone
numbers, messages, user IDs or financial values to event parameters. Private
account/admin/chat/support-tracking/token pages do not emit Wave PageView events.
Search, missing and noindex pages are excluded too. URLs with fragments or
unrecognized query parameters are rejected before initialization/events, because
the SDK can read the current URL independently of custom parameters. Supported
attribution tags use ASCII slug values (`utm_source`, `utm_medium`, `utm_campaign`,
`utm_content`, `utm_term`, `fbclid`); do not put personal data in these values.

## Events

| Event | Trigger | Parameters |
| --- | --- | --- |
| `PageView` | Resolved, indexable public SPA page, including navigation | `page_path`; random `eventID` in Pixel options |
| `CompleteRegistration` | Successful email signup or Google/Apple signup completion response | `content_name`, `account_type`, `status=true`, `registration_method`, known `company_type` |

The standard registration event targets this Pixel with `trackSingle`, includes a
random `eventID`, and fires once per account per page lifecycle. Account IDs are
used in memory only for duplicate prevention. Buttons disable duplicate submits.
Login, restored sessions, failed requests and profile edits do not fire registration.
Without consent there is no deferred registration replay. Staff and presentation
demo registrations are excluded. Blocking/failing tracking cannot prevent signup.

`account_type`/`content_name` are `creator` or `company`. Agencies remain companies;
`company_type=agency` is included only if already known in the signup response.
The current short registration form collects company type later in profile setup,
so a later switch to agency must not generate a second CompleteRegistration event.
Google/Apple completion is reported as `registration_method=social`; password signup
as `email`. Google Analytics behavior is unchanged.

These are registration events, not verified/approved/completed-profile events.
Use Wave's existing admin activation milestones for registration quality, profile
completion, approval and first real activity. This integration does not invent
extra conversions for those stages.

### Event IDs and the automatic Meta connection (checked 2026-10-26)

Both browser events now provide a random UUID in Pixel's `eventID` options. Each
actual public-page navigation, including returning to an earlier page, gets a new
ID; repeated metadata refreshes do not emit another PageView. The ID is unrelated
to account IDs, and does not change consent or which routes can be measured.

The live Meta account has an **active Conversions API · Web-only** business-level
connection. Meta's settings record its enrollment from Pixel creation on
2026-10-21, with **Connect all owned datasets automatically** selected. This is
separate from Wave's code, which contains no server event sender or CAPI token.

Events Manager's PageView deduplication report showed 0% total coverage, with
event IDs on 54.67% of browser events and 100% of the much smaller server volume.
The debugging sample included `ob3_plugin-set_` IDs on both sides for some rows,
alongside server-only rows and repeated copies. Therefore the sample does not
support claiming that every browser/server ID differs or that no events arrive.
These are historical/aggregated reports, not a live verification of this patch.

The CompleteRegistration overview also includes Meta-hosted CAPI events, and its
deduplication panel says deduplication has not been set up for that event. Wave
already supplies a registration event ID, so the PageView change must not be
described as fixing registration deduplication as well.

Adding the missing PageView ID fixes browser key coverage only after deployment.
It does **not** establish that Meta's automatic connection forwards that same ID,
or make server event volume equal browser volume. Confirm newly received matching
`event_name` and browser `eventID` / server `event_id` for the same action using
Test Events and fresh debugging samples. Any future explicit server integration
must reuse the browser ID, honor Marketing consent and avoid a second independent
sender. Do not generate new server IDs for browser copies, send personal profile
data to improve a score, or disconnect the business-wide connection without the
owner's approval.

## Meta setup and verification after deployment

1. Open Wave's dataset in Events Manager, verify Pixel ID `1845189730242577`,
   and confirm it belongs to the intended Wave business portfolio.
2. Review automatic-event/advanced-matching settings as above. Do not enable a
   portfolio-wide Conversions API connection as part of this browser-only setup.
3. In Test Events, open the public site without Marketing permission: no
   `fbevents.js`, Pixel requests or Meta cookies should be created by Wave.
4. Allow Marketing. Check a public PageView, then navigate to another public page.
   SEO refreshes must not duplicate it. Analytics-only permission must not load Meta.
5. Register a dedicated QA account once, with Marketing allowed. Confirm one
   CompleteRegistration with the right `account_type` and no personal custom data.
   Login, reload and profile editing must not count as new registrations.
6. Withdraw Marketing permission and verify no new Wave Pixel events and removal
   of accessible `_fbp`/`_fbc` cookies. Check another open tab receives the change.
7. Create two custom conversions based on `CompleteRegistration`, filtering
   `account_type=creator` and `account_type=company` respectively. Check the rules
   against a received event before choosing each conversion for an ad campaign.
   Agencies stay in the company conversion; never count them twice.

Automated tests intercept/queue events rather than sending synthetic production
conversions. Passing those checks does not claim Events Manager has received live
traffic. No deployment or ad publishing is performed by the integration work.

Official references:
- https://developers.facebook.com/documentation/meta-pixel/reference
- https://developers.facebook.com/documentation/meta-pixel/implementation/gdpr
- https://developers.facebook.com/documentation/meta-pixel/implementation/conversion-tracking


## Verified Meta account settings (2026-10-21)

The Wave.ba dataset belongs to `wave.ba.app`. Its automatic events and automatic
advanced matching were already off. With the owner's explicit approval, the
separate **Automatically include more detailed page and product info** option was
turned off and its saved Off state verified. Categories were left unassigned.
No ad account access was granted and no ad was published. Live event receipt still
requires deployment of the app integration and the server Vite configuration.
