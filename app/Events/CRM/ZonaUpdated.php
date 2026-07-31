<?php

namespace App\Events\CRM;

use App\Events\ModelBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de broadcast para Zonas del CRM.
 * Emite en el canal module.{enterprise}.crm.catalogos
 * con nombre 'zona.updated'.
 */
class ZonaUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'zona.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}"),
        ];
    }
}
