---
title: Notifications
description: Notification channels, categories, timing and delivery outcomes
sidebar:
    order: 6
---

Alerts go to **channels**: an email address, Slack, Microsoft Teams, Discord, Telegram, Pushover, ntfy or a webhook. Every channel belongs to one recipient: the team, a person or a client. For each channel you choose per category whether it hears about it immediately, in the daily digest, or not at all.

| Where                           | Who can change it                           | What it holds                                                                                         |
| ------------------------------- | ------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| **Settings → Notifications**    | Owners and Admins                           | The team's channels: a shared mailbox, a chat channel, a status-page webhook                          |
| **Settings → My notifications** | Everyone, for themselves                    | Your account email and any extra channel of your own                                                  |
| **Client → Notifications**      | Owners and Admins with access to the client | Where that client can be reached; nothing goes to a client automatically until you turn a category on |

### Categories

| Category    | Meaning                                                                            | Digest |
| ----------- | ---------------------------------------------------------------------------------- | ------ |
| Outage      | A site is Down (uptime).                                                           | no     |
| Failure     | A check result Failed: certificate, DNS, broken links and so on.                   | no     |
| Check error | We could not check, for example a timeout or a blocked request.                    | yes    |
| Warning     | Something needs attention soon.                                                    | yes    |
| Expiring    | A certificate or domain expires soon.                                              | yes    |
| Recovery    | An outage is over or a check is fine again.                                        | no     |
| Changes     | New broken links, DNS or certificate changes, lower scores, faster response times. | yes    |
| System      | Heptapod itself needs attention: backups, mail, the scheduler.                     | no     |

New team channels hear everything except Changes and System. The first Owner's account email hears everything except Changes, plus System. Other people start with Outage, Failure and Recovery for every site they can see. Client channels start with everything off, and System is never offered to clients or Members. The Owner role always gets System at its account email, even in quiet hours; that row is locked.

The channel sheet highlights each category that differs from how the channel started and offers **Reset to defaults**. For channels converted on upgrade, the converted settings are that starting point, so a reset never undoes the conversion.

### Channel actions

Each row shows the channel's summary in one sentence, for example "Gets outages, failures and recoveries immediately; warnings in the 08:00 digest. Not for shop.example." Open a row to change its categories, leave out single sites, send a test, pause or resume it, edit its name and transport settings, or remove it. Removing a channel drops its queued messages and keeps its delivery history; the account email cannot be removed. Pausing discards queued automatic alerts; resuming does not restore them. A channel whose last delivery was rejected is marked **Needs attention** and sends nothing until its settings are repaired.

### Who gets this

Site overviews, check pages and open incidents show **Who gets this**: for one category, every channel that would be told if it happened now, when, and why the others would not (category off, site left out, paused, no access to the site, quiet hours, digest). A recovery is only sent to channels that received the opening alert for the same problem. Members see other people's addresses masked. It sends nothing and does not replay history.

## Sending to a client

Nothing reaches a client automatically until you turn a category on for one of its channels. Most of the time you decide yourself when a client hears about something: **Send to client…** on a broken links, mixed content or sitemap check with current findings, and in the actions of an incident. Owners and Admins with access to the client can send; Members see the history.

The sheet lists the client's email channels. A paused channel can still be chosen: pausing stops automatic alerts, not this message. Other channel types are shown but cannot receive it. **Another address (this time only)** sends to one more address once; it is not kept as a contact, and it gets exactly one attempt. Add a note in your own words and, for findings, attach every finding as a CSV file (up to 10,000 rows and 5 MB). The preview shows the email as the client reads it: in the client's language and timezone, without links into Heptapod. Replies go to your own address. The message is sent right away, also during the client's quiet hours.

What you approve is what is sent: the text, the attachment and each recipient address are stored when you press **Send to client**. Changing or pausing a channel afterwards does not change that message; removing a channel before it is sent stops it. Pressing the button twice, or a retried request, sends nothing twice: a request repeats a message only with the same content within 24 hours. A sender can send 20 messages per hour, and a client receives at most 10 per 24 hours.

