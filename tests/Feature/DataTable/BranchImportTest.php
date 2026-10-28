<?php

use App\Models\Branch;
use App\Support\Excel\ExcelExport;
use Illuminate\Http\UploadedFile;

function branchFile(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
    ExcelExport::store($path, ['Mã chi nhánh*', 'Tên chi nhánh*', 'Điện thoại', 'Địa chỉ'], $rows);

    return new UploadedFile($path, 'chi-nhanh.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('imports new branches and updates existing codes', function () {
    $owner = owner();
    Branch::factory()->create(['code' => 'CN02', 'name' => 'Tên cũ']);

    $this->actingAs($owner)->post('/branches/import', [
        'file' => branchFile([
            ['CN02', 'Chi nhánh Quận 3', '0909', '12 Lê Văn Sỹ'],
            ['cn05', 'Chi nhánh Thủ Đức', null, null],
        ]),
    ])->assertRedirect('/branches')->assertSessionHasNoErrors();

    expect(Branch::query()->where('code', 'CN02')->value('name'))->toBe('Chi nhánh Quận 3')
        ->and(Branch::query()->where('code', 'CN05')->value('name'))->toBe('Chi nhánh Thủ Đức');
});

test('nothing is imported when a row is invalid', function () {
    $owner = owner();

    $this->actingAs($owner)->post('/branches/import', [
        'file' => branchFile([
            ['CN07', 'Hợp lệ', null, null],
            ['CN08', null, null, null],
            ['CN07', 'Trùng mã', null, null],
        ]),
    ])->assertSessionHasErrors(['file', 'rows']);

    expect(Branch::query()->where('code', 'CN07')->exists())->toBeFalse();
    expect(session('errors')->first('rows'))
        ->toContain('Dòng 3')
        ->toContain('Dòng 4');
});

test('the import template downloads as xlsx', function () {
    $this->actingAs(owner())->get('/branches/import/template')->assertOk()->assertDownload('mau-nhap-chi-nhanh.xlsx');
});

test('importing needs the create permission', function () {
    $this->actingAs(staffWithRole('Thu ngân'))->post('/branches/import', [
        'file' => branchFile([['CN09', 'X', null, null]]),
    ])->assertForbidden();
});
