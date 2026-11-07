---
title: Performance
description: Response-time warnings and recovery
sidebar:
    order: 8
---

Performance uses accepted online uptime observations from its current window: twice the uptime check's interval, at least 60 minutes and at most 23 hours. With the default uptime interval this is the last 60 minutes. The 23-hour cap keeps the window clear of the previous day's baseline hour. An uptime check that runs less often than every 12 hours gives no response-time data. It makes no extra request to the site. With no eligible observations, it produces no result and keeps the current warning state. Failed uptime observations, check errors and missing timings are not samples.

An evaluation is elevated when either:

- Its 60-minute median exceeds the configured `threshold_in_ms` (default: 2000 ms).
- Its 60-minute median exceeds `max(baseline p90, baseline median × (1 + change_percentage / 100))`, and is at least 200 ms above the baseline median. Both conditions must hold. `change_percentage` is the minimum change from normal (default: 50%). A 47 → 93 ms increase does not qualify.

The historical baseline consists of accepted online uptime response times from the same UTC hour on each of the preceding seven days. Today is excluded. For example, an evaluation at 14:30 UTC compares its last 60 minutes with observations from 14:00–15:00 UTC on those prior days. The baseline median describes the usual response time; its p90 is the upper end of the usual range: 90% of those samples are at or below it. Percentiles use linear interpolation.

Relative comparisons require at least three observations in the current 60-minute window and at least nine baseline observations across at least three distinct prior days, with a positive baseline median. Until that history exists, relative warnings are disabled. The absolute threshold remains active, even without a baseline or with fewer than three current observations.

Both warning branches use the current median, so one isolated slow request does not dominate a populated window. The displayed average remains the arithmetic mean of the same 60-minute samples; it is not a warning trigger.

Three consecutive elevated performance evaluations enter Warning. While Warning, three consecutive evaluations must clear the recovery band before the warning ends. `recovery_margin_percentage` sets the band margin from 0 to 20%, with a default of 5%. The absolute rule recovers at or below `threshold_in_ms × (1 − recovery_margin_percentage / 100)`. For the relative rule, the same margin applies to the greater of the relative threshold and baseline median plus 200 ms, preserving the entry rule's equality boundaries. Both rules must clear. Values inside the band keep Warning and reset recovery progress. A margin of 0 preserves the warning and recovery boundaries without a band. A non-elevated evaluation breaks an entry streak. Each evaluation is one accepted performance result, not an individual HTTP request. Confirmation progress is shown while a warning or recovery is being confirmed. This rule applies only to performance warnings; uptime failures and other checks keep their existing thresholds.

“What we check” shows “Recovers below” directly under Threshold. With the defaults, this is 1900 ms. This line is the absolute recovery bound; the relative bound varies with the historical baseline. Changing the margin takes effect on the next evaluation and does not reset confirmation counts. Existing configurations use the default without a migration. The API returns stored setting keys; omitted keys use the documented defaults.

A clear speed-up adds one information event to Activity and the check history, for example “Got faster: 2.1 s → 0.31 s (-85%)”. It needs the same history as relative warnings, a 60-minute median below `baseline median × (1 − change_percentage / 100)` and at least 200 ms below the baseline median, on three consecutive evaluations outside a Warning. A recovery from a Warning does not count. The event appears once; another one needs three evaluations in a row back at the usual response time first (slow evaluations and Warnings do not count). It does not change the result or open an incident. Notifications for it use the Changes category, which is off by default.

The settings form labels `change_percentage` as “Minimum change from normal”. Its API/import key and validation are unchanged; stored values remain active and valid, with no migration. The similarly named run metric reports the observed percentage change between the current and baseline medians.
