# Local negotiation browser regression

The regression runs real Google Chrome and WebKit (Safari's engine) in separate sessions
against Symfony, PostgreSQL and a private Mercure hub. It does not mock the API. Native
Safari is a separate manual check. It never uses production or regular local accounts.

1. Install frontend dependencies and WebKit, and build the frontend:

   ```sh
   npm --prefix frontend ci
   cd frontend
   npx playwright install webkit
   cd ..
   npm --prefix frontend run build
   ```

2. Have PostgreSQL running locally and create a dedicated database named
   `wave_browser_e2e`. The script refuses other database names and non-local hosts.
   **All data in this dedicated database is reset each run.** Do not use it for accounts
   or anything you need to keep. For example, using your local PostgreSQL login:

   ```sh
   createdb -h 127.0.0.1 wave_browser_e2e
   ```

3. Install the project's local Mercure binary following the existing
   [quick local commands](local-quick-run.md), or provide its path with `MERCURE_BIN`.
   Google Chrome and PHP 8.4 with PostgreSQL support must be installed.

4. Run with your local PostgreSQL credentials in the environment:

   ```sh
   export WAVE_E2E_DATABASE_URL='postgresql://YOUR_LOCAL_USER@127.0.0.1:5432/wave_browser_e2e?serverVersion=18&charset=utf8'
   PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php npm --prefix frontend run test:browser
   ```

The test starts and stops its own backend on `127.0.0.1:8027` and Mercure on
`127.0.0.1:8028`; occupied ports cause it to stop before resetting fixtures. Override
with `WAVE_E2E_PORT` and `WAVE_E2E_HUB_PORT` if needed. It clears only the `browser`
environment cache and its rate-limiter pool, so repeated fixture logins remain repeatable. Mail, queued deliveries and external resource requests are disabled;
temporary credentials and Mercure signing keys are generated for the run. Normal local
backend, frontend and Mercure processes are not stopped or reconfigured.

The result prints an artifact directory with screenshots, a JSON result and service logs.
Use `WAVE_E2E_ARTIFACTS` to select a private output directory. `WAVE_E2E_CHROME_PATH`
can select a nonstandard Chrome installation; `PLAYWRIGHT_BROWSERS_PATH` can select a
custom WebKit cache. Keep generated logs and credentials out of commits.


The browser regression also checks an accepted hire on a closed campaign: both sides keep
chatting; the provider submits work; the company requests changes with a required reason;
the provider resubmits; the company completes the engagement and opens the shared review
form, submits ratings from both separate sessions, then explicitly finishes the campaign. API regression coverage runs the same work
flow for both an individual creator and an agency and blocks premature finishing through
both campaign mutation endpoints.

The same regression covers direct work submission, required revisions, resubmission,
client confirmation and latest-message ordering. Cancelled campaign discussions are
reopened through the sidebar: decline keeps them read-only; a subsequent accepted
request restores chat without a hire, followed by a fresh offer and confirmation.
