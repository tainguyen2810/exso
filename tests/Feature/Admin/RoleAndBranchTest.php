<?php

use App\Authorization\Permissions;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

test('the owner can create a role from the permission matrix', function () {
    $this->actingAs(owner())->post('/roles', [
        'name' => 'Thu ngân ca tối',
        'permissions' => ['pos.view', 'pos.create'],
    ])->assertRedirect('/roles');

    $role = Role::findByName('Thu ngân ca tối');

    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['pos.create', 'pos.view'])
        ->and($role->tenant_id)->toBe($this->tenant->id);
});

test('unknown permissions are rejected', function () {
    $this->actingAs(owner())->post('/roles', [
        'name' => 'X',
        'permissions' => ['orders.fly'],
    ])->assertSessionHasErrors('permissions.0');
});

test('a role in use cannot be deleted', function () {
    staffWithRole('Thu ngân');

    $this->actingAs(owner())->delete('/roles/'.Role::findByName('Thu ngân')->id);

    expect(Role::query()->where('name', 'Thu ngân')->exists())->toBeTrue();
});

test('every store gets its own default roles', function () {
    $other = Tenant::factory()->create();
    Permissions::createDefaultRoles($other);

    $mine = Role::query()->pluck('id');
    $theirs = app(TenantContext::class)->run($other, fn () => Role::query()->pluck('id'));

    expect($mine)->toHaveCount(4)
        ->and($theirs)->toHaveCount(4)
        ->and($mine->intersect($theirs))->toBeEmpty();
});

test('only one branch is the default', function () {
    $owner = owner();
    $first = Branch::query()->where('is_default', true)->sole();

    $this->actingAs($owner)->post('/branches', [
        'code' => 'CN02',
        'name' => 'Chi nhánh 2',
        'is_default' => true,
        'status' => 'active',
    ])->assertRedirect('/branches');

    expect($first->fresh()->is_default)->toBeFalse()
        ->and(Branch::query()->where('is_default', true)->sole()->code)->toBe('CN02');
});

test('the default branch cannot be deleted', function () {
    $owner = owner();
    $default = Branch::query()->where('is_default', true)->sole();

    $this->actingAs($owner)->delete("/branches/{$default->id}");

    expect($default->fresh()->trashed())->toBeFalse();
});

test('a user can only switch to their own branches', function () {
    $mine = Branch::factory()->create();
    $other = Branch::factory()->create();
    $user = staffWithRole('Thu ngân', $mine);

    $this->actingAs($user)->post('/branch/switch', ['branch_id' => $other->id])->assertForbidden();
    $this->actingAs($user)->post('/branch/switch', ['branch_id' => $mine->id])->assertRedirect();

    expect(session('branch_id'))->toBe($mine->id);
});
