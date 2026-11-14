---
title: Notifications
description: Choose which channels hear about outages, failures and recoveries, when they hear it, and what reaches a client.
sidebar:
    order: 6
---

Alerts go to channels: an email address, Slack, Microsoft Teams, Discord, Telegram, Pushover, ntfy or a webhook. For each channel you choose per category whether it hears about it immediately, in the daily digest, or not at all.

## Where channels live

| Page                            | Who can change it                           | Holds                                                                                  |
| ------------------------------- | ------------------------------------------- | -------------------------------------------------------------------------------------- |
| **Settings → Notifications**    | Owners and Admins                           | The team's channels, such as a shared mailbox, a chat channel or a status-page webhook |
| **Settings → My notifications** | Everyone, for themselves                    | Your account email and any extra channel of your own                                   |
| **Client → Notifications**      | Owners and Admins with access to the client | Where the client can be reached                                                        |

Members manage only their own channels and cannot see the team's or a client's. A Member limited to assigned clients gets alerts only for those clients' sites.

To connect a chat service, see [channel setup](./channels.md). To receive webhooks, see [verify webhook deliveries](./webhook-receivers.md).

## Categories

| Category        | Meaning                                                                            | Digest allowed |
| --------------- | ---------------------------------------------------------------------------------- | -------------- |
| **Outage**      | A site is Down (uptime).                                                           | No             |
| **Failure**     | A check result Failed: certificate, DNS, broken links and so on.                   | No             |
| **Check error** | We could not check, for example a timeout or a blocked request.                    | Yes            |
| **Warning**     | Something needs attention soon.                                                    | Yes            |
| **Expiring**    | A certificate or domain expires soon.                                              | Yes            |
| **Recovery**    | An outage is over or a check is fine again.                                        | No             |
| **Changes**     | New broken links, DNS or certificate changes, lower scores, faster response times. | Yes            |
| **System**      | Heptapod itself needs attention: backups, mail, the scheduler.                     | No             |

## Defaults

| Channel                         | Starts with                                                                           |
| ------------------------------- | ------------------------------------------------------------------------------------- |
| A new team channel              | Every category except Changes and System; Check error as Digest                       |
| The first Owner's account email | Every category except Changes, plus System; Check error as Digest                     |
| Another person's account email  | Outage, Failure and Recovery, plus Check error as Digest, for every site they can see |
| A client channel                | Nothing. No alert reaches a client until you turn a category on.                      |

- System is never offered to clients or Members.
- The Owner role always gets System at its account email, also in quiet hours. That row is locked.
- Check error is the only Digest cell by default.

## Manage a channel

Each channel row shows a one-sentence summary, for example "Gets outages, failures and recoveries immediately; warnings in the 08:00 digest. Not for shop.example." Open a row to:

- Change its categories. Categories that differ from the channel's starting point are highlighted. **Reset to defaults** restores it; on an upgraded instance the starting point is the converted settings.
- Leave out single sites. A left-out site sends this channel nothing.
- **Send test**.
- **Pause** or **Resume** it. Pausing discards queued automatic alerts; resuming does not bring them back. A send already in progress can still finish.
- Edit its name and transport settings.
- **Remove channel**. Its queued messages are dropped and its delivery history stays. The account email cannot be removed.

The account email follows the account address. After a change, queued alerts wait until the new address is verified. A person cannot add more email channels.

## Who gets this

**Site settings → Alerts** and open incidents show **Who gets this**. For one check and one category, it lists every channel that would be told if it happened at this moment, and when. The site overview and every check tab show one line with the number of channels and a **Details** link to it.

- For the other channels it gives the reason: category off, site left out, paused, no access, quiet hours or digest.
- It sends nothing and does not replay history. Members see other people's addresses masked.

The sidebar, Sites and Add site warn when no visible site has a channel for outages or failures. Use **Who gets this** for one check.

## Quiet hours and digests

Each recipient has one schedule for all its channels.

| Setting                         | Effect                                                                                                      |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| **Quiet from**, **Quiet until** | Holds back everything except outages, failures and check errors. Held messages go out when quiet hours end. |
| **Also quiet on weekends**      | Extends quiet hours to Saturday and Sunday and moves weekend digests to the next weekday.                   |
| **Daily digest at**             | Time of the daily digest, 08:00 by default.                                                                 |
| **Digest days**                 | Days the digest is sent. At least one.                                                                      |

