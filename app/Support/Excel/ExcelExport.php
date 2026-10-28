<?php

namespace App\Support\Excel;

use Closure;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes .xlsx files row by row (OpenSpout), so large lists export without
 * running out of memory on shared hosting.
 */
class ExcelExport
{
    /**
     * @param  list<string>  $headings
     * @param  Closure(): iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $headings, Closure $rows): StreamedResponse
    {
        $filename = str_ends_with($filename, '.xlsx') ? $filename : "{$filename}.xlsx";

        return new StreamedResponse(function () use ($headings, $rows): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            self::write($writer, $headings, $rows());
            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Save to a file (used by tests and background exports).
     *
     * @param  list<string>  $headings
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function store(string $path, array $headings, iterable $rows): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        self::write($writer, $headings, $rows);
        $writer->close();
    }

    /**
     * @param  list<string>  $headings
     * @param  iterable<array<int, mixed>>  $rows
     */
    protected static function write(Writer $writer, array $headings, iterable $rows): void
    {
        $writer->addRow(Row::fromValuesWithStyle($headings, (new Style)->withFontBold(true)));

        foreach ($rows as $values) {
            $writer->addRow(Row::fromValues(array_map(self::cell(...), array_values($values))));
        }
    }

    protected static function cell(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof \DateTimeInterface => $value->format('d/m/Y H:i'),
            $value instanceof \BackedEnum => $value->value,
            is_array($value) => implode(', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value)),
            default => (string) json_encode($value),
        };
    }
}
