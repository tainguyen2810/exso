<?php

use App\Models\Branch;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Cashbook\Models\CashVoucher;
use Modules\Cashbook\Models\CashVoucherType;
use Modules\Cashbook\Services\CashbookService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\ReturnService;
use Modules\Report\Models\SalesDaily;
use Modules\Report\Models\SavedReport;
use Modules\Report\Services\SalesAggregator;
use Modules\Report\Support\ReportRegistry;

/**
 * Two mice at 150.000 (cost 80.000), 30.000 order discount, 20.000 shipping,
 * picked up (completed at once); one mouse returned for 135.000.
 */
function soldAndReturned(): Order
{
    $owner = owner();
    test()->actingAs($owner);
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000]);
    $order = app(OrderService::class)->create(orderData([[$mouse, 2]], ['delivery_method' => 'pickup', 'discount' => 30000, 'shipping_fee' => 20000]), $owner, 'export');
    app(ReturnService::class)->create($order, [$order->items->first()->id => 1]);

    return $order->refresh();
}

test('completed orders and returns fill the daily table with Sapo\'s formulas', function () {
    $order = soldAndReturned();

    expect($order->status)->toBe(Order::COMPLETED);

    $day = SalesDaily::query()->sole();
    expect($day->orders_count)->toBe(1)
        ->and($day->goods_amount)->toBe(300000)
        ->and($day->discount)->toBe(30000)
        ->and($day->shipping_fee)->toBe(20000)
        ->and($day->cost)->toBe(160000)
        ->and($day->returned_amount)->toBe(135000)
        ->and($day->returned_cost)->toBe(80000);

    $this->get('/reports/sales/time?group_by=day')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reports/view')
        ->where('rows.0.revenue', 155000)
        ->where('rows.0.net_revenue', 135000)
        ->where('rows.0.cost', 80000)
        ->where('rows.0.gross_profit', 55000)
        ->where('totals.revenue', 155000)
        ->has('chart.points', 30));
});

test('the dashboard 7-day revenue matches the sales report', function () {
    soldAndReturned();

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('sales.today.revenue', 155000)
        ->where('sales.chart.6.revenue', 155000)
        ->where('sales.top.0.quantity', 1));

    $from = CarbonImmutable::now()->subDays(6)->format('d/m/Y');
    $this->get("/reports/sales/time?from={$from}")->assertInertia(fn (Assert $page) => $page->where('totals.revenue', 155000));
    $this->get('/reports/sales')->assertInertia(fn (Assert $page) => $page->where('overview.revenue', 155000));
});

test('rebuilding the daily tables gives the same numbers', function () {
    soldAndReturned();
    $before = SalesDaily::query()->get()->map->only(['goods_amount', 'discount', 'cost', 'returned_amount'])->all();

    SalesDaily::query()->delete();
    $this->artisan('reports:rebuild')->assertSuccessful();

    expect(SalesDaily::query()->get()->map->only(['goods_amount', 'discount', 'cost', 'returned_amount'])->all())->toBe($before)
        ->and(app(SalesAggregator::class)->rebuild())->toBe(1)
        ->and(SalesDaily::query()->count())->toBe(1);
});

test('the profit report adds other income and subtracts other expenses', function () {
    $order = soldAndReturned();
    $cashbook = app(CashbookService::class);
    $type = fn (string $kind, string $code) => CashVoucherType::query()->where('kind', $kind)->where('code', $code)->firstOrFail();
    $make = fn (string $kind, string $code, int $amount) => $cashbook->create([
        'kind' => $kind, 'branch_id' => mainBranch()->id, 'type_id' => $type($kind, $code)->id, 'party_type' => 'other',
        'party_name' => 'Anh Tư', 'amount' => $amount, 'payment_method_id' => cash()->id, 'affects_profit' => true,
    ], auth()->user());

    $make(CashVoucher::RECEIPT, 'THANHLY', 40000);
    $make(CashVoucher::PAYMENT, 'MATBANG', 100000);

    $this->get('/reports/finance/profit')->assertOk()->assertInertia(function (Assert $page) {
        $rows = collect($page->toArray()['props']['rows'])->keyBy('key');

        expect($rows['revenue']['current'])->toBe(155000)
            ->and($rows['net_goods']['current'])->toBe(165000)
            ->and($rows['cost']['current'])->toBe(80000)
            ->and($rows['other_income']['current'])->toBe(40000)
            ->and($rows['other_expenses']['current'])->toBe(100000)
            ->and($rows['profit']['current'])->toBe(155000 + 40000 - 80000 - 100000)
            ->and($rows['profit']['previous'])->toBe(0);
    });
});

