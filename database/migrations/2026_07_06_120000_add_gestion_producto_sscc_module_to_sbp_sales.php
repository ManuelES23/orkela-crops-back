<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $applicationId = DB::table('applications')
            ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
            ->where('applications.slug', 'sales')
            ->where('enterprises.slug', 'splendidbyporvenir')
            ->value('applications.id');

        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'gestion-producto')
            ->value('id');

        if (! $moduleId) {
            $nextOrder = ((int) (DB::table('modules')
                ->where('application_id', $applicationId)
                ->max('order') ?? 0)) + 1;

            $moduleId = DB::table('modules')->insertGetId([
                'application_id' => $applicationId,
                'slug' => 'gestion-producto',
                'name' => 'Gestion de Producto',
                'description' => 'Gestion del producto durante el proceso de venta',
                'icon' => 'PackageSearch',
                'path' => null,
                'order' => $nextOrder,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $submoduleId = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'calculadora-sscc')
            ->value('id');

        if (! $submoduleId) {
            $nextSubmoduleOrder = ((int) (DB::table('submodules')
                ->where('module_id', $moduleId)
                ->max('order') ?? 0)) + 1;

            $submoduleId = DB::table('submodules')->insertGetId([
                'module_id' => $moduleId,
                'slug' => 'calculadora-sscc',
                'name' => 'Calculadora SSCC',
                'description' => 'Generacion y validacion de codigos SSCC para ventas',
                'icon' => 'QrCode',
                'path' => null,
                'order' => $nextSubmoduleOrder,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $basePermissions = [
            ['slug' => 'view', 'name' => 'Ver', 'description' => 'Permite ver el submodulo'],
            ['slug' => 'create', 'name' => 'Crear', 'description' => 'Permite crear registros en el submodulo'],
            ['slug' => 'edit', 'name' => 'Editar', 'description' => 'Permite editar registros en el submodulo'],
            ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Permite eliminar registros en el submodulo'],
        ];

        foreach ($basePermissions as $permission) {
            $exists = DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduleId)
                ->where('slug', $permission['slug'])
                ->exists();

            if ($exists) {
                continue;
            }

            $nextPermissionOrder = ((int) (DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduleId)
                ->max('order') ?? 0)) + 1;

            DB::table('submodule_permission_types')->insert([
                'submodule_id' => $submoduleId,
                'slug' => $permission['slug'],
                'name' => $permission['name'],
                'description' => $permission['description'],
                'order' => $nextPermissionOrder,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $applicationId = DB::table('applications')
            ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
            ->where('applications.slug', 'sales')
            ->where('enterprises.slug', 'splendidbyporvenir')
            ->value('applications.id');

        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'gestion-producto')
            ->value('id');

        if (! $moduleId) {
            return;
        }

        $submoduleId = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'calculadora-sscc')
            ->value('id');

        if ($submoduleId) {
            DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduleId)
                ->whereIn('slug', ['view', 'create', 'edit', 'delete'])
                ->delete();

            DB::table('submodules')
                ->where('id', $submoduleId)
                ->delete();
        }

        $remainingSubmodules = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->count();

        if ($remainingSubmodules === 0) {
            DB::table('modules')
                ->where('id', $moduleId)
                ->delete();
        }
    }
};
