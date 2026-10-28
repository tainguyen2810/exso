<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;

test('settings are stored per store', function () {
    settings()->set('store.name', 'ITSO');

    $other = Tenant::factory()->create();

    app(TenantContext::class)->run($other, function () {
        expect(settings('store.name'))->toBeNull();

        settings()->set('store.name', 'Cửa hàng B');
    });

    expect(settings('store.name'))->toBe('ITSO');
});

test('settings keep their type', function () {
    settings()->setMany([
        'sales.allow_negative_stock' => false,
        'sales.sources' => ['Tại quầy', 'Facebook'],
    ]);

    expect(settings('sales.allow_negative_stock'))->toBeFalse()
        ->and(settings('sales.sources'))->toBe(['Tại quầy', 'Facebook'])
        ->and(settings('missing', 'mặc định'))->toBe('mặc định');
});
