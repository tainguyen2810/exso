<?php

use App\Installer\Installer;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\File;
use Modules\Inventory\Services\StockLedger;
use Modules\Product\Models\ProductVariant;

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
    $this->get('/install/database')->assertSuccessful();
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
        ->and(settings('store.name'))->toBe('ITSO')
        ->and($admin->is_owner)->toBeTrue()
        ->and($admin->hasRole('Quản trị'))->toBeTrue()
        ->and($admin->branches()->pluck('code')->all())->toBe(['CN01']);

    $this->post('/login', ['email' => 'admin@itso.vn', 'password' => 'matkhau123'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs(User::query()->sole());
});

test('the wizard goes step by step', function () {
    $this->get('/install')->assertOk()->assertSee('Bắt đầu cài đặt');
    $this->get('/install/database')->assertOk()->assertSee('Kết nối cơ sở dữ liệu');
    // No MySQL details yet: back to the database step.
    $this->get('/install/store')->assertRedirect('/install/database');
    $this->post('/install/store', [])->assertRedirect('/install/database');
    // The done page only shows right after installing.
    $this->get('/install/done')->assertRedirect('/login');

    $this->withSession(['install.db' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'exso', 'username' => 'root', 'password' => '']])
        ->get('/install/store')->assertOk()->assertSee('Cài dữ liệu mẫu và tài khoản demo');
})->skip(fn () => ! app(Installer::class)->meetsRequirements(), 'Server requirements not met in this environment.');

test('the done page shows once after installing, then the installer is locked', function () {
    config(['installer.installed' => true]);

    $this->withSession(['install.done' => ['admin_email' => 'admin@itso.vn', 'demo_email' => 'demo@exso.demo', 'demo_domain' => 'exso.demo']])
        ->get('/install/done')->assertOk()->assertSee('Cài đặt thành công')->assertSee('demo@exso.demo');

    $this->flushSession();
    $this->get('/install/done')->assertRedirect('/login');
    $this->get('/install/database')->assertNotFound();
});

test('install with sample data creates demo accounts that sign in with the demo password', function () {
    Tenant::query()->delete();
    app(TenantContext::class)->set(null);

    app(Installer::class)->install([
        'store_name' => 'ITSO',
        'admin_name' => 'Quản trị',
        'admin_email' => 'admin@itso.vn',
        'admin_password' => 'matkhau123',
        'demo_password' => 'demo12345',
    ]);

    app(TenantContext::class)->set(Tenant::query()->sole());
    $demo = User::query()->where('email', 'demo@exso.demo')->sole();

    expect(User::query()->where('email', 'like', '%@exso.demo')->count())->toBe(8)
        ->and($demo->is_owner)->toBeFalse()
        ->and($demo->hasRole('Quản trị'))->toBeTrue()
        ->and(Branch::query()->count())->toBe(2)
        ->and(ProductVariant::query()->count())->toBeGreaterThan(5)
        ->and(app(StockLedger::class)->mismatches())->toBe([]);

    app(TenantContext::class)->set(null);
    $this->post('/login', ['email' => 'demo@exso.demo', 'password' => 'demo12345'])->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk();
});

test('the database step shows an error when MySQL cannot be reached', function () {
    $this->from('/install/database')->post('/install/database', [
        'db_host' => '127.0.0.1',
        'db_port' => 1,
        'db_database' => 'exso',
        'db_username' => 'root',
        'db_password' => '',
    ])->assertRedirect('/install/database')->assertSessionHasErrors('db_host');

    expect(File::exists($this->marker))->toBeFalse();
})->skip(fn () => ! app(Installer::class)->meetsRequirements(), 'Server requirements not met in this environment.');
