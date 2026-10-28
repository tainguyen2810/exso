<?php

namespace App\Support\Hooks;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void addFilter(string $hook, callable $callback, int $priority = 10)
 * @method static mixed filter(string $hook, mixed $value, mixed ...$args)
 * @method static bool hasFilter(string $hook)
 * @method static void removeAll(string $hook)
 *
 * @see HookManager
 */
class Hook extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HookManager::class;
    }
}
