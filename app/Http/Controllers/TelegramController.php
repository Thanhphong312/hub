<?php

namespace App\Http\Controllers;

use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function __construct(private TelegramService $telegram) {}

    public function getSettings()
    {
        return response()->json(TelegramService::settings());
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'bot_token'          => 'nullable|string|max:200',
            'default_chat_id'    => 'nullable|string|max:120',
            'default_thread_id'  => 'nullable|integer',
            'report_days'        => 'nullable|integer|min:0|max:365',
            'daily_cron_enabled' => 'nullable|boolean',
        ]);
        $saved = TelegramService::saveSettings($data);
        return response()->json($saved);
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
