<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionSlugs = ['view', 'create', 'edit', 'delete'];

        foreach (['splendidfarms', 'splendidbyporvenir'] as $enterpriseSlug) {
            $applicationId = DB::table('applications')
                ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
                ->where('applications.slug', 'inventario')
                ->where('enterprises.slug', $enterpriseSlug)
                ->value('applications.id');

            if (! $applicationId) {
                continue;
            }

            $moduleId = DB::table('modules')
                ->where('application_id', $applicationId)
                ->where('slug', 'reportes')
                ->value('id');

            if (! $moduleId) {
                continue;
            }

            $submoduleId = DB::table('submodules')
                ->where('module_id', $moduleId)
                ->where('slug', 'gasto-produccion')
                ->value('id');

            if (! $submoduleId) {
                $nextSubmoduleOrder = ((int) (DB::table('submodules')
                    ->where('module_id', $moduleId)
                    ->max('order') ?? 0)) + 1;

                $submoduleId = DB::table('submodules')->insertGetId([
                    'module_id' => $moduleId,
                    'slug' => 'gasto-produccion',
                    'name' => 'Gasto en Produccion',
                    'description' => 'Reporte de consumo de insumos por produccion de empaque',
                    'icon' => 'Factory',
                    'path' => null,
                    'order' => $nextSubmoduleOrder,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $permissionTypeIds = [];

            foreach ($permissionSlugs as $slug) {
                $permissionTypeId = DB::table('submodule_permission_types')
                    ->where('submodule_id', $submoduleId)
                    ->where('slug', $slug)
                    ->value('id');

                if (! $permissionTypeId) {
                    $nextPermissionOrder = ((int) (DB::table('submodule_permission_types')
                        ->where('submodule_id', $submoduleId)
                        ->max('order') ?? 0)) + 1;

                    $name = match ($slug) {
                        'view' => 'Ver',
                        'create' => 'Crear',
                        'edit' => 'Editar',
                        'delete' => 'Eliminar',
                    };

                    $permissionTypeId = DB::table('submodule_permission_types')->insertGetId([
                        'submodule_id' => $submoduleId,
                        'slug' => $slug,
                        'name' => $name,
                        'description' => 'Permiso base del submodulo de gasto en produccion',
                        'order' => $nextPermissionOrder,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $permissionTypeIds[] = $permissionTypeId;
            }

            $userIds = DB::table('users')
                ->whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])
                ->pluck('id');

            foreach ($userIds as $userId) {
                DB::table('user_module_access')->updateOrInsert(
                    [
                        'user_id' => $userId,
                        'module_id' => $moduleId,
                    ],
                    [
                        'is_active' => true,
                        'granted_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                DB::table('user_submodule_access')->updateOrInsert(
                    [
                        'user_id' => $userId,
                        'submodule_id' => $submoduleId,
                    ],
                    [
                        'is_active' => true,
                        'granted_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                foreach ($permissionTypeIds as $permissionTypeId) {
                    DB::table('user_submodule_permissions')->updateOrInsert(
                        [
                            'user_id' => $userId,
                            'submodule_id' => $submoduleId,
                            'permission_type_id' => $permissionTypeId,
                        ],
                        [
                            'is_granted' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['splendidfarms', 'splendidbyporvenir'] as $enterpriseSlug) {
            $applicationId = DB::table('applications')
                ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
                ->where('applications.slug', 'inventario')
                ->where('enterprises.slug', $enterpriseSlug)
                ->value('applications.id');

            if (! $applicationId) {
                continue;
            }

            $moduleId = DB::table('modules')
                ->where('application_id', $applicationId)
                ->where('slug', 'reportes')
                ->value('id');

            if (! $moduleId) {
                continue;
            }

            $submoduleId = DB::table('submodules')
                ->where('module_id', $moduleId)
                ->where('slug', 'gasto-produccion')
                ->value('id');

            if (! $submoduleId) {
                continue;
            }

            DB::table('user_submodule_permissions')
                ->where('submodule_id', $submoduleId)
                ->delete();

            DB::table('user_submodule_access')
                ->where('submodule_id', $submoduleId)
                ->delete();

            DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduleId)
                ->whereIn('slug', ['view', 'create', 'edit', 'delete'])
                ->delete();

            DB::table('submodules')
                ->where('id', $submoduleId)
                ->delete();
        }
    }
};
