<?php

namespace App\Services;

use App\Models\Gangsheet;
use App\Models\GangsheetLabel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemMeta;
use Illuminate\Support\Facades\DB;

/**
 * Shared "record a gangsheet" logic, used by both the manual Compose/Find flow
 * (GangsheetController::store) and the automation finalize flow
 * (GangsheetGroupController::finalize). Keeps the three-step side effect —
 * create gangsheet row, flip production=true on orders+metas, mirror a
 * GangsheetLabel — in ONE place so the two entry points can never drift.
 */
class GangsheetService
{
    /**
     * @param array $data filename, file_url, line_id, first_system_id,
     *                     last_system_id, orders_count, metas_count,
     *                     order_ids[], meta_ids[]
     */
    public function record(array $data, ?int $userId): Gangsheet
    {
        $orderIds = array_values(array_unique(array_map('intval', $data['order_ids'] ?? [])));
        $metaIds  = array_values(array_unique(array_map('intval', $data['meta_ids'] ?? [])));

        return DB::transaction(function () use ($data, $orderIds, $metaIds, $userId) {
            $gs = Gangsheet::create([
                'created_by_user_id' => $userId,
                'filename'           => $data['filename'],
                'file_url'           => $data['file_url'],
                'line_id'            => $data['line_id'] ?? null,
                'source'             => $data['source'] ?? \App\Models\Order::SOURCE_NORMAL,
                'page_format'        => $data['page_format'] ?? null,
                'first_system_id'    => $data['first_system_id'] ?? null,
                'last_system_id'     => $data['last_system_id'] ?? null,
                'orders_count'       => (int) ($data['orders_count'] ?? count($orderIds)),
                'metas_count'        => (int) ($data['metas_count'] ?? count($metaIds)),
                'order_ids'          => $orderIds,
                'meta_ids'           => $metaIds,
            ]);

            if (!empty($metaIds)) {
                OrderItemMeta::whereIn('id', $metaIds)->update(['production' => true]);
            }
            if (!empty($orderIds)) {
                Order::whereIn('id', $orderIds)->update(['production' => true]);
            }

            // Parallel gangsheet_labels row mirrors the orders so the
            // scan-to-complete-all workflow operates on the same set.
            $labelName = preg_replace('/\.pdf$/i', '', $gs->filename) . '_label';
            $totalItemsQty = empty($orderIds)
                ? 0
                : (int) OrderItem::whereIn('order_id', $orderIds)->sum('quantity');
            GangsheetLabel::create([
                'gangsheet_id'       => $gs->id,
                'created_by_user_id' => $userId,
                'name'               => $labelName,
                'line_id'            => $gs->line_id,
                'order_ids'          => $orderIds,
                'orders_count'       => count($orderIds),
                'total_items_qty'    => $totalItemsQty,
                'status'             => 'pending',
            ]);

            return $gs;
        });
    }
}
