<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $applicationId = DB::table('applications')
            ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
            ->where('applications.slug', 'administration')
            ->where('enterprises.slug', 'splendidfarms')
            ->value('applications.id');

        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'reportes')
            ->value('id');

        if (! $moduleId) {
            return;
        }

        DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'cajas-producidas')
            ->update([
                'name' => 'Producción',
                'description' => 'Reporte administrativo de producción por empaque',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $applicationId = DB::table('applications')
            ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
            ->where('applications.slug', 'administration')
            ->where('enterprises.slug', 'splendidfarms')
            ->value('applications.id');

        if (! $applicationId) {
            return;
        }

        $moduleId = DB::table('modules')
            ->where('application_id', $applicationId)
            ->where('slug', 'reportes')
            ->value('id');

        if (! $moduleId) {
            return;
        }

        DB::table('submodules')
            ->where('module_id', $moduleId)
            ->where('slug', 'cajas-producidas')
            ->update([
                'name' => 'Cajas producidas',
                'description' => 'Reporte administrativo de cajas producidas por empaque',
                'updated_at' => now(),
            ]);
    }
};
