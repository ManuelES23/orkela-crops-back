<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de asistencia SplendidFarms actualizada (Personal). Se dispara desde
 * AttendanceConsolidationService::consolidate() — cubre tanto la verificación
 * automática (VerifyFieldCheckJob) como la aprobación manual de RH
 * (SfFieldCheckController::review()), ambos caminos hacia el mismo consolidate().
 */
class SfAttendanceRecordUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'sf-attendance-record.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.personal"),
        ];
    }
}
