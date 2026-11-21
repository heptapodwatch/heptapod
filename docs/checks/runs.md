---
title: Check runs
description: See queued, running and late checks, run checks by hand and cancel long crawls.
sidebar:
    order: 5
---

Each check runs at its next due time on the fast, crawl or browser queue. **Runs** (`/runs`) shows what is queued, running and finished, with the attempt history below.

## Read the Runs page

- The live board shows queued, running and finished runs.
- The attempt history lists every attempt, including skipped and cancelled ones.
- Filter by client, check, check type and state. Filters never widen what you are allowed to see.
- The history hides attempts whose result matches the check's previous result. **Show runs with the same result as before** includes them. It compares result states, not metrics or findings.
- First results, changed results, queued, running and late work always stay visible. So do attempts whose previous result is unknown or pruned.
- Runs sits in the Operations group of the navigation. Its badge counts late checks only.

## Results

| Result          | Meaning                                                                                                                                           |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Failed**      | The check's result failed. This includes non-uptime checks.                                                                                       |
| **Down**        | The site has an open failed uptime incident. An uptime Check error is not Down. A failed uptime result can come before the outage incident opens. |
| **Check error** | The attempt errored or timed out. It is not proof that the site is down.                                                                          |
| Late            | A due check has not started within `max(60 seconds, 10% of its interval)`. Late is a schedule state, not a result.                                |

A late check shows **Late by** the time since its due time. Read the check type, result and recorded cause together. See [notifications](../notifications.md) for how results become alerts.

## Monitor outage guard

When at least three uptime checks fail with network errors inside two minutes, Heptapod checks two outside DNS names and two direct-IP targets on independent providers.

- If a burst has no fresh control verdict yet, Heptapod queues one background probe job and records the check normally without waiting.
- If both DNS control probes fail, DNS-resolution uptime failures are recorded as **Check error** instead of **Down**.
- If both direct-IP control probes fail, connection, timeout and DNS uptime failures are recorded as **Check error** instead of **Down**.
- Reclassified runs open no site incidents, send no per-site alerts and count as no site downtime.
- The first wave can still record normal failures before the probe verdict is cached. With a failure threshold of one, or a prior failure, those checks can still open **Down**.
- One System alert reports the monitor outage and its recovery.
- HTTP errors (such as 5xx), TLS failures and content failures stay **Down**.
- If the control probes succeed, normal **Down** handling stands. This covers a partial monitor fault.
- If the control probes fail during a real wide outage, runs show **Check error** and the System issue explains why.

Control probes send no telemetry or instance data. Operators can configure or turn them off through server environment variables.

## Run checks by hand

- **Run check** on a check page queues one run. A fast check accepts one manual request every 30 seconds, a crawl or browser check one every five minutes.
- **Run all checks…** in the site header queues every enabled check after a confirmation. The confirmation lists each check with its queue and maximum run time, because it sends requests to the site.
- **Only fast checks** skips crawl and browser checks.
- Run all reports each check as queued or skipped with a reason: busy, rate-limited, unavailable or fast checks only. A busy check does not stop the others.
- A manual run does not move the check's schedule.

## Cancel a run

Only Broken links, Mixed content and Lighthouse runs can be cancelled, with **Cancel** on the check page. Sitemap shares the crawl queue but cannot be cancelled.

- A queued run stops before it starts.
- A running run stops at its next checkpoint and is marked cancelled. It produces no result, incident change or change event.
- If the result was recorded before the cancel request, the result stands.
- Cancelling never moves the next due time.

## Pause a site

**Pause site** under **More actions** in the site header stops checks and alerts and keeps history. **Resume site** starts them again. A scheduled resume in one hour or tomorrow at 08:00 uses your time zone.

## Queues and retention

| Item                | Value                                                                                                 |
| ------------------- | ----------------------------------------------------------------------------------------------------- |
| Browser worker pool | One worker                                                                                            |
| Crawl worker pool   | Its configured concurrency                                                                            |
| Lost queued job     | Requeued after ten minutes (fast) or 25 minutes (crawl, browser); a job still waiting keeps its claim |
| Progress updates    | At most one per second, only while you view the check                                                 |
| Attempt history     | Seven days (`MONITORING_ATTEMPT_RETENTION_DAYS`)                                                      |
| Check results       | The check history retention                                                                           |

Upcoming due times are on **Upcoming**, not on Runs.
