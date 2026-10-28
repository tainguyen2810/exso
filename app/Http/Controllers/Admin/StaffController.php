<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffController extends Controller
{
    public function index(Request $request): Response|StreamedResponse
    {
        $table = DataTable::for(User::query()->with(['roles:id,name', 'branches:id,name']), $request, 'staff')
            ->search(['name', 'email', 'phone'])
            ->filterExact('status')
            ->filter('role', fn (Builder $query, mixed $value) => $query->whereHas('roles', fn (Builder $roles) => $roles->whereKey((int) $value)))
            ->filter('branch', fn (Builder $query, mixed $value) => $query->whereHas('branches', fn (Builder $branches) => $branches->whereKey((int) $value)))
            ->filterDateRange('last_login_at')
            ->sortable(['name', 'email', 'last_login_at', 'created_at'], 'name');

        if ($request->query('export') === 'xlsx') {
            abort_unless($request->user()?->can('staff.export'), 403);

            return $table->export('nhan-vien-'.now()->format('Ymd-His'), [
                'Họ tên' => fn (User $user) => $user->name,
                'Email' => fn (User $user) => $user->email,
                'Điện thoại' => fn (User $user) => $user->phone,
                'Vai trò' => fn (User $user) => $user->roles->pluck('name')->all(),
                'Chi nhánh' => fn (User $user) => $user->is_owner ? 'Tất cả' : $user->branches->pluck('name')->all(),
                'Trạng thái' => fn (User $user) => $user->status === 'active' ? 'Đang hoạt động' : 'Ngừng hoạt động',
                'Đăng nhập gần nhất' => fn (User $user) => $user->last_login_at,
            ]);
        }

        return Inertia::render('admin/staff/index', [
            'staff' => $table->paginate(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_owner' => $user->is_owner,
                'status' => $user->status,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'roles' => $user->roles->pluck('name'),
                'branches' => $user->branches->pluck('name'),
            ]),
            ...$this->options($request),
        ]);
    }

    /**
     * Bulk actions from the list: activate, deactivate or delete the selected staff.
     * The owner, the current user and staff with more rights than the
     * current user are always skipped.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete'])],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $action = $request->string('action')->toString();

        abort_if($action === 'delete' && ! $request->user()?->can('staff.delete'), 403);

        /** @var User $editor */
        $editor = $request->user();

        $users = User::query()
            ->whereKey($data['ids'])
            ->where('is_owner', false)
            ->whereKeyNot($editor->id)
            ->get()
            ->filter(fn (User $user): bool => $editor->canManage($user));

        foreach ($users as $user) {
            if ($action === 'delete') {
                $user->delete();
            } else {
                $user->update(['status' => $action === 'activate' ? 'active' : 'inactive']);
            }
        }

        $this->toast("Đã cập nhật {$users->count()} nhân viên.");

        return back();
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/staff/form', [
            'member' => null,
            'locked' => null,
            ...$this->options($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $user = DB::transaction(function () use ($data): User {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'status' => $data['status'],
            ]);
            $user->email_verified_at = now();
            $user->save();

            $this->saveAccess($user, $data);

            return $user;
        });

        $this->toast("Đã thêm nhân viên {$user->name}.");

        return to_route('staff.index');
    }

    public function edit(Request $request, User $staff): Response
    {
        /** @var User $editor */
        $editor = $request->user();

        return Inertia::render('admin/staff/form', [
            'member' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'phone' => $staff->phone,
                'status' => $staff->status,
                'is_owner' => $staff->is_owner,
                'branch_ids' => $staff->branches()->pluck('branches.id'),
                'assignments' => $this->assignmentsOf($staff),
                'data_permissions' => $staff->getDirectPermissions()->pluck('name')
                    ->intersect(array_keys(Permissions::dataPermissions()))->values(),
            ],
            'locked' => $editor->canManage($staff) ? null : ($staff->is($editor)
                ? 'Bạn không tự sửa vai trò của mình được. Đổi thông tin cá nhân ở Tài khoản của tôi.'
                : 'Nhân viên này có quyền mà bạn không có, nên bạn chỉ xem được.'),
            ...$this->options($request),
        ]);
    }

    public function update(Request $request, User $staff): RedirectResponse
    {
        /** @var User $editor */
        $editor = $request->user();
        abort_unless($editor->canManage($staff), 403);

        $data = $this->validated($request, $staff);

        if ($staff->is_owner && $data['status'] !== 'active') {
            return back()->withErrors(['status' => 'Không thể khóa tài khoản chủ cửa hàng.']);
        }

        DB::transaction(function () use ($staff, $data): void {
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
            $this->saveAccess($staff, $data);
        });

        $this->toast("Đã lưu nhân viên {$staff->name}.");

        return to_route('staff.index');
    }

    public function destroy(Request $request, User $staff): RedirectResponse
    {
        /** @var User $editor */
        $editor = $request->user();

        if ($staff->is_owner || $staff->is($editor) || ! $editor->canManage($staff)) {
            $this->toast('Không thể xóa chủ cửa hàng, chính bạn hoặc nhân viên có quyền cao hơn bạn.', 'error');

            return back();
        }

        $staff->delete();

        $this->toast("Đã xóa nhân viên {$staff->name}.");

        return to_route('staff.index');
    }

    /**
     * Options for the form. Roles, branches and data permissions the editor
     * may not hand out are marked so the form can disable them.
     *
     * @return array<string, mixed>
     */
    protected function options(Request $request): array
    {
        /** @var User $editor */
        $editor = $request->user();
        $grantable = $editor->grantablePermissions();
        $branchIds = $editor->branchIds();

        return [
            'roles' => Role::query()->with('permissions:id,name')->orderBy('id')->get()
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'grantable' => $role->permissions->pluck('name')->diff($grantable)->isEmpty(),
                ]),
            'branches' => Branch::query()->where('status', 'active')->orderByDesc('is_default')->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Branch $branch): array => [
                    'id' => $branch->id,
                    'code' => $branch->code,
                    'name' => $branch->name,
                    'grantable' => in_array($branch->id, $branchIds, true),
                ]),
            'dataPermissions' => collect(Permissions::dataPermissions())
                ->map(fn (string $label, string $name): array => [
                    'name' => $name,
                    'label' => $label,
                    'grantable' => in_array($name, $grantable, true),
                ])->values(),
        ];
    }

    /**
     * @return list<array{role_id: int, branch_id: ?int}>
     */
    protected function assignmentsOf(User $user): array
    {
        $rows = $user->roleAssignments()->get(['role_id', 'branch_id'])
            ->map(fn (RoleAssignment $row): array => ['role_id' => $row->role_id, 'branch_id' => $row->branch_id]);

        // Roles given before branch roles existed apply to all branches.
        foreach ($user->roles()->pluck('roles.id') as $roleId) {
            if (! $rows->contains('role_id', (int) $roleId)) {
                $rows->push(['role_id' => (int) $roleId, 'branch_id' => null]);
            }
        }

        return array_values($rows->all());
    }

    /**
     * Branches, roles per branch and data permissions.
     *
     * @param  array{branch_ids: list<int>, assignments: list<array{role_id: int, branch_id: ?int}>, data_permissions: list<string>}  $data
     */
    protected function saveAccess(User $user, array $data): void
    {
        $user->branches()->sync($data['branch_ids']);

        $user->roleAssignments()->delete();

        foreach ($data['assignments'] as $assignment) {
            $user->roleAssignments()->create($assignment);
        }

        $user->syncRoles(array_values(array_unique(array_column($data['assignments'], 'role_id'))));
        $user->syncPermissions($data['data_permissions']);
        $user->unsetRelation('roleAssignments');
    }

    /**
     * @return array{name: string, email: string, phone: ?string, password: ?string, status: string, branch_ids: list<int>, assignments: list<array{role_id: int, branch_id: ?int}>, data_permissions: list<string>}
     */
    protected function validated(Request $request, ?User $user = null): array
    {
        $tenantId = tenancy()->id();

        /** @var array{name: string, email: string, phone?: ?string, password?: ?string, status: string, role_ids?: list<int>, assignments?: list<array{role_id: int, branch_id?: ?int}>, data_permissions?: list<string>, branch_ids: list<int>} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->where('tenant_id', $tenantId)->ignore($user)],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => [$user === null ? 'required' : 'nullable', 'confirmed', Password::defaults()],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            // role_ids: roles in all branches (older clients); assignments: role per branch.
            'role_ids' => ['array', 'max:50'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('tenant_id', $tenantId)],
            'assignments' => ['array', 'max:200'],
            'assignments.*.role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('tenant_id', $tenantId)],
            'assignments.*.branch_id' => ['nullable', 'integer', 'in_array:branch_ids.*'],
            'data_permissions' => ['array'],
            'data_permissions.*' => ['string', Rule::in(array_keys(Permissions::dataPermissions()))],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
        ], [
            'branch_ids.required' => 'Chọn ít nhất một chi nhánh.',
            'branch_ids.min' => 'Chọn ít nhất một chi nhánh.',
            'assignments.*.role_id.required' => 'Chọn vai trò.',
            'assignments.*.role_id.exists' => 'Vai trò không tồn tại.',
            'assignments.*.branch_id.in_array' => 'Chi nhánh của vai trò phải nằm trong chi nhánh làm việc.',
        ]);

        $assignments = isset($data['assignments'])
            ? array_map(fn (array $row): array => ['role_id' => (int) $row['role_id'], 'branch_id' => isset($row['branch_id']) ? (int) $row['branch_id'] : null], $data['assignments'])
            : array_map(fn ($id): array => ['role_id' => (int) $id, 'branch_id' => null], $data['role_ids'] ?? []);

        $result = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'] ?? null,
            'status' => $data['status'],
            'branch_ids' => array_values(array_unique(array_map('intval', $data['branch_ids']))),
            'assignments' => array_values(array_unique($assignments, SORT_REGULAR)),
            'data_permissions' => array_values(array_unique($data['data_permissions'] ?? [])),
        ];

        $this->ensureGrantable($request, $result, $user);

        return $result;
    }

    /**
     * Staff can only hand out what they have themselves: roles whose
     * permissions they hold, their own data permissions and their own
     * branches. What the member already had may stay.
     *
     * @param  array{branch_ids: list<int>, assignments: list<array{role_id: int, branch_id: ?int}>, data_permissions: list<string>}  $data
     */
    protected function ensureGrantable(Request $request, array $data, ?User $user): void
    {
        /** @var User $editor */
        $editor = $request->user();

        if ($editor->is_owner) {
            return;
        }

        $grantable = $editor->grantablePermissions();
        $hadRoles = $user?->roles()->pluck('roles.id')->map(fn ($id): int => (int) $id)->all() ?? [];
        $hadBranches = $user?->branches()->pluck('branches.id')->map(fn ($id): int => (int) $id)->all() ?? [];
        $hadData = $user?->getDirectPermissions()->pluck('name')->all() ?? [];
        $errors = [];

        $newRoles = array_diff(array_column($data['assignments'], 'role_id'), $hadRoles);
        $tooStrong = Role::query()->whereKey($newRoles)->with('permissions:id,name')->get()
            ->filter(fn (Role $role): bool => $role->permissions->pluck('name')->diff($grantable)->isNotEmpty());

        if ($tooStrong->isNotEmpty()) {
            $errors['assignments'] = 'Bạn không gán được vai trò có quyền mà bạn không có: '.$tooStrong->pluck('name')->implode(', ').'.';
        }

        if (array_diff($data['data_permissions'], $grantable, $hadData) !== []) {
            $errors['data_permissions'] = 'Bạn không cấp được quyền dữ liệu mà bạn không có.';
        }

        if (array_diff($data['branch_ids'], $editor->branchIds(), $hadBranches) !== []) {
            $errors['branch_ids'] = 'Bạn chỉ gán được chi nhánh mà bạn đang làm việc.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