The client's **Messages** tab lists everything it was sent: when, what, by whom (or automatically), to which address and with which outcome. **Open message** shows the email exactly as it was approved, and **Download CSV** returns the attached file as it was sent. A message that could not be delivered shows the reason and **Send again**, which opens the sheet with the same note.

## Email setup

A fresh installation uses `MAIL_MAILER=log`: messages are written to the app's stderr log, not delivered. The `array` transport also does not deliver mail. Delivery history labels these outcomes **Logged, not delivered**; they do not count as accepted opening alerts or successful sends. Existing historical sent records are unchanged.

The instance Owner can inspect the active transport in **Settings → Instance**. Configure an SMTP or API transport in the installation's `.env.prod` and recreate the app and workers to apply it. For SMTP, set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` and the provider's required `MAIL_SCHEME`. Then open a channel in **Settings → Notifications** and use **Send test** to verify acceptance. Ask your instance Owner to configure this if you do not manage the server.

## Channel setup

Add a channel under **Settings → Notifications**, **Settings → My notifications** or **Client → Notifications** with **Add channel**, then open it and press **Send test**. Heptapod uses a token or webhook URL that you create, not an "Add to Slack" sign-in: a self-hosted instance has no fixed sign-in address, and the token stays on your instance.

If a test fails, Heptapod shows "Notification provider rejected delivery or could not be reached." The exact reason, as listed in the tables below, is in the app log (`Notification transport failed.`) and on failed deliveries in an incident's **Timeline**.

