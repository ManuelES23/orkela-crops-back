<?php

namespace App\Events\CRM;

use App\Events\ModelBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de broadcast para Clientes del CRM.
 * Emite en el canal module.{enterprise}.crm.clientes con nombre 'cliente.updated'.
 * El payload viaja en action + data (created | updated | deleted).
 */
class ClienteUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'cliente.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}"),
        ];
    }
}
