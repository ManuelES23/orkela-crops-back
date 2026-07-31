<?php

namespace App\Events\CRM;

use App\Events\ModelBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de broadcast para Empresas Externas del CRM.
 * Emite en el canal module.{enterprise}.crm.empresas-externas
 * con nombre 'empresa-externa.updated'.
 */
class EmpresaExternaUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'empresa-externa.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}"),
        ];
    }
}
