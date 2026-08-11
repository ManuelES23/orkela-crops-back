<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Area;
use App\Models\FixedAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

class FixedAssetControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    private const BASE_URL = '/api/splendidfarms/inventario/activos-fijos/activos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_puede_listar_activos_fijos(): void
    {
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001']));

        $response = $this->getJson(self::BASE_URL);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'AF-000001');
    }

    public function test_puede_crear_un_activo_fijo_con_codigo_automatico(): void
    {
        $response = $this->postJson(self::BASE_URL, $this->validFixedAssetPayload());

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.code', 'AF-000001')
            ->assertJsonPath('data.name', 'Laptop Dell Latitude 5530')
            ->assertJsonPath('data.category.id', $this->assetCategory->id)
            ->assertJsonPath('data.subcategory.id', $this->assetSubcategory->id)
            ->assertJsonPath('data.brand.id', $this->brand->id);

        $this->assertDatabaseHas('fixed_assets', [
            'code' => 'AF-000001',
            'name' => 'Laptop Dell Latitude 5530',
            'branch_id' => $this->branch->id,
            'entity_id' => $this->entity->id,
        ]);
    }

    public function test_los_codigos_automaticos_son_secuenciales(): void
    {
        $this->postJson(self::BASE_URL, $this->validFixedAssetPayload(['name' => 'Activo 1']));
        $response = $this->postJson(self::BASE_URL, $this->validFixedAssetPayload(['name' => 'Activo 2']));

        $response->assertJsonPath('data.code', 'AF-000002');
    }

    public function test_requiere_nombre_tipo_de_activo_sucursal_y_entidad(): void
    {
        $response = $this->postJson(self::BASE_URL, []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'category_id', 'branch_id', 'entity_id']);
    }

    public function test_rechaza_una_entidad_que_no_pertenece_a_la_sucursal_indicada(): void
    {
        $otraSucursal = \App\Models\Branch::create([
            'enterprise_id' => $this->enterprise->id,
            'code' => 'SF-OTRA',
            'name' => 'Otra sucursal',
            'slug' => 'otra-sucursal',
            'is_active' => true,
        ]);

        $response = $this->postJson(self::BASE_URL, $this->validFixedAssetPayload([
            'branch_id' => $otraSucursal->id, // la entidad pertenece a $this->branch, no a esta
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('message', 'La entidad seleccionada no pertenece a la sucursal indicada');
    }

    public function test_rechaza_un_area_que_no_pertenece_a_la_entidad_indicada(): void
    {
        $area = Area::create([
            'code' => 'ARE-001',
            'name' => 'Área sin asignar',
            'slug' => 'area-sin-asignar',
            'is_active' => true,
        ]);
        // Nota: intencionalmente NO se vincula $area a $this->entity vía entity_area

        $response = $this->postJson(self::BASE_URL, $this->validFixedAssetPayload([
            'area_id' => $area->id,
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El área seleccionada no pertenece a la entidad indicada');
    }

    public function test_acepta_un_area_correctamente_vinculada_a_la_entidad(): void
    {
        $area = Area::create([
            'code' => 'ARE-002',
            'name' => 'Recepción',
            'slug' => 'recepcion',
            'is_active' => true,
        ]);
        DB::table('entity_area')->insert([
            'entity_id' => $this->entity->id,
            'area_id' => $area->id,
            'is_active' => true,
            'allows_inventory' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson(self::BASE_URL, $this->validFixedAssetPayload([
            'area_id' => $area->id,
        ]));

        $response->assertCreated()->assertJsonPath('data.area.id', $area->id);
    }

    public function test_puede_ver_el_detalle_de_un_activo(): void
    {
        $asset = FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001']));

        $response = $this->getJson(self::BASE_URL."/{$asset->id}");

        $response->assertOk()->assertJsonPath('data.id', $asset->id);
    }

    public function test_puede_actualizar_un_activo(): void
    {
        $asset = FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001']));

        $response = $this->putJson(self::BASE_URL."/{$asset->id}", $this->validFixedAssetPayload([
            'name' => 'Laptop Dell Latitude 5530 (actualizada)',
            'status' => 'en_mantenimiento',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.name', 'Laptop Dell Latitude 5530 (actualizada)')
            ->assertJsonPath('data.status', 'en_mantenimiento');
    }

    public function test_puede_eliminar_un_activo_con_soft_delete(): void
    {
        $asset = FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001']));

        $response = $this->deleteJson(self::BASE_URL."/{$asset->id}");

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSoftDeleted('fixed_assets', ['id' => $asset->id]);
    }

    public function test_endpoint_de_siguiente_codigo_no_colisiona_con_existentes(): void
    {
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000005']));

        $response = $this->getJson(self::BASE_URL.'/next-code');

        $response->assertOk()->assertJsonPath('data.code', 'AF-000006');
    }

    public function test_filtra_por_estado(): void
    {
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000001', 'status' => 'en_uso']));
        FixedAsset::create($this->validFixedAssetPayload(['code' => 'AF-000002', 'status' => 'baja']));

        $response = $this->getJson(self::BASE_URL.'?status=baja');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'AF-000002');
    }

    public function test_al_crear_registra_caracteristicas_nuevas_en_el_catalogo_del_subtipo(): void
    {
        $payload = $this->validFixedAssetPayload();
        $payload['characteristics'] = [
            ['name' => 'Procesador', 'value' => 'Intel Core i7'],
            ['name' => 'RAM', 'value' => '16GB'],
        ];

        $response = $this->postJson(self::BASE_URL, $payload);

        $response->assertCreated()->assertJsonCount(2, 'data.characteristics');

        $this->assertDatabaseHas('asset_characteristic_definitions', [
            'category_id' => $this->assetSubcategory->id,
            'name' => 'Procesador',
        ]);
        $this->assertDatabaseHas('fixed_asset_characteristics', [
            'name' => 'RAM',
            'value' => '16GB',
        ]);
    }

    public function test_reutiliza_una_definicion_de_caracteristica_existente_en_vez_de_duplicarla(): void
    {
        $definition = \App\Models\AssetCharacteristicDefinition::create([
            'category_id' => $this->assetSubcategory->id,
            'name' => 'Procesador',
        ]);

        $payload = $this->validFixedAssetPayload();
        $payload['characteristics'] = [
            ['name' => 'Procesador', 'value' => 'AMD Ryzen 7', 'definition_id' => $definition->id],
        ];

        $this->postJson(self::BASE_URL, $payload);

        $this->assertDatabaseCount('asset_characteristic_definitions', 1);
    }

    public function test_actualizar_sin_caracteristicas_elimina_las_que_ya_tenia(): void
    {
        $payload = $this->validFixedAssetPayload();
        $payload['characteristics'] = [['name' => 'Procesador', 'value' => 'Intel Core i7']];
        $created = $this->postJson(self::BASE_URL, $payload)->json('data');

        $this->assertDatabaseHas('fixed_asset_characteristics', ['fixed_asset_id' => $created['id']]);

        $update = $this->validFixedAssetPayload();
        // Sin la llave 'characteristics': el controller igual debe sincronizar (vaciar)
        $this->putJson(self::BASE_URL."/{$created['id']}", $update);

        $this->assertDatabaseMissing('fixed_asset_characteristics', ['fixed_asset_id' => $created['id']]);
    }

    public function test_reutilizar_el_nombre_de_una_caracteristica_borrada_del_catalogo_no_revienta(): void
    {
        // Una característica que existió y se borró (soft delete) del catálogo...
        $definition = \App\Models\AssetCharacteristicDefinition::create([
            'category_id' => $this->assetSubcategory->id,
            'name' => 'Procesador',
        ]);
        $definition->delete();

        // ...no debe impedir que se vuelva a usar ese mismo nombre en un
        // activo nuevo (el auto-registro debe reciclarla, no truena por
        // duplicado en la restricción única category_id+name).
        $payload = $this->validFixedAssetPayload();
        $payload['characteristics'] = [
            ['name' => 'Procesador', 'value' => 'Intel Core i9'],
        ];

        $response = $this->postJson(self::BASE_URL, $payload);

        $response->assertCreated();
        $this->assertDatabaseHas('fixed_asset_characteristics', [
            'name' => 'Procesador',
            'value' => 'Intel Core i9',
        ]);
        $this->assertDatabaseCount('asset_characteristic_definitions', 1);
    }
}
