<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class SalesSsccLabelSpreadsheetService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        if (count($rows) < 2) {
            return [];
        }

        [$headerRowIndex, $headers] = $this->detectHeaders($rows);
        if ($headerRowIndex === null) {
            return [];
        }

        $dataRows = array_slice($rows, $headerRowIndex + 1);
        $parsedRows = [];

        foreach ($dataRows as $rowIndex => $row) {
            $mapped = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $mapped[$header] = $row[$index] ?? null;
            }

            $normalized = $this->normalizeRow($mapped);
            if (! $normalized) {
                continue;
            }

            $normalized['row_number'] = $rowIndex + 1;
            $parsedRows[] = $normalized;
        }

        return $parsedRows;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @return array{0: int|null, 1: array<int, string>}
     */
    private function detectHeaders(array $rows): array
    {
        $maxScan = min(5, count($rows));

        for ($i = 0; $i < $maxScan; $i++) {
            $candidate = array_map(fn ($value) => $this->normalizeHeader((string) $value), $rows[$i]);
            $hasProduct = $this->containsAny($candidate, ['codigo', 'codigo_producto', 'sku', 'producto', 'product_name']);
            $hasQty = $this->containsAny($candidate, ['cantidad_etiquetas', 'cantidad', 'etiquetas', 'labels', 'qty']);

            if ($hasProduct || $hasQty) {
                return [$i, $candidate];
            }
        }

        return [0, array_map(fn ($value) => $this->normalizeHeader((string) $value), $rows[0])];
    }

    /**
     * @param array<int, string> $headers
     */
    private function containsAny(array $headers, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (in_array($needle, $headers, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeHeader(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';

        return trim($normalized, '_');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row): ?array
    {
        $productCode = $this->firstString($row, ['codigo', 'codigo_producto', 'product_code', 'sku', 'clave']);
        $productName = $this->firstString($row, ['producto', 'nombre_producto', 'product_name', 'descripcion', 'description']);
        $lote = $this->firstString($row, ['lote', 'lote_pt', 'lot', 'lote_producto_terminado']);
        $presentation = $this->firstString($row, ['presentacion', 'empaque', 'formato', 'presentation']);

        $packDateRaw = $this->firstValue($row, ['fecha', 'fecha_empaque', 'pack_date', 'fecha_produccion']);
        $packDate = $this->parseDate($packDateRaw);

        $labelsCountRaw = $this->firstValue($row, ['cantidad_etiquetas', 'cantidad', 'etiquetas', 'labels', 'qty']);
        $labelsCount = is_numeric($labelsCountRaw) ? max(1, (int) $labelsCountRaw) : 1;

        $serialRaw = $this->firstValue($row, ['serial_reference', 'serial', 'referencia_serial']);
        $serialReference = is_numeric($serialRaw) ? (int) $serialRaw : null;

        if (! $productCode && ! $productName && ! $lote) {
            return null;
        }

        return [
            'product_code' => $productCode,
            'product_name' => $productName,
            'lote' => $lote,
            'presentation' => $presentation,
            'pack_date' => $packDate,
            'labels_count' => $labelsCount,
            'serial_reference' => $serialReference,
            'raw_data' => $row,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function firstValue(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function firstString(array $row, array $keys): ?string
    {
        $value = $this->firstValue($row, $keys);
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }

            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
