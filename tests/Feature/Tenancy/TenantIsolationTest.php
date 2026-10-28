<?php

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;

beforeEach(function () {
    $this->otherTenant = Tenant::factory()->create();
    $this->tenancy = app(TenantContext::class);
});

test('a store cannot read another store\'s rows', function () {
    $mine = User::factory()->create();
    $theirs = $this->tenancy->run($this->otherTenant, fn () => User::factory()->create());

    expect(User::query()->pluck('id')->all())->toBe([$mine->id])
        ->and(User::query()->find($theirs->id))->toBeNull();

    $this->tenancy->run($this->otherTenant, function () use ($mine, $theirs) {
        expect(User::query()->pluck('id')->all())->toBe([$theirs->id])
            ->and(User::query()->find($mine->id))->toBeNull();
    });
});

test('new rows get the current tenant automatically', function () {
    $setting = $this->tenancy->run($this->otherTenant, fn () => Setting::query()->create(['key' => 'a', 'value' => 1]));

    expect($setting->tenant_id)->toBe($this->otherTenant->id);
});

test('queries return nothing without a tenant in saas mode', function () {
    User::factory()->create();

    config(['tenancy.mode' => 'saas']);
    $this->tenancy->set(null);

    expect(User::query()->count())->toBe(0);
});

test('creating a row without a tenant fails in saas mode', function () {
    config(['tenancy.mode' => 'saas']);
    $this->tenancy->set(null);

    Setting::query()->create(['key' => 'a', 'value' => 1]);
})->throws(RuntimeException::class);

test('saas mode resolves the store from its subdomain', function () {
    config(['tenancy.mode' => 'saas', 'tenancy.central_domain' => 'exso.test']);
    $this->tenancy->set(null);

    $user = $this->tenancy->run($this->otherTenant, fn () => User::factory()->create());

    $this->actingAs($user)
        ->get("http://{$this->otherTenant->slug}.exso.test/dashboard")
        ->assertOk();

    expect($this->tenancy->id())->toBe($this->otherTenant->id);
});

test('saas mode returns 404 for an unknown store', function () {
    config(['tenancy.mode' => 'saas', 'tenancy.central_domain' => 'exso.test']);
    $this->tenancy->set(null);

    $this->get('http://unknown.exso.test/login')->assertNotFound();
});

test('a user of store A cannot log in on store B', function () {
    config(['tenancy.mode' => 'saas', 'tenancy.central_domain' => 'exso.test']);
    $this->tenancy->set(null);

    $user = $this->tenancy->run($this->tenant, fn () => User::factory()->create());

    $this->post("http://{$this->otherTenant->slug}.exso.test/login", [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});
