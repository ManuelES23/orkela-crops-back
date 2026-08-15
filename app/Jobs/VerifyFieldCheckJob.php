<?php
// sentinel-back/app/Jobs/VerifyFieldCheckJob.php
namespace App\Jobs;

use App\Exceptions\FaceRecognitionException;
use App\Models\SfAttendanceRecord;
use App\Models\SfEmployeeFaceTemplate;
use App\Models\SfFieldCheck;
use App\Services\FaceMatchingService;
use App\Services\FaceRecognitionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VerifyFieldCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $fieldCheckId)
    {
    }

    public function handle(): void
    {
        // Resueltos manualmente desde el contenedor (en vez de vía inyección
        // en la firma del método) para que handle() se comporte igual tanto
        // si lo llama el queue worker (dispatch normal) como si se invoca
        // directamente ($job->handle(), como hacen los tests de esta clase).
        // La inyección por firma solo la resuelve automáticamente
        // Illuminate\Queue\CallQueuedHandler cuando el job pasa por la cola;
        // una llamada directa a ->handle() con parámetros tipados y sin
        // valores por defecto produciría un ArgumentCountError.
        $faceService = app(FaceRecognitionService::class);
        $matchService = app(FaceMatchingService::class);

        $check = SfFieldCheck::find($this->fieldCheckId);
        if (! $check) {
            return;
        }

        if (! $check->sf_employee_id) {
            $check->update(['verification_status' => SfFieldCheck::STATUS_NO_TEMPLATE]);
            return;
        }

        $template = SfEmployeeFaceTemplate::where('sf_employee_id', $check->sf_employee_id)
            ->where('status', SfEmployeeFaceTemplate::STATUS_ACTIVE)
            ->first();

        if (! $template) {
            $check->update(['verification_status' => SfFieldCheck::STATUS_NO_TEMPLATE]);
            return;
        }

        // La foto de evidencia puede faltar (borrada, sync incompleto,
        // almacenamiento corrupto). El disco 'local' tiene 'throw' => false
        // (ver config/filesystems.php), así que Storage::get() NO lanza
        // excepción para un archivo faltante — simplemente retorna null, que
        // si no se detecta aquí terminaría enviándose como una foto "vacía"
        // al face-service en vez de tratarse como un caso "no se puede
        // verificar". Igual que un fallo del face-service, esto nunca debe
        // tirar el job ni dejar el check atascado en 'pending': se enruta a
        // revisión humana como cualquier otro evento no verificable.
        if (! Storage::disk('local')->exists($check->evidence_photo_path)) {
            $this->markUnverifiable($check);
            return;
        }

        try {
            $photoContents = Storage::disk('local')->get($check->evidence_photo_path);
            $result = $faceService->embed($photoContents);
            $distance = $matchService->euclideanDistance($result['embedding'], $template->embedding);
        } catch (FaceRecognitionException $e) {
            // No se pudo procesar la foto (sin rostro, servicio caído, etc.) — no se pierde el evento, va a revisión.
            $this->markUnverifiable($check);
            return;
        }

        $skewToleranceSeconds = ((int) config('biometrics.clock_skew_tolerance_minutes')) * 60;
        $clockSkewOk = ($check->clock_skew_seconds ?? 0) <= $skewToleranceSeconds;

        // server_confidence guarda la distancia euclidiana (recortada a [0,1]
        // para caber en decimal(5,4) — distancias de embeddings sin relación
        // pueden superar 50 en 128 dimensiones), no una "confianza" invertida:
        // 0.0 = coinciden exactamente, valores mayores = más disímiles.
        $clampedDistance = min($distance, 1);

        if ($check->manual_override || ! $clockSkewOk) {
            $check->update([
                'verification_status' => SfFieldCheck::STATUS_LOW_CONFIDENCE,
                'server_confidence' => $clampedDistance,
            ]);
            return;
        }

        if (! $matchService->isMatch($distance)) {
            $check->update([
                'verification_status' => SfFieldCheck::STATUS_MISMATCH,
                'server_confidence' => $clampedDistance,
            ]);
            return;
        }

        $check->update([
            'verification_status' => SfFieldCheck::STATUS_VERIFIED,
            'server_confidence' => $clampedDistance,
        ]);

        $this->consolidateAttendance($check);
    }

    /**
     * Marca el check como no verificable (foto faltante o irreconocible,
     * face-service caído, etc.): nunca se pierde el evento, siempre queda un
     * estado accionable por un humano en vez de silenciarse o tronar el job.
     */
    private function markUnverifiable(SfFieldCheck $check): void
    {
        $check->update([
            'verification_status' => SfFieldCheck::STATUS_LOW_CONFIDENCE,
            'server_confidence' => null,
        ]);
    }

    /**
     * Recalcula el registro de asistencia del día a partir de TODOS los chequeos verificados
     * de ese empleado en esa fecha (idempotente sin importar el orden de procesamiento).
     */
    private function consolidateAttendance(SfFieldCheck $check): void
    {
        $date = $check->checked_at->toDateString();

        $verifiedChecks = SfFieldCheck::where('sf_employee_id', $check->sf_employee_id)
            ->where('verification_status', SfFieldCheck::STATUS_VERIFIED)
            ->whereDate('checked_at', $date)
            ->get();

        $checkIn = $verifiedChecks->where('type', SfFieldCheck::TYPE_CHECK_IN)->min('checked_at');
        $checkOut = $verifiedChecks->where('type', SfFieldCheck::TYPE_CHECK_OUT)->max('checked_at');

        $payload = [
            'status' => 'present',
            'source_device' => 'field_biometric',
        ];

        if ($checkIn) {
            $payload['check_in'] = $checkIn;
        }
        if ($checkOut) {
            $payload['check_out'] = $checkOut;
        }
        if ($checkIn && $checkOut && $checkOut > $checkIn) {
            $payload['hours_worked'] = round($checkIn->diffInMinutes($checkOut) / 60, 2);
        }

        $record = SfAttendanceRecord::updateOrCreate(
            ['sf_employee_id' => $check->sf_employee_id, 'date' => $date],
            $payload
        );

        // El cast 'date' del modelo solo trunca la hora al *leer* el
        // atributo; al *escribir*, Eloquent siempre serializa con
        // getDateFormat() ('Y-m-d H:i:s'), así que la fila queda guardada
        // como "2026-08-15 00:00:00". En MySQL (producción) la columna DATE
        // descarta la hora al insertar, así que esto es invisible ahí; en
        // SQLite (tests) no se trunca, por lo que sin este ajuste una
        // corrida posterior de updateOrCreate() con la misma fecha no
        // encontraría la fila (comparación de string exacta contra
        // "AAAA-MM-DD 00:00:00" vs "AAAA-MM-DD") y terminaría intentando
        // insertar un duplicado que choca con el índice único
        // (sf_employee_id, date). Normalizamos la columna cruda para que la
        // consolidación sea idempotente sin importar el motor de BD.
        DB::table('sf_attendance_records')->where('id', $record->id)->update(['date' => $date]);
    }
}
