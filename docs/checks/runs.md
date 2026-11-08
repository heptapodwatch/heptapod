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

| Result      | Meaning                                                                                                            |
| ----------- | ------------------------------------------------------------------------------------------------------------------ |
| Failed      | The check's result failed. This includes non-uptime checks.                                                        |
| Down        | The site has an open uptime incident. A failed uptime result can come before the outage incident opens.            |
| Check error | The attempt errored or timed out. It is not proof that the site is down.                                           |
| Late        | A due check has not started within `max(60 seconds, 10% of its interval)`. Late is a schedule state, not a result. |

A late check shows **Late by** the time since its due time. Read the check type, result and recorded cause together. See [notifications](../notifications.md) for how results become alerts.

## Run checks by hand

- **Run check** on a check page queues one run. Each check accepts one manual request every 30 seconds.
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

**Pause** in the site header stops checks and alerts and keeps history. **Resume** starts them again. A scheduled resume in one hour or tomorrow at 08:00 uses the instance time zone.

## Queues and retention

| Item                | Value                                                           |
| ------------------- | --------------------------------------------------------------- |
| Browser worker pool | One worker                                                      |
| Crawl worker pool   | Its configured concurrency                                      |
| Lost queued job     | Requeued after ten minutes; a job still waiting keeps its claim |
| Progress updates    | At most one per second, only while you view the check           |
| Attempt history     | Seven days (`MONITORING_ATTEMPT_RETENTION_DAYS`)                |
| Check results       | The check history retention                                     |

Upcoming due times are on **Upcoming**, not on Runs.

## Live events

- `run.finished` carries the outcome: `done`, `failed`, `cancelled` or `skipped`. It contains no evidence or secrets.
- Every completed result also sends `state.changed`, so history and metrics refresh when the state stays the same.
