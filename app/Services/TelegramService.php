<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Telegram Bot HTTP API. Reads bot config from the
 * `telegram` row of `app_settings` so the admin can change tokens / chat
 * ids without redeploying. Methods throw on hard HTTP failure; the
 * `description` field of the Telegram error response is bubbled in the
 * exception message so the admin UI can show what went wrong.
 */
class TelegramService
{
    public const SETTING_KEY = 'telegram';
    private const API_BASE = 'https://api.telegram.org';

    public static function settings(): array
    {
        return AppSetting::getJson(self::SETTING_KEY, [
            'bot_token'          => '',
            'bot_username'       => '',
            'webhook_secret'     => '',
            'default_chat_id'    => '',
            'default_thread_id'  => null,
            'topup_chat_id'      => '',
            'topup_thread_id'    => null,
            'report_days'        => 0,
            'daily_cron_enabled' => false,
        ]);
    }

    public static function saveSettings(array $data): array
    {
        $merged = array_merge(self::settings(), $data);
        AppSetting::setJson(self::SETTING_KEY, $merged);
        return $merged;
    }

    /**
     * Send a plain message. Returns the Telegram message id on success.
     *
     * @param  string|int  $chatId
     * @param  string      $text
     * @param  int|null    $threadId   (forum supergroup thread / topic)
     */
    public function sendMessage($chatId, string $text, ?int $threadId = null): int
    {
        $token = self::settings()['bot_token'] ?? '';
        if (!$token) throw new \RuntimeException('Telegram bot_token is not configured');

        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];
        if ($threadId) $payload['message_thread_id'] = (int) $threadId;

        $res = Http::asJson()->timeout(15)
            ->post(self::API_BASE . "/bot{$token}/sendMessage", $payload);

