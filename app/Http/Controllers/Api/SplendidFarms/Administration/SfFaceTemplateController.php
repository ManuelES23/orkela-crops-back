<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Exceptions\FaceRecognitionException;
use App\Http\Controllers\Controller;
use App\Models\SfEmployee;
use App\Models\SfEmployeeFaceTemplate;
use App\Services\FaceRecognitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SfFaceTemplateController extends Controller
{
    public function __construct(private readonly FaceRecognitionService $faceService)
    {
    }

    /**
     * Enrolar (o re-enrolar) la plantilla facial de un empleado.
     */
    public function store(Request $request, SfEmployee $sfEmployee): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,jpg,png|max:5120',
            'consent_signed' => 'required|accepted',
            'consent_document' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
        ]);

        try {
            $result = $this->faceService->embed(
                $request->file('photo')->get(),
                $request->file('photo')->getClientOriginalName()
            );
        } catch (FaceRecognitionException $e) {
            $messages = [
                'no_face' => 'No se detectó ningún rostro en la foto. Toma la foto de frente, con buena luz.',
                'multiple_faces' => 'Se detectó más de un rostro. La foto debe contener solo al empleado.',
                'service_unavailable' => 'El servicio de reconocimiento facial no está disponible. Intenta de nuevo.',
                'invalid_response' => 'Respuesta inválida del servicio de reconocimiento facial.',
            ];

            $status = $e->getReason() === 'service_unavailable' ? 503 : 422;

            return response()->json([
                'status' => 'error',
                'message' => $messages[$e->getReason()] ?? $messages['invalid_response'],
            ], $status);
        }

        $photoPath = $request->file('photo')->store('private/sf-face-templates', 'local');

        $consentDocumentPath = null;
        if ($request->hasFile('consent_document')) {
            $consentDocumentPath = $request->file('consent_document')
                ->store('private/sf-face-consents', 'local');
        }

        // Si había plantilla previa, borrar su foto para no acumular datos biométricos huérfanos
        $previous = SfEmployeeFaceTemplate::where('sf_employee_id', $sfEmployee->id)->first();
        if ($previous && $previous->photo_path && Storage::disk('local')->exists($previous->photo_path)) {
            Storage::disk('local')->delete($previous->photo_path);
        }

        $template = SfEmployeeFaceTemplate::updateOrCreate(
            ['sf_employee_id' => $sfEmployee->id],
            [
                'embedding' => $result['embedding'],
                'photo_path' => $photoPath,
                'model_version' => $result['model_version'],
                'enrolled_by_user_id' => $request->user()?->id,
                'enrolled_at' => now(),
                'consent_signed_at' => now(),
                'consent_document_path' => $consentDocumentPath,
                'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
                'revoked_at' => null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Plantilla facial enrolada correctamente',
            'data' => [
                'id' => $template->id,
                'sf_employee_id' => $template->sf_employee_id,
                'model_version' => $template->model_version,
                'enrolled_at' => $template->enrolled_at,
                'status' => $template->status,
            ],
        ], 201);
    }

    /**
     * Revocar la plantilla facial de un empleado.
     */
    public function destroy(Request $request, SfEmployee $sfEmployee): JsonResponse
    {
        $template = SfEmployeeFaceTemplate::where('sf_employee_id', $sfEmployee->id)
            ->where('status', SfEmployeeFaceTemplate::STATUS_ACTIVE)
            ->first();

        if (! $template) {
            return response()->json([
                'status' => 'error',
                'message' => 'El empleado no tiene plantilla facial activa',
            ], 404);
        }

        $template->update([
            'status' => SfEmployeeFaceTemplate::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Plantilla facial revocada correctamente',
            'data' => null,
        ]);
    }
}
