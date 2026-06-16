<?php

namespace App\Http\Controllers;

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

        $message = $request->input('message') ?? $request->input('edited_message');
        $chatId  = $message['chat']['id'] ?? null;
        $text    = trim((string) ($message['text'] ?? ''));
        $msgId   = $message['message_id'] ?? 0;
        if (!$chatId) return response()->json(['ok' => true]);

        // Only the /login command is actionable; anything else gets guidance.
        if (!preg_match('/^\/login(@\w+)?\s+/i', $text)) {
            $this->reply($chatId, "👋 To link your account, send:\n<code>/login your@email.com yourPassword</code>");
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
            'telegram_chat_id'   => $chatId,
            'telegram_linked_at' => now(),
        ])->save();
        Cache::forget("tg:login:fails:{$chatId}");
        Cache::forget($lockKey);
        $this->telegram->deleteMessage($chatId, (int) $msgId);
        $this->reply($chatId, "✅ Linked! You'll receive order notifications here.");

        return response()->json(['ok' => true]);
    }

    /** Best-effort reply that never throws inside the webhook. */
    private function reply($chatId, string $text): void
    {
        try { $this->telegram->sendMessage($chatId, $text); }
        catch (\Throwable $e) { \Log::warning('[TelegramController] reply failed: ' . $e->getMessage()); }
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
