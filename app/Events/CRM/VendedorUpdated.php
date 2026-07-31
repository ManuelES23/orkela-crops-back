<?php

namespace App\Events\CRM;

use App\Events\ModelBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de broadcast para Vendedores del CRM.
 * Emite en el canal module.{enterprise}.crm.catalogos
 * con nombre 'vendedor.updated'.
 */
class VendedorUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'vendedor.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}"),
        ];
    }
}
