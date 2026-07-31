<?php

namespace App\Events\CRM;

use App\Events\ModelBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de broadcast para Productos del CRM.
 * Emite en el canal module.{enterprise}.crm.catalogos
 * con nombre 'producto.updated'.
 */
class ProductoUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'producto.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}"),
        ];
    }
}
