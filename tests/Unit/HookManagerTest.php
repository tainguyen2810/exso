<?php

use App\Support\Hooks\HookManager;

test('filters run in priority order and can use extra arguments', function () {
    $hooks = new HookManager;

    $hooks->addFilter('order.total', fn (int $total) => $total * 2, 20);
    $hooks->addFilter('order.total', fn (int $total, int $fee) => $total + $fee, 10);

    expect($hooks->filter('order.total', 100, 5))->toBe(210);
});

test('a hook without filters returns the value unchanged', function () {
    expect((new HookManager)->filter('menu.items', ['a']))->toBe(['a']);
});
