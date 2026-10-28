<?php

namespace App\Http\Controllers\Config;

use App\Http\Controllers\Controller;
use App\Models\SalesSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Nguồn bán hàng": where orders come from. Exactly one source is the
 * default; the counter source (POS) is built in and cannot be deleted.
 */
class SalesSourceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('config/sources', [
            'sources' => SalesSource::query()->orderByDesc('is_default')->orderBy('id')->get(['id', 'name', 'code', 'is_default', 'is_active']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['code'] = $this->uniqueCode($data['name']);

        $this->save(new SalesSource, $data);

        $this->toast('Đã thêm nguồn bán hàng.');

        return back();
    }

    public function update(Request $request, SalesSource $salesSource): RedirectResponse
    {
        $data = $this->validated($request, $salesSource);

        if ($salesSource->is_default && (! $data['is_default'] || ! $data['is_active'])) {
            return back()->withErrors(['is_default' => 'Chọn nguồn mặc định khác trước khi bỏ mặc định hoặc ngừng áp dụng nguồn này.']);
        }

        $this->save($salesSource, $data);

        $this->toast('Đã lưu nguồn bán hàng.');

        return back();
    }

    public function destroy(SalesSource $salesSource): RedirectResponse
    {
        if ($salesSource->is_default || $salesSource->code === 'POS') {
            $this->toast('Không xóa được nguồn mặc định hoặc nguồn Tại quầy; bạn có thể ngừng áp dụng.', 'error');

            return back();
        }

        $salesSource->delete();

        $this->toast('Đã xóa nguồn bán hàng.');

        return back();
    }

    /**
     * @param  array{name: string, is_default: bool, is_active: bool, code?: string}  $data
     */
    protected function save(SalesSource $source, array $data): void
    {
        DB::transaction(function () use ($source, $data): void {
            if ($data['is_default']) {
                SalesSource::query()->where('is_default', true)->whereKeyNot($source->id ?? 0)->lockForUpdate()->get()
                    ->each(fn (SalesSource $other) => $other->update(['is_default' => false]));
            }

            $source->fill($data)->save();
        });
    }

    /**
     * @return array{name: string, is_default: bool, is_active: bool}
     */
    protected function validated(Request $request, ?SalesSource $source = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('sales_sources')->where('tenant_id', tenancy()->id())->ignore($source)],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ], ['name.unique' => 'Đã có nguồn bán hàng tên này.']);

        $active = (bool) ($data['is_active'] ?? true);

        return [
            'name' => $data['name'],
            // A source that is not used cannot be the default.
            'is_default' => $active && (bool) ($data['is_default'] ?? false),
            'is_active' => $active,
        ];
    }

    protected function uniqueCode(string $name): string
    {
        $base = Str::upper(Str::limit(Str::slug($name, ''), 24, '')) ?: 'NGUON';
        $code = $base;

        for ($i = 2; SalesSource::query()->where('code', $code)->exists(); $i++) {
            $code = $base.$i;
        }

        return $code;
    }
}
