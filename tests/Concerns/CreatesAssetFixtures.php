<?php

namespace Tests\Concerns;

use App\Models\AssetCategory;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Enterprise;
use App\Models\UnitOfMeasure;
use App\Models\User;

/**
 * Fixtures mínimos para probar el módulo de Activos Fijos (FixedAsset /
 * AssetCategory): empresa → sucursal → tipo de entidad → entidad, más
 * catálogos de apoyo (tipo/subtipo de activo, marca, unidad de medida).
 */
trait CreatesAssetFixtures
{
    protected User $actingUser;
    protected Enterprise $enterprise;
    protected Branch $branch;
    protected Entity $entity;
    protected AssetCategory $assetCategory;
    protected AssetCategory $assetSubcategory;
    protected Brand $brand;
    protected UnitOfMeasure $unit;

    protected function setUpAssetFixtures(): void
    {
        $this->actingUser = User::factory()->create();

        $this->enterprise = Enterprise::create([
            'name' => 'Splendid Farms',
            'slug' => 'splendidfarms',
            'description' => 'Empresa agrícola de prueba',
            'is_active' => true,
        ]);

        $this->branch = Branch::create([
            'enterprise_id' => $this->enterprise->id,
            'code' => 'SF-MAIN',
            'name' => 'Casa Matriz',
            'slug' => 'casa-matriz',
            'is_active' => true,
            'is_main' => true,
        ]);

        $entityType = EntityType::create([
            'code' => 'OFICINA',
            'name' => 'Oficina',
            'slug' => 'oficina',
            'is_active' => true,
        ]);

        $this->entity = Entity::create([
            'branch_id' => $this->branch->id,
            'entity_type_id' => $entityType->id,
            'code' => 'OFC-001',
            'name' => 'Oficinas Administrativas',
            'slug' => 'oficinas-administrativas',
            'is_active' => true,
        ]);

        $this->assetCategory = AssetCategory::create([
            'code' => 'TAC-001',
            'name' => 'Equipos de cómputo',
            'icon' => 'Laptop',
            'is_active' => true,
        ]);

        $this->assetSubcategory = AssetCategory::create([
            'code' => 'TAC-002',
            'name' => 'Laptops',
            'parent_id' => $this->assetCategory->id,
            'icon' => 'Laptop',
            'is_active' => true,
        ]);

        $this->brand = Brand::create([
            'code' => 'MRC-001',
            'name' => 'Dell',
            'is_active' => true,
        ]);

        $this->unit = UnitOfMeasure::create([
            'code' => 'HRS',
            'name' => 'Horas',
            'abbreviation' => 'hrs',
        ]);
    }

    /**
     * Payload mínimo válido para crear un Activo Fijo.
     */
    protected function validFixedAssetPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Laptop Dell Latitude 5530',
            'category_id' => $this->assetCategory->id,
            'subcategory_id' => $this->assetSubcategory->id,
            'branch_id' => $this->branch->id,
            'entity_id' => $this->entity->id,
            'brand_id' => $this->brand->id,
        ], $overrides);
    }
}
