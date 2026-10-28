<?php

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can create a staff member with roles and branches', function () {
    $owner = owner();
    $branch = Branch::factory()->create();
    $cashier = Role::findByName('Thu ngân');

    $this->actingAs($owner)->post('/staff', [
        'name' => 'Nguyễn Văn A',
        'email' => 'a@itso.vn',
        'phone' => '0901234567',
        'password' => 'matkhau123',
        'password_confirmation' => 'matkhau123',
        'status' => 'active',
        'role_ids' => [$cashier->id],
        'branch_ids' => [$branch->id],
    ])->assertRedirect('/staff');

    $member = User::query()->where('email', 'a@itso.vn')->sole();

    expect($member->hasRole('Thu ngân'))->toBeTrue()
        ->and($member->branchIds())->toBe([$branch->id])
        ->and($member->is_owner)->toBeFalse();
});

test('a staff member needs at least one branch', function () {
    $this->actingAs(owner())->post('/staff', [
        'name' => 'B',
        'email' => 'b@itso.vn',
        'password' => 'matkhau123',
        'password_confirmation' => 'matkhau123',
        'status' => 'active',
        'branch_ids' => [],
    ])->assertSessionHasErrors('branch_ids');
});

test('the same email may exist in another store', function () {
    $other = Tenant::factory()->create();
    app(TenantContext::class)->run($other, fn () => User::factory()->create(['email' => 'dup@itso.vn']));

    $this->actingAs(owner())->post('/staff', [
        'name' => 'C',
        'email' => 'dup@itso.vn',
        'password' => 'matkhau123',
        'password_confirmation' => 'matkhau123',
        'status' => 'active',
        'branch_ids' => [Branch::query()->value('id')],
    ])->assertSessionHasNoErrors();
});

test('a locked staff member cannot log in', function () {
    $user = staffWithRole('Thu ngân');
    $user->update(['status' => 'inactive']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a staff member locked while logged in is signed out', function () {
    $user = staffWithRole('Thu ngân');

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->update(['status' => 'inactive']);

    $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

test('the owner cannot be locked or deleted', function () {
    $owner = owner();
    $admin = staffWithRole('Quản trị');

    $this->actingAs($admin)->delete("/staff/{$owner->id}");
    expect($owner->fresh())->not->toBeNull();

    // Even a full admin cannot touch the owner's account (password, email...).
    $this->actingAs($admin)->put("/staff/{$owner->id}", [
        'name' => $owner->name,
        'email' => $owner->email,
        'status' => 'inactive',
        'branch_ids' => $owner->branches()->pluck('branches.id')->all(),
    ])->assertForbidden();

    $this->actingAs($owner)->put("/staff/{$owner->id}", [
        'name' => $owner->name,
        'email' => $owner->email,
        'status' => 'inactive',
        'branch_ids' => $owner->branches()->pluck('branches.id')->all(),
    ])->assertSessionHasErrors('status');
});

test('changing a staff member is logged with before and after values, without the password', function () {
    $owner = owner();
    $member = staffWithRole('Thu ngân');

    $this->actingAs($owner)->put("/staff/{$member->id}", [
        'name' => 'Tên mới',
        'email' => $member->email,
        'password' => 'matkhaumoi123',
        'password_confirmation' => 'matkhaumoi123',
        'status' => 'active',
        'branch_ids' => $member->branches()->pluck('branches.id')->all(),
    ])->assertRedirect('/staff');

    $log = ActivityLog::query()->where('event', 'updated')->where('subject_id', $member->id)->latest('id')->firstOrFail();

    expect($log->user_id)->toBe($owner->id)
        ->and($log->properties['old']['name'])->toBe($member->name)
        ->and($log->properties['new']['name'])->toBe('Tên mới')
        ->and($log->properties['new'])->not->toHaveKey('password');

    $this->actingAs($owner)->get('/activity-log')
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/activity-log/index')
            ->where('logs.rows.data.0.description', 'Sửa nhân viên Tên mới'));
});

test('staff of another store are not reachable', function () {
    $other = Tenant::factory()->create();
    $stranger = app(TenantContext::class)->run($other, fn () => User::factory()->create());

    $this->actingAs(owner())->get("/staff/{$stranger->id}/edit")->assertNotFound();
});
