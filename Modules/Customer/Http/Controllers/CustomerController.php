<?php

namespace Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FileLog;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\DataTable\DataTable;
use App\Support\Excel\ExcelExport;
use App\Support\Excel\ExcelImport;
use App\Support\Hooks\Hook;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerAddress;
use Modules\Customer\Models\CustomerContact;
use Modules\Customer\Models\CustomerDebt;
use Modules\Customer\Models\CustomerGroup;
use Modules\Customer\Models\CustomerNote;
use Modules\Customer\Services\CustomerService;
use Modules\Product\Models\PriceList;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Khách hàng": list, detail with purchase history / debt / contacts /
 * addresses / notes, create and edit, Excel import and export, and the
 * search used by the order form and the POS.
 *
 * Users with customers.view (without view_all) only reach the customers
 * they are in charge of.
 */
class CustomerController extends Controller
{
    public function __construct(protected CustomerService $customers) {}

    public function index(Request $request): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $table = DataTable::for(Customer::query()->visibleTo($user)->with(['group:id,name', 'assignee:id,name']), $request, 'customers')
            ->search(['code', 'name', 'phone', 'email'])
            ->filterExact('group_id')
            ->filterExact('assigned_to')
            ->filterExact('status')
            ->filter('debt', fn (Builder $query, mixed $value) => match ($value) {
                'owing' => $query->where('debt', '>', 0),
                'credit' => $query->where('debt', '<', 0),
                'none' => $query->where('debt', 0),
                default => null,
            })
            ->filterDateRange('created_at')
            ->filterDateRange('last_order_at')
            ->sortable(['code', 'name', 'debt', 'total_spent', 'orders_count', 'last_order_at', 'created_at']);

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('customers.export'), 403);

            return $table->export('khach-hang-'.now()->format('Ymd-His'), self::exportColumns());
        }

        return Inertia::render('customers/index', [
            'customers' => $table->paginate(fn (Customer $customer): array => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'group' => $customer->group?->name,
                'assignee' => $customer->assignee?->name,
                'debt' => $customer->debt,
                'total_spent' => $customer->total_spent,
                'orders_count' => $customer->orders_count,
                'last_order_at' => $customer->last_order_at?->toIso8601String(),
                'status' => $customer->status,
                'created_at' => $customer->created_at?->toIso8601String(),
            ]),
            'groups' => $this->groupOptions(),
            'staff' => $this->staffOptions(),
        ]);
    }

    /**
     * Customers for pickers (order form F4, POS): recent ones when the box
     * is empty, else matches on name, phone or code.
     */
    public function search(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $raw = mb_substr($request->string('q')->trim()->toString(), 0, 100);
        $term = addcslashes($raw, '%_\\');
        $phone = Customer::normalizePhone($raw);

        $customers = Customer::query()
            ->visibleTo($user)
            ->with('group')
            ->where('status', 'active')
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->when($phone !== null && strlen($phone) >= 3, fn (Builder $query) => $query->orWhere('phone', 'like', "%{$phone}%"))))
            ->orderByDesc($term === '' ? 'updated_at' : 'orders_count')
            ->limit(10)
            ->get();

        return response()->json($customers->map(fn (Customer $customer): array => self::option($customer)));
    }

    /**
     * What a picker shows about a customer (and what the order needs).
     *
     * @return array<string, mixed>
     */
    public static function option(Customer $customer): array
    {
        $customer->loadMissing(['group', 'addresses']);
        $defaults = $customer->salesDefaults();

        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'group' => $customer->group?->name,
            'address' => implode(', ', array_filter([$customer->address, $customer->ward, $customer->district, $customer->province])) ?: null,
            'addresses' => $customer->addresses->map(fn (CustomerAddress $address): array => [
                'id' => $address->id,
                'name' => $address->name,
                'phone' => $address->phone,
                'address' => $address->fullAddress(),
            ])->all(),
            'debt' => $customer->debt,
            'total_spent' => $customer->total_spent,
            'orders_count' => $customer->orders_count,
            'returned_quantity' => $customer->returned_quantity,
            'price_policy' => $defaults['price_list_id'] !== null ? 'list:'.$defaults['price_list_id'] : $defaults['column'],
            'price_label' => $defaults['label'],
            'discount_bp' => $defaults['discount_bp'],
            'payment_method_id' => $defaults['payment_method_id'],
            ...Hook::filter('customer.option', [], $customer),
        ];
    }

    public function create(): Response
    {
        return Inertia::render('customers/form', [
            'customer' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $customer = $this->customers->save(null, $this->validated($request, null));

        // Quick add from the order form / POS: answer with the new customer.
        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(self::option($customer->refresh()), 201);
        }

        $this->toast("Đã thêm khách hàng {$customer->name}.");

        return to_route('customers.show', $customer);
    }

    public function show(Request $request, Customer $customer): Response
    {
        $this->authorizeCustomer($request, $customer);
        $customer->load(['group', 'assignee:id,name', 'priceList:id,name', 'paymentMethod:id,name', 'addresses', 'contacts', 'notes.creator:id,name', 'creator:id,name']);

        return Inertia::render('customers/show', [
            'customer' => [
                ...$this->detail($customer),
                'group' => $customer->group?->only('id', 'name'),
                'assignee' => $customer->assignee?->name,
                'price_label' => $customer->salesDefaults()['label'],
                'customer_price' => $customer->priceList->name ?? ($customer->price_column === null ? null : CustomerGroup::PRICE_COLUMNS[$customer->price_column] ?? null),
                'payment_method' => $customer->paymentMethod?->name,
                'debt' => $customer->debt,
                'total_spent' => $customer->total_spent,
                'orders_count' => $customer->orders_count,
                'returned_quantity' => $customer->returned_quantity,
                'last_order_at' => $customer->last_order_at?->toIso8601String(),
                'creator' => $customer->creator?->name,
                'created_at' => $customer->created_at?->toIso8601String(),
                'addresses' => $customer->addresses->map(fn (CustomerAddress $address): array => [
                    'id' => $address->id,
                    'name' => $address->name,
                    'phone' => $address->phone,
                    'address' => $address->address,
                    'province' => $address->province,
                    'district' => $address->district,
                    'ward' => $address->ward,
                    'full' => $address->fullAddress(),
                    'is_default' => $address->is_default,
                ])->all(),
                'contacts' => $customer->contacts->map(fn (CustomerContact $contact): array => $contact->only('id', 'name', 'phone', 'email', 'position', 'note'))->all(),
                'notes' => $customer->notes->map(fn (CustomerNote $note): array => [
                    'id' => $note->id,
                    'content' => $note->content,
                    'creator' => $note->creator?->name,
                    'created_at' => $note->created_at?->toIso8601String(),
                ])->all(),
            ],
            'debts' => CustomerDebt::query()
                ->with('creator:id,name')
                ->where('customer_id', $customer->id)
                ->latest('id')
                ->limit(200)
                ->get()
                ->map(fn (CustomerDebt $line): array => [
                    'id' => $line->id,
                    'type' => CustomerDebt::TYPES[$line->type] ?? $line->type,
                    'amount' => $line->amount,
                    'debt_after' => $line->debt_after,
                    'reference_code' => $line->reference_code,
                    'reference_url' => Hook::filter('customer.reference_url', null, $line),
                    'note' => $line->note,
                    'creator' => $line->creator?->name,
                    'created_at' => $line->created_at?->toIso8601String(),
                ]),
            // The orders module fills the purchase history.
            'orders' => Hook::filter('customer.orders', [], $customer, $request->user()),
            // Extra cards of other modules (e.g. loyalty points): {title, href?, lines: [{label, value}]}.
            'panels' => Hook::filter('customer.panels', [], $customer, $request->user()),
        ]);
    }

    public function edit(Request $request, Customer $customer): Response
    {
        $this->authorizeCustomer($request, $customer);

        return Inertia::render('customers/form', [
            'customer' => $this->detail($customer),
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($request, $customer);
        $this->customers->save($customer, $this->validated($request, $customer));
        $this->toast("Đã lưu khách hàng {$customer->name}.");

        return to_route('customers.show', $customer);
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($request, $customer);

        try {
            $this->customers->delete($customer);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return back();
        }

        $this->toast("Đã xóa khách hàng {$customer->name}.");

        return to_route('customers.index');
    }

    public function bulk(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'action' => ['required', Rule::in(['delete', 'group', 'active', 'inactive'])],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'group_id' => ['required_if:action,group', 'nullable', 'integer', Rule::exists('customer_groups', 'id')->where('tenant_id', tenancy()->id())->whereNull('deleted_at')],
        ]);

        Gate::authorize($data['action'] === 'delete' ? 'customers.delete' : 'customers.update');

        $done = 0;
        $skipped = 0;

        foreach (Customer::query()->visibleTo($user)->whereKey($data['ids'])->get() as $customer) {
            try {
                match ($data['action']) {
                    'delete' => $this->customers->delete($customer),
                    'group' => $customer->update(['group_id' => (int) $data['group_id']]),
                    default => $customer->update(['status' => $data['action']]),
                };
                $done++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        $this->toast("Đã cập nhật {$done} khách hàng.".($skipped > 0 ? " Bỏ qua {$skipped} khách còn công nợ." : ''), $skipped > 0 ? 'warning' : 'success');

        return back();
    }

    public function addNote(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeCustomer($request, $customer);
        $data = $request->validate(['content' => ['required', 'string', 'max:5000']], [], ['content' => 'ghi chú']);
        $customer->notes()->create(['content' => $data['content'], 'created_by' => $request->user()?->id]);

        return back();
    }

    public function deleteNote(Request $request, Customer $customer, CustomerNote $note): RedirectResponse
    {
        $this->authorizeCustomer($request, $customer);
        abort_unless($note->customer_id === $customer->id, 404);
        $note->delete();

        return back();
    }

    public function saveAddress(Request $request, Customer $customer, ?CustomerAddress $address = null): RedirectResponse
    {
        $this->authorizeCustomer($request, $customer);
        abort_if($address !== null && $address->customer_id !== $customer->id, 404);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'ward' => ['nullable', 'string', 'max:100'],
            'is_default' => ['boolean'],
        ], [], ['address' => 'địa chỉ']);

        DB::transaction(function () use ($customer, $address, $data): void {
            $data['phone'] = Customer::normalizePhone($data['phone'] ?? null);
            $saved = $address ?? $customer->addresses()->make();
            $saved->fill($data)->save();

            if ($saved->is_default || $customer->addresses()->count() === 1) {
                $customer->addresses()->whereKeyNot($saved->id)->update(['is_default' => false]);
                $saved->forceFill(['is_default' => true])->save();
            }
        });

        return back();
    }

    public function deleteAddress(Request $request, Customer $customer, CustomerAddress $address): RedirectResponse
    {
        $this->authorizeCustomer($request, $customer);
        abort_unless($address->customer_id === $customer->id, 404);
        $address->delete();

        return back();
    }

    public function saveContact(Request $request, Customer $customer, ?CustomerContact $contact = null): RedirectResponse
    {
        $this->authorizeCustomer($request, $customer);
        abort_if($contact !== null && $contact->customer_id !== $customer->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'position' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'tên người liên hệ']);

        ($contact ?? $customer->contacts()->make())->fill($data)->save();

        return back();
    }

    public function deleteContact(Request $request, Customer $customer, CustomerContact $contact): RedirectResponse
    {
        $this->authorizeCustomer($request, $customer);
        abort_unless($contact->customer_id === $customer->id, 404);
        $contact->delete();

        return back();
    }

    public function template(): StreamedResponse
    {
        return ExcelExport::download('mau-nhap-khach-hang', array_keys(self::importHeadings()), fn () => [
            ['', 'Nguyễn Văn An', '0901234567', 'an@example.com', 'Bán lẻ', '12 Lê Lợi', 'Hồ Chí Minh', 'Quận 1', 'Phường Bến Nghé', '15/08/1990', 'Nam', '', 'Khách quen'],
        ]);
    }

    /**
     * Import customers: new codes/phones are added, a known code (or phone)
     * updates that customer. Nothing is saved when any row is invalid.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt']], [], ['file' => 'tệp Excel']);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $path = $file->getRealPath().'.'.strtolower($file->getClientOriginalExtension());
        copy($file->getRealPath(), $path);

        try {
            $rows = ExcelImport::rows($path, self::importHeadings());
        } catch (\Throwable) {
            FileLog::record('customers', 'import', $file->getClientOriginalName(), 'failed', 0, 'Không đọc được tệp.');

            return back()->withErrors(['file' => 'Không đọc được tệp. Hãy dùng tệp mẫu .xlsx.']);
        } finally {
            @unlink($path);
        }

        if ($rows === []) {
            return back()->withErrors(['file' => 'Tệp không có dòng dữ liệu nào.']);
        }

        $groups = CustomerGroup::query()->get();
        $errors = [];
        $prepared = [];
        $seenPhones = [];
        $seenCodes = [];

        foreach ($rows as $row) {
            $data = array_map(fn (?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value), $row['data']);
            $messages = [];

            if ($data['name'] === null) {
                $messages[] = 'Thiếu tên khách hàng.';
            }

            $phone = Customer::normalizePhone($data['phone']);
            $code = $data['code'];
            $existing = $code !== null ? Customer::query()->withTrashed()->where('code', $code)->first() : null;
            $existing ??= $phone !== null ? Customer::query()->withTrashed()->where('phone', $phone)->first() : null;

            if ($phone !== null && isset($seenPhones[$phone])) {
                $messages[] = "Số điện thoại {$phone} bị trùng trong tệp.";
            }

            if ($code !== null && isset($seenCodes[$code])) {
                $messages[] = "Mã {$code} bị trùng trong tệp.";
            }

            if ($phone !== null && $existing !== null && $existing->phone !== null && $existing->phone !== $phone) {
                $other = Customer::query()->withTrashed()->where('phone', $phone)->whereKeyNot($existing->id)->first();

                if ($other !== null) {
                    $messages[] = "Số điện thoại {$phone} đã dùng cho khách {$other->code}.";
                }
            }

            $group = null;

            if ($data['group'] !== null) {
                $group = $groups->first(fn (CustomerGroup $item): bool => mb_strtolower($item->name) === mb_strtolower((string) $data['group']) || mb_strtolower($item->code) === mb_strtolower((string) $data['group']));

                if ($group === null) {
                    $messages[] = "Không có nhóm khách hàng \"{$data['group']}\".";
                }
            }

            if ($data['email'] !== null && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
                $messages[] = 'Email không hợp lệ.';
            }

            $birthday = $this->date($data['birthday']);

            if ($data['birthday'] !== null && $birthday === null) {
                $messages[] = 'Ngày sinh phải dạng dd/mm/yyyy.';
            }

            $seenPhones[(string) $phone] = $phone !== null ? true : null;
            $seenCodes[(string) $code] = $code !== null ? true : null;

            if ($messages !== []) {
                $errors[] = "Dòng {$row['line']}: ".implode(' ', $messages);

                continue;
            }

            $gender = match (mb_strtolower((string) $data['gender'])) {
                'nam', 'male' => 'male',
                'nữ', 'nu', 'female' => 'female',
                '' => null,
                default => 'other',
            };

            $prepared[] = [$existing, array_filter([
                'code' => $code,
                'name' => $data['name'],
                'phone' => $phone,
                'email' => $data['email'],
                'group_id' => $group?->id,
                'address' => $data['address'],
                'province' => $data['province'],
                'district' => $data['district'],
                'ward' => $data['ward'],
                'birthday' => $birthday,
                'gender' => $gender,
                'tax_code' => $data['tax_code'],
                'description' => $data['description'],
            ], fn (mixed $value): bool => $value !== null)];
        }

        if ($errors !== []) {
            FileLog::record('customers', 'import', $file->getClientOriginalName(), 'failed', count($rows), 'Có '.count($errors).' dòng lỗi.');

            return back()->withErrors(['file' => implode("\n", array_slice($errors, 0, 20))]);
        }

        DB::transaction(function () use ($prepared): void {
            foreach ($prepared as [$existing, $data]) {
                if ($existing?->trashed()) {
                    $existing->restore();
                }

                $this->customers->save($existing, $data);
            }
        });

        FileLog::record('customers', 'import', $file->getClientOriginalName(), 'done', count($prepared));
        $this->toast('Đã nhập '.count($prepared).' khách hàng.');

        return back();
    }

    /**
     * @return array<string, string>
     */
    protected static function importHeadings(): array
    {
        return [
            'Mã khách hàng' => 'code',
            'Tên khách hàng*' => 'name',
            'Số điện thoại' => 'phone',
            'Email' => 'email',
            'Nhóm khách hàng' => 'group',
            'Địa chỉ' => 'address',
            'Tỉnh/Thành phố' => 'province',
            'Quận/Huyện' => 'district',
            'Phường/Xã' => 'ward',
            'Ngày sinh' => 'birthday',
            'Giới tính' => 'gender',
            'Mã số thuế' => 'tax_code',
            'Ghi chú' => 'description',
        ];
    }

    /**
     * @return array<string, \Closure(Customer): mixed>
     */
    protected static function exportColumns(): array
    {
        return [
            'Mã khách hàng' => fn (Customer $customer) => $customer->code,
            'Tên khách hàng*' => fn (Customer $customer) => $customer->name,
            'Số điện thoại' => fn (Customer $customer) => $customer->phone,
            'Email' => fn (Customer $customer) => $customer->email,
            'Nhóm khách hàng' => fn (Customer $customer) => $customer->group?->name,
            'Địa chỉ' => fn (Customer $customer) => $customer->address,
            'Tỉnh/Thành phố' => fn (Customer $customer) => $customer->province,
            'Quận/Huyện' => fn (Customer $customer) => $customer->district,
            'Phường/Xã' => fn (Customer $customer) => $customer->ward,
            'Ngày sinh' => fn (Customer $customer) => $customer->birthday?->format('d/m/Y'),
            'Giới tính' => fn (Customer $customer) => Customer::GENDERS[$customer->gender ?? ''] ?? null,
            'Mã số thuế' => fn (Customer $customer) => $customer->tax_code,
            'Ghi chú' => fn (Customer $customer) => $customer->description,
            'Công nợ' => fn (Customer $customer) => $customer->debt,
            'Tổng chi tiêu' => fn (Customer $customer) => $customer->total_spent,
            'Số đơn hàng' => fn (Customer $customer) => $customer->orders_count,
            'Nhân viên phụ trách' => fn (Customer $customer) => $customer->assignee?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'gender' => $customer->gender,
            'birthday' => $customer->birthday?->format('d/m/Y'),
            'tax_code' => $customer->tax_code,
            'website' => $customer->website,
            'address' => $customer->address,
            'province' => $customer->province,
            'district' => $customer->district,
            'ward' => $customer->ward,
            'description' => $customer->description,
            'tags' => $customer->tags,
            'group_id' => $customer->group_id,
            'assigned_to' => $customer->assigned_to,
            'price_policy' => $customer->price_list_id !== null ? 'list:'.$customer->price_list_id : $customer->price_column,
            'discount_bp' => $customer->discount_bp,
            'payment_method_id' => $customer->payment_method_id,
            'marketplace_id' => $customer->marketplace_id,
            'source' => $customer->source,
            'status' => $customer->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            'groups' => $this->groupOptions(),
            'staff' => $this->staffOptions(),
            'pricePolicies' => self::pricePolicies(),
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('sort')->get(['id', 'name']),
            'genders' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(Customer::GENDERS), Customer::GENDERS),
        ];
    }

    /**
     * Price columns and active price lists as "retail_price" / "list:<id>".
     *
     * @return list<array{value: string, label: string}>
     */
    public static function pricePolicies(): array
    {
        $options = array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(CustomerGroup::PRICE_COLUMNS), CustomerGroup::PRICE_COLUMNS);

        foreach (PriceList::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']) as $list) {
            $options[] = ['value' => 'list:'.$list->id, 'label' => $list->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    protected function groupOptions(): array
    {
        if (! CustomerGroup::query()->withTrashed()->exists()) {
            CustomerGroup::installDefaults();
        }

        return array_values(CustomerGroup::query()->orderBy('name')->get(['id', 'name'])->map(fn (CustomerGroup $group): array => ['id' => $group->id, 'name' => $group->name])->all());
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    protected function staffOptions(): array
    {
        return array_values(User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->all());
    }

    protected function authorizeCustomer(Request $request, Customer $customer): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($customer->isVisibleTo($user), 403, 'Bạn chỉ xem được khách hàng mình phụ trách.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Customer $customer): array
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = tenancy()->id();

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'gender' => ['nullable', Rule::in(array_keys(Customer::GENDERS))],
            'birthday' => ['nullable', 'string', 'max:20'],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'ward' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'string', 'max:255'],
            'group_id' => ['nullable', 'integer', Rule::exists('customer_groups', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenant)],
            'price_policy' => ['nullable', 'string', Rule::in(array_column(self::pricePolicies(), 'value'))],
            'discount_bp' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('tenant_id', $tenant)],
            'marketplace_id' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ], [], [
            'name' => 'tên khách hàng',
            'phone' => 'số điện thoại',
            'code' => 'mã khách hàng',
            'group_id' => 'nhóm khách hàng',
            'birthday' => 'ngày sinh',
        ]);

        $birthday = $this->date($data['birthday'] ?? null);

        if (($data['birthday'] ?? null) !== null && $birthday === null) {
            throw ValidationException::withMessages(['birthday' => 'Ngày sinh phải dạng dd/mm/yyyy.']);
        }

        $policy = $data['price_policy'] ?? null;
        unset($data['price_policy']);

        return [
            ...$data,
            'birthday' => $birthday,
            'price_column' => $policy !== null && ! str_starts_with($policy, 'list:') ? $policy : null,
            'price_list_id' => $policy !== null && str_starts_with($policy, 'list:') ? (int) substr($policy, 5) : null,
            'status' => $data['status'] ?? $customer->status ?? 'active',
            // New customers are put in charge of whoever adds them.
            'assigned_to' => array_key_exists('assigned_to', $data) ? $data['assigned_to'] : ($customer->assigned_to ?? $user->id),
        ];
    }

    protected function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        foreach (['d/m/Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, trim($value));
            } catch (\Throwable) {
                continue;
            }

            if ($date !== null && $date->format($format) === trim($value)) {
                return $date->toDateString();
            }
        }

        return null;
    }
}
