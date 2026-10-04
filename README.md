# Wave

Wave is a single-deployment creator–brand collaboration marketplace foundation. Symfony serves a Vue single-page app and its JSON API from the same origin; there are no Twig templates and no separate frontend project.

## Runtime and stack

The checked-in lockfiles resolve Symfony 8.1, Vue 3.5.43, Vue I18n 11, Vite 8.3.2, and `vite-plugin-pwa` 1.3. Symfony 8.1 requires PHP 8.4 or newer. Vite 8 requires Node 20.19 or newer; the setup was verified with Node 22.20.

Local persistence uses SQLite. PHP needs the `pdo_sqlite` extension. Node and npm are used only to build and serve the Vue source.

## Project layout

| Path | Contents |
| --- | --- |
| `src/Controller/Api/` | JSON API endpoints |
| `src/Api/` | Request helpers and API resource serializers |
| `src/Entity/` | Doctrine marketplace and account entities |
| `src/Account/` | Account tokens, mail delivery, and email rendering |
| `src/Localization/` | API messages and server-side locale handling |
| `templates/emails/` | Editable HTML email templates with escaped placeholders; no Twig views |
| `frontend/src/views/` | Route-level Vue pages |
| `frontend/src/components/shared/` | Reusable design and status components |
| `frontend/src/components/shared/MediaUploadField.vue` | Reusable owned-image upload control |
| `frontend/src/components/companies/` | Company directory cards |
| `frontend/src/components/account/` | Account access and dashboard panels |
| `frontend/src/components/creators/`, `campaigns/` | Marketplace cards grouped by domain |
| `frontend/src/composables/` | Reusable Vue state and lifecycle logic |
| `frontend/src/lib/` | API client and marketplace constants |
| `frontend/src/locales/` | Bosnian, Croatian, Serbian, Slovenian, and English catalogs |
| `migrations/`, `tests/` | Database migrations and backend tests |

## Run locally

From the project root, use the PHP 8.4 and Node 22 executables without changing Homebrew's global PHP link:

```sh
cd ~/Projects/wave
export PATH="/opt/homebrew/opt/php@8.4/bin:$HOME/.nvm/versions/node/v22.20.0/bin:$PATH"
composer install
npm --prefix frontend install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:seed-demo-data
npm --prefix frontend run build
```

Start Symfony's local PHP server:

```sh
php -S 127.0.0.1:8000 -t public public/router.php
```

Open <http://127.0.0.1:8000>. For frontend hot reload, leave Symfony running and start Vite in a second terminal:

```sh
cd ~/Projects/wave
export PATH="/opt/homebrew/opt/php@8.4/bin:$HOME/.nvm/versions/node/v22.20.0/bin:$PATH"
npm --prefix frontend run dev -- --host 127.0.0.1
```

Then open <http://127.0.0.1:5173>; Vite proxies `/api` to Symfony on port 8000.

Wave uses the self-hosted Mercure Hub for live campaign messages and in-app notification updates. Docker is not required for local development. In a third terminal, install the checksum-verified native Hub binary once and run it:

```sh
cd ~/Projects/wave
./bin/install-mercure.sh
PATH="/opt/homebrew/opt/php@8.4/bin:$PATH" php bin/mercure-local.php
```

The local Hub listens on `http://127.0.0.1:3000`. Keep the development URLs and shared local secret in `.env`; production must provide its own secret values through the deployment environment. For local Valet domains, set `MERCURE_URL` and `MERCURE_PUBLIC_URL` to a Hub URL on the same host as the app, update the Caddy `cors_origins`, and ensure the Hub is served over HTTPS if the Valet site is HTTPS.

Browser push uses standard Web Push and a VAPID key pair. For local testing, keep the private key in the ignored `.env.local` file and never commit it. Generate a key pair once with:

