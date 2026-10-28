<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FileLog;
use App\Models\SalesSource;
use App\Models\User;
use App\Support\Excel\ExcelExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Report\Models\SalesDaily;
use Modules\Report\Models\SavedReport;
use Modules\Report\Reports\Report;
use Modules\Report\Support\Period;
use Modules\Report\Support\ReportContext;
use Modules\Report\Support\ReportRegistry;
use Modules\Report\Support\SalesMetrics;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Báo cáo: the hub of report cards (like Sapo) and one shared page for every
 * report with its filters, chart, table, totals and Excel export.
 */
class ReportController extends Controller
{
    public const PER_PAGE = 50;

    /** Links to pages of other menus shown on a hub. */
    protected const LINKS = [
        'finance' => [
            ['title' => 'Sổ quỹ', 'description' => 'Tồn đầu kỳ, tổng thu, tổng chi và tồn cuối kỳ của từng quỹ.', 'url' => '/cashbook', 'permission' => 'cashbook.view'],
        ],
        'customers' => [
            ['title' => 'Khách hàng mua nhiều nhất', 'description' => 'Doanh thu, số đơn của từng khách hàng.', 'url' => '/reports/sales/customer', 'permission' => null],
            ['title' => 'Bán hàng theo nhóm khách hàng', 'description' => 'Doanh thu theo nhóm khách hàng.', 'url' => '/reports/sales/customer-group', 'permission' => null],
        ],
    ];

    public function __construct(protected ReportRegistry $registry) {}

    public function index(Request $request): Response
    {
        return $this->hub($request, null);
    }

    public function group(Request $request, string $group): Response
    {
        abort_unless(isset(ReportRegistry::GROUPS[$group]), 404);

        return $this->hub($request, $group);
    }

    protected function hub(Request $request, ?string $current): Response
    {
        /** @var User $user */
        $user = $request->user();
        $sections = [];

        foreach (ReportRegistry::GROUPS as $group => [$title, $description]) {
            if ($current !== null && $group !== $current) {
                continue;
            }

            $reports = array_map(fn (Report $report): array => [
                'key' => $report->key(),
                'title' => $report->title(),
                'description' => $report->description(),
                'url' => $report->url(),
            ], $this->registry->group($group, $user));

            $links = [];

            foreach (self::LINKS[$group] ?? [] as $link) {
                if ($link['permission'] === null || $user->can($link['permission'])) {
                    $links[] = $link;
                }
            }

            if ($reports !== [] || $links !== []) {
                $sections[] = ['key' => $group, 'title' => $title, 'description' => $description, 'reports' => $reports, 'links' => $links];
            }
        }

        $saved = SavedReport::query()->visibleTo($user)->latest('id')->get()
            ->filter(function (SavedReport $saved) use ($user, $current): bool {
                [$group, $name] = array_pad(explode('.', $saved->report, 2), 2, '');
                $report = $this->registry->find($group, $name);

                return $report !== null && ($current === null || $group === $current) && $this->registry->allows($report, $user);
            })
            ->map(fn (SavedReport $saved): array => $this->savedItem($saved, $user))
            ->values()
            ->all();

        return Inertia::render('reports/hub', [
            'current' => $current,
            'groups' => array_map(fn (array $group): string => $group[0], ReportRegistry::GROUPS),
            'sections' => $sections,
            'saved' => $saved,
            'overview' => in_array($current, [null, 'sales'], true) ? $this->overview($user) : null,
        ]);
    }

