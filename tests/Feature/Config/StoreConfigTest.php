<?php

use App\Models\FileLog;
use App\Models\OrderReason;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\SalesSource;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\StoreDefaults;
use App\Support\Settings\StoreSettings;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  list<string>  $permissions
 */
function configStaff(array $permissions): User
{
    $user = staffWithRole('Thu ngân');
    Role::findOrCreate('Cấu hình', 'web')->syncPermissions($permissions);
    $user->syncRoles([]);
    $user->assignRole('Cấu hình');

    return $user->fresh();
}

test('a new store has default taxes, payment methods, sources and reasons', function () {
    expect(TaxRate::query()->pluck('code')->all())->toEqualCanonicalizing(['NHAPHANG', 'KHONGAPDUNG', 'BANHANG'])
        ->and(PaymentMethod::query()->where('is_system', true)->count())->toBe(4)
        ->and(SalesSource::query()->where('is_default', true)->value('code'))->toBe('POS')
        ->and(OrderReason::query()->where('type', 'cancel')->count())->toBeGreaterThan(0)
        ->and(settings('payment.default_pos'))->toBe(PaymentMethod::query()->where('type', 'cash')->value('id'))
        ->and(settings('tax.default_sale'))->toBe('BANHANG');
});

test('installing the defaults again changes nothing', function () {
    TaxRate::query()->where('code', 'BANHANG')->update(['rate_bp' => 800]);

    StoreDefaults::install();

    expect(TaxRate::query()->count())->toBe(3)
        ->and(TaxRate::query()->where('code', 'BANHANG')->value('rate_bp'))->toBe(800)
        ->and(SalesSource::query()->count())->toBe(10);
});

test('settings pages need settings.view and saving needs settings.update', function () {
    $viewer = configStaff(['settings.view']);

    foreach (['/config/store', '/config/taxes', '/config/payment-methods', '/config/sources', '/config/reasons', '/config/sales'] as $url) {
        $this->actingAs($viewer)->get($url)->assertOk();
    }

    $this->actingAs($viewer)->put('/config/sales', ['sales_print_copies' => 2])->assertForbidden();
    $this->actingAs($viewer)->post('/config/taxes', ['name' => 'VAT 8', 'code' => 'VAT8', 'rate' => 8, 'type' => 'sale'])->assertForbidden();
    $this->actingAs($viewer)->post('/config/store', ['name' => 'X'])->assertForbidden();

    $cashier = staffWithRole('Thu ngân');
    $this->actingAs($cashier)->get('/config/taxes')->assertForbidden();
    $this->actingAs($cashier)->get('/config/file-logs')->assertForbidden();
});

test('store info is saved with a logo kept off the public disk', function () {
    Storage::fake('local');
    $owner = owner();

    $this->actingAs($owner)->post('/config/store', [
        'name' => 'ITSO Quận 1',
        'phone' => '0909 123 456',
        'email' => 'shop@itso.vn',
        'address' => '12 Lê Lợi',
        'province' => 'TP Hồ Chí Minh',
        'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
    ])->assertSessionHasNoErrors();

    $path = settings('store.logo');
    expect(settings('store.name'))->toBe('ITSO Quận 1')
        ->and($path)->toStartWith('tenants/'.tenancy()->id().'/logo-');
    Storage::disk('local')->assertExists($path);

    $this->actingAs($owner)->get('/store-logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($owner)->get('/config/store')->assertInertia(fn (Assert $page) => $page
        ->where('store.logo_url', fn ($url) => str_starts_with((string) $url, '/store-logo'))
    );

    // Removing it deletes the file.
    $this->actingAs($owner)->post('/config/store', [
        'name' => 'ITSO Quận 1', 'phone' => '0909123456', 'address' => '12 Lê Lợi', 'remove_logo' => true,
    ])->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($path);
    expect(settings('store.logo'))->toBeNull();
});

test('the logo must be a real image', function () {
    Storage::fake('local');

    $this->actingAs(owner())->post('/config/store', [
        'name' => 'ITSO', 'phone' => '0909123456', 'address' => '12 Lê Lợi',
        'logo' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;'),
    ])->assertSessionHasErrors('logo');

    expect(settings('store.logo'))->toBeNull();
});