```sh
PATH="/opt/homebrew/opt/php@8.4/bin:$PATH" php -r 'require "vendor/autoload.php"; $keys = \Minishlink\WebPush\VAPID::createVapidKeys(); file_put_contents(".env.local", "\nWEB_PUSH_PUBLIC_KEY=".$keys["publicKey"]."\nWEB_PUSH_PRIVATE_KEY=".$keys["privateKey"]."\nWEB_PUSH_SUBJECT=mailto:notifications@wave.ba\n", FILE_APPEND | LOCK_EX);'
```

The app requests notification permission only when a signed-in user chooses **Enable device notifications**. In-app notification and message updates use private per-user Mercure topics; the Hub payload contains identifiers only, and the app reloads authorized details from Symfony. The service worker handles background push and notification clicks. It precaches static app assets only, not API responses or private content. The header's install button opens the native install prompt where available and provides the iOS Add to Home Screen hint.

Rebuild the frontend after changes with `npm --prefix frontend run build`. Vite emits the app shell, hashed assets, web app manifest, and service worker into Symfony's `public/` directory. The build intentionally does not empty that directory, so it won't delete Symfony's `index.php`.

## API

All endpoints are same-origin JSON routes. Public pages and demo content support Bosnian (default), Croatian, Serbian (Latin), Slovenian, and English. The language choice is saved in the browser and API responses use the selected `?locale=` value.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET` | `/api/health` | Basic service health |
| `GET` | `/api/auth/csrf` | Get a CSRF token for the current session |
| `GET` | `/api/auth/oauth/providers` | List configured social sign-in providers |
| `GET` | `/api/auth/oauth/{provider}/start` | Start Google or Apple sign-in/registration |
| `GET` | `/api/auth/oauth/{provider}/callback` | Validate the provider callback and sign in or begin registration |
| `GET` | `/api/auth/oauth/pending` | Read the current session's unexpired social-registration details |
| `POST` | `/api/auth/oauth/complete` | Complete the required creator/company profile after social registration |
| `POST` | `/api/auth/register` | Create a creator or company account and sign in |
| `POST` | `/api/auth/login` | Sign in |
| `POST` | `/api/auth/logout` | Sign out |
| `GET` | `/api/auth/me` | Current account and public profile |
| `POST` | `/api/auth/verify-email` | Consume a one-time email verification token |
| `POST` | `/api/auth/verification-email` | Resend the signed-in user's verification email |
| `POST` | `/api/auth/password-reset-requests` | Request a password reset without revealing whether an email is registered |
| `POST` | `/api/auth/password-resets` | Consume a one-time token and set a new password |
| `PUT` | `/api/me/profile` | Update the signed-in creator or company profile |
| `GET` | `/api/me/campaigns` | List the signed-in company's campaigns |
| `POST` | `/api/company/campaigns` | Publish a campaign |
| `PUT` | `/api/company/campaigns/{id}` | Edit or close a campaign owned by the signed-in company |
| `POST` | `/api/campaigns/{slug}/applications` | Apply to an open campaign as a creator |
| `GET` | `/api/me/applications` | List the creator's applications |
| `GET` | `/api/company/campaigns/{slug}/applications` | Review applications for an owned campaign |
| `POST` | `/api/company/applications/{id}/shortlist` | Open the private campaign chat for a selected applicant |
| `POST` | `/api/company/applications/{id}/offer` | Send an offer within the campaign's budget |
| `POST` | `/api/company/applications/{id}/reject` | Reject a pending application |
| `GET` | `/api/me/offers` | List offers sent to the creator |
| `POST` | `/api/me/offers/{id}/respond` | Accept or reject an offer |
| `POST` | `/api/company/campaigns/{slug}/invitations` | Send a pending campaign invitation to a creator |
| `GET` | `/api/me/invitations` | List campaign invitations received by the creator |
| `POST` | `/api/me/invitations/{id}/respond` | Accept or decline an invitation; acceptance opens a private chat |
| `GET` | `/api/me/conversations` | List the signed-in user's campaign conversations |
| `POST` | `/api/company/campaigns/{slug}/conversations` | Start or continue a private chat after shortlisting an applicant |
| `GET` / `POST` | `/api/me/conversations/{id}/messages` | Read or send participant-only campaign messages |
| `GET` | `/api/me/notifications` | List the signed-in user's in-app notifications |
| `GET` | `/api/me/realtime` | Authorize a private Mercure subscription for the signed-in user |
| `GET` | `/api/me/push/config` | Read the public Web Push configuration |
| `POST` / `DELETE` | `/api/me/push-subscriptions` | Subscribe or unsubscribe this device from push notifications |
| `POST` | `/api/me/notifications/{id}/read` | Mark one notification as read |
| `POST` | `/api/me/notifications/read-all` | Mark all notifications as read |
| `GET` | `/api/me/bookmarks` | List the creator's saved open campaigns |
| `POST` / `DELETE` | `/api/campaigns/{slug}/bookmark` | Save or remove an open campaign for the signed-in creator |
| `GET` | `/api/moderation/catalog` | List editable categories and creator FAQs for moderators |
| `POST` / `PUT` | `/api/moderation/catalog/categories[/{id}]` | Create or edit localized marketplace categories |
| `POST` / `PUT` | `/api/moderation/catalog/faqs[/{id}]` | Create or edit localized creator FAQs |
| `GET` | `/api/marketplace/categories` | List active localized categories |
| `GET` | `/api/marketplace/creator-faqs` | List active localized creator FAQs |
| `GET` | `/api/homepage` | Load the configured homepage creator and campaign sections |
| `GET` | `/api/admin/dashboard` | Read marketplace counts and homepage curation options as an admin |
| `PUT` | `/api/admin/homepage` | Save creator mode and featured creator, company, and campaign selections |
| `GET` | `/api/admin/email-templates` | List locale-specific outgoing email templates and available variables |
| `PUT` | `/api/admin/email-templates/{key}` | Save an email subject and HTML body as an admin |
| `POST` | `/api/admin/registrations/{id}/approve` | Approve a registered, email-verified creator or company account |
| `DELETE` | `/api/admin/{resource}/bulk-delete` | Delete selected registrations, creators, companies, or campaigns |
| `GET` | `/api/creators?q=&category=&limit=` | Public creator directory |
| `GET` | `/api/creators/{slug}` | Creator media kit and public profile |
| `GET` / `POST` | `/api/media[?folder=...]` | List owned media or upload an image with CSRF protection |
| `GET` | `/api/media/{id}/file` | Serve an owned or publicly associated image |
| `DELETE` | `/api/media/{id}` | Delete owned media that is not in use |
| `POST` | `/api/creators/{slug}/inquiries` | Send a company collaboration request for a creator or package |
| `GET` | `/api/me/inquiries` | List the signed-in user's direct collaboration requests |
| `POST` | `/api/me/inquiries/{id}/decision` | Creator accepts or rejects a pending request |
| `GET` / `POST` | `/api/me/inquiries/{id}/messages` | Read or send participant-only chat after acceptance |
| `GET` | `/api/companies` | Public company profiles |
| `GET` | `/api/companies/{slug}` | Public company profile |
| `GET` | `/api/campaigns?q=&category=&company=&featured=&limit=` | Open campaign briefs |
| `GET` | `/api/campaigns/{slug}` | Public campaign detail |

Creator accounts can manage public media kits, a headline, up to five marketplace categories, self-authored profile FAQs, self-reported social metrics, up to four portfolio items, and collaboration packages with optional prices. Campaign budgets, package prices, collaboration requests, and offers support BAM, EUR, and RSD; BAM is the default for existing and new records. Creators can save open campaigns from their cards, sign in or register to finish saving, and manage saved campaigns from their account. Images are uploaded as JPEG, PNG, or WebP files up to 10 MB. Wave stores each file outside the public web root under `var/media/{folder}/{owner}/{year}/{month}/`, records it in the media library, and associates profile images by media ID. Creator avatars, portfolio images, and company logos use separate media folders. Unassociated uploads remain private to their owner; referenced profile images are public. Portfolio videos are embedded only from YouTube or Vimeo. Creator profiles show a three-item desktop gallery with a lightbox for the full portfolio and a swipeable mobile slider. The sticky header includes creator search and links the query to the creator directory. Company accounts can send a request against a package or make a general collaboration request, optionally including a proposed amount. A request is not a purchase or binding deal: the creator can accept or reject it, and participant-only chat becomes available only after acceptance. No checkout, cart, or payment handling is included.

Campaign conversations are separate from direct creator inquiries and unique to a campaign/creator pair. Shortlisting an application opens the private thread and sends a notification; the creator may then start the conversation. A company invitation stays pending until its creator accepts or declines. Accepting creates a private thread seeded with the invitation message; each creator has a separate thread, never a group chat with other applicants. Offers remain a separate decision after discussion. In-app notifications and chat updates stream through authenticated Mercure subscriptions; if the connection is interrupted, the inbox includes a manual refresh control. Browser push is opt-in per device. No private chat or API data is cached by the PWA.

Categories and shared creator FAQs are localized in Bosnian, Croatian, Serbian (Latin), Slovenian, and English, and moderators can create, edit, reorder, or deactivate them. Registration requires country, manually entered city, and phone for either account type. Creators provide first and last name; companies provide a company name. Registration uses the active country-code list from the local Shopware country catalog. Creator social metrics remain self-reported; metric update dates are assigned by the server. Company accounts can edit their public profile, publish and manage campaign briefs, and review creator applications. Open campaigns appear publicly immediately; Wave does not moderate campaign content in this MVP. A trusted operator grants moderator access to an existing account from the server console with `php bin/console app:moderator moderator@example.com grant`; revoke it with `php bin/console app:moderator moderator@example.com revoke`. A moderator signs in and opens the moderation area from their account dashboard.

The admin dashboard is limited to accounts granted `ROLE_ADMIN` by a trusted operator with `php bin/console app:admin admin@example.com grant` (`revoke` removes access). Sign out and back in after changing the role, then open the dashboard from the account page. The full-width dashboard has a statistics overview, a registration approval queue, homepage curation, email templates, and creator, company, and campaign lists. New registrations must verify their email and be approved by an admin before they are publicly visible or can use marketplace actions; the migration preserves approval for existing accounts. Reusable tables provide text and status filters, sortable columns, page-size controls, row selection, bulk deletion, and per-row actions. Deleting a creator or company also deletes its owned account, and company deletion can remove its campaigns and related marketplace content. Admins can choose whether the homepage creator section shows latest or featured profiles, feature creators and campaigns for homepage curation, feature companies to rank them first in the public company directory, and edit localized verification, password-reset, and contact email templates for Bosnian, Croatian, Serbian (Latin), Montenegrin, Slovenian, and English. Select the email language independently in the template editor; each locale has separate saved subjects and HTML, and previews use sample content in that language. New outgoing verification, password-reset, and contact messages use localized defaults until an admin saves a custom template. Template previews run in a sandboxed modal, available variables can be inserted into the HTML source, and saved templates are used by subsequent emails. Admin writes enforce role, session, and CSRF checks. The trusted `app:moderate-campaign` command remains available for emergency server-side campaign review. Campaigns and applications are manually mediated.

### Search engine metadata

Set `APP_BASE_URL` to the site's public origin (scheme and host, with no path) in each deployment. Symfony emits route-specific titles, descriptions, Open Graph/Twitter metadata, canonical URLs, and `hreflang` alternates in the initial HTML response; the Vue SEO helper keeps the same metadata current during in-app navigation. Bosnian is the `x-default` language, and Serbian alternates use `sr-Latn`. Public creator and company profiles and open, approved campaigns receive schema.org structured data; campaign briefs use `CreativeWork` rather than employment `JobPosting` markup.

`GET /sitemap.xml` dynamically lists localized public landing pages and public profiles/campaigns with multilingual alternate links. `GET /robots.txt` advertises that sitemap, leaves public APIs available for crawler rendering, and disallows private account, authentication, moderation, and admin API routes. Private frontend routes also receive `noindex, nofollow` and are excluded from the sitemap.

Registration and login use hashed passwords, rate limits, and same-origin server sessions with HTTP-only, same-site cookies and CSRF validation on all writes. Obtain the current CSRF token from `/api/auth/csrf` and send it in the `X-CSRF-Token` header. Email/password registrations must verify their email before the admin can approve the account; verified accounts still wait for admin approval before participating in marketplace actions. The registration email explains both steps, and an approval email is sent when an admin approves the account. Google and Apple registration accept the provider's verified email, still collect all required creator/company profile fields, send a registration-pending email, and remain unapproved until an admin reviews them. Profile editing and reading remain available while approval is pending. Verification links expire after 24 hours, reset links after one hour, and both are single-use. Password-reset requests return the same response whether or not an account exists.

### Google and Apple sign-in

Google and Apple buttons are always visible on the login and registration screens. A button is enabled only after that provider's required credentials are configured. You can set up either provider independently.

| Environment variable | Provider | Purpose |
| --- | --- | --- |
| `GOOGLE_OAUTH_CLIENT_ID` | Google | OAuth client ID |
| `GOOGLE_OAUTH_CLIENT_SECRET` | Google | OAuth client secret |
| `GOOGLE_OAUTH_REDIRECT_URI` | Google | Exact registered callback URL |
| `APPLE_OAUTH_CLIENT_ID` | Apple | Services ID |
| `APPLE_OAUTH_TEAM_ID` | Apple | Developer team ID used to sign client assertions |
| `APPLE_OAUTH_KEY_ID` | Apple | Sign in with Apple key ID |
| `APPLE_OAUTH_PRIVATE_KEY` | Apple | Contents of the `.p8` signing key |
| `APPLE_OAUTH_REDIRECT_URI` | Apple | Exact registered callback URL |

Register callbacks at `https://your-domain/api/auth/oauth/google/callback` and `https://your-domain/api/auth/oauth/apple/callback`. For local development, use the exact Symfony origin and callback path (for example, `http://127.0.0.1:8000/api/auth/oauth/google/callback`). Apple client assertions are signed server-side from the private key and short-lived; Google and Apple ID tokens are checked for signature, issuer, audience, expiry, nonce, and verified email. The provider identity is stored separately from the Wave email/password login. Existing accounts are linked only after the provider confirms the matching email address. New social accounts retain the normal profile requirements and moderation queue.

