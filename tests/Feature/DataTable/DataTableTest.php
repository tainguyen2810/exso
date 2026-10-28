<?php

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;

/**
 * @return list<list<mixed>>
 */
function readXlsx(string $content): array
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    file_put_contents($path, $content);

    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }

    $reader->close();
    unlink($path);

    return $rows;
}

test('the list searches, filters, sorts and pages on the server', function () {
    $owner = owner();
    $owner->update(['name' => 'Chủ cửa hàng']); // a random name could contain "Bình"
    User::factory()->create(['name' => 'Trần Bình', 'status' => 'active']);
    User::factory()->create(['name' => 'Lê Bình', 'status' => 'inactive']);
    User::factory()->count(3)->create(['name' => 'Khác']);

    $this->actingAs($owner)
        ->get('/staff?search=Bình&sort=-name')
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/staff/index')
            ->has('staff.rows.data', 2)
            ->where('staff.rows.data.0.name', 'Trần Bình')
            ->where('staff.query.search', 'Bình')
            ->where('staff.query.sort', '-name'));

    $this->actingAs($owner)
        ->get('/staff?filter[status]=inactive')
        ->assertInertia(fn (Assert $page) => $page
            ->has('staff.rows.data', 1)
            ->where('staff.rows.data.0.name', 'Lê Bình')
            ->where('staff.query.filter', ['status' => 'inactive']));
});

test('unknown sort columns and page sizes fall back to the defaults', function () {
    $this->actingAs(owner())
        ->get('/staff?sort=password&per_page=100000')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('staff.query.sort', 'name')
            ->where('staff.query.per_page', 20)
            ->where('staff.rows.per_page', 20));
});

test('filters by role, branch and a date range', function () {
    $owner = owner();
    $second = Branch::factory()->create();
    $cashier = staffWithRole('Thu ngân', $second);
    $cashier->forceFill(['last_login_at' => Carbon::parse('2026-10-20 09:00')])->save();
    staffWithRole('Nhân viên kho');

    $roleId = $cashier->roles()->value('roles.id');

    $this->actingAs($owner)
        ->get("/staff?filter[role]={$roleId}")
        ->assertInertia(fn (Assert $page) => $page->has('staff.rows.data', 1)->where('staff.rows.data.0.id', $cashier->id));

    $this->actingAs($owner)
        ->get("/staff?filter[branch]={$second->id}")
        ->assertInertia(fn (Assert $page) => $page->has('staff.rows.data', 1)->where('staff.rows.data.0.id', $cashier->id));

    $this->actingAs($owner)
        ->get('/staff?filter[last_login_at][from]=20/10/2026&filter[last_login_at][to]=2026-10-20')
        ->assertInertia(fn (Assert $page) => $page->has('staff.rows.data', 1)->where('staff.rows.data.0.id', $cashier->id));

    $this->actingAs($owner)
        ->get('/staff?filter[last_login_at][from]=2026-10-21')
        ->assertInertia(fn (Assert $page) => $page->has('staff.rows.data', 0));
});

test('each user keeps their own saved filters', function () {
    $owner = owner();
    $other = staffWithRole('Quản trị');

    $this->actingAs($owner)->post('/saved-views', [
        'table_key' => 'staff',
        'name' => 'Đang nghỉ',
        'query' => ['search' => '', 'filter' => ['status' => 'inactive'], 'sort' => 'name'],
    ])->assertRedirect();

    $view = SavedView::query()->sole();
    expect($view->query)->toEqual(['filter' => ['status' => 'inactive'], 'sort' => 'name']);

    $this->actingAs($owner)->get('/staff')
        ->assertInertia(fn (Assert $page) => $page->has('staff.views', 1)->where('staff.views.0.name', 'Đang nghỉ'));

    $this->actingAs($owner)->get('/branches')
        ->assertInertia(fn (Assert $page) => $page->has('branches.views', 0));

    $this->actingAs($other)->get('/staff')
        ->assertInertia(fn (Assert $page) => $page->has('staff.views', 0));

    $this->actingAs($other)->delete("/saved-views/{$view->id}")->assertForbidden();
    $this->actingAs($owner)->delete("/saved-views/{$view->id}")->assertRedirect();

    expect(SavedView::query()->count())->toBe(0);
});

test('exports the filtered list to Excel', function () {
    $owner = owner();
    User::factory()->create(['name' => 'Trần Bình', 'phone' => '0901', 'status' => 'inactive']);
    User::factory()->create(['name' => 'Người khác']);

    $response = $this->actingAs($owner)->get('/staff?filter[status]=inactive&export=xlsx');

    $response->assertOk()->assertDownload();
    $rows = readXlsx($response->streamedContent());

    expect($rows)->toHaveCount(2)
        ->and($rows[0][0])->toBe('Họ tên')
        ->and($rows[1][0])->toBe('Trần Bình')
        ->and($rows[1][5])->toBe('Ngừng hoạt động');
});

test('export needs the view permission of the list', function () {
    $this->actingAs(staffWithRole('Thu ngân'))->get('/staff?export=xlsx')->assertForbidden();
});

test('bulk actions skip the owner and the current user', function () {
    $owner = owner();
    $admin = staffWithRole('Quản trị');
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->actingAs($admin)->post('/staff/bulk', [
        'action' => 'deactivate',
        'ids' => [$owner->id, $admin->id, $a->id, $b->id],
    ])->assertRedirect();

    expect($owner->fresh()->status)->toBe('active')
        ->and($admin->fresh()->status)->toBe('active')
        ->and($a->fresh()->status)->toBe('inactive')
        ->and($b->fresh()->status)->toBe('inactive');

    $this->actingAs($admin)->post('/staff/bulk', ['action' => 'delete', 'ids' => [$a->id]])->assertRedirect();
    expect(User::query()->find($a->id))->toBeNull();
});

test('bulk actions need the update permission', function () {
    $cashier = staffWithRole('Thu ngân');
    $other = User::factory()->create();

    $this->actingAs($cashier)->post('/staff/bulk', ['action' => 'deactivate', 'ids' => [$other->id]])->assertForbidden();
    expect($other->fresh()->status)->toBe('active');
});

test('bulk deactivate never turns off the default branch', function () {
    $owner = owner();
    $default = Branch::query()->where('is_default', true)->sole();
    $other = Branch::factory()->create();

    $this->actingAs($owner)->post('/branches/bulk', ['action' => 'deactivate', 'ids' => [$default->id, $other->id]]);

    expect($default->fresh()->status)->toBe('active')
        ->and($other->fresh()->status)->toBe('inactive');
});

test('the activity log list filters by event and exports', function () {
    $owner = owner();
    $this->actingAs($owner)->post('/branches', ['code' => 'CN09', 'name' => 'Kho 9', 'status' => 'active']);

    $this->actingAs($owner)->get('/activity-log?filter[event]=created')
        ->assertInertia(fn (Assert $page) => $page
            ->where('logs.rows.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['event'] === 'created'))
            ->where('logs.rows.total', ActivityLog::query()->where('event', 'created')->count()));

    $response = $this->actingAs($owner)->get('/activity-log?export=xlsx');
    $response->assertOk();
    expect(readXlsx($response->streamedContent())[0])->toBe(['Thời gian', 'Người thực hiện', 'Thao tác', 'Nội dung', 'IP']);
});

test('page props named "branches" do not hide the branch switcher data', function () {
    $this->actingAs(owner())->get('/staff/create')
        ->assertInertia(fn (Assert $page) => $page
            ->has('branches')
            ->has('branchContext.available', 1)
            ->has('branchContext.current'));
});
