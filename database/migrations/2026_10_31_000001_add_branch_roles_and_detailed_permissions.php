<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sapo-style permissions:
 * - roles get a note;
 * - a staff member's role can be limited to one branch (role_assignments;
 *   no row, or branch_id null, means all of the member's branches);
 * - new detailed permissions are granted to the roles that already had the
 *   matching basic permission, so nobody loses access on upgrade.
 */
return new class extends Migration
{
    /** New permission => permission whose holders get it on upgrade. */
    private const GRANTS = [
        'orders.view_all' => 'orders.view',
        'orders.cancel' => 'orders.update',
        'orders.ship' => 'orders.update',
        'orders.pay' => 'orders.update',
        'orders.export' => 'orders.view',
        'orders.import' => 'orders.create',
        'customers.view_all' => 'customers.view',
        'customers.export' => 'customers.view',
        'customers.import' => 'customers.create',
        'products.export' => 'products.view',
        'products.import' => 'products.create',
        'inventory.export' => 'inventory.view',
        'inventory.import' => 'inventory.create',
        'cashbook.cancel' => 'cashbook.update',
        'cashbook.export' => 'cashbook.view',
        'reports.export' => 'reports.view',
        'staff.export' => 'staff.view',
        'branches.export' => 'branches.view',
        'branches.import' => 'branches.create',
        'activity_log.export' => 'activity_log.view',
    ];

    /** Data permissions given directly to staff whose roles have the basic permission. */
    private const USER_GRANTS = [
        'products.view_cost' => 'inventory.view',
        'inventory.view_transfer_price' => 'inventory.view',
    ];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->after('guard_name');
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            // branch_id null = all branches; duplicates are prevented by
            // StaffController, which rewrites a member's rows on save.
            $table->index(['tenant_id', 'user_id']);
        });

        $now = now();

        foreach ([...array_keys(self::GRANTS), ...array_keys(self::USER_GRANTS)] as $name) {
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

        foreach (self::USER_GRANTS as $new => $base) {
            if (! isset($ids[$new], $ids[$base])) {
                continue;
            }

            $users = DB::table('model_has_roles')
                ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
                ->where('role_has_permissions.permission_id', $ids[$base])
                ->distinct()
                ->get(['model_has_roles.model_type', 'model_has_roles.model_id', 'model_has_roles.tenant_id']);

            DB::table('model_has_permissions')->insertOrIgnore($users->map(fn ($user) => [
                'permission_id' => $ids[$new],
                'model_type' => $user->model_type,
                'model_id' => $user->model_id,
                'tenant_id' => $user->tenant_id,
            ])->all());
        }

        // Data permissions are per staff member: if a role carries one
        // (an earlier build had products.view_cost as a role action), move
        // it to the role's members.
        foreach (array_keys(self::USER_GRANTS) as $name) {
            if (! isset($ids[$name])) {
                continue;
            }

            $users = DB::table('model_has_roles')
                ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
                ->where('role_has_permissions.permission_id', $ids[$name])
                ->distinct()
                ->get(['model_has_roles.model_type', 'model_has_roles.model_id', 'model_has_roles.tenant_id']);

            DB::table('model_has_permissions')->insertOrIgnore($users->map(fn ($user) => [
                'permission_id' => $ids[$name],
                'model_type' => $user->model_type,
                'model_id' => $user->model_id,
                'tenant_id' => $user->tenant_id,
            ])->all());

            DB::table('role_has_permissions')->where('permission_id', $ids[$name])->delete();
        }

        // Existing roles keep working in all branches.
        $assignments = DB::table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->get(['model_has_roles.tenant_id', 'model_has_roles.model_id', 'model_has_roles.role_id']);

        DB::table('role_assignments')->insertOrIgnore($assignments->map(fn ($row) => [
            'tenant_id' => $row->tenant_id,
            'user_id' => $row->model_id,
            'role_id' => $row->role_id,
            'branch_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
