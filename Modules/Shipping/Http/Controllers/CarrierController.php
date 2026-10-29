<?php

namespace Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\Sequences\Sequence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Shipping\Carriers\CarrierException;
use Modules\Shipping\Carriers\CarrierManager;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\Shipment;

/**
 * "Kết nối đối tác": integrated carriers (connect with the API token, pick
 * the carrier's shop per branch, webhook URL) and partners the store
 * contacts itself (with what is still owed by or to them).
 */
class CarrierController extends Controller
{
    public function __construct(protected CarrierManager $carriers) {}

    public function index(Request $request): Response
    {
        $connections = Carrier::query()->where('kind', Carrier::INTEGRATED)->get()->keyBy('driver');
        $integrated = [];

        foreach ($this->carriers->drivers() as $driver => $class) {
            /** @var Carrier|null $carrier */
            $carrier = $connections->get($driver);
            $integrated[] = [
                'driver' => $driver,
                'name' => $class::label(),
                'connected' => $carrier !== null && $carrier->credential('token') !== null,
                'is_active' => (bool) $carrier?->is_active,
                'is_sandbox' => (bool) $carrier?->is_sandbox,
                'token' => $carrier?->maskedCredential('token'),
                'shop_id' => $carrier?->setting('shop_id'),
                'shop_name' => $carrier?->setting('shop_name'),
                'from_district_id' => $carrier?->setting('from_district_id'),
                'branch_shops' => (object) ($carrier?->setting('branch_shops') ?? []),
                'webhook_url' => $carrier?->webhook_token === null ? null : route('shipping.webhook', $carrier->webhook_token),
                'connected_at' => $carrier?->connected_at?->toIso8601String(),
            ];
        }

        // What each partner still owes: COD of delivered parcels not yet
        // reconciled, minus the fees of finished ones.
        $open = fn (Builder $query) => $query->whereIn('status', [Shipment::DELIVERED, Shipment::RETURNED])->whereNull('reconciled_at');
        $partners = Carrier::query()->where('kind', Carrier::MANUAL)
            ->withCount('shipments')
            ->withSum(['shipments as cod_open' => fn (Builder $query) => $open($query)->where('status', Shipment::DELIVERED)], 'cod_amount')
            ->withSum(['shipments as fee_open' => $open], 'fee')
            ->withSum('shipments as fee_total', 'fee')
            ->orderBy('code')
            ->get();

        return Inertia::render('carriers/index', [
            'integrated' => $integrated,
            'partners' => $partners->map(fn (Carrier $carrier): array => [
                'id' => $carrier->id,
                'code' => $carrier->code,
                'name' => $carrier->name,
                'phone' => $carrier->phone,
                'note' => $carrier->note,
                'is_active' => $carrier->is_active,
                'shipments_count' => (int) $carrier->getAttribute('shipments_count'),
                'debt' => (int) $carrier->getAttribute('cod_open') - (int) $carrier->getAttribute('fee_open'),
                'fee_total' => (int) $carrier->getAttribute('fee_total'),
            ])->all(),
            'branches' => Branch::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Check a token before saving it: returns the carrier's shops.
     */
    public function verify(Request $request, string $driver): JsonResponse
    {
        abort_unless($this->carriers->has($driver), 404);
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:255'],
            'is_sandbox' => ['boolean'],
        ]);

        $carrier = $this->connection($driver) ?? new Carrier(['kind' => Carrier::INTEGRATED, 'driver' => $driver, 'name' => $driver]);
        $carrier->is_sandbox = (bool) ($data['is_sandbox'] ?? false);

        if (! empty($data['token'])) {
            $carrier->credentials = ['token' => trim($data['token'])];
        }

        try {
            return response()->json(['shops' => $this->carriers->for($carrier)->verify()]);
        } catch (CarrierException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Save the connection of an integrated carrier. A new token is checked
     * with the carrier first; leaving it empty keeps the saved one.
     */
    public function connect(Request $request, string $driver): RedirectResponse
    {
        abort_unless($this->carriers->has($driver), 404);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:255'],
            'is_sandbox' => ['boolean'],
            'is_active' => ['boolean'],
            'shop_id' => ['required', 'string', 'max:30'],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'from_district_id' => ['nullable', 'integer', 'min:0'],
            'branch_shops' => ['array'],
            'branch_shops.*' => ['nullable', 'string', 'max:30'],
        ], [], ['token' => 'Token API', 'shop_id' => 'cửa hàng (ShopId)']);

        $carrier = $this->connection($driver) ?? new Carrier([
            'kind' => Carrier::INTEGRATED,
            'driver' => $driver,
            'code' => strtoupper($driver),
            'name' => $this->carriers->drivers()[$driver]::label(),
            'created_by' => $user->id,
        ]);

        if (empty($data['token']) && $carrier->credential('token') === null) {
            throw ValidationException::withMessages(['token' => 'Hãy nhập Token API.']);
        }

        // Shop per branch, only for the user's branches.
        $branchShops = [];

        foreach ((array) ($data['branch_shops'] ?? []) as $branch => $shop) {
            if (is_string($shop) && trim($shop) !== '' && in_array((int) $branch, $user->branchIds(), true)) {
                $branchShops[(string) $branch] = trim($shop);
            }
        }

        $carrier->fill([
            'is_sandbox' => (bool) ($data['is_sandbox'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'settings' => [
                ...($carrier->settings ?? []),
                'shop_id' => trim($data['shop_id']),
                'shop_name' => $data['shop_name'] ?? null,
                'from_district_id' => (int) ($data['from_district_id'] ?? 0) ?: null,
                'branch_shops' => $branchShops,
            ],
        ]);

        if (! empty($data['token'])) {
            $carrier->credentials = ['token' => trim($data['token'])];
        }

        if ($carrier->is_active) {
            try {
                $this->carriers->for($carrier)->verify();
            } catch (CarrierException $exception) {
                throw ValidationException::withMessages(['token' => $exception->getMessage()]);
            }
        }

        $carrier->webhook_token ??= Str::random(48);
        $carrier->connected_at ??= now();
        $carrier->save();

        $this->toast("Đã lưu kết nối {$carrier->name}.");

        return back();
    }

    /**
     * A new webhook URL (the old one stops working).
     */
    public function rotateWebhook(string $driver): RedirectResponse
    {
        $carrier = $this->connection($driver);
        abort_if($carrier === null, 404);

        $carrier->webhook_token = Str::random(48);
        $carrier->save();
        $this->toast('Đã tạo địa chỉ webhook mới. Hãy cập nhật lại ở trang của đối tác.');

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $this->partnerInput($request);

        DB::transaction(fn () => Carrier::query()->create([
            ...$data,
            'kind' => Carrier::MANUAL,
            'code' => Sequence::next('DTVC', Carrier::class),
            'created_by' => $user->id,
        ]));

        $this->toast("Đã thêm đối tác {$data['name']}.");

        return back();
    }

    public function update(Request $request, Carrier $carrier): RedirectResponse
    {
        abort_unless($carrier->kind === Carrier::MANUAL, 404);

        $carrier->update($this->partnerInput($request, $carrier));
        $this->toast("Đã lưu đối tác {$carrier->name}.");

        return back();
    }

    public function destroy(Carrier $carrier): RedirectResponse
    {
        abort_unless($carrier->kind === Carrier::MANUAL, 404);

        if ($carrier->shipments()->exists()) {
            throw ValidationException::withMessages(['carrier' => 'Đối tác đã có vận đơn, chỉ ngừng hoạt động được.']);
        }

        $carrier->delete();
        $this->toast("Đã xóa đối tác {$carrier->name}.");

        return back();
    }

    /**
     * @return array{name: string, phone: string|null, note: string|null, is_active: bool}
     */
    protected function partnerInput(Request $request, ?Carrier $carrier = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('carriers', 'name')->where('tenant_id', tenancy()->id())->whereNull('deleted_at')->ignore($carrier?->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ], [], ['name' => 'tên đối tác']);

        return [
            'name' => trim($data['name']),
            'phone' => $data['phone'] ?? null,
            'note' => $data['note'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    protected function connection(string $driver): ?Carrier
    {
        return Carrier::query()->where('kind', Carrier::INTEGRATED)->where('driver', $driver)->first();
    }
}
