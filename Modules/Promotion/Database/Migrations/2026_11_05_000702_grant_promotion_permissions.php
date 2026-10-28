<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * New permission => the one a role must already have to get it: store
     * managers (who could change the store settings) manage promotions.
     */
    private const GRANTS = [
        'promotions.view' => 'settings.update',
        'promotions.create' => 'settings.update',
        'promotions.update' => 'settings.update',
        'promotions.delete' => 'settings.update',
        'coupons.view' => 'settings.update',
        'coupons.create' => 'settings.update',
        'coupons.update' => 'settings.update',
        'coupons.delete' => 'settings.update',
        'loyalty.view' => 'customers.view_all',
        'loyalty.update' => 'settings.update',
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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', array_keys(self::GRANTS))->delete();
    }
};
