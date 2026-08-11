<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Database\Seeder;

/**
 * Seeder para el módulo "Activos Fijos" dentro de la app Inventario.
 *
 * Estructura final (módulo propio, no submódulo de Catálogos):
 *   Inventario
 *     └── Activos Fijos (módulo)
 *           ├── Activos Fijos      (slug: activos)       -> registro de activos
 *           └── Tipos de Activos Fijos (slug: tipos-activo) -> catálogo tipo/subtipo
 *
 * Es seguro volver a correr este seeder: si detecta el submódulo antiguo
 * 'activos-fijos' colgando del módulo 'catalogos' (estructura previa), lo
 * migra al nuevo módulo en vez de duplicarlo.
 *
 * Ejecutar: php artisan db:seed --class=FixedAssetsModuleSeeder
 */
class FixedAssetsModuleSeeder extends Seeder
{
    private const STANDARD_PERMISSIONS = [
        ['slug' => 'view', 'name' => 'Ver', 'description' => 'Ver y listar registros', 'order' => 1],
        ['slug' => 'create', 'name' => 'Crear', 'description' => 'Crear nuevos registros', 'order' => 2],
        ['slug' => 'edit', 'name' => 'Editar', 'description' => 'Editar registros existentes', 'order' => 3],
        ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Eliminar registros', 'order' => 4],
    ];

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('🚚 Configurando módulo Activos Fijos...');

        $created = [];

        foreach (['splendidfarms', 'splendidbyporvenir', 'grupoesplendido'] as $enterpriseSlug) {
            $result = $this->ensureModuleStructure($enterpriseSlug);
            if ($result) {
                $created[] = $result;
            }
        }

        if (empty($created)) {
            $this->command->error('No se pudo configurar el módulo Activos Fijos en ninguna empresa.');
            return;
        }

        // Otorgar acceso y permisos CRUD a los usuarios base del sistema
        $users = User::whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])->get();

        foreach ($users as $user) {
            foreach ($created as $entry) {
                if ($entry['appIsNew']) {
                    UserApplicationAccess::firstOrCreate(
                        ['user_id' => $user->id, 'application_id' => $entry['app']->id],
                        ['is_active' => true, 'granted_at' => now()]
                    );
                }

                UserModuleAccess::firstOrCreate(
                    ['user_id' => $user->id, 'module_id' => $entry['module']->id],
                    ['is_active' => true, 'granted_at' => now()]
                );

                foreach ([$entry['activosSubmodule'], $entry['tiposSubmodule']] as $submodule) {
                    UserSubmoduleAccess::firstOrCreate(
                        ['user_id' => $user->id, 'submodule_id' => $submodule->id],
                        ['is_active' => true, 'granted_at' => now()]
                    );

                    $permTypes = SubmodulePermissionType::where('submodule_id', $submodule->id)->get();
                    foreach ($permTypes as $permType) {
                        UserSubmodulePermission::firstOrCreate([
                            'user_id' => $user->id,
                            'submodule_id' => $submodule->id,
                            'permission_type_id' => $permType->id,
                        ]);
                    }
                }
            }
            $this->command->info("  ✓ Permisos asignados a: {$user->email}");
        }

        $this->command->info('');
        $this->command->info('✅ Módulo Activos Fijos listo en '.count($created).' empresa(s)');
    }

    private function ensureModuleStructure(string $enterpriseSlug): ?array
    {
        $enterprise = Enterprise::where('slug', $enterpriseSlug)->first();
        if (!$enterprise) {
            $this->command->warn("  ⚠ {$enterpriseSlug}: empresa no encontrada, se omite.");
            return null;
        }

        $appIsNew = !Application::where('slug', 'inventario')
            ->where('enterprise_id', $enterprise->id)->exists();

        $app = Application::firstOrCreate(
            ['slug' => 'inventario', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Inventario',
                'description' => 'Catálogo de activos fijos e inventario general',
                'icon' => 'Package',
                'path' => "/{$enterpriseSlug}/inventario",
                'is_active' => true,
            ]
        );

        // Módulo propio "Activos Fijos"
        $order = (int) (Module::where('application_id', $app->id)->max('order') ?? 0) + 1;
        $module = Module::firstOrCreate(
            ['slug' => 'activos-fijos', 'application_id' => $app->id],
            ['name' => 'Activos Fijos', 'icon' => 'Truck', 'order' => $order, 'is_active' => true]
        );

        // Migrar el submódulo antiguo (estructura previa: dentro de Catálogos)
        $oldCatalogos = Module::where('slug', 'catalogos')
            ->where('application_id', $app->id)
            ->first();

        $activosSubmodule = null;
        if ($oldCatalogos) {
            $activosSubmodule = Submodule::where('module_id', $oldCatalogos->id)
                ->where('slug', 'activos-fijos')
                ->first();
        }

        if ($activosSubmodule) {
            $activosSubmodule->update([
                'module_id' => $module->id,
                'slug' => 'activos',
                'name' => 'Activos Fijos',
                'icon' => 'Truck',
                'order' => 1,
            ]);
            $this->command->info("  ↪ {$enterpriseSlug}: submódulo Activos Fijos migrado a su propio módulo");
        } else {
            $activosSubmodule = Submodule::firstOrCreate(
                ['slug' => 'activos', 'module_id' => $module->id],
                ['name' => 'Activos Fijos', 'icon' => 'Truck', 'order' => 1, 'is_active' => true]
            );
        }
        $this->ensurePermissionTypes($activosSubmodule);

        // Submódulo "Tipos de Activos Fijos"
        $tiposSubmodule = Submodule::firstOrCreate(
            ['slug' => 'tipos-activo', 'module_id' => $module->id],
            ['name' => 'Tipos de Activos Fijos', 'icon' => 'Layers', 'order' => 2, 'is_active' => true]
        );
        $this->ensurePermissionTypes($tiposSubmodule);

        // Limpiar el módulo Catálogos si quedó vacío (caso Grupo Espléndido,
        // donde se creó solo para alojar temporalmente este submódulo)
        if ($oldCatalogos && $oldCatalogos->submodules()->count() === 0) {
            $oldCatalogos->delete();
            $this->command->info("  🧹 {$enterpriseSlug}: módulo Catálogos vacío eliminado");
        }

        $this->command->info("  ✓ {$enterpriseSlug}/inventario/activos-fijos/{activos,tipos-activo}");

        if ($appIsNew) {
            $this->command->warn('    → Falta configurar Sucursales/Entidades/Áreas para esta empresa (Administración > Organización).');
        }

        return [
            'app' => $app,
            'appIsNew' => $appIsNew,
            'module' => $module,
            'activosSubmodule' => $activosSubmodule,
            'tiposSubmodule' => $tiposSubmodule,
        ];
    }

    private function ensurePermissionTypes(Submodule $submodule): void
    {
        foreach (self::STANDARD_PERMISSIONS as $perm) {
            SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $submodule->id, 'slug' => $perm['slug']],
                ['name' => $perm['name'], 'description' => $perm['description'], 'order' => $perm['order']]
            );
        }
    }
}
