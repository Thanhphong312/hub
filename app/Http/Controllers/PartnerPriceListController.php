<?php

namespace App\Http\Controllers;

use App\Models\AccessoryPrice;
use App\Models\PartnerAccessoryPrice;
use App\Models\PartnerPriceList;
use App\Models\PartnerVariantPrice;
use App\Models\ProductVariantPrice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rate cards partners are paid by. Admin-only — see routes/api.php.
 */
class PartnerPriceListController extends Controller
{
    public function index()
    {
        return response()->json(
            PartnerPriceList::withCount(['partners', 'variantPrices', 'accessoryPrices'])
                ->with('partners:id,name,partner_price_list_id')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'note' => 'nullable|string|max:2000',
        ]);

        return response()->json([
            'message' => 'Đã tạo bảng giá',
            'list' => PartnerPriceList::create($data),
        ], 201);
    }

    public function update(Request $request, PartnerPriceList $list)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'note' => 'nullable|string|max:2000',
        ]);
        $list->update($data);

        return response()->json(['message' => 'Đã lưu', 'list' => $list->fresh()]);
    }

    public function destroy(PartnerPriceList $list)
    {
        // Partners pointing here are detached by the FK's nullOnDelete, so they
        // fall back to "no rate card" rather than to a dangling id.
        $names = $list->partners()->pluck('name');
        $list->delete();

        return response()->json([
            'message' => 'Đã xoá bảng giá'
                . ($names->isNotEmpty() ? ' · gỡ khỏi ' . $names->implode(', ') : ''),
        ]);
    }

    /**
     * Everything the editor needs for one card: every sellable variant and
     * accessory choice, each carrying this card's price when it has one.
     *
     * The seller price rides along for reference — setting a partner rate is a
     * margin decision, and having to open another screen to see what the order
     * sells for makes that guesswork.
     */
    public function show(PartnerPriceList $list)
    {
        $mine = $list->variantPrices()->get()
            ->keyBy(fn ($r) => $r->product_variant_id . '|' . $r->key);

        $variants = ProductVariantPrice::with('variant.product:id,name,line_id', 'tier:id,name')
            ->get()
            ->groupBy('product_variant_id')
            ->map(function ($rows, $variantId) use ($mine) {
                $v = $rows->first()->variant;

                return [
                    'variant_id' => (int) $variantId,
                    'product'    => $v?->product?->name,
                    'line_id'    => $v?->product?->line_id,
                    'sku'        => $v?->sku,
                    'color'      => $v?->color,
                    'size'       => $v?->size,
                    'keys'       => $rows->groupBy('key')->map(fn ($byKey, $key) => [
                        'key' => $key,
                        // What sellers pay, per tier — context, not editable here.
                        'seller_prices' => $byKey->map(fn ($r) => [
                            'tier'  => $r->tier?->name,
                            'price' => (float) $r->price,
                        ])->values(),
                        'partner_price'         => isset($mine[$variantId . '|' . $key])
                            ? (float) $mine[$variantId . '|' . $key]->price
                            : null,
                        'partner_price_private' => isset($mine[$variantId . '|' . $key])
                            ? ($mine[$variantId . '|' . $key]->price_private !== null
                                ? (float) $mine[$variantId . '|' . $key]->price_private
                                : null)
                            : null,
                    ])->values(),
                ];
            })->values();

        $myAcc = $list->accessoryPrices()->get()->keyBy('accessory_price_id');
        $accessories = AccessoryPrice::with('accessory:id,name', 'tier:id,name')->get()
            ->map(fn ($ap) => [
                'accessory_price_id' => $ap->id,
                'accessory'          => $ap->accessory?->name,
                'style'              => $ap->style,
                'accessory_code'     => $ap->accessory_code,
                'tier'               => $ap->tier?->name,
                'seller_price'       => (float) $ap->price,
                'partner_price'         => isset($myAcc[$ap->id]) ? (float) $myAcc[$ap->id]->price : null,
                'partner_price_private' => isset($myAcc[$ap->id]) && $myAcc[$ap->id]->price_private !== null
                    ? (float) $myAcc[$ap->id]->price_private : null,
            ])->values();

        return response()->json([
            'list'        => $list->load('partners:id,name,partner_price_list_id'),
            'variants'    => $variants,
            'accessories' => $accessories,
        ]);
    }

    /**
     * Set the prices on one card. A null price DELETES the row: that is how a
     * key is taken out of what the partner earns, and it keeps "not paid" and
     * "paid zero" as genuinely different states.
     */
    public function savePrices(Request $request, PartnerPriceList $list)
    {
        $data = $request->validate([
            'variants'                              => 'sometimes|array',
            'variants.*.product_variant_id'         => 'required|integer|exists:product_variants,id',
            'variants.*.key'                        => 'required|string|max:64',
            'variants.*.price'                      => 'nullable|numeric|min:0',
            'variants.*.price_private'              => 'nullable|numeric|min:0',
            'accessories'                           => 'sometimes|array',
            'accessories.*.accessory_price_id'      => 'required|integer|exists:accessory_prices,id',
            'accessories.*.price'                   => 'nullable|numeric|min:0',
            'accessories.*.price_private'           => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($data, $list) {
            foreach ($data['variants'] ?? [] as $row) {
                $where = [
                    'partner_price_list_id' => $list->id,
                    'product_variant_id'    => $row['product_variant_id'],
                    'key'                   => $row['key'],
                ];
                $publicPrice   = ($row['price'] ?? null) === null || $row['price'] === '' ? null : $row['price'];
                $privatePrice  = ($row['price_private'] ?? null) === null || $row['price_private'] === '' ? null : $row['price_private'];
                if ($publicPrice === null && $privatePrice === null) {
                    PartnerVariantPrice::where($where)->delete();
                } else {
                    PartnerVariantPrice::updateOrCreate($where, array_filter([
                        'price'         => $publicPrice,
                        'price_private' => $privatePrice,
                    ], fn ($v) => $v !== null));
                    // Explicitly allow setting price_private to null (clear it)
                    if ($publicPrice !== null && $privatePrice === null) {
                        PartnerVariantPrice::where($where)->update(['price_private' => null]);
                    }
                }
            }
            foreach ($data['accessories'] ?? [] as $row) {
                $where = [
                    'partner_price_list_id' => $list->id,
                    'accessory_price_id'    => $row['accessory_price_id'],
                ];
                $publicPrice  = ($row['price'] ?? null) === null || $row['price'] === '' ? null : $row['price'];
                $privatePrice = ($row['price_private'] ?? null) === null || $row['price_private'] === '' ? null : $row['price_private'];
                if ($publicPrice === null && $privatePrice === null) {
                    PartnerAccessoryPrice::where($where)->delete();
                } else {
                    PartnerAccessoryPrice::updateOrCreate($where, array_filter([
                        'price'         => $publicPrice,
                        'price_private' => $privatePrice,
                    ], fn ($v) => $v !== null));
                    if ($publicPrice !== null && $privatePrice === null) {
                        PartnerAccessoryPrice::where($where)->update(['price_private' => null]);
                    }
                }
            }
        });

        return response()->json(['message' => 'Đã lưu bảng giá']);
    }

    /** Point partners at this card (or, with an empty list, at none). */
    public function setPartners(Request $request, PartnerPriceList $list)
    {
        $request->validate([
            'user_ids'   => 'present|array',
            'user_ids.*' => 'integer|exists:users,id',
        ]);
        $ids = array_map('intval', $request->user_ids);

        DB::transaction(function () use ($ids, $list) {
            User::where('partner_price_list_id', $list->id)->whereNotIn('id', $ids)
                ->update(['partner_price_list_id' => null]);
            if ($ids) {
                User::whereIn('id', $ids)->update(['partner_price_list_id' => $list->id]);
            }
        });

        return response()->json([
            'message'  => 'Đã gán partner',
            'partners' => $list->fresh()->partners()->get(['id', 'name']),
        ]);
    }
}
