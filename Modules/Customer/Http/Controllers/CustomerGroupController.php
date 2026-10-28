<?php

namespace Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Support\DataTable\DataTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Customer\Models\CustomerGroup;

/**
 * "Nhóm khách hàng": the price policy, default discount and payment
 * method new orders of the group's customers start with.
 */
class CustomerGroupController extends Controller
{
    public function index(Request $request): Response
    {
        if (! CustomerGroup::query()->withTrashed()->exists()) {
            CustomerGroup::installDefaults();
        }

        $table = DataTable::for(CustomerGroup::query()->with('priceList:id,name')->withCount('customers'), $request, 'customer-groups')
            ->search(['name', 'code'])
            ->sortable(['name', 'customers_count', 'created_at'], 'name');

        return Inertia::render('customer-groups/index', [
            'groups' => $table->paginate(fn (CustomerGroup $group): array => [
                'id' => $group->id,
                'code' => $group->code,
                'name' => $group->name,
                'description' => $group->description,
                'price' => $group->priceLabel(),
                'discount_bp' => $group->discount_bp,
                'is_default' => $group->is_default,
                'customers_count' => (int) $group->customers_count,
                'created_at' => $group->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('customer-groups/form', ['group' => null, ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = CustomerGroup::query()->create($this->validated($request, null));
        $this->toast("Đã thêm nhóm khách hàng {$group->name}.");

        return to_route('customer-groups.index');
    }

    public function edit(CustomerGroup $customerGroup): Response
    {
        return Inertia::render('customer-groups/form', [
            'group' => [
                ...$customerGroup->only('id', 'code', 'name', 'description', 'discount_bp', 'payment_method_id', 'is_default'),
                'price_policy' => $customerGroup->price_list_id !== null ? 'list:'.$customerGroup->price_list_id : $customerGroup->price_column,
                'customers_count' => $customerGroup->customers()->count(),
            ],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, CustomerGroup $customerGroup): RedirectResponse
    {
        $customerGroup->update($this->validated($request, $customerGroup));
        $this->toast("Đã lưu nhóm khách hàng {$customerGroup->name}.");

        return to_route('customer-groups.index');
    }

    public function destroy(CustomerGroup $customerGroup): RedirectResponse
    {
        if ($customerGroup->is_default) {
            $this->toast('Không xóa được nhóm mặc định.', 'error');

            return back();
        }

        if ($customerGroup->customers()->exists()) {
            $this->toast("Nhóm {$customerGroup->name} còn khách hàng, hãy chuyển khách sang nhóm khác trước.", 'error');

            return back();
        }

        $customerGroup->delete();
        $this->toast("Đã xóa nhóm khách hàng {$customerGroup->name}.");

        return to_route('customer-groups.index');
    }

    /**
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        return [
            'pricePolicies' => CustomerController::pricePolicies(),
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('sort')->get(['id', 'name']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?CustomerGroup $group): array
    {
        $tenant = tenancy()->id();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('customer_groups', 'code')->where('tenant_id', $tenant)->ignore($group?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_policy' => ['required', 'string', Rule::in(array_column(CustomerController::pricePolicies(), 'value'))],
            'discount_bp' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('tenant_id', $tenant)],
        ], [], ['name' => 'tên nhóm', 'code' => 'mã nhóm', 'price_policy' => 'giá mặc định']);

        $policy = $data['price_policy'];
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        if ($code === '') {
            $code = $group->code ?? 'NHOM'.str_pad((string) (CustomerGroup::query()->withTrashed()->count() + 1), 3, '0', STR_PAD_LEFT);
        }

        return [
            'name' => $data['name'],
            'code' => $code,
            'description' => $data['description'] ?? null,
            'price_column' => str_starts_with($policy, 'list:') ? 'retail_price' : $policy,
            'price_list_id' => str_starts_with($policy, 'list:') ? (int) substr($policy, 5) : null,
            'discount_bp' => (int) ($data['discount_bp'] ?? 0),
            'payment_method_id' => $data['payment_method_id'] ?? null,
        ];
    }
}
