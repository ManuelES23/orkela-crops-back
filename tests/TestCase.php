<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cinturón de seguridad (segunda capa, independiente de force="true" en
     * phpunit.xml): si el proceso de PHPUnit terminó apuntando a una base de
     * datos que no es la sqlite en memoria esperada, se aborta el proceso
     * COMPLETO antes de que createApplication()/RefreshDatabase alcancen a
     * abrir una sola conexión real.
     *
     * Se revisan las variables de entorno crudas (getenv/$_ENV), no
     * config('database...'), porque en este punto la aplicación de Laravel
     * todavía no existe (se crea dentro de parent::setUp()) — para cuando
     * config() esté disponible ya sería tarde.
     *
     * Motivo de este guard: en agosto de 2026, `php artisan test
     * --env=testing` terminó ejecutando RefreshDatabase contra la base MySQL
     * real de desarrollo (ver phpunit.xml para el detalle del mecanismo).
     * Este archivo es la última línea de defensa si algo similar vuelve a
     * pasar.
     */
    protected function setUp(): void
    {
        $this->abortIfNotUsingTestDatabase();

        parent::setUp();
    }

    private function abortIfNotUsingTestDatabase(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null);
        $database = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? null);

        if ($connection === 'sqlite' && $database === ':memory:') {
            return;
        }

        fwrite(STDERR, "\n\n🛑 TESTS ABORTADOS: la conexión de base de datos resuelta es "
            ."'{$connection}' (BD: '{$database}'), no 'sqlite' / ':memory:' como exige phpunit.xml.\n"
            ."Ejecutar los tests así podría borrar o modificar una base de datos real.\n"
            ."Causa típica: correr 'php artisan test --env=...' (el flag --env puede hacer que "
            ."variables de .env se filtren al proceso de PHPUnit). Corre 'php artisan test' sin --env.\n\n");

        exit(1);
    }
}
