---
title: Watchdog
description: Get an alert from outside your server when Heptapod itself stops, using its heartbeat and health URL.
---

Heptapod cannot tell you when Heptapod itself is down. A watchdog is a service outside your server that alerts you when the heartbeat stops or the health URL fails.

## What Heptapod reports itself

Heptapod opens the system issue "No check has finished for more than ten minutes" when checks are due but no result arrives. Owners receive it, and Admins who turned on system mail.

- The scheduler evaluates system issues every five minutes and sends this mail from your server.
- When the server, the scheduler, the database or the mail connection is down, this mail cannot leave.
- A watchdog covers these cases, because it alerts when a signal stops arriving.

## Signals

Heptapod gives a watchdog two signals: a heartbeat it sends and a health URL the watchdog polls.

### Heartbeat

Set `INSTANCE_HEARTBEAT_URL` in the server environment, then recreate the scheduler and app containers. The scheduler then sends a `GET` request to that URL every minute.

- The request adds one query parameter, `check_age_seconds`. Keep a secret token in the path or query of the URL.
- The request times out after 10 seconds. A response other than 2xx counts as a failed heartbeat.
- An empty value turns the heartbeat off.
- Heptapod needs its database to build the request. When the database is down, no heartbeat is sent.
- **Settings → General** shows under **Watch the watcher** whether the heartbeat is configured and when it last succeeded.

A missing heartbeat means the server, the scheduler or the database stopped.

### Check age

`check_age_seconds` says how long checks have been due without a finished result. Its value is one of:

- An integer: the seconds since the later of the last finished check run and the moment the oldest outstanding check became due.
- The literal `null`: no result is expected. This is the case in maintenance mode, and when no enabled check on an active site is due.

A heartbeat with a growing integer means the scheduler runs, but checks do not finish. A typical cause is a stopped queue worker. Heptapod opens its own system issue above 600 seconds.

### Health URL

`/up` on your Heptapod address answers with status 200 while the web application serves requests. Any other answer, a timeout or a refused connection is a failure.

- `/up` does not test the database, the queue worker or the scheduler.
- `/up` answers 200 in maintenance mode too, so planned work does not trip it.
- **Settings → General** shows the exact URL under **Health URL for polling**.

A failing `/up` means visitors cannot reach Heptapod. The heartbeat and the health URL fail independently, so watch both.

## Choose a watchdog

Pick one option. Each one needs an alert channel that does not depend on your Heptapod server.

### A dead-man service and an uptime monitor

Use two hosted services. A dead-man service alerts when expected pings stop, for example Healthchecks.io.

1. Create a check with a period of one minute and a grace time of a few minutes.
2. Set its ping URL as `INSTANCE_HEARTBEAT_URL`.
3. Add an uptime monitor for `https://<your Heptapod address>/up` in any uptime service.

Result: you get an alert when the heartbeat stops or `/up` fails. A generic dead-man service ignores `check_age_seconds`, so it does not alert when checks stop finishing.

### Heptapod Sentinel

[Heptapod Sentinel](https://github.com/heptapodwatch/sentinel) is a ready-made watchdog for one Heptapod instance. It runs on the Cloudflare Workers free tier and understands all three signals.

- It alerts when no heartbeat arrives for five minutes.
- It alerts when `check_age_seconds` stays above 900 for two heartbeats in a row.
- It polls `/up` every five minutes and alerts after two failures in a row.
- It sends alerts to a webhook, ntfy, Brevo or Cloudflare email.

Its README describes the setup and its limits. It is a reference implementation with best-effort support.

### Build your own

Any service outside your server can be a watchdog. It needs to:

1. Accept a `GET` request every minute at a secret URL and answer with 2xx.
2. Alert when no request arrives for a few minutes.
3. Read `check_age_seconds` and alert when the integer stays high. Treat `null` as healthy.
4. Request `/up` every few minutes and alert when it fails repeatedly. Treat a redirect as a failure.