Times use the instance time zone for the team, the profile time zone for a person and the client's time zone for a client.

- The digest collects open items set to Digest, also those reported on earlier days. A day without items sends nothing.
- Each item is checked against the recipient's current access.

Digests are in beta. They may repeat warnings, include outdated ones, or count a warning twice after it recovers and returns. Use immediate alerts when you rely on a notification.

## Alert sensitivity

Alert sensitivity decides when a problem sends an alert and how often Down reminders follow. Set the team default in **Settings → Alert sensitivity**, or override it in the site settings under **Alerts**. Sites use Relaxed unless you choose another.

A sensitivity has four settings. A preset fills all four; you can then change each one.

| Preset       | Alert after (failed minutes) | Recovery after (good checks) | Other checks: wait for a confirming run | Down reminders             |
| ------------ | ---------------------------- | ---------------------------- | --------------------------------------- | -------------------------- |
| **Strict**   | 2                            | 1                            | No                                      | After 1 h, 4 h, then daily |
| **Balanced** | 5                            | 3                            | Yes                                     | After 1 h, 4 h, then daily |
| **Relaxed**  | 30                           | 3                            | Yes                                     | Once a day                 |
| **Patient**  | 60                           | 3                            | Yes                                     | Once a day                 |
| **Custom**   | 2–60                         | 1–10                         | Yes or No                               | Either                     |

- The page shows the preset that matches all four settings, otherwise **Custom**.
- A site set to **Team default** shows the team's settings read-only. Choose a preset to override them.
- Alert sensitivity delays or skips alerts only. Incidents, check results and uptime history stay complete.
- **Alert after** and **Recovery after** apply to new interruptions, not to a running episode.
- **Wait for a confirming run** and **Down reminders** apply at once, also to open incidents.
- **What you would receive** shows example outages for the current settings: one shorter than the alert delay, one that crosses it and, with **After 1 h, 4 h, then daily**, one long enough for a reminder. Each example names its length and the number of messages. A numbered marker above the five-minute squares shows when each message goes out, and the list below names it. With **Wait for a confirming run** on, a short example shows a check that fails once and sends nothing.

### Uptime outages

- **Alert after** counts failed minutes from the first failed check. It also applies to uptime check errors.
- An interruption shorter than the delay stays in the incident list without an alert.
- A short recovery without enough good checks stays in the same episode, with one opening alert per channel. Its Since time and duration cover the episode.
- Missing checks do not confirm a recovery. A failure resets the good-check count.
- A value set by import or API outside the **Custom** range stays visible with its real numbers. To change those minutes, choose a value in range.

### Other checks

With **Wait for a confirming run**, a **Failed** or **Check error** of any other check alerts only when the next run shows the same result.

- Hourly and daily checks run again 10 minutes after the first bad result. Other checks wait for their next scheduled run.
- The confirming run checks the site again. A crawl does not reuse the earlier crawl.
- A problem that is gone on the next run stays in the incident list. Neither its opening alert nor its recovery is sent.
- A change from **Check error** to **Failed**, or back, is a new problem that waits for its own confirming run.
- Digests include an alert only after its confirming run.
- Without it, the first bad result alerts at once.

