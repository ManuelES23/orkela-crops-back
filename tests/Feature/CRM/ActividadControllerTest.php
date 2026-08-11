<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class ActividadControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const BASE_URL = '/api/crm/actividades';

    protected CrmCliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente de prueba']);
    }

    public function test_puede_registrar_una_actividad_para_un_cliente(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'entidad_tipo' => 'cliente',
            'entidad_id' => $this->cliente->id,
            'tipo' => 'llamada',
            'descripcion' => 'Llamada de seguimiento',
            'fecha_actividad' => now()->toDateTimeString(),
            'vendedor_id' => $this->vendedor->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.entidad_tipo', 'cliente')
            ->assertJsonPath('data.tipo', 'llamada')
            ->assertJsonPath('data.fuente', 'manual');
    }

    public function test_rechaza_un_tipo_de_actividad_invalido(): void
    {
        $response = $this->withHeaders($this->crmHeaders())->postJson(self::BASE_URL, [
            'entidad_tipo' => 'cliente',
            'entidad_id' => $this->cliente->id,
            'tipo' => 'telepatia',
            'descripcion' => 'x',
            'fecha_actividad' => now()->toDateTimeString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['tipo']);
    }

    public function test_puede_filtrar_actividades_por_entidad(): void
    {
        $otroCliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro cliente']);
        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'tipo' => 'nota',
            'entidad_type' => CrmCliente::class, 'entidad_id' => $this->cliente->id,
            'descripcion' => 'Nota del cliente 1', 'fecha_actividad' => now(),
        ]);
        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'tipo' => 'nota',
            'entidad_type' => CrmCliente::class, 'entidad_id' => $otroCliente->id,
            'descripcion' => 'Nota del cliente 2', 'fecha_actividad' => now(),
        ]);

        $response = $this->withHeaders($this->crmHeaders())
            ->getJson(self::BASE_URL."?entidad_tipo=cliente&entidad_id={$this->cliente->id}");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.descripcion', 'Nota del cliente 1');
    }

    public function test_no_puede_ver_una_actividad_de_otra_empresa(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $actividad = CrmActividad::create([
            'empresa_id' => $otraEmpresa->id, 'tipo' => 'nota',
            'entidad_type' => CrmCliente::class, 'entidad_id' => $this->cliente->id,
            'descripcion' => 'Ajena', 'fecha_actividad' => now(),
        ]);

        $response = $this->withHeaders($this->crmHeaders())->getJson(self::BASE_URL."/{$actividad->id}");

        $response->assertStatus(404);
    }
}
