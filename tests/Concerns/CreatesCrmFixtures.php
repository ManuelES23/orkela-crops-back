<?php

namespace Tests\Concerns;

use App\Models\CRM\CrmBodega;
use App\Models\CRM\CrmRegion;
use App\Models\CRM\CrmVendedor;
use App\Models\CRM\CrmZona;
use App\Models\Enterprise;
use App\Models\User;

/**
 * Fixtures mínimos para probar el módulo CRM. A diferencia de Activos Fijos
 * (que resuelve la empresa por la URL), el CRM resuelve el contexto de
 * empresa vía el header X-Enterprise-Id (ver FiltraPorEmpresa::getEmpresaId).
 * Por eso los tests deben enviar ese header explícitamente en cada request.
 */
trait CreatesCrmFixtures
{
    protected User $actingUser;
    protected Enterprise $enterprise;
    protected CrmVendedor $vendedor;
    protected CrmRegion $region;
    protected CrmZona $zona;
    protected CrmBodega $bodega;

    protected function setUpCrmFixtures(): void
    {
        $this->actingUser = User::factory()->create();

        $this->enterprise = Enterprise::create([
            'name' => 'Splendid Farms',
            'slug' => 'splendidfarms-crm',
            'description' => 'Empresa de prueba para CRM',
            'is_active' => true,
        ]);

        $vendedorUser = User::factory()->create();

        $this->vendedor = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $vendedorUser->id,
            'nombre' => 'Juan Pérez',
            'email' => 'juan.perez@example.com',
            'activo' => true,
        ]);

        $this->region = CrmRegion::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Noroeste',
        ]);

        $this->zona = CrmZona::create([
            'empresa_id' => $this->enterprise->id,
            'region_id' => $this->region->id,
            'nombre' => 'Sinaloa',
        ]);

        $this->bodega = CrmBodega::create([
            'empresa_id' => $this->enterprise->id,
            'zona_id' => $this->zona->id,
            'nombre' => 'Bodega Los Mochis',
        ]);
    }

    /**
     * Header de contexto de empresa que todos los endpoints CRM requieren.
     */
    protected function crmHeaders(?int $empresaId = null): array
    {
        return ['X-Enterprise-Id' => $empresaId ?? $this->enterprise->id];
    }

    /**
     * Crea una segunda empresa con su propio vendedor, para probar
     * aislamiento multi-tenant.
     */
    protected function crearOtraEmpresa(): Enterprise
    {
        return Enterprise::create([
            'name' => 'Otra Empresa',
            'slug' => 'otra-empresa-crm-'.uniqid(),
            'description' => 'Segunda empresa de prueba (aislamiento)',
            'is_active' => true,
        ]);
    }
}
