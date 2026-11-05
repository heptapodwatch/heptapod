---
title: Sitemap
description: Sitemap reachability, rotation, limits and incomplete runs
sidebar:
    order: 2
---

The sitemap check reads `/sitemap.xml` by default and follows sitemap indexes. Set another site-relative path through the check configuration. Discovery is limited to 10,000 entries, 100 sitemap documents, depth 5 and 2 MiB per XML response. These limits are fixed.

## URLs checked per run

Each run first fetches and validates every sitemap document. It then checks the reachability of a window of the listed URLs. The setting **URLs checked per run** (`urls_per_run`) sets the window size per check:

| Value                | Meaning                                                                                           |
| -------------------- | ------------------------------------------------------------------------------------------------- |
| `0`                  | Structure only: the sitemap documents are fetched and validated, and no listed page is requested. |
| `100`, `500`, `1000` | This many listed URLs per run. `500` is the default.                                              |
| `max`                | As many URLs as fit into the run time at the chosen speed.                                        |

Each run continues where the previous accepted run stopped. Over successive runs, the whole sitemap is covered. With a stable sitemap of N URLs and a window of size S, every URL is checked within ⌈N/S⌉ runs. For example, at 500 per run and a daily check, 1,663 URLs are covered every 4 days. URLs that fall outside the current window are unchecked, not confirmed broken or healthy.

The order is the order of discovery: each URL is counted once, at its first listing. Over-long and credential-bearing locations are reported as sitemap issues on every run; they are never requested and do not take a place in the window. A window ends at the end of the list, and the next run starts again at the first URL. When the sitemap changes between runs, the next run continues at the same position in the new list. If that position is past the end of a shrunken list, the run starts at the first URL. A changed sitemap can therefore shift which URLs a window covers. A run that ends with a check error does not move the position.

With `max`, URLs are checked in batches of 100. A new batch starts only while the remaining run time is at least twice the duration of the previous batch plus 10 seconds. Stopping there is a planned limit, not a time-limit error, and the next run continues with the following URLs.

A broken URL stays an open finding until a later run checks it again and finds it reachable, or until the sitemap no longer lists it: a URL removed from the sitemap is no longer a sitemap problem, in every mode. A run that checks a different window, or a structure-only run, keeps earlier findings open and cannot report them as resolved. A Failed incident is resolved only once every known broken URL has been checked again and found reachable.

## Speed and run time

Requests share an execution-scoped pool with at most four in flight per host. The nominal start gate is 110 ms plus the configured additional delay: default 25 ms, fast 10 ms, slow 50 ms, slowest 100 ms, fastest 0 ms. Sitemap documents, redirect hops and HEAD-to-GET retries share that gate. The sitemap check probes URLs published in the sitemap without enforcing robots rules; the separate crawl checks retain their robots behavior.

The shared check budget remains 300 seconds. Each request has a nominal 10-second ceiling beginning at its first admission, retained across redirects, and bounded by the check's remaining budget minus a 500 ms reserve. Requests are not started with less than about one second of usable budget, including after DNS validation. Refused requests and budget-clamped timeouts remain unchecked, not broken links. These bounds do not guarantee that every URL can be checked before the deadline.

## Incomplete runs

A run that reaches its execution deadline is **Errored**, with an incomplete-time-limit summary and partial evidence. Site detail and the Sites list report a check error, not OK or target downtime. Detail shows checked/total counts and confirmed issues from the partial run; unchecked URLs must not be presented as healthy or broken.

A deadline-exhausted run cannot open, escalate or resolve an incident, and emits no issue-change events. The previous comparison baseline remains intact. Discovery limits and the per-run window are different: they can produce a Failed result and comparable findings, but retain earlier findings on unchecked URLs rather than reporting false recovery. A complete check without issues can resolve an existing Failed incident. New time-limit summaries include checked/total counts; older summaries without those parameters remain readable.
