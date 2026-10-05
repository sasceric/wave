# Deploying Mercure on Debian with HestiaCP

This guide describes a single-server Debian deployment where Symfony and Mercure run on the same HestiaCP server. It assumes the Wave site is served at `https://wave.ba`, with Mercure on `https://mercure.wave.ba`.

Mercure is self-hosted and has no per-message service fee. It still uses the VM's CPU, memory, and disk. The Hub accepts publisher traffic only on loopback; browsers reach it through HTTPS on the Mercure subdomain.

```text
Symfony app ── HTTP ──> 127.0.0.1:3000 (Mercure Hub)
Browser ── HTTPS ──> mercure.wave.ba ── reverse proxy ──> 127.0.0.1:3000
```

## 1. Prepare DNS and the firewall

1. Create an `A` record for `mercure.wave.ba` pointing to the VM's public IPv4 address. Add an `AAAA` record only if IPv6 is configured and reachable.
2. In the Hestia firewall and any provider firewall, allow inbound TCP 80 and 443 for certificate issuance and browser traffic. Restrict SSH (TCP 22) to your management IP where possible.
3. Do **not** expose TCP 3000 publicly. The Hub should bind only to `127.0.0.1`; the reverse proxy handles public HTTPS.

The web server must support long-lived Server-Sent Events (SSE). Do not cache or buffer `/.well-known/mercure`.

## 2. Install the Hub binary

From the deployed Wave checkout, run the repository's installer as the checkout owner. It downloads the pinned Mercure release and verifies its published checksum:

```sh
cd /home/steelcodeweb/web/wave.ba/public_html
sudo -u steelcodeweb sh bin/install-mercure.sh
```

The binary will be at `/home/steelcodeweb/web/wave.ba/public_html/var/mercure/mercure`. Install it outside the deployable checkout and create a dedicated service account and persistent data directory:

```sh
sudo useradd --system --home /var/lib/mercure --shell /usr/sbin/nologin mercure
sudo install -d -o root -g root -m 0755 /opt/mercure
sudo install -d -o root -g mercure -m 0750 /etc/mercure
sudo install -d -o mercure -g mercure -m 0750 /var/lib/mercure
sudo install -o root -g root -m 0755 \
  /home/steelcodeweb/web/wave.ba/public_html/var/mercure/mercure /opt/mercure/mercure
```

If the `mercure` service account already exists, skip `useradd`. Keep `/var/lib/mercure` on persistent storage and include its Bolt database in the server backup plan.

## 3. Configure the Hub and its secret

Generate a new production secret on the server with a trusted random generator, for example `openssl rand -hex 32`. Use this production-only value for both Hub JWT keys and Symfony's `MERCURE_JWT_SECRET`. Do not reuse the local development secret, print it in deployment logs, or commit it.

Create `/etc/mercure/mercure.env` with `sudoedit` and set:

```dotenv
MERCURE_TRUSTED_ISSUERS=https://wave.ba
MERCURE_PUBLISHER_JWT_KEY=<production-secret>
MERCURE_SUBSCRIBER_JWT_KEY=<same-production-secret>
```

Protect the file so only root and the Mercure service group can read it:

```sh
sudo chown root:mercure /etc/mercure/mercure.env
sudo chmod 0640 /etc/mercure/mercure.env
```

Create `/etc/mercure/Caddyfile`:

```caddyfile
{
	auto_https off
}

http://mercure.wave.ba:3000 {
	bind 127.0.0.1
	mercure {
		issuer {$MERCURE_TRUSTED_ISSUERS} {
			publisher {
				jwt {env.MERCURE_PUBLISHER_JWT_KEY} HS256
			}
			subscriber {
				jwt {env.MERCURE_SUBSCRIBER_JWT_KEY} HS256
			}
		}
		cookie_name mercureAuthorization
		cookie_name mercureAuthorization
		cors_origins https://wave.ba

		transport bolt {
			path /var/lib/mercure/mercure.db
			size 10000
		}
	}

	header / Content-Type "text/plain; charset=utf-8"
	respond / "Wave Mercure Hub"
	respond "Not Found" 404
}
```

Allow the Mercure service account to read the Caddyfile:

