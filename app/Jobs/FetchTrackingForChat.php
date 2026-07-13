<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\CarrierTrackingService;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Server-side equivalent of the desktop app's "Fetch Tracking" queue, triggered
 * by the Telegram /gettracking command. Walks every pending-tracking order,
 * resolves its tracking number via CarrierTrackingService, and reports progress
 * by editing a single Telegram message in place (then a final summary).
 *
 * Mirrors the app's pacing: 3s between orders, 8s backoff after an error, and a
 * circuit breaker that bails out after 5 consecutive carrier failures.
 */
class FetchTrackingForChat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;  // a long batch; never retry the whole run
    public int $tries = 1;

    // Pacing — kept in sync with Orders.jsx (BASE_DELAY / ERROR_BACKOFF / CONSECUTIVE_FAIL_LIMIT).
    private const BASE_DELAY = 3;
    private const ERROR_BACKOFF = 8;
    private const CONSECUTIVE_FAIL_LIMIT = 5;

    public function __construct(
        private string|int $chatId,
        private int $progressMessageId,
        private ?int $threadId = null,
        private int $limit = 500,
    ) {}

    public function handle(TelegramService $tg, CarrierTrackingService $carrier): void
    {
        $lockKey = "tg:gettracking:running:{$this->chatId}";

        try {
            $orders = Order::pendingTracking()
                ->orderBy('id', 'desc')
                ->limit($this->limit)
                ->get(['id', 'system_id', 'shipping_label']);

            $total  = $orders->count();
            $done   = 0;
            $errors = 0;
            $consecutiveFails = 0;
            $stopped = false;
            $last = '—';

            foreach ($orders as $i => $order) {
                $hadError = false;
                try {
                    $result = $carrier->fetch($order->shipping_label);
                    $tk = $result['tracking_id'] ?? null;

                    if (!$tk) {
                        // No tracking in response — counts as an error but does
                        // not trip the circuit breaker (carrier was reachable).
                        $errors++;
                        $consecutiveFails = 0;
                        $last = e($order->system_id) . ' → ⚠️ no tracking in response';
                    } else {
                        $order->update(['tracking_id' => $tk]);
                        $done++;
                        $consecutiveFails = 0;
                        $carrierName = $result['carrier'] ? ' (' . e($result['carrier']) . ')' : '';
                        $last = e($order->system_id) . ' → tracking = ' . e($tk) . $carrierName;
                    }
                } catch (\Throwable $ex) {
                    $errors++;
                    $hadError = true;
                    $consecutiveFails++;
                    $last = e($order->system_id) . ' → ❌ ' . e($ex->getMessage());
                }

                $remaining = $total - ($i + 1);
                $tg->editMessageText(
                    $this->chatId,
                    $this->progressMessageId,
                    $this->progressText($total, $done, $errors, $remaining, $last, true),
                    $this->threadId,
                    'seller',
                );

                // Circuit breaker: stop hammering an unreachable carrier.
                if ($consecutiveFails >= self::CONSECUTIVE_FAIL_LIMIT) {
                    $stopped = true;
                    break;
                }

                // Pace requests (skip the wait after the last order).
                if ($remaining > 0) {
                    sleep($hadError ? self::ERROR_BACKOFF : self::BASE_DELAY);
                }
            }

            $tg->editMessageText(
                $this->chatId,
                $this->progressMessageId,
                $this->summaryText($total, $done, $errors, $stopped),
                $this->threadId,
                'seller',
            );
        } finally {
            Cache::forget($lockKey);
        }
    }

    /** If the job blows up entirely, free the lock so the user isn't stuck. */
    public function failed(\Throwable $e): void
    {
        Cache::forget("tg:gettracking:running:{$this->chatId}");
    }

    private function progressText(int $total, int $done, int $errors, int $remaining, string $last, bool $running): string
    {
        return implode("\n", [
            '📦 <b>Fetch Tracking — Queue</b>',
            "Total: {$total} · ✅ {$done} · ❌ {$errors} · ⏳ {$remaining}",
            'Status: ' . ($running ? 'Running' : 'Idle'),
            "Last: {$last}",
        ]);
    }

    private function summaryText(int $total, int $done, int $errors, bool $stopped): string
    {
        $lines = [
            '✅ <b>Fetch Tracking — Complete</b>',
            "Total: {$total} · Updated: {$done} · Errors: {$errors}",
        ];
        if ($stopped) {
            $lines[] = '⛔ Stopped — ' . self::CONSECUTIVE_FAIL_LIMIT . ' consecutive carrier failures. Verify carrier.pressify.us is reachable.';
        }
        return implode("\n", $lines);
    }
}
