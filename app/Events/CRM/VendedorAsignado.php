<?php

namespace App\Events\CRM;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * VendedorAsignado
 * Evento de broadcast disparado al (re)asignar un vendedor a un prospecto o
 * cliente. Emite en el canal privado crm.{empresa_id} con nombre
 * 'vendedor.asignado' para que las vistas del CRM refresquen en tiempo real.
 */
class VendedorAsignado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $empresaId,
        public string $entidadType,
        public int $entidadId,
        public ?string $vendedorNombre = null,
        public ?string $asignadoPor = null,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'vendedor.asignado';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("crm.{$this->empresaId}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'entidad_type'    => $this->entidadType,
            'entidad_id'      => $this->entidadId,
            'vendedor_nombre' => $this->vendedorNombre,
            'asignado_por'    => $this->asignadoPor,
            'timestamp'       => now()->toISOString(),
        ];
    }
}