test('store info requires name, phone and address', function () {
    $this->actingAs(owner())->post('/config/store', ['phone' => 'abc'])
        ->assertSessionHasErrors(['name', 'phone', 'address']);
});

test('tax rates: add, keep rates in basis points, protect the defaults', function () {
    $owner = owner();

    $this->actingAs($owner)->post('/config/taxes', ['name' => 'Thuế 8%', 'code' => 'vat8', 'rate' => '8.5', 'type' => 'sale'])
        ->assertSessionHasNoErrors();
    $rate = TaxRate::query()->where('code', 'VAT8')->sole();
    expect($rate->rate_bp)->toBe(850);

    $this->actingAs($owner)->post('/config/taxes', ['name' => 'Trùng', 'code' => 'VAT8', 'rate' => 8, 'type' => 'sale'])
        ->assertSessionHasErrors('code');

    $this->actingAs($owner)->put('/config/taxes/defaults', ['sale' => 'VAT8', 'purchase' => 'NHAPHANG', 'price_includes_tax' => false])
        ->assertSessionHasNoErrors();
    expect(settings('tax.default_sale'))->toBe('VAT8')->and(settings('tax.price_includes_tax'))->toBeFalse();

    $this->actingAs($owner)->delete("/config/taxes/{$rate->id}");
    expect(TaxRate::query()->whereKey($rate->id)->exists())->toBeTrue();

    $this->actingAs($owner)->put("/config/taxes/{$rate->id}", ['name' => 'Thuế 8%', 'code' => 'VAT08', 'rate' => 8, 'type' => 'sale'])
        ->assertSessionHasErrors('code');

    $this->actingAs($owner)->put('/config/taxes/defaults', ['sale' => 'KHONG_CO', 'purchase' => 'NHAPHANG', 'price_includes_tax' => true])
        ->assertSessionHasErrors('sale');

    $banhang = TaxRate::query()->where('code', 'BANHANG')->sole();
    $this->actingAs($owner)->delete("/config/taxes/{$banhang->id}");
    expect(TaxRate::query()->whereKey($banhang->id)->exists())->toBeFalse();
});

test('payment methods: bank account, defaults and built-in methods', function () {
    $owner = owner();

    $this->actingAs($owner)->post('/config/payment-methods', [
        'name' => 'Chuyển khoản MB', 'type' => 'bank', 'bank_bin' => '970422', 'account_number' => '0123456789', 'account_name' => 'Nguyen Van A',
    ])->assertSessionHasNoErrors();
    $mb = PaymentMethod::query()->where('name', 'Chuyển khoản MB')->sole();
    expect($mb->account_name)->toBe('NGUYEN VAN A');

    // Only bank / e-wallet methods can be added.
    $this->actingAs($owner)->post('/config/payment-methods', ['name' => 'Tiền mặt 2', 'type' => 'cash'])
        ->assertSessionHasErrors('type');

    $cash = PaymentMethod::query()->where('type', 'cash')->sole();
    $this->actingAs($owner)->delete("/config/payment-methods/{$cash->id}");
    expect($cash->fresh())->not->toBeNull();

    $this->actingAs($owner)->put("/config/payment-methods/{$cash->id}", ['name' => 'Tiền mặt', 'type' => 'cash', 'is_active' => false])
        ->assertSessionHasErrors('is_active');

    $this->actingAs($owner)->put('/config/payment-methods/defaults', ['pos' => $mb->id, 'online' => $mb->id])->assertSessionHasNoErrors();
    expect(settings('payment.default_pos'))->toBe($mb->id);

    $this->actingAs($owner)->delete("/config/payment-methods/{$mb->id}");
    expect($mb->fresh())->not->toBeNull();
});

