<?php

use Inertia\Testing\AssertableInertia as Assert;

test('a cashier cannot open or call inventory, reports or admin pages', function (string $url) {
    $this->actingAs(staffWithRole('Thu ngân'))->get($url)->assertForbidden();
})->with(['/inventory', '/reports', '/staff', '/roles', '/branches', '/activity-log', '/cashbook']);

test('a cashier can open the pages of the cashier role', function (string $url) {
    $this->actingAs(staffWithRole('Thu ngân'))->get($url)->assertOk();
})->with(['/dashboard', '/pos', '/orders', '/customers']);

test('the menu permissions sent to a cashier exclude inventory and reports', function () {
    $this->actingAs(staffWithRole('Thu ngân'))
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('pos.view')
                && ! collect($permissions)->contains('inventory.view')
                && ! collect($permissions)->contains('reports.view')));
});

test('a cashier cannot create staff through the API either', function () {
    $this->actingAs(staffWithRole('Thu ngân'))
        ->post('/staff', ['name' => 'X', 'email' => 'x@itso.vn'])
        ->assertForbidden();
});

test('the owner can open every page', function (string $url) {
    $this->actingAs(owner())->get($url)->assertOk();
})->with(['/inventory', '/reports', '/staff', '/roles', '/branches', '/activity-log']);

test('a warehouse role sees inventory but not the cash book', function () {
    $user = staffWithRole('Nhân viên kho');

    $this->actingAs($user)->get('/inventory')->assertOk();
    $this->actingAs($user)->get('/cashbook')->assertForbidden();
});
