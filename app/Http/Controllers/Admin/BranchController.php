<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        return Inertia::render('admin/branches/index', [
            'branches' => Branch::query()
                ->withCount('users')
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")))
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/branches/form', ['branch' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $branch = Branch::query()->create($this->validated($request));
        $this->ensureSingleDefault($branch);

        $this->toast("Đã thêm chi nhánh {$branch->name}.");

        return to_route('branches.index');
    }

    public function edit(Branch $branch): Response
    {
        return Inertia::render('admin/branches/form', ['branch' => $branch]);
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $data = $this->validated($request, $branch);

        if ($branch->is_default && ! $data['is_default']) {
            return back()->withErrors(['is_default' => 'Phải có một chi nhánh mặc định. Hãy chọn chi nhánh khác làm mặc định trước.']);
        }

        $branch->update($data);
        $this->ensureSingleDefault($branch);

        $this->toast("Đã lưu chi nhánh {$branch->name}.");

        return to_route('branches.index');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        if ($branch->is_default) {
            $this->toast('Không thể xóa chi nhánh mặc định.', 'error');

            return back();
        }

        $branch->delete();

        $this->toast("Đã xóa chi nhánh {$branch->name}.");

        return to_route('branches.index');
    }

    /**
     * @return array{code: string, name: string, phone: ?string, address: ?string, is_default: bool, status: string}
     */
    protected function validated(Request $request, ?Branch $branch = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('branches')->where('tenant_id', tenancy()->id())->ignore($branch),
            ],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        if ($data['is_default'] && $data['status'] !== 'active') {
            $data['status'] = 'active';
        }

        /** @var array{code: string, name: string, phone: ?string, address: ?string, is_default: bool, status: string} $data */
        return $data;
    }

    protected function ensureSingleDefault(Branch $branch): void
    {
        if ($branch->is_default) {
            Branch::query()->whereKeyNot($branch->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
