<?php

use App\Models\Branch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockLedger;
use Modules\Product\Models\ComboItem;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;

function stock(): InventoryService
{
    return app(InventoryService::class);
}

function onHand(ProductVariant $variant, Branch $branch): int
{
    return (int) Inventory::query()->where('variant_id', $variant->id)->where('branch_id', $branch->id)->value('on_hand');
}

test('every change writes a movement with the stock after it', function () {
    $branch = mainBranch();
    $mouse = variant();

    stock()->move($branch->id, $mouse, 10, StockMovement::OPENING, ['unit_cost' => 100000, 'update_cost' => true]);
    stock()->move($branch->id, $mouse, -3, StockMovement::SALE, ['reference_code' => 'SON00001']);

    expect(onHand($mouse, $branch))->toBe(7);

    $movements = StockMovement::query()->orderBy('id')->get();
    expect($movements)->toHaveCount(2)
        ->and($movements[0]->quantity)->toBe(10)
        ->and($movements[0]->on_hand_after)->toBe(10)
        ->and($movements[1]->quantity)->toBe(-3)
        ->and($movements[1]->on_hand_after)->toBe(7)
        ->and($movements[1]->unit_cost)->toBe(100000)
        ->and($movements[1]->reference_code)->toBe('SON00001');
});

test('stock cannot go below zero unless the product allows it', function () {
    $branch = mainBranch();
    $mouse = variant();
    stock()->move($branch->id, $mouse, 2, StockMovement::OPENING);

    expect(fn () => stock()->move($branch->id, $mouse, -3, StockMovement::SALE))
        ->toThrow(InsufficientStockException::class);

    expect(onHand($mouse, $branch))->toBe(2)
        ->and(StockMovement::query()->count())->toBe(1);

    $license = variant(['name' => 'Office 365', 'allow_negative_stock' => true]);
    stock()->move($branch->id, $license, -1, StockMovement::SALE);

    expect(onHand($license, $branch))->toBe(-1);
});