```sh
sudo chown root:mercure /etc/mercure/Caddyfile
sudo chmod 0640 /etc/mercure/Caddyfile
```

`cors_origins` must list the browser origin of the Vue app exactly, including scheme and hostname, without a path. Add a second exact origin only if the app is intentionally served from it. The cookie name must stay aligned with `config/packages/mercure.yaml`.

## 4. Run the Hub with systemd

Create `/etc/systemd/system/mercure.service`:

```ini
[Unit]
Description=Wave Mercure Hub
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=mercure
Group=mercure
WorkingDirectory=/var/lib/mercure
EnvironmentFile=/etc/mercure/mercure.env
ExecStart=/opt/mercure/mercure run --config /etc/mercure/Caddyfile
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/mercure

[Install]
WantedBy=multi-user.target
```

Enable it and confirm it is listening only on loopback:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now mercure
sudo systemctl status mercure
curl -i -H 'Host: mercure.wave.ba' http://127.0.0.1:3000/
sudo ss -ltnp | grep ':3000'
```

Use `sudo journalctl -u mercure -f` to follow Hub logs.

## 5. Publish the Hub through HTTPS

### HestiaCP with Nginx

1. In Hestia, open **Web → Add Web Domain** and add `mercure.wave.ba` to the `steelcodeweb` account. Use the default Nginx proxy template. Enable SSL, Let's Encrypt, and HTTPS redirection, then save. DNS must already point to this server for Let's Encrypt to succeed.
2. Confirm the domain appears under **Web** in Hestia and that `/home/steelcodeweb/conf/web/mercure.wave.ba/` exists. Hestia creates this directory when the web domain is added. If it does not exist, do not try to write the include yet; check that the domain was created under the `steelcodeweb` account.
3. After the domain is created and its certificate is active, create the Hestia per-domain SSL include. Do not edit Hestia's generated Nginx virtual-host files; Hestia rebuilds those. Its default Nginx templates include files named `nginx.ssl.conf_*` from this directory:

```sh
sudo tee /home/steelcodeweb/conf/web/mercure.wave.ba/nginx.ssl.conf_mercure >/dev/null <<'NGINX'
location ^~ /.well-known/mercure {
    proxy_pass http://127.0.0.1:3000;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Connection "";
    proxy_buffering off;
    proxy_request_buffering off;
    proxy_cache off;
    proxy_read_timeout 24h;
    proxy_send_timeout 24h;
}
NGINX
sudo chown steelcodeweb:steelcodeweb \
  /home/steelcodeweb/conf/web/mercure.wave.ba/nginx.ssl.conf_mercure
sudo chmod 644 \
  /home/steelcodeweb/conf/web/mercure.wave.ba/nginx.ssl.conf_mercure
sudo nginx -t && sudo systemctl reload nginx
```

This routes only Mercure's endpoint to the Hub; other paths on the subdomain remain under Hestia's normal domain handling. If the Nginx template selected for this domain is custom, verify its SSL server block includes `/home/%user%/conf/web/%domain%/nginx.ssl.conf_*`; the Hestia default template does.

The Nginx snippet forwards `Host: mercure.wave.ba`. The Caddy site address
above must match that host; `bind 127.0.0.1` keeps the Hub private while
allowing the matching host. The `cors_origins https://wave.ba` directive in
the Caddyfile must match the Wave page's origin exactly. Because Hestia
terminates HTTPS before proxying to the Hub over HTTP, pin
`resource_identifier` to the same HTTPS Hub URL configured in Symfony; without
it, Mercure derives an HTTP audience and rejects Symfony's HTTPS-audienced JWT
with 401.

### Other Nginx or Caddy setups

For Caddy, add this site to its existing configuration; Caddy obtains and renews the TLS certificate when DNS and ports 80/443 are correct:

```caddyfile
mercure.wave.ba {
	reverse_proxy 127.0.0.1:3000
}
```

For a non-Hestia Nginx server, configure a TLS virtual host for `mercure.wave.ba` and proxy the Hub path without buffering SSE:

```nginx
location /.well-known/mercure {
    proxy_pass http://127.0.0.1:3000;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_buffering off;
    proxy_read_timeout 24h;
    proxy_send_timeout 24h;
}
```

