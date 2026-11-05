# Notification channels

## Webhook receivers

Webhook channels require HTTPS unless `allow_insecure_http: true` is explicitly configured. Verify `Signature` as HMAC-SHA256 hex over `X-Heptapod-Timestamp + "." + raw body`, with the channel secret. Use constant-time comparison and a five-minute timestamp window. Deduplicate atomically on the signed body's `(event_id, destination_id)`, not the unsigned `X-Heptapod-Delivery` or `Idempotency-Key` headers. Treat `is_test` events as verification-only.

See [receiver documentation](../../docs/webhook-receivers.md) for exact headers, retry semantics and PHP/Node verifiers.

## Smoke tests

The manual test publishes to a random public topic on https://ntfy.sh and verifies receipt through its JSON poll endpoint. It sends a webhook to the Compose fixture receiver and verifies the stored body and headers with the Spatie `Signer` extension `TimestampSigner`.

Run from the repository root:

```sh
docker compose up -d --wait app postgres redis
FIXTURES_HTTP_PORT=8581 FIXTURES_HTTPS_PORT=8582 docker compose --profile fixtures up -d --wait fixtures
docker compose exec -e HEPTAPOD_NOTIFICATION_LIVE=1 app vendor/bin/pest addons/channels/tests/Manual/NotificationLiveSmokeTest.php --group=notification-live --colors=never
```

The test is outside the PHPUnit/CI suites. It skips without the opt-in variable or when a connection probe fails. Provider rejection, missing receipt, or invalid signature fails the test. No provider credentials are required. Fixture receipts expire after five minutes; public ntfy messages use its normal cache lifetime. Change the fixture ports if they are occupied.
