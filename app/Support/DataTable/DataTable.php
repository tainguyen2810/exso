<?php

namespace App\Support\DataTable;

use App\Models\FileLog;
use App\Models\SavedView;
use App\Support\Excel\ExcelExport;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server side list for index pages: search, filters, sorting, paging, saved
 * views and Excel export, all driven by the query string the React
 * DataTable sends (search, filter[key], sort=-column, per_page, page).
 *
 * @template TModel of Model
 */
class DataTable
{
    public const PER_PAGE = [20, 50, 100];

    /** @var list<string> */
    protected array $searchColumns = [];

    /** @var array<string, Closure(Builder<TModel>, mixed): mixed> */
    protected array $filters = [];

    /** @var list<string> */
    protected array $sortable = [];

    protected string $defaultSort = '-id';

    /**
     * @param  Builder<TModel>  $query
     */
    public function __construct(
        protected Builder $query,
        protected Request $request,
        protected string $key,
    ) {}

    /**
     * @template TFor of Model
     *
     * @param  Builder<TFor>  $query
     * @return self<TFor>
     */
    public static function for(Builder $query, Request $request, string $key): self
    {
        return new self($query, $request, $key);
    }

    /**
     * Columns matched with LIKE against the search box. "relation.column"
     * searches through a relation.
     *
     * @param  list<string>  $columns
     * @return $this
     */
    public function search(array $columns): static
    {
        $this->searchColumns = $columns;

        return $this;
    }

    /**
     * Value equals (or is one of a comma separated list).
     *
     * @return $this
     */
    public function filterExact(string $name, ?string $column = null): static
    {
        $column ??= $name;

        return $this->filter($name, function (Builder $query, mixed $value) use ($column): void {
            $values = array_values(array_filter(explode(',', (string) $value), fn (string $item): bool => $item !== ''));

            if ($values !== []) {
                $query->whereIn($query->qualifyColumn($column), $values);
            }
        });
    }

    /**
     * filter[name][from] / filter[name][to] as dd/mm/yyyy or yyyy-mm-dd, whole days.
     *
     * @return $this
     */
    public function filterDateRange(string $name, ?string $column = null): static
    {
        $column ??= $name;

        return $this->filter($name, function (Builder $query, mixed $value) use ($column): void {
            if (! is_array($value)) {
                return;
            }

            $timezone = (string) config('app.timezone');

            if ($from = self::parseDate($value['from'] ?? null, $timezone)) {
                $query->where($query->qualifyColumn($column), '>=', $from->startOfDay());
            }

            if ($to = self::parseDate($value['to'] ?? null, $timezone)) {
                $query->where($query->qualifyColumn($column), '<=', $to->endOfDay());
            }
        });
    }

    /**
     * @param  Closure(Builder<TModel>, mixed): mixed  $apply
     * @return $this
     */
    public function filter(string $name, Closure $apply): static
    {
        $this->filters[$name] = $apply;

        return $this;
    }

    /**
     * @param  list<string>  $columns
     * @return $this
     */
    public function sortable(array $columns, string $default = '-id'): static
    {
        $this->sortable = $columns;
        $this->defaultSort = $default;

        return $this;
    }

    /**
     * The filtered and sorted query, without paging.
     *
     * @return Builder<TModel>
     */
    public function query(): Builder
    {
        $query = clone $this->query;
        $search = $this->searchTerm();

        if ($search !== '' && $this->searchColumns !== []) {
            $query->where(function (Builder $query) use ($search): void {
                foreach ($this->searchColumns as $column) {
                    if (str_contains($column, '.')) {
                        [$relation, $field] = explode('.', $column, 2);
                        $query->orWhereHas($relation, fn (Builder $related) => $related->where($field, 'like', "%{$search}%"));
                    } else {
                        $query->orWhere($query->qualifyColumn($column), 'like', "%{$search}%");
                    }
                }
            });
        }

        foreach ($this->activeFilters() as $name => $value) {
            ($this->filters[$name])($query, $value);
        }

        [$column, $direction] = $this->sort();
        $query->reorder()->orderBy($column === 'id' ? $query->qualifyColumn('id') : $column, $direction);

        if ($column !== 'id') {
            $query->orderBy($query->qualifyColumn('id'), $direction);
        }

        return $query;
    }

