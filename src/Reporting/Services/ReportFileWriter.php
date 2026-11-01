<?php

namespace Src\Reporting\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Escribe un reporte en un archivo local. CSV y Excel van fila a fila; PDF se
 * arma en memoria, así que tiene tope de filas.
 */
final class ReportFileWriter
{
    /**
     * @param  array<string, string>  $columns
     * @param  iterable<array<string, scalar|null>>  $rows
     * @return int Filas escritas
     */
    public function write(string $format, string $title, array $columns, iterable $rows, string $path): int
    {
        return match ($format) {
            'csv' => $this->csv($columns, $rows, $path),
            'xlsx' => $this->xlsx($columns, $rows, $path),
            'pdf' => $this->pdf($title, $columns, $rows, $path),
        };
    }

    private function csv(array $columns, iterable $rows, string $path): int
    {
        $file = fopen($path, 'wb');
        // BOM: sin él, Excel abre el CSV con otra codificación y rompe las tildes.
        fwrite($file, "\xEF\xBB\xBF");
        fputcsv($file, array_values($columns), escape: '');

        $count = 0;

        foreach ($rows as $row) {
            fputcsv($file, array_map($this->csvCell(...), $this->ordered($columns, $row)), escape: '');
            $count++;
        }

        fclose($file);

        return $count;
    }

    /**
     * Una celda que empieza por `=`, `+`, `-` o `@` Excel la ejecuta como fórmula, y
     * descripciones y nombres los escriben usuarios. Los números se quedan como están.
     */
    private function csvCell(mixed $value): mixed
    {
        if (is_string($value) && ! is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    private function xlsx(array $columns, iterable $rows, string $path): int
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_values($columns)));

        $count = 0;

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($this->ordered($columns, $row)));
            $count++;
        }

        $writer->close();

        return $count;
    }

    private function pdf(string $title, array $columns, iterable $rows, string $path): int
    {
        $limit = (int) config('reporting.pdf_max_rows');
        $cells = '';
        $count = 0;

        foreach ($rows as $row) {
            if (++$count > $limit) {
                throw new BusinessRuleException(
                    message: "A PDF report admits up to {$limit} rows.",
                    errorCode: 'REPORT_TOO_LARGE_FOR_PDF',
                );
            }

            $cells .= '<tr>'.implode('', array_map(
                fn (mixed $value): string => '<td>'.e((string) $value).'</td>',
                $this->ordered($columns, $row),
            )).'</tr>';
        }

        $header = implode('', array_map(fn (string $label): string => '<th>'.e($label).'</th>', array_values($columns)));

        file_put_contents($path, Pdf::loadHTML(
            '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:9px}'
            .'table{width:100%;border-collapse:collapse}'
            .'th,td{border:1px solid #ddd;padding:3px;text-align:left}'
            .'th{background:#f4f4f4}'
            .'</style></head><body><h1>'.e($title).'</h1><table><tr>'.$header.'</tr>'.$cells.'</table></body></html>',
        )->setPaper('a4', 'landscape')->output());

        return $count;
    }

    /**
     * @param  array<string, string>  $columns
     * @param  array<string, scalar|null>  $row
     * @return list<scalar|null>
     */
    private function ordered(array $columns, array $row): array
    {
        return array_map(fn (string $key): mixed => $row[$key] ?? null, array_keys($columns));
    }
}
