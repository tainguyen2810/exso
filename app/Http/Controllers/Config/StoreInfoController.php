<?php

namespace App\Http\Controllers\Config;

use App\Http\Controllers\Controller;
use App\Support\Activity\ActivityLogger;
use App\Support\Settings\SettingsRepository;
use App\Support\Settings\StoreSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Thông tin cửa hàng": name, contact, address and logo.
 */
class StoreInfoController extends Controller
{
    private const FIELDS = ['store.name', 'store.phone', 'store.email', 'store.business_field', 'store.address', 'store.province', 'store.district', 'store.ward'];

    /** Logos are kept on the private disk and served by logo(), never from /storage. */
    private const LOGO_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

    public function show(SettingsRepository $settings): Response
    {
        $values = [];

        foreach (self::FIELDS as $key) {
            $values[Str::after($key, 'store.')] = $settings->get($key);
        }

        return Inertia::render('config/store', [
            'info' => $values + ['logo_url' => self::logoUrl()],
            'businessFields' => StoreSettings::BUSINESS_FIELDS,
        ]);
    }

    public function update(Request $request, SettingsRepository $settings, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+().\s-]{8,20}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'business_field' => ['nullable', Rule::in(StoreSettings::BUSINESS_FIELDS)],
            'address' => ['required', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'ward' => ['nullable', 'string', 'max:100'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
            'remove_logo' => ['boolean'],
        ], [
            'phone.regex' => 'Số điện thoại không hợp lệ.',
            'logo.mimes' => 'Logo phải là ảnh PNG, JPG hoặc WEBP.',
            'logo.max' => 'Logo tối đa 2MB.',
        ]);

        $old = [];
        $new = [];

        foreach (self::FIELDS as $key) {
            $field = Str::after($key, 'store.');
            $old[$field] = $settings->get($key);
            $new[$field] = $data[$field] ?? null;
        }

        $values = [];

        foreach ($new as $field => $value) {
            $values["store.{$field}"] = $value;
        }

        $currentLogo = $settings->get('store.logo');

        if ($request->hasFile('logo') || $request->boolean('remove_logo')) {
            if (is_string($currentLogo) && $currentLogo !== '') {
                Storage::disk('local')->delete($currentLogo);
            }

            $values['store.logo'] = null;
            $new['logo'] = null;
            $old['logo'] = $currentLogo;
        }

        if ($file = $request->file('logo')) {
            // Extension from the detected content type, not the uploaded name.
            $extension = strtolower((string) $file->extension());
            $extension = array_key_exists($extension, self::LOGO_TYPES) ? $extension : 'png';
            $values['store.logo'] = $file->storeAs('tenants/'.tenancy()->id(), 'logo-'.Str::random(12).'.'.$extension, 'local');
            $new['logo'] = $values['store.logo'];
        }

        $settings->setMany($values);
        $activity->log('updated', 'Sửa thông tin cửa hàng', null, ['old' => $old, 'new' => $new]);

        $this->toast('Đã lưu thông tin cửa hàng.');

        return back();
    }

    /**
     * The store logo, for signed-in users of this store.
     */
    public function logo(SettingsRepository $settings): StreamedResponse
    {
        $path = $settings->get('store.logo');

        abort_unless(is_string($path) && $path !== '' && Storage::disk('local')->exists($path), 404);

        $type = self::LOGO_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function logoUrl(): ?string
    {
        $path = settings('store.logo');

        return is_string($path) && $path !== '' ? '/store-logo?v='.substr(md5($path), 0, 8) : null;
    }
}
