<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The order list / search filters, shared by every screen that lists orders:
 * the admin Orders page, CSV export, invoice data, the X-API-KEY /fulfill
 * feed and the partner order list.
 *
 * It lives in a trait rather than in OrderController so the partner app gets
 * the SAME filters — a partner searching for a system_id must behave exactly
 * like an admin doing it, and a second implementation would drift the first
 * time a filter is added on one side only.
 *
 * Callers must apply their own row scoping (seller's own orders, partner's
 * assigned orders) BEFORE calling this; it filters, it does not authorise.
 */
trait FiltersOrderList
{
    /**
     * Shared list filters for GET /orders, /orders/export, /orders/invoice-data,
     * and GET /fulfill. Callers must apply seller/admin user scoping first.
     */
    protected function applyOrderListFilters(Builder $query, Request $request): void
    {
        // Channel scope FIRST — every list (index, export, invoice, API) goes
        // through here, so an FPT order can never leak into a normal listing
        // and vice-versa. No `source` param = 'normal', which is what every
        // existing client sends. Pass source=all to see both.
        $query->ofSource($request->input('source'));

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('ship_type')) {
            $query->where('ship_type', $request->ship_type);
        }

        if ($request->filled('ref_id')) {
            $query->where('ref_id', 'like', '%' . $request->ref_id . '%');
        }

        if ($request->filled('ref_ids')) {
            $list = preg_split('/[\s,]+/', (string) $request->ref_ids, -1, PREG_SPLIT_NO_EMPTY);
            $list = array_values(array_unique(array_map('trim', $list)));
            if (!empty($list)) {
                $query->whereIn('ref_id', $list);
            }
        }

        if ($request->has('system_id')) {
            $query->where('system_id', 'like', '%' . $request->system_id . '%');
        }

        // Paste-a-list lookup, same shape as ref_ids above: split on whitespace
        // or commas, exact match (not LIKE) so one pasted id can never drag in
        // a longer one that merely contains it.
        if ($request->filled('system_ids')) {
            $list = preg_split('/[\s,]+/', (string) $request->system_ids, -1, PREG_SPLIT_NO_EMPTY);
            $list = array_values(array_unique(array_map('trim', $list)));
            if (!empty($list)) {
                $query->whereIn('system_id', $list);
            }
        }

        if ($request->has('tracking_id')) {
            $query->where('tracking_id', 'like', '%' . $request->tracking_id . '%');
        }

        if ($request->filled('tracking_status')) {
            $ts = $request->tracking_status;
            if ($ts === 'none') {
                $query->whereDoesntHave('tracking');
            } else {
                $query->whereHas('tracking', fn ($q) => $q->where('status', $ts));
            }
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('paid')) {
            if ($request->paid === 'paid') {
                $query->whereColumn('paid_cost', '>=', 'total_cost');
            } elseif ($request->paid === 'unpaid') {
                $query->whereColumn('paid_cost', '<', 'total_cost');
            }
        }

        if ($request->filled('order_ids')) {
            $ids = is_array($request->order_ids)
                ? $request->order_ids
                : preg_split('/[\s,]+/', (string) $request->order_ids, -1, PREG_SPLIT_NO_EMPTY);
            $query->whereIn('id', array_map('intval', $ids));
        }

        $this->applyProductFilters($query, $request);
    }

    /**
     * Product-related filters — order matches if at least one order_item satisfies
     * the condition (OR across items, AND when multiple product params are set).
     */
    protected function applyProductFilters(Builder $query, Request $request): void
    {
        $productIds = $this->parseIdList($request, 'product_ids');
        if ($request->filled('product_id')) {
            $productIds[] = (int) $request->product_id;
        }
        $productIds = array_values(array_unique(array_filter($productIds)));
        if (!empty($productIds)) {
            $query->whereHas('items.productVariant', fn ($q) => $q->whereIn('product_id', $productIds));
        }

        if ($request->filled('product_variant_id')) {
            $query->whereHas('items', fn ($q) =>
                $q->where('product_variant_id', (int) $request->product_variant_id));
        }

        if ($request->filled('line_id')) {
            $query->whereHas('items.productVariant.product', fn ($q) =>
                $q->where('line_id', $request->line_id));
        }

        if ($request->filled('sku')) {
            $query->whereHas('items.productVariant', fn ($q) =>
                $q->where('sku', $request->sku));
        }

        foreach (['color', 'size', 'paper_type'] as $field) {
            if ($request->filled($field)) {
                $value = $request->input($field);
                $query->whereHas('items.productVariant', fn ($q) => $q->where($field, $value));
            }
        }

        if ($request->filled('material_id')) {
            $query->whereHas('items', fn ($q) =>
                $q->where('material_id', (int) $request->material_id));
        }

        if ($request->filled('accessory_id')) {
            $accessoryId = (int) $request->accessory_id;
            $query->whereHas('items', function ($q) use ($accessoryId) {
                $q->where(function ($w) use ($accessoryId) {
                    $w->whereHas('accessoryPrice', fn ($ap) => $ap->where('accessory_id', $accessoryId))
                      ->orWhereHas('accessoryPrices', fn ($ap) => $ap->where('accessory_id', $accessoryId));
                });
            });
        }

        if ($request->filled('accessory_code')) {
            $code = strtoupper(trim($request->accessory_code));
            $query->whereHas('items', function ($q) use ($code) {
                $q->where(function ($w) use ($code) {
                    $w->whereHas('accessoryPrice', fn ($ap) => $ap->where('accessory_code', $code))
                      ->orWhereHas('accessoryPrices', fn ($ap) => $ap->where('accessory_code', $code));
                });
            });
        }
    }

    /** @return int[] */
    protected function parseIdList(Request $request, string $key): array
    {
        if (!$request->filled($key)) {
            return [];
        }
        $raw = $request->input($key);
        $parts = is_array($raw)
            ? $raw
            : preg_split('/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter(array_map('intval', $parts))));
    }
}
