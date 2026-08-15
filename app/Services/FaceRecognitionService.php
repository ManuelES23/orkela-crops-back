<?php
// sentinel-back/app/Services/FaceRecognitionService.php
namespace App\Services;

use App\Exceptions\FaceRecognitionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FaceRecognitionService
{
    private const EMBEDDING_SIZE = 128;

    /**
     * Genera el embedding facial canónico a partir de los bytes de una foto.
     *
     * @return array{embedding: float[], model_version: string}
     * @throws FaceRecognitionException
     */
    public function embed(string $photoContents, string $filename = 'photo.jpg'): array
    {
        try {
            $response = Http::withToken(config('services.face_recognition.token'))
                ->timeout((int) config('services.face_recognition.timeout', 15))
                ->attach('photo', $photoContents, $filename)
                ->post(config('services.face_recognition.url') . '/embed');
        } catch (ConnectionException $e) {
            Log::warning('FaceRecognitionService: face-service unreachable', [
                'exception' => $e->getMessage(),
            ]);

            throw new FaceRecognitionException('service_unavailable', $e->getMessage());
        }

        if ($response->status() === 422) {
            $reason = $response->json('error') ?? 'no_face';
            throw new FaceRecognitionException(
                in_array($reason, ['no_face', 'multiple_faces'], true) ? $reason : 'invalid_response'
            );
        }

        if (! $response->successful()) {
            // Distintas causas (401 token mal configurado, 400 imagen
            // inválida, 500 error interno del servicio Node, etc.) todas
            // colapsan al mismo mensaje genérico "service_unavailable" de
            // cara al usuario — eso es intencional para no filtrar detalles
            // internos, pero sin este log un problema de configuración
            // (token incorrecto) sería indistinguible de una caída real del
            // servicio para quien opera el sistema. No se loguean los bytes
            // de la foto enviada, solo el status y el cuerpo de respuesta
            // del face-service.
            Log::warning('FaceRecognitionService: respuesta no exitosa del face-service', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new FaceRecognitionException('service_unavailable', 'HTTP ' . $response->status());
        }

        $embedding = $response->json('embedding');
        $modelVersion = $response->json('model_version');

        if (! is_array($embedding) || count($embedding) !== self::EMBEDDING_SIZE || ! is_string($modelVersion)) {
            throw new FaceRecognitionException('invalid_response');
        }

        return [
            'embedding' => array_map('floatval', $embedding),
            'model_version' => $modelVersion,
        ];
    }
}
