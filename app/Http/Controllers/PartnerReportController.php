<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PartnerAccessoryPrice;
use App\Models\PartnerVariantPrice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What each partner shipped, and what that owes them.
 *
 * Days are bucketed in America/Chicago like the main dashboard — window edges
 * are computed there and converted to UTC, so the comparison against the
 * UTC-stored completed_time stays right across a DST change.
 */
class PartnerReportController extends Controller
{
    private const TZ = 'America/Chicago';

    /** Default span of the daily strip on each card. */
    private const DAILY_DAYS = 14;

    /** Item meta keys that count as a printed face, per OrderController. */
    private const DESIGN_KEYS = ['front', 'back', 'left', 'right', 'neck', 'special'];

    /**
     * One card per partner: how much they have shipped, and what is owed.
     *
     * "Shipped" is status = shipped with a completed_time — an order missing
     * the timestamp cannot be placed on a day, and silently dropping it from
     * the daily strip while counting it in the total would make the two
     * disagree, so it is reported separately as `undated`.
     */
    public function dashboard(Request $request)
    {
        $tz = self::TZ;
        $days = min(60, max(1, (int) $request->get('days', self::DAILY_DAYS)));

        // Window: local midnight `days-1` ago through end of today, in UTC.
        $endLocal = now()->setTimezone($tz)->endOfDay();
        $startLocal = $endLocal->copy()->startOfDay()->subDays($days - 1);
        [$startUtc, $endUtc] = [$startLocal->copy()->utc(), $endLocal->copy()->utc()];

        $partners = User::whereHas('role', fn ($q) => $q->where('slug', 'partner'))
            ->with('partnerPriceList:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'partner_price_list_id']);

        $todayStartUtc = now()->setTimezone($tz)->startOfDay()->utc();

        return response()->json([
            'timezone' => $tz,
            'days' => $days,
            'from' => $startLocal->toDateString(),
            'to' => $endLocal->toDateString(),
            'partners' => $partners->map(function ($p) use ($startUtc, $endUtc, $todayStartUtc, $tz) {
                $shipped = Order::where('partner_id', $p->id)
                    ->where('status', Order::STATUS_SHIPPED);

                $inWindow = (clone $shipped)
                    ->whereNotNull('completed_time')
                    ->whereBetween('completed_time', [$startUtc, $endUtc])
                    ->get(['id', 'completed_time', 'partner_revenue', 'partner_locked_at']);

                $daily = $inWindow
                    ->groupBy(fn ($o) => Carbon::parse($o->completed_time)->setTimezone($tz)->toDateString())
                    ->map(fn ($rows, $date) => [
                        'date' => $date,
                        'count' => $rows->count(),
                        'revenue' => round((float) $rows->sum('partner_revenue'), 2),
                    ])
                    ->values()
                    ->sortBy('date')
                    ->values();

                return [
                    'user' => ['id' => $p->id, 'name' => $p->name, 'email' => $p->email],
                    'price_list' => $p->partnerPriceList
                        ? ['id' => $p->partnerPriceList->id, 'name' => $p->partnerPriceList->name]
                        : null,
                    'shipped_total' => (clone $shipped)->count(),
                    'shipped_today' => (clone $shipped)->where('completed_time', '>=', $todayStartUtc)->count(),
                    'shipped_window' => $inWindow->count(),
                    // Shipped but with no completed_time, so absent from `daily`.
                    'undated' => (clone $shipped)->whereNull('completed_time')->count(),
                    'revenue_total' => round((float) (clone $shipped)->sum('partner_revenue'), 2),
                    'revenue_locked' => round((float) (clone $shipped)->whereNotNull('partner_locked_at')->sum('partner_revenue'), 2),
                    // Shipped work carrying no amount yet — the backlog the
                    // "tính tiền" button exists to clear.
                    'unpriced' => (clone $shipped)->whereNull('partner_revenue')->count(),
                    'daily' => $daily,
                ];
            }),
        ]);
    }

    /**
     * Per-order working for one partner: what each shipped order is currently
     * worth versus what their rate card says it should be.
     *
     * A preview, not a write. Locked orders are shown with their settled
     * amount and flagged, because the partner has already been paid against
     * them and re-pricing would move a number both sides agreed on.
     */
    public function orders(Request $request, User $partner)
    {
        abort_unless($partner->hasRole('partner'), 404, 'Not a partner');

        $tz = self::TZ;
        $query = Order::where('partner_id', $partner->id)
            ->where('status', Order::STATUS_SHIPPED)
            ->with(['items.productVariant.product:id,name,line_id', 'items.accessoryPrices']);

        // Same Chicago-day convention as the cards.
        if ($request->filled('date_from')) {
            $query->where('completed_time', '>=',
                Carbon::parse($request->date_from, $tz)->startOfDay()->utc());
        }
        if ($request->filled('date_to')) {
            $query->where('completed_time', '<=',
                Carbon::parse($request->date_to, $tz)->endOfDay()->utc());
        }

        $orders = $query->orderBy('completed_time')->get();
        $listId = $partner->partner_price_list_id;

        $rows = $orders->map(function ($o) use ($listId, $tz) {
            $breakdown = $listId
                ? $this->priceBreakdown($o, $listId)
                : ['total' => null, 'items' => [], 'order' => []];
            $computed = $breakdown['total'];
            $current = $o->partner_revenue === null ? null : (float) $o->partner_revenue;

            return [
                'order_id' => $o->id,
                'system_id' => $o->system_id,
                'ref_id' => $o->ref_id,
                'completed_time' => $o->completed_time
                    ? Carbon::parse($o->completed_time)->setTimezone($tz)->toDateTimeString()
                    : null,
                'items' => $o->items->map(fn ($i) => [
                    'product' => $i->productVariant?->product?->name,
                    'sku' => $i->productVariant?->sku,
                    'quantity' => (int) $i->quantity,
                ])->values(),
                'qty' => (int) $o->items->sum('quantity'),
                'current' => $current,
                'computed' => $computed,
                'locked' => $o->partner_locked_at !== null,
                // Locked AND already carrying an amount: that number was
                // settled against, so it is the one applyRevenue protects.
                // Locked with no amount protects nothing — those still get
                // priced, or they would stay blank forever.
                'settled' => $o->partner_locked_at !== null && $current !== null,
                // How that number was arrived at: per item, then the
                // once-per-order shipping component.
                'breakdown' => [
                    'items' => $breakdown['items'],
                    'order' => $breakdown['order'],
                ],
                // Only meaningful when both sides have a number.
                'diff' => ($current !== null && $computed !== null)
                    ? round($computed - $current, 2) : null,
            ];
        });

        return response()->json([
            'partner' => ['id' => $partner->id, 'name' => $partner->name],
            'price_list' => $partner->partnerPriceList
                ? ['id' => $partner->partnerPriceList->id, 'name' => $partner->partnerPriceList->name]
                : null,
            'timezone' => $tz,
            'orders' => $rows,
            'totals' => [
                'orders' => $rows->count(),
                'qty' => (int) $rows->sum('qty'),
                'current' => round((float) $rows->sum(fn ($r) => $r['current'] ?? 0), 2),
                'computed' => round((float) $rows->sum(fn ($r) => $r['computed'] ?? $r['current'] ?? 0), 2),
                'locked' => $rows->where('locked', true)->count(),
                'settled' => $rows->where('settled', true)->count(),
                'unpriced' => $rows->whereNull('current')->count(),
            ],
        ]);
    }

    /**
     * Write the computed amounts onto the orders.
     *
     * An order that is locked AND already carries an amount is skipped, never
     * overwritten: partner_locked_at means the partner marked the gang printed,
     * and that amount was settled against. A locked order with no amount has
     * nothing to protect — it predates partner pricing — so it is priced like
     * any other, otherwise it could never be paid at all.
     */
    public function applyRevenue(Request $request, User $partner)
    {
        abort_unless($partner->hasRole('partner'), 404, 'Not a partner');

        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer',
        ]);

