<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Services\EnterpriseProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BootsDynamicEnterpriseRoutes;
use Tests\TestCase;

class RhSuiteIsolationTest extends TestCase
{
    use RefreshDatabase;
    use BootsDynamicEnterpriseRoutes;

    public function test_department_created_for_mirror_is_invisible_from_root(): void
    {
        $root = Enterprise::create(['name' => 'Grupo Espléndido', 'slug' => 'grupoesplendido', 'description' => 'x', 'is_active' => true]);
        $mirror = Enterprise::create([
            'name' => 'Agroverde', 'slug' => 'agroverde-demo', 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);
        app(EnterpriseProvisioningService::class)->provision($mirror);

        // routes/api.php resuelve los slugs espejo de RH con una query eager
        // (Enterprise::mirrorsOf()) que corrió durante el boot inicial de la
        // app, ANTES de insertar las filas de arriba. Forzamos un re-boot
        // para que las rutas de 'agroverde-demo' se registren (ver
        // BootsDynamicEnterpriseRoutes, mismo patrón que SuiteRouteParityTest).
        $this->refreshApplication();

        $user = User::factory()->create();
        UserEnterpriseAccess::create(['user_id' => $user->id, 'enterprise_id' => $mirror->id, 'is_active' => true, 'granted_at' => now()]);
        Sanctum::actingAs($user);

        $this->postJson("/api/agroverde-demo/rh/departamentos", [
            'enterprise_id' => $mirror->id,
            'name' => 'Departamento de prueba espejo',
        ])->assertStatus(201);

        $this->assertSame(1, Department::where('enterprise_id', $mirror->id)->count());
        $this->assertSame(0, Department::where('enterprise_id', $root->id)->count());
    }
}
