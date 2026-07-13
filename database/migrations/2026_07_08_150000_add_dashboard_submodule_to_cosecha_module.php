<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $applicationId = DB::table('applications')
            ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
            ->where('applications.slug', 'operacion-agricola')
            ->where('enterprises.slug', 'splendidfarms')
            ->value('applications.id');

        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'cosecha')
            ->value('id');

        if (! $moduleId) {
            return;
        }

        $submoduleId = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'dashboard')
            ->value('id');

        if (! $submoduleId) {
            $nextSubmoduleOrder = ((int) (DB::table('submodules')
                ->where('module_id', $moduleId)
                ->max('order') ?? 0)) + 1;

            $submoduleId = DB::table('submodules')->insertGetId([
                'module_id' => $moduleId,
                'slug' => 'dashboard',
                'name' => 'Dashboard',
                'description' => 'Dashboard de metricas de cosecha y rendimientos',
                'icon' => 'BarChart3',
                'path' => null,
                'order' => $nextSubmoduleOrder,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionSlugs = ['view', 'create', 'edit', 'delete'];
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
                    'description' => 'Permiso base del submodulo dashboard de cosecha',
                    'order' => $nextPermissionOrder,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $permissionTypeIds[] = $permissionTypeId;
        }

        $userIds = DB::table('user_submodule_access')
            ->join('submodules', 'user_submodule_access.submodule_id', '=', 'submodules.id')
            ->where('submodules.module_id', $moduleId)
            ->pluck('user_submodule_access.user_id')
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            $userIds = DB::table('users')
                ->whereIn('email', ['admin@sentinel.com', 'demo@sentinel.com'])
                ->pluck('id');
        }

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

    public function down(): void
    {
        $applicationId = DB::table('applications')
            ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
            ->where('applications.slug', 'operacion-agricola')
            ->where('enterprises.slug', 'splendidfarms')
            ->value('applications.id');

        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'cosecha')
            ->value('id');

        if (! $moduleId) {
            return;
        }

        $submoduleId = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'dashboard')
            ->value('id');

        if (! $submoduleId) {
            return;
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
};
