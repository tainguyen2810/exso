<?php

namespace Modules\Einvoice\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity\ActivityLogger;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Einvoice\Drivers\EinvoiceException;
use Modules\Einvoice\Drivers\EinvoiceManager;
use Modules\Einvoice\Models\EinvoiceProvider;

/**
 * Cấu hình → Hóa đơn điện tử: connect a provider (account checked before
 * saving, password never sent back to the page), pick the one used to
 * issue, and auto-issue on completed orders.
 */
class ProviderController extends Controller
{
    public function __construct(protected EinvoiceManager $drivers) {}

    public function index(): Response
    {
        $connections = EinvoiceProvider::query()->get()->keyBy('driver');
        $providers = [];

        foreach ($this->drivers->drivers() as $driver => $class) {
            /** @var EinvoiceProvider|null $provider */
            $provider = $connections->get($driver);
            $settings = [];

            foreach (array_keys($class::fields()) as $key) {
                $settings[$key] = $provider?->setting($key);
            }

            $providers[] = [
                'driver' => $driver,
                'name' => $class::label(),
                'fields' => collect($class::fields())->map(fn (array $field, string $key): array => ['key' => $key, 'label' => $field[0], 'help' => $field[1]])->values()->all(),
                'connected' => $provider?->connected_at !== null,
                'is_active' => (bool) $provider?->is_active,
                'is_sandbox' => (bool) $provider?->is_sandbox,
                'username' => $provider?->credential('username'),
                'has_password' => $provider?->credential('password') !== null,
                'settings' => (object) $settings,
                'connected_at' => $provider?->connected_at?->toIso8601String(),
            ];
        }

        return Inertia::render('e-invoices/settings', [
            'providers' => $providers,
            'settings' => ['auto_issue' => (bool) settings('einvoice.auto_issue')],
            // Providers on Sapo's list not connected through EXSO yet.
            'upcoming' => ['MISA meInvoice', 'VNPT Invoice', 'BKAV eHoadon', 'EasyInvoice'],
        ]);
    }

    /**
     * "Kiểm tra kết nối" with what is typed (the saved password when left empty).
     */
    public function verify(Request $request, string $driver): JsonResponse
    {
        abort_unless($this->drivers->has($driver), 404);
        $provider = $this->connection($driver, $this->input($request), $request->user());

        try {
            $this->drivers->for($provider)->verify();
        } catch (EinvoiceException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Kết nối thành công.']);
    }

    public function connect(Request $request, string $driver): RedirectResponse
    {
        abort_unless($this->drivers->has($driver), 404);
        $data = $this->input($request);
        $provider = $this->connection($driver, $data, $request->user());

        if ($provider->credential('password') === null) {
            throw ValidationException::withMessages(['password' => 'Nhập mật khẩu tài khoản.']);
        }

        foreach ($this->drivers->drivers()[$driver]::fields() as $key => $field) {
            if ($provider->setting($key) === null) {
                throw ValidationException::withMessages(["settings.{$key}" => "Nhập {$field[0]}."]);
            }
        }

        if ($provider->is_active) {
            try {
                $this->drivers->for($provider)->verify();
            } catch (EinvoiceException $exception) {
                throw ValidationException::withMessages(['username' => $exception->getMessage()]);
            }
        }

        DB::transaction(function () use ($provider): void {
            $provider->connected_at ??= now();
            $provider->save();

            // One provider issues: turning this one on turns the others off.
            if ($provider->is_active) {
                EinvoiceProvider::query()->whereKeyNot($provider->id)->update(['is_active' => false]);
            }
        });

        $this->toast("Đã lưu kết nối {$provider->name}.");

        return back();
    }

    public function disconnect(string $driver): RedirectResponse
    {
        $provider = EinvoiceProvider::query()->where('driver', $driver)->first();
        abort_if($provider === null, 404);

        // Issued invoices keep the provider (to cancel, adjust, download); only the account is removed.
        $provider->forceFill(['is_active' => false, 'credentials' => null, 'connected_at' => null])->save();
        $this->toast("Đã ngắt kết nối {$provider->name}.");

        return back();
    }

    public function saveSettings(Request $request, SettingsRepository $settings, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate(['auto_issue' => ['required', 'boolean']]);
        $old = (bool) settings('einvoice.auto_issue');
        $settings->setMany(['einvoice.auto_issue' => (bool) $data['auto_issue']]);

        if ($old !== (bool) $data['auto_issue']) {
            $activity->log('updated', 'Sửa cấu hình hóa đơn điện tử', null, ['old' => ['einvoice.auto_issue' => $old], 'new' => ['einvoice.auto_issue' => (bool) $data['auto_issue']]]);
        }

        $this->toast('Đã lưu cấu hình hóa đơn điện tử.');

        return back();
    }

    /**
     * @return array{username: string, password?: string|null, is_sandbox?: bool, is_active?: bool, settings?: array<string, string|null>}
     */
    protected function input(Request $request): array
    {
        /** @var array{username: string, password?: string|null, is_sandbox?: bool, is_active?: bool, settings?: array<string, string|null>} $data */
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'is_sandbox' => ['boolean'],
            'is_active' => ['boolean'],
            'settings' => ['array'],
            'settings.*' => ['nullable', 'string', 'max:100'],
        ], [], ['username' => 'tài khoản', 'password' => 'mật khẩu']);

        return $data;
    }

    /**
     * The saved connection with the form applied (not saved yet).
     *
     * @param  array{username: string, password?: string|null, is_sandbox?: bool, is_active?: bool, settings?: array<string, string|null>}  $data
     */
    protected function connection(string $driver, array $data, ?User $user): EinvoiceProvider
    {
        $class = $this->drivers->drivers()[$driver];
        $provider = EinvoiceProvider::query()->where('driver', $driver)->first()
            ?? new EinvoiceProvider(['driver' => $driver, 'name' => $class::label(), 'created_by' => $user?->id]);

        $settings = $provider->settings ?? [];

        foreach (array_keys($class::fields()) as $key) {
            $value = trim((string) ($data['settings'][$key] ?? ''));
            $settings[$key] = $value === '' ? null : $value;
        }

        $password = $data['password'] ?? null;
        $provider->fill([
            'is_sandbox' => (bool) ($data['is_sandbox'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'settings' => $settings,
        ]);
        $provider->credentials = [
            'username' => trim($data['username']),
            'password' => $password !== null && $password !== '' ? $password : $provider->credential('password'),
        ];

        return $provider;
    }
}
