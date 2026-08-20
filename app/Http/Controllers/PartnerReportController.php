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
            $breakdown = $listId ? $this->priceBreakdown($o, $listId) : ['total' => null, 'lines' => []];
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
                // How that number was arrived at, item by item.
                'breakdown' => $breakdown['lines'],
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
     * Mirrors calcPartnerRevenue in GangsheetController: only the keys the card
     * actually prices are summed. `total` is null when the card prices nothing
     * on this order, so "no rate" stays distinguishable from a real zero.
     *
     * `lines` is the same arithmetic laid out per item — which key or accessory
     * contributed what — because an amount nobody can check is an amount
     * nobody can argue with.
     */
    private function priceBreakdown(Order $order, int $listId): array
    {
        $order->loadMissing(['items.productVariant.product:id,name,line_id', 'items.accessoryPrices.accessory:id,name']);

        $variantIds = $order->items->pluck('product_variant_id')->filter()->unique()->values()->all();
        if (empty($variantIds)) {
            return ['total' => null, 'lines' => []];
        }

        // Kept per key, not summed, so the breakdown can name each contributor.
        $variantPrices = PartnerVariantPrice::where('partner_price_list_id', $listId)
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->groupBy('product_variant_id');

        $accIds = $order->items->flatMap(fn ($i) => $i->accessoryPrices->pluck('id'))
            ->unique()->values()->all();
        $accPrices = empty($accIds) ? collect() : PartnerAccessoryPrice::where('partner_price_list_id', $listId)
            ->whereIn('accessory_price_id', $accIds)
            ->pluck('price', 'accessory_price_id');

        $total = 0;
        $hasPrice = false;
        $lines = [];

        foreach ($order->items as $item) {
            $qty = max(1, (int) $item->quantity);
            $unit = 0;
            $parts = [];

            foreach ($variantPrices[$item->product_variant_id] ?? [] as $vp) {
                $unit += (float) $vp->price;
                $hasPrice = true;
                $parts[] = ['type' => 'variant', 'label' => $vp->key, 'price' => (float) $vp->price];
            }

            foreach ($item->accessoryPrices as $ap) {
                $label = trim(($ap->accessory?->name ?? 'Add-on').' · '.($ap->style ?: $ap->accessory_code));
                if ($accPrices->has($ap->id)) {
                    $unit += (float) $accPrices[$ap->id];
                    $hasPrice = true;
                    $parts[] = ['type' => 'accessory', 'label' => $label, 'price' => (float) $accPrices[$ap->id]];
                } else {
                    // On the order but absent from the card: shown at zero so a
                    // missing rate reads as an omission, not as free work.
                    $parts[] = ['type' => 'accessory', 'label' => $label, 'price' => null];
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

        return ['total' => $hasPrice ? round($total, 2) : null, 'lines' => $lines];
    }
}
