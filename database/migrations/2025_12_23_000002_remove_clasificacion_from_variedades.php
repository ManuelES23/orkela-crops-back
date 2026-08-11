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
        // El índice sobre 'clasificacion' debe quitarse antes de la columna:
        // MySQL lo hace implícito al hacer dropColumn, pero SQLite (usado en
        // tests) reconstruye la tabla preservando índices y falla con
        // "no such column: clasificacion" si el índice sigue ahí.
        Schema::table('variedades', function (Blueprint $table) {
            $table->dropIndex(['clasificacion']);
        });

        Schema::table('variedades', function (Blueprint $table) {
            $table->dropColumn('clasificacion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('variedades', function (Blueprint $table) {
            $table->enum('clasificacion', ['organico', 'convencional'])->nullable();
        });

        Schema::table('variedades', function (Blueprint $table) {
            $table->index('clasificacion');
        });
    }
};