    /**
     * Doanh thu 7 ngày on the sales hub, from the daily table.
     *
     * @return array<string, mixed>
     */
    protected function overview(User $user): array
    {
        $timezone = (string) config('app.timezone');
        $today = CarbonImmutable::now($timezone);
        $from = $today->subDays(6)->startOfDay();

        $days = SalesDaily::query()
            ->whereIn('branch_id', $user->branchIds())
            ->whereBetween('date', [$from->toDateString(), $today->toDateString()])
            ->groupBy('date')
            ->selectRaw('date, '.SalesMetrics::SUMS)
            ->get()
            ->mapWithKeys(fn (SalesDaily $day): array => [substr((string) $day->getAttribute('date'), 0, 10) => SalesMetrics::from($day->getAttributes())]);

        $cost = $user->can('products.view_cost');
        $chart = [];

        for ($day = $from; $day <= $today; $day = $day->addDay()) {
            $metrics = $days[$day->toDateString()] ?? SalesMetrics::from([]);
            $chart[] = ['date' => $day->toDateString(), 'revenue' => $metrics['revenue'], 'profit' => $cost ? $metrics['gross_profit'] : null];
        }

        $sum = fn (string $key): int => (int) $days->sum($key);

        return [
            'chart' => $chart,
            'revenue' => $sum('revenue'),
            'orders' => $sum('orders_count'),
            'returned' => $sum('returned_amount'),
            'profit' => $cost ? $sum('gross_profit') : null,
        ];
    }

    public function show(Request $request, string $group, string $name): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $report = $this->registry->find($group, $name);

        abort_if($report === null, 404);
        abort_unless($this->registry->allows($report, $user), 403);

        $context = ReportContext::fromRequest($request, $report->options());
        $columns = array_values(array_filter($report->columns($context), fn (array $column): bool => ! ($column['cost'] ?? false) || $context->canViewCost()));
        $hidden = array_values(array_map(fn (array $column): string => $column['key'], array_filter($report->columns($context), fn (array $column): bool => (bool) ($column['cost'] ?? false) && ! $context->canViewCost())));
        $notice = $report->notice($context);
        $rows = array_map(fn (array $row): array => array_diff_key($row, array_flip($hidden)), $report->rows($context));
        $totals = $report->totals($context, $rows);
        $totals = $totals === null ? null : array_diff_key($totals, array_flip($hidden));

        if ($request->query('export') === 'xlsx') {
            abort_unless($user->can('reports.export'), 403);

            return $this->export($report, $context, $columns, $rows, $totals);
        }

        $page = max(1, $request->integer('page', 1));
        $lastPage = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $page = min($page, $lastPage);