        $body = $res->json();
        if (!$res->ok() || !($body['ok'] ?? false)) {
            $desc = $body['description'] ?? "HTTP {$res->status()}";
            throw new \RuntimeException("Telegram sendMessage failed: {$desc}");
        }
        return (int) ($body['result']['message_id'] ?? 0);
    }

    /**
     * Best-effort edit of an existing message (used to update a live progress
     * message in place, e.g. the /gettracking queue). Never throws — Telegram
     * returns an error when the text is unchanged ("message is not modified"),
     * which is harmless and simply logged.
     *
     * @param string|int $chatId
     */
    public function editMessageText($chatId, int $messageId, string $text, ?int $threadId = null): void
    {
        $token = self::settings()['bot_token'] ?? '';
        if (!$token || !$messageId) return;

        $payload = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];
        if ($threadId) $payload['message_thread_id'] = (int) $threadId;

        try {
            Http::asJson()->timeout(15)
                ->post(self::API_BASE . "/bot{$token}/editMessageText", $payload);
        } catch (\Throwable $e) {
            \Log::warning('[TelegramService] editMessageText failed: ' . $e->getMessage());
        }
    }

    /**
     * Best-effort delete of a message (used to scrub the plaintext password from
     * a user's `/login` message after linking). Never throws.
     *
     * @param string|int $chatId
     */
    public function deleteMessage($chatId, int $messageId): void
    {
        $token = self::settings()['bot_token'] ?? '';
        if (!$token || !$messageId) return;
        try {
            Http::asJson()->timeout(10)
                ->post(self::API_BASE . "/bot{$token}/deleteMessage", [
                    'chat_id'    => $chatId,
                    'message_id' => $messageId,
                ]);
        } catch (\Throwable $e) {
            \Log::warning('[TelegramService] deleteMessage failed: ' . $e->getMessage());
        }
    }

    /**
     * Register the bot webhook with Telegram. Returns the Telegram response body.
     * `secret_token` is echoed back by Telegram in the
     * X-Telegram-Bot-Api-Secret-Token header on every webhook call.
     */
    public function setWebhook(string $url, ?string $secret = null): array
    {
        $token = self::settings()['bot_token'] ?? '';
        if (!$token) throw new \RuntimeException('Telegram bot_token is not configured');

        $payload = ['url' => $url, 'drop_pending_updates' => true];
        if ($secret) $payload['secret_token'] = $secret;

        $res  = Http::asJson()->timeout(15)
            ->post(self::API_BASE . "/bot{$token}/setWebhook", $payload);
        $body = $res->json() ?? [];
        if (!$res->ok() || !($body['ok'] ?? false)) {
            $desc = $body['description'] ?? "HTTP {$res->status()}";
            throw new \RuntimeException("Telegram setWebhook failed: {$desc}");
        }
        return $body;
    }

    /**
     * Notify about a newly created order. Sends to the order OWNER's linked
     * Telegram chat (if linked) AND to the admin default channel. Best-effort:
     * never throws so it can't break order creation.
     */
    public function notifyNewOrder(Order $order, \App\Models\User $owner): void
    {
        $cfg = self::settings();
        if (empty($cfg['bot_token'])) return;

        $text = implode("\n", array_filter([
            '📦 <b>New Order Created</b>',
            sprintf('Order: <b>%s</b>', e($order->system_id)),
            $order->ref_id ? 'Ref: ' . e($order->ref_id) : null,
            'Items: ' . (int) $order->items()->count(),
            sprintf('Total: $%.2f', (float) $order->total_cost),
            'Owner: ' . e($owner->name ?? ('#' . $owner->id)),
        ]));

        // Owner's private chat (only if they linked their account and haven't
        // muted notifications via the /recievemessage command).
        if (!empty($owner->telegram_chat_id) && $owner->telegram_notifications_enabled) {
            try { $this->sendMessage($owner->telegram_chat_id, $text); }
            catch (\Throwable $e) { \Log::warning('[TelegramService] notifyNewOrder owner: ' . $e->getMessage()); }
        }

        // Admin channel copy.
        $adminChat = $cfg['default_chat_id'] ?? null;
        if ($adminChat && (string) $adminChat !== (string) ($owner->telegram_chat_id ?? '')) {
            try { $this->sendMessage($adminChat, $text, $cfg['default_thread_id'] ?? null); }
            catch (\Throwable $e) { \Log::warning('[TelegramService] notifyNewOrder admin: ' . $e->getMessage()); }
        }
    }

    /**
     * Single summary message after a CSV bulk import. Sent to the uploader's
     * linked chat AND the admin channel. Best-effort.
     */
    public function notifyOrdersImported(\App\Models\User $owner, int $count): void
    {
        $cfg = self::settings();
        if (empty($cfg['bot_token']) || $count < 1) return;

        $text = implode("\n", [
            '📦 <b>Orders Imported</b>',
            sprintf('<b>%d</b> new order(s) imported via CSV.', $count),
            'By: ' . e($owner->name ?? ('#' . $owner->id)),
        ]);

        if (!empty($owner->telegram_chat_id) && $owner->telegram_notifications_enabled) {
            try { $this->sendMessage($owner->telegram_chat_id, $text); }
            catch (\Throwable $e) { \Log::warning('[TelegramService] notifyOrdersImported owner: ' . $e->getMessage()); }
        }
        $adminChat = $cfg['default_chat_id'] ?? null;
        if ($adminChat && (string) $adminChat !== (string) ($owner->telegram_chat_id ?? '')) {
            try { $this->sendMessage($adminChat, $text, $cfg['default_thread_id'] ?? null); }
            catch (\Throwable $e) { \Log::warning('[TelegramService] notifyOrdersImported admin: ' . $e->getMessage()); }
        }
    }

    /**
     * Best-effort send to the configured topup chat (falls back to default_chat_id).
     * Swallows config errors + Telegram failures so they never break the wallet
     * deposit / approval HTTP response — failures are logged instead.
     */
    public function notifyTopup(string $text): void
    {
        $cfg      = self::settings();
        $chatId   = $cfg['topup_chat_id']   ?: ($cfg['default_chat_id']   ?? null);
        $threadId = $cfg['topup_thread_id'] ?? ($cfg['default_thread_id'] ?? null);
        if (!$chatId || empty($cfg['bot_token'])) return;

        try {
            $this->sendMessage($chatId, $text, $threadId ? (int) $threadId : null);
        } catch (\Throwable $e) {
            \Log::warning('[TelegramService] notifyTopup failed: ' . $e->getMessage());
        }
    }

    /**
     * Pull recent updates and extract unique chats the bot has seen. Note:
     * Telegram only returns updates the bot hasn't acknowledged yet AND
     * only when no webhook is set. The admin needs to send any message in
     * each group after adding the bot so the chat shows up here.
     */
    public function discoverGroups(): array
    {
        $token = self::settings()['bot_token'] ?? '';
        if (!$token) throw new \RuntimeException('Telegram bot_token is not configured');

        $res = Http::asJson()->timeout(15)
            ->get(self::API_BASE . "/bot{$token}/getUpdates", ['limit' => 100, 'timeout' => 0]);

        $body = $res->json();
        if (!$res->ok() || !($body['ok'] ?? false)) {
            $desc = $body['description'] ?? "HTTP {$res->status()}";
            throw new \RuntimeException("Telegram getUpdates failed: {$desc}");
        }

        $chats = [];
        foreach ($body['result'] ?? [] as $update) {
            // Both regular messages and channel posts carry a `chat` object.
            $msg = $update['message']
                ?? $update['channel_post']
                ?? $update['edited_message']
                ?? null;
            $chat = $msg['chat'] ?? null;
            if (!$chat) continue;
            $id = (string) ($chat['id'] ?? '');
            if ($id === '') continue;

            // Forum supergroups carry message_thread_id when a topic was
            // used — expose it so the admin can pick which topic to post to.
            $threadId = $msg['message_thread_id'] ?? null;
            $threadTitle = $msg['reply_to_message']['forum_topic_created']['name'] ?? null;

            if (!isset($chats[$id])) {
                $chats[$id] = [
                    'id'       => $chat['id'],
                    'type'     => $chat['type']  ?? '',
                    'title'    => $chat['title'] ?? ($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''),
                    'username' => $chat['username'] ?? null,
                    'threads'  => [],
                ];
            }
            if ($threadId && !isset($chats[$id]['threads'][$threadId])) {
                $chats[$id]['threads'][$threadId] = [
                    'id'    => (int) $threadId,
                    'title' => $threadTitle ?: "Topic #{$threadId}",
                ];
            }
        }
        // Convert threads map → list for client consumption.
        foreach ($chats as &$c) $c['threads'] = array_values($c['threads']);
        return array_values($chats);
    }

    /**
     * Build + send the orders report for the last N days. Returns the
     * report text so callers can echo it back into the API response.
     */
    public function sendOrdersReport($chatId, ?int $threadId = null, ?int $days = null): array
    {
        $cfg = self::settings();
        $days = $days ?? (int) ($cfg['report_days'] ?? 0);
        $chatId = $chatId ?: ($cfg['default_chat_id'] ?? null);
        $threadId = $threadId ?? ($cfg['default_thread_id'] ?? null);

        if (!$chatId) throw new \RuntimeException('No chat_id configured');

        [$from, $to] = $this->dateWindow($days);
        $text = $this->buildOrderReportText($from, $to);
        $msgId = $this->sendMessage($chatId, $text, $threadId);
        return [
            'message_id' => $msgId,
            'text'       => $text,
            'from'       => $from->toDateString(),
            'to'         => $to->toDateString(),
        ];
    }

    private function dateWindow(int $days): array
    {
        // days=0 → today only. days=1 → today + yesterday. Etc.
        $to   = Carbon::now()->endOfDay();
        $from = Carbon::now()->subDays(max(0, $days))->startOfDay();
        return [$from, $to];
    }

    private function buildOrderReportText(Carbon $from, Carbon $to): string
    {
        // Only report the open / in-progress statuses (0..5). Shipped (6)
        // and cancelled (7) are excluded — admin asked to keep the daily
        // digest focused on orders that still need work.
        $reportedStatuses = [
            Order::STATUS_NEW_ORDER,
            Order::STATUS_PROCESSING,
            Order::STATUS_WRONGSIZE,
            Order::STATUS_FIXED,
            Order::STATUS_REPRINT,
            Order::STATUS_ONHOLD,
        ];

        $counts = Order::whereBetween('created_at', [$from, $to])
            ->whereIn('status', $reportedStatuses)
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $rangeLabel = $from->isSameDay($to)
            ? $from->format('M j, Y')
            : $from->format('M j') . ' – ' . $to->format('M j, Y');

        $lines = [];
        $lines[] = '<b>📊 BullStart Orders Report</b>';
        $lines[] = "📅 {$rangeLabel}";
        $lines[] = '';
        $lines[] = '<b>By status</b>';
        foreach ($reportedStatuses as $st) {
            $name = Order::STATUS_MAP[$st] ?? "status {$st}";
            $count = (int) ($counts[$st] ?? 0);
            $lines[] = "  • {$name}: {$count}";
        }

        // ── Per-user backlog (current state, NOT scoped to the date window).
        // For each user with open new_order or onhold orders we show their
        // counts. Identified by user_id + user name.
        $perUser = DB::table('orders as o')
            ->join('users as u', 'u.id', '=', 'o.user_id')
            ->whereIn('o.status', [Order::STATUS_NEW_ORDER, Order::STATUS_ONHOLD])
            ->groupBy('u.id', 'u.name')
            ->selectRaw('
                u.id AS user_id,
                u.name AS user_name,
                SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) AS new_count,
                SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) AS onhold_count
            ', [Order::STATUS_NEW_ORDER, Order::STATUS_ONHOLD])
            ->orderByDesc('new_count')
            ->orderByDesc('onhold_count')
            ->orderBy('u.name')
            ->get();

        if ($perUser->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>By user (open new + onhold — current)</b>';
            foreach ($perUser as $row) {
                $newCount    = (int) $row->new_count;
                $onholdCount = (int) $row->onhold_count;
                if ($newCount === 0 && $onholdCount === 0) continue; // safety
                $lines[] = "  • #{$row->user_id} {$row->user_name} · 🆕 {$newCount} · ⏸ {$onholdCount}";
            }
        }

        return implode("\n", $lines);
    }
}
