<?php

namespace Database\Seeders\Concerns;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;

/**
 * Construye la jerarquía Aplicación → Módulo → Submódulo para los 3
 * "perfiles" de empresa que maneja el sistema (corporativo/RH, agrícola
 * completo, comercio/exportación). El mismo esqueleto de features lo usan
 * tanto RealEnterprisesSeeder (las empresas reales de trabajo) como
 * DemoStructureSeeder (empresas ficticias para presentaciones a clientes)
 * — cambia la empresa a la que se le construye, no la estructura.
 *
 * Requiere que la clase que use este trait extienda Illuminate\Database\
 * Seeder (usa $this->command para los logs de progreso).
 */
trait BuildsEnterpriseStructure
{
    /**
     * Perfil "Corporativo": una sola aplicación de Recursos Humanos.
     */
    protected function buildCorporateRhSuite(Enterprise $enterprise): void
    {
        $this->command->info('');
        $this->command->info("📱 Creando aplicaciones para: {$enterprise->name}");
        app(\App\Services\EnterpriseProvisioningService::class)->provisionCorporateRh($enterprise);
        $this->command->info('    → Recursos Humanos: Catálogos, Empleados, Asistencia, Gestión');
    }

    /**
     * Perfil "Agrícola completo": Administración + Inventario + Contabilidad
     * + Operación Agrícola (Agrícola, Cosecha, Empaque).
     *
     * ⚠️ Cosecha (SalidaCampoCosechaController) y Empaque
     * (RecepcionEmpaqueController) transmiten sus eventos de tiempo real a
     * un canal con el nombre de empresa hardcodeado a 'splendidfarms' — una
     * empresa distinta que use estos submódulos no recibirá el push en
     * vivo (los datos se guardan/cargan bien por REST normal, solo el
     * WebSocket queda mudo). No es un bug de este seeder; ver tarea aparte.
     */
    protected function buildAgriculturalSuite(Enterprise $enterprise): void
    {
        $this->command->info('');
        $this->command->info("📱 Creando aplicaciones para: {$enterprise->name}");
        app(\App\Services\EnterpriseProvisioningService::class)->provisionAgricultural($enterprise);
        $this->command->info('    → Administración, Inventario, Contabilidad, Operación Agrícola creados/verificados');
    }

    /**
     * Perfil "Comercio/Exportación": Administración + Inventario + Ventas +
     * Exportaciones + Compras de Fruta.
     */
    protected function buildTradeSuite(Enterprise $enterprise): void
    {
        $this->command->info('');
        $this->command->info("📱 Creando aplicaciones para: {$enterprise->name}");
        app(\App\Services\EnterpriseProvisioningService::class)->provisionTrade($enterprise);
        $this->command->info('    → Administración, Inventario, Ventas, Exportaciones, Compras de Fruta creados/verificados');
    }

    protected function ensureSubmodulePermissionTypes(Module $module, string $submoduleSlug, array $types): void
    {
        $submodule = Submodule::where('module_id', $module->id)
            ->where('slug', $submoduleSlug)
            ->first();

        if (! $submodule) {
            return;
        }

        $order = (int) (SubmodulePermissionType::where('submodule_id', $submodule->id)->max('order') ?? 0);

        foreach ($types as $type) {
            $exists = SubmodulePermissionType::where('submodule_id', $submodule->id)
                ->where('slug', $type['slug'])
                ->exists();

            if ($exists) {
                continue;
            }

            $order++;

            SubmodulePermissionType::create([
                'submodule_id' => $submodule->id,
                'slug' => $type['slug'],
                'name' => $type['name'],
                'description' => $type['description'],
                'order' => $order,
                'is_active' => true,
            ]);
        }
    }

    /**
     * Otorga acceso completo (empresa → aplicación → módulo → submódulo +
     * todos los permisos CRUD) a un usuario sobre una lista de empresas.
     * Compartido por RealEnterprisesSeeder y DemoStructureSeeder para no
     * duplicar el recorrido de la jerarquía.
     *
     * @param  \App\Models\User  $user
     * @param  \Illuminate\Support\Collection<int, \App\Models\Enterprise>  $enterprises
     */
    protected function grantFullAccess($user, $enterprises): void
    {
        $permissionTypes = SubmodulePermissionType::all();

        foreach ($enterprises as $enterprise) {
            \App\Models\UserEnterpriseAccess::firstOrCreate(
                ['user_id' => $user->id, 'enterprise_id' => $enterprise->id],
                ['is_active' => true, 'granted_at' => now()]
            );

            $applications = Application::where('enterprise_id', $enterprise->id)->get();
            foreach ($applications as $application) {
                \App\Models\UserApplicationAccess::firstOrCreate(
                    ['user_id' => $user->id, 'application_id' => $application->id],
                    ['is_active' => true, 'granted_at' => now()]
                );

                $modules = Module::where('application_id', $application->id)->get();
                foreach ($modules as $module) {
                    \App\Models\UserModuleAccess::firstOrCreate(
                        ['user_id' => $user->id, 'module_id' => $module->id],
                        ['is_active' => true, 'granted_at' => now()]
                    );

                    $submodules = Submodule::where('module_id', $module->id)->get();
                    foreach ($submodules as $submodule) {
                        \App\Models\UserSubmoduleAccess::firstOrCreate(
                            ['user_id' => $user->id, 'submodule_id' => $submodule->id],
                            ['is_active' => true, 'granted_at' => now()]
                        );

                        foreach ($permissionTypes as $permType) {
                            \App\Models\UserSubmodulePermission::firstOrCreate([
                                'user_id' => $user->id,
                                'submodule_id' => $submodule->id,
                                'permission_type_id' => $permType->id,
                            ]);
                        }
                    }
                }
            }

            $this->command->info("    ✓ {$enterprise->name} → {$user->email}");
        }
    }
}
