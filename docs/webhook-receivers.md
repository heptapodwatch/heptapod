---
title: Verify webhook deliveries
description: Verify the signature, timestamp and payload of a webhook delivery.
---

Every webhook delivery is signed with the channel secret. Verify the signature and timestamp before you act on the payload.

## Headers

| Header                                   | Value                                                                                           | Signed |
| ---------------------------------------- | ----------------------------------------------------------------------------------------------- | ------ |
| `X-Heptapod-Timestamp`                   | Unix seconds in decimal, new for each delivery attempt                                          | Yes    |
| `Signature`                              | Lowercase hexadecimal HMAC-SHA256 of `timestamp + "." + raw JSON body`, with the channel secret | —      |
| `X-Heptapod-Delivery`, `Idempotency-Key` | Delivery hints. Do not use them to deduplicate.                                                 | No     |

## Verify a delivery

1. Read the raw request bytes before any JSON parsing.
2. Reject malformed headers with 401.
3. Reject a timestamp more than 300 seconds away from your clock, in either direction. Keep both clocks synchronized.
4. Compute the HMAC of `timestamp + "." + raw body` and compare it with `Signature` in constant time. Reject a mismatch with 401.
5. Parse the signed body.
6. Record `(event_id, destination_id)` atomically before any side effect. Skip the event if it is already recorded.
7. Return 2xx for a processed event, also when it was processed before.

- Keep the deduplication keys for the full retry period. Retries get a fresh timestamp but the same signed IDs.
- The timestamp window alone does not stop a replay inside the window.
- A test delivery has `is_test: true` and `event_id: 0`. Use it to verify your receiver, without side effects.
- Signatures over the body alone are not accepted.

## HTTPS

Webhook channels require HTTPS. Set `allow_insecure_http` to `true` only for a trusted HTTP receiver, such as a local test fixture. It is `false` by default. Over HTTP the payload and signature are visible on the network; the signature does not encrypt anything.

## Payload text fields

| Field              | Content                                                                                                                                                                        |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `headline`         | The delivered event, not the check's state at delivery time. It names the check when the headline does not. If run evidence has expired, it uses the event's retained summary. |
| `title`, `summary` | Title and summary of the alert.                                                                                                                                                |
| `changes`          | Localized change descriptions, apart from the headline of a Warning or Failed result. Empty when there are none.                                                               |
| `details`          | Localized secondary facts, such as links that could not be verified. Empty when there are none.                                                                                |

## System alerts

A webhook channel receives System alerts when System is on for it in the team notification settings. They report a problem with the Heptapod instance, such as a failed backup, a stopped scheduler or failing email, and its recovery. The payload has the same keys as other alerts:

| Field                                             | Value                                                          |
| ------------------------------------------------- | -------------------------------------------------------------- |
| `site_id`, `site_label`, `site_url`, `check_type` | `null`                                                         |
| `title`                                           | Starts with `[System]`, or `[System recovered]` for a recovery |
| `headline`                                        | The recommended action, or what changed for a recovery         |
| `summary`                                         | Localized facts, one per line                                  |
| `severity`                                        | `down` or `warning` for a problem, `recovered` for a recovery  |
| `cause`                                           | `null`                                                         |
| `link`                                            | System health                                                  |

Each problem and recovery is sent once per channel with its own `event_id`.

## PHP example

Returns 401 when verification fails. `$secret` comes from your receiver configuration.

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

## Node example

Call it before any JSON or body middleware. `rawBody` is the original Buffer.

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
