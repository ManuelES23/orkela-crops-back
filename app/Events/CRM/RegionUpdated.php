<?php

namespace App\Events\CRM;

use App\Events\ModelBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de broadcast para Regiones del CRM.
 * Emite en el canal module.{enterprise}.crm.catalogos
 * con nombre 'region.updated'.
 */
class RegionUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'region.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}"),
        ];
    }
}