test('there is always exactly one default sales source', function () {
    $owner = owner();

    $this->actingAs($owner)->post('/config/sources', ['name' => 'Hotline', 'is_default' => true])->assertSessionHasNoErrors();

    $hotline = SalesSource::query()->where('name', 'Hotline')->sole();
    expect($hotline->is_default)->toBeTrue()
        ->and($hotline->code)->toBe('HOTLINE')
        ->and(SalesSource::query()->where('is_default', true)->count())->toBe(1);

    $this->actingAs($owner)->put("/config/sources/{$hotline->id}", ['name' => 'Hotline', 'is_default' => false, 'is_active' => true])
        ->assertSessionHasErrors('is_default');

    $this->actingAs($owner)->delete("/config/sources/{$hotline->id}");
    expect($hotline->fresh())->not->toBeNull();

    $pos = SalesSource::query()->where('code', 'POS')->sole();
    $this->actingAs($owner)->put("/config/sources/{$pos->id}", ['name' => 'Tại quầy', 'is_default' => true, 'is_active' => true]);
    $this->actingAs($owner)->delete("/config/sources/{$pos->id}");
    expect($pos->fresh()->is_default)->toBeTrue()->and($hotline->fresh()->is_default)->toBeFalse();
});

test('reasons: add, keep at least one, and require toggles', function () {
    $owner = owner();

    $this->actingAs($owner)->post('/config/reasons', ['type' => 'return', 'name' => 'Hết hạn bảo hành'])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post('/config/reasons', ['type' => 'return', 'name' => 'Hết hạn bảo hành'])->assertSessionHasErrors('name');

    $this->actingAs($owner)->put('/config/reasons/settings', ['cancel' => true, 'return' => false])->assertSessionHasNoErrors();
    expect(settings('reasons.require_cancel'))->toBeTrue();

    $cancel = OrderReason::query()->where('type', 'cancel')->get();
    $cancel->slice(1)->each(fn (OrderReason $reason) => $reason->delete());
    $last = $cancel->first();

    $this->actingAs($owner)->delete("/config/reasons/{$last->id}");
    expect($last->fresh())->not->toBeNull();

    // The type of a reason never changes.
    $this->actingAs($owner)->put("/config/reasons/{$last->id}", ['type' => 'return', 'name' => 'Đổi tên', 'is_active' => true]);
    expect($last->fresh()->type)->toBe('cancel')->and($last->fresh()->name)->toBe('Đổi tên');
});

test('sales settings are saved and logged', function () {
    $owner = owner();

    expect(settings('sales.allow_negative_export'))->toBeFalse();

    $payload = ['sales_print_copies' => 2];

    foreach (StoreSettings::SALES_TOGGLES as $toggles) {
        foreach (array_keys($toggles) as $key) {
            $payload[str_replace('.', '_', $key)] = $key === 'sales.allow_negative_export';
        }
    }

    $this->actingAs($owner)->put('/config/sales', $payload)->assertSessionHasNoErrors();

    expect(settings('sales.allow_negative_export'))->toBeTrue()
        ->and(settings('sales.print_copies'))->toBe(2)
        ->and(settings('pos.print_draft'))->toBeFalse();

    $this->actingAs($owner)->put('/config/sales', [...$payload, 'sales_print_copies' => 9])->assertSessionHasErrors('sales_print_copies');
});

test('records of another store cannot be reached', function () {
    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->run($other, fn () => TaxRate::query()->create(['name' => 'X', 'code' => 'X', 'rate_bp' => 0, 'type' => 'other']));

    $owner = owner();
    $this->actingAs($owner)->delete("/config/taxes/{$foreign->id}")->assertNotFound();
    $this->actingAs($owner)->get('/config/taxes')->assertInertia(fn (Assert $page) => $page->has('rates', 3));
});

test('exports and imports are listed on Xuất/nhập file', function () {
    $owner = owner();

    $this->actingAs($owner)->get('/staff?export=xlsx')->assertOk()->streamedContent();

    $log = FileLog::query()->sole();
    expect($log->action)->toBe('export')
        ->and($log->module)->toBe('staff')
        ->and($log->status)->toBe('done')
        ->and($log->user_id)->toBe($owner->id);

    $this->actingAs($owner)->get('/config/file-logs')->assertInertia(fn (Assert $page) => $page
        ->component('config/file-logs')
        ->where('logs.rows.data.0.module', 'Nhân viên')
        ->where('logs.rows.data.0.status', 'done')
    );
});
