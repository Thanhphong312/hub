<?php

namespace App\Http\Controllers;

use App\Models\AccessoryPrice;
use App\Models\Order;
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

    /**
     * POST /api/inventory/resync — recompute every variant + accessory stock
     * from history: `stock = SUM(imports.quantity) - SUM(shipped_orders × item.qty)`.
     *
     * Used after upgrading installs that have historical shipped orders, since
     * those didn't decrement stock at ship-time (the feature didn't exist yet).
     * Also flips `orders.stock_decremented = true` on every SHIPPED order so
     * future re-saves of the same order don't trigger a phantom decrement.
     *
     * Idempotent: running it twice gives the same result.
     */
    public function resyncStock(Request $request)
    {
        return DB::transaction(function () {
            // Variants ─────────────────────────────────────────────────────
            $variantImports = DB::table('stock_imports')
                ->whereNotNull('product_variant_id')
                ->groupBy('product_variant_id')
                ->selectRaw('product_variant_id, SUM(quantity) AS imported')
                ->pluck('imported', 'product_variant_id');

            $variantShipped = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', Order::STATUS_SHIPPED)
                ->groupBy('order_items.product_variant_id')
                ->selectRaw('order_items.product_variant_id, SUM(order_items.quantity) AS shipped')
                ->pluck('shipped', 'product_variant_id');

            $variantIds = $variantImports->keys()->merge($variantShipped->keys())->unique();
            $variantsTouched = 0;
            foreach ($variantIds as $vid) {
                $imported = (int) ($variantImports[$vid] ?? 0);
                $shipped  = (int) ($variantShipped[$vid] ?? 0);
                ProductVariant::where('id', $vid)->update(['stock' => $imported - $shipped]);
                $variantsTouched++;
            }

            // Accessories ──────────────────────────────────────────────────
            // Shipped count comes from the pivot — the migration backfilled
            // legacy single-accessory rows into the pivot, so this covers
            // both multi-accessory and pre-multi orders uniformly.
            $accessoryImports = DB::table('stock_imports')
                ->whereNotNull('accessory_price_id')
                ->groupBy('accessory_price_id')
                ->selectRaw('accessory_price_id, SUM(quantity) AS imported')
                ->pluck('imported', 'accessory_price_id');

            $accessoryShipped = DB::table('order_item_accessories as oia')
                ->join('order_items as oi', 'oi.id', '=', 'oia.order_item_id')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where('o.status', Order::STATUS_SHIPPED)
                ->groupBy('oia.accessory_price_id')
                ->selectRaw('oia.accessory_price_id, SUM(oi.quantity) AS shipped')
                ->pluck('shipped', 'accessory_price_id');

            $accessoryIds = $accessoryImports->keys()->merge($accessoryShipped->keys())->unique();
            $accessoriesTouched = 0;
            foreach ($accessoryIds as $apid) {
                $imported = (int) ($accessoryImports[$apid] ?? 0);
                $shipped  = (int) ($accessoryShipped[$apid] ?? 0);
                AccessoryPrice::where('id', $apid)->update(['stock' => $imported - $shipped]);
                $accessoriesTouched++;
            }

            // Mark all SHIPPED orders as accounted-for so future re-saves
            // don't re-trigger decrementStockForShipment().
            $ordersMarked = Order::where('status', Order::STATUS_SHIPPED)
                ->where('stock_decremented', false)
                ->update(['stock_decremented' => true]);

            return response()->json([
                'message' => 'Stock resynced from history',
                'variants_updated'   => $variantsTouched,
                'accessories_updated'=> $accessoriesTouched,
                'orders_marked'      => $ordersMarked,
            ]);
        });
    }

    /**
     * GET /api/inventory/imports/template — download a CSV pre-listing every
     * existing variant + accessory_price row as a reference, with empty
     * quantity / price columns for admin to fill in then re-upload via
     * `POST /api/inventory/imports/bulk`.
     *
     * Header order MUST match the import parser below. See README/inventory.md.
     */
    public function exportTemplate()
    {
        $headers = [
            'target_type',       // 'variant' | 'accessory'
            'sku_or_code',       // product_variants.sku or accessory_prices.accessory_code
            'tier_id',           // required for accessory (which tier row to bump); blank for variant
            'price_mode',        // 'per_item' | 'per_package'
            'quantity',          // per_item only; per_package leaves blank (auto = size * count)
            'package_size',      // per_package only — units per pkg
            'package_count',     // per_package only — number of pkgs
            'unit_price',        // per_item = $/unit, per_package = $/pkg
            'notes',
            // Reference-only columns (parser ignores) — to help operator
            // identify the right row when scanning the template.
            'ref_product_name',
            'ref_variant_label',
            'ref_tier_name',
            'ref_current_stock',
        ];

        $variants = \App\Models\ProductVariant::with('product:id,name')
            ->orderBy('product_id')->orderBy('id')->get();
        $accessories = \App\Models\AccessoryPrice::with('accessory:id,name,product_id', 'accessory.product:id,name', 'tier:id,name')
            ->orderBy('accessory_id')->orderBy('tier_id')->get();

        $filename = 'inventory_import_template_' . now()->format('Ymd_His') . '.csv';

        $callback = function () use ($headers, $variants, $accessories) {
            $fh = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens Vietnamese correctly.
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $headers);

            // Variants
            foreach ($variants as $v) {
                $label = trim(implode(' / ', array_filter([$v->color, $v->size, $v->paper_type])));
                if ($label === '') $label = $v->sku ?? "#{$v->id}";
                fputcsv($fh, [
                    'variant',
                    $v->sku ?? '',
                    '',                  // tier_id N/A for variant
                    '',                  // price_mode — fill in
                    '',                  // quantity
                    '', '', '',          // package_*, unit_price
                    '',                  // notes
                    $v->product?->name ?? '',
                    $label,
                    '',
                    (int) ($v->stock ?? 0),
                ]);
            }

            // Accessories
            foreach ($accessories as $a) {
                $accName = $a->accessory?->name ?? 'Accessory';
                $tierName = $a->tier?->name ?? "tier {$a->tier_id}";
                $styleNote = $a->style ? " ({$a->style})" : '';
                fputcsv($fh, [
                    'accessory',
                    $a->accessory_code ?? '',
                    $a->tier_id,
                    '',
                    '',
                    '', '', '',
                    '',
                    $a->accessory?->product?->name ?? '',
                    $accName . $styleNote,
                    $tierName,
                    (int) ($a->stock ?? 0),
                ]);
            }

            fclose($fh);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * POST /api/inventory/imports/bulk — upload the filled template. Each row
     * with non-empty price_mode becomes 1 stock_imports row + bumps target
     * stock. Empty rows are skipped (so the template stays small to fill).
     *
     * Validation: same rules as single store(). Per-row errors are collected
     * and returned in the response so the operator can fix and re-upload only
     * the failing rows.
     */
    public function importCsv(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $path = $request->file('file')->getPathname();
        $rows = array_map('str_getcsv', file($path));
        if (empty($rows)) {
            return response()->json(['message' => 'Empty file'], 422);
        }

        $header = array_map(fn ($h) => trim((string) $h), array_shift($rows));
        // Strip UTF-8 BOM from first header cell if Excel added it.
        if (!empty($header)) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        $required = ['target_type', 'sku_or_code', 'price_mode', 'unit_price'];
        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                return response()->json(['message' => "Missing required column: {$col}"], 422);
            }
        }

        $created  = 0;
        $skipped  = 0;
        $errors   = [];
        $imports  = [];

        DB::transaction(function () use ($rows, $header, $request, &$created, &$skipped, &$errors, &$imports) {
            foreach ($rows as $i => $row) {
                $lineNum = $i + 2;
                if (count($row) < count($header)) {
                    // pad to avoid array_combine warning
                    $row = array_pad($row, count($header), '');
                }
                $row = array_slice($row, 0, count($header));
                $data = array_combine($header, $row);
                $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);

                $mode = strtolower($data['price_mode'] ?? '');
                if ($mode === '') { $skipped++; continue; } // empty reference row

                $targetType = strtolower($data['target_type'] ?? '');
                $skuOrCode  = $data['sku_or_code'] ?? '';
                if ($skuOrCode === '') {
                    $errors[] = "Line {$lineNum}: missing sku_or_code"; continue;
                }

                // Resolve target.
                $variantId = null;
                $accessoryPriceId = null;
                if ($targetType === 'variant') {
                    $variant = \App\Models\ProductVariant::where('sku', $skuOrCode)->first();
                    if (!$variant) { $errors[] = "Line {$lineNum}: variant sku '{$skuOrCode}' not found"; continue; }
                    $variantId = $variant->id;
                } elseif ($targetType === 'accessory') {
                    $tierId = isset($data['tier_id']) && $data['tier_id'] !== '' ? (int) $data['tier_id'] : null;
                    if (!$tierId) { $errors[] = "Line {$lineNum}: tier_id required for accessory"; continue; }
                    $ap = \App\Models\AccessoryPrice::where('accessory_code', $skuOrCode)
                        ->where('tier_id', $tierId)
                        ->first();
                    if (!$ap) { $errors[] = "Line {$lineNum}: accessory code '{$skuOrCode}' tier {$tierId} not found"; continue; }
                    $accessoryPriceId = $ap->id;
                } else {
                    $errors[] = "Line {$lineNum}: target_type must be 'variant' or 'accessory'"; continue;
                }

                // Resolve qty + total based on price_mode.
                $unitPrice = (float) ($data['unit_price'] ?? 0);
                if ($unitPrice < 0) { $errors[] = "Line {$lineNum}: unit_price negative"; continue; }

                if ($mode === StockImport::MODE_PER_PACKAGE) {
                    $pkgSize  = (int) ($data['package_size'] ?? 0);
                    $pkgCount = (int) ($data['package_count'] ?? 0);
                    if ($pkgSize < 1 || $pkgCount < 1) {
                        $errors[] = "Line {$lineNum}: per_package needs package_size + package_count >= 1"; continue;
                    }
                    $quantity = $pkgSize * $pkgCount;
                    $totalCost = round($unitPrice * $pkgCount, 2);
                } elseif ($mode === StockImport::MODE_PER_ITEM) {
                    $quantity = (int) ($data['quantity'] ?? 0);
                    if ($quantity < 1) { $errors[] = "Line {$lineNum}: per_item needs quantity >= 1"; continue; }
                    $pkgSize = null; $pkgCount = null;
                    $totalCost = round($unitPrice * $quantity, 2);
                } else {
                    $errors[] = "Line {$lineNum}: price_mode must be 'per_item' or 'per_package'"; continue;
                }

                $import = StockImport::create([
                    'product_variant_id' => $variantId,
                    'accessory_price_id' => $accessoryPriceId,
                    'quantity'      => $quantity,
                    'price_mode'    => $mode,
                    'unit_price'    => $unitPrice,
                    'package_size'  => $pkgSize,
                    'package_count' => $pkgCount,
                    'total_cost'    => $totalCost,
                    'notes'         => $data['notes'] ?? null,
                    'imported_by_user_id' => $request->user()->id,
                    'imported_at'   => now(),
                ]);
                if ($variantId) {
                    \App\Models\ProductVariant::where('id', $variantId)->increment('stock', $quantity);
                } else {
                    \App\Models\AccessoryPrice::where('id', $accessoryPriceId)->increment('stock', $quantity);
                }
                $imports[] = $import->id;
                $created++;
            }
        });

        return response()->json([
            'message' => "{$created} import(s) created, {$skipped} skipped, " . count($errors) . " errors",
            'created' => $created,
            'skipped' => $skipped,
            'error_count' => count($errors),
            'errors' => $errors,
            'import_ids' => $imports,
        ]);
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
