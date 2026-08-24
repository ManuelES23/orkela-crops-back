<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Reconecta la conexión sqlite ":memory:" al PDO ya migrado (el que
 * RefreshDatabase cachea estáticamente para todo el proceso, ver
 * RefreshDatabaseState::$inMemoryConnections) ANTES de que arranquen los
 * ServiceProviders, en lugar de después.
 *
 * POR QUÉ ES NECESARIO: `routes/api.php` ahora resuelve dinámicamente los
 * slugs de las empresas espejo de RH (grupoesplendido) y Comercio
 * (splendidbyporvenir) con `Enterprise::mirrorsOf()->pluck('slug')`, una
 * query "eager" que se ejecuta en cada boot de la app —
 * `RouteServiceProvider::register()` encola la carga de routes/api.php en
 * `$app->booted()`, y ese callback corre en cada boot, no solo en el
 * primero. `refreshApplication()` (llamado desde un test, típicamente tras
 * insertar filas nuevas) crea una `Application` completamente nueva con una
 * conexión ":memory:" nueva y SIN migrar; si la reconexión al PDO cacheado
 * ocurriera después del boot (por ejemplo llamando a
 * `restoreInMemoryDatabase()` tras `refreshApplication()`, que es lo que
 * hace `RefreshDatabase` por defecto en su `setUp()`), llegaría demasiado
 * tarde: la query eager de routes/api.php ya habría fallado con
 * "no such table: enterprises" (o directamente no vería las filas recién
 * insertadas) durante el boot.
 *
 * CUÁNDO USARLO: en cualquier test que cree una fila `Enterprise` nueva y
 * necesite que routes/api.php la "vea" — normalmente porque el test llama a
 * `$this->refreshApplication()` a mitad de la prueba para forzar un
 * re-registro de rutas con los datos ya insertados (p. ej. para verificar
 * que una empresa espejo recién creada recibe las rutas de RH/Comercio).
 * Sin este trait, ese tipo de test obtiene 0 rutas registradas (404 en
 * todo) sin ningún error explícito, porque `Schema::hasTable()` /
 * `Schema::hasColumn()` devuelven `false` en el momento en que
 * routes/api.php se carga por primera vez respecto a cuándo corrió la
 * migración normal de `RefreshDatabase`.
 *
 * SEGURIDAD: este trait NUNCA abre una conexión nueva ni apunta a una base
 * de datos distinta — únicamente adjunta el PDO sqlite ":memory:" que
 * `RefreshDatabase` ya dejó cacheado en el proceso actual
 * (`RefreshDatabaseState::$inMemoryConnections['sqlite']`). Si ese PDO
 * cacheado no existe todavía (por ejemplo, en el primer boot de la
 * aplicación, antes de que `RefreshDatabase::setUp()` haya migrado nada),
 * el trait no hace nada y deja el boot seguir su curso normal. No debilita
 * de ninguna forma el guard `TestCase::abortIfNotUsingTestDatabase()`.
 */
trait BootsDynamicEnterpriseRoutes
{
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
}
