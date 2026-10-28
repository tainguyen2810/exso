<?php

namespace App\Support\Hooks;

/**
 * WordPress-style filters (add_filter / apply_filters) so modules can adjust
 * values owned by core (order totals, menus, table columns) without editing
 * core files. For "after something happened" use Laravel events instead.
 */
class HookManager
{
    /**
     * @var array<string, array<int, list<callable>>>
     */
    protected array $filters = [];

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->filters[$hook][$priority][] = $callback;
    }

    public function filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        if (! isset($this->filters[$hook])) {
            return $value;
        }

        ksort($this->filters[$hook]);

        foreach ($this->filters[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }

        return $value;
    }

    public function hasFilter(string $hook): bool
    {
        return ! empty($this->filters[$hook]);
    }

    public function removeAll(string $hook): void
    {
        unset($this->filters[$hook]);
    }
}
