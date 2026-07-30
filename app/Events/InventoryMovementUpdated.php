<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Evento de movimiento de inventario actualizado.
 * Emite en el canal de CADA empresa que tenga una entidad involucrada
 * (origen o destino), permitiendo tiempo real cross-enterprise en transferencias.
 */
class InventoryMovementUpdated extends ModelBroadcastEvent
{
    private array $enterpriseSlugs;

    /**
     * @param string   $action         created | updated | deleted | approved | cancelled
     * @param array    $data           Datos del movimiento (snake_case de Laravel)
     * @param string[] $enterpriseSlugs Slugs de las empresas que deben recibir el evento
     */
    public function __construct(string $action, array $data, array $enterpriseSlugs)
    {
        parent::__construct($action, $data, $enterpriseSlugs[0] ?? null, 'inventario', 'operaciones');
        $this->enterpriseSlugs = array_values(array_unique(array_filter($enterpriseSlugs)));
    }

    public function broadcastAs(): string
    {
        return 'movement.updated';
    }

    /**
     * Emite en el canal de TODAS las empresas involucradas.
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (string $slug) => new PrivateChannel("module.{$slug}.inventario.operaciones"),
            $this->enterpriseSlugs,
        );
    }
}
