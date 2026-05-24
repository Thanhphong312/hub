<?php

namespace App\Http\Controllers;

use App\Models\Accessory;
use App\Models\AccessoryPrice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Models\Tier;
use Illuminate\Http\Request;

/**
 * @group Products
 *
 * Catalog management. Read endpoints mở cho mọi user đã login; write
 * (POST/PUT/DELETE) chỉ admin.
 */
class ProductController extends Controller
{
    /**
     * @OA\Get(
     *     path="/products",
     *     tags={"Products"},
     *     summary="List products",
     *     description="Paginated list với variants + tier prices + accessories nested. Dùng khi muốn browse cả catalog 1 lần. Để tìm variant theo SKU, dùng GET /variants nhanh hơn.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="search",   in="query", description="Contains match on product name", @OA\Schema(type="string"), example="Greeting"),
     *     @OA\Parameter(name="status",   in="query", description="1=active, 0=inactive", @OA\Schema(type="integer", enum={0,1})),
     *     @OA\Parameter(name="style",    in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Paginated products with variants + accessories")
     * )
     */
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

    /**
     * @OA\Get(
     *     path="/products/{product}",
     *     tags={"Products"},
     *     summary="Get product (with variants + accessories + tier prices)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="product", in="path", required=true, @OA\Schema(type="integer"), example=1),
     *     @OA\Response(response=200, description="Product full detail",
     *         @OA\JsonContent(
     *             @OA\Property(property="product", type="object",
     *                 @OA\Property(property="id",        type="integer", example=1),
     *                 @OA\Property(property="name",      type="string",  example="Greeting Card"),
     *                 @OA\Property(property="style",     type="string"),
     *                 @OA\Property(property="line_id",   type="string",  example="GC"),
     *                 @OA\Property(property="status",    type="integer", example=1),
     *                 @OA\Property(property="variants",  type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="accessories", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(Product $product)
    {
        return response()->json([
            'product' => $product->load('variants.prices.tier', 'accessories.prices.tier'),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/variants",
     *     tags={"Products"},
     *     summary="Search product variants",
     *     description="Flat list of variants with filters. Use this to look up `product_variant_id` for POST /orders.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="sku",         in="query", description="Exact SKU match", @OA\Schema(type="string"), example="TSHIRT-RED-M"),
     *     @OA\Parameter(name="product_id",  in="query", @OA\Schema(type="integer"), example=1),
     *     @OA\Parameter(name="product_name",in="query", description="Contains match on product name", @OA\Schema(type="string"), example="Greeting"),
     *     @OA\Parameter(name="color",       in="query", description="Exact match", @OA\Schema(type="string")),
     *     @OA\Parameter(name="size",        in="query", description="Exact match", @OA\Schema(type="string")),
     *     @OA\Parameter(name="paper_type",  in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="status",      in="query", description="1=active, 0=inactive", @OA\Schema(type="integer", enum={0,1})),
     *     @OA\Parameter(name="per_page",    in="query", @OA\Schema(type="integer", default=50)),
     *     @OA\Response(response=200, description="Paginated variants",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id",          type="integer", example=42),
     *                     @OA\Property(property="sku",         type="string",  example="TSHIRT-RED-M"),
     *                     @OA\Property(property="color",       type="string",  example="Red"),
     *                     @OA\Property(property="size",        type="string",  example="M"),
     *                     @OA\Property(property="paper_type",  type="string",  nullable=true),
     *                     @OA\Property(property="stock",       type="integer", example=100),
     *                     @OA\Property(property="product",     type="object", @OA\Property(property="id",type="integer"), @OA\Property(property="name",type="string"))
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function searchVariants(Request $request)
    {
        $q = \App\Models\ProductVariant::with('product:id,name,line_id', 'prices.tier:id,name');

        if ($request->filled('sku'))         $q->where('sku', $request->sku);
        if ($request->filled('product_id'))  $q->where('product_id', (int) $request->product_id);
        if ($request->filled('color'))       $q->where('color', $request->color);
        if ($request->filled('size'))        $q->where('size', $request->size);
        if ($request->filled('paper_type'))  $q->where('paper_type', $request->paper_type);
        if ($request->has('status') && $request->status !== '') $q->where('status', (int) $request->status);

        if ($request->filled('product_name')) {
            $q->whereHas('product', fn ($w) => $w->where('name', 'like', '%' . $request->product_name . '%'));
        }

        return response()->json($q->orderBy('product_id')->orderBy('id')->paginate($request->get('per_page', 50)));
    }

    /**
     * @OA\Get(
     *     path="/accessories",
     *     tags={"Products"},
     *     summary="Search accessories (with tier-scoped prices)",
     *     description="Flat list of accessories. Use this to look up `accessory_ids[]` for POST /orders. Returns accessory_prices scoped to caller's tier — chỉ pick `accessory_prices.id` cùng tier seller mới hợp lệ.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="product_id", in="query", @OA\Schema(type="integer"), example=1),
     *     @OA\Parameter(name="name",       in="query", description="Contains match on accessory name", @OA\Schema(type="string"), example="Envelope"),
     *     @OA\Parameter(name="code",       in="query", description="Exact match on any accessory_price.accessory_code", @OA\Schema(type="string"), example="W"),
     *     @OA\Response(response=200, description="Accessories with prices",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id",         type="integer", example=5),
     *                 @OA\Property(property="name",       type="string",  example="Envelope"),
     *                 @OA\Property(property="product_id", type="integer", example=1),
     *                 @OA\Property(property="stock",      type="integer", example=200),
     *                 @OA\Property(property="prices", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="id",             type="integer", example=18, description="accessory_price_id — pass into accessory_ids[]"),
     *                         @OA\Property(property="style",          type="string",  example="White"),
     *                         @OA\Property(property="accessory_code", type="string",  example="W"),
     *                         @OA\Property(property="price",          type="number",  example=0.50),
     *                         @OA\Property(property="tier_id",        type="integer", example=1)
     *                     )
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function searchAccessories(Request $request)
    {
        $callerTierId = $request->user()?->tier_id;

        $q = \App\Models\Accessory::with([
            'product:id,name,line_id',
            // Scope prices to caller's tier — accessory_ids[] from a different
            // tier won't validate when creating an order.
            'prices' => fn ($pq) => $callerTierId
                ? $pq->where('tier_id', $callerTierId)
                : $pq,
            'prices.tier:id,name',
        ]);

        if ($request->filled('product_id')) $q->where('product_id', (int) $request->product_id);
        if ($request->filled('name'))       $q->where('name', 'like', '%' . $request->name . '%');
        if ($request->filled('code')) {
            $q->whereHas('prices', fn ($w) => $w->where('accessory_code', $request->code));
        }

        return response()->json($q->orderBy('product_id')->orderBy('id')->get());
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

    /**
     * @OA\Get(
     *     path="/products/{product}/variants",
     *     tags={"Products"},
     *     summary="List variants of one product",
     *     description="Khi đã biết product_id, lấy nhanh list variants. Để cross-product search dùng GET /variants.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="product", in="path", required=true, @OA\Schema(type="integer"), example=1),
     *     @OA\Parameter(name="color",   in="query", @OA\Schema(type="string"), example="Red"),
     *     @OA\Parameter(name="size",    in="query", @OA\Schema(type="string"), example="M"),
     *     @OA\Response(response=200, description="Variants array (with tier-scoped prices)")
     * )
     */
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

    /**
     * @OA\Get(
     *     path="/products/{product}/prices",
     *     tags={"Products"},
     *     summary="List variant prices for a product",
     *     description="Flat list price rows (1 row per variant × tier × key). Key = `base_cost`, `label_fee`, `shipping_cost`, `addition_fee`, `2nd_fee`.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="product", in="path", required=true, @OA\Schema(type="integer"), example=1),
     *     @OA\Parameter(name="tier_id", in="query", description="Filter by tier", @OA\Schema(type="integer"), example=1),
     *     @OA\Response(response=200, description="Prices array")
     * )
     */
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
