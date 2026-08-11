<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sintaxis específica de MySQL; se omite en SQLite (usado en tests).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE venta_rezaga_empaque MODIFY comprador VARCHAR(200) NULL');
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE venta_rezaga_empaque SET comprador = '' WHERE comprador IS NULL");
        DB::statement('ALTER TABLE venta_rezaga_empaque MODIFY comprador VARCHAR(200) NOT NULL');
    }
};