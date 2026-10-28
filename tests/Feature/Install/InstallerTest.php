<?php

use App\Installer\Installer;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->marker = storage_path('framework/testing/installed.json');
    File::delete($this->marker);

    config(['installer.installed' => null, 'installer.marker' => $this->marker]);
});

afterEach(function () {
    File::delete($this->marker);
});

test('every page redirects to the installer until the app is installed', function () {
    $this->get('/login')->assertRedirect('/install');
    $this->get('/install')->assertOk()->assertSee('Kiểm tra máy chủ');
});

test('the installer is locked once installed', function () {
    config(['installer.installed' => true]);

    $this->get('/install')->assertNotFound();
});

test('install creates the store, its settings and an admin who can log in', function () {
    Tenant::query()->delete();
    app(TenantContext::class)->set(null);

    $admin = app(Installer::class)->install([
        'store_name' => 'ITSO',
        'admin_name' => 'Quản trị',
        'admin_email' => 'admin@itso.vn',
        'admin_password' => 'matkhau123',
    ]);

    expect(File::exists($this->marker))->toBeTrue()
        ->and(Tenant::query()->sole()->name)->toBe('ITSO')
        ->and($admin->tenant_id)->toBe(Tenant::query()->sole()->id)
        ->and(settings('store.name'))->toBe('ITSO');

    $this->post('/login', ['email' => 'admin@itso.vn', 'password' => 'matkhau123'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs(User::query()->sole());
});

test('install shows an error when MySQL cannot be reached', function () {
    $this->from('/install')->post('/install', [
        'db_host' => '127.0.0.1',
        'db_port' => 1,
        'db_database' => 'exso',
        'db_username' => 'root',
        'db_password' => '',
        'store_name' => 'ITSO',
        'admin_name' => 'Quản trị',
        'admin_email' => 'admin@itso.vn',
        'admin_password' => 'matkhau123',
        'admin_password_confirmation' => 'matkhau123',
    ])->assertRedirect('/install')->assertSessionHasErrors('db_host');

    expect(File::exists($this->marker))->toBeFalse();
})->skip(fn () => ! app(Installer::class)->meetsRequirements(), 'Server requirements not met in this environment.');
