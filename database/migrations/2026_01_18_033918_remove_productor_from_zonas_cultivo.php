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
        // El índice compuesto sobre productor_id debe quitarse antes de la
        // FK y la columna: MySQL lo hace implícito al hacer dropColumn, pero
        // SQLite (usado en tests) reconstruye la tabla preservando índices y
        // falla con "no such column: productor_id" si el índice sigue ahí.
        Schema::table('zonas_cultivo', function (Blueprint $table) {
            $table->dropIndex(['productor_id', 'is_active']);
        });

        Schema::table('zonas_cultivo', function (Blueprint $table) {
            // Eliminar foreign key si existe
            $table->dropForeign(['productor_id']);
            // Eliminar columnas
            $table->dropColumn(['productor_id', 'superficie_total']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zonas_cultivo', function (Blueprint $table) {
            $table->foreignId('productor_id')->nullable()->after('id')->constrained('productores')->nullOnDelete();
            $table->decimal('superficie_total', 10, 2)->nullable()->after('ubicacion');
        });

        Schema::table('zonas_cultivo', function (Blueprint $table) {
            $table->index(['productor_id', 'is_active']);
        });
    }
};
