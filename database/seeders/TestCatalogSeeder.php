<?php

namespace Database\Seeders;

use App\Models\Accessory;
use App\Models\AccessoryPrice;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Models\Tier;
use Illuminate\Database\Seeder;

/**
 * Idempotent catalog seeder for dev/QA. Run with `php artisan db:seed
 * --class=TestCatalogSeeder`. Creates one Greeting Card product (line PS_C)
 * with 3 variants, 5 tier-1 price rows per variant, 2 materials, 1
 * accessory (Envelope) with 2 styles. All lookups use firstOrCreate so
 * re-running is safe.
 */
class TestCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Spread test prices across EVERY tier currently in the DB so the
        // seeded catalog is usable regardless of which tier the test seller
        // happens to be on. Default tier names differ between deploys
        // ("Tier 1" vs "Public" etc.), so looking up by name is fragile —
        // seed against the full set instead.
        $tiers = Tier::all();
        if ($tiers->isEmpty()) {
            $tiers = collect([Tier::firstOrCreate(['name' => 'Default'])]);
        }

        // ── Product + variants ──────────────────────────────────────
        $product = Product::firstOrCreate(
            ['name' => 'Greeting Card (Test)'],
            ['style' => 'standard', 'line_id' => 'PS_C', 'status' => 1]
        );

        $variantsSpec = [
            ['sku' => 'PS_C-RED-S',  'color' => 'Red',   'size' => 'Small',  'paper_type' => 'Matte 250gsm'],
            ['sku' => 'PS_C-RED-M',  'color' => 'Red',   'size' => 'Medium', 'paper_type' => 'Matte 250gsm'],
            ['sku' => 'PS_C-BLUE-M', 'color' => 'Blue',  'size' => 'Medium', 'paper_type' => 'Gloss 300gsm'],
        ];

        // Price rows per variant (Tier 1). Tweak here to retune the test set.
        $priceMatrix = [
            'base_cost'     => 1.50,
            'label_fee'     => 0.30,
            'shipping_cost' => 4.99,
            'addition_fee'  => 1.00,
            '2nd_fee'       => 0.50,
        ];

        foreach ($variantsSpec as $v) {
            $variant = ProductVariant::firstOrCreate(
                ['sku' => $v['sku']],
                array_merge($v, ['product_id' => $product->id, 'status' => 1, 'stock' => 1000])
            );

            foreach ($tiers as $t) {
                foreach ($priceMatrix as $key => $price) {
                    ProductVariantPrice::firstOrCreate(
                        [
                            'product_variant_id' => $variant->id,
                            'tier_id'            => $t->id,
                            'key'                => $key,
                        ],
                        ['price' => $price]
                    );
                }
            }
        }

        // ── Materials ────────────────────────────────────────────────
        Material::firstOrCreate(
            ['product_id' => $product->id, 'name' => 'Matte 250gsm'],
            ['description' => 'Mịn, không bóng — phổ thông',          'is_default' => true]
        );
        Material::firstOrCreate(
            ['product_id' => $product->id, 'name' => 'Gloss 300gsm'],
            ['description' => 'Bóng, dày, dùng cho thiệp cao cấp',     'is_default' => false]
        );

        // ── Accessory (Envelope) with 2 styles ──────────────────────
        $envelope = Accessory::firstOrCreate(
            ['product_id' => $product->id, 'name' => 'Envelope'],
            ['stock' => 1000, 'gangsheet_split' => false]
        );

        $accessoryStyles = [
            ['style' => 'White', 'accessory_code' => 'W', 'price' => 0.20],
            ['style' => 'Kraft', 'accessory_code' => 'K', 'price' => 0.25],
        ];
        foreach ($tiers as $t) {
            foreach ($accessoryStyles as $row) {
                AccessoryPrice::firstOrCreate(
                    [
                        'accessory_id'   => $envelope->id,
                        'tier_id'        => $t->id,
                        'accessory_code' => $row['accessory_code'],
                    ],
                    ['style' => $row['style'], 'price' => $row['price'], 'stock' => 1000]
                );
            }
        }

        $this->command->info('TestCatalogSeeder: 1 product, ' . count($variantsSpec) . ' variants, 2 materials, 1 accessory (2 styles).');

        // Pretty-print the IDs so the QA can paste them straight into curl.
        $vRows = ProductVariant::where('product_id', $product->id)
            ->get(['id', 'sku'])->map(fn($v) => "variant_id={$v->id}  sku={$v->sku}");
        $mRows = Material::where('product_id', $product->id)
            ->get(['id', 'name'])->map(fn($m) => "material_id={$m->id}  name={$m->name}");
        $aRows = AccessoryPrice::whereIn('accessory_id',
            Accessory::where('product_id', $product->id)->pluck('id')
        )->get(['id', 'accessory_code'])->map(fn($a) => "accessory_price_id={$a->id}  code={$a->accessory_code}");

        $this->command->line('product_id=' . $product->id);
        foreach ($vRows as $line) $this->command->line('  ' . $line);
        foreach ($mRows as $line) $this->command->line('  ' . $line);
        foreach ($aRows as $line) $this->command->line('  ' . $line);
    }
}
