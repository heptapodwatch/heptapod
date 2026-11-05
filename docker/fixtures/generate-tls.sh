#!/bin/sh
# Run in an OpenSSL container, with docker/fixtures mounted at /fixtures.
set -eu
cd "${1:-/fixtures}"
fixtures_dir=$(pwd)
certificate_health_dir=${2:-}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p tls "$work/newcerts"
touch "$work/index"
printf '1000\n' > "$work/serial"
openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 7300 \
    -subj '/CN=Heptapod offline fixture CA' \
    -addext 'basicConstraints=critical,CA:TRUE' \
    -addext 'keyUsage=critical,keyCertSign,cRLSign' \
    -keyout "$work/ca.key" -out tls/ca.crt
cat > "$work/ca.conf" <<EOF
[ca]
default_ca = fixture
[fixture]
unique_subject = no
database = $work/index
new_certs_dir = $work/newcerts
serial = $work/serial
certificate = $fixtures_dir/tls/ca.crt
private_key = $work/ca.key
default_md = sha256
policy = names
[names]
commonName = supplied
[leaf]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
EOF
for name in valid expired wrong-host not-yet-valid; do
    case "$name" in
        valid) host=fixtures.test; end=20460101000000Z ;;
        expired) host=expired.fixtures.test; end=20200102000000Z ;;
        wrong-host) host=unrelated.fixtures.test; end=20460101000000Z ;;
        not-yet-valid) host=fixtures.test; end=20460101000000Z ;;
    esac
    start=20200101000000Z
    [ "$name" != not-yet-valid ] || start=20450101000000Z
    openssl req -new -newkey rsa:2048 -nodes -sha256 \
        -subj "/CN=$host" -keyout "tls/$name.key" -out "$work/$name.csr"
    cp "$work/ca.conf" "$work/leaf.conf"
    printf 'subjectAltName = DNS:%s\n' "$host" >> "$work/leaf.conf"
    openssl ca -batch -notext -config "$work/leaf.conf" -extensions leaf \
        -startdate "$start" -enddate "$end" \
        -in "$work/$name.csr" -out "tls/$name.crt"
done
# The leaf's issuer is signed by the trusted root, but intentionally not served by nginx.
openssl req -new -newkey rsa:2048 -nodes -sha256 \
    -subj '/CN=Heptapod fixture intermediate' -keyout "$work/intermediate.key" -out "$work/intermediate.csr"
printf 'basicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n' > "$work/intermediate.ext"
openssl x509 -req -in "$work/intermediate.csr" -CA tls/ca.crt -CAkey "$work/ca.key" \
    -set_serial 2001 -days 7300 -sha256 -extfile "$work/intermediate.ext" -out "$work/intermediate.crt"
openssl req -new -newkey rsa:2048 -nodes -sha256 \
    -subj '/CN=fixtures.test' -keyout tls/incomplete-chain.key -out "$work/incomplete-chain.csr"
printf 'basicConstraints=critical,CA:FALSE\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:fixtures.test\n' > "$work/incomplete-chain.ext"
openssl x509 -req -in "$work/incomplete-chain.csr" -CA "$work/intermediate.crt" -CAkey "$work/intermediate.key" \
    -set_serial 2002 -days 7300 -sha256 -extfile "$work/incomplete-chain.ext" -out tls/incomplete-chain.crt
# Keep the display-chain fixture paired with the regenerated valid private key.
cat tls/valid.crt > tls/oversized-chain.crt
for index in $(seq 1 20); do
    cat tls/ca.crt >> tls/oversized-chain.crt
done
# Public, throwaway fixture keys must be readable by the nginx worker.
chmod 644 tls/*.key tls/*.crt

# Opt in: certificate-health snapshots share this run's disposable CA.
if [ -n "$certificate_health_dir" ]; then
    mkdir -p "$certificate_health_dir"
    openssl req -new -newkey rsa:2048 -nodes -sha256 \
        -subj '/CN=fixtures.test' -keyout "$work/replacement.key" -out "$work/replacement.csr"
    cp "$work/ca.conf" "$work/replacement.conf"
    printf 'subjectAltName = DNS:fixtures.test\n' >> "$work/replacement.conf"
    openssl ca -batch -notext -config "$work/replacement.conf" -extensions leaf \
        -startdate 20261001224834Z -enddate 20460926224834Z \
        -in "$work/replacement.csr" -out "$certificate_health_dir/replacement.crt"
fi
