<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SentinelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear usuario demo (solo si no existe)
        $user = User::firstOrCreate(
            ['email' => 'demo@sentinel.com'],
            [
                'name' => 'Usuario Demo',
                'password' => Hash::make('password123'),
            ]
        );

        // Crear empresas (solo si no existen)
        $splendidfarms = Enterprise::firstOrCreate(
            ['slug' => 'splendidfarms'],
            [
                'name' => 'Splendid Farms',
                'description' => 'Empresa agrícola especializada en cultivos',
                'color' => '#10B981',
                'is_active' => true,
            ]
        );

        $splendidbyporvenir = Enterprise::firstOrCreate(
            ['slug' => 'splendidbyporvenir'],
            [
                'name' => 'Splendid by Porvenir',
                'description' => 'Empresa de exportación y ventas de fruta',
                'color' => '#3B82F6',
                'is_active' => true,
            ]
        );

        // Crear aplicaciones para Splendid Farms
        $agricultural = Application::firstOrCreate(
            ['enterprise_id' => $splendidfarms->id, 'slug' => 'agricultural'],
            [
                'name' => 'Gestión Agrícola',
                'description' => 'Manejo de cultivos, siembras y cosechas',
                'icon' => '🌱',
                'path' => '/splendidfarms/agricultural',
                'is_active' => true,
            ]
        );

        $administration = Application::firstOrCreate(
            ['enterprise_id' => $splendidfarms->id, 'slug' => 'administration'],
            [
                'name' => 'Administración',
                'description' => 'Gestión administrativa general',
                'icon' => '📋',
                'path' => '/splendidfarms/administration',
                'is_active' => true,
            ]
        );

        $accounting = Application::firstOrCreate(
            ['enterprise_id' => $splendidfarms->id, 'slug' => 'accounting'],
            [
                'name' => 'Contabilidad',
                'description' => 'Gestión contable y financiera',
                'icon' => '💰',
                'path' => '/splendidfarms/accounting',
                'is_active' => true,
            ]
        );

        // Crear aplicaciones para Splendid by Porvenir
        $sales = Application::firstOrCreate(
            ['enterprise_id' => $splendidbyporvenir->id, 'slug' => 'sales'],
            [
                'name' => 'Ventas',
                'description' => 'Gestión de ventas y clientes',
                'icon' => '🛒',
                'path' => '/splendidbyporvenir/sales',
                'is_active' => true,
            ]
        );

        $exports = Application::firstOrCreate(
            ['enterprise_id' => $splendidbyporvenir->id, 'slug' => 'exports'],
            [
                'name' => 'Exportaciones',
                'description' => 'Manejo de exportaciones',
                'icon' => '🚢',
                'path' => '/splendidbyporvenir/exports',
                'is_active' => true,
            ]
        );

        $purchases = Application::firstOrCreate(
            ['enterprise_id' => $splendidbyporvenir->id, 'slug' => 'purchases'],
            [
                'name' => 'Compras',
                'description' => 'Gestión de compras de fruta',
                'icon' => '🍎',
                'path' => '/splendidbyporvenir/purchases',
                'is_active' => true,
            ]
        );

        // ===== SISTEMA DE PERMISOS JERÁRQUICOS =====
        
        // Importar los modelos necesarios
        $userEnterpriseAccess = \App\Models\UserEnterpriseAccess::class;
        $userApplicationAccess = \App\Models\UserApplicationAccess::class;

        // Asignar acceso a empresas (nuevo sistema jerárquico)
        $userEnterpriseAccess::updateOrCreate(
            ['user_id' => $user->id, 'enterprise_id' => $splendidfarms->id],
            ['is_active' => true, 'granted_at' => now()]
        );

        $userEnterpriseAccess::updateOrCreate(
            ['user_id' => $user->id, 'enterprise_id' => $splendidbyporvenir->id],
            ['is_active' => true, 'granted_at' => now()]
        );

        // Asignar acceso a todas las aplicaciones (nuevo sistema jerárquico)
        $applications = [$agricultural, $administration, $accounting, $sales, $exports, $purchases];
        foreach ($applications as $application) {
            $userApplicationAccess::updateOrCreate(
                ['user_id' => $user->id, 'application_id' => $application->id],
                ['is_active' => true, 'granted_at' => now()]
            );
        }

        echo "✅ Datos de SENTINEL 3.0 creados exitosamente!\n";
        echo "👤 Usuario: demo@sentinel.com\n";
        echo "🔑 Password: password123\n";
    }
}