#### Google setup

1. In [Google Cloud Console](https://console.cloud.google.com/), create or select a project, configure the OAuth consent screen, and add your account as a test user while the app is in testing.
2. Create an OAuth client with application type **Web application**. Add the callback URL to **Authorized redirect URIs** exactly as configured in Wave, including scheme, host, port, and path. For a local Symfony server on port 8000, use `http://127.0.0.1:8000/api/auth/oauth/google/callback`; for production, use your public HTTPS domain. Do not use the Vite development port unless Symfony itself serves that callback there.
3. Copy the generated client ID and client secret into the environment variables below.

#### Apple setup

1. Enroll in the [Apple Developer Program](https://developer.apple.com/programs/) and open **Certificates, Identifiers & Profiles**.
2. Create a **Services ID**, enable **Sign in with Apple**, and configure its domain and return URL. The return URL must exactly match Wave's callback. Apple web sign-in requires a verified HTTPS domain, so use a public HTTPS staging/production domain rather than a local HTTP address.
3. Create a **Sign in with Apple** key, download its `.p8` private key (Apple only makes the download available once), and record the key ID and your Team ID. Use the Services ID as `APPLE_OAUTH_CLIENT_ID`.
4. Complete Apple's domain verification steps for the chosen domain.

For local development, put the settings in the ignored `.env.local` file; for deployment, use the platform's secret manager. Never commit client secrets or Apple private keys. Keep the Apple private key as a multiline PEM value:

```dotenv
GOOGLE_OAUTH_CLIENT_ID="your-google-client-id"
GOOGLE_OAUTH_CLIENT_SECRET="your-google-client-secret"
GOOGLE_OAUTH_REDIRECT_URI="http://127.0.0.1:8000/api/auth/oauth/google/callback"

APPLE_OAUTH_CLIENT_ID="your-apple-services-id"
APPLE_OAUTH_TEAM_ID="your-apple-team-id"
APPLE_OAUTH_KEY_ID="your-apple-key-id"
APPLE_OAUTH_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----
paste-the-key-body-here
-----END PRIVATE KEY-----"
APPLE_OAUTH_REDIRECT_URI="https://your-domain/api/auth/oauth/apple/callback"
```

Use the same callback URL in the provider console and Wave configuration. Restart Symfony after changing local environment values. Check `GET /api/auth/oauth/providers`; it should report `true` for each configured provider, and those buttons will then be enabled.

### Email delivery

Symfony Mailer builds its SMTP DSN from `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, and `MAIL_PASSWORD`; the username and password are URL-encoded when constructing the DSN, and `require_tls=true` enforces STARTTLS. Account messages and the public contact form use `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`; contact submissions are sent to `info@wave.ba`, with the visitor's address used only as `Reply-To`. Set these variables in the local environment or deployment secret store; never commit real SMTP credentials. `APP_BASE_URL` must be the public origin where the Vue app is served.

Verification, social-registration pending, account-approval, and password-reset emails share the editable HTML layout in `templates/emails/account_action.html`; contact notifications use `templates/emails/contact.html`. `EmailTemplateRenderer` replaces `{{variable}}` placeholders with HTML-escaped values. The admin editor lists the variables for each template and saves locale-specific subject and HTML overrides in the `email_template` table. The account language selected at registration is retained for approval mail. No Twig or Vue rendering is involved in email delivery.

For local email testing, install Mailpit and run it in its own terminal:

```sh
brew install mailpit
mailpit
```

In the terminal that runs Symfony, configure its SMTP sink (Mailpit's web inbox is at <http://127.0.0.1:8025>):

```sh
export MAIL_MAILER="smtp"
export MAIL_HOST="127.0.0.1"
export MAIL_PORT="1025"
export MAIL_USERNAME=""
export MAIL_PASSWORD=""
export MAIL_ENCRYPTION="tls"
export MAIL_FROM_ADDRESS="no-reply@wave.example"
export MAIL_FROM_NAME="Wave"
export APP_BASE_URL="http://127.0.0.1:8000"
```

Then start or restart the Symfony server from that terminal. Use provider-issued SMTP credentials and a sender address authorized by the provider in other environments.

This project intentionally defers payments, escrow/wallet, social analytics imports, affiliate tracking, AI matching, e-signatures, and complex chat.

## Database and tests

Schema changes are managed with Doctrine Migrations. After changing entities, generate and apply a migration:

```sh
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate --no-interaction
```

Run the API tests with:

```sh
php bin/phpunit
```

The sample-data command is safe to rerun; it inserts missing demo records, refreshes their translations, and fills demo portfolio, package, category, and FAQ fields only when those fields are empty.

## Deployment

Build `frontend/` and deploy its generated `public/` files together with the Symfony application. Configure the web server document root to `public/`, serve existing files directly, and send other paths to `public/index.php`; this lets direct links such as `/creators/maya-chen` load the Vue app while `/api/*` stays in Symfony. Run Doctrine migrations during deployment. Ensure the PHP process can write to `var/media/`, and configure `upload_max_filesize` to at least `10M` and `post_max_size` above `10M`. Set a unique production `APP_SECRET` and an appropriate `DATABASE_URL` via the environment; do not use the development values from `.env`.

The PWA is responsive and installable, and precaches its public app shell and static assets only. API routes are excluded from navigation fallback, and no API/private responses are added to a runtime cache.
