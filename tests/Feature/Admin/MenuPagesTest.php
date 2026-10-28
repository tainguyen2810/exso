<?php

use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

test('pages of later phases are guarded by their permission', function (string $url, bool $allowed) {
    $response = $this->actingAs(staffWithRole('Thu ngân'))->get($url);

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    ['/orders/create', true],
    ['/returns', true],
    ['/customer-groups', true],
    ['/cashbook', false],
    ['/cashbook/receipts', false],
    ['/reports/sales', false],
]);

test('a coming soon page tells which phase builds it', function () {
    $this->actingAs(owner())
        ->get('/cashbook')
        ->assertInertia(fn (Assert $page) => $page
            ->component('coming-soon')
            ->where('title', 'Sổ quỹ')
            ->where('phase', 'G4'));
});

test('the config hub opens for anyone who can see one of its pages', function () {
    $this->actingAs(owner())->get('/config')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('config/index'));

    $role = Role::findOrCreate('Chỉ xem nhân viên', 'web');
    $role->syncPermissions(['staff.view']);
    $this->actingAs(staffWithRole('Chỉ xem nhân viên'))->get('/config')->assertOk();
});

test('the config hub is closed to staff without any settings permission', function () {
    $this->actingAs(staffWithRole('Thu ngân'))->get('/config')->assertForbidden();
});

test('guests are sent to login from the config hub', function () {
    $this->get('/config')->assertRedirect('/login');
});
