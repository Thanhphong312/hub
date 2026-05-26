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
}
