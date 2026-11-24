#!/usr/bin/env bash
# Run in an interactive terminal as the production licence custodian.
set -euo pipefail
umask 077

if [[ $# -ne 1 || ! -t 0 ]]; then
    echo "Usage from an interactive terminal: bash tools/provision_key.sh PRIVATE_DIRECTORY" >&2
    exit 2
fi

repo=$(cd "$(dirname "$0")/.." && pwd -P)
parent=$(cd "$(dirname "$1")" && pwd -P)
target="$parent/$(basename "$1")"
case "$target/" in
    "$repo/"*) echo "The private directory must be outside the repository." >&2; exit 2 ;;
esac

read -r -s -p "Choose a strong signing key passphrase: " passphrase
printf '\n'
read -r -s -p "Repeat the signing key passphrase: " confirmation
printf '\n'
if [[ ${#passphrase} -lt 16 || "$passphrase" != "$confirmation" ]]; then
    echo "Use a passphrase of at least 16 characters and enter it twice." >&2
    exit 2
fi
unset confirmation

mkdir -m 700 "$target"
stage=$(mktemp -d "$target/.provision.XXXXXXXX")
cleanup() {
    rm -f "$stage/signing-key.pem" "$stage/public-key.pem"
    rmdir "$stage"
    rmdir "$target" 2>/dev/null || true
}
trap cleanup EXIT

printf '%s\n' "$passphrase" | openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -aes-256-cbc \
    -pass stdin -out "$stage/signing-key.pem"
grep -qx -- '-----BEGIN ENCRYPTED PRIVATE KEY-----' "$stage/signing-key.pem"
printf '%s\n' "$passphrase" | openssl pkey -in "$stage/signing-key.pem" -passin stdin -pubout -out "$stage/public-key.pem"
unset passphrase
mkdir -m 700 "$target/registry"
mv "$stage/signing-key.pem" "$stage/public-key.pem" "$target/"
echo "Encrypted signing key and verification key created in $target"
