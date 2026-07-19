<?php

namespace App\Http\Controllers\Api\SplendidByPorvenir\Sales;

use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\SalesSsccLabel;
use App\Services\SalesSsccLabelSpreadsheetService;
use App\Services\SsccGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SsccLabelController extends Controller
{
    public function __construct(
        private readonly SalesSsccLabelSpreadsheetService $spreadsheetService,
        private readonly SsccGeneratorService $ssccGeneratorService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
            'batch_code' => 'nullable|string|max:80',
            'per_page' => 'nullable|integer|min:5|max:200',
        ]);

        $query = SalesSsccLabel::query()
            ->where('enterprise_id', $this->resolveEnterpriseId($request))
            ->when(! empty($validated['search']), function ($builder) use ($validated) {
                $search = trim($validated['search']);
                $builder->where(function ($nested) use ($search) {
                    $nested->where('sscc', 'like', "%{$search}%")
                        ->orWhere('pallet_tag', 'like', "%{$search}%")
                        ->orWhere('grower', 'like', "%{$search}%")
                        ->orWhere('variety', 'like', "%{$search}%")
                        ->orWhere('product_code', 'like', "%{$search}%")
                        ->orWhere('product_name', 'like', "%{$search}%")
                        ->orWhere('lote', 'like', "%{$search}%");
                });
            })
            ->when(! empty($validated['batch_code']), function ($builder) use ($validated) {
                $builder->where('batch_code', $validated['batch_code']);
            })
            ->orderByDesc('id');

        $labels = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'success' => true,
            'data' => $labels->items(),
            'meta' => [
                'total' => $labels->total(),
                'per_page' => $labels->perPage(),
                'current_page' => $labels->currentPage(),
                'last_page' => $labels->lastPage(),
            ],
        ]);
    }

    public function importExcel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
            'company_prefix' => 'required|string|regex:/^\d{6,12}$/',
            'extension_digit' => 'required|string|regex:/^\d$/',
            'serial_start' => 'nullable|integer|min:0|max:99999999999999999',
        ]);

        $enterpriseId = $this->resolveEnterpriseId($request);
        $parsed = $this->spreadsheetService->parseWithMeta($request->file('file'));
        $rows = $parsed['rows'];

        if (count($rows) === 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron filas validas en el archivo.',
            ], 422);
        }

        $batchCode = 'SSCC-' . now()->format('YmdHis');
        $sourceFile = $request->file('file')->getClientOriginalName();

        $nextSerial = $this->getNextSerial(
            $enterpriseId,
            $validated['company_prefix'],
            $validated['extension_digit'],
            $validated['serial_start'] ?? null,
        );

        $created = 0;

        DB::transaction(function () use (
            $rows,
            $validated,
            $enterpriseId,
            $request,
            $batchCode,
            $sourceFile,
            &$nextSerial,
            &$created
        ) {
            foreach ($rows as $row) {
                $labelsCount = (int) ($row['labels_count'] ?? 1);

                for ($i = 0; $i < $labelsCount; $i++) {
                    $serialReference = $row['serial_reference'] ?? $nextSerial;

                    if ($i > 0 || empty($row['serial_reference'])) {
                        $serialReference = $nextSerial;
                    }

                    $sscc = $this->ssccGeneratorService->generate(
                        $validated['company_prefix'],
                        $validated['extension_digit'],
                        $serialReference,
                    );

                    while (SalesSsccLabel::query()->where('sscc', $sscc)->exists()) {
                        $nextSerial++;
                        $serialReference = $nextSerial;
                        $sscc = $this->ssccGeneratorService->generate(
                            $validated['company_prefix'],
                            $validated['extension_digit'],
                            $serialReference,
                        );
                    }

                    SalesSsccLabel::create([
                        'enterprise_id' => $enterpriseId,
                        'created_by_user_id' => $request->user()?->id,
                        'source_file' => $sourceFile,
                        'batch_code' => $batchCode,
                        'row_number' => $row['row_number'] ?? 0,
                        'product_code' => $row['product_code'] ?? null,
                        'product_name' => $row['product_name'] ?? null,
                        'lote' => $row['lote'] ?? null,
                        'pallet_tag' => $row['pallet_tag'] ?? null,
                        'grower' => $row['grower'] ?? null,
                        'variety' => $row['variety'] ?? null,
                        'boxes_count' => $row['boxes_count'] ?? null,
                        'presentation' => $row['presentation'] ?? null,
                        'pack_date' => $row['pack_date'] ?? null,
                        'sscc' => $sscc,
                        'serial_reference' => $serialReference,
                        'company_prefix' => $validated['company_prefix'],
                        'extension_digit' => $validated['extension_digit'],
                        'status' => 'generated',
                        'raw_data' => $row['raw_data'] ?? null,
                    ]);

                    $created++;
                    $nextSerial = max($nextSerial, (int) $serialReference + 1);
                }
            }
        });

        $labels = SalesSsccLabel::query()
            ->where('enterprise_id', $enterpriseId)
            ->where('batch_code', $batchCode)
            ->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Etiquetas SSCC generadas correctamente.',
            'data' => [
                'batch_code' => $batchCode,
                'created' => $created,
                'labels' => $labels,
                'detected_headers' => $parsed['headers'],
                'sample_rows' => $parsed['sample_rows'],
            ],
        ], 201);
    }

    public function createManual(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_prefix' => 'required|string|regex:/^\d{6,12}$/',
            'extension_digit' => 'required|string|regex:/^\d$/',
            'serial_start' => 'nullable|integer|min:0|max:99999999999999999',
            'items' => 'required|array|min:1|max:500',
            'items.*.pallet_tag' => 'required|string|max:120',
            'items.*.grower' => 'required|string|max:180',
            'items.*.boxes_count' => 'required|integer|min:1|max:999999',
            'items.*.pack_date' => 'required|date',
            'items.*.variety' => 'required|string|max:180',
            'items.*.presentation' => 'required|string|max:180',
            'items.*.product_code' => 'nullable|string|max:120',
            'items.*.product_name' => 'nullable|string|max:255',
            'items.*.lote' => 'nullable|string|max:120',
            'items.*.labels_count' => 'nullable|integer|min:1|max:5000',
            'items.*.serial_reference' => 'nullable|integer|min:0|max:99999999999999999',
        ]);

        $enterpriseId = $this->resolveEnterpriseId($request);
        $batchCode = 'SSCC-MANUAL-' . now()->format('YmdHis');

        $nextSerial = $this->getNextSerial(
            $enterpriseId,
            $validated['company_prefix'],
            $validated['extension_digit'],
            $validated['serial_start'] ?? null,
        );

        $created = 0;

        DB::transaction(function () use (
            $validated,
            $enterpriseId,
            $request,
            $batchCode,
            &$nextSerial,
            &$created
        ) {
            foreach ($validated['items'] as $index => $item) {
                $labelsCount = (int) ($item['labels_count'] ?? 1);

                for ($i = 0; $i < $labelsCount; $i++) {
                    $serialReference = $item['serial_reference'] ?? $nextSerial;

                    if ($i > 0 || empty($item['serial_reference'])) {
                        $serialReference = $nextSerial;
                    }

                    $sscc = $this->ssccGeneratorService->generate(
                        $validated['company_prefix'],
                        $validated['extension_digit'],
                        $serialReference,
                    );

                    while (SalesSsccLabel::query()->where('sscc', $sscc)->exists()) {
                        $nextSerial++;
                        $serialReference = $nextSerial;
                        $sscc = $this->ssccGeneratorService->generate(
                            $validated['company_prefix'],
                            $validated['extension_digit'],
                            $serialReference,
                        );
                    }

                    SalesSsccLabel::create([
                        'enterprise_id' => $enterpriseId,
                        'created_by_user_id' => $request->user()?->id,
                        'source_file' => null,
                        'batch_code' => $batchCode,
                        'row_number' => $index + 1,
                        'product_code' => $item['product_code'] ?? null,
                        'product_name' => $item['product_name'] ?? null,
                        'lote' => $item['lote'] ?? null,
                        'pallet_tag' => $item['pallet_tag'],
                        'grower' => $item['grower'],
                        'variety' => $item['variety'],
                        'boxes_count' => (int) $item['boxes_count'],
                        'presentation' => $item['presentation'],
                        'pack_date' => $item['pack_date'],
                        'sscc' => $sscc,
                        'serial_reference' => $serialReference,
                        'company_prefix' => $validated['company_prefix'],
                        'extension_digit' => $validated['extension_digit'],
                        'status' => 'generated',
                        'raw_data' => $item,
                    ]);

                    $created++;
                    $nextSerial = max($nextSerial, (int) $serialReference + 1);
                }
            }
        });

        $labels = SalesSsccLabel::query()
            ->where('enterprise_id', $enterpriseId)
            ->where('batch_code', $batchCode)
            ->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Etiquetas SSCC generadas manualmente.',
            'data' => [
                'batch_code' => $batchCode,
                'created' => $created,
                'labels' => $labels,
            ],
        ], 201);
    }

    public function previewExcel(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
        ]);

        $parsed = $this->spreadsheetService->parseWithMeta($request->file('file'));

        return response()->json([
            'success' => true,
            'message' => 'Archivo analizado correctamente.',
            'data' => [
                'source_file' => $request->file('file')->getClientOriginalName(),
                'detected_headers' => $parsed['headers'],
                'sample_rows' => $parsed['sample_rows'],
                'valid_rows' => count($parsed['rows']),
            ],
        ]);
    }

    public function markPrinted(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:sales_sscc_labels,id',
        ]);

        $enterpriseId = $this->resolveEnterpriseId($request);

        $updated = SalesSsccLabel::query()
            ->where('enterprise_id', $enterpriseId)
            ->whereIn('id', $validated['ids'])
            ->update([
                'status' => 'printed',
                'printed_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Etiquetas marcadas como impresas.',
            'data' => [
                'updated' => $updated,
            ],
        ]);
    }

    public function destroy(Request $request, SalesSsccLabel $ssccLabel): JsonResponse
    {
        $enterpriseId = $this->resolveEnterpriseId($request);

        if ((int) $ssccLabel->enterprise_id !== (int) $enterpriseId) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes permisos para eliminar esta etiqueta.',
            ], 403);
        }

        $ssccLabel->delete();

        return response()->json([
            'success' => true,
            'message' => 'Etiqueta eliminada correctamente.',
        ]);
    }

    private function resolveEnterpriseId(Request $request): int
    {
        $slug = strtolower(trim((string) $request->header('X-Enterprise-Slug', 'splendidbyporvenir')));
        $enterpriseId = (int) (Enterprise::query()
            ->where('slug', $slug)
            ->value('id') ?? 0);

        if ($enterpriseId > 0) {
            return $enterpriseId;
        }

        return (int) Enterprise::query()
            ->where('slug', 'splendidbyporvenir')
            ->value('id');
    }

    private function getNextSerial(int $enterpriseId, string $companyPrefix, string $extensionDigit, ?int $serialStart): int
    {
        $lastSerial = (int) (SalesSsccLabel::query()
            ->where('enterprise_id', $enterpriseId)
            ->where('company_prefix', $companyPrefix)
            ->where('extension_digit', $extensionDigit)
            ->max('serial_reference') ?? 0);

        if ($serialStart === null) {
            return $lastSerial + 1;
        }

        return max($lastSerial + 1, $serialStart);
    }
}
