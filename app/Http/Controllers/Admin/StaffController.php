<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->toString();

        $staff = User::query()
            ->with(['roles:id,name', 'branches:id,name'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status))
            ->orderByDesc('is_owner')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_owner' => $user->is_owner,
                'status' => $user->status,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'roles' => $user->roles->pluck('name'),
                'branches' => $user->branches->pluck('name'),
            ]);

        return Inertia::render('admin/staff/index', [
            'staff' => $staff,
            'filters' => ['search' => $search, 'status' => $status],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/staff/form', [
            'member' => null,
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => $data['password'],
            'status' => $data['status'],
        ]);
        $user->email_verified_at = now();
        $user->save();

        $user->branches()->sync($data['branch_ids']);
        $user->syncRoles($data['role_ids']);

        $this->toast("Đã thêm nhân viên {$user->name}.");

        return to_route('staff.index');
    }

    public function edit(User $staff): Response
    {
        return Inertia::render('admin/staff/form', [
            'member' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'phone' => $staff->phone,
                'status' => $staff->status,
                'is_owner' => $staff->is_owner,
                'role_ids' => $staff->roles()->pluck('roles.id'),
                'branch_ids' => $staff->branches()->pluck('branches.id'),
            ],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, User $staff): RedirectResponse
    {
        $data = $this->validated($request, $staff);

        if ($staff->is_owner && $data['status'] !== 'active') {
            return back()->withErrors(['status' => 'Không thể khóa tài khoản chủ cửa hàng.']);
        }

        $staff->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'status' => $data['status'],
        ]);

        if ($data['password'] !== null) {
            $staff->password = $data['password'];
        }

        $staff->save();
        $staff->branches()->sync($data['branch_ids']);
        $staff->syncRoles($data['role_ids']);

        $this->toast("Đã lưu nhân viên {$staff->name}.");

        return to_route('staff.index');
    }

    public function destroy(Request $request, User $staff): RedirectResponse
    {
        if ($staff->is_owner || $staff->is($request->user())) {
            $this->toast('Không thể xóa tài khoản chủ cửa hàng hoặc chính bạn.', 'error');

            return back();
        }

        $staff->delete();

        $this->toast("Đã xóa nhân viên {$staff->name}.");

        return to_route('staff.index');
    }

    /**
     * @return array{roles: mixed, branches: mixed}
     */
    protected function options(): array
    {
        return [
            'roles' => Role::query()->orderBy('id')->get(['id', 'name']),
            'branches' => Branch::query()->where('status', 'active')->orderByDesc('is_default')->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }

    /**
     * @return array{name: string, email: string, phone: ?string, password: ?string, status: string, role_ids: list<int>, branch_ids: list<int>}
     */
    protected function validated(Request $request, ?User $user = null): array
    {
        $tenantId = tenancy()->id();

        /** @var array{name: string, email: string, phone?: ?string, password?: ?string, status: string, role_ids?: list<int>, branch_ids: list<int>} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->where('tenant_id', $tenantId)->ignore($user)],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => [$user === null ? 'required' : 'nullable', 'confirmed', Password::defaults()],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('tenant_id', $tenantId)],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
        ], [
            'branch_ids.required' => 'Chọn ít nhất một chi nhánh.',
            'branch_ids.min' => 'Chọn ít nhất một chi nhánh.',
        ]);

        return [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'] ?? null,
            'status' => $data['status'],
            'role_ids' => array_map('intval', $data['role_ids'] ?? []),
            'branch_ids' => array_map('intval', $data['branch_ids']),
        ];
    }
}