    /**
     * @template TRow
     *
     * @param  Closure(TModel): TRow  $map
     * @return array{rows: LengthAwarePaginator<int, TRow>, query: array<string, mixed>, views: list<array{id: int, name: string, query: array<string, mixed>}>}
     */
    public function paginate(Closure $map): array
    {
        $perPage = (int) $this->request->integer('per_page', self::PER_PAGE[0]);
        $perPage = in_array($perPage, self::PER_PAGE, true) ? $perPage : self::PER_PAGE[0];

        /** @var LengthAwarePaginator<int, TRow> $rows */
        $rows = $this->query()->paginate($perPage)->withQueryString()->through($map);

        return [
            'rows' => $rows,
            'query' => $this->state() + ['per_page' => $perPage],
            'views' => $this->views(),
        ];
    }

    /**
     * Stream the filtered list (all pages) as an .xlsx file.
     *
     * @param  array<string, Closure(TModel): mixed>  $columns  heading => value
     */
    public function export(string $filename, array $columns): StreamedResponse
    {
        // Logged on "Xuất/nhập file"; the row count is filled in once streamed.
        $log = FileLog::record($this->key, 'export', str_ends_with($filename, '.xlsx') ? $filename : "{$filename}.xlsx");

        return ExcelExport::download($filename, array_keys($columns), function () use ($columns, $log): iterable {
            $rows = 0;

            try {
                foreach ($this->query()->lazy(500) as $model) {
                    yield array_map(fn (Closure $value): mixed => $value($model), array_values($columns));
                    $rows++;
                }
            } catch (\Throwable $e) {
                $log->update(['status' => 'failed', 'rows' => $rows, 'message' => 'Xuất file bị lỗi.']);

                throw $e;
            }

            $log->update(['status' => 'done', 'rows' => $rows]);
        });
    }

    /**
     * The current search/filter/sort, as sent back to the page and saved in views.
     *
     * @return array{search: string, filter: array<string, mixed>, sort: string}
     */
    public function state(): array
    {
        [$column, $direction] = $this->sort();

        return [
            'search' => $this->searchTerm(),
            'filter' => $this->activeFilters(),
            'sort' => ($direction === 'desc' ? '-' : '').$column,
        ];
    }

    protected function searchTerm(): string
    {
        return mb_substr($this->request->string('search')->trim()->toString(), 0, 100);
    }

    /**
     * @return array<string, mixed>
     */
    protected function activeFilters(): array
    {
        $input = $this->request->query('filter');

        if (! is_array($input)) {
            return [];
        }

        $active = [];

        foreach ($this->filters as $name => $apply) {
            $value = $input[$name] ?? null;

            if (is_array($value)) {
                $value = array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== '');
            }

            if ($value !== null && $value !== '' && $value !== []) {
                $active[$name] = $value;
            }
        }

        return $active;
    }

    /**
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    protected function sort(): array
    {
        $sort = $this->request->string('sort')->toString() ?: $this->defaultSort;
        $column = ltrim($sort, '-');

        if (! in_array($column, $this->sortable, true) && $column !== ltrim($this->defaultSort, '-')) {
            $sort = $this->defaultSort;
            $column = ltrim($sort, '-');
        }

        return [$column, str_starts_with($sort, '-') ? 'desc' : 'asc'];
    }

    /**
     * @return list<array{id: int, name: string, query: array<string, mixed>}>
     */
    protected function views(): array
    {
        $user = $this->request->user();

        if ($user === null) {
            return [];
        }

        return array_values(SavedView::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('table_key', $this->key)
            ->orderBy('name')
            ->get()
            ->map(fn (SavedView $view): array => ['id' => $view->id, 'name' => $view->name, 'query' => $view->query])
            ->all());
    }

    public static function parseDate(mixed $value, string $timezone): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value, $timezone);
            } catch (\Throwable) {
                continue;
            }

            if ($date !== null && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }
}
