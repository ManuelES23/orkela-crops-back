<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuiteRouteParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Igual que TestCase::createApplication(), salvo que reconecta la
     * conexión sqlite ":memory:" al PDO ya migrado (el que RefreshDatabase
     * cachea estáticamente para todo el proceso, ver RefreshDatabaseState)
     * ANTES de que arranquen los ServiceProviders.
     *
     * Es necesario porque RouteServiceProvider::register() encola la carga
     * de routes/api.php en $app->booted(), y ese archivo ahora ejecuta
     * Enterprise::mirrorsOf()->pluck() de forma eager al registrar las rutas
     * de RH/Comercio. refreshApplication() (llamado desde el test) crea una
     * Application nueva con una conexión ":memory:" nueva y sin migrar; si la
     * reconexión al PDO cacheado ocurriera después del boot (por ejemplo
     * llamando a restoreInMemoryDatabase() tras refreshApplication()),
     * llegaría demasiado tarde: la query eager ya habría fallado con
     * "no such table: enterprises" durante el boot.
     */
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));

        if (isset(RefreshDatabaseState::$inMemoryConnections['sqlite'])) {
            $app->booting(function () use ($app) {
                $app->make('db')->connection('sqlite')->setPdo(
                    RefreshDatabaseState::$inMemoryConnections['sqlite']
                );
            });
        }

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

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

        // Forzar recarga de rutas con los datos ya insertados (ver
        // createApplication() arriba para la reconexión al PDO ":memory:").
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
        // Nota: 'agrícola' (splendidfarms) queda fuera a propósito. Ese
        // bloque de routes/api.php sigue usando un array literal hardcodeado
        // (['splendidfarms', 'finca-modelo-demo']), no Enterprise::mirrorsOf()
        // — está fuera del alcance de esta tarea (Task 4), que solo
        // generaliza RH (grupoesplendido) y Comercio (splendidbyporvenir).
        // Un espejo nuevo del agrícola (con slug distinto al hardcodeado) NO
        // recibiría rutas automáticamente, así que este caso no aplica aquí.
        return [
            'rh' => ['grupoesplendido', 'agroverde-demo-test'],
            'comercio' => ['splendidbyporvenir', 'exportadora-valle-demo-test'],
        ];
    }
}
