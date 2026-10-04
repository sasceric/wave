#!/usr/bin/env sh
set -eu

root_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
os=$(uname -s)
architecture=$(uname -m)
version=1.0.3

case "$os:$architecture" in
    Darwin:arm64|Darwin:aarch64)
        asset="mercure_Darwin_arm64.tar.gz"
        ;;
    Darwin:x86_64)
        asset="mercure_Darwin_x86_64.tar.gz"
        ;;
    Linux:x86_64)
        asset="mercure_Linux_x86_64.tar.gz"
        ;;
    Linux:arm64|Linux:aarch64)
        asset="mercure_Linux_arm64.tar.gz"
        ;;
    *)
        printf 'Mercure %s has no configured binary for %s/%s.\n' "$version" "$os" "$architecture" >&2
        exit 1
        ;;
esac

install_dir="$root_dir/var/mercure"
mkdir -p "$install_dir"
curl --fail --silent --show-error --location \
    "https://github.com/dunglas/mercure/releases/download/v$version/$asset" \
    --output "$install_dir/$asset"
curl --fail --silent --show-error --location \
    "https://github.com/dunglas/mercure/releases/download/v$version/checksums.txt" \
    --output "$install_dir/checksums.txt"

if command -v shasum >/dev/null 2>&1; then
    (cd "$install_dir" && shasum -a 256 -c checksums.txt --ignore-missing)
elif command -v sha256sum >/dev/null 2>&1; then
    (cd "$install_dir" && sha256sum -c checksums.txt --ignore-missing)
else
    echo 'Install shasum or sha256sum to verify the Mercure download.' >&2
    exit 1
fi

tar -xzf "$install_dir/$asset" -C "$install_dir"
chmod +x "$install_dir/mercure"
printf 'Installed Mercure %s at %s\n' "$version" "$install_dir/mercure"
