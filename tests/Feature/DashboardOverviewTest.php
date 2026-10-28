<?php

use App\Models\Branch;
use App\Models\Role;
use App\Support\Dashboard\DashboardContext;
use App\Support\Hooks\Hook;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryService;

test('the owner sees sales, stock and recent activity', function () {
    $owner = owner();
    $mouse = variant();
    app(InventoryService::class)->move(mainBranch()->id, $mouse, 8, StockMovement::OPENING, ['unit_cost' => 50000, 'update_cost' => true]);
    Inventory::query()->where('variant_id', $mouse->id)->update(['min_quantity' => 10]);

    $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('sales.ready', false)
        ->has('sales.chart', 7)
        ->where('sales.today.revenue', 0)
        ->where('stock.below_min', 1)
        ->where('stock.on_hand', 8)
        ->where('stock.value', 400000)
        ->has('activity')
    );
});

test('each section needs its permission', function () {
    // Cashiers see all orders, so they see sales, but not stock or the activity log.
    $this->actingAs(staffWithRole('Thu ngân'))->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->has('sales.today')
        ->where('stock', null)
        ->where('activity', null)
    );

    Role::findOrCreate('Chỉ xem sản phẩm', 'web')->syncPermissions(['products.view']);
    $viewer = staffWithRole('Thu ngân');
    $viewer->syncRoles(['Chỉ xem sản phẩm']);

    $this->actingAs($viewer->fresh())->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sales', null)
        ->where('stock', null)
    );
});

test('stock value needs the cost data permission', function () {
    $user = staffWithRole('Nhân viên kho');
    $user->revokePermissionTo('products.view_cost');

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('stock.value', null)
        ->has('stock.on_hand')
    );
});

test('stock counts only the chosen branch, and only branches of the user', function () {
    $owner = owner();
    $second = Branch::factory()->create();
    $mouse = variant();
    app(InventoryService::class)->move(mainBranch()->id, $mouse, 8, StockMovement::OPENING);
    app(InventoryService::class)->move($second->id, $mouse, 5, StockMovement::OPENING);

    $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('stock.on_hand', 13));
    $this->actingAs($owner)->get("/dashboard?branch={$second->id}")->assertInertia(fn (Assert $page) => $page
        ->where('branch', $second->id)
        ->where('stock.on_hand', 5)
    );

    // A warehouse clerk of the main branch cannot pick the other branch.
    $clerk = staffWithRole('Nhân viên kho');
    $this->actingAs($clerk)->get("/dashboard?branch={$second->id}")->assertInertia(fn (Assert $page) => $page
        ->where('branch', null)
        ->where('stock.on_hand', 8)
    );
});

test('an orders module fills the sales section through the filter', function () {
    Hook::addFilter('dashboard.sales', function (array $sales, DashboardContext $context): array {
        $sales['ready'] = true;
        $sales['today']['revenue'] = 1500000;

        return $sales;
    });

    $this->actingAs(owner())->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('sales.ready', true)
        ->where('sales.today.revenue', 1500000)
    );

    Hook::removeAll('dashboard.sales');
});
