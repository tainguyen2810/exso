<?php

namespace Modules\Report\Support;

use Carbon\CarbonImmutable;

/**
 * Time buckets for "Nhóm theo": ngày, tuần, tháng, quý, năm.
 */
final class Period
{
    public const GROUPS = ['day' => 'Ngày', 'week' => 'Tuần', 'month' => 'Tháng', 'quarter' => 'Quý', 'year' => 'Năm'];

    /**
     * @return array{label: string, options: array<string, string>, default: string}
     */
    public static function option(): array
    {
        return ['label' => 'Nhóm theo', 'options' => self::GROUPS, 'default' => 'day'];
    }

    /** Sortable key of the bucket holding $date (Y-m-d). */
    public static function key(string $date, string $group): string
    {
        $day = CarbonImmutable::parse($date);

        return match ($group) {
            'week' => $day->startOfWeek()->toDateString(),
            'month' => $day->format('Y-m'),
            'quarter' => $day->year.'-Q'.$day->quarter,
            'year' => (string) $day->year,
            default => $day->toDateString(),
        };
    }

    /** Label of a bucket key, Vietnamese style (28/10/2026, Tuần 26/10/2026, 10/2026...). */
    public static function label(string $key, string $group): string
    {
        return match ($group) {
            'week' => 'Tuần '.CarbonImmutable::parse($key)->format('d/m/Y'),
            'month' => CarbonImmutable::parse($key.'-01')->format('m/Y'),
            'quarter' => 'Quý '.substr($key, -1).'/'.substr($key, 0, 4),
            'year' => 'Năm '.$key,
            default => CarbonImmutable::parse($key)->format('d/m/Y'),
        };
    }

    /**
     * Every bucket key between two dates, for charts without gaps.
     *
     * @return list<string>
     */
    public static function keys(CarbonImmutable $from, CarbonImmutable $to, string $group): array
    {
        $keys = [];

        for ($day = $from->startOfDay(); $day <= $to; $day = $day->addDay()) {
            $keys[self::key($day->toDateString(), $group)] = true;
        }

        return array_keys($keys);
    }
}
