<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BootsDynamicEnterpriseRoutes;
use Tests\TestCase;

/**
 * Regresión para el hallazgo Crítico del review final de rama (parte 2):
 * los 3 bloques de rutas espejo (agrícola/RH/Comercio) en routes/api.php
 * usaban Enterprise::mirrorsOf($rootSlug) sin ningún filtro por is_active,
 * así que desactivar una empresa espejo NO retiraba sus rutas — seguían
 * registradas y accesibles. Se agregó ->active() a la query de
 * mirrorsOf() en los 3 bloques (manteniendo el fallback que siempre
 * incluye la raíz vía ->prepend(), sin importar su propio estado).
 */
class EnterpriseMirrorRouteDeactivationTest extends TestCase
{
    use RefreshDatabase;
    use BootsDynamicEnterpriseRoutes;

    /**
     * @dataProvider suiteRoots
     */
    public function test_deactivated_mirror_does_not_register_routes(string $rootSlug, string $mirrorSlug): void
    {
        $root = Enterprise::create(['name' => $rootSlug, 'slug' => $rootSlug, 'description' => 'x', 'is_active' => true]);
        Enterprise::create([
            'name' => $mirrorSlug, 'slug' => $mirrorSlug, 'description' => 'x',
            'is_active' => false, 'mirror_source_id' => $root->id,
        ]);

        $this->refreshApplication();

        $mirrorRoutes = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), "api/{$mirrorSlug}/"));

        $this->assertCount(0, $mirrorRoutes, "La empresa espejo desactivada '{$mirrorSlug}' no debería tener rutas registradas.");

        // La raíz sigue registrada sin importar el estado de sus espejos.
        $rootRoutes = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), "api/{$rootSlug}/"));

        $this->assertGreaterThan(0, $rootRoutes->count());
    }

    public static function suiteRoots(): array
    {
        return [
            'agricola' => ['splendidfarms', 'finca-modelo-demo-inactive-test'],
            'rh' => ['grupoesplendido', 'agroverde-demo-inactive-test'],
            'comercio' => ['splendidbyporvenir', 'exportadora-valle-demo-inactive-test'],
        ];
    }
}
