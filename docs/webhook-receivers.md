# Verify webhook deliveries

Webhook channels require HTTPS. Set the channel's boolean `allow_insecure_http` to `true` only for an explicitly trusted HTTP receiver (for example, a local fixture). HTTP exposes the payload and signature to the network; signatures do not encrypt data. The flag defaults to false when omitted.

Headers:

- `X-Heptapod-Timestamp`: Unix seconds in decimal, generated for each delivery attempt.
- `Signature`: lowercase hexadecimal HMAC-SHA256 of `timestamp + "." + raw JSON body`, using the channel secret.
- `X-Heptapod-Delivery` and `Idempotency-Key`: delivery hints, not signed. Do not use them as authenticated deduplication keys.

Read the raw request bytes before parsing JSON. Reject malformed headers, invalid signatures and timestamps more than 300 seconds from the receiver's current time, including future timestamps. Keep both machines' clocks synchronized. Compare signatures in constant time. Body-only signatures are not accepted.

After verification, parse the signed body. Atomically deduplicate on `(event_id, destination_id)` before applying side effects. Keep those keys for the full retry lifetime; retries have fresh timestamps but the same signed IDs. A timestamp window alone does not prevent replay inside the window. `is_test: true` deliveries have `event_id: 0`; treat them as verification-only, without production side effects. Return 2xx for successfully processed or already processed events.

The `headline` field describes the delivered event, not the check's state at delivery time. It includes the check name when the headline does not identify its subject. If detailed run evidence has expired, it uses that event's retained summary. The existing `title` and `summary` fields remain available. The `changes` field is a list of localized change descriptions, separate from the state headline for Warning and Failed results, and is empty when there are no separate change facts. The `details` field is a list of localized secondary facts that are neither a problem nor a change, for example links that could not be verified, and is empty when there are none.

## System alerts

A webhook channel receives System alerts only when System is turned on for it in the team notification settings. They report a problem with the Heptapod instance itself, such as a failed backup, a stopped scheduler or failing email delivery, and the matching recovery. The payload has the same keys as every other alert:

- `site_id`, `site_label`, `site_url` and `check_type` are `null`; the instance is the subject.
- `title` starts with `[System]` for a problem and `[System recovered]` for its recovery.
- `headline` is the recommended action, or for a recovery what changed.
- `summary` lists the localized facts, one per line.
- `severity` is `down` or `warning` for a problem and `recovered` for its recovery. `cause` is `null`.
- `link` opens System health.

Each problem and each recovery is sent once per channel, with its own `event_id`. Deduplicate on `(event_id, destination_id)` as for other alerts.

PHP (returns 401 on verification failure; `$secret` comes from receiver configuration):

```php
$raw = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_HEPTAPOD_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_SIGNATURE'] ?? '';
if (!preg_match('/^[1-9][0-9]{0,9}$/D', $timestamp)
    || !preg_match('/^[a-f0-9]{64}$/D', $signature)
    || abs(time() - (int) $timestamp) > 300
    || !hash_equals(hash_hmac('sha256', $timestamp.'.'.$raw, $secret), $signature)) {
    http_response_code(401);
    exit;
}
$event = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
// Atomically record [$event['event_id'], $event['destination_id']] before side effects.
```

Node (call before any JSON/body middleware; `rawBody` is the original Buffer):

```js
import { createHmac, timingSafeEqual } from 'node:crypto';

export function verifyWebhook(
    rawBody,
    headers,
    secret,
    now = Math.floor(Date.now() / 1000),
) {
    const timestamp = headers['x-heptapod-timestamp'];
    const signature = headers.signature;
    if (
        typeof timestamp !== 'string' ||
        !/^[1-9][0-9]{0,9}$/.test(timestamp) ||
        typeof signature !== 'string' ||
        !/^[a-f0-9]{64}$/.test(signature) ||
        Math.abs(now - Number(timestamp)) > 300
    ) {
        return false;
    }
    const expected = createHmac('sha256', secret)
        .update(timestamp + '.')
        .update(rawBody)
        .digest();
    return timingSafeEqual(expected, Buffer.from(signature, 'hex'));
}
// Reject with 401 when false. Parse JSON and atomically deduplicate signed IDs when true.
```
