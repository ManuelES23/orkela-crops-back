<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\SfEmployee;
use App\Services\ThumbnailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
