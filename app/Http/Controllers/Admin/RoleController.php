<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/roles/index', [
            'roles' => Role::query()
                ->withCount([
                    'permissions',
                    'users as active_users_count' => fn ($query) => $query->where('status', 'active'),
                    'users as inactive_users_count' => fn ($query) => $query->where('status', '!=', 'active'),
                ])
                ->orderBy('id')
                ->get()
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'permissions_count' => $role->permissions_count,
                    'active_users_count' => $role->active_users_count,
                    'inactive_users_count' => $role->inactive_users_count,
                    'created_at' => $role->created_at?->toIso8601String(),
                    'updated_at' => $role->updated_at?->toIso8601String(),
                ]),
            'permissionTotal' => count(Permissions::rolePermissions()),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/roles/form', [
            'role' => null,
            'locked' => null,
            ...$this->matrix($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $role = Role::query()->create(['name' => $data['name'], 'description' => $data['description'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions']);

        $this->toast("Đã thêm vai trò {$role->name}.");

        return to_route('roles.index');
    }

    public function edit(Request $request, Role $role): Response
    {
        return Inertia::render('admin/roles/form', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'permissions' => $role->permissions()->pluck('name'),
            ],
            'locked' => $this->canManage($request, $role) ? null : 'Vai trò này có quyền mà bạn không có, nên bạn chỉ xem được.',
            ...$this->matrix($request),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_unless($this->canManage($request, $role), 403);

        $data = $this->validated($request, $role);

        $role->update(['name' => $data['name'], 'description' => $data['description']]);
        $role->syncPermissions($data['permissions']);

        $this->toast("Đã lưu vai trò {$role->name}.");

        return to_route('roles.index');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        abort_unless($this->canManage($request, $role), 403);

        if ($role->users()->exists()) {
            $this->toast("Vai trò {$role->name} đang được gán cho nhân viên, không thể xóa.", 'error');

            return back();
        }

        $role->delete();

        $this->toast("Đã xóa vai trò {$role->name}.");

        return to_route('roles.index');
    }

    /**
     * Staff may only edit roles whose permissions they hold themselves.
     */
    protected function canManage(Request $request, Role $role): bool
    {
        /** @var User $editor */
        $editor = $request->user();

        return $editor->is_owner || $role->permissions()->whereNotIn('name', $editor->grantablePermissions())->doesntExist();
    }

    /**
     * Permission groups as on Sapo's role page, plus what the editor may grant.
     *
     * @return array{groups: list<array{label: string, modules: list<array{key: string, label: string, permissions: list<array{name: string, label: string}>}>}>, grantable: list<string>}
     */
    protected function matrix(Request $request): array
    {
        $groups = [];

        foreach (Permissions::modules() as $key => $definition) {
            $groups[$definition['group']][] = [
                'key' => $key,
                'label' => $definition['label'],
                'permissions' => array_map(fn (string $action): array => [
                    'name' => "{$key}.{$action}",
                    'label' => $definition['labels'][$action] ?? Permissions::ACTIONS[$action] ?? $action,
                ], $definition['actions']),
            ];
        }

        /** @var User $editor */
        $editor = $request->user();

        return [
            'groups' => array_map(fn (string $label, array $modules): array => ['label' => $label, 'modules' => $modules], array_keys($groups), array_values($groups)),
            'grantable' => $editor->grantablePermissions(),
        ];
    }

    /**
     * @return array{name: string, description: ?string, permissions: list<string>}
     */
    protected function validated(Request $request, ?Role $role = null): array
    {
        /** @var array{name: string, description?: ?string, permissions?: list<string>} $data */
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('roles')->where('tenant_id', tenancy()->id())->where('guard_name', 'web')->ignore($role),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Permissions::rolePermissions())],
        ]);

        $permissions = array_values(array_unique($data['permissions'] ?? []));

        /** @var User $editor */
        $editor = $request->user();
        $missing = array_diff($permissions, $editor->grantablePermissions());

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'Bạn không cấp được quyền mà bạn không có: '.implode(', ', array_map(Permissions::label(...), $missing)).'.',
            ]);
        }

        return ['name' => $data['name'], 'description' => $data['description'] ?? null, 'permissions' => $permissions];
    }
}
