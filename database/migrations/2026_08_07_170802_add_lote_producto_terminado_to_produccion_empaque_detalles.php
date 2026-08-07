<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('produccion_empaque_detalles', function (Blueprint $table) {
            $table->string('lote_producto_terminado', 100)->nullable()->after('calibre');
        });
    }

    public function down(): void
    {
        Schema::table('produccion_empaque_detalles', function (Blueprint $table) {
            $table->dropColumn('lote_producto_terminado');
        });
    }
};
