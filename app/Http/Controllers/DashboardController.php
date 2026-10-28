<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\User;
use App\Support\Dashboard\DashboardContext;
use App\Support\Hooks\Hook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tổng quan, laid out like Sapo. The core only knows the sections; modules
 * fill them through filters, each given a DashboardContext:
 *  - dashboard.sales (orders): today's results, revenue chart, pending orders, top products
 *  - dashboard.stock (inventory): below minimum, stock quantity and value
 * A section is sent only to users allowed to see it.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $allowed = $user->branchIds();
        $branch = (int) $request->query('branch');
        $branchIds = in_array($branch, $allowed, true) ? [$branch] : $allowed;

        $context = new DashboardContext($user, $branchIds, CarbonImmutable::now());

        return Inertia::render('dashboard', [
            'branches' => Branch::query()->whereKey($allowed)->orderBy('name')->get(['id', 'name']),
            'branch' => in_array($branch, $allowed, true) ? $branch : null,
            'sales' => $user->canAny(['reports.view', 'orders.view_all'])
                ? Hook::filter('dashboard.sales', $context->emptySales(), $context)
                : null,
            'stock' => $user->can('inventory.view') ? Hook::filter('dashboard.stock', null, $context) : null,
            'activity' => $user->can('activity_log.view') ? $this->activity() : null,
        ]);
    }

    /**
     * @return list<array{id: int, user: ?string, description: string, created_at: ?string}>
     */
    protected function activity(): array
    {
        return array_values(ActivityLog::query()->with('user:id,name')->latest('id')->limit(10)->get()
            ->map(fn (ActivityLog $log): array => [
                'id' => $log->id,
                'user' => $log->user?->name,
                'description' => $log->description,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->all());
    }
}
