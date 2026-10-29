<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * New permission => the one a role must already have to get it: staff
     * who pack and ship orders handle shipments, accountants reconcile COD,
     * store managers connect the shipping partners.
     */
    private const GRANTS = [
        'shipments.view' => 'orders.ship',
        'shipments.create' => 'orders.ship',
        'shipments.update' => 'orders.ship',
        'shipments.cancel' => 'orders.ship',
        'shipments.export' => 'orders.export',
        'cod_reconciliations.view' => 'cashbook.view',
        'cod_reconciliations.create' => 'cashbook.create',
        'cod_reconciliations.approve' => 'cashbook.approve',
        'cod_reconciliations.cancel' => 'cashbook.cancel',
        'cod_reconciliations.export' => 'cashbook.export',
        'carriers.view' => 'orders.ship',
        'carriers.create' => 'settings.update',
        'carriers.update' => 'settings.update',
        'carriers.delete' => 'settings.update',
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
