# Telegram Bot — Connection & Commands

How the BullStart Telegram bot links user accounts, sends notifications, and what
each chat command does.

---

## 1. Overview

The bot has two directions:

- **Inbound** (Telegram → server): user sends a command (`/login`, `/gettracking`, …).
  Telegram forwards it to the webhook `POST /api/telegram/webhook`. **Requires the public
  webhook URL (ngrok/APP_URL) to be reachable.**
- **Outbound** (server → Telegram): the app sends notifications (new order, topup, reports,
  progress messages). These go directly to `api.telegram.org` and **do not need ngrok** —
  only the backend running with internet access.

Bot config is stored in the `telegram` row of the `app_settings` table (managed from the
desktop app → **Settings → Telegram**), not in `.env`.

| Setting | Required? | Purpose |
|---|---|---|
| `bot_token` | ✅ Yes | All Telegram API calls. Nothing works without it. |
| `webhook_secret` | ⚠️ Optional | Authenticates inbound webhook calls (recommended in production). |
| `bot_username` | ⚪ Optional | Builds the `t.me/<username>` "Open bot" link in the app's Profile page. |
| `default_chat_id` | ⚪ Optional | Admin channel that also receives order copies + reports. |
| `topup_chat_id` | ⚪ Optional | Channel for topup notifications. |

---

## 2. Admin setup (one-time)

In the desktop app → **Settings → Telegram**:

1. Enter the **Bot Token** (from [@BotFather](https://t.me/BotFather)). Optionally set
   **Bot Username** and **Webhook Secret**.
2. Click **Save changes**. *(Only this button persists settings — picking a group or sending
   a test does not save.)*
3. Click **Register webhook**. This points Telegram at `{APP_URL}/api/telegram/webhook`.
4. *(Optional)* Use **Refresh groups** → pick a **Default** / **Topup** chat → **Save changes**.

> The webhook URL is derived from `APP_URL`. If the ngrok URL changes, update `APP_URL` and
> click **Register webhook** again. A static/reserved ngrok domain avoids this.

---

## 3. Account linking flow (`/login`)

```
User → /login email password → Telegram → POST /api/telegram/webhook
   → verify webhook_secret → rate-limit check → verify email + password (+ active account)
   → save users.telegram_chat_id + telegram_linked_at
   → delete the password message → reply "✅ Linked!"
```

- Login is by **email + password** (same rules as the website login).
- The message containing the password is **auto-deleted** after processing.
- **Brute-force protection:** 5 failed attempts per chat → 30-minute lock.
- Notifications are sent to each linked user's `telegram_chat_id`. This is independent of
  `default_chat_id` — a user receives their own order notifications once linked.

Users can also link from the app: **Profile → "Open Telegram bot"**, then send `/login`.

---

## 4. Commands

All commands are sent as a normal chat message to the bot.

| Command | Who can use | What it does |
|---|---|---|
| `/login <email> <password>` | Anyone | Links this chat to the account and enables notifications. |
| `/recievemessage` | Any linked user | Toggles order notifications **on/off** for this chat (without unlinking). |
| `/logout` | Any linked user | Unlinks this chat — stops all notifications. Re-link with `/login`. |
| `/gettracking` | **Admin / support** only | Batch-fetches tracking numbers for all pending orders (see §5). |

Anything else (or an unlinked chat) gets a help message listing these commands.

### `/recievemessage` — mute / unmute
- Toggles the `telegram_notifications_enabled` flag on the user.
- Reply: `🔔 Notifications ON` or `🔕 Notifications OFF`.
- The chat stays linked; only order notifications to **this user** are silenced. The admin
  channel (`default_chat_id`) and topup notifications are unaffected.
- Sending `/login` again resets this to **ON**.

### `/logout` — unlink
- Clears `telegram_chat_id` + `telegram_linked_at`.
- Reply: `✅ Logged out…`. If the chat wasn't linked: `ℹ️ This chat isn't linked…`.

**`/recievemessage` vs `/logout`:** mute is reversible by toggling again and keeps the link;
logout fully unlinks and requires `/login` to restore.

---

## 5. `/gettracking` — batch fetch tracking

Server-side equivalent of the app's **Fetch Tracking** button. Restricted to **admin/support**
linked users.

**Flow:**
1. Webhook validates the sender (linked + admin/support role).
2. A per-chat lock prevents two runs at once.
3. Counts pending orders (`tracking_id IS NULL` + has `shipping_label`). If zero → replies
   "✅ No orders pending tracking."
4. Sends a progress message and dispatches a **background queue job**
   (`App\Jobs\FetchTrackingForChat`); the webhook returns immediately.
5. The job processes orders one at a time — POSTing each shipping label to
   `carrier.pressify.us/track`, saving the tracking number — and **edits the progress message
   in place**. Pacing matches the app: 3s between orders, 8s backoff on error, stop after 5
   consecutive carrier failures.

**Messages:**
```
📦 Fetch Tracking — Queue
Total: 240 · ✅ 12 · ❌ 1 · ⏳ 227
Status: Running
Last: BS-1023 → tracking = 1Z999AA1... (UPS)
```
```
✅ Fetch Tracking — Complete
Total: 240 · Updated: 230 · Errors: 10
```

> **Requires a running queue worker** (`QUEUE_CONNECTION=database`). Without it, the
> "Starting…" message appears but no orders are processed:
> ```
> php artisan queue:work
> ```

---

## 6. What needs to be running

| To do this | Backend running | ngrok / public URL | Queue worker |
|---|---|---|---|
| Send order/topup notifications to already-linked users | ✅ | ❌ | ❌ |
| `/login`, `/logout`, `/recievemessage` (inbound commands) | ✅ | ✅ | ❌ |
| `/gettracking` | ✅ | ✅ | ✅ |

---

## 7. Quick reference (code)

| Concern | Location |
|---|---|
| Webhook + command dispatch | `app/Http/Controllers/TelegramController.php` → `handleWebhook()` |
| Telegram API wrapper | `app/Services/TelegramService.php` (`sendMessage`, `editMessageText`) |
| Carrier tracking lookup | `app/Services/CarrierTrackingService.php` |
| Batch tracking job | `app/Jobs/FetchTrackingForChat.php` |
| Pending-tracking query | `App\Models\Order::pendingTracking()` scope |
| Webhook route | `routes/api.php` → `POST /api/telegram/webhook` (throttle 60/min) |
| Linking fields | `users.telegram_chat_id`, `telegram_linked_at`, `telegram_notifications_enabled` |
