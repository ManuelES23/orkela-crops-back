<?php

namespace App\Events\CRM;

use App\Events\ModelBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de broadcast para Bodegas del CRM.
 * Emite en el canal module.{enterprise}.crm.catalogos
 * con nombre 'bodega.updated'.
 */
class BodegaUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'bodega.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}"),
        ];
    }
}
