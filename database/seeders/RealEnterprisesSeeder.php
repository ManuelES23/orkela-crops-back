<?php

namespace Database\Seeders;

use App\Models\Enterprise;
use App\Models\User;
use Database\Seeders\Concerns\BuildsEnterpriseStructure;
use Illuminate\Database\Seeder;

/**
 * Empresas reales de trabajo — Grupo Espléndido, Splendid Farms, Splendid by
 * Porvenir. NO se corre automáticamente desde MasterStructureSeeder ni
 * desde `migrate:fresh --seed`: son datos de trabajo real, no deben
 * aparecer nunca en el usuario demo que se usa para presentaciones a
 * clientes (ver DemoStructureSeeder). Solo admin@orkelacrops.com recibe
 * acceso a estas empresas.
 *
 * Ejecutar manualmente cuando quieras tu propio entorno de trabajo:
 *   php artisan db:seed --class=RealEnterprisesSeeder
 */
class RealEnterprisesSeeder extends Seeder
{
    use BuildsEnterpriseStructure;

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('╔════════════════════════════════════════════════════════════╗');
        $this->command->info('║       Empresas reales (Grupo Espléndido / Splendid)         ║');
        $this->command->info('╚════════════════════════════════════════════════════════════╝');

        $admin = User::where('email', 'admin@orkelacrops.com')->first();

        if (! $admin) {
            $this->command->error('  admin@orkelacrops.com no existe todavía — corre MasterStructureSeeder primero.');

            return;
        }

        $enterprises = $this->createEnterprises();

        $this->buildCorporateRhSuite($enterprises['grupoesplendido']);
        $this->buildAgriculturalSuite($enterprises['splendidfarms']);
        $this->buildTradeSuite($enterprises['splendidbyporvenir']);

        // Catálogos base de personal SF (grupos y puestos)
        $this->call(SfPersonalCatalogSeeder::class);

        $this->command->info('');
        $this->command->info('🔐 Asignando permisos a admin@orkelacrops.com...');
        $this->grantFullAccess($admin, collect($enterprises));

        $this->command->info('');
        $this->command->info('╔════════════════════════════════════════════════════════════╗');
        $this->command->info('║          ¡Empresas reales listas!                           ║');
        $this->command->info('╚════════════════════════════════════════════════════════════╝');
    }

    /**
     * @return array<string, Enterprise>
     */
    private function createEnterprises(): array
    {
        $this->command->info('');
        $this->command->info('🏢 Creando empresas...');

        $grupoesplendido = Enterprise::firstOrCreate(
            ['slug' => 'grupoesplendido'],
            [
                'name' => 'Grupo Espléndido',
                'description' => 'Corporativo - Gestión centralizada de todas las empresas',
                'color' => '#6366F1',
                'is_active' => true,
            ]
        );
        $this->command->info('  ✓ Grupo Espléndido (Corporativo)');

        $splendidfarms = Enterprise::firstOrCreate(
            ['slug' => 'splendidfarms'],
            [
                'name' => 'Splendid Farms',
                'description' => 'Empresa agrícola especializada en cultivos',
                'color' => '#10B981',
                'is_active' => true,
            ]
        );
        $this->command->info('  ✓ Splendid Farms (Agrícola)');

        $splendidbyporvenir = Enterprise::firstOrCreate(
            ['slug' => 'splendidbyporvenir'],
            [
                'name' => 'Splendid by Porvenir',
                'description' => 'Empresa de exportación y ventas de fruta',
                'color' => '#3B82F6',
                'is_active' => true,
            ]
        );
        $this->command->info('  ✓ Splendid by Porvenir (Exportación)');

        return [
            'grupoesplendido' => $grupoesplendido,
            'splendidfarms' => $splendidfarms,
            'splendidbyporvenir' => $splendidbyporvenir,
        ];
    }
}
