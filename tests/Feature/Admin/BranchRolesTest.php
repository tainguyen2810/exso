<?php

use App\Models\Branch;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Staff member with $permissions through a role of their own, working in
 * the default branch.
 *
 * @param  list<string>  $permissions
 */
function staffWithPermissions(array $permissions): User
{
    Role::findOrCreate('Quản lý nhân sự', 'web')->syncPermissions($permissions);

    return staffWithRole('Quản lý nhân sự');
}

/**
 * @return array<string, mixed>
 */
function staffPayload(User $user, array $overrides = []): array
{
    return [
        'name' => $user->name,
        'email' => $user->email,
        'status' => 'active',
        'branch_ids' => $user->branches()->pluck('branches.id')->all(),
        ...$overrides,
    ];
}

test('a role given for one branch only applies while working in that branch', function () {
    $main = Branch::query()->firstOrCreate(['code' => 'CN01'], ['name' => 'Chi nhánh chính', 'is_default' => true]);
    $second = Branch::factory()->create();
    $owner = owner();

    $this->actingAs($owner)->post('/staff', [
        'name' => 'Kế toán Quận 3',
        'email' => 'ketoan@itso.vn',
        'password' => 'matkhau123',
        'password_confirmation' => 'matkhau123',
        'status' => 'active',
        'branch_ids' => [$main->id, $second->id],
        'assignments' => [
            ['role_id' => Role::findByName('Thu ngân')->id, 'branch_id' => null],
            ['role_id' => Role::findByName('Kế toán')->id, 'branch_id' => $second->id],
        ],
    ])->assertRedirect('/staff');

    $member = User::query()->where('email', 'ketoan@itso.vn')->sole();
    expect(RoleAssignment::query()->where('user_id', $member->id)->count())->toBe(2);

    // Default branch first: cashier only.
    $this->actingAs($member)->get('/pos')->assertOk();
    $this->actingAs($member)->get('/cashbook')->assertForbidden();
    $this->actingAs($member)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', fn ($permissions) => ! collect($permissions)->contains('cashbook.view')));

    $this->actingAs($member)->post('/branch/switch', ['branch_id' => $second->id]);
    $member->unsetRelations();

    $this->actingAs($member)->get('/cashbook')->assertOk();
    $this->actingAs($member)->get('/pos')->assertOk();
});

test('a role branch must be one of the member working branches', function () {
    $other = Branch::factory()->create();
    $owner = owner();

    $this->actingAs($owner)->post('/staff', [
        'name' => 'A',
        'email' => 'a@itso.vn',
        'password' => 'matkhau123',
        'password_confirmation' => 'matkhau123',
        'status' => 'active',
        'branch_ids' => $owner->branches()->pluck('branches.id')->all(),
        'assignments' => [['role_id' => Role::findByName('Thu ngân')->id, 'branch_id' => $other->id]],
    ])->assertSessionHasErrors('assignments.0.branch_id');
});

test('data permissions are saved per staff member', function () {
    $member = staffWithRole('Thu ngân');

    $this->actingAs(owner())->put("/staff/{$member->id}", staffPayload($member, [
        'assignments' => [['role_id' => Role::findByName('Thu ngân')->id, 'branch_id' => null]],
        'data_permissions' => ['products.view_cost'],
    ]))->assertRedirect('/staff');

    expect($member->fresh()->can('products.view_cost'))->toBeTrue()
        ->and($member->fresh()->can('inventory.view_transfer_price'))->toBeFalse();
});

test('staff cannot give a role with permissions they do not have', function () {
    $member = staffWithRole('Thu ngân');
    $manager = staffWithPermissions(['staff.view', 'staff.create', 'staff.update', ...Role::findByName('Thu ngân')->permissions->pluck('name')]);

    $this->actingAs($manager)->put("/staff/{$member->id}", staffPayload($member, [
        'assignments' => [['role_id' => Role::findByName('Quản trị')->id, 'branch_id' => null]],
    ]))->assertSessionHasErrors('assignments');

    expect($member->fresh()->hasRole('Quản trị'))->toBeFalse();
});

