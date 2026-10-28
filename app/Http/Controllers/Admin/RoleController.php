<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/roles/index', [
            'roles' => Role::query()
                ->withCount(['users', 'permissions'])
                ->orderBy('id')
                ->get(['id', 'name']),
            'permissionTotal' => count(Permissions::all()),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/roles/form', [
            'role' => null,
            ...$this->matrix(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $role = Role::query()->create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions']);

        $this->toast("Đã thêm vai trò {$role->name}.");

        return to_route('roles.index');
    }

    public function edit(Role $role): Response
    {
        return Inertia::render('admin/roles/form', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions()->pluck('name'),
            ],
            ...$this->matrix(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request, $role);

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions']);

        $this->toast("Đã lưu vai trò {$role->name}.");

        return to_route('roles.index');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->users()->exists()) {
            $this->toast("Vai trò {$role->name} đang được gán cho nhân viên, không thể xóa.", 'error');

            return back();
        }

        $role->delete();

        $this->toast("Đã xóa vai trò {$role->name}.");

        return to_route('roles.index');
    }

    /**
     * @return array{modules: list<array{key: string, label: string, actions: list<string>}>, actions: array<string, string>}
     */
    protected function matrix(): array
    {
        $modules = [];

        foreach (Permissions::modules() as $key => $definition) {
            $modules[] = ['key' => $key, 'label' => $definition['label'], 'actions' => $definition['actions']];
        }

        return ['modules' => $modules, 'actions' => Permissions::ACTIONS];
    }

    /**
     * @return array{name: string, permissions: list<string>}
     */
    protected function validated(Request $request, ?Role $role = null): array
    {
        /** @var array{name: string, permissions?: list<string>} $data */
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('roles')->where('tenant_id', tenancy()->id())->where('guard_name', 'web')->ignore($role),
            ],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        return ['name' => $data['name'], 'permissions' => $data['permissions'] ?? []];
    }
}
