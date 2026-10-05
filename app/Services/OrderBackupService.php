<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderBackup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Archives a full JSON snapshot of an order the moment it is created (manual
 * create or CSV import). Two durable copies are written, neither tied to the
 * order's lifetime:
 *
 *   1. Backblaze B2 — object at  order-jsons/<system_id>.json
 *   2. DB table `order_backups` — row with the same JSON in `payload`
 *
 * This exists because deleting an order cascades away its items/metas/address
 * and, historically, left refunds pointing at a now-missing ref_id with no way
 * to recover it. With this archive, an order's ref_id/system_id and full detail
 * survive deletion.
 *
 * Every step is best-effort: a B2 outage or a malformed relation must never
 * break order creation, so the whole thing is wrapped and only logs on failure.
 */
class OrderBackupService
{
    /** Backblaze folder (object key prefix) the JSON lands in. */
    public const FOLDER = 'order-jsons';

    /**
     * Snapshot one order to B2 + the order_backups table. Never throws.
     *
     * @param  string  $source  how the order was created: 'web' | 'import_csv' | ...
     */
    public function archive(Order $order, string $source = 'web'): void
    {
        try {
            $payload = $this->buildPayload($order, $source);
            $json = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $path = self::FOLDER.'/'.$order->system_id.'.json';
            $url = null;
            $uploaded = false;

            try {
                // b2 disk has 'throw' => true, so guard it explicitly.
                Storage::disk('b2')->put($path, $json, 'public');
                $url = Storage::disk('b2')->url($path);
                $uploaded = true;
            } catch (\Throwable $e) {
                Log::warning('[order-backup] B2 upload failed for '.$order->system_id.': '.$e->getMessage());
            }

            // Keyed by system_id (unique) so re-archiving an order overwrites
            // its previous snapshot instead of piling up rows.
            OrderBackup::updateOrCreate(
                ['system_id' => $order->system_id],
                [
                    'order_id' => $order->id,
                    'ref_id'   => $order->ref_id,
                    'user_id'  => $order->user_id,
                    'source'   => $source,
                    'b2_path'  => $path,
                    'b2_url'   => $url,
                    'uploaded' => $uploaded,
                    'payload'  => $payload,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[order-backup] archive failed for order #'.($order->id ?? '?').': '.$e->getMessage());
        }
    }

    /**
     * Archive a whole CSV import in one shot (used by importCsv). To avoid N
     * network round-trips lagging a big import, this does exactly ONE B2 upload
     * — a single combined file under order-jsons/imports/ holding every order's
     * snapshot — while still writing a per-order row to order_backups (cheap
     * local inserts). Each row's full `payload` is retained, and its b2_path
     * points at the combined batch file. Best-effort; never throws.
     *
     * @param  iterable<int>  $orderIds
     */
    public function archiveImportBatch(iterable $orderIds, string $source = 'import_csv'): void
    {
        try {
            // Build each order's payload up front (also keeps the model handy
            // for the per-order DB row).
            $entries = [];
            foreach ($orderIds as $id) {
                $order = Order::find($id);
                if (! $order) {
                    continue;
                }
                $entries[] = ['order' => $order, 'payload' => $this->buildPayload($order, $source)];
            }
            if (empty($entries)) {
                return;
            }

            // One combined file for the whole import.
            $batchPath = self::FOLDER.'/imports/'.now()->format('Y-m-d_His').'_'.Str::random(6).'.json';
            $combined = [
                'backed_up_at' => now()->toIso8601String(),
                'source'       => $source,
                'count'        => count($entries),
                'orders'       => array_map(fn ($e) => $e['payload'], $entries),
            ];
            $json = json_encode(
                $combined,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $url = null;
            $uploaded = false;
            try {
                Storage::disk('b2')->put($batchPath, $json, 'public');
                $url = Storage::disk('b2')->url($batchPath);
                $uploaded = true;
            } catch (\Throwable $e) {
                Log::warning('[order-backup] B2 batch upload failed ('.$batchPath.'): '.$e->getMessage());
            }

            // Per-order rows (cheap local inserts); all point at the batch file.
            foreach ($entries as $e) {
                $order = $e['order'];
                OrderBackup::updateOrCreate(
                    ['system_id' => $order->system_id],
                    [
                        'order_id' => $order->id,
                        'ref_id'   => $order->ref_id,
                        'user_id'  => $order->user_id,
                        'source'   => $source,
                        'b2_path'  => $batchPath,
                        'b2_url'   => $url,
                        'uploaded' => $uploaded,
                        'payload'  => $e['payload'],
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[order-backup] import batch archive failed: '.$e->getMessage());
        }
    }

    /**
     * Build the snapshot array. Uses the full model (incl. loaded relations)
     * plus a few flattened top-level keys for quick scanning of the JSON.
     */
    protected function buildPayload(Order $order, string $source): array
    {
        $order->loadMissing([
            'user:id,name,email',
            'items.metas',
            'items.productVariant',
            'items.material',
            'items.accessoryPrices.accessory',
            'address',
        ]);

        return [
            'backed_up_at'  => now()->toIso8601String(),
            'source'        => $source,
            'order_id'      => $order->id,
            'system_id'     => $order->system_id,
            'ref_id'        => $order->ref_id,
            'user'          => $order->user ? [
                'id'    => $order->user->id,
                'name'  => $order->user->name,
                'email' => $order->user->email,
            ] : null,
            'status'        => $order->status,
            'ship_type'     => $order->ship_type,
            'print_cost'    => $order->print_cost,
            'shipping_cost' => $order->shipping_cost,
            'total_cost'    => $order->total_cost,
            'paid_cost'     => $order->paid_cost,
            'note'          => $order->note,
            'created_at'    => optional($order->created_at)->toIso8601String(),
            // Full snapshot, including items, metas, accessories and address.
            'order'         => $order->toArray(),
        ];
    }
}
