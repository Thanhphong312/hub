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
