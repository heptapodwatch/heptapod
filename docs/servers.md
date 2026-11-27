---
title: Servers
description: Watch disk, memory, load and reports of your own servers with a small agent, get alerts and link the sites they host.
---

:::caution
Servers are experimental. The feature, its agent, its data and its API may change or be removed. Their data will not be migrated.
:::

## What it is

Servers are an optional host layer next to your sites. You install a small agent on each Linux server. It reports one sample per minute: disk, memory, CPU and load, plus a few system facts.

- Heptapod shows the current values and the last 14 days, and alerts when a value stays too high or reports stop.
- The agent only sends. It opens no port, runs no remote commands and does not update itself.
- Servers are not application performance monitoring. Heptapod collects no logs, traces, process lists or file contents.

The [agent README](https://github.com/heptapodwatch/heptapod/blob/main/agent/README.md) lists what the agent reads, what it never does and how it is sandboxed.

## Turn it on

Two switches must both be on.

1. The instance Owner sets `HEPTAPOD_SERVERS_ENABLED=true` in `.env.prod` and recreates the app and scheduler containers. The default is `false`, a hard off.
2. An Owner opens **Settings → General** and checks **Servers (experimental)** under **Experimental**, then saves.

Result: Owners and Admins see **Servers** in the sidebar. Members never see servers, server alerts or the server card on a site.

## Add a server and install the agent

The server needs systemd on amd64 or arm64, `curl` and `sha256sum`, and it must reach your Heptapod address over HTTPS.

1. Open **Servers** and select **Add server**.
2. Enter a name and select **Add server**.
3. Under **Install the agent**, copy the **Install command** and run it on the server.
4. When the installer asks for the agent token, paste the **Agent token** from the page. The page shows the token once.

The install command has this form:

```sh
d=$(mktemp -d) \
  && cd "$d" \
  && curl -fsSLO --proto '=https' https://github.com/heptapodwatch/heptapod/releases/download/vX.Y.Z/install.sh \
  && sudo sh install.sh --version vX.Y.Z --endpoint https://heptapod.example.com/agent/v1/report
```

Result: the server changes from **Awaiting first report** to **Reporting**, and the page shows its values.

- The script and the agent come from the GitHub release of your Heptapod version, never from your instance. **Download, verify the checksum, then run** shows a variant that checks the checksum first.
- An instance that runs no release installs the agent of the latest release. Without a known release, the page shows no command.
- Lost the token? Select **Rotate token…** on the server page and run the installer again. The old token stops working at once.
- **Remove server…** deletes the server and its samples. Uninstall the agent on the server with `sudo sh install.sh --uninstall`.

## Link sites

Link each site to the server it runs on. A linked site shows the server on its Overview, and the server page lists its sites.

- Select **Link site…** on the server page, or pick the **Server** in a site's settings.
- A site runs on one server. Linking it to another server moves it there.
- **Suggested from DNS** lists sites on no server whose DNS check resolves the site's host name to an address this server reports. A site behind a CDN is never suggested.

## Alerts

A server issue opens when a value stays at or above its threshold in every sample for the whole duration. A missed report breaks the streak. The first sample below the threshold resolves the issue.

| Issue                | Default threshold                   | Default duration |
| -------------------- | ----------------------------------- | ---------------- |
| **Disk almost full** | 90 % used, on each mount            | 15 min           |
| **Low memory**       | 90 % of RAM used                    | 10 min           |
| **High load**        | 5-minute load of 2 × the vCPU count | 15 min           |
| **No report**        | No report for 2 min                 | None             |

- Heptapod looks for missing reports every five minutes. The next report resolves **No report**.
- To change thresholds for one server, open **Settings…** on its page. Under **Alert thresholds**, set each value and duration; disks can differ per mount. **Reset** restores the default.
- Under **Mounts**, clear a mount to ignore it. It never alerts, and its open issue ends without a recovery alert.

### Snooze a server

Select **Snooze** on the server page and choose 1 hour, 1 day, 7 days or **Until a date…**. **End snooze** ends it early.

- The server keeps reporting, and issues keep opening and resolving.
- An issue that opens during the snooze is sent when the snooze ends, if it is still open.
- A recovery during the snooze sends no alert.

### Who gets server alerts

Server alerts use the **Servers** category in the notification settings: **Immediately**, **Digest** or **Off**.

| Channel                                | Servers                                       |
| -------------------------------------- | --------------------------------------------- |
| Personal channels of Owners and Admins | On by default                                 |
| Team channels                          | Off by default; turn **Servers** on to opt in |
| Team channels limited to one client    | Never                                         |
| Client channels and Members' channels  | Never                                         |

- Quiet hours hold server alerts until they end. Digests work as for site alerts; see [quiet hours and digests](./notifications.md#quiet-hours-and-digests).
- A recovery goes to the channels that received the problem on its own.
- Webhook channels receive a `server` object; see [server alerts](./webhook-receivers.md#server-alerts).

## API

The API reads servers and snoozes them. Both abilities need an Owner or Admin token for all clients; a token for one client cannot get them. The form offers them only while servers are on.

| Endpoint                          | Ability          | Effect                                                                                                                                                 |
| --------------------------------- | ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `GET /api/servers`                | `servers:read`   | Lists servers with their state, open issue count and current values, paged. `filter[state]` takes `awaiting`, `reporting`, `no_report` or `attention`. |
| `GET /api/servers/{id}`           | `servers:read`   | One server with its thresholds, facts, open issues and the linked sites the token can see.                                                             |
| `GET /api/servers/{id}/issues`    | `servers:read`   | The server's issues, open and resolved, paged.                                                                                                         |
| `POST /api/servers/{id}/snooze`   | `servers:snooze` | Snoozes alerts: `{"minutes": 30}` (1–525600), or `snooze_until` with a `Z` or offset, at most 365 days ahead.                                          |
| `POST /api/servers/{id}/unsnooze` | `servers:snooze` | Ends the snooze.                                                                                                                                       |

Snooze a server around a deploy, then end the snooze:

```sh
curl -X POST https://heptapod.example.com/api/servers/3/snooze \
  -H "Authorization: Bearer $HEPTAPOD_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" -d '{"minutes": 30}'
# deploy
curl -X POST https://heptapod.example.com/api/servers/3/unsnooze \
  -H "Authorization: Bearer $HEPTAPOD_TOKEN" -H "Accept: application/json"
```

- Snooze and unsnooze return only the server id and the snooze end (`id`, `snoozed_until`).
- While servers are off, every server endpoint returns 404.
- Every operation and field is in the [API reference](https://heptapod.watch/docs/reference/api/endpoints/) and the [OpenAPI document](./api.md#openapi-document).

## Turn it off

Clear **Servers (experimental)** in **Settings → General**, or set `HEPTAPOD_SERVERS_ENABLED=false`.

- **Servers**, the server pages, the server card on sites and the **Servers** notification category disappear.
- The server endpoints of the API and the agent endpoint return 404. Agents retry every 15 minutes.
- Heptapod keeps servers, samples, site links and issues. It deletes no samples while servers are off.
- Alerts not yet sent wait. When servers are on again, an alert goes out if its issue is still open.
