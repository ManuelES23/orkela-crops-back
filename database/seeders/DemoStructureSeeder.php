<?php

namespace Database\Seeders;

use App\Models\Enterprise;
use App\Models\User;
use Database\Seeders\Concerns\BuildsEnterpriseStructure;
use Illuminate\Database\Seeder;

/**
 * Empresas ficticias para presentaciones a clientes potenciales — mismo
 * "esqueleto" de aplicaciones/módulos/submódulos que las empresas reales
 * (ver Concerns\BuildsEnterpriseStructure), pero con nombres y colores
 * inventados. El usuario demo@orkelacrops.com solo tiene acceso a estas
 * empresas, nunca a las reales; admin@orkelacrops.com también recibe
 * acceso para poder ensayar la demo antes de una presentación.
 */
class DemoStructureSeeder extends Seeder
{
    use BuildsEnterpriseStructure;

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('🎬 Creando empresas demo (para presentaciones)...');

        $enterprises = $this->createDemoEnterprises();

        // Vincular cada empresa demo a su raíz real vía mirror_source_id, para
        // que use el mecanismo nuevo (Enterprise::mirrorsOf) en vez de los
        // arrays fijos que tenían las rutas antes de esta tarea.
        $splendidFarms = Enterprise::where('slug', 'splendidfarms')->first();
        $grupoEsplendido = Enterprise::where('slug', 'grupoesplendido')->first();
        $splendidByPorvenir = Enterprise::where('slug', 'splendidbyporvenir')->first();

        if ($splendidFarms) {
            $enterprises['fincamodelo']->update(['mirror_source_id' => $splendidFarms->id]);
        }
        if ($grupoEsplendido) {
            $enterprises['agroverde']->update(['mirror_source_id' => $grupoEsplendido->id]);
        }
        if ($splendidByPorvenir) {
            $enterprises['exportadoravalle']->update(['mirror_source_id' => $splendidByPorvenir->id]);
        }

        $this->buildCorporateRhSuite($enterprises['agroverde']);
        $this->buildAgriculturalSuite($enterprises['fincamodelo']);
        $this->buildTradeSuite($enterprises['exportadoravalle']);

        $this->command->info('');
        $this->command->info('🔐 Asignando permisos de las empresas demo...');

        $demoUser = User::where('email', 'demo@orkelacrops.com')->first();
        $adminUser = User::where('email', 'admin@orkelacrops.com')->first();

        if ($demoUser) {
            $this->grantFullAccess($demoUser, collect($enterprises));
        }

        // El admin también ve las empresas demo (para poder ensayarlas antes
        // de una presentación), además de las que ya tenga por su cuenta.
        if ($adminUser) {
            $this->grantFullAccess($adminUser, collect($enterprises));
        }
    }

    /**
     * @return array<string, Enterprise>
     */
    private function createDemoEnterprises(): array
    {
        $agroverde = Enterprise::firstOrCreate(
            ['slug' => 'agroverde-demo'],
            [
                'name' => 'Agroverde Corporativo',
                'description' => 'Empresa de demostración · Corporativo y Recursos Humanos',
                'color' => '#7C3AED',
                'is_active' => true,
            ]
        );
        $this->command->info('  ✓ Agroverde Corporativo (demo · Corporativo)');

        $fincamodelo = Enterprise::firstOrCreate(
            ['slug' => 'finca-modelo-demo'],
            [
                'name' => 'Finca Modelo',
                'description' => 'Empresa de demostración · cultivos, cosecha y empaque',
                'color' => '#F59E0B',
                'is_active' => true,
            ]
        );
        $this->command->info('  ✓ Finca Modelo (demo · Agrícola)');

        $exportadoravalle = Enterprise::firstOrCreate(
            ['slug' => 'exportadora-valle-demo'],
            [
                'name' => 'Exportadora del Valle',
                'description' => 'Empresa de demostración · exportación y ventas',
                'color' => '#0EA5E9',
                'is_active' => true,
            ]
        );
        $this->command->info('  ✓ Exportadora del Valle (demo · Exportación)');

        return [
            'agroverde' => $agroverde,
            'fincamodelo' => $fincamodelo,
            'exportadoravalle' => $exportadoravalle,
        ];
    }
}
