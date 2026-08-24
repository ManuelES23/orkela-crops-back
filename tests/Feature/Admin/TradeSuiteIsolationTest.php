<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use App\Models\SalesSsccLabel;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Services\EnterpriseProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BootsDynamicEnterpriseRoutes;
use Tests\TestCase;

class TradeSuiteIsolationTest extends TestCase
{
    use RefreshDatabase;
    use BootsDynamicEnterpriseRoutes;

    public function test_sscc_label_scope_follows_the_x_enterprise_slug_header(): void
    {
        $root = Enterprise::create(['name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'x', 'is_active' => true]);
        $mirror = Enterprise::create([
            'name' => 'Exportadora del Valle', 'slug' => 'exportadora-valle-demo', 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);
        app(EnterpriseProvisioningService::class)->provision($mirror);

        // routes/api.php resuelve los slugs espejo de Comercio con una query
        // eager (Enterprise::mirrorsOf('splendidbyporvenir')) que corrió
        // durante el boot inicial de la app, ANTES de insertar las filas de
        // arriba. Forzamos un re-boot para que las rutas de
        // 'exportadora-valle-demo' se registren (ver
        // BootsDynamicEnterpriseRoutes, mismo patrón que RhSuiteIsolationTest).
        $this->refreshApplication();

        $user = User::factory()->create();
        UserEnterpriseAccess::create(['user_id' => $user->id, 'enterprise_id' => $mirror->id, 'is_active' => true, 'granted_at' => now()]);
        Sanctum::actingAs($user);

        // Simula lo que hará el frontend ya corregido: manda el slug real de
        // la empresa activa en el header, no el literal 'splendidbyporvenir'.
        $this->getJson('/api/exportadora-valle-demo/sales/gestion-producto/etiquetas-sscc', [
            'X-Enterprise-Slug' => 'exportadora-valle-demo',
        ])->assertStatus(200);

        // SalesSsccLabel no tiene factory registrada (solo UserFactory
        // existe en database/factories); se crean las filas directamente
        // con los campos obligatorios reales de la migración
        // (enterprise_id, batch_code, sscc único, serial_reference,
        // company_prefix, extension_digit).
        foreach (range(1, 2) as $i) {
            SalesSsccLabel::create([
                'enterprise_id' => $mirror->id,
                'batch_code' => 'SSCC-MIRROR',
                'row_number' => $i,
                'sscc' => str_pad((string) (100000000000000000 + $i), 18, '0', STR_PAD_LEFT),
                'serial_reference' => $i,
                'company_prefix' => '123456',
                'extension_digit' => '1',
                'status' => 'generated',
            ]);
        }

        foreach (range(1, 3) as $i) {
            SalesSsccLabel::create([
                'enterprise_id' => $root->id,
                'batch_code' => 'SSCC-ROOT',
                'row_number' => $i,
                'sscc' => str_pad((string) (200000000000000000 + $i), 18, '0', STR_PAD_LEFT),
                'serial_reference' => $i,
                'company_prefix' => '654321',
                'extension_digit' => '2',
                'status' => 'generated',
            ]);
        }

        $response = $this->getJson('/api/exportadora-valle-demo/sales/gestion-producto/etiquetas-sscc', [
            'X-Enterprise-Slug' => 'exportadora-valle-demo',
        ])->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
    }
}
