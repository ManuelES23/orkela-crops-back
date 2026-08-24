<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\BootsDynamicEnterpriseRoutes;
use Tests\TestCase;

class SuiteRouteParityTest extends TestCase
{
    use RefreshDatabase;
    use BootsDynamicEnterpriseRoutes;

    /**
     * @dataProvider suiteRoots
     */
    public function test_mirror_gets_same_routes_as_its_root(string $rootSlug, string $mirrorSlug): void
    {
        $root = Enterprise::create(['name' => $rootSlug, 'slug' => $rootSlug, 'description' => 'x', 'is_active' => true]);
        Enterprise::create([
            'name' => $mirrorSlug, 'slug' => $mirrorSlug, 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);

        // Forzar recarga de rutas con los datos ya insertados (ver el trait
        // BootsDynamicEnterpriseRoutes para la reconexión al PDO ":memory:").
        $this->refreshApplication();

        // Las rutas de la API llevan el prefijo "api/" (ver apiPrefix en
        // bootstrap/app.php), por eso se compara contra "api/{slug}/".
        $rootPaths = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), "api/{$rootSlug}/"))
            ->map(fn ($r) => Str::after($r->uri(), "api/{$rootSlug}/"))
            ->sort()->values();

        $mirrorPaths = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), "api/{$mirrorSlug}/"))
            ->map(fn ($r) => Str::after($r->uri(), "api/{$mirrorSlug}/"))
            ->sort()->values();

        $this->assertEquals($rootPaths->all(), $mirrorPaths->all());
        $this->assertGreaterThan(0, $mirrorPaths->count());
    }

    public static function suiteRoots(): array
    {
        // Las 3 suites (agrícola, RH, Comercio) usan Enterprise::mirrorsOf()
        // en routes/api.php, así que un espejo nuevo (con un slug arbitrario,
        // no presente en ningún array hardcodeado previo) recibe el juego
        // completo de rutas de su suite automáticamente en las 3.
        return [
            'agricola' => ['splendidfarms', 'finca-modelo-demo-test'],
            'rh' => ['grupoesplendido', 'agroverde-demo-test'],
            'comercio' => ['splendidbyporvenir', 'exportadora-valle-demo-test'],
        ];
    }
}
