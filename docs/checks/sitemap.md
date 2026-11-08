---
title: Sitemap
description: Validate a site's sitemap once a day and check a rotating share of its URLs on each run.
sidebar:
    order: 2
---

The sitemap check reads the site's sitemap, follows sitemap indexes and checks that the listed URLs are reachable. Large sitemaps are covered over several runs.

## Settings

| Setting                               | API key        | Default           | Values                                                                                  |
| ------------------------------------- | -------------- | ----------------- | --------------------------------------------------------------------------------------- |
| **Sitemap path**                      | `path`         | `/sitemap.xml`    | A site-relative path starting with `/`                                                  |
| **URLs checked per run**              | `urls_per_run` | `500`             | `0`, `100`, `500`, `1000`, `max`                                                        |
| **Additional delay between requests** | `speed`        | `default` (25 ms) | `fastest` (0 ms), `fast` (10 ms), `default` (25 ms), `slow` (50 ms), `slowest` (100 ms) |

**URLs checked per run**:

- **Structure only (no page requests)** (`0`) fetches and validates the sitemap files only.
- `100`, `500` or `1000` checks that many listed URLs per run.
- **As many as fit** (`max`) checks URLs in batches of 100 while the run time allows. A new batch starts only while the remaining time is at least twice the last batch plus 10 seconds. Stopping there is planned, not an error.

## Limits

| Limit                       | Value                                     |
| --------------------------- | ----------------------------------------- |
| Listed URLs                 | 10,000                                    |
| Sitemap documents           | 100                                       |
| Index depth                 | 5                                         |
| Size per XML document       | 2 MiB                                     |
| Run time                    | 300 seconds                               |
| Time per request            | 10 seconds, within the remaining run time |
| Requests in flight per host | 4                                         |
| Gap between request starts  | 110 ms plus the additional delay          |

These limits are fixed. The sitemap documents, redirects and HEAD-to-GET retries share the request gap. The sitemap check requests listed URLs without applying robots.txt.

## How runs cover the sitemap

- Each run continues where the previous accepted run stopped. At the end of the list it starts again at the first URL.
- With N URLs and S per run, every URL is checked within ⌈N/S⌉ runs. At 500 per run and one run a day, 1,663 URLs take 4 days.
- URLs keep the order of their first listing. Each URL counts once.
- When the sitemap changes, the next run continues at the same position in the new list, or at the start if the list got shorter.
- A run that ends with a check error does not move the position.
- Over-long URLs and URLs with credentials are reported as sitemap issues on every run. They are never requested and take no place in the window.

## When a finding clears

- A broken URL stays open until a later run finds it reachable, or the sitemap stops listing it.
- A run that checks other URLs, or a structure-only run, keeps earlier findings open.
- A Failed incident resolves only when every known broken URL is reachable again or has left the sitemap.
- URLs outside the current window are unchecked, not healthy or broken. So are refused requests and requests cut short by the run time.

## Runs that hit the time limit

A run that reaches the 300-second limit is a Check error with its checked and total counts and the issues found so far.

- It opens, escalates and resolves nothing and sends no change events.
- The earlier findings stay as they were.
- Discovery limits and the per-run window are not errors: they can produce a Failed result, and earlier findings on unchecked URLs stay open.
