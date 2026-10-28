<?php

use App\Models\Role;
use App\Support\Settings\StoreDefaults;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerGroup;
use Modules\Customer\Services\CustomerLedger;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    StoreDefaults::install();
});

test('a new customer gets a code, the default group and a normalized unique phone', function () {
    $this->actingAs(owner());

    $this->post('/customers', ['name' => 'Nguyễn Văn An', 'phone' => '+84 901 234 567'])->assertRedirect();
    $an = Customer::query()->firstOrFail();

    expect($an->code)->toBe('CUZN00001')
        ->and($an->phone)->toBe('0901234567')
        ->and($an->group?->is_default)->toBeTrue();

    $this->post('/customers', ['name' => 'Trần Bình', 'phone' => '0901.234.567'])->assertSessionHasErrors('phone');

    // Quick add from the order form answers with the picker data.
    $this->postJson('/customers', ['name' => 'Lê Chi', 'phone' => '0912000111'])
        ->assertCreated()
        ->assertJsonPath('name', 'Lê Chi')
        ->assertJsonPath('price_policy', 'retail_price');
});

test('staff with customers.view only reach the customers in their charge', function () {
    Role::findOrCreate('Chăm sóc khách', 'web')->syncPermissions(['customers.view', 'customers.create', 'customers.update']);
    $staff = staffWithRole('Chăm sóc khách');
    $mine = customer(['assigned_to' => $staff->id, 'name' => 'Khách của tôi']);
    $other = customer(['name' => 'Khách người khác']);

    $this->actingAs($staff)->get('/customers')->assertInertia(fn (Assert $page) => $page
        ->component('customers/index')
        ->has('customers.rows.data', 1)
        ->where('customers.rows.data.0.id', $mine->id));

    $this->get("/customers/{$other->id}")->assertForbidden();
    $this->put("/customers/{$other->id}", ['name' => 'Đổi tên'])->assertForbidden();
    $this->post("/customers/{$other->id}/notes", ['content' => 'x'])->assertForbidden();
    $this->getJson('/customers/search?q=Khách')->assertJsonCount(1);
    $this->get("/customers/{$mine->id}")->assertInertia(fn (Assert $page) => $page->component('customers/show')->where('customer.name', 'Khách của tôi'));
    $this->get('/customers?export=xlsx')->assertForbidden();
});

test('the customer page keeps addresses, contacts, notes and the debt ledger', function () {
    $this->actingAs(owner());
    $an = customer();

    $this->post("/customers/{$an->id}/addresses", ['address' => '12 Lê Lợi', 'district' => 'Quận 1'])->assertSessionHasNoErrors();
    $this->post("/customers/{$an->id}/contacts", ['name' => 'Chị Hoa', 'phone' => '0909'])->assertSessionHasNoErrors();
    $this->post("/customers/{$an->id}/notes", ['content' => 'Thích giao buổi sáng'])->assertSessionHasNoErrors();
    app(CustomerLedger::class)->record($an, 'adjustment', 50000, ['note' => 'Nợ cũ']);

    $this->get("/customers/{$an->id}")->assertInertia(fn (Assert $page) => $page
        ->where('customer.debt', 50000)
        ->where('customer.addresses.0.is_default', true)
        ->has('customer.contacts', 1)
        ->has('customer.notes', 1)
        ->where('debts.0.debt_after', 50000));

    // A customer who still owes cannot be deleted.
    $this->delete("/customers/{$an->id}");
    expect(Customer::query()->find($an->id))->not->toBeNull();
});

test('customers are imported from Excel all or nothing', function () {
    $this->actingAs(owner());
    $existing = customer(['phone' => '0901234567', 'name' => 'Tên cũ']);
    $file = function (array $rows): UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'kh').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Mã khách hàng', 'Tên khách hàng*', 'Số điện thoại', 'Email', 'Nhóm khách hàng', 'Địa chỉ', 'Tỉnh/Thành phố', 'Quận/Huyện', 'Phường/Xã', 'Ngày sinh', 'Giới tính', 'Mã số thuế', 'Ghi chú']));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return new UploadedFile($path, 'khach-hang.xlsx', null, null, true);
    };

    $this->post('/customers/import', ['file' => $file([
        ['', 'Khách mới', '0912000111', '', 'Đại lý', '', '', '', '', '15/08/1990', 'Nữ', '', ''],
        ['', '', '0912000222', '', '', '', '', '', '', '', '', '', ''],
    ])])->assertSessionHasErrors('file');
    expect(Customer::query()->count())->toBe(1);

    $this->post('/customers/import', ['file' => $file([
        ['', 'Khách mới', '0912000111', '', 'Đại lý', '', '', '', '', '15/08/1990', 'Nữ', '', ''],
        ['', 'Tên mới', '0901234567', '', '', '', '', '', '', '', '', '', ''],
    ])])->assertSessionHasNoErrors();

    $new = Customer::query()->where('phone', '0912000111')->firstOrFail();
    expect(Customer::query()->count())->toBe(2)
        ->and($existing->refresh()->name)->toBe('Tên mới')
        ->and($new->group?->code)->toBe('DAILY')
        ->and($new->gender)->toBe('female');
});

test('default groups and groups with customers are kept', function () {
    $this->actingAs(owner());
    $default = CustomerGroup::query()->where('is_default', true)->firstOrFail();
    $vip = CustomerGroup::query()->where('code', 'VIP')->firstOrFail();
    customer(['group_id' => $vip->id]);

    $this->delete("/customer-groups/{$default->id}");
    $this->delete("/customer-groups/{$vip->id}");
    expect(CustomerGroup::query()->count())->toBe(4);

    $this->post('/customer-groups', ['name' => 'Cộng tác viên', 'price_policy' => 'wholesale_price', 'discount_bp' => 500])->assertRedirect('/customer-groups');
    $group = CustomerGroup::query()->where('name', 'Cộng tác viên')->firstOrFail();
    expect($group->price_column)->toBe('wholesale_price')->and($group->discount_bp)->toBe(500);

    $this->delete("/customer-groups/{$group->id}");
    expect(CustomerGroup::query()->find($group->id))->toBeNull();
});