Keep the existing certificate configuration for the Nginx virtual host. After changing the reverse proxy, validate its configuration and reload it using the commands appropriate for the installed web server.

## 6. Configure Symfony

Set these production values in the server's secret/environment configuration for the Symfony application. If using a `.env.local` file on the server, keep it outside version control and restrict its permissions:

```dotenv
MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=https://mercure.wave.ba/.well-known/mercure
MERCURE_JWT_SECRET=<same-production-secret>
MERCURE_ISSUER=https://wave.ba
```

`MERCURE_URL` is the private server-to-Hub address. `MERCURE_PUBLIC_URL` is the HTTPS address returned to browsers. `MERCURE_ISSUER` must exactly match `MERCURE_TRUSTED_ISSUERS`; the JWT secret must match both Hub keys. Keep the Mercure bundle configured for protocol `1.0`.

For this Hestia deployment, put these values in
`/home/steelcodeweb/web/wave.ba/public_html/.env.local`, preserving the other
production settings already there. Restrict the file, then regenerate Symfony's
compiled production environment and clear its cache:

```sh
sudo chmod 640 /home/steelcodeweb/web/wave.ba/public_html/.env.local
cd /home/steelcodeweb/web/wave.ba/public_html
COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=prod php8.4 "$(command -v composer)" dump-env prod
APP_ENV=prod php8.4 bin/console cache:clear
```

The Symfony Mercure component automatically scopes the subscriber cookie to
`.wave.ba` when the app origin is `wave.ba` and the Hub is on
`mercure.wave.ba`. In browser developer tools, check that `/api/me/realtime`
sets a secure, HTTP-only `mercureAuthorization` cookie for the Hub path. Never
copy its JWT value into logs or messages.

Deploy the Symfony app, then clear its production cache and restart PHP-FPM or the application workers if required by the deployment. Apply database migrations as part of the normal Wave deployment process.

The app also needs a scheduled command to send the one-hour unread-chat email reminder. After configuring Symfony's production mail transport, run it every five minutes as the deployment user; `flock` prevents overlapping executions:

```cron
*/5 * * * * cd /home/steelcodeweb/web/wave.ba/public_html && /usr/bin/flock -n /var/lock/wave-unread-message-reminders.lock /usr/bin/php8.4 bin/console app:send-unread-message-reminders --env=prod --no-interaction >> var/log/unread-message-reminders.log 2>&1
```

## 7. Verify end-to-end delivery

1. Confirm the Caddy Hub allows the Wave origin. This probe must return `Access-Control-Allow-Origin: https://wave.ba`:

   ```sh
   curl -i --max-time 3 \
     -H 'Host: mercure.wave.ba' \
     -H 'Origin: https://wave.ba' \
     'http://127.0.0.1:3000/.well-known/mercure?match=https%3A%2F%2Fwave.local%2Fusers%2F4'
   ```

   A request without a subscriber cookie should return 401; that is expected. Its `WWW-Authenticate` resource metadata URL should begin with `https://mercure.wave.ba/`, confirming that the Hub uses the pinned HTTPS identity. Then run the same probe through HTTPS (remove the Host header):

   ```sh
   curl -i --max-time 3 \
     -H 'Origin: https://wave.ba' \
     'https://mercure.wave.ba/.well-known/mercure?match=https%3A%2F%2Fwave.local%2Fusers%2F4'
   ```

   The public response must also include `Access-Control-Allow-Origin: https://wave.ba`. If the local probe has the header but the public one does not, verify the Hestia Nginx include is loaded.
2. Sign in to Wave as an approved user and open the browser's network tools. The `GET /api/me/realtime` response should set the `mercureAuthorization` cookie for the Hub; the EventSource request should stay open.
3. Use two approved accounts to exercise the campaign flow in [the local test instructions](../README.md#test-campaign-messaging-locally). Shortlisting or accepting an invitation should open the private chat and deliver a Mercure update.
4. Check `sudo journalctl -u mercure -f` and the web server logs if the SSE request fails. Verify the public origin in `cors_origins`, the issuer/secret values, the Hub public URL, TLS, and proxy buffering.

Web Push uses separate VAPID keys and remains opt-in per device. Configure its production keys separately in the Symfony environment; do not reuse development keys or put private keys in the repository.
