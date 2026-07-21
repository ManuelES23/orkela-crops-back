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
        $parsed = $this->parseWithMeta($file);

        return $parsed['rows'];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, headers: array<int, string>, sample_rows: array<int, array<string, mixed>>}
     */
    public function parseWithMeta(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        if (count($rows) < 2) {
            return [
                'rows' => [],
                'headers' => [],
                'sample_rows' => [],
            ];
        }

        [$headerRowIndex, $headers] = $this->detectHeaders($rows);
        if ($headerRowIndex === null) {
            return [
                'rows' => [],
                'headers' => [],
                'sample_rows' => [],
            ];
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

        return [
            'rows' => $parsedRows,
            'headers' => array_values(array_filter($headers, fn ($header) => $header !== '')),
            'sample_rows' => array_slice($parsedRows, 0, 5),
        ];
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
            $hasProduct = $this->containsAny($candidate, [
                'codigo',
                'codigo_producto',
                'sku',
                'producto',
                'product_name',
                'articulo',
                'item',
                'clave_producto',
                'label',
            ]);
            $hasQty = $this->containsAny($candidate, [
                'cantidad_etiquetas',
                'cantidad',
                'etiquetas',
                'labels',
                'qty',
                'cajas',
                'cantidad_cajas',
                'cantidad_piezas',
            ]);
            $hasLabelData = $this->containsAny($candidate, [
                'grower',
                'grower_name',
                'pack_style',
                'packstyle',
                'pallet_id',
                'pallet_tag',
                'tag',
                'size',
                'label',
            ]);

            if ($hasProduct || $hasQty || $hasLabelData) {
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
        $productCode = $this->firstString($row, [
            'codigo',
            'codigo_producto',
            'product_code',
            'sku',
            'clave',
            'clave_producto',
            'upc',
            'gtin',
            'ean',
        ]);
        $productName = $this->firstString($row, [
            'producto',
            'nombre_producto',
            'product_name',
            'descripcion',
            'description',
            'articulo',
            'item',
            'nombre',
            'label',
        ]);
        $lote = $this->firstString($row, [
            'lote',
            'lote_pt',
            'lot',
            'lote_producto_terminado',
            'lote_produccion',
        ]);
        $presentation = $this->firstString($row, [
            'presentacion',
            'empaque',
            'formato',
            'presentation',
            'tipo_empaque',
            'caja',
            'cajas_tipo',
            'pack_style',
            'packstyle',
            'style_pack',
            'pack_style_description',
        ]);
        $labelName = $this->firstString($row, [
            'label',
            'etiqueta',
            'brand_label',
        ]);
        $palletTag = $this->firstString($row, [
            'pallet_tag',
            'pallet_id',
            'tag_pallet',
            'id_pallet',
            'tarima',
            'id_tarima',
            'tag',
            'tag_id',
            'pallet',
            'pallet_no',
            'pallet_number',
            'tag_or_pallet_id',
        ]);
        $grower = $this->firstString($row, [
            'grower',
            'grower_name',
            'productor',
            'agricultor',
            'proveedor',
        ]);
        $variety = $this->firstString($row, [
            'variedad',
            'variety',
            'cultivar',
            'size',
            'talla',
        ]);
        $productOfCountry = $this->firstString($row, [
            'product_of_country',
            'country',
            'pais',
            'pais_origen',
            'country_of_origin',
        ]);
        $productOfState = $this->firstString($row, [
            'product_of_state',
            'state',
            'estado',
            'estado_origen',
            'state_of_origin',
        ]);

        $packDateRaw = $this->firstValue($row, [
            'fecha',
            'fecha_empaque',
            'pack_date',
            'fecha_produccion',
            'fecha_embarque',
            'fecha_orden',
            'package_date',
            'packaged_date',
        ]);
        $packDate = $this->parseDate($packDateRaw);

        $labelsCountRaw = $this->firstValue($row, [
            'cantidad_etiquetas',
            'etiquetas',
            'labels',
            'labels_count',
            'label_count',
            'numero_etiquetas',
            'num_etiquetas',
        ]);
        $boxesCountRaw = $this->firstValue($row, [
            'cajas',
            'cantidad_cajas',
            'cantidad',
            'qty',
            'boxes',
            'boxes_count',
            'cantidad_piezas',
        ]);
        $labelsCount = is_numeric($labelsCountRaw) ? max(1, (int) $labelsCountRaw) : 1;
        $boxesCount = is_numeric($boxesCountRaw) ? max(1, (int) $boxesCountRaw) : null;

        $serialRaw = $this->firstValue($row, ['serial_reference', 'serial', 'referencia_serial']);
        $serialReference = is_numeric($serialRaw) ? (int) $serialRaw : null;

        $customer = $this->firstString($row, ['cliente', 'customer', 'razon_social']);
        $orderNumber = $this->firstString($row, ['orden_venta', 'order_number', 'pedido', 'ov', 'po']);
        $gtin = $this->firstString($row, ['gtin', 'upc', 'ean']);

        if (! $productCode && ! $productName && ! $lote && ! $palletTag && ! $grower && ! $presentation && ! $variety && ! $labelName) {
            return null;
        }

        return [
            'product_code' => $productCode,
            'product_name' => $productName,
            'label' => $labelName,
            'lote' => $lote,
            'pallet_tag' => $palletTag,
            'grower' => $grower,
            'variety' => $variety,
            'size' => $variety,
            'boxes_count' => $boxesCount,
            'presentation' => $presentation,
            'pack_style' => $presentation,
            'pack_date' => $packDate,
            'product_of_country' => $productOfCountry ? strtoupper($productOfCountry) : null,
            'product_of_state' => $productOfState ? strtoupper($productOfState) : null,
            'labels_count' => $labelsCount,
            'serial_reference' => $serialReference,
            'customer' => $customer,
            'order_number' => $orderNumber,
            'gtin' => $gtin,
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
