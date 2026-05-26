<?php

namespace App\Http\Controllers;

use App\Models\Accessory;
use App\Models\AccessoryPrice;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockImport;
use App\Models\Supply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    // GET /api/inventory/imports — paginated history of every stock import.
    public function index(Request $request)
    {
        $q = StockImport::with([
            'productVariant.product:id,name',
            // New unified target: accessory (one stock pool per accessory).
            'accessory:id,name,product_id',
            'accessory.product:id,name',
            'supply:id,name,code',
            // Legacy: rows imported before per-accessory unification still
            // carry accessory_price_id. Load for display only.
            'accessoryPrice.accessory:id,name,product_id',
            'accessoryPrice.accessory.product:id,name',
            'accessoryPrice.tier:id,name',
            'importer:id,name',
        ])->orderByDesc('imported_at')->orderByDesc('id');

        if ($request->filled('target')) {
            if ($request->target === 'variant') $q->whereNotNull('product_variant_id');
            // An "accessory" target now covers both new (accessory_id) and
            // legacy (accessory_price_id) rows.
            if ($request->target === 'accessory') {
                $q->where(function ($w) {
                    $w->whereNotNull('accessory_id')->orWhereNotNull('accessory_price_id');
                });
            }
            if ($request->target === 'supply') $q->whereNotNull('supply_id');
        }
        if ($request->filled('product_variant_id')) {
            $q->where('product_variant_id', (int) $request->product_variant_id);
        }
        if ($request->filled('accessory_id')) {
            $q->where('accessory_id', (int) $request->accessory_id);
        }
        if ($request->filled('date_from')) $q->whereDate('imported_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $q->whereDate('imported_at', '<=', $request->date_to);

        return response()->json($q->paginate($request->get('per_page', 20)));
    }

    // POST /api/inventory/imports — record a new stock import and bump on-hand stock.
    // Target is exactly one of:
    //   - { product_variant_id }   → bumps product_variants.stock
    //   - { accessory_price_id }   → bumps accessory_prices.stock (per-code)
    //   - { accessory_id }         → legacy: resolves to FIRST accessory_price of that accessory
    //   - { supply_id }            → bumps supplies.stock
    // Price mode same as before.
    public function store(Request $request)
    {
        $request->validate([
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'accessory_id'       => 'nullable|exists:accessories,id',
            'supply_id'          => 'nullable|exists:supplies,id',
            'accessory_price_id' => 'nullable|exists:accessory_prices,id',
            'price_mode'   => 'required|in:per_item,per_package',
            'quantity'     => 'nullable|integer|min:1',
            'unit_price'   => 'required|numeric|min:0',
            'package_size' => 'nullable|integer|min:1',
            'package_count'=> 'nullable|integer|min:1',
            'notes'        => 'nullable|string|max:500',
            'imported_at'  => 'nullable|date',
        ]);

        $variantId        = $request->product_variant_id;
        $supplyId         = $request->supply_id;
        $accessoryPriceId = $request->accessory_price_id;
        $accessoryId      = $request->accessory_id;

        // Legacy callers may pass only accessory_id (pre-unshare). Auto-resolve
        // to the FIRST accessory_price row of that accessory so the import
        // lands somewhere visible; operator can rebalance afterwards.
        if (!$accessoryPriceId && $accessoryId) {
            $accessoryPriceId = AccessoryPrice::where('accessory_id', $accessoryId)
                ->orderBy('id')->value('id');
            if (!$accessoryPriceId) {
                return response()->json(['message' => 'Accessory has no price rows to attribute stock to'], 422);
            }
        }
        if ($accessoryPriceId) {
            // Always keep accessory_id in sync for legacy joins / history filters.
            $accessoryId = AccessoryPrice::where('id', $accessoryPriceId)->value('accessory_id');
        }

        $targetCount = ($variantId ? 1 : 0) + ($accessoryPriceId ? 1 : 0) + ($supplyId ? 1 : 0);
        if ($targetCount !== 1) {
            return response()->json(['message' => 'Provide exactly one of product_variant_id, accessory_price_id (or accessory_id), or supply_id'], 422);
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

        return DB::transaction(function () use ($request, $variantId, $accessoryId, $accessoryPriceId, $supplyId, $mode, $quantity, $packageSize, $packageCount, $totalCost) {
            $import = StockImport::create([
                'product_variant_id' => $variantId,
                'accessory_id'       => $accessoryId,        // historical filter convenience
                'accessory_price_id' => $accessoryPriceId,   // source of truth target
                'supply_id'          => $supplyId,
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
            } elseif ($accessoryPriceId) {
                AccessoryPrice::where('id', $accessoryPriceId)->increment('stock', $quantity);
            } else {
                Supply::where('id', $supplyId)->increment('stock', $quantity);
            }

            return response()->json([
                'message' => 'Stock import recorded',
                'import' => $import->fresh()->load([
                    'productVariant.product:id,name',
                    'accessory:id,name,product_id',
                    'accessory.product:id,name',
                    'supply:id,name,code',
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
            } elseif ($import->supply_id) {
                Supply::where('id', $import->supply_id)->decrement('stock', $import->quantity);
            } elseif ($import->accessory_id) {
                // Legacy import row without per-code attribution. Decrement
                // off the first accessory_price row of that accessory.
                $priceId = AccessoryPrice::where('accessory_id', $import->accessory_id)
                    ->orderBy('id')->value('id');
                if ($priceId) AccessoryPrice::where('id', $priceId)->decrement('stock', $import->quantity);
            }
            $import->delete();
            return response()->json(['message' => 'Stock import deleted; stock adjusted back']);
        });
    }

    /**
     * POST /api/inventory/resync — recompute on-hand stock from import history.
     *
     * Per operator decision (2026-05-27), variant + accessory stock is now
     * import-only — shipments do NOT reduce stock until the new
     * per-accessory-code deduction model lands. So:
     *   product_variants.stock  = SUM(stock_imports.quantity)
     *   accessory_prices.stock  = SUM(stock_imports.quantity per accessory_price_id)
     *
     * Supplies keep their existing behavior (label paper decrements once per
     * shipped order).
     *
     * Idempotent.
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

            $variantsTouched = 0;
            foreach ($variantImports as $vid => $imported) {
                ProductVariant::where('id', $vid)->update(['stock' => (int) $imported]);
                $variantsTouched++;
            }
            // Zero out variants with no imports so deleted-then-resynced data is clean.
            ProductVariant::whereNotIn('id', $variantImports->keys())->update(['stock' => 0]);

            // Accessories ──────────────────────────────────────────────────
            // Per-accessory_price now. Sum direct rows (accessory_price_id
            // populated). Re-attribute legacy rows that only have accessory_id
            // to the first accessory_price of that accessory.
            $directImports = DB::table('stock_imports')
                ->whereNotNull('accessory_price_id')
                ->groupBy('accessory_price_id')
                ->selectRaw('accessory_price_id, SUM(quantity) AS imported')
                ->pluck('imported', 'accessory_price_id');

            $legacyByAccessory = DB::table('stock_imports')
                ->whereNotNull('accessory_id')
                ->whereNull('accessory_price_id')
                ->groupBy('accessory_id')
                ->selectRaw('accessory_id, SUM(quantity) AS imported')
                ->pluck('imported', 'accessory_id');

            // Map each legacy accessory_id → first accessory_price_id.
            $firstPrice = DB::table('accessory_prices')
                ->select('accessory_id', DB::raw('MIN(id) AS first_id'))
                ->groupBy('accessory_id')
                ->pluck('first_id', 'accessory_id');

            $merged = $directImports->mapWithKeys(fn ($v, $k) => [(int) $k => (int) $v])->all();
            foreach ($legacyByAccessory as $aid => $qty) {
                $pid = $firstPrice[$aid] ?? null;
                if (!$pid) continue;
                $merged[(int) $pid] = ($merged[(int) $pid] ?? 0) + (int) $qty;
            }

            $accessoriesTouched = 0;
            foreach ($merged as $pid => $imported) {
                AccessoryPrice::where('id', $pid)->update(['stock' => $imported]);
                $accessoriesTouched++;
            }
            // Zero accessory_prices that have no imports.
            AccessoryPrice::whereNotIn('id', array_keys($merged))->update(['stock' => 0]);

            // Supplies ─────────────────────────────────────────────────────
            // Each supply: stock = Σ imports.quantity for this supply_id − N
            // where N is the count of shipped orders ONLY for the default
            // label supply (because that's the one that gets decremented on
            // ship). Non-default supplies just mirror their imports.
            $supplyImports = DB::table('stock_imports')
                ->whereNotNull('supply_id')
                ->groupBy('supply_id')
                ->selectRaw('supply_id, SUM(quantity) AS imported')
                ->pluck('imported', 'supply_id');

            $shippedOrderCount = (int) Order::where('status', Order::STATUS_SHIPPED)->count();

            $suppliesTouched = 0;
            foreach (Supply::all() as $supply) {
                $imported = (int) ($supplyImports[$supply->id] ?? 0);
                $consumed = $supply->is_default_label ? $shippedOrderCount : 0;
                $supply->update(['stock' => $imported - $consumed]);
                $suppliesTouched++;
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
                'supplies_updated'   => $suppliesTouched,
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
            'target_type',       // 'variant' | 'accessory' | 'supply'
            'sku_or_code',       // variants.sku · accessory id/code · supplies.code or id
            'price_mode',        // 'per_item' | 'per_package'
            'quantity',          // per_item only; per_package leaves blank (auto = size * count)
            'package_size',      // per_package only — units per pkg
            'package_count',     // per_package only — number of pkgs
            'unit_price',        // per_item = $/unit, per_package = $/pkg
            'notes',
            // Reference-only columns (parser ignores) — to help operator
            // identify the right row when scanning the template.
            'ref_product_name',
            'ref_label',
            'ref_codes_any_tier',
            'ref_current_stock',
        ];

        $variants = \App\Models\ProductVariant::with('product:id,name')
            ->orderBy('product_id')->orderBy('id')->get();
        // Per-code accessory rows. Each accessory_price (= one choice like
        // envelope White vs Red) is its own template row with its own stock.
        $accessoryPrices = AccessoryPrice::with('accessory.product:id,name')
            ->orderBy('accessory_id')->orderBy('id')->get();
        // Supplies (label paper, packaging, etc.) — flat, default-label first.
        $supplies = Supply::orderByDesc('is_default_label')->orderBy('id')->get();

        $filename = 'inventory_import_template_' . now()->format('Ymd_His') . '.csv';

        $writeSectionHeader = function ($fh, $headerCount, $title) {
            // price_mode (col 3) empty → importer skips. All other cells empty
            // except ref_product_name which carries the divider text.
            fputcsv($fh, ['', '', '', '', '', '', '', '', $title, '', '', '']);
        };

        $callback = function () use ($headers, $variants, $accessoryPrices, $supplies, $writeSectionHeader) {
            $fh = fopen('php://output', 'w');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $headers);

            $writeSectionHeader($fh, count($headers), '════════ PRODUCT VARIANTS ════════');

            // Variants — grouped by product with blank divider + per-product
            // header row between groups. price_mode empty on the divider rows
            // means the importer ignores them.
            $lastProductId = null;
            foreach ($variants as $v) {
                $productId = $v->product_id;
                if ($productId !== $lastProductId) {
                    if ($lastProductId !== null) {
                        fputcsv($fh, array_fill(0, count($headers), ''));
                    }
                    $writeSectionHeader($fh, count($headers),
                        '── PRODUCT: ' . ($v->product?->name ?? "#{$productId}") . ' ──');
                    $lastProductId = $productId;
                }

                $label = trim(implode(' / ', array_filter([$v->color, $v->size, $v->paper_type])));
                if ($label === '') $label = $v->sku ?? "#{$v->id}";
                fputcsv($fh, [
                    'variant',
                    $v->sku ?? '',
                    '',
                    '',
                    '', '', '',
                    '',
                    $v->product?->name ?? '',
                    $label,
                    '',
                    (int) ($v->stock ?? 0),
                ]);
            }

            // ── Accessories section — FLAT, one row per accessory_price
            //     (per code / choice). Each code carries its own stock pool.
            if ($accessoryPrices->isNotEmpty()) {
                fputcsv($fh, array_fill(0, count($headers), ''));
                $writeSectionHeader($fh, count($headers), '════════ ACCESSORIES ════════');

                foreach ($accessoryPrices as $p) {
                    $code = $p->accessory_code ?? (string) $p->id;
                    fputcsv($fh, [
                        'accessory',
                        $code,
                        '',
                        '',
                        '', '', '',
                        '',
                        $p->accessory?->product?->name ?? '',
                        $p->accessory?->name . ($p->style ? " ({$p->style})" : ''),
                        '',
                        (int) ($p->stock ?? 0),
                    ]);
                }
            }

            // ── Supplies (consumables — label paper, packaging, etc.) ──
            // Section is always present so operator knows the row exists even
            // when stock=0. Default-label row floats to the top.
            if ($supplies->isNotEmpty()) {
                fputcsv($fh, array_fill(0, count($headers), ''));
                $writeSectionHeader($fh, count($headers), '════════ SUPPLIES (label paper / consumables) ════════');

                foreach ($supplies as $s) {
                    $tag = $s->is_default_label ? ' (default label)' : '';
                    fputcsv($fh, [
                        'supply',
                        $s->code ?: (string) $s->id,
                        '',
                        '',
                        '', '', '',
                        '',
                        '',                       // supplies have no product
                        $s->name . $tag,
                        $s->code ?? '',
                        (int) ($s->stock ?? 0),
                    ]);
                }
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
                // Accessories no longer split by tier → CSV "sku_or_code" for
                // an accessory row means either:
                //   - accessory.id (numeric), OR
                //   - any accessory_prices.accessory_code that maps to one
                //     unique accessory_id (we collapse tier dups).
                $variantId        = null;
                $accessoryPriceId = null;
                $supplyId         = null;
                if ($targetType === 'variant') {
                    $variant = \App\Models\ProductVariant::where('sku', $skuOrCode)->first();
                    if (!$variant) { $errors[] = "Line {$lineNum}: variant sku '{$skuOrCode}' not found"; continue; }
                    $variantId = $variant->id;
                } elseif ($targetType === 'accessory') {
                    // Per-code stock: sku_or_code is the accessory_code (unique
                    // per accessory_prices row) or the row id directly.
                    if (ctype_digit((string) $skuOrCode)) {
                        $exists = AccessoryPrice::where('id', (int) $skuOrCode)->exists();
                        if (!$exists) { $errors[] = "Line {$lineNum}: accessory_price id '{$skuOrCode}' not found"; continue; }
                        $accessoryPriceId = (int) $skuOrCode;
                    } else {
                        $priceIds = AccessoryPrice::where('accessory_code', $skuOrCode)
                            ->distinct()->pluck('id')->all();
                        if (count($priceIds) === 0) {
                            $errors[] = "Line {$lineNum}: accessory code '{$skuOrCode}' not found"; continue;
                        }
                        if (count($priceIds) > 1) {
                            $errors[] = "Line {$lineNum}: accessory code '{$skuOrCode}' maps to multiple price rows — use numeric id instead"; continue;
                        }
                        $accessoryPriceId = (int) $priceIds[0];
                    }
                } elseif ($targetType === 'supply') {
                    // sku_or_code resolves by exact match on supplies.code first,
                    // falling back to numeric id. Codes are case-insensitive to
                    // forgive Excel auto-uppercase.
                    $supply = Supply::whereRaw('LOWER(code) = ?', [strtolower((string) $skuOrCode)])->first();
                    if (!$supply && ctype_digit((string) $skuOrCode)) {
                        $supply = Supply::find((int) $skuOrCode);
                    }
                    if (!$supply) { $errors[] = "Line {$lineNum}: supply code/id '{$skuOrCode}' not found"; continue; }
                    $supplyId = $supply->id;
                } else {
                    $errors[] = "Line {$lineNum}: target_type must be 'variant', 'accessory' or 'supply'"; continue;
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

                $accessoryIdSync = $accessoryPriceId
                    ? AccessoryPrice::where('id', $accessoryPriceId)->value('accessory_id')
                    : null;

                $import = StockImport::create([
                    'product_variant_id' => $variantId,
                    'accessory_id'       => $accessoryIdSync,    // history/back-compat
                    'accessory_price_id' => $accessoryPriceId,   // per-code target
                    'supply_id'          => $supplyId,
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
                    ProductVariant::where('id', $variantId)->increment('stock', $quantity);
                } elseif ($accessoryPriceId) {
                    AccessoryPrice::where('id', $accessoryPriceId)->increment('stock', $quantity);
                } else {
                    Supply::where('id', $supplyId)->increment('stock', $quantity);
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

        // Accessory stock is per-code (one row per accessory_prices row).
        $accessories = AccessoryPrice::with('accessory.product:id,name', 'accessory:id,name,product_id')
            ->orderBy('accessory_id')->orderBy('id')
            ->get()
            ->map(function ($p) {
                return [
                    'kind'         => 'accessory',
                    'id'           => $p->id,                              // accessory_price.id
                    'accessory_id' => $p->accessory_id,
                    'product_name' => $p->accessory?->product?->name,
                    'sku'          => $p->accessory_code ?? (string) $p->id,
                    'label'        => trim(($p->accessory?->name ?? '') . ($p->style ? " ({$p->style})" : '')),
                    'stock'        => (int) $p->stock,
                ];
            });

        $supplies = Supply::orderByDesc('is_default_label')->orderBy('id')
            ->get()
            ->map(fn ($s) => [
                'kind'                => 'supply',
                'id'                  => $s->id,
                'product_name'        => null,
                'sku'                 => $s->code,
                'label'               => $s->name . ($s->is_default_label ? ' (default label)' : ''),
                'stock'               => (int) $s->stock,
                'is_default_label'    => (bool) $s->is_default_label,
                'low_stock_threshold' => $s->low_stock_threshold,
            ]);

        return response()->json([
            'variants'    => $variants,
            'accessories' => $accessories,
            'supplies'    => $supplies,
        ]);
    }
}
