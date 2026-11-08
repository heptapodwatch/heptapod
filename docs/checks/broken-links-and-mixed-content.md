---
title: Broken links and mixed content
description: Crawl a site once a day for broken links and for HTTP resources on HTTPS pages.
sidebar:
    order: 1
---

The broken-links check crawls the HTML pages of a site and checks every link, image, script and stylesheet it finds. The mixed-content check uses the same crawl and reports HTTP resources on HTTPS pages.

## Results

| Result  | Broken links                                                                  | Mixed content                                |
| ------- | ----------------------------------------------------------------------------- | -------------------------------------------- |
| OK      | No broken links found.                                                        | No HTTP resources on HTTPS pages.            |
| Warning | Broken external links, links that could not be checked, or rate-limited URLs. | —                                            |
| Failed  | Broken internal links.                                                        | At least one HTTP resource on an HTTPS page. |

A finding stays until a later complete crawl confirms it is fixed.

## What counts as broken

- An HTTP error status, an unreachable link or a malformed HTTP(S) URL. A malformed URL shows the page it was found on.
- An external link is broken only on hard evidence: 404 or 410, an invalid certificate or another client error.
- Links with `javascript:`, `mailto:`, `tel:` or `data:` are ignored.
- Spaces in a link path are encoded like a browser does before the check. A malformed host stays invalid.
- Cloudflare's email-protection path `/cdn-cgi/l/email-protection` on the site's host or its `www`/apex alias is ignored by default. Other 404s still count.
- A page or resource is never broken only because of its size. Above 2 MiB, Heptapod retries the URL with HEAD to get its status. Links inside an oversized page are not read.

## Links that are not broken

These links appear in their own group and do not count as broken:

- **Could not be checked**: the request timed out, could not connect or was reset (`unchecked (timeout)`, `unchecked (connect)`, `unchecked (connection reset)`). They make an otherwise clean run a Warning.
- **Not verifiable**: the site blocks automated checks (bot protection), is rate-limited, or the link points to a private network address.
- **Not verifiable, external links only**: the site requires a login, refuses the request, its host name does not resolve, or TLS fails. The same cause on an internal link is broken.

Identifying the client does not get past a challenge or WAF rule. Use **Allowed broken URLs** for links you know are fine.

## Settings

| Setting                  | Default | Range                                                                 | Effect                                                                              |
| ------------------------ | ------- | --------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| **Maximum pages**        | 2,000   | 1–10,000                                                              | Same-origin pages crawled. Mixed content shares this crawl.                         |
| **Maximum depth**        | 5       | 0–20                                                                  | Link steps from the start page.                                                     |
| **Check external links** | On      | —                                                                     | Checks the status of external links. External pages are never crawled.              |
| **Resource types**       | All     | **Link**, **Image**, **Script**, **Stylesheet**, **Open Graph image** | What the broken-links check status-checks. Mixed content always inspects resources. |
| **Respect robots.txt**   | On      | —                                                                     | Applies robots.txt to same-origin pages and resources.                              |
| **Only new links**       | Off     | —                                                                     | Has no effect on crawls or results.                                                 |
| **Exclude patterns**     | Empty   | Up to 1,000                                                           | URLs or `*` patterns that are never crawled.                                        |
| **Do not crawl URLs**    | Empty   | Up to 1,000                                                           | URLs or `*` patterns that are never crawled.                                        |
| **Always crawl URLs**    | Empty   | Up to 1,000                                                           | URLs or `*` patterns crawled even when robots.txt or another rule skips them.       |
| **Allowed broken URLs**  | Empty   | Up to 1,000                                                           | Full HTTP(S) URLs or wildcard patterns that never count as broken.                  |

### Allowed broken URLs

- Entries match the full URL. Scheme and host case, path spaces and trailing slashes are normalized. Path and query keep their case, and fragments are ignored.
- `*` matches across path and query, for example `*/news/sample?edition=*`. An exact entry needs an exact query.
- In a pattern that starts with `*`, write the host in lowercase.
- An entry that is not a valid URL matches as a glob, without its fragment and with spaces as `%20`.
- An invalid exact entry is ignored and noted in the run evidence, without showing the entry.

## Limits and incomplete crawls

| Limit                                         | Default       | Change it with                             |
| --------------------------------------------- | ------------- | ------------------------------------------ |
| Pages                                         | 2,000         | **Maximum pages**, up to 10,000            |
| Same-origin resources and external URLs, each | 10,000        | `HEPTAPOD_CRAWL_MAX_STATUS_URLS`, 1–25,000 |
| Comparison of pages, resources and findings   | 25,000 items  | Fixed                                      |
| Run time                                      | 1,200 seconds | `HEPTAPOD_CRAWL_TIMEOUT_SECONDS`, 1–1,200  |
| Malformed URLs per page                       | 100           | Fixed                                      |
| Response body                                 | 2 MiB         | Fixed                                      |
| Parallel requests                             | 5             | Fixed                                      |

To raise an environment limit, set it in the production environment, rebuild the configuration cache and restart the workers. Larger budgets cost crawl time and memory; size workers with the [capacity table](../deploy/operations.md#installation-and-capacity).

A crawl that hits a limit is incomplete and says so, for example "Crawl incomplete (cap 2000 reached)". An incomplete crawl never resolves an incident or clears a finding.

- A page or resource limit still opens or escalates an incident for confirmed failures and reports new findings.
- A time limit or the comparison limit opens nothing, escalates nothing and sends no change events. It keeps earlier findings and records `time_limit_reached` or the comparison flag in the run evidence.
- A clean run that hit a limit is OK but incomplete.
- A 404 or 410 link does not make a crawl incomplete. Unavailable pages, timeouts and limits do.

Progress reads "N pages checked · M URLs discovered · limit reached". M includes resources and external URLs, so it is not a page count.

## When a new crawl runs

Both checks run once a day and share one recent crawl.

- A change to depth, limits, resource types, crawl lists, external checking or robots.txt starts a new crawl.
- A change to **Allowed broken URLs** re-evaluates the current crawl without a new one.
- **Run check** on either check always starts a new crawl.

## Mixed content

- Mixed content checks the images, scripts and stylesheets of HTTPS pages. An HTTP resource is a finding.
- A malformed resource URL is reported by broken links, not here.
- Both checks read HTML only. They do not run JavaScript.

## Check detail

- Detail shows up to 50 findings from the last accepted crawl.
- `observed_at` and the cap flags describe that crawl. `run_ended_at` and the comparison flag describe the latest run, which may not have replaced it.
- Each broken link shows its first observation in retained history.
- New and fixed counts appear once a crawl from at least seven days ago exists.

## Request headers

All HTTP requests from Heptapod (checks, crawls, sitemaps, site icons, RDAP lookups, update checks and HTTP notifications) send:

| Header            | Value                                                 |
| ----------------- | ----------------------------------------------------- |
| `User-Agent`      | `HeptapodBot/<version> (+https://heptapod.watch/bot)` |
| `Accept`          | `text/html,application/xhtml+xml;q=0.9,*/*;q=0.8`     |
| `Accept-Language` | `en,de;q=0.9,*;q=0.5`                                 |

- Site crawler headers and check headers can override them on same-origin requests.
- After a redirect leaves the origin, only these public headers are sent, even if a later hop returns. Credentials never go to other origins.
- Lighthouse sends the headless Chrome User-Agent. Certificate, DNS and WHOIS checks make no HTTP requests.