test('every report opens and exports for the owner', function () {
    soldAndReturned();

    $this->get('/reports')->assertOk()->assertInertia(fn (Assert $page) => $page->component('reports/hub')->has('sections', 5));

    foreach (array_keys(ReportRegistry::GROUPS) as $group) {
        $this->get("/reports/{$group}")->assertOk();
    }

    foreach (app(ReportRegistry::class)->all() as $report) {
        $this->get($report->url())->assertOk();
        $response = $this->get($report->url().'?export=xlsx');
        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('spreadsheetml');
    }

    $this->get('/reports/sales/nope')->assertNotFound();
    $this->get('/reports/nope')->assertNotFound();
});

test('cost columns are hidden without products.view_cost and reports need reports.view', function () {
    soldAndReturned();
    $accountant = staffWithRole('Kế toán');

    $this->actingAs($accountant)->get('/reports/sales/time')->assertOk()->assertInertia(function (Assert $page) {
        $props = $page->toArray()['props'];

        expect(array_column($props['columns'], 'key'))->not->toContain('cost')->not->toContain('gross_profit')
            ->and($props['rows'][0])->not->toHaveKey('cost')->not->toHaveKey('gross_profit')
            ->and($props['totals'])->not->toHaveKey('cost')
            ->and($props['chart']['line'])->toBeNull();
    });

    $this->get('/reports/finance/profit')->assertForbidden();
    $this->get('/dashboard')->assertOk();

    $this->actingAs(staffWithRole('Thu ngân'))->get('/reports/sales/time')->assertForbidden();
});

test('reports only count the user\'s own branches', function () {
    soldAndReturned();
    $other = Branch::query()->create(['code' => 'CN02', 'name' => 'Chi nhánh 2']);
    $accountant = staffWithRole('Kế toán', $other);

    $this->actingAs($accountant)->get('/reports/sales/time?branch_id='.mainBranch()->id)->assertInertia(fn (Assert $page) => $page
        ->missing('query.branch_id')
        ->where('rows', []));
});

test('a saved report keeps only known filters and only its owner deletes it', function () {
    $owner = owner();

    $this->actingAs($owner)->post('/reports/saved', [
        'name' => 'Doanh thu theo tháng',
        'report' => 'sales.time',
        'query' => ['group_by' => 'month', 'from' => '01/01/2026', 'evil' => 'x'],
        'is_shared' => true,
    ])->assertRedirect();

    $saved = SavedReport::query()->sole();
    expect($saved->query)->toEqualCanonicalizing(['from' => '01/01/2026', 'group_by' => 'month'])
        ->and($saved->query)->not->toHaveKey('evil');

    $this->get('/reports/sales/time')->assertInertia(fn (Assert $page) => $page->where('saved.0.name', 'Doanh thu theo tháng'));

    $accountant = staffWithRole('Kế toán');
    $this->actingAs($accountant)->get('/reports')->assertInertia(fn (Assert $page) => $page->where('saved.0.mine', false));
    $this->delete("/reports/saved/{$saved->id}")->assertForbidden();
    $this->post('/reports/saved', ['name' => 'Lãi lỗ', 'report' => 'finance.profit'])->assertForbidden();

    $this->actingAs($owner)->delete("/reports/saved/{$saved->id}")->assertRedirect();
    expect(SavedReport::query()->count())->toBe(0);
});

test('the customer debt report shows opening, changes and closing debt', function () {
    $owner = owner();
    $this->actingAs($owner);
    $mouse = stocked(10, 80000, [], ['retail_price' => 150000]);
    $an = customer(['name' => 'Trần Thị Bình']);
    app(OrderService::class)->create(orderData([[$mouse, 2]], ['customer_id' => $an->id, 'delivery_method' => 'pickup']), $owner, 'export');

    $this->get('/reports/finance/customer-debts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.opening', 0)
        ->where('rows.0.increase', 300000)
        ->where('rows.0.closing', 300000));

    $tomorrow = CarbonImmutable::now()->addDay()->format('d/m/Y');
    $this->get("/reports/finance/customer-debts?from={$tomorrow}&to={$tomorrow}")->assertInertia(fn (Assert $page) => $page
        ->where('rows.0.opening', 300000)
        ->where('rows.0.increase', 0)
        ->where('rows.0.closing', 300000));
});
