<?php

namespace Modules\Einvoice\Support;

/**
 * Amount in Vietnamese words, as printed on invoices ("Số tiền viết bằng
 * chữ"); same rules as resources/js/lib/money-words.ts.
 */
class MoneyWords
{
    private const DIGITS = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];

    private const GROUPS = ['', ' nghìn', ' triệu', ' tỷ', ' nghìn tỷ', ' triệu tỷ'];

    /** 1.250.000 → "Một triệu hai trăm năm mươi nghìn đồng". */
    public static function of(int $amount): string
    {
        $value = abs($amount);

        if ($value === 0) {
            return 'Không đồng';
        }

        $parts = [];

        while ($value > 0) {
            $parts[] = $value % 1000;
            $value = intdiv($value, 1000);
        }

        $words = [];

        for ($index = count($parts) - 1; $index >= 0; $index--) {
            if ($parts[$index] === 0) {
                continue;
            }

            $words[] = self::group($parts[$index], $index < count($parts) - 1).self::GROUPS[$index];
        }

        $text = implode(' ', $words).' đồng';

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** One group of three digits, e.g. 105 → "một trăm linh năm". */
    private static function group(int $value, bool $full): string
    {
        $hundreds = intdiv($value, 100);
        $tens = intdiv($value % 100, 10);
        $ones = $value % 10;
        $words = [];

        if ($full || $hundreds > 0) {
            $words[] = self::DIGITS[$hundreds].' trăm';
        }

        if ($tens > 1) {
            $words[] = self::DIGITS[$tens].' mươi';
        } elseif ($tens === 1) {
            $words[] = 'mười';
        } elseif ($ones > 0 && ($full || $hundreds > 0)) {
            $words[] = 'linh';
        }

        if ($ones > 0) {
            $words[] = match (true) {
                $ones === 1 && $tens > 1 => 'mốt',
                $ones === 5 && $tens > 0 => 'lăm',
                $ones === 4 && $tens > 1 => 'tư',
                default => self::DIGITS[$ones],
            };
        }

        return implode(' ', $words);
    }
}
