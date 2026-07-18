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
            return;
        }

        $submoduleId = DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'etiquetas-sscc')
            ->value('id');

        if (! $submoduleId) {
            $nextOrder = ((int) (DB::table('submodules')
                ->where('module_id', $moduleId)
                ->max('order') ?? 0)) + 1;

            $submoduleId = DB::table('submodules')->insertGetId([
                'module_id' => $moduleId,
                'slug' => 'etiquetas-sscc',
                'name' => 'Etiquetas SSCC',
                'description' => 'Generacion de etiquetas SSCC 4x2 desde archivo Excel',
                'icon' => 'Tags',
                'path' => null,
                'order' => $nextOrder,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissions = [
            ['slug' => 'view', 'name' => 'Ver', 'description' => 'Permite ver el submodulo'],
            ['slug' => 'create', 'name' => 'Crear', 'description' => 'Permite crear etiquetas SSCC'],
            ['slug' => 'import', 'name' => 'Importar', 'description' => 'Permite importar Excel para etiquetas SSCC'],
            ['slug' => 'print', 'name' => 'Imprimir', 'description' => 'Permite imprimir etiquetas SSCC'],
            ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Permite eliminar etiquetas SSCC'],
        ];

        foreach ($permissions as $permission) {
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
            ->where('slug', 'etiquetas-sscc')
            ->value('id');

        if (! $submoduleId) {
            return;
        }

        DB::table('submodule_permission_types')
            ->where('submodule_id', $submoduleId)
            ->whereIn('slug', ['view', 'create', 'import', 'print', 'delete'])
            ->delete();

        DB::table('submodules')->where('id', $submoduleId)->delete();
    }
};
