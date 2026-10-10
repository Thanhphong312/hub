<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tier;
use Illuminate\Http\Request;


class SettingController extends Controller
{
    // Roles
    public function roles()
    {
        return response()->json(Role::with('permissions')->get());
    }

    public function storeRole(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'slug' => 'required|string|unique:roles',
        ]);

        $role = Role::create($request->only('name', 'slug'));
        return response()->json(['message' => 'Role created', 'role' => $role], 201);
    }

    public function updateRole(Request $request, Role $role)
    {
        $request->validate([
            'name' => 'nullable|string',
            'slug' => 'nullable|string|unique:roles,slug,' . $role->id,
        ]);

        $role->update($request->only('name', 'slug'));
        return response()->json(['message' => 'Role updated', 'role' => $role]);
    }

    public function destroyRole(Role $role)
    {
        if ($role->users()->exists()) {
            return response()->json(['message' => 'Cannot delete role with users'], 400);
        }
        $role->delete();
        return response()->json(['message' => 'Role deleted']);
    }

    // Permissions
    public function updatePermissions(Request $request, Role $role)
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*.module' => 'required|string',
            'permissions.*.can_view' => 'boolean',
            'permissions.*.can_create' => 'boolean',
            'permissions.*.can_edit' => 'boolean',
            'permissions.*.can_delete' => 'boolean',
        ]);

        foreach ($request->permissions as $perm) {
            Permission::updateOrCreate(
                ['role_id' => $role->id, 'module' => $perm['module']],
                [
                    'can_view' => $perm['can_view'] ?? false,
                    'can_create' => $perm['can_create'] ?? false,
                    'can_edit' => $perm['can_edit'] ?? false,
                    'can_delete' => $perm['can_delete'] ?? false,
                ]
            );
        }

        return response()->json(['message' => 'Permissions updated', 'role' => $role->load('permissions')]);
    }

    // Available modules for permission setup
    public function modules()
    {
        return response()->json([
            'modules' => ['orders', 'products', 'dashboard', 'wallet', 'users', 'settings'],
        ]);
    }

    // Tiers
    public function tiers()
    {
        return response()->json(Tier::withCount('users')->get());
    }

    public function storeTier(Request $request)
    {
        $request->validate(['name' => 'required|string']);
        $tier = Tier::create($request->only('name'));
        return response()->json(['message' => 'Tier created', 'tier' => $tier], 201);
    }

    public function updateTier(Request $request, Tier $tier)
    {
        $request->validate(['name' => 'required|string']);
        $tier->update($request->only('name'));
        return response()->json(['message' => 'Tier updated', 'tier' => $tier]);
    }

    public function destroyTier(Tier $tier)
    {
        if ($tier->users()->exists()) {
            return response()->json(['message' => 'Cannot delete tier with users'], 400);
        }
        $tier->delete();
        return response()->json(['message' => 'Tier deleted']);
    }

    // ----------------------------------------------------------------------
    // Invoice payment info — shown on the commercial invoice PDF. Two
    // variants today (PingPong = USD international transfer, VNPay = VND
    // QR transfer); admin edits both in the Settings → Invoice Payment tab.
    // ----------------------------------------------------------------------

    public function getInvoicePayment()
    {
        return response()->json([
            'pingpong' => AppSetting::getJson('invoice_pingpong', [
                'account_name'   => '',
                'account_number' => '',
                'bank_name'      => '',
                'swift_code'     => '',
                'bank_address'   => '',
                'notes'          => '',
            ]),
            'vnpay' => AppSetting::getJson('invoice_vnpay', [
                'qr_url'         => '',
                'account_name'   => '',
                'account_number' => '',
                'bank_name'      => '',
                'notes'          => '',
            ]),
        ]);
    }

    public function updateInvoicePayment(Request $request)
    {
        $data = $request->validate([
            'pingpong' => 'sometimes|array',
            'pingpong.account_name'   => 'nullable|string|max:200',
            'pingpong.account_number' => 'nullable|string|max:64',
            'pingpong.bank_name'      => 'nullable|string|max:200',
            'pingpong.swift_code'     => 'nullable|string|max:32',
            'pingpong.bank_address'   => 'nullable|string|max:500',
            'pingpong.notes'          => 'nullable|string|max:500',

            'vnpay' => 'sometimes|array',
            'vnpay.qr_url'         => 'nullable|url|max:1024',
            'vnpay.account_name'   => 'nullable|string|max:200',
            'vnpay.account_number' => 'nullable|string|max:64',
            'vnpay.bank_name'      => 'nullable|string|max:200',
            'vnpay.notes'          => 'nullable|string|max:500',
        ]);

        if (isset($data['pingpong'])) AppSetting::setJson('invoice_pingpong', $data['pingpong']);
        if (isset($data['vnpay']))    AppSetting::setJson('invoice_vnpay',    $data['vnpay']);

        return $this->getInvoicePayment();
    }

    // ----------------------------------------------------------------------
    // VNPay merchant gateway — credentials + exchange rate. Admin only via
    // the wrapping route group. hash_secret is sensitive: it's stored in
    // app_settings but never travels to the renderer outside this endpoint.
    // VnpayController reads these settings as overrides for config/services.vnpay
    // so existing env-based installs keep working.
    // ----------------------------------------------------------------------

    // ----------------------------------------------------------------------
    // Stamp shipping config — fee + item limit. GET readable by any auth user
    // (OrderCreate needs it for the fee preview + limit); PUT admin only.
    // ----------------------------------------------------------------------

    // ----------------------------------------------------------------------
    // Shippo config — API tokens (test/live), test-mode toggle, the from/return
    // address, a default parcel, and the rate-pick strategy. Used to buy labels
    // for seller_ship orders. Tokens are NEVER returned to the client (only
    // has_*_token booleans); a blank token on update keeps the stored one.
    // ----------------------------------------------------------------------
    public function getShippoConfig()
    {
        $c    = AppSetting::getJson('shippo_config', []);
        $from = $c['from_address'] ?? [];
        $p    = $c['parcel'] ?? [];
        $r    = $c['rate'] ?? [];
        return response()->json([
            'test_mode'      => $c['test_mode'] ?? true,
            'has_test_token' => !empty($c['test_token']),
            'has_live_token' => !empty($c['live_token']),
            'from_address'   => [
                'name'    => $from['name']    ?? 'BullStart',
                'company' => $from['company'] ?? 'BullStart',
                'street1' => $from['street1'] ?? '4353 Saddle Horn Way',
                'street2' => $from['street2'] ?? '',
                'city'    => $from['city']    ?? 'Oceanside',
                'state'   => $from['state']   ?? 'CA',
                'zip'     => $from['zip']     ?? '92057',
                'country' => $from['country'] ?? 'US',
                'phone'   => $from['phone']   ?? '',
                'email'   => $from['email']   ?? '',
            ],
            'parcel' => [
                'length'        => $p['length']        ?? 10,
                'width'         => $p['width']         ?? 7,
                'height'        => $p['height']        ?? 1,
                'distance_unit' => $p['distance_unit'] ?? 'in',
                'weight'        => $p['weight']        ?? 5,
                'mass_unit'     => $p['mass_unit']     ?? 'oz',
            ],
            'rate' => [
                'strategy'     => $r['strategy']     ?? 'cheapest',   // cheapest | carrier | service
                'carrier'      => $r['carrier']      ?? '',           // e.g. usps
                'servicelevel' => $r['servicelevel'] ?? '',           // e.g. usps_ground_advantage
            ],
        ]);
    }

    public function updateShippoConfig(Request $request)
    {
        $data = $request->validate([
            'test_mode'    => 'sometimes|boolean',
            'test_token'   => 'sometimes|nullable|string|max:255',
            'live_token'   => 'sometimes|nullable|string|max:255',
            'from_address' => 'sometimes|array',
            'parcel'       => 'sometimes|array',
            'rate'         => 'sometimes|array',
        ]);

        $current = AppSetting::getJson('shippo_config', []);
        if (array_key_exists('test_mode', $data)) {
            $current['test_mode'] = (bool) $data['test_mode'];
        }
        // Only overwrite a token when a non-empty value is provided (blank = keep).
        if (!empty($data['test_token'])) $current['test_token'] = trim($data['test_token']);
        if (!empty($data['live_token'])) $current['live_token'] = trim($data['live_token']);
        if (isset($data['from_address'])) $current['from_address'] = array_merge($current['from_address'] ?? [], $data['from_address']);
        if (isset($data['parcel']))       $current['parcel']       = array_merge($current['parcel'] ?? [], $data['parcel']);
        if (isset($data['rate']))         $current['rate']         = array_merge($current['rate'] ?? [], $data['rate']);
        AppSetting::setJson('shippo_config', $current);

        return $this->getShippoConfig();
    }

    // ----------------------------------------------------------------------
    // Order types — a configurable list of product order-type labels. Each
    // product is assigned one (products.order_type); the app's item "Type"
    // column shows the product's value. Readable by any auth user (Products
    // page dropdown); admin edits the list.
    // ----------------------------------------------------------------------
    public function getOrderTypes()
    {
        $list = AppSetting::getJson('order_types', ['Greeting Card', 'Pass Sleeve']);
        return response()->json(['order_types' => array_values(array_filter(array_map('strval', (array) $list)))]);
    }

    public function updateOrderTypes(Request $request)
    {
        $data = $request->validate([
            'order_types'   => 'present|array',
            'order_types.*' => 'string|max:64',
        ]);
        // De-dupe + trim + drop blanks, keep order.
        $seen = [];
        $clean = [];
        foreach ($data['order_types'] as $t) {
            $t = trim($t);
            if ($t === '' || isset($seen[$t])) continue;
            $seen[$t] = true;
            $clean[] = $t;
        }
        AppSetting::setJson('order_types', $clean);
        return response()->json(['order_types' => $clean]);
    }

    // ----------------------------------------------------------------------
    // Convert layouts — maps each order_type to the _qr convert flow used for
    // its items. Drives composeImage on the desktop (no more name matching).
    //   default → barcode stamped bottom-left ON the design
    //   outside → design fit above; barcode in a white band OUTSIDE the design
    // ----------------------------------------------------------------------
    public const CONVERT_LAYOUTS = [
        ['key' => 'default', 'label' => 'Mặc định (barcode trên design, góc dưới-trái)'],
        ['key' => 'outside', 'label' => 'Barcode ngoài design (band trắng dưới)'],
        ['key' => 'native',  'label' => 'Giữ nguyên kích thước design (vd greeting card 5x5)'],
        // Pass Sleeve: same card as the skin, band on the RIGHT, and ganged 6
        // distinct designs to a sheet with no duplicate copy underneath.
        ['key' => 'sleeve',  'label' => 'Pass Sleeve (band bên phải, gang 6 cái/trang)'],
        // Sticker Sheet: 11×17 canvas, design scaled to 8×11 flush top-centre,
        // barcode in the blank area below; ganged one 11×17 page per _qr.
        ['key' => 'sticker_sheet', 'label' => 'Sticker Sheet (canvas 11×17, design 8×11 sát trên, barcode bên dưới)'],
        // Kindle Insert: design trimmed + scaled to the variant's W × L inches,
        // a 0.8" band added below L for the barcode.
        ['key' => 'kindle_insert', 'label' => 'Kindle Insert (scale theo size W×L của variant, thêm khúc barcode dưới L)'],
        // Native + band (e.g. Die Cut Sticker): design kept at its own size,
        // a 0.8" band added below for the barcode.
        ['key' => 'native_band', 'label' => 'Giữ nguyên design + thêm khúc barcode bên dưới (vd Die Cut Sticker)'],
    ];

    /** Resolve the convert layout for an order_type label (default when unset). */
    public static function convertLayoutFor(?string $orderType): string
    {
        if (!$orderType) return 'default';
        $map = AppSetting::getJson('convert_layouts', []);
        $val = $map[$orderType] ?? 'default';
        $allowed = array_column(self::CONVERT_LAYOUTS, 'key');
        return in_array($val, $allowed, true) ? $val : 'default';
    }

    public function getConvertLayouts()
    {
        return response()->json([
            'available'   => self::CONVERT_LAYOUTS,
            'order_types' => array_values(array_filter(array_map('strval', (array) AppSetting::getJson('order_types', ['Greeting Card', 'Pass Sleeve'])))),
            'map'         => (object) AppSetting::getJson('convert_layouts', []),
        ]);
    }

    public function updateConvertLayouts(Request $request)
    {
        $allowed = array_column(self::CONVERT_LAYOUTS, 'key');
        $data = $request->validate([
            'map'   => 'present|array',
            'map.*' => ['string', \Illuminate\Validation\Rule::in($allowed)],
        ]);
        // Drop entries pointing to 'default' to keep the map lean.
        $clean = [];
        foreach ($data['map'] as $orderType => $layout) {
            $orderType = trim((string) $orderType);
            if ($orderType === '' || $layout === 'default') continue;
            $clean[$orderType] = $layout;
        }
        AppSetting::setJson('convert_layouts', $clean);
        return $this->getConvertLayouts();
    }

    public function getStampConfig()
    {
        return response()->json(\App\Models\Order::stampConfig());
    }

    public function updateStampConfig(Request $request)
    {
        $data = $request->validate([
            'fee'          => 'nullable|numeric|min:0|max:100',
            'handling_fee' => 'nullable|numeric|min:0|max:100',
            'base_items'   => 'nullable|integer|min:1|max:50',
            'max_items'    => 'nullable|integer|min:1|max:100',
        ]);
        $current = AppSetting::getJson('stamp_config', []);
        AppSetting::setJson('stamp_config', array_merge($current, $data));
        return response()->json(\App\Models\Order::stampConfig());
    }

    // ----------------------------------------------------------------------
    // Push scan price — charged per order the seller pushes for a same-day
    // tracking scan. GET readable by any auth user (Orders confirm dialog +
    // OrderCreate preview); PUT admin only.
    // ----------------------------------------------------------------------
    public function getPushScanConfig()
    {
        return response()->json(\App\Models\PushTracking::config());
    }

    public function updatePushScanConfig(Request $request)
    {
        $data = $request->validate([
            'price' => 'required|numeric|min:0|max:100',
        ]);
        AppSetting::setJson(\App\Models\PushTracking::SETTINGS_KEY, ['price' => round((float) $data['price'], 2)]);
        return response()->json(\App\Models\PushTracking::config());
    }

    // ----------------------------------------------------------------------
    // Resend config —shipping methods + prices, reason cases (+ which gets the
    // base-cost support), and the monthly free-resend quota. Admin-editable.
    // ----------------------------------------------------------------------
    public function getResendConfig()
    {
        return response()->json(\App\Models\Order::resendConfig());
    }

    public function updateResendConfig(Request $request)
    {
        $data = $request->validate([
            'methods'                   => 'present|array',
            'methods.*.key'             => 'required|string|max:32',
            'methods.*.label'           => 'required|string|max:120',
            'methods.*.price'           => 'required|numeric|min:0|max:1000',
            'methods.*.ship_type'       => 'required|in:seller_ship,stamp,tiktok_ship',
            'methods.*.desc'            => 'nullable|string|max:255',
            'reasons'                   => 'present|array',
            'reasons.*.key'             => 'required|string|max:32',
            'reasons.*.label'           => 'required|string|max:120',
            'reasons.*.quota_based'     => 'required|boolean',
            'reasons.*.support'             => 'required|array',
            'reasons.*.support.base_cost'  => 'nullable|numeric|min:0|max:100',
            'reasons.*.support.2nd_fee'    => 'nullable|numeric|min:0|max:100',
            'reasons.*.support.accessory'  => 'nullable|numeric|min:0|max:100',
            'quota'                     => 'required|array',
            'quota.default_free'        => 'required|integer|min:0|max:1000',
            'quota.after_free_support_pct' => 'required|numeric|min:0|max:100',
        ]);
        AppSetting::setJson('resend_config', $data);
        return response()->json(\App\Models\Order::resendConfig());
    }

    // ----------------------------------------------------------------------
    // QR portal behaviour. auto_ship_print: when opening /qr/{systemId} for a
    // not-yet-shipped order, automatically mark it shipped then print the label
    // (scan-to-fulfil workflow). Toggled from the desktop Settings page; the
    // blade reads the same app_settings key directly.
    // ----------------------------------------------------------------------
    public function getQrConfig()
    {
        return response()->json(AppSetting::getJson('qr_config', [
            'auto_ship_print' => false,
        ]));
    }

    public function updateQrConfig(Request $request)
    {
        $data = $request->validate([
            'auto_ship_print' => 'required|boolean',
        ]);
        $current = AppSetting::getJson('qr_config', []);
        AppSetting::setJson('qr_config', array_merge($current, $data));
        return $this->getQrConfig();
    }

    public function getVnpayMerchant()
    {
        $merchant = AppSetting::getJson('vnpay_merchant', []);
        $merchant = array_merge([
            'env'                    => 'sandbox',
            'sandbox_tmn_code'       => '',
            'sandbox_hash_secret'    => '',
            'production_tmn_code'    => '',
            'production_hash_secret' => '',
            'return_url'             => '',
        ], $merchant);

        $rate = AppSetting::getJson('wallet_exchange_rate', [
            'vnd_per_usd' => (float) config('services.vnpay.rate'),
        ]);

        // Suggested IPN URL to register in the VNPay merchant portal — derived
        // from the app URL so the admin can copy it straight out.
        $base = rtrim(config('app.url') ?: 'https://bullstart.us', '/');
        return response()->json([
            'merchant' => $merchant,
            'rate'     => $rate,
            'ipn_url'    => $base . '/wallet/vnpay/ipn',
            'return_url' => $base . '/wallet/vnpay/return',
        ]);
    }

    public function updateVnpayMerchant(Request $request)
    {
        $data = $request->validate([
            'merchant'                        => 'sometimes|array',
            'merchant.env'                    => 'nullable|in:sandbox,production',
            'merchant.sandbox_tmn_code'       => 'nullable|string|max:64',
            'merchant.sandbox_hash_secret'    => 'nullable|string|max:200',
            'merchant.production_tmn_code'    => 'nullable|string|max:64',
            'merchant.production_hash_secret' => 'nullable|string|max:200',
            'merchant.return_url'             => 'nullable|url|max:500',
            'rate'                            => 'sometimes|array',
            'rate.vnd_per_usd'                => 'nullable|numeric|min:1000|max:100000',
        ]);

        if (isset($data['merchant'])) {
            $current = AppSetting::getJson('vnpay_merchant', []);
            AppSetting::setJson('vnpay_merchant', array_merge($current, $data['merchant']));
        }
        if (isset($data['rate']['vnd_per_usd'])) {
            AppSetting::setJson('wallet_exchange_rate', ['vnd_per_usd' => (float) $data['rate']['vnd_per_usd']]);
        }

        return $this->getVnpayMerchant();
    }
}
