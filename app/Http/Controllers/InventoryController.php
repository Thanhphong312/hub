<?php

namespace App\Http\Controllers;

use App\Models\AccessoryPrice;
use App\Models\ProductVariant;
use App\Models\StockImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    // GET /api/inventory/imports — paginated history of every stock import.
    public function index(Request $request)
    {
        $q = StockImport::with([
            'productVariant.product:id,name',
            'accessoryPrice.accessory:id,name,product_id',
            'accessoryPrice.accessory.product:id,name',
            'accessoryPrice.tier:id,name',
            'importer:id,name',
        ])->orderByDesc('imported_at')->orderByDesc('id');

        if ($request->filled('target')) {
            if ($request->target === 'variant') $q->whereNotNull('product_variant_id');
            if ($request->target === 'accessory') $q->whereNotNull('accessory_price_id');
        }
        if ($request->filled('product_variant_id')) {
            $q->where('product_variant_id', (int) $request->product_variant_id);
        }
        if ($request->filled('accessory_price_id')) {
            $q->where('accessory_price_id', (int) $request->accessory_price_id);
        }
        if ($request->filled('date_from')) $q->whereDate('imported_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $q->whereDate('imported_at', '<=', $request->date_to);

        return response()->json($q->paginate($request->get('per_page', 20)));
    }

    // POST /api/inventory/imports — record a new stock import and bump on-hand stock.
    // Target is exactly one of:
    //   - { product_variant_id }     → bumps product_variants.stock
    //   - { accessory_price_id }     → bumps accessory_prices.stock
    // Price mode:
    //   - per_item:    unit_price * quantity = total_cost
    //   - per_package: package_size * package_count = quantity (overrides supplied qty);
    //                  unit_price holds the price per package; total_cost = unit_price * package_count.
    public function store(Request $request)
    {
        $request->validate([
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'accessory_price_id' => 'nullable|exists:accessory_prices,id',
            'price_mode'   => 'required|in:per_item,per_package',
            'quantity'     => 'nullable|integer|min:1',
            'unit_price'   => 'required|numeric|min:0',
            'package_size' => 'nullable|integer|min:1',
            'package_count'=> 'nullable|integer|min:1',
            'notes'        => 'nullable|string|max:500',
            'imported_at'  => 'nullable|date',
        ]);

        $variantId = $request->product_variant_id;
        $accessoryPriceId = $request->accessory_price_id;
        if ((bool) $variantId === (bool) $accessoryPriceId) {
            return response()->json(['message' => 'Provide exactly one of product_variant_id or accessory_price_id'], 422);
        }

        $mode = $request->price_mode;
        if ($mode === StockImport::MODE_PER_PACKAGE) {
            if (!$request->filled('package_size') || !$request->filled('package_count')) {
                return response()->json(['message' => 'package_size and package_count are required for per_package'], 422);
            }
            $packageSize  = (int) $request->package_size;
            $packageCount = (int) $request->package_count;
            $quantity = $packageSize * $packageCount;
            $totalCost = round((float) $request->unit_price * $packageCount, 2);
        } else {
            if (!$request->filled('quantity')) {
                return response()->json(['message' => 'quantity is required for per_item'], 422);
            }
            $quantity = (int) $request->quantity;
            $packageSize = null;
            $packageCount = null;
            $totalCost = round((float) $request->unit_price * $quantity, 2);
        }

        return DB::transaction(function () use ($request, $variantId, $accessoryPriceId, $mode, $quantity, $packageSize, $packageCount, $totalCost) {
            $import = StockImport::create([
                'product_variant_id' => $variantId,
                'accessory_price_id' => $accessoryPriceId,
                'quantity'      => $quantity,
                'price_mode'    => $mode,
                'unit_price'    => $request->unit_price,
                'package_size'  => $packageSize,
                'package_count' => $packageCount,
                'total_cost'    => $totalCost,
                'notes'         => $request->notes,
                'imported_by_user_id' => $request->user()->id,
                'imported_at'   => $request->imported_at ?: now(),
            ]);

            if ($variantId) {
                ProductVariant::where('id', $variantId)->increment('stock', $quantity);
            } else {
                AccessoryPrice::where('id', $accessoryPriceId)->increment('stock', $quantity);
            }

            return response()->json([
                'message' => 'Stock import recorded',
                'import' => $import->fresh()->load([
                    'productVariant.product:id,name',
                    'accessoryPrice.accessory:id,name,product_id',
                    'accessoryPrice.accessory.product:id,name',
                    'accessoryPrice.tier:id,name',
                    'importer:id,name',
                ]),
            ], 201);
        });
    }

    // DELETE /api/inventory/imports/{import} — reverse an import (subtract stock back).
    // Admin-only via route middleware.
    public function destroy(StockImport $import)
    {
        return DB::transaction(function () use ($import) {
            if ($import->product_variant_id) {
                ProductVariant::where('id', $import->product_variant_id)->decrement('stock', $import->quantity);
            } elseif ($import->accessory_price_id) {
                AccessoryPrice::where('id', $import->accessory_price_id)->decrement('stock', $import->quantity);
            }
            $import->delete();
            return response()->json(['message' => 'Stock import deleted; stock adjusted back']);
        });
    }

    // GET /api/inventory/stock — flat list of on-hand stock for variants + accessory prices.
    // Used by the inventory page's "Current Stock" tab.
    public function stock(Request $request)
    {
        $variants = ProductVariant::with('product:id,name')
            ->select('id', 'product_id', 'sku', 'color', 'size', 'paper_type', 'stock')
            ->orderBy('product_id')->orderBy('id')
            ->get()
            ->map(fn ($v) => [
                'kind'         => 'variant',
                'id'           => $v->id,
                'product_name' => $v->product?->name,
                'sku'          => $v->sku,
                'label'        => trim(implode(' / ', array_filter([$v->color, $v->size, $v->paper_type]))) ?: ($v->sku ?? "Variant #{$v->id}"),
                'stock'        => (int) $v->stock,
            ]);

        $accessories = AccessoryPrice::with('accessory:id,name,product_id', 'accessory.product:id,name', 'tier:id,name')
            ->select('id', 'accessory_id', 'tier_id', 'style', 'accessory_code', 'stock')
            ->orderBy('accessory_id')->orderBy('tier_id')
            ->get()
            ->map(fn ($a) => [
                'kind'         => 'accessory',
                'id'           => $a->id,
                'product_name' => $a->accessory?->product?->name,
                'sku'          => $a->accessory_code,
                'label'        => trim(($a->accessory?->name ?? 'Accessory') . ' • ' . ($a->tier?->name ?? '') . ($a->style ? " ({$a->style})" : '')),
                'stock'        => (int) $a->stock,
            ]);

        return response()->json([
            'variants'    => $variants,
            'accessories' => $accessories,
        ]);
    }
}
