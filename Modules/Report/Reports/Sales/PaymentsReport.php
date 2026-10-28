<?php

namespace Modules\Report\Reports\Sales;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Order\Models\OrderPayment;
use Modules\Report\Reports\Report;
use Modules\Report\Support\Period;
use Modules\Report\Support\ReportContext;

/**
 * Báo cáo thanh toán: money customers paid for orders (and refunds), by
 * payment method, time, staff or branch, on the day it was received.
 */
class PaymentsReport extends Report
{
    public const BY = ['method' => 'Phương thức thanh toán', 'time' => 'Thời gian', 'staff' => 'Nhân viên', 'branch' => 'Chi nhánh'];

    public function group(): string
    {
        return 'sales';
    }

    public function name(): string
    {
        return 'payments';
    }

    public function title(): string
    {
        return 'Báo cáo thanh toán';
    }

    public function description(): string
    {
        return 'Tiền khách thanh toán và hoàn trả theo phương thức, thời gian, nhân viên, chi nhánh.';
    }

    public function options(): array
    {
        return [
            'by' => ['label' => 'Theo', 'options' => self::BY, 'default' => 'method'],
            'group_by' => Period::option(),
        ];
    }

    public function columns(ReportContext $context): array
    {
        return [
            ['key' => 'label', 'label' => self::BY[$context->option('by')] ?? 'Nhóm', 'type' => 'text'],
            ['key' => 'count', 'label' => 'Số lần thanh toán', 'type' => 'number'],
            ['key' => 'received', 'label' => 'Tiền thu', 'type' => 'money'],
            ['key' => 'refunded', 'label' => 'Tiền hoàn', 'type' => 'money'],
            ['key' => 'net', 'label' => 'Thực thu', 'type' => 'money'],
        ];
    }

    public function rows(ReportContext $context): array
    {
        $by = $context->option('by');
        $group = $context->option('group_by');
        $buckets = [];

        OrderPayment::query()
            ->whereIn('branch_id', $context->branchIds)
            ->whereBetween('paid_at', [$context->from, $context->to])
            ->when($context->sourceId !== null, fn (Builder $query) => $query->whereHas('order', fn (Builder $order) => $order->where('source_id', $context->sourceId)))
            ->select(['id', 'branch_id', 'payment_method_id', 'method_name', 'amount', 'paid_at', 'created_by'])
            ->orderBy('id')
            ->each(function (OrderPayment $payment) use (&$buckets, $by, $group): void {
                $key = match ($by) {
                    'time' => Period::key($payment->paid_at->setTimezone((string) config('app.timezone'))->toDateString(), $group),
                    'staff' => (string) $payment->created_by,
                    'branch' => (string) $payment->branch_id,
                    default => $payment->method_name,
                };
                $bucket = $buckets[$key] ?? ['count' => 0, 'received' => 0, 'refunded' => 0];
                $bucket['count']++;
                $bucket[$payment->amount >= 0 ? 'received' : 'refunded'] += abs($payment->amount);
                $buckets[$key] = $bucket;
            });

        $names = match ($by) {
            'staff' => User::query()->whereKey(array_filter(array_keys($buckets)))->pluck('name', 'id')->all(),
            'branch' => Branch::query()->whereKey(array_keys($buckets))->pluck('name', 'id')->all(),
            default => [],
        };

        if ($by === 'time') {
            krsort($buckets);
        }

        $rows = [];

        foreach ($buckets as $key => $bucket) {
            $label = match ($by) {
                'time' => Period::label((string) $key, $group),
                'staff', 'branch' => (string) ($names[$key] ?? '—'),
                default => (string) $key,
            };
            $rows[] = ['label' => $label, ...$bucket, 'net' => $bucket['received'] - $bucket['refunded']];
        }

        if ($by !== 'time') {
            usort($rows, fn (array $a, array $b): int => $b['net'] <=> $a['net']);
        }

        return $rows;
    }

    public function chart(ReportContext $context): ?array
    {
        return ['x' => 'label', 'bars' => ['net'], 'line' => null];
    }
}