test('services and licenses have no stock', function () {
    $branch = mainBranch();
    $service = variant(['name' => 'Cài Windows', 'type' => Product::TYPE_SERVICE]);

    expect(stock()->move($branch->id, $service, -5, StockMovement::SALE))->toBe([])
        ->and(Inventory::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

test('a conversion unit moves its base variant', function () {
    $branch = mainBranch();
    $can = variant(['name' => 'Nước suối', 'unit' => 'Chai'], ['unit' => 'Chai']);
    $case = ProductVariant::query()->create([
        'product_id' => $can->product_id, 'name' => 'Nước suối (Thùng)', 'sku' => 'NS-THUNG',
        'unit' => 'Thùng', 'base_variant_id' => $can->id, 'unit_ratio' => 24,
    ]);

    stock()->move($branch->id, $case, 2, StockMovement::PURCHASE, ['unit_cost' => 120000, 'update_cost' => true]);
    stock()->move($branch->id, $can, -5, StockMovement::SALE);

    expect(onHand($can, $branch))->toBe(43)
        ->and(Inventory::query()->where('variant_id', $case->id)->exists())->toBeFalse()
        ->and($can->refresh()->cost_price)->toBe(5000)
        ->and(stock()->stockOf($case, [$branch->id])['on_hand'])->toBe(1);
});

test('a combo moves its components', function () {
    $branch = mainBranch();
    $mouse = variant(['name' => 'Chuột']);
    $pad = variant(['name' => 'Lót chuột']);
    $combo = variant(['name' => 'Combo chuột + lót', 'type' => Product::TYPE_COMBO]);
    ComboItem::query()->create(['product_id' => $combo->product_id, 'variant_id' => $mouse->id, 'quantity' => 1]);
    ComboItem::query()->create(['product_id' => $combo->product_id, 'variant_id' => $pad->id, 'quantity' => 2]);

    stock()->move($branch->id, $mouse, 10, StockMovement::OPENING);
    stock()->move($branch->id, $pad, 10, StockMovement::OPENING);

    expect(stock()->stockOf($combo, [$branch->id])['available'])->toBe(5);

    stock()->move($branch->id, $combo, -3, StockMovement::SALE);

    expect(onHand($mouse, $branch))->toBe(7)
        ->and(onHand($pad, $branch))->toBe(4)
        ->and(stock()->stockOf($combo, [$branch->id])['available'])->toBe(2);
});

test('receipts update the weighted average cost across branches', function () {
    $main = mainBranch();
    $second = Branch::factory()->create();
    $mouse = variant();

    stock()->move($main->id, $mouse, 10, StockMovement::PURCHASE, ['unit_cost' => 100000, 'update_cost' => true]);
    // (10 × 100.000 + 30 × 120.000) / 40 = 115.000
    stock()->move($second->id, $mouse, 30, StockMovement::PURCHASE, ['unit_cost' => 120000, 'update_cost' => true]);

    expect($mouse->refresh()->cost_price)->toBe(115000);

    // Selling does not change the cost.
    stock()->move($main->id, $mouse, -5, StockMovement::SALE);
    expect($mouse->refresh()->cost_price)->toBe(115000);

    // (35 × 115.000 + 5 × 99.999) / 40 = 113.124,875 → 113.125
    stock()->move($main->id, $mouse, 5, StockMovement::PURCHASE, ['unit_cost' => 99999, 'update_cost' => true]);
    expect($mouse->refresh()->cost_price)->toBe(113125);

    $last = StockMovement::query()->latest('id')->first();
    expect($last->unit_cost)->toBe(99999)->and($last->cost_after)->toBe(113125);
});

test('when stock is zero or negative the new cost is the receipt cost', function () {
    $branch = mainBranch();
    $item = variant(['allow_negative_stock' => true], ['cost_price' => 50000]);
    stock()->move($branch->id, $item, -2, StockMovement::SALE);

    stock()->move($branch->id, $item, 5, StockMovement::PURCHASE, ['unit_cost' => 70000, 'update_cost' => true]);

    expect($item->refresh()->cost_price)->toBe(70000)->and(onHand($item, $branch))->toBe(3);
});

test('reserving lowers what can be sold but not what is in stock', function () {
    $branch = mainBranch();
    $mouse = variant();
    stock()->move($branch->id, $mouse, 10, StockMovement::OPENING);

    stock()->reserve($branch->id, $mouse, 4);
    expect(stock()->stockOf($mouse, [$branch->id]))->toBe(['on_hand' => 10, 'available' => 6]);

    stock()->release($branch->id, $mouse, 4);
    expect(stock()->stockOf($mouse, [$branch->id]))->toBe(['on_hand' => 10, 'available' => 10]);
});

test('a failure rolls back every line of the change', function () {
    $branch = mainBranch();
    $mouse = variant(['name' => 'Chuột']);
    $pad = variant(['name' => 'Lót chuột']);
    $combo = variant(['name' => 'Combo', 'type' => Product::TYPE_COMBO]);
    ComboItem::query()->create(['product_id' => $combo->product_id, 'variant_id' => $mouse->id, 'quantity' => 1]);
    ComboItem::query()->create(['product_id' => $combo->product_id, 'variant_id' => $pad->id, 'quantity' => 1]);
    stock()->move($branch->id, $mouse, 5, StockMovement::OPENING);

    expect(fn () => stock()->move($branch->id, $combo, -1, StockMovement::SALE))->toThrow(InsufficientStockException::class);

    expect(onHand($mouse, $branch))->toBe(5)->and(StockMovement::query()->count())->toBe(1);
});

test('the ledger check finds totals that do not match the movements', function () {
    $branch = mainBranch();
    $mouse = variant();
    stock()->move($branch->id, $mouse, 10, StockMovement::OPENING);
    stock()->move($branch->id, $mouse, -4, StockMovement::SALE);

    expect(app(StockLedger::class)->mismatches())->toBe([]);
    expect(Artisan::call('inventory:verify'))->toBe(0);

    // Someone edits the table by hand.
    DB::table('inventories')->update(['on_hand' => 9]);

    expect(app(StockLedger::class)->mismatches())->toBe([
        ['branch_id' => $branch->id, 'variant_id' => $mouse->id, 'on_hand' => 9, 'ledger' => 6],
    ]);
    expect(Artisan::call('inventory:verify'))->toBe(1);
});

test('the ledger check runs every night', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->contains(fn ($event) => str_contains((string) $event->command, 'inventory:verify')))->toBeTrue();
});