test('staff cannot give data permissions they do not have', function () {
    $member = staffWithRole('Thu ngân');
    $manager = staffWithPermissions(['staff.view', 'staff.update', ...Role::findByName('Thu ngân')->permissions->pluck('name')]);

    $this->actingAs($manager)->put("/staff/{$member->id}", staffPayload($member, [
        'role_ids' => [Role::findByName('Thu ngân')->id],
        'data_permissions' => ['products.view_cost'],
    ]))->assertSessionHasErrors('data_permissions');
});

test('staff cannot edit, lock or delete themselves or anyone stronger', function () {
    $manager = staffWithPermissions(['staff.view', 'staff.update', 'staff.delete']);
    $admin = staffWithRole('Quản trị');

    $this->actingAs($manager)->put("/staff/{$admin->id}", staffPayload($admin, ['password' => 'chiemquyen1', 'password_confirmation' => 'chiemquyen1']))
        ->assertForbidden();
    $this->actingAs($manager)->put("/staff/{$manager->id}", staffPayload($manager, ['role_ids' => [Role::findByName('Quản trị')->id]]))
        ->assertForbidden();
    $this->actingAs($manager)->delete("/staff/{$admin->id}");
    $this->actingAs($manager)->post('/staff/bulk', ['action' => 'deactivate', 'ids' => [$admin->id]]);

    expect($admin->fresh())->not->toBeNull()
        ->and($admin->fresh()->status)->toBe('active');

    $this->actingAs($manager)->get("/staff/{$admin->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('locked', fn ($locked) => is_string($locked)));
});

test('staff with the role permissions can still manage weaker staff', function () {
    $member = staffWithRole('Thu ngân');
    $manager = staffWithPermissions(['staff.view', 'staff.update', ...Role::findByName('Thu ngân')->permissions->pluck('name')]);

    $this->actingAs($manager)->put("/staff/{$member->id}", staffPayload($member, [
        'name' => 'Tên mới',
        'role_ids' => [Role::findByName('Thu ngân')->id],
    ]))->assertRedirect('/staff');

    expect($member->fresh()->name)->toBe('Tên mới');
});

test('a role editor cannot add permissions they do not have or touch stronger roles', function () {
    $editor = staffWithPermissions(['roles.view', 'roles.update', 'roles.create', 'dashboard.view']);

    $this->actingAs($editor)->post('/roles', ['name' => 'Lén', 'permissions' => ['dashboard.view', 'cashbook.view']])
        ->assertSessionHasErrors('permissions');
    $this->actingAs($editor)->post('/roles', ['name' => 'Xem tổng quan', 'permissions' => ['dashboard.view']])
        ->assertRedirect('/roles');

    $admin = Role::findByName('Quản trị');
    $this->actingAs($editor)->put("/roles/{$admin->id}", ['name' => 'Quản trị', 'permissions' => []])->assertForbidden();
    expect($admin->permissions()->count())->toBeGreaterThan(0);
});

test('data permissions cannot be put in a role', function () {
    $this->actingAs(owner())->post('/roles', ['name' => 'X', 'permissions' => ['products.view_cost']])
        ->assertSessionHasErrors('permissions.0');
});

test('export and import need their own permissions', function () {
    $viewer = staffWithPermissions(['staff.view', 'branches.view', 'branches.create']);

    $this->actingAs($viewer)->get('/staff')->assertOk();
    $this->actingAs($viewer)->get('/staff?export=xlsx')->assertForbidden();
    $this->actingAs($viewer)->get('/branches?export=xlsx')->assertForbidden();
    $this->actingAs($viewer)->get('/branches/import/template')->assertForbidden();

    $this->actingAs(owner())->get('/staff?export=xlsx')->assertOk();
});

test('the role page shows permissions grouped like Sapo with a note', function () {
    $this->actingAs(owner())->get('/roles/create')
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/roles/form')
            ->where('groups.1.label', 'Đơn hàng')
            ->where('groups.1.modules.0.permissions.1.label', 'Xem tất cả đơn'));

    $this->actingAs(owner())->post('/roles', ['name' => 'Ca tối', 'description' => 'Bán hàng ca tối', 'permissions' => ['orders.view']])
        ->assertRedirect('/roles');

    expect(Role::findByName('Ca tối')->description)->toBe('Bán hàng ca tối');
});
