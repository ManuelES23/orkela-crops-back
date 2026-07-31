<?php

namespace App\Events\CRM;

use App\Events\ModelBroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de broadcast para Contactos polimórficos del CRM.
 * Emite en el canal module.{enterprise}.crm.contactos
 * con nombre 'contacto.updated'.
 */
class ContactoUpdated extends ModelBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'contacto.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("module.{$this->enterprise}.{$this->application}.{$this->module}"),
        ];
    }
}
