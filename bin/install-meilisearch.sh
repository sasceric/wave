#!/bin/sh
# Installs ONLY the dedicated Wave service. Does not upgrade another Meilisearch instance.
set -eu
if [ "$(id -u)" -ne 0 ]; then echo 'Run this installer as root on the Linux server.' >&2; exit 1; fi
case "$(uname -s):$(uname -m)" in
  Linux:aarch64|Linux:arm64) WAVE_MEILI_ARCH=aarch64; WAVE_MEILI_SHA=f268dde681f20f6fc393d053dab11909d059574e52916355ad1d10212ec8a2a7 ;;
  Linux:x86_64) WAVE_MEILI_ARCH=amd64; WAVE_MEILI_SHA=7e33f5889235f14290074a19a6d3574bd9b10fb2ee8731eabb7dafd0714b0513 ;;
  *) echo 'Supported: Linux ARM64 and AMD64.' >&2; exit 1 ;;
esac
WAVE_MEILI_VERSION=v1.55.0
if [ -x /usr/local/bin/wave-meilisearch ]; then
  WAVE_INSTALLED_VERSION=$(/usr/local/bin/wave-meilisearch --version)
  case "$WAVE_INSTALLED_VERSION" in
    "meilisearch ${WAVE_MEILI_VERSION#v}") ;;
    *) echo 'A different Wave Meilisearch version is installed. Follow the documented upgrade procedure before replacing it.' >&2; exit 1 ;;
  esac
fi
WAVE_INSTALL_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
WAVE_DOWNLOAD=$(mktemp)
trap 'rm -f "$WAVE_DOWNLOAD"' EXIT HUP INT TERM
curl --fail --location --proto '=https' --tlsv1.2 "https://github.com/meilisearch/meilisearch/releases/download/$WAVE_MEILI_VERSION/meilisearch-linux-$WAVE_MEILI_ARCH" --output "$WAVE_DOWNLOAD"
printf '%s  %s\n' "$WAVE_MEILI_SHA" "$WAVE_DOWNLOAD" | sha256sum --check --status
install -m 0755 "$WAVE_DOWNLOAD" /usr/local/bin/wave-meilisearch
if ! id wave-meilisearch >/dev/null 2>&1; then useradd --system --home-dir /var/lib/wave-meilisearch --shell /usr/sbin/nologin wave-meilisearch; fi
install -d -m 0750 -o wave-meilisearch -g wave-meilisearch /var/lib/wave-meilisearch
install -d -m 0750 -o root -g wave-meilisearch /etc/wave
if [ ! -f /etc/wave/meilisearch.env ]; then
  (umask 077
    printf 'MEILI_ENV=production\nMEILI_HTTP_ADDR=127.0.0.1:7700\nMEILI_DB_PATH=/var/lib/wave-meilisearch/data.ms\nMEILI_NO_ANALYTICS=true\nMEILI_MAX_INDEXING_MEMORY=1GiB\nMEILI_MAX_INDEXING_THREADS=2\nMEILI_MASTER_KEY='
    openssl rand -hex 32
  ) > /etc/wave/meilisearch.env
fi
chown root:wave-meilisearch /etc/wave/meilisearch.env
chmod 0640 /etc/wave/meilisearch.env
install -m 0644 "$WAVE_INSTALL_ROOT/deploy/wave-meilisearch.service" /etc/systemd/system/wave-meilisearch.service
systemctl daemon-reload
systemctl enable --now wave-meilisearch.service
printf 'Wave Meilisearch installed. Credentials are in /etc/wave/meilisearch.env; no key is printed.\n'
