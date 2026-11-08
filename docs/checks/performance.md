---
title: Performance
description: Warn when a site's response time stays above a threshold or well above its usual level for the hour.
sidebar:
    order: 8
---

The performance check reads the response times of the uptime check and warns when they stay high. It sends no requests of its own and raises a Warning, never Down.

## Settings

| Setting                        | API key                      | Default | Range     |
| ------------------------------ | ---------------------------- | ------- | --------- |
| **Threshold**                  | `threshold_in_ms`            | 2000 ms | 0 or more |
| **Minimum change from normal** | `change_percentage`          | 50%     | 0 or more |
| **Recovery margin**            | `recovery_margin_percentage` | 5%      | 0–20%     |

The API returns only the keys you saved. Missing keys use these defaults.

## When it warns

Each evaluation compares the median response time of its sample window with two limits. It is elevated when either applies:

- **Threshold**: the median is above **Threshold**.
- **Usual level**: the median is above both the baseline p90 and the baseline median plus **Minimum change from normal**. It must also be at least 200 ms above the baseline median. A rise from 47 ms to 93 ms does not count.

Three elevated evaluations in a row raise a Warning. A normal evaluation resets that count. While a warning or recovery is being confirmed, the check shows its progress.

The median decides, so in a well-populated window one slow request does not trigger a warning. **Threshold** has no minimum sample count, so a single slow sample in a sparse window can. The average shown on the check is the mean of the same samples and never triggers one.

## When it recovers

A Warning ends after three evaluations in a row below the recovery limit of both rules:

- **Threshold** rule: at or below **Threshold** × (1 − **Recovery margin**). With the defaults this is 1900 ms, shown as **Recovers below** in **What we check**.
- **Usual level** rule: the same margin below the greater of the relative limit and the baseline median plus 200 ms.

A value between the limit and the recovery limit keeps the Warning and resets recovery progress. A margin of 0 keeps the warning boundaries without a band. A change to the margin applies from the next evaluation and keeps the current counts.

## Baseline

- The baseline is the uptime response times from the same UTC hour on each of the last seven days, today excluded. An evaluation at 14:30 UTC compares with 14:00–15:00 UTC on those days.
- The baseline median is the usual response time. Its p90 is the upper end of the usual range. Percentiles use linear interpolation.
- The usual-level rule needs at least three current samples and nine baseline samples from at least three days, with a positive median. Until then only **Threshold** applies.

## Samples

- Samples are the response times of successful or Warning uptime results in the sample window: twice the uptime interval, at least 60 minutes and at most 23 hours.
- Failed uptime checks, check errors and missing timings are not samples.
- An uptime check that runs less often than every 12 hours gives no response-time data.
- Without samples, an evaluation produces no result and keeps the current state.

## Faster responses

A clear speed-up adds one event to Activity and the check history, for example "Got faster: 2.1 s → 0.31 s (-85%)".

- It needs the same baseline as the usual-level rule.
- The median must be below the baseline median minus **Minimum change from normal**, and at least 200 ms below it, on three evaluations in a row outside a Warning.
- Recovery from a Warning does not count. Another event needs three evaluations back at the usual level first.
- It changes no result and opens no incident. Its notifications use the Changes category, which is off by default.

The run metric `change_percentage` is the observed change between the current and baseline medians, not the setting.
