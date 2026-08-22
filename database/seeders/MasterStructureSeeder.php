<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder de arranque del sistema — usuarios base + empresas demo.
 *
 * Deliberadamente NO crea las empresas reales de trabajo (Grupo Espléndido,
 * Splendid Farms, Splendid by Porvenir): ese es el trabajo de
 * RealEnterprisesSeeder, que se corre aparte y a mano. La razón es que
 * demo@orkelacrops.com se usa en vivo frente a clientes potenciales, y no
 * debe poder ver ninguna empresa real que se esté trabajando — solo las
 * empresas ficticias de DemoStructureSeeder.
 *
 * Ejecutar: php artisan db:seed --class=MasterStructureSeeder
 * (DatabaseSeeder.php — el que corre `migrate:fresh --seed` por defecto —
 * es un seeder aparte, no relacionado; no llama a este).
 *
 * Para tu propio entorno de trabajo con datos reales, además corre:
 *   php artisan db:seed --class=RealEnterprisesSeeder
 */
class MasterStructureSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('╔════════════════════════════════════════════════════════════╗');
        $this->command->info('║       SENTINEL 3.0 - Master Structure Seeder               ║');
        $this->command->info('╚════════════════════════════════════════════════════════════╝');
        $this->command->info('');

        // 1. Crear usuarios
        $this->createUsers();

        // 2. Empresas demo (para presentaciones a clientes) — nunca las
        //    empresas reales, ver RealEnterprisesSeeder.
        $this->call(DemoStructureSeeder::class);

        $this->command->info('');
        $this->command->info('╔════════════════════════════════════════════════════════════╗');
        $this->command->info('║          ¡Estructura creada exitosamente!                  ║');
        $this->command->info('╚════════════════════════════════════════════════════════════╝');
        $this->command->info('');
        $this->command->info('Para cargar también las empresas reales de trabajo:');
        $this->command->info('  php artisan db:seed --class=RealEnterprisesSeeder');
    }

    /**
     * Crear usuarios del sistema
     */
    private function createUsers(): void
    {
        $this->command->info('📦 Creando usuarios...');

        User::firstOrCreate(
            ['email' => 'admin@orkelacrops.com'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('password123'),
                'role' => 'admin',
            ]
        );

        User::firstOrCreate(
            ['email' => 'demo@orkelacrops.com'],
            [
                'name' => 'Usuario Demo',
                'password' => Hash::make('password123'),
                'role' => 'user',
            ]
        );

        $this->command->info('  ✓ admin@orkelacrops.com (Administrador)');
        $this->command->info('  ✓ demo@orkelacrops.com (Usuario Demo)');
    }
}
