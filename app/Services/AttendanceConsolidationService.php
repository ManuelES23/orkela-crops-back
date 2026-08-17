<?php
// sentinel-back/app/Services/AttendanceConsolidationService.php
namespace App\Services;

use App\Events\SfAttendanceRecordUpdated;
use App\Models\Enterprise;
use App\Models\SfAttendanceRecord;
use App\Models\SfFieldCheck;
use Illuminate\Support\Facades\DB;

class AttendanceConsolidationService
{
    /**
     * Recalcula el registro de asistencia del día a partir de TODOS los chequeos verificados
     * (verified) o aprobados manualmente (manually_approved) de ese empleado en esa fecha —
     * idempotente sin importar el orden de procesamiento. Usado tanto por VerifyFieldCheckJob
     * (verificación automática) como por SfFieldCheckController::review() (aprobación manual RH).
     */
    public function consolidate(SfFieldCheck $check): void
    {
        $date = $check->checked_at->toDateString();

        $resolvedChecks = SfFieldCheck::where('sf_employee_id', $check->sf_employee_id)
            ->whereIn('verification_status', [SfFieldCheck::STATUS_VERIFIED, SfFieldCheck::STATUS_MANUALLY_APPROVED])
            ->whereDate('checked_at', $date)
            ->get();

        $checkIn = $resolvedChecks->where('type', SfFieldCheck::TYPE_CHECK_IN)->min('checked_at');
        $checkOut = $resolvedChecks->where('type', SfFieldCheck::TYPE_CHECK_OUT)->max('checked_at');

        $existingRecord = SfAttendanceRecord::where('sf_employee_id', $check->sf_employee_id)
            ->where('date', $date)
            ->first();

        $payload = [
            'source_device' => 'field_biometric',
        ];

        // No degradar una clasificación de RH ("el empleado no vino": absent,
        // sick_leave, holiday, half_day...) solo porque un chequeo biométrico
        // se resolvió ese mismo día. Solo se fija/mantiene 'present' cuando no
        // hay registro previo, o cuando el registro existente ya estaba en un
        // estado "sí vino" (present/late) — de lo contrario se conserva el
        // status actual tal cual.
        if (! $existingRecord || in_array($existingRecord->status, ['present', 'late'], true)) {
            $payload['status'] = 'present';
        }

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

        // Ver comentario original en VerifyFieldCheckJob (git blame) — normaliza
        // la columna DATE cruda para que updateOrCreate() sea idempotente sin
        // importar el motor de BD (SQLite en tests vs MySQL en producción).
        DB::table('sf_attendance_records')->where('id', $record->id)->update(['date' => $date]);

        $this->broadcastUpdate($record, $check, $existingRecord === null);
    }

    /**
     * Mejor esfuerzo: si no se puede resolver el slug de la empresa (dato
     * corrupto/borrado), no se rompe la consolidación por esto — solo se omite
     * el broadcast, la asistencia ya quedó guardada correctamente arriba.
     */
    private function broadcastUpdate(SfAttendanceRecord $record, SfFieldCheck $check, bool $wasCreated): void
    {
        $enterpriseSlug = Enterprise::find($check->enterprise_id)?->slug;
        if (! $enterpriseSlug) {
            return;
        }

        $record->load('employee:id,code,checker_key,first_name,last_name,second_last_name');

        broadcast(new SfAttendanceRecordUpdated(
            $wasCreated ? 'created' : 'updated',
            $record->toArray(),
            $enterpriseSlug,
            'administration',
            'personal',
        ));
    }
}
