<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create roles
        $admin = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $seller = Role::create(['name' => 'Seller', 'slug' => 'seller']);
        $support = Role::create(['name' => 'Support', 'slug' => 'support']);

        // Create default tiers
        Tier::create(['name' => 'Tier 1']);
        Tier::create(['name' => 'Tier 2']);
        Tier::create(['name' => 'Tier 3']);

        // Admin permissions - full access
        $modules = ['orders', 'products', 'dashboard', 'wallet', 'users', 'settings'];
        foreach ($modules as $module) {
            Permission::create([
                'role_id' => $admin->id,
                'module' => $module,
                'can_view' => true,
                'can_create' => true,
                'can_edit' => true,
                'can_delete' => true,
            ]);
        }

        // Seller permissions
        foreach (['orders', 'dashboard', 'wallet', 'products'] as $module) {
            Permission::create([
                'role_id' => $seller->id,
                'module' => $module,
                'can_view' => true,
                'can_create' => $module === 'orders',
                'can_edit' => false,
                'can_delete' => false,
            ]);
        }

        // Support permissions
        foreach (['orders', 'dashboard'] as $module) {
            Permission::create([
                'role_id' => $support->id,
                'module' => $module,
                'can_view' => true,
                'can_create' => false,
                'can_edit' => $module === 'orders',
                'can_delete' => false,
            ]);
        }

        // Create admin user
        User::create([
            'name' => 'Admin',
            'email' => 'admin@bullstart.com',
            'password' => 'password',
            'role_id' => $admin->id,
            'tier_id' => 1,
            'wallet' => 1000,
            'api_key' => 'bsk_' . Str::random(32),
        ]);

        // Create test seller
        User::create([
            'name' => 'Seller Test',
            'email' => 'seller@bullstart.com',
            'password' => 'password',
            'role_id' => $seller->id,
            'tier_id' => 1,
            'wallet' => 500,
            'api_key' => 'bsk_' . Str::random(32),
        ]);
    }
}