        $listId = $partner->partner_price_list_id;
        if (! $listId) {
            return response()->json([
                'message' => 'Partner này chưa được gán bảng giá — không tính được tiền.',
            ], 422);
        }

        $orders = Order::where('partner_id', $partner->id)
            ->whereIn('id', $request->order_ids)
            ->with(['items.accessoryPrices'])
            ->get();

        $updated = 0;
        $skippedLocked = [];
        $noPrice = [];

        DB::transaction(function () use ($orders, $listId, &$updated, &$skippedLocked, &$noPrice) {
            foreach ($orders as $o) {
                if ($o->partner_locked_at !== null && $o->partner_revenue !== null) {
                    $skippedLocked[] = $o->system_id;

                    continue;
                }
                $amount = $this->priceBreakdown($o, $listId)['total'];
                if ($amount === null) {
                    $noPrice[] = $o->system_id;

                    continue;
                }
                $o->update(['partner_revenue' => $amount]);
                $updated++;
            }
        });

        return response()->json([
            'message' => "Đã tính tiền cho {$updated} đơn"
                .(count($skippedLocked) ? ' · bỏ qua '.count($skippedLocked).' đơn đã chốt tiền' : '')
                .(count($noPrice) ? ' · '.count($noPrice).' đơn bảng giá chưa có giá' : ''),
            'updated' => $updated,
            'skipped_locked' => $skippedLocked,
            'no_price' => $noPrice,
        ]);
    }

    /**
     * One order's worth against a rate card, with the working shown.
     *
     * Follows the same formula the order itself is priced by (see
     * OrderController::computeResendPricing / computeOrderShipping), with the
     * partner's rates substituted for the seller's:
     *
     *   per item   base_cost + 2nd_fee x (so mat in - 1) + add-ons, all x SL
     *   per order  tiktok_ship  -> label_fee, only when the order has a label
     *              otherwise    -> shipping_cost + addition_fee x (tong SL - 1)
     *
     * Summing every key on the card instead would charge 2nd_fee on one-sided
     * designs and addition_fee on single-item orders - money the order never
     * actually incurred.
     *
     * `total` is null when the card prices nothing on this order, so "no rate"
     * stays distinguishable from a real zero.
     */
    private function priceBreakdown(Order $order, int $listId): array
    {
        $order->loadMissing([
            'items.productVariant.product:id,name,line_id',
            'items.accessoryPrices.accessory:id,name',
            'items.metas',
        ]);

        $empty = ['total' => null, 'items' => [], 'order' => []];

        $variantIds = $order->items->pluck('product_variant_id')->filter()->unique()->values()->all();
        if (empty($variantIds)) {
            return $empty;
        }

        // (variant, key) -> rate. Missing means the card does not pay that key.
        $rates = PartnerVariantPrice::where('partner_price_list_id', $listId)
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->groupBy('product_variant_id')
            ->map(fn ($rows) => $rows->pluck('price', 'key')->map(fn ($p) => (float) $p));
        $rate = fn (?int $vid, string $key) => $vid ? ($rates[$vid][$key] ?? null) : null;

        $accIds = $order->items->flatMap(fn ($i) => $i->accessoryPrices->pluck('id'))
            ->unique()->values()->all();
        $accRates = empty($accIds) ? collect() : PartnerAccessoryPrice::where('partner_price_list_id', $listId)
            ->whereIn('accessory_price_id', $accIds)
            ->pluck('price', 'accessory_price_id');

        $hasPrice = false;
        $total = 0.0;
        $lines = [];

        foreach ($order->items as $item) {
            $qty = max(1, (int) $item->quantity);
            $vid = $item->product_variant_id;
            $unit = 0.0;
            $parts = [];

            $base = $rate($vid, 'base_cost');
            if ($base !== null) {
                $unit += $base;
                $hasPrice = true;
                $parts[] = ['label' => 'base_cost', 'note' => null, 'unit' => $base];
            }

            // Face fee, exactly as the order charges it: 2nd_fee x (faces - 1).
            $faces = $item->metas
                ->filter(fn ($m) => in_array($m->key, self::DESIGN_KEYS, true) && trim((string) $m->value) !== '')
                ->count();
            $second = $rate($vid, '2nd_fee');
            if ($faces > 1 && $second !== null) {
                $amt = ($faces - 1) * $second;
                $unit += $amt;
                $hasPrice = true;
                $parts[] = ['label' => '2nd_fee', 'note' => $faces.' mặt in → × '.($faces - 1), 'unit' => $amt];
            } elseif ($second !== null) {
                // Priced on the card but not owed here - say so, rather than
                // leave the reader wondering why the rate went unused.
                $parts[] = ['label' => '2nd_fee', 'note' => 'đơn 1 mặt → không tính', 'unit' => 0.0];
            }

            foreach ($item->accessoryPrices as $ap) {
                $label = 'Add-on: '.trim(($ap->accessory?->name ?? 'Add-on').' · '.($ap->style ?: $ap->accessory_code));
                if ($accRates->has($ap->id)) {
                    $unit += (float) $accRates[$ap->id];
                    $hasPrice = true;
                    $parts[] = ['label' => $label, 'note' => null, 'unit' => (float) $accRates[$ap->id]];
                } else {
                    // On the order but absent from the card: shown at null so a
                    // missing rate reads as an omission, not as free work.
                    $parts[] = ['label' => $label, 'note' => null, 'unit' => null];
                }
            }

            $total += $unit * $qty;
            $lines[] = [
                'product' => $item->productVariant?->product?->name,
                'sku' => $item->productVariant?->sku,
                'quantity' => $qty,
                'parts' => $parts,
                'unit' => round($unit, 2),
                'total' => round($unit * $qty, 2),
            ];
        }

        // Shipping is charged once per order off the first item's variant -
        // the same reference computeOrderShipping uses.
        $refVid = (int) ($order->items->first()?->product_variant_id ?? 0);
        $totalQty = (int) $order->items->sum(fn ($i) => max(1, (int) $i->quantity));
        $orderParts = [];

        if ($order->ship_type === Order::SHIP_TIKTOK) {
            $labelFee = $rate($refVid, 'label_fee');
            $hasLabel = ! empty($order->shipping_label);
            if ($labelFee !== null && $hasLabel) {
                $total += $labelFee;
                $hasPrice = true;
                $orderParts[] = ['label' => 'label_fee', 'note' => 'tiktok_ship, đơn có label', 'amount' => $labelFee];
            } elseif ($labelFee !== null) {
                $orderParts[] = ['label' => 'label_fee', 'note' => 'tiktok_ship, đơn chưa có label → không tính', 'amount' => 0.0];
            }
        } else {
            $ship = $rate($refVid, 'shipping_cost');
            $addition = $rate($refVid, 'addition_fee');
            $extra = max(0, $totalQty - 1);

            if ($ship !== null) {
                $total += $ship;
                $hasPrice = true;
                $orderParts[] = ['label' => 'shipping_cost', 'note' => $order->ship_type, 'amount' => $ship];
            }
            if ($addition !== null) {
                $amt = $extra * $addition;
                $total += $amt;
                if ($amt > 0) {
                    $hasPrice = true;
                }
                $orderParts[] = [
                    'label' => 'addition_fee',
                    'note' => $extra > 0
                        ? 'tổng SL '.$totalQty.' → × '.$extra
                        : 'tổng SL 1 → không tính',
                    'amount' => round($amt, 2),
                ];
            }
        }

        return [
            'total' => $hasPrice ? round($total, 2) : null,
            'items' => $lines,
            'order' => $orderParts,
        ];
    }
}
