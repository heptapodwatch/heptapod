---
title: Maintenance
description: Mute alerts during planned work and get one catch-up alert for anything still failing at the end.
sidebar:
    order: 7
---

A maintenance window marks planned work on one site, a client's sites or every site with a tag. Checks keep running during the window, and you choose whether its alerts are muted.

## Schedule a window

Owners and Admins schedule windows.

1. Open **Maintenance** and select **New window**. On a site, **Schedule maintenance** in the header opens the same sheet for that site.
2. Choose the **Target**: **Site**, **Client** or **Tag**, then the record.
3. Enter **Starts** and **Ends** in 24-hour format and choose the **Timezone**.
4. Choose **Repeats**: **Once**, **Daily**, **Weekly** or **Monthly**.
5. Set **Mute alerts during the window** and **Alert if still failing at the end**. Both are on by default.
6. Select **Schedule maintenance**.

The list shows 50 windows per page, each **Planned**, **Active** or **Done**. Filter it by **Window state** and client. The site header links to that site's windows; a shared window the site left early shows as **Done** with its departure time.

## What happens during a window

- A window is active from its start, inclusive, until its end, exclusive. It ends on its own.
- Incidents open, recover and stay visible as usual.
- A muted window sends no opening or recovery alerts. It also stops all reminders for its sites, including reminders for incidents that opened before the window.
- An unmuted window changes no notifications.
- Client and tag windows include the sites that belong to the client or tag at check time. Sites added later join automatically.
- The site header shows the active or next window, muted or not.

## Catch-up alerts

With **Alert if still failing at the end** on, each incident that opened during a muted window and is still open at its end gets one alert. It follows the normal routing of each channel: categories, site mutes, quiet hours and digests.

- Each uptime outage (Down) gets its own opening alert per channel, with its saved alert sensitivity. Its note reads "Maintenance has ended. This interruption is still ongoing."
- All other failing checks of a site share one message per channel, titled `[After maintenance] <site> is still failing`. It waits for the latest due time among its checks. Uptime delays do not hold it back.
- An uptime incident without saved alert sensitivity joins this shared message.
- A channel gets only the checks whose categories it receives. A site with no matching channel sends nothing.
- A check grouped under the site's uptime outage is covered by the outage alert.
- An incident that recovered before the end gets no catch-up. A waiting catch-up is dropped when all its incidents recover.
- Active check snoozes still suppress their incidents.
- While another muted window on the site is still active, the catch-up waits for it.
- When overlapping muted windows cover an incident, the catch-up belongs to the window with catch-up on, then the window ending first.
- A site that leaves a shared window early gets its catch-up when it leaves. The other sites wait for the window's end.
- The catch-up counts as the opening alert. Later reminders and the recovery alert follow from it.

Catch-up alerts need the notification worker. Sent means the mail server or chat service accepted the message, not that someone read it.

With **Alert if still failing at the end** off, a still-open Down incident sends its first alert at the window end or its alert-sensitivity due time, whichever is later. The first reminder follows one hour after that alert, the second four hours later, then every 24 hours. See [notifications](./notifications.md) for channels, alert sensitivity and reminders.

## End, skip or delete a window

Owners and Admins use the row actions in the list:

| Action              | Applies to                       | Effect                                                                                                                                         |
| ------------------- | -------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| **Edit**            | A window that has not ended      | A one-time window changes itself. A recurring window changes its future occurrences; active and past ones stay. The repeat type cannot change. |
| **End window**      | An active window                 | Its sites leave maintenance at once. Other windows keep running.                                                                               |
| **Skip occurrence** | A planned occurrence of a series | Deletes only this occurrence. The series keeps its other dates.                                                                                |
| **Delete window**   | A planned one-time window        | Deletes the window. This cannot be undone.                                                                                                     |
| **Delete series**   | A series                         | Deletes future occurrences. A running occurrence keeps going until it ends.                                                                    |

Every change is audited.

## Who sees which window

- Owners and Admins see and change every window.
- Members see every window without write controls.
- Members limited to assigned clients see only the site and client windows of those clients. They never see tag windows, because sites outside their clients may join the tag later.
- For a site in an active tag window, such a Member's site header shows only that maintenance is active and whether alerts are muted.

## Recurring windows

- **Daily** repeats every N days, **Weekly** every N weeks on the chosen **Weekdays**, **Monthly** every N months. N is 1 to 52, counted from the start date.
- **Monthly** repeats on a **Day of month** or on an **Nth weekday**, for example the second Tuesday.
- One occurrence lasts at most 24 hours.
- An ambiguous wall time during a DST change uses the first occurrence. A wall time skipped in spring moves forward.
- Imported schedules are muted and use catch-up.

## Live updates

The list and site headers update when a window starts or ends. They are sent every minute, best-effort; a page reload always shows the current state. Changes from window boundaries or another tab wait behind **N new changes**. Your own edits appear at once. See [interface behavior](./ui.md) for buffering limits.

## API

The [API](./api.md#maintenance-periods) schedules, ends and lists site windows with the `maintenance:read` and `maintenance:write` abilities. Client and tag windows are managed in the UI.
