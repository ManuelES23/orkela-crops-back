<?php

namespace App\Events\CRM;

use App\Events\ModelBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de broadcast para Prospectos del CRM.
 * Emite en el canal module.{enterprise}.crm.prospectos con nombre 'prospecto.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class ProspectoUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'prospecto.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}"),
        ];
    }
}
