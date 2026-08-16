<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Jobs\VerifyFieldCheckJob;
use App\Models\SfEmployee;
use App\Models\SfFieldCheck;
use App\Services\ThumbnailService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SfFieldCheckController extends Controller
{
    private const CURRENT_MODEL_VERSION = 'faceapi-v1';

    public function __construct(private readonly ThumbnailService $thumbnailService)
    {
    }

    /**
     * Paquete de cuadrilla: empleados activos y enrolados de la empresa, con embedding y miniatura.
     */
    public function crewPackage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
        ]);

        $this->authorizeEnterpriseAccess($request, (int) $validated['enterprise_id']);

        $employees = SfEmployee::query()
            ->where('enterprise_id', $validated['enterprise_id'])
            ->where('status', SfEmployee::STATUS_ACTIVE)
            ->whereHas('faceTemplate', function ($q) {
                $q->where('model_version', self::CURRENT_MODEL_VERSION);
            })
            ->with(['faceTemplate' => function ($q) {
                $q->where('model_version', self::CURRENT_MODEL_VERSION);
            }])
            ->get(['id', 'enterprise_id', 'code', 'first_name', 'last_name', 'second_last_name']);

        $rows = $employees->map(function (SfEmployee $employee) {
            $template = $employee->faceTemplate;

            return [
                'id' => $employee->id,
                'code' => $employee->code,
                'full_name' => trim("{$employee->first_name} {$employee->last_name} {$employee->second_last_name}"),
                'embedding' => $template->embedding,
                'thumbnail' => $this->thumbnailService->makeThumbnailDataUri($template->photo_path),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'model_version' => self::CURRENT_MODEL_VERSION,
                'generated_at' => now()->toIso8601String(),
                'employees' => $rows,
            ],
        ]);
    }

    /**
     * Sincroniza un lote de chequeos de campo. Idempotente por client_uuid.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'checks' => 'required|array|min:1|max:20',
            'checks.*.client_uuid' => 'required|uuid',
            'checks.*.sf_employee_id' => [
                'nullable',
                Rule::exists('sf_employees', 'id')->where(function ($query) use ($request) {
                    $query->where('enterprise_id', $request->input('enterprise_id'));
                }),
            ],
            'checks.*.type' => 'required|in:' . SfFieldCheck::TYPE_CHECK_IN . ',' . SfFieldCheck::TYPE_CHECK_OUT,
            'checks.*.checked_at' => 'required|date',
            'checks.*.device_synced_at' => 'required|date',
            'checks.*.evidence_photo' => 'required|string',
            // Límite superior deliberadamente NO puesto aquí como regla de validación
            // (p.ej. |max:9.9999): Laravel invalida el REQUEST completo si cualquier
            // item del arreglo falla una regla, tumbando todo el lote de hasta 20
            // checks por un solo valor corrupto. En vez de eso, un valor fuera de
            // rango para decimal(5,4) se rechaza per-item dentro del loop (igual que
            // el caso de foto base64 inválida, ver más abajo) para no perder el resto
            // del lote.
            'checks.*.client_confidence' => 'nullable|numeric|min:0',
            'checks.*.manual_override' => 'nullable|boolean',
            'checks.*.latitude' => 'nullable|numeric|between:-90,90',
            'checks.*.longitude' => 'nullable|numeric|between:-180,180',
            'checks.*.device_info' => 'nullable|array',
        ]);

        $this->authorizeEnterpriseAccess($request, (int) $validated['enterprise_id']);

        $results = [];

        // Máximo storable en la columna decimal(5,4) de sf_field_checks.client_confidence.
        $clientConfidenceMax = 9.9999;

        foreach ($validated['checks'] as $item) {
            $existing = SfFieldCheck::where('client_uuid', $item['client_uuid'])->first();
            if ($existing) {
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'duplicate'];
                continue;
            }

            $clientConfidence = $item['client_confidence'] ?? null;
            if ($clientConfidence !== null && $clientConfidence > $clientConfidenceMax) {
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'rejected', 'reason' => 'client_confidence fuera de rango'];
                continue;
            }

            $decodedPhoto = $this->decodeBase64Photo($item['evidence_photo']);
            if ($decodedPhoto === null || strlen($decodedPhoto) > 2 * 1024 * 1024) {
                $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'rejected', 'reason' => 'foto de evidencia inválida o demasiado grande'];
                continue;
            }

            $photoPath = 'private/sf-field-checks-evidence/' . $item['client_uuid'] . '.jpg';
            Storage::disk('local')->put($photoPath, $decodedPhoto);

            // checked_at es cuándo el DISPOSITIVO capturó el chequeo (puede ser horas
            // atrás si estaba offline) — se guarda tal cual, sigue siendo el timestamp
            // real de asistencia. device_synced_at es la hora que el reloj del
            // dispositivo marca AHORA MISMO, en el instante del sync — comparado
            // contra now() (hora del servidor en ese mismo instante), esto sí mide
            // desfase de reloj real entre dispositivo y servidor, independiente de
            // cuánto tiempo estuvo el dispositivo desconectado.
            $checkedAt = Carbon::parse($item['checked_at']);
            $deviceSyncedAt = Carbon::parse($item['device_synced_at']);
            $clockSkewSeconds = abs(now()->diffInSeconds($deviceSyncedAt));

            $check = SfFieldCheck::create([
                'enterprise_id' => $validated['enterprise_id'],
                'client_uuid' => $item['client_uuid'],
                'sf_employee_id' => $item['sf_employee_id'] ?? null,
                'checked_by_user_id' => $request->user()->id,
                'type' => $item['type'],
                'checked_at' => $checkedAt,
                'synced_at' => now(),
                'evidence_photo_path' => $photoPath,
                'client_confidence' => $clientConfidence,
                'verification_status' => SfFieldCheck::STATUS_PENDING,
                'manual_override' => $item['manual_override'] ?? false,
                'latitude' => $item['latitude'] ?? null,
                'longitude' => $item['longitude'] ?? null,
                'device_info' => $item['device_info'] ?? null,
                'clock_skew_seconds' => $clockSkewSeconds,
            ]);

            VerifyFieldCheckJob::dispatch($check->id);

            $results[] = ['client_uuid' => $item['client_uuid'], 'status' => 'accepted'];
        }

        return response()->json(['success' => true, 'data' => ['results' => $results]]);
    }

    /**
     * Listado paginado de chequeos de campo, con filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'sf_employee_id' => 'nullable|exists:sf_employees,id',
            'verification_status' => 'nullable|in:pending,verified,low_confidence,mismatch,no_template,manually_approved,rejected',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $this->authorizeEnterpriseAccess($request, (int) $validated['enterprise_id']);

        $query = SfFieldCheck::query()
            ->with(['employee:id,enterprise_id,code,first_name,last_name,second_last_name', 'checkedBy:id,name'])
            ->where('enterprise_id', $validated['enterprise_id'])
            ->when($validated['sf_employee_id'] ?? null, fn ($q, $v) => $q->where('sf_employee_id', $v))
            ->when($validated['verification_status'] ?? null, fn ($q, $v) => $q->where('verification_status', $v))
            ->when(($validated['start_date'] ?? null) && ($validated['end_date'] ?? null), fn ($q) => $q->whereBetween('checked_at', [$validated['start_date'], $validated['end_date']]))
            ->orderByDesc('checked_at');

        return response()->json([
            'success' => true,
            'data' => $query->paginate((int) $request->get('per_page', 50)),
        ]);
    }

    /**
     * Verifica que el usuario autenticado pertenece a la empresa solicitada.
     *
     * User::hasEnterpriseAccess() existe pero recibe un slug (string), no un id
     * — este controller trabaja con enterprise_id (numérico, ya validado con
     * exists:enterprises,id) en las 3 rutas que expone. En vez de traducir el
     * id a slug con una consulta extra solo para volver a filtrar el mismo
     * pivot por slug, se consulta directamente la relación activeEnterprises()
     * (el mismo pivot user_enterprises con is_active=true que hasEnterpriseAccess()
     * usa por debajo) filtrando por enterprises.id.
     */
    private function authorizeEnterpriseAccess(Request $request, int $enterpriseId): void
    {
        abort_unless(
            $request->user()->activeEnterprises()->where('enterprises.id', $enterpriseId)->exists(),
            403,
            'No tienes acceso a esta empresa'
        );
    }

    private function decodeBase64Photo(string $data): ?string
    {
        if (str_contains($data, 'base64,')) {
            $data = substr($data, strpos($data, 'base64,') + 7);
        }

        $decoded = base64_decode($data, true);

        return $decoded === false ? null : $decoded;
    }
}
