---
title: Channel setup
description: Connect Slack, Discord, Microsoft Teams, Telegram, Pushover or ntfy to Heptapod and check the channel with Send test.
sidebar:
    order: 6.5
---

Add a channel under **Settings → Notifications**, **Settings → My notifications** or **Client → Notifications** with **Add channel**, then open it and press **Send test**. Heptapod uses a token or webhook URL that you create, not an "Add to Slack" sign-in: a self-hosted instance has no fixed sign-in address, and the token stays on your instance.

If a test fails, the channel sheet shows the provider's reason, as listed in the tables below. Failed alerts show it in the incident's **Timeline**.

Email: see [Email setup](./notifications.md#email-setup). Webhook: see [Verify webhook deliveries](./webhook-receivers.md).

### Slack

Heptapod needs a bot token and a channel ID.

1. Open https://api.slack.com/apps and choose **Create New App → Blank app**. Pick a name and your workspace.
2. In **OAuth & Permissions → Bot Token Scopes**, add `chat:write`.
3. Choose **Install to Workspace**, then **Allow**.
4. Copy the **Bot User OAuth Token**. Leave token rotation off; rotated tokens expire after 12 hours.
5. In the Slack channel, type `/invite @YourAppName`.
6. Click the channel name and copy the **Channel ID**.

| Field   | Value                | Format        |
| ------- | -------------------- | ------------- |
| Channel | Channel ID           | `C0123456789` |
| Token   | Bot User OAuth Token | `xoxb-…`      |

| Heptapod shows                 | Fix                                                     |
| ------------------------------ | ------------------------------------------------------- |
| `HTTP 200 — not_in_channel`    | Invite the app to the channel (step 5).                 |
| `HTTP 200 — channel_not_found` | Check the channel ID; private channels need the invite. |
| `HTTP 200 — invalid_auth`      | Copy the token again from **OAuth & Permissions**.      |

### Discord

Heptapod needs a bot token and a channel ID.

1. Open https://discord.com/developers/applications and choose **New Application**.
2. Copy the **Application ID** from **General Information**.
3. In **Bot**, choose **Reset Token** and copy the token.
4. Open `https://discord.com/oauth2/authorize?client_id=APPLICATION_ID&scope=bot&permissions=19456` with your Application ID, pick your server and choose **Authorize**. This grants View Channels, Send Messages and Embed Links. You need **Manage Server** on that server.
5. In Discord, turn on **User Settings → Advanced → Developer Mode**.
6. Right-click the channel and choose **Copy Channel ID**.

| Field   | Value      | Format                          |
| ------- | ---------- | ------------------------------- |
| Channel | Channel ID | `123456789012345678`            |
| Token   | Bot token  | the token only, no `Bot` prefix |

| Heptapod shows                                             | Fix                                                            |
| ---------------------------------------------------------- | -------------------------------------------------------------- |
| `HTTP 403 — RequestException — 50001; Missing Access`      | Invite the bot again (step 4) or let its role see the channel. |
| `HTTP 403 — RequestException — 50013; Missing Permissions` | Allow Send Messages and Embed Links in the channel.            |
| `HTTP 401 — RequestException — 0; 401: Unauthorized`       | Reset the token and paste the new one.                         |

### Microsoft Teams

Heptapod needs a Workflows webhook URL. Office 365 connector URLs do not work.

1. In Teams, open **Workflows** and pick the template **Send webhook alerts to a channel**. The "from specific people" and "from people in an org" variants do not work with Heptapod.
2. Choose the team and channel, then **Save**.
3. Copy the webhook URL from the workflow's details page.

| Field | Value       | Format      |
| ----- | ----------- | ----------- |
| URL   | Webhook URL | `https://…` |

| Heptapod shows                      | Fix                                                                                                              |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `HTTP 429 — RequestException`       | Teams is throttling requests. Wait a minute and send the test again.                                             |
| Other `HTTP 4xx — RequestException` | Check the URL. In Workflows, turn the workflow on and repair its connection; if it was deleted, create it again. |
| Test sent, but nothing in Teams     | Open the workflow's run history in Workflows.                                                                    |

### Telegram

Heptapod needs a bot token and a chat ID.

1. In Telegram, send `/newbot` to @BotFather and follow the prompts. Copy the token it replies with.
2. Add the bot to your group. For a channel, make it an admin that can post messages.
3. In the group, send `/start@your_bot_name`. In a channel, publish any post.
4. Open `https://api.telegram.org/bot<token>/getUpdates` in a browser (the URL contains your token; keep it private). Find the last entry whose `"title"` is your group or channel and copy its `"chat":{"id":…}` number.

| Field   | Value     | Format                                                   |
| ------- | --------- | -------------------------------------------------------- |
| Chat ID | Chat ID   | groups are negative, e.g. `-1001234567890`; keep the `-` |
| Token   | Bot token | `123456789:AA…`                                          |

| Heptapod shows                                              | Fix                                                                   |
| ----------------------------------------------------------- | --------------------------------------------------------------------- |
| `HTTP 400 — RequestException — Bad Request: chat not found` | Check the chat ID and that the bot is in the group.                   |
| `HTTP 400 — … group chat was upgraded to a supergroup chat` | Repeat steps 3–4; the last entry has the new ID starting with `-100`. |
| `HTTP 401 — RequestException — Unauthorized`                | Send `/token` to @BotFather and paste the new token.                  |

### Pushover

Heptapod needs your user key and an application token.

1. Sign in at https://pushover.net and copy your user key from the dashboard.
2. Choose **Create an Application/API Token**, name it and copy its token.
3. Install the Pushover app on your device and sign in.

| Field    | Value                 | Format        |
| -------- | --------------------- | ------------- |
| User key | User or group key     | 30 characters |
| Token    | Application API token | 30 characters |

| Heptapod shows                                             | Fix                               |
| ---------------------------------------------------------- | --------------------------------- |
| `HTTP 400 — RequestException — user identifier is invalid` | Copy the user key again.          |
| `HTTP 400 — RequestException` and a token error            | Copy the application token again. |

### ntfy

Heptapod needs a server URL and a topic, plus an access token for protected topics.

1. Choose a server, for example `https://ntfy.sh`.
2. Choose a hard-to-guess topic; anyone who knows it can read it.
3. Subscribe to the topic in the ntfy app.
4. For a protected topic, create an access token under **Account** in the ntfy web app.

| Field  | Value                   | Format                               |
| ------ | ----------------------- | ------------------------------------ |
| Server | Server base URL         | `https://ntfy.sh`, without the topic |
| Topic  | Topic name              | letters, digits, `_`, `-`; up to 64  |
| Token  | Access token (optional) | `tk_…`                               |

| Heptapod shows                                | Fix                                                                                                      |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `HTTP 403 — RequestException — forbidden`     | Add a token that may write to the topic.                                                                 |
| `HTTP 401 — RequestException — unauthorized`  | Create a new token and paste it.                                                                         |
| "Not sent: … private, loopback, link-local …" | The server is on a private network; the instance Owner allows it with `HEPTAPOD_DELIVERY_PRIVATE_HOSTS`. |
