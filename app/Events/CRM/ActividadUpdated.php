<?php

namespace App\Events\CRM;

use App\Events\ModelBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de broadcast para Actividades comerciales del CRM.
 * Emite en el canal module.{enterprise}.crm.actividades
 * con nombre 'actividad.updated'.
 */
class ActividadUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'actividad.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}"),
        ];
    }
}
