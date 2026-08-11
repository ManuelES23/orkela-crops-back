<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sintaxis específica de MySQL; SQLite (usado en tests) no aplica
        // límites de longitud a VARCHAR, no hace falta MODIFY.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sf_position_groups MODIFY code VARCHAR(30) NOT NULL');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sf_position_groups MODIFY code CHAR(1) NOT NULL');
    }
};
