<?php

namespace App\Support\Excel;

use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads the first sheet of an .xlsx/.csv file into rows keyed by heading.
 * Headings are matched loosely: "Mã chi nhánh", "ma chi nhanh" and
 * "ma_chi_nhanh" are all the key "ma_chi_nhanh".
 */
class ExcelImport
{
    /**
     * @param  array<string, string>  $headings  heading text => field name
     * @return list<array{line: int, data: array<string, string|null>}>
     */
    public static function rows(string $path, array $headings, int $limit = 5000): array
    {
        $map = [];

        foreach ($headings as $heading => $field) {
            $map[self::key($heading)] = $field;
        }

        $sheet = self::read($path, $limit);
        $columns = array_map(fn (string $heading): ?string => $map[self::key($heading)] ?? null, $sheet['headings']);
        $rows = [];

        foreach ($sheet['rows'] as $row) {
            $data = array_fill_keys(array_values($headings), null);

            foreach ($columns as $position => $field) {
                if ($field !== null) {
                    $data[$field] = $row['values'][$position] ?? null;
                }
            }

            $rows[] = ['line' => $row['line'], 'data' => $data];
        }

        return $rows;
    }

    /**
     * The raw first sheet: heading texts and the non-empty rows below them.
     * For files whose columns are not fixed (e.g. one column per branch).
     *
     * @return array{headings: list<string>, rows: list<array{line: int, values: list<string|null>}>}
     */
    public static function read(string $path, int $limit = 5000): array
    {
        $reader = str_ends_with(strtolower($path), '.csv') ? new CsvReader : new XlsxReader;
        $reader->open($path);

        $headings = null;
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $index => $row) {
                $values = array_values(array_map(fn (mixed $value): ?string => self::text($value), $row->toArray()));

                if ($headings === null) {
                    $headings = array_map(fn (?string $heading): string => (string) $heading, $values);

                    continue;
                }

                if (array_filter($values, fn (?string $value): bool => $value !== null) === []) {
                    continue;
                }

                $rows[] = ['line' => (int) $index, 'values' => $values];

                if (count($rows) >= $limit) {
                    break;
                }
            }

            break;
        }

        $reader->close();

        return ['headings' => $headings ?? [], 'rows' => $rows];
    }

    /**
     * Loose heading key: "Mã SKU*" → "ma_sku".
     */
    public static function key(string $heading): string
    {
        return Str::slug(str_replace('*', '', $heading), '_');
    }

    protected static function text(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y');
        }

        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }
}