Email: see [Email setup](#email-setup). Webhook: see [Verify webhook deliveries](./webhook-receivers.md).

### Slack

Heptapod needs a bot token and a channel ID.

1. Open https://api.slack.com/apps and choose **Create New App → Blank app**. Pick a name and your workspace.
2. In **OAuth & Permissions → Bot Token Scopes**, add `chat:write`.
3. Choose **Install to Workspace**, then **Allow**.
4. Copy the **Bot User OAuth Token**. Leave token rotation off; rotated tokens expire after 12 hours.
5. In the Slack channel, type `/invite @YourAppName`.
6. Click the channel name and copy the **Channel ID**.

| Field   | Value                | Format        |
| ------- | -------------------- | ------------- |
| Channel | Channel ID           | `C0123456789` |
| Token   | Bot User OAuth Token | `xoxb-…`      |

| Heptapod shows                 | Fix                                                     |
| ------------------------------ | ------------------------------------------------------- |
| `HTTP 200 — not_in_channel`    | Invite the app to the channel (step 5).                 |
| `HTTP 200 — channel_not_found` | Check the channel ID; private channels need the invite. |
| `HTTP 200 — invalid_auth`      | Copy the token again from **OAuth & Permissions**.      |

### Discord

Heptapod needs a bot token and a channel ID.

1. Open https://discord.com/developers/applications and choose **New Application**.
2. Copy the **Application ID** from **General Information**.
3. In **Bot**, choose **Reset Token** and copy the token.
4. Open `https://discord.com/oauth2/authorize?client_id=APPLICATION_ID&scope=bot&permissions=19456` with your Application ID, pick your server and choose **Authorize**. This grants View Channels, Send Messages and Embed Links. You need **Manage Server** on that server.
5. In Discord, turn on **User Settings → Advanced → Developer Mode**.
6. Right-click the channel and choose **Copy Channel ID**.

| Field   | Value      | Format                          |
| ------- | ---------- | ------------------------------- |
| Channel | Channel ID | `123456789012345678`            |
| Token   | Bot token  | the token only, no `Bot` prefix |

| Heptapod shows                                             | Fix                                                            |
| ---------------------------------------------------------- | -------------------------------------------------------------- |
| `HTTP 403 — RequestException — 50001; Missing Access`      | Invite the bot again (step 4) or let its role see the channel. |
| `HTTP 403 — RequestException — 50013; Missing Permissions` | Allow Send Messages and Embed Links in the channel.            |
| `HTTP 401 — RequestException — 0; 401: Unauthorized`       | Reset the token and paste the new one.                         |

### Microsoft Teams

Heptapod needs a Workflows webhook URL. Office 365 connector URLs do not work.

1. In Teams, open **Workflows** and pick the template **Send webhook alerts to a channel**. The "from specific people" and "from people in an org" variants do not work with Heptapod.
2. Choose the team and channel, then **Save**.
3. Copy the webhook URL from the workflow's details page.

| Field | Value       | Format      |
| ----- | ----------- | ----------- |
| URL   | Webhook URL | `https://…` |

| Heptapod shows                  | Fix                                                                     |
| ------------------------------- | ----------------------------------------------------------------------- |
| `HTTP 4xx — RequestException`   | The workflow was deleted, turned off or needs sign-in. Create it again. |
| Test sent, but nothing in Teams | Open the workflow's run history in Workflows.                           |

### Telegram

Heptapod needs a bot token and a chat ID.

1. In Telegram, send `/newbot` to @BotFather and follow the prompts. Copy the token it replies with.
2. Add the bot to your group. For a channel, make it an admin that can post messages.
3. In the group, send `/start@your_bot_name`.
4. Open `https://api.telegram.org/bot<token>/getUpdates` in a browser (the URL contains your token; keep it private) and copy the number after `"chat":{"id":`.

| Field   | Value     | Format                                                   |
| ------- | --------- | -------------------------------------------------------- |
| Chat ID | Chat ID   | groups are negative, e.g. `-1001234567890`; keep the `-` |
| Token   | Bot token | `123456789:AA…`                                          |

| Heptapod shows                                              | Fix                                                       |
| ----------------------------------------------------------- | --------------------------------------------------------- |
| `HTTP 400 — RequestException — Bad Request: chat not found` | Check the chat ID and that the bot is in the group.       |
| `HTTP 400 — … group chat was upgraded to a supergroup chat` | Repeat steps 3–4 and use the new ID starting with `-100`. |
| `HTTP 401 — RequestException — Unauthorized`                | Send `/token` to @BotFather and paste the new token.      |

### Pushover

Heptapod needs your user key and an application token.

1. Sign in at https://pushover.net and copy your user key from the dashboard.
2. Choose **Create an Application/API Token**, name it and copy its token.
3. Install the Pushover app on your device and sign in.

| Field    | Value                 | Format        |
| -------- | --------------------- | ------------- |
| User key | User or group key     | 30 characters |
| Token    | Application API token | 30 characters |

| Heptapod shows                                             | Fix                               |
| ---------------------------------------------------------- | --------------------------------- |
| `HTTP 400 — RequestException — user identifier is invalid` | Copy the user key again.          |
| `HTTP 400 — RequestException` and a token error            | Copy the application token again. |

### ntfy

Heptapod needs a server URL and a topic, plus an access token for protected topics.

1. Choose a server, for example `https://ntfy.sh`.
2. Choose a hard-to-guess topic; anyone who knows it can read it.
3. Subscribe to the topic in the ntfy app.
4. For a protected topic, create an access token under **Account** in the ntfy web app.

| Field  | Value                   | Format                               |
| ------ | ----------------------- | ------------------------------------ |
| Server | Server base URL         | `https://ntfy.sh`, without the topic |
| Topic  | Topic name              | letters, digits, `_`, `-`; up to 64  |
| Token  | Access token (optional) | `tk_…`                               |

| Heptapod shows                                | Fix                                                                                                      |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `HTTP 403 — RequestException — forbidden`     | Add a token that may write to the topic.                                                                 |
| `HTTP 401 — RequestException — unauthorized`  | Create a new token and paste it.                                                                         |
| "Not sent: … private, loopback, link-local …" | The server is on a private network; the instance Owner allows it with `HEPTAPOD_DELIVERY_PRIVATE_HOSTS`. |

## Alert sensitivity

Set the team default in **Settings → Alert sensitivity**, or choose an override in **Site settings → Alerts**. Sites inherit Relaxed unless you choose another value. Sensitivity affects uptime outage and uptime check-error notifications only. It does not hide failed checks, change the incident list, or remove downtime from uptime history.

- Strict: alert at the second failed minute; confirm recovery with one good check.
- Balanced: alert at the fifth failed minute; confirm recovery with three consecutive good checks.
- Relaxed: alert at the tenth failed minute; confirm recovery with three consecutive good checks.
- Patient: alert at the thirtieth failed minute; confirm recovery with three consecutive good checks.
- Custom: choose 2–60 failed minutes and 1–10 consecutive good checks.

A short recovery without enough good checks belongs to the same notification episode. Each recipient gets at most one opening alert for that episode, followed by a recovery only after an opening alert was accepted. Missing checks do not confirm recovery. A failure resets the good-check streak. Short interruptions that end before the alert delay stay in the incident list without an opening or recovery notification.

An episode notification's Since time and duration cover the notification episode, including short unconfirmed recoveries. Its recovery email compares against the opening observation actually sent to that recipient. Each incident page and its API record keep that incident's own times and duration.

Changes apply to new interruptions. An episode retains the timing it had when it began, including when it continues through another incident. Incidents already open when this feature is installed keep their immediate notification behavior. Quiet hours, suppression and recipient permissions still apply.

The strip uses one check per minute to illustrate two interruptions. Its pins show the selected timing, not a replay of delivery history.

After muted maintenance ends, each ongoing uptime episode gets its own opening message, with a maintenance-ended note and the same sensitivity delay. Other check types retain the aggregate catch-up. A recovered episode does not send a delayed catch-up.

The Oh Dear-compatible uptime `failed_notification_threshold` means failed minutes before an alert, not the incident-opening threshold. It accepts 1–1000 or `null` to inherit. Writing a number selects one recovery check for values up to 2, otherwise three. Other check types retain their failed-check-count semantics. The monitor response exposes the effective, read-only `alert_sensitivity` object (`preset`, `alert_after_minutes`, `recovery_checks`, `inherited`). Top-level alert timing fields are not writable on monitor resources.

Imports treat Oh Dear's default value 2 as inheritance; other values retain their timing (0 becomes 1). An imported or API value outside the Custom editor's range stays visible with its real numbers. You can keep those minutes while saving other fields; changing the minutes requires a value in the editor's range.

## Timing and delivery

Webhook dates, including dates within `summary` text, use ISO 8601. Human-channel dates remain localized. Deliveries retained after site deletion carry `site_id: null` and `link: null`; `site_label` retains the site's label with a deleted-site marker.

The webhook payload's `severity` expresses urgency, not the cause of a failure. Both an uptime outage (**Down**) and a non-uptime failure (**Failed**) can have `severity: "down"` and bypass personal quiet hours. Use event type, check type and result to identify the cause; do not label every `down` severity as a site outage. Mail subjects and Activity labels distinguish Down from Failed. Only uptime Down incidents emit reminders. Non-uptime incidents send opening and resolution alerts, without recurring reminders; their recovery titles say Resolved. Historical non-uptime reminders retain their Still failing title. Recovery emails state how long the problem lasted. Uptime: “[Recovered] site after 7 min” (“[Wiederhergestellt] site nach 7 Min.”). Non-uptime incidents: a “Recovered after 7 min” badge (“Nach 7 Min. wiederhergestellt”) beside the incident's start–end span. Component recoveries, such as a scheduled task: the same badge when the component's failure start is known, beside the time the recovery was observed; scheduled-task emails also list the failure start as a separate “Failure started” row (“Fehler seit”). A component without a known failure start shows only the observed time, without a duration. Chat recovery bodies label non-uptime incident and component durations as “Total duration” (“Gesamtdauer” in German); uptime recoveries retain “Total downtime”. The API/webhook `downtime_seconds` field and numeric value are unchanged: for non-uptime checks, they describe the failure duration, not evidence of target downtime. Webhook summary text retains its existing “Total downtime” label. Public severity values are `down`, `recovered`, `warning`, `error` and `info`; event/cause labels do not add severity values.

- Every channel whose category is on gets the alert. A site left out of a channel sends that channel nothing about it. An incident recovery needs an accepted opener for the same channel; a warning or addon component recovery needs its matching check/component opening alert accepted first. An opening, warning or component alert that quiet hours or a digest scheduled for after the recovery is dropped together with its recovery. An alert that was already due before the recovery, or is being retried after a failed send, is still sent, followed by its recovery. Repeated failures of one component share one recovery. A maintenance catch-up whose incidents have all recovered before it is sent is dropped.
  Pausing a channel blocks new alerts and discards unleased pending, failed/retry-scheduled and expired-send queue rows in the same transaction; the response and audit include the count. Resuming only changes the flag: discarded rows stay discarded, and new events can send. An already-leased send may finish because provider I/O cannot be recalled.

Lighthouse, sitemap, broken-links and mixed-content incidents share an uptime notification when they fail with the same target availability cause on the same site. The named `AvailabilityFailure` classes are `tls` (target TLS verification or handshake failure) and `http_5xx` (target HTTP status 500–599). Different classes, HTTP 4xx responses, unreachable external links, low Lighthouse scores and monitoring-service failures do not match. A sitemap matches only when its initial document on the site's origin fails; a crawl matches only when its start page fails. Each dependent still has its own incident and check result. An incident whose opening alert was already accepted by any channel, including a maintenance catch-up, or whose opening was already released is never grouped and keeps its own notification lifecycle, including recovery. A dependent matched while the uptime outage is open stays grouped until that uptime incident resolves, even if the uptime cause changes; dependents that open later match the uptime incident's current cause.

The uptime opening alert and reminders include “Also affected” with the matching checks. A dependent that opens later appears in the next uptime message without an extra notification. Suppressed recipient deliveries record `uptime_dependency` (“Included in the uptime outage”). The uptime recovery alert waits for the episode's configured good-check confirmation and lists held dependents as “Re-checking”. Each held dependent waits for its first accepted observation that started after the uptime recovery time, on its next natural run. No extra run is scheduled. A confirmed failure then receives its own opening alert, subject to the channels' categories and ordinary suppression; a confirmed success closes silently. The persisted hold survives restarts. Held checks show “Re-checking after recovery” with their next scheduled run, and do not count as needing attention in the Sites band or overview counts until a fresh observation confirms a problem. An outage-era observation cannot release the hold, even if it finishes after uptime recovers. If uptime fails again before the dependent re-runs, the dependent stays held under the new uptime incident. Already admitted provider sends cannot be recalled. Webhook payloads expose these lists as `also_affected` and `rechecking`, using check keys rather than translated names; an empty list is `[]`.

Stopping monitoring closes open incidents without a recovery alert. Disabling a check, pausing a site, or deleting a site records the reason and audit actor/origin. The resolution event is suppressed with `monitoring_stopped`, so no recovery delivery is planned. Pending opening/cause-change/reminder deliveries for that episode are suppressed too. Recovery notifications waiting for good checks are also closed without sending. Already admitted provider sends cannot be recalled. **Who gets this** and delivery views show “Monitoring stopped” (“Überwachung beendet”). Hard deletion removes the site, its checks, check runs and incidents. Timeline events and notification delivery history are kept with the site's identity; deleted-site entries have no site link. Queued notifications for the deleted site are suppressed. The deletion audit retains the resolved incident IDs and count.

- Uptime Down incident reminders follow a backoff while open: the first is due 1 hour after the opening alert, the second 4 hours after the first reminder, then every 24 hours. The final interval repeats. Existing incidents with two or more reminders use the 24-hour interval. Reminders remain at least one hour apart per recipient. Normally only recipients whose opening email was accepted get reminders. A maintenance catch-up counts as an accepted opener for its included incidents. With catch-up off, a new uptime episode can send its first alert at the later of the window end and its saved sensitivity due time. That first alert is not counted as a reminder; its reminders then follow the 1-hour, 4-hour and 24-hour sequence. Quiet hours do not delay outage reminders; incident snoozes stop them. Snoozes and muted maintenance do not reset or advance the sequence. When suppression ends, an overdue reminder is emitted on the next evaluation, without a burst of missed reminders; the following delay starts from that reminder. Recovery stops the sequence, and a new incident starts at the first interval. Muted maintenance windows prevent reminder emission, even for incidents opened before the window. After a deferred opener is accepted, the next eligible incident-wide backoff tick resumes reminders.
- Check errors, warnings, expiring items and changes set to Digest are collected into one daily digest at the recipient's digest time (08:00 by default) on its digest days. The team uses the instance timezone, a person their profile timezone, a client the client's timezone. Digest generation selects open items, including warnings already reported on earlier days. No eligible item means no email; the suppressed recipient delivery row remains for audit, and a digest with some eligible sources drops only the ineligible sources from its payload. Digest slots retain their unique channel/time identity.
- Queued alerts for a person are suppressed when they lose access to the site's client. Digest generation and delivery recheck each source against current client access; a mixed-client digest includes only sites still visible to its recipient. Suppressed items record `personal_site_access_revoked` for audit.
- Every account has one account email channel, using its account address and normal account verification. Changing that address keeps the channel, its categories and its delivery history. Queued alerts stay pending while the new address is unverified; after verification they go to the new address. Extra email addresses for a person are not offered; add another kind of channel instead.
- Quiet hours hold back everything except outages, failures and check errors; held messages go out when quiet hours end. "Also quiet on weekends" extends this to Saturday and Sunday and moves weekend digests to the next weekday.
- Accepted means the SMTP server accepted the message. Rejected (recipient or DATA SMTP 5xx) means that recipient/message was rejected permanently and is not retried. Failed (config) means SMTP handshake, TLS or sender configuration failed; transient SMTP 4xx and transport failures retry. Suppressed deliveries are visible as suppressed, not pending. Members see their own email address on incident delivery rows; other recipients are masked. Acceptance does not prove inbox delivery or that anybody read it. Without a bounce source, later bounces are not reported.

## Moving a site to another client

Owners and Admins can select or create an active client in Site settings, then Save. The whole site moves, including settings, check secrets and history. Members of the new client gain access; members with access only to the previous client lose it. Client channels and maintenance stay with their client. Site exceptions on channels and snoozes stay with the site, subject to current access. A move ends the site's open incidents without notifying anyone, like a pause; each keeps its history and shows "Site moved to another client" as its resolution reason. A check that still fails starts a new failure streak, and the incident that opens at its threshold belongs to the new client and alerts its channels. Moves wait until outstanding site alerts have finished.

A move ends existing daily digest recurrence without changing accepted delivery history. The new client's channels apply on the next state change; they do not inherit an ongoing daily digest. An aggregate digest planned before the move may still be delivered afterward because it describes facts from before the move. Concurrent planning and moves serialize on the digest source rows: the planner may finish first, or the move ends recurrence first and the planner skips that source.

## Digests (beta)

Digest cells are off by default except where an upgrade converted an earlier digest choice. Digests may repeat warnings, include outdated warnings, or count the same warning more than once after it recovers and returns. Use immediate alerts for reliable notifications.

## Permissions and endpoints

Owners and Admins are instance-wide, even if an old account still carries a restricted-client flag; only Members can have restricted client scope. Members manage only their own channels; they cannot see the team's channels or a client's.

The settings pages read `GET /settings/notifications/team/data`, `GET /settings/notifications/data` and `GET /clients/{client}/notifications/data`. Channels use `POST /notification-channels`, `PATCH /notification-channels/{id}`, `PATCH /notification-channels/{id}/enabled`, `DELETE /notification-channels/{id}` (removes the channel and keeps its history), `POST /notification-channels/{id}/test`, `PUT /notification-channels/{id}/preferences` and `PUT|DELETE /notification-channels/{id}/site-mutes/{site}`. Schedules use `PATCH /notification-schedules/{team|user|client}/{id?}`. Client messages use `POST /clients/{client}/client-messages/preview` (renders, stores nothing), `POST /clients/{client}/client-messages` with an `Idempotency-Key` header, `GET /clients/{client}/client-messages?before=…` for the history, and `GET /clients/{client}/client-messages/{message}` and `…/{message}/attachment` for one sent message and its CSV. **Who gets this** reads `GET /notifications/explain?check_id=…&category=…&at=…` with an offset-bearing `at` such as `2026-10-05T00:30:00+00:00` (URL-encode the `+`).

The shared `notifications_covered` flag used by Sites, Add site and the sidebar reports whether any visible site has a reachable channel that hears outages or failures. It is not per-check coverage and not proof of delivery; use **Who gets this** on a check for the details.

Use finite Maintenance for alert suppression and Pause to stop monitoring. Maintenance prevents reminders while active and can group end-of-window catch-up per channel. See [Maintenance](./maintenance.md).
