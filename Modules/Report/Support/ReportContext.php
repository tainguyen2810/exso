<?php

namespace Modules\Report\Support;

use App\Models\User;
use App\Support\DataTable\DataTable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Filters every report shares, like Sapo: period, branch (always within the
 * user's own branches) and sales source, plus report options (group by...).
 */
final class ReportContext
{
    /**
     * @param  list<int>  $branchIds
     * @param  array<string, string>  $options
     */
    public function __construct(
        public readonly User $user,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly array $branchIds,
        public readonly ?int $branchId,
        public readonly ?int $sourceId,
        public readonly array $options = [],
    ) {}

    /**
     * @param  array<string, array{label: string, options: array<string, string>, default: string}>  $options  report options
     */
    public static function fromRequest(Request $request, array $options = [], int $defaultDays = 30): self
    {
        /** @var User $user */
        $user = $request->user();
        $timezone = (string) config('app.timezone');
        $today = CarbonImmutable::now($timezone);

        $from = DataTable::parseDate($request->query('from'), $timezone)?->toImmutable()->startOfDay()
            ?? $today->subDays($defaultDays - 1)->startOfDay();
        $to = DataTable::parseDate($request->query('to'), $timezone)?->toImmutable()->endOfDay()
            ?? $today->endOfDay();

        if ($to->lessThan($from)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        // At most about 3 years at once keeps reports quick.
        if ($from->diffInDays($to) > 1100) {
            $from = $to->subDays(1100)->startOfDay();
        }

        $allowed = $user->branchIds();
        $branch = $request->integer('branch_id') ?: null;
        $branch = in_array($branch, $allowed, true) ? $branch : null;

        $values = [];

        foreach ($options as $name => $option) {
            $value = $request->query($name);
            $values[$name] = is_string($value) && isset($option['options'][$value]) ? $value : $option['default'];
        }

        return new self(
            $user,
            $from,
            $to,
            $branch !== null ? [$branch] : $allowed,
            $branch,
            $request->integer('source_id') ?: null,
            $values,
        );
    }

    public function option(string $name): string
    {
        return $this->options[$name] ?? '';
    }

    public function canViewCost(): bool
    {
        return $this->user->can('products.view_cost');
    }

    /** The same length of time just before this period (for "Kỳ trước"). */
    public function previous(): self
    {
        $days = (int) $this->from->diffInDays($this->to->startOfDay()) + 1;

        return new self(
            $this->user,
            $this->from->subDays($days),
            $this->from->subDay()->endOfDay(),
            $this->branchIds,
            $this->branchId,
            $this->sourceId,
            $this->options,
        );
    }

    /**
     * Query string of these filters, for links and saved reports.
     *
     * @return array<string, string|int>
     */
    public function query(): array
    {
        return array_filter([
            'from' => $this->from->format('d/m/Y'),
            'to' => $this->to->format('d/m/Y'),
            'branch_id' => $this->branchId,
            'source_id' => $this->sourceId,
            ...$this->options,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
