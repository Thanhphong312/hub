<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cron: for each user with auto_pay=true and wallet > 0, settle oldest-first
 * unpaid orders against the wallet until the wallet runs out. Mirrors the
 * single-pay logic in OrderController::pay() — same DB transaction, same
 * wallet_transactions row, same new_order → processing status bump — so
 * the audit trail looks identical to a manual click.
 *
 * Scheduled every minute (see routes/console.php).
 */
class AutoPayOrders extends Command
{
    protected $signature = 'orders:auto-pay {--user= : only this user_id}';

    protected $description = 'Auto-pay unpaid orders (FIFO) for users with auto_pay=true';

    public function handle(): int
    {
        $query = User::where('auto_pay', true)
            ->where('wallet', '>', 0);
        if ($this->option('user')) {
            $query->where('id', (int) $this->option('user'));
        }
        $users = $query->get();

        if ($users->isEmpty()) {
            $this->info('No auto_pay users with positive wallet.');
            return self::SUCCESS;
        }

        $total = 0;
        foreach ($users as $user) {
            $paid = $this->payForUser($user);
            if ($paid['count'] > 0) {
                $total += $paid['count'];
                $this->info("user={$user->id} ({$user->email}): paid {$paid['count']} order(s) totalling \${$paid['amount']}");
                app(TelegramService::class)->notifyTopup(implode("\n", [
                    '🤖 <b>Auto-pay ran</b>',
                    sprintf('User: %s (#%s)', e($user->name), $user->id),
                    sprintf('Paid: <b>%s order(s)</b> · $%s', $paid['count'], number_format($paid['amount'], 2)),
                    sprintf('Wallet now: $%s', number_format((float) $user->fresh()->wallet, 2)),
                    !empty($paid['system_ids']) ? 'Orders: ' . implode(', ', $paid['system_ids']) : null,
                ]));
            }
        }

        $this->info("Auto-pay done. Total orders settled: {$total}.");
        return self::SUCCESS;
    }

    /**
     * Settle one user's oldest-first unpaid orders. Returns
     * { count, amount, system_ids[] } so the caller can log + notify.
     */
    private function payForUser(User $user): array
    {
        $count = 0;
        $amount = 0.0;
        $systemIds = [];

        // Refetch within a small loop instead of grabbing all rows upfront —
        // if pay() side-effects mutate the user's wallet we want a fresh read.
        while (true) {
            $order = Order::where('user_id', $user->id)
                ->whereColumn('paid_cost', '<', 'total_cost')
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->first();
            if (!$order) break;

            $remaining = round((float) $order->total_cost - (float) $order->paid_cost, 2);
            if ($remaining <= 0) break;

            $user->refresh();
            if (round((float) $user->wallet, 2) < $remaining) {
                // Stop at the first order the wallet can't cover — strict
                // FIFO. Avoids skipping ahead to a cheaper later order.
                break;
            }

            DB::transaction(function () use ($user, $order, $remaining) {
                $balanceBefore = (float) $user->wallet;
                $user->decrement('wallet', $remaining);

                $updates = ['paid_cost' => $order->total_cost];
                if ((int) $order->status === Order::STATUS_NEW_ORDER) {
                    $updates['status'] = Order::STATUS_PROCESSING;
                }
                $order->update($updates);

                WalletTransaction::create([
                    'user_id'         => $user->id,
                    'order_id'        => $order->id,
                    'order_system_id' => $order->system_id,
                    'type'            => 'paid',
                    'status'          => WalletTransaction::STATUS_APPROVED,
                    'amount'          => -$remaining,
                    'balance_before'  => $balanceBefore,
                    'balance_after'   => $user->fresh()->wallet,
                    'note'            => "Auto-paid order #{$order->system_id}",
                ]);
            });

            $count++;
            $amount += $remaining;
            $systemIds[] = $order->system_id;
        }

        return ['count' => $count, 'amount' => round($amount, 2), 'system_ids' => $systemIds];
    }
}
