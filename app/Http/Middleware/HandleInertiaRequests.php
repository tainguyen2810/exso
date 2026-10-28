<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\User;
use App\Support\Branches\CurrentBranch;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'store' => fn (): array => ['name' => (string) settings('store.name', config('app.name'))],
            'auth' => fn (): array => [
                'user' => $user,
                'permissions' => $user instanceof User ? $user->permissionNames() : [],
            ],
            'branchContext' => fn (): array => $user instanceof User ? $this->branchContext($user) : ['current' => null, 'available' => []],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array{current: ?int, available: list<array{id: int, name: string}>}
     */
    protected function branchContext(User $user): array
    {
        $available = array_values(Branch::query()
            ->whereIn('id', $user->branchIds())
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name])
            ->all());

        return [
            'current' => app(CurrentBranch::class)->id($user),
            'available' => $available,
        ];
    }
}