The API exposes alert sensitivity on monitors; see [monitors and checks](./api.md#monitors-and-checks).

## Reminders

Only open uptime Down incidents send reminders. Other incidents send an opening and a resolution alert; their recovery title says Resolved.

- The site's alert sensitivity sets the schedule. **After 1 h, 4 h, then daily** sends the first reminder one hour after the opening alert, the second four hours later, then one every 24 hours. **Once a day** sends one every 24 hours.
- Only channels whose opening alert was accepted get reminders. Reminders stay at least one hour apart per channel.
- Quiet hours do not delay outage reminders. An incident snooze stops them.
- Muted maintenance stops reminders while active, also for incidents opened before it. See [maintenance](./maintenance.md).
- After a snooze or maintenance ends, one overdue reminder goes out, never a burst. Snoozes and maintenance do not reset the sequence.
- A recovery ends the sequence. A new incident starts the schedule again.

## Recovery alerts

- A recovery needs an accepted opening alert on the same channel. A warning or component recovery needs its own accepted opening alert.
- An opening alert held by quiet hours or a digest until after the recovery is dropped with its recovery.
- An alert that was already due, or is being retried, still goes out, followed by its recovery.
- Repeated failures of one component share one recovery.
- Recovery alerts state how long the problem lasted, for example `[Recovered] <site> after 7 min`. A scheduled task also lists **Failure started** when it is known.
- Chat messages say **Total downtime** for uptime and **Total duration** for other checks.

## Checks grouped under an outage

When a site is Down, a Lighthouse, sitemap, broken-links or mixed-content failure with the same cause joins the uptime alert instead of alerting on its own.

- Matching causes are a TLS failure or an HTTP 5xx on the site's start page or first sitemap document. HTTP 4xx, external links and low scores do not match.
- The uptime alert lists them as "Also affected". Each keeps its own incident and result. A check that already sent its own alert is never grouped.
- After the site recovers, each grouped check re-checks on its next normal run. It counts as needing attention only if that run fails, and then alerts on its own.
- If the site goes Down again first, the check stays grouped under the new outage.

## When monitoring stops

Disabling a check, pausing a site or deleting it closes its open incidents without a recovery alert. Pending openings, reminders and recoveries of those incidents are not sent. Delivery views show **Monitoring stopped**. A send already in progress can still finish.

Deleting a site removes its checks, runs and incidents. Timeline events and delivery history stay, without a link to the site.

### Moving a site to another client

Owners and Admins choose another active client in the site settings. Settings, secrets and history move with the site; client channels and maintenance stay with the client.

- Members of the new client gain access. Members with access only to the old client lose it.
- Open incidents close without an alert and show "Site moved to another client". A check that still fails alerts the new client's channels.
- Channel site exceptions and snoozes stay with the site.
- The site leaves existing daily digests. A digest planned before the move may still be sent.

## Delivery outcomes

| Outcome                   | Meaning                                                                                                                   |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| **Accepted**              | The mail server or chat service accepted the message. It is no proof that anyone read it. Later bounces are not reported. |
| Rejected                  | The server refused this recipient or message permanently (SMTP 5xx). No retry.                                            |
| Failed (config)           | The connection, TLS or sender settings failed.                                                                            |
| Retrying                  | A temporary error (SMTP 4xx or a transport failure). Heptapod tries again.                                                |
| Suppressed                | Not sent on purpose, with the reason.                                                                                     |
| **Logged, not delivered** | The mail transport only logs. This does not count as an accepted alert.                                                   |

Members see only their own address on delivery rows. Alerts for a person are dropped when they lose access to the site's client.

## Email setup

A fresh installation uses `MAIL_MAILER=log`: mail is written to the app log, not sent. The `array` transport does not send either.

1. Ask the instance Owner if you do not manage the server. The Owner sees the active transport in **Settings → Instance**.
2. In `.env.prod`, set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` and, if your provider needs it, `MAIL_SCHEME`. An API transport works too.
3. Recreate the app and worker containers.
4. Open a channel in **Settings → Notifications** and select **Send test**.

## Send to a client

Besides automatic alerts, you decide when a client hears about something. Owners and Admins with access to the client can send; Members see the history.

1. Select **Send to client…** on a broken links, mixed content or sitemap check with current findings, or in the actions of an incident.
2. Choose the client's email channels. A paused channel can be chosen. Other channel types are shown but cannot receive it.
3. Optionally add **Another address (this time only)**. It gets exactly one attempt and is not saved as a contact.
4. Add a note. For findings, attach all of them as a CSV file, up to 10,000 rows and 5 MB.
5. Check the preview. It shows the email in the client's language and time zone, without links into Heptapod.
6. Select **Send to client**.

- The message goes out at once, also in quiet hours. Replies go to your own address.
- What you send is fixed: a later channel change does not alter it. Removing a channel before sending stops it.
- A double click or retry within 24 hours sends nothing twice.
- Limits: 20 messages per sender per hour, 10 per client per 24 hours.

The client's **Messages** tab lists every message with its outcome. **Open message** and **Download CSV** show what was sent. An undelivered message offers **Send again** with the same note.
