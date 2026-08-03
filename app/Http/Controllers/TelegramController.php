<?php

namespace App\Http\Controllers;

use App\Jobs\FetchTrackingForChat;
use App\Models\Order;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class TelegramController extends Controller
{
    // Brute-force policy for the /login command (per chat_id).
    private const MAX_LOGIN_FAILS = 5;
    private const LOCK_MINUTES = 30;

    public function __construct(private TelegramService $telegram) {}

    public function getSettings()
    {
        return response()->json(TelegramService::settings());
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'bot_token'          => 'nullable|string|max:200',
            'bot_username'       => 'nullable|string|max:64',
            'webhook_secret'     => 'nullable|string|max:200',
            'default_chat_id'    => 'nullable|string|max:120',
            'default_thread_id'  => 'nullable|integer',
            'topup_chat_id'      => 'nullable|string|max:120',
            'topup_thread_id'    => 'nullable|integer',
            'design_check_chat_id'   => 'nullable|string|max:64',
            'design_check_thread_id' => 'nullable|integer',
            'report_days'        => 'nullable|integer|min:0|max:365',
            'daily_cron_enabled' => 'nullable|boolean',
        ]);
        $saved = TelegramService::saveSettings($data);
        return response()->json($saved);
    }

    /**
     * Admin: register the webhook with Telegram. URL is derived from APP_URL so
     * Telegram posts updates to POST /api/telegram/webhook with the secret token.
     */
    public function setWebhook()
    {
        $cfg = TelegramService::settings();
        $url = rtrim(config('app.url'), '/') . '/api/telegram/webhook';
        try {
            $body = $this->telegram->setWebhook($url, $cfg['webhook_secret'] ?: null);
            return response()->json(['ok' => true, 'url' => $url, 'result' => $body]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Authenticated user: unlink their Telegram account (stop notifications).
     */
    public function unlink(Request $request)
    {
        $request->user()->forceFill([
            'telegram_chat_id'   => null,
            'telegram_linked_at' => null,
        ])->save();
        return response()->json(['message' => 'Telegram unlinked']);
    }

    /**
     * Public webhook hit by Telegram. Handles the `/login <email> <password>`
     * command to link a user's account to their Telegram chat. Always returns
     * 200 for processed updates so Telegram does not retry; logical results are
     * communicated back to the user via sendMessage.
     */
    public function handleWebhook(Request $request)
    {
        // 1. Verify the shared secret Telegram echoes on every call.
        $secret = TelegramService::settings()['webhook_secret'] ?? '';
        if ($secret) {
            $got = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
            if (!hash_equals($secret, $got)) {
                return response()->json(['ok' => false], 403);
            }
        }

        $message  = $request->input('message') ?? $request->input('edited_message');
        $chatId   = $message['chat']['id'] ?? null;
        $text     = trim((string) ($message['text'] ?? ''));
        $msgId    = $message['message_id'] ?? 0;
        $threadId = $message['message_thread_id'] ?? null;
        if (!$chatId) return response()->json(['ok' => true]);

        // Batch fetch-tracking (admin/support) — dispatches a background job.
        if (preg_match('/^\/gettracking(@\w+)?\s*$/i', $text)) {
            return $this->handleGetTracking($chatId, $threadId !== null ? (int) $threadId : null);
        }

        // Unlink the account currently linked to this chat.
        if (preg_match('/^\/logout(@\w+)?\s*$/i', $text)) {
            return $this->handleLogout($chatId);
        }

        // Toggle order notifications on/off for this chat (without unlinking).
        if (preg_match('/^\/recievemessage(@\w+)?\s*$/i', $text)) {
            return $this->handleToggleNotifications($chatId);
        }

        // Only the /login command is otherwise actionable; anything else gets guidance.
        if (!preg_match('/^\/login(@\w+)?\s+/i', $text)) {
            if (preg_match('/^\/login(@\w+)?\b/i', $text)) {
                // Known command, missing args → usage hint (not "unknown").
                $this->reply($chatId, "⚠️ Usage: <code>/login your@email.com yourPassword</code>");
            } elseif (str_starts_with($text, '/')) {
                // Looks like a command but isn't recognized.
                $this->reply($chatId, "❌ Unknown command.\n\n<b>Available commands</b>\n" . $this->commandList());
            } else {
                // Plain text / greeting.
                $this->reply($chatId, "👋 To link your account, send:\n" . $this->commandList());
            }
            return response()->json(['ok' => true]);
        }

        // 2. Rate limit by chat_id.
        $lockKey = "tg:login:lock:{$chatId}";
        if (Cache::get($lockKey)) {
            $this->reply($chatId, "⛔ Too many failed attempts. Try again in " . self::LOCK_MINUTES . " minutes.");
            return response()->json(['ok' => true]);
        }

        // 3. Parse credentials (login by EMAIL).
        $parts = preg_split('/\s+/', $text, 3);
        $email = $parts[1] ?? null;
        $password = $parts[2] ?? null;
        if (!$email || !$password) {
            $this->reply($chatId, "⚠️ Usage: <code>/login your@email.com yourPassword</code>");
            return response()->json(['ok' => true]);
        }

        // 4. Verify credentials — same rules as web login (email + hash + active).
        $user = User::where('email', $email)->first();
        if (!$user || !Hash::check($password, $user->password)) {
            $this->registerFail($chatId);
            $this->reply($chatId, "❌ Incorrect email or password.");
            // Scrub the message that contained the password.
            $this->telegram->deleteMessage($chatId, (int) $msgId);
            return response()->json(['ok' => true]);
        }
        if ((int) $user->status !== 1) {
            $this->reply($chatId, "❌ This account is inactive.");
            $this->telegram->deleteMessage($chatId, (int) $msgId);
            return response()->json(['ok' => true]);
        }

        // 5. Link + reset rate limiter + scrub password message.
        $user->forceFill([
            'telegram_chat_id'               => $chatId,
            'telegram_linked_at'             => now(),
            'telegram_notifications_enabled' => true, // fresh link defaults to receiving
        ])->save();
        Cache::forget("tg:login:fails:{$chatId}");
        Cache::forget($lockKey);
        $this->telegram->deleteMessage($chatId, (int) $msgId);
        $this->reply($chatId, "✅ Linked! You'll receive order notifications here.");

        return response()->json(['ok' => true]);
    }

    /**
     * Handle `/logout`: unlink the account currently bound to this chat so it
     * stops receiving notifications. Same effect as the app's unlink endpoint.
     */
    private function handleLogout($chatId)
    {
        $user = User::where('telegram_chat_id', (int) $chatId)->first();
        if (!$user) {
            $this->reply($chatId, "ℹ️ This chat isn't linked to any account.");
            return response()->json(['ok' => true]);
        }

        $user->forceFill([
            'telegram_chat_id'   => null,
            'telegram_linked_at' => null,
        ])->save();

        $this->reply($chatId, "✅ Logged out. You won't receive notifications here anymore.\nSend <code>/login your@email.com yourPassword</code> to link again.");
        return response()->json(['ok' => true]);
    }

    /**
     * Handle `/recievemessage`: toggle order notifications on/off for this chat
     * without unlinking, so the user can mute/unmute without re-logging in.
     */
    private function handleToggleNotifications($chatId)
    {
        $user = User::where('telegram_chat_id', (int) $chatId)->first();
        if (!$user) {
            $this->reply($chatId, "❌ Account not linked. Send <code>/login your@email.com yourPassword</code> first.");
            return response()->json(['ok' => true]);
        }

        $enabled = !$user->telegram_notifications_enabled;
        $user->forceFill(['telegram_notifications_enabled' => $enabled])->save();

        $this->reply(
            $chatId,
            $enabled
                ? "🔔 Notifications <b>ON</b> — you'll receive order updates here."
                : "🔕 Notifications <b>OFF</b> — you won't receive order updates until you send /recievemessage again.",
        );
        return response()->json(['ok' => true]);
    }

    /**
     * Handle `/gettracking`: validate the sender (linked admin/support), then
     * dispatch a background job that mirrors the app's Fetch Tracking queue and
     * reports progress by editing a single message in place. Returns fast so the
     * webhook never blocks on the (potentially long) batch.
     */
    private function handleGetTracking($chatId, ?int $threadId)
    {
        $user = User::where('telegram_chat_id', (int) $chatId)->first();
        if (!$user) {
            $this->reply($chatId, "❌ Account not linked. Send <code>/login your@email.com yourPassword</code> first.");
            return response()->json(['ok' => true]);
        }
        if (!in_array($user->role?->slug, ['admin', 'support'])) {
            $this->reply($chatId, "❌ Access denied. Only admin/support can use /gettracking.");
            return response()->json(['ok' => true]);
        }

        // One run per chat at a time. Lock is released in the job's finally/failed.
        $lockKey = "tg:gettracking:running:{$chatId}";
        if (!Cache::add($lockKey, true, now()->addMinutes(40))) {
            $this->reply($chatId, "⏳ A fetch is already running. Please wait for it to finish.");
            return response()->json(['ok' => true]);
        }

        $pending = Order::pendingTracking()->count();
        if ($pending === 0) {
            Cache::forget($lockKey);
            $this->reply($chatId, "✅ No orders pending tracking.");
            return response()->json(['ok' => true]);
        }

        // Initial progress message — the job edits this in place.
        try {
            $messageId = $this->telegram->sendMessage(
                $chatId,
                "📦 <b>Fetch Tracking — Queue</b>\nTotal: {$pending} · ✅ 0 · ❌ 0\nStatus: Starting…",
                $threadId,
            );
        } catch (\Throwable $e) {
            Cache::forget($lockKey);
            \Log::warning('[TelegramController] gettracking start message failed: ' . $e->getMessage());
            return response()->json(['ok' => true]);
        }

        FetchTrackingForChat::dispatch($chatId, $messageId, $threadId, 500);

        return response()->json(['ok' => true]);
    }

    /** Best-effort reply that never throws inside the webhook. */
    private function reply($chatId, string $text): void
    {
        try { $this->telegram->sendMessage($chatId, $text); }
        catch (\Throwable $e) { \Log::warning('[TelegramController] reply failed: ' . $e->getMessage()); }
    }

    /** The list of bot commands, shared by the welcome and unknown-command replies. */
    private function commandList(): string
    {
        return implode("\n", [
            "<code>/login your@email.com yourPassword</code> — link this chat",
            "<code>/recievemessage</code> — turn notifications on/off",
            "<code>/logout</code> — unlink this chat",
            "<code>/gettracking</code> — fetch tracking (admin/support)",
        ]);
    }

    /** Count a failed /login attempt and lock the chat after the threshold. */
    private function registerFail($chatId): void
    {
        $key = "tg:login:fails:{$chatId}";
        $fails = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $fails, now()->addMinutes(self::LOCK_MINUTES));
        if ($fails >= self::MAX_LOGIN_FAILS) {
            Cache::put("tg:login:lock:{$chatId}", true, now()->addMinutes(self::LOCK_MINUTES));
        }
    }

    public function discoverGroups()
    {
        try {
            return response()->json(['groups' => $this->telegram->discoverGroups()]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function test(Request $request)
    {
        $data = $request->validate([
            'chat_id'   => 'nullable|string|max:120',
            'thread_id' => 'nullable|integer',
        ]);
        $cfg = TelegramService::settings();
        $chatId   = $data['chat_id']   ?? ($cfg['default_chat_id']   ?? null);
        $threadId = $data['thread_id'] ?? ($cfg['default_thread_id'] ?? null);
        if (!$chatId) return response()->json(['message' => 'No chat_id configured'], 422);

        try {
            $text = "✅ <b>BullStart Telegram test</b>\nNếu thấy tin này thì cấu hình OK.\n" . now()->format('Y-m-d H:i');
            $messageId = $this->telegram->sendMessage($chatId, $text, $threadId);
            return response()->json(['ok' => true, 'message_id' => $messageId]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function report(Request $request)
    {
        $data = $request->validate([
            'chat_id'   => 'nullable|string|max:120',
            'thread_id' => 'nullable|integer',
            'days'      => 'nullable|integer|min:0|max:365',
        ]);
        $cfg = TelegramService::settings();
        try {
            $result = $this->telegram->sendOrdersReport(
                $data['chat_id']   ?? ($cfg['default_chat_id']   ?? null),
                $data['thread_id'] ?? ($cfg['default_thread_id'] ?? null),
                $data['days']      ?? null,
            );
            return response()->json(['ok' => true, ...$result]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
