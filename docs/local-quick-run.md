# Quick local run

Use a separate terminal for the backend, frontend, Mercure and Meilisearch.

## 1. Start PostgreSQL

```sh
brew services start postgresql@18
```

## 2. Run backend — PHP 8.4

```sh
cd /Users/suadasceric/Projects/wave
/opt/homebrew/opt/php@8.4/bin/php -S 127.0.0.1:8000 -t public public/router.php
```

## 3. Run frontend — dev

```sh
cd /Users/suadasceric/Projects/wave
nvm use 24
npm --prefix frontend run dev -- --host 127.0.0.1 --port 5173 --strictPort
```

Open **http://127.0.0.1:5173**.

## 4. Run Mercure

```sh
cd /Users/suadasceric/Projects/wave
/opt/homebrew/opt/php@8.4/bin/php bin/mercure-local.php
```

## 5. Run Meilisearch

Skip if already running for Zeka. Keep any existing master-key environment settings.

```sh
cd /Users/suadasceric
./meilisearch --http-addr 127.0.0.1:7700 --db-path /Users/suadasceric/data.ms
```

Wave uses it when `MEILISEARCH_ENABLED=true` in `.env.local`.

If `WAVE_QUEUE_ENABLED=true`, leave a signed-in admin page visible to process
queues and scheduled tasks using the existing development admin worker.

**Stop:** Ctrl+C in each service terminal. Leave shared PostgreSQL/Meilisearch
running if another project needs them.
