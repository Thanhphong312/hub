<?php

namespace App\Http\Controllers;

use App\Models\Accessory;
use App\Models\AccessoryPrice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Models\Tier;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with('variants.prices.tier', 'accessories.prices.tier');

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('style')) {
            $query->where('style', $request->style);
        }

        $products = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 20));
        return response()->json($products);
    }

    public function show(Product $product)
    {
        return response()->json([
            'product' => $product->load('variants.prices.tier', 'accessories.prices.tier'),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'style' => 'nullable|string',
            'line_id' => 'nullable|string|max:16',
        ]);

        $product = Product::create($request->only('name', 'style', 'line_id'));
        return response()->json(['message' => 'Product created', 'product' => $product], 201);
    }

    public function update(Request $request, Product $product)
    {
        $request->validate([
            'name' => 'nullable|string',
            'style' => 'nullable|string',
            'line_id' => 'nullable|string|max:16',
            'status' => 'nullable|integer|in:0,1',
        ]);

        $product->update($request->only('name', 'style', 'line_id', 'status'));
        return response()->json(['message' => 'Product updated', 'product' => $product]);
    }

    public function destroy(Product $product)
    {
        $product->delete();
        return response()->json(['message' => 'Product deleted']);
    }

    // List variants for a product
    public function variants(Product $product, Request $request)
    {
        $query = $product->variants()->with('prices.tier');

        if ($request->has('color')) {
            $query->where('color', $request->color);
        }
        if ($request->has('size')) {
            $query->where('size', $request->size);
        }

        return response()->json($query->get());
    }

    public function storeVariant(Request $request, Product $product)
    {
        $request->validate([
            'sku' => 'nullable|string|unique:product_variants,sku',
            'color' => 'nullable|string',
            'size' => 'nullable|string',
            'paper_type' => 'nullable|string',
            'weight' => 'nullable|numeric',
            'length' => 'nullable|numeric',
            'width' => 'nullable|numeric',
            'height' => 'nullable|numeric',
        ]);

        $variant = $product->variants()->create($request->only('sku', 'color', 'size', 'paper_type', 'weight', 'length', 'width', 'height'));
        return response()->json(['message' => 'Variant created', 'variant' => $variant], 201);
    }

    // Bulk create variants
    public function bulkStoreVariants(Request $request, Product $product)
    {
        $request->validate([
            'variants' => 'required|array|min:1',
            'variants.*.sku' => 'nullable|string|distinct|unique:product_variants,sku',
            'variants.*.color' => 'nullable|string',
            'variants.*.size' => 'nullable|string',
            'variants.*.paper_type' => 'nullable|string',
            'variants.*.weight' => 'nullable|numeric',
            'variants.*.length' => 'nullable|numeric',
            'variants.*.width' => 'nullable|numeric',
            'variants.*.height' => 'nullable|numeric',
        ]);

        $created = [];
        foreach ($request->variants as $variantData) {
            $created[] = $product->variants()->create($variantData);
        }

        return response()->json(['message' => count($created) . ' variants created', 'variants' => $created], 201);
    }

    public function updateVariant(Request $request, ProductVariant $variant)
    {
        $request->validate([
            'sku' => 'nullable|string|unique:product_variants,sku,' . $variant->id,
        ]);
        $variant->update($request->only('sku', 'color', 'size', 'paper_type', 'weight', 'length', 'width', 'height', 'status'));
        return response()->json(['message' => 'Variant updated', 'variant' => $variant]);
    }

    public function destroyVariant(ProductVariant $variant)
    {
        $variant->delete();
        return response()->json(['message' => 'Variant deleted']);
    }

    // List prices for a product
    public function prices(Product $product, Request $request)
    {
        $query = ProductVariantPrice::whereHas('variant', function ($q) use ($product) {
            $q->where('product_id', $product->id);
        })->with(['variant', 'tier']);

        if ($request->has('tier_id')) {
            $query->where('tier_id', $request->tier_id);
        }

        return response()->json($query->get());
    }

    // Import prices - bulk upsert
    public function importPrices(Request $request)
    {
        $validKeys = implode(',', ProductVariantPrice::KEYS);
        $request->validate([
            'prices' => 'required|array',
            'prices.*.product_variant_id' => 'required|exists:product_variants,id',
            'prices.*.tier_id' => 'required|exists:tiers,id',
            'prices.*.key' => "required|in:{$validKeys}",
            'prices.*.price' => 'required|numeric|min:0',
        ]);

        $count = 0;
        foreach ($request->prices as $priceData) {
            ProductVariantPrice::updateOrCreate(
                [
                    'product_variant_id' => $priceData['product_variant_id'],
                    'tier_id' => $priceData['tier_id'],
                    'key' => $priceData['key'],
                ],
                ['price' => $priceData['price']]
            );
            $count++;
        }

        return response()->json(['message' => "{$count} prices imported"]);
    }

    // Import prices via CSV
    public function importPricesCsv(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt']);

        $file = $request->file('file');
        $rows = array_map('str_getcsv', file($file->getPathname()));
        $header = array_map('trim', array_shift($rows));

        $count = 0;
        $errors = [];
        $validKeys = ProductVariantPrice::KEYS;

        foreach ($rows as $index => $row) {
            $lineNum = $index + 2;
            if (count($row) !== count($header)) {
                $errors[] = "Line {$lineNum}: column count mismatch";
                continue;
            }
            $data = array_combine($header, $row);

            if (empty($data['product_variant_id']) || empty($data['tier_id']) || empty($data['key']) || !isset($data['price'])) {
                $errors[] = "Line {$lineNum}: missing required fields (product_variant_id, tier_id, key, price)";
                continue;
            }

            if (!in_array($data['key'], $validKeys)) {
                $errors[] = "Line {$lineNum}: invalid key '{$data['key']}'. Must be: " . implode(', ', $validKeys);
                continue;
            }

            if ($data['price'] === '') continue; // skip empty prices

            ProductVariantPrice::updateOrCreate(
                [
                    'product_variant_id' => (int)$data['product_variant_id'],
                    'tier_id' => (int)$data['tier_id'],
                    'key' => $data['key'],
                ],
                ['price' => (float)$data['price']]
            );
            $count++;
        }

        return response()->json([
            'message' => "{$count} prices imported",
            'error_count' => count($errors),
            'errors' => $errors,
        ]);
    }

    // Export all variant x tier x key combinations as CSV (ready to import back)
    public function exportPrices(Request $request)
    {
        $tiers = Tier::orderBy('id')->get();
        $keys = ProductVariantPrice::KEYS;

        $variantQuery = ProductVariant::with('product');
        if ($request->product_id) {
            $variantQuery->where('product_id', $request->product_id);
        }
        $variants = $variantQuery->orderBy('product_id')->orderBy('id')->get();

        // Build existing prices lookup: "variantId-tierId-key" => price
        $variantIds = $variants->pluck('id');
        $existingPrices = ProductVariantPrice::whereIn('product_variant_id', $variantIds)
            ->get()
            ->keyBy(fn($p) => $p->product_variant_id . '-' . $p->tier_id . '-' . $p->key);

        $callback = function () use ($variants, $tiers, $keys, $existingPrices) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['product_variant_id', 'product_name', 'variant_color', 'variant_size', 'tier_id', 'tier_name', 'key', 'price']);

            foreach ($variants as $v) {
                foreach ($tiers as $tier) {
                    foreach ($keys as $key) {
                        $lookup = $v->id . '-' . $tier->id . '-' . $key;
                        $price = isset($existingPrices[$lookup]) ? $existingPrices[$lookup]->price : '';
                        fputcsv($file, [
                            $v->id,
                            $v->product?->name ?? '',
                            $v->color ?? '',
                            $v->size ?? '',
                            $tier->id,
                            $tier->name,
                            $key,
                            $price,
                        ]);
                    }
                }
            }
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="prices_export.csv"',
        ]);
    }

    // Export price CSV template with all variant x tier x key rows (price empty)
    public function exportPriceTemplate(Request $request)
    {
        $tiers = Tier::orderBy('id')->get();
        $keys = ProductVariantPrice::KEYS;

        $variantQuery = ProductVariant::with('product');
        if ($request->product_id) {
            $variantQuery->where('product_id', $request->product_id);
        }
        $variants = $variantQuery->orderBy('product_id')->orderBy('id')->get();

        $callback = function () use ($variants, $tiers, $keys) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['product_variant_id', 'product_name', 'variant_color', 'variant_size', 'tier_id', 'tier_name', 'key', 'price']);

            foreach ($variants as $v) {
                foreach ($tiers as $tier) {
                    foreach ($keys as $key) {
                        fputcsv($file, [
                            $v->id,
                            $v->product?->name ?? '',
                            $v->color ?? '',
                            $v->size ?? '',
                            $tier->id,
                            $tier->name,
                            $key,
                            '',
                        ]);
                    }
                }
            }
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="price_template.csv"',
        ]);
    }

    // Accessories
    public function storeAccessory(Request $request, Product $product)
    {
        $request->validate(['name' => 'required|string']);

        $accessory = $product->accessories()->create(['name' => $request->name]);
        return response()->json([
            'message' => 'Accessory created',
            'accessory' => $accessory->load('prices.tier'),
        ], 201);
    }

    public function updateAccessory(Request $request, Accessory $accessory)
    {
        $request->validate(['name' => 'required|string']);

        $accessory->update(['name' => $request->name]);
        return response()->json(['message' => 'Accessory updated', 'accessory' => $accessory]);
    }

    public function destroyAccessory(Accessory $accessory)
    {
        $accessory->delete();
        return response()->json(['message' => 'Accessory deleted']);
    }

    // Accessory prices (per tier, optional style)
    public function storeAccessoryPrice(Request $request, Accessory $accessory)
    {
        $request->validate([
            'tier_id' => 'required|exists:tiers,id',
            'style' => 'nullable|string',
            'accessory_code' => 'nullable|string',
            'price' => 'required|numeric|min:0',
        ]);

        $price = AccessoryPrice::updateOrCreate(
            [
                'accessory_id' => $accessory->id,
                'tier_id' => $request->tier_id,
                'style' => $request->style,
            ],
            [
                'accessory_code' => $request->accessory_code,
                'price' => $request->price,
            ]
        );

        return response()->json([
            'message' => 'Accessory price saved',
            'price' => $price->load('tier'),
        ], 201);
    }

    public function updateAccessoryPrice(Request $request, AccessoryPrice $price)
    {
        $request->validate([
            'tier_id' => 'nullable|exists:tiers,id',
            'style' => 'nullable|string',
            'accessory_code' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
        ]);

        $price->update($request->only('tier_id', 'style', 'accessory_code', 'price'));
        return response()->json([
            'message' => 'Accessory price updated',
            'price' => $price->fresh()->load('tier'),
        ]);
    }

    public function destroyAccessoryPrice(AccessoryPrice $price)
    {
        $price->delete();
        return response()->json(['message' => 'Accessory price deleted']);
    }
}
