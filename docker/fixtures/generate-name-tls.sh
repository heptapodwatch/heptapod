#!/bin/sh
# Run via docker compose exec app sh docker/fixtures/generate-name-tls.sh.
set -eu
cd docker/fixtures/tls
openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 7300 \
    -subj '/CN=names.fixtures.test' \
    -addext 'subjectAltName=DNS:*.wildcard.fixtures.test,DNS:alt.fixtures.test,IP:127.0.0.1' \
    -addext 'extendedKeyUsage=serverAuth' \
    -keyout names.key -out names.crt
openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 7300 \
    -subj '/CN=self-signed.fixtures.test' \
    -addext 'subjectAltName=DNS:self-signed.fixtures.test' \
    -addext 'extendedKeyUsage=serverAuth' \
    -keyout self-signed.key -out self-signed.crt
cat valid.crt > oversized-chain.crt
for index in $(seq 1 20); do
    cat ca.crt >> oversized-chain.crt
done
chmod 644 names.key names.crt self-signed.key self-signed.crt oversized-chain.crt
