<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * New permission => the one a role must already have to get it, so
     * existing roles keep doing what they could before suppliers and
     * purchases had their own permissions.
     */
    private const GRANTS = [
        'suppliers.view' => 'inventory.view',
        'suppliers.create' => 'inventory.create',
        'suppliers.update' => 'inventory.update',
        'suppliers.delete' => 'inventory.delete',
        'suppliers.export' => 'inventory.export',
        'suppliers.import' => 'inventory.import',
        'purchases.view' => 'inventory.view',
        'purchases.create' => 'inventory.create',
        'purchases.update' => 'inventory.update',
        'purchases.delete' => 'inventory.delete',
        'purchases.approve' => 'inventory.create',
        'purchases.cancel' => 'inventory.update',
        'purchases.pay' => 'cashbook.create',
        'purchases.export' => 'inventory.export',
    ];

    public function up(): void
    {
        $now = now();

        foreach (array_keys(self::GRANTS) as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }

        $ids = DB::table('permissions')->where('guard_name', 'web')->pluck('id', 'name');

        foreach (self::GRANTS as $new => $base) {
            if (! isset($ids[$new], $ids[$base])) {
                continue;
            }

            $roles = DB::table('role_has_permissions')->where('permission_id', $ids[$base])->pluck('role_id');

            DB::table('role_has_permissions')->insertOrIgnore(
                $roles->map(fn ($role) => ['role_id' => $role, 'permission_id' => $ids[$new]])->all(),
            );
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', array_keys(self::GRANTS))->delete();
    }
};
