<?php

namespace App\Http\Controllers;

use App\Models\SavedView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SavedViewController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'table_key' => ['required', 'string', 'max:50', 'alpha_dash'],
            'name' => ['required', 'string', 'max:100'],
            'query' => ['array'],
            'query.search' => ['nullable', 'string', 'max:100'],
            'query.filter' => ['nullable', 'array'],
            'query.sort' => ['nullable', 'string', 'max:50'],
        ], [], ['name' => 'tên bộ lọc']);

        /** @var array<string, mixed> $query */
        $query = array_filter([
            'search' => $data['query']['search'] ?? null,
            'filter' => $data['query']['filter'] ?? null,
            'sort' => $data['query']['sort'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        SavedView::query()->updateOrCreate(
            ['user_id' => $request->user()?->id, 'table_key' => $data['table_key'], 'name' => $data['name']],
            ['query' => $query],
        );

        $this->toast("Đã lưu bộ lọc \"{$data['name']}\".");

        return back();
    }

    public function destroy(Request $request, SavedView $savedView): RedirectResponse
    {
        abort_unless($savedView->user_id === $request->user()?->id, 403);

        $savedView->delete();

        $this->toast('Đã xóa bộ lọc.');

        return back();
    }
}
