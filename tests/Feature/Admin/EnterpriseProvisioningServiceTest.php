<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Services\EnterpriseProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnterpriseProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_provision_builds_agricultural_tree_for_a_mirror(): void
    {
        $root = Enterprise::create([
            'name' => 'Splendid Farms', 'slug' => 'splendidfarms',
            'description' => 'Raíz', 'is_active' => true,
        ]);
        $mirror = Enterprise::create([
            'name' => 'Finca Modelo', 'slug' => 'finca-modelo-demo',
            'description' => 'Espejo', 'is_active' => true,
            'mirror_source_id' => $root->id,
        ]);

        app(EnterpriseProvisioningService::class)->provision($mirror);

        $this->assertTrue(
            Application::where('enterprise_id', $mirror->id)->where('slug', 'operacion-agricola')->exists()
        );

        // 'agricola' es el slug de un módulo en DOS aplicaciones distintas
        // (Administración y Operación Agrícola) — se ancla explícitamente a
        // la aplicación 'administration', que es la que tiene el submódulo
        // 'temporadas' (el de Operación Agrícola no lo tiene, ver
        // buildAgriculturalSuite() líneas 148-171 vs 403-426).
        $administration = Application::where('enterprise_id', $mirror->id)
            ->where('slug', 'administration')->firstOrFail();
        $agricola = Module::where('application_id', $administration->id)
            ->where('slug', 'agricola')->first();
        $this->assertNotNull($agricola);
        $this->assertTrue(
            Submodule::where('module_id', $agricola->id)->where('slug', 'temporadas')->exists()
        );
    }

    public function test_provision_is_idempotent(): void
    {
        $root = Enterprise::create([
            'name' => 'Splendid Farms', 'slug' => 'splendidfarms',
            'description' => 'Raíz', 'is_active' => true,
        ]);
        $mirror = Enterprise::create([
            'name' => 'Finca Modelo', 'slug' => 'finca-modelo-demo',
            'description' => 'Espejo', 'is_active' => true,
            'mirror_source_id' => $root->id,
        ]);

        $service = app(EnterpriseProvisioningService::class);
        $service->provision($mirror);
        $countBefore = Application::where('enterprise_id', $mirror->id)->count();

        $service->provision($mirror);
        $countAfter = Application::where('enterprise_id', $mirror->id)->count();

        $this->assertSame($countBefore, $countAfter);
    }

    public function test_provision_throws_when_no_mirror_source(): void
    {
        $independent = Enterprise::create([
            'name' => 'Independiente', 'slug' => 'independiente',
            'description' => 'Sin suite', 'is_active' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(EnterpriseProvisioningService::class)->provision($independent);
    }
}