        return Inertia::render('reports/view', [
            'report' => [
                'key' => $report->key(),
                'group' => $report->group(),
                'group_title' => ReportRegistry::GROUPS[$report->group()][0] ?? $report->group(),
                'title' => $report->title(),
                'description' => $report->description(),
                'url' => $report->url(),
                'filters' => $report->filters(),
                'options' => array_map(fn (string $key, array $option): array => [
                    'key' => $key,
                    'label' => $option['label'],
                    'options' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($option['options']), $option['options']),
                ], array_keys($report->options()), $report->options()),
            ],
            'siblings' => array_map(fn (Report $sibling): array => ['key' => $sibling->key(), 'title' => $sibling->title(), 'url' => $sibling->url()], $this->registry->group($report->group(), $user)),
            'query' => $context->query(),
            'columns' => $columns,
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'totals' => $totals,
            'pagination' => ['page' => $page, 'last_page' => $lastPage, 'total' => count($rows), 'per_page' => self::PER_PAGE],
            'chart' => $this->chart($report, $context, $columns, $rows),
            'notice' => $notice,
            'branches' => Branch::query()->whereKey($user->branchIds())->orderBy('id')->get(['id', 'name'])->map(fn (Branch $branch): array => ['value' => (string) $branch->id, 'label' => $branch->name])->all(),
            'sources' => in_array('source', $report->filters(), true)
                ? SalesSource::query()->orderBy('id')->get(['id', 'name'])->map(fn (SalesSource $source): array => ['value' => (string) $source->id, 'label' => $source->name])->all()
                : [],
            'saved' => SavedReport::query()->visibleTo($user)->where('report', $report->key())->latest('id')->get()
                ->map(fn (SavedReport $saved): array => $this->savedItem($saved, $user))->all(),
        ]);
    }

    /**
     * Chart points: every bucket of the period for time reports, otherwise
     * the first rows (the biggest).
     *
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    protected function chart(Report $report, ReportContext $context, array $columns, array $rows): ?array
    {
        $chart = $report->chart($context);

        if ($chart === null || $rows === []) {
            return null;
        }

        $labels = array_column($columns, 'label', 'key');
        $series = array_values(array_filter([...$chart['bars'], $chart['line'] ?? null], fn (?string $key): bool => $key !== null && isset($labels[$key])));

        if ($series === []) {
            return null;
        }

        $point = fn (string $label, array $row): array => ['label' => $label, ...array_combine($series, array_map(fn (string $key): int|float => $row[$key] ?? 0, $series))];

        if (($chart['fill'] ?? null) === 'time' && $context->option('group_by') !== '') {
            $byKey = array_column($rows, null, 'key');
            $points = [];

            foreach (Period::keys($context->from, $context->to, $context->option('group_by')) as $key) {
                $points[] = $point(Period::label($key, $context->option('group_by')), $byKey[$key] ?? []);
            }
        } else {
            $points = array_map(fn (array $row): array => $point((string) ($row[$chart['x']] ?? ''), $row), array_slice($rows, 0, $chart['top'] ?? 10));
        }

        return [
            'points' => $points,
            'bars' => array_map(fn (string $key): array => ['key' => $key, 'label' => $labels[$key]], array_values(array_filter($chart['bars'], fn (string $key): bool => isset($labels[$key])))),
            'line' => isset($chart['line'], $labels[$chart['line']]) ? ['key' => $chart['line'], 'label' => $labels[$chart['line']]] : null,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals
     */
    protected function export(Report $report, ReportContext $context, array $columns, array $rows, ?array $totals): StreamedResponse
    {
        $filename = Str::slug($report->title()).'-'.$context->from->format('Ymd').'-'.$context->to->format('Ymd').'.xlsx';
        FileLog::record('reports', 'export', $filename, 'done', count($rows), $report->title());

        return ExcelExport::download($filename, array_column($columns, 'label'), function () use ($columns, $rows, $totals): iterable {
            foreach ($rows as $row) {
                yield array_map(fn (array $column): mixed => $row[$column['key']] ?? null, $columns);
            }

            if ($totals !== null && $totals !== []) {
                yield array_map(fn (array $column): mixed => $column === $columns[0] ? 'Tổng' : ($totals[$column['key']] ?? null), $columns);
            }
        });
    }

    public function save(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'report' => ['required', 'string', 'max:100'],
            'query' => ['nullable', 'array'],
            'query.*' => ['nullable', 'string', 'max:100'],
            'is_shared' => ['boolean'],
        ]);

        [$group, $name] = array_pad(explode('.', $data['report'], 2), 2, '');
        $report = $this->registry->find($group, $name);

        abort_if($report === null, 422);
        abort_unless($this->registry->allows($report, $user), 403);

        // Only the filters the report knows are kept.
        $allowed = ['from', 'to', 'branch_id', 'source_id', ...array_keys($report->options())];
        $query = array_filter(array_intersect_key($data['query'] ?? [], array_flip($allowed)), fn (?string $value): bool => $value !== null && $value !== '');

        SavedReport::query()->create([
            'name' => $data['name'],
            'report' => $report->key(),
            'query' => $query,
            'is_shared' => (bool) ($data['is_shared'] ?? false),
            'user_id' => $user->id,
        ]);

        $this->toast('Đã lưu báo cáo tùy chỉnh.');

        return back();
    }

    public function destroySaved(Request $request, SavedReport $saved): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($saved->user_id === $user->id || $user->is_owner, 403);

        $saved->delete();
        $this->toast('Đã xóa báo cáo tùy chỉnh.');

        return back();
    }

    /**
     * @return array{id: int, name: string, report: string, url: string, is_shared: bool, mine: bool}
     */
    protected function savedItem(SavedReport $saved, User $user): array
    {
        [$group, $name] = array_pad(explode('.', $saved->report, 2), 2, '');
        $query = http_build_query($saved->query ?? []);

        return [
            'id' => $saved->id,
            'name' => $saved->name,
            'report' => $this->registry->find($group, $name)?->title() ?? $saved->report,
            'url' => "/reports/{$group}/{$name}".($query !== '' ? "?{$query}" : ''),
            'is_shared' => $saved->is_shared,
            'mine' => $saved->user_id === $user->id || $user->is_owner,
        ];
    }
}
