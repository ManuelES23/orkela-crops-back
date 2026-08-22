<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mismo desfase que en enterprises (ver
 * 2026_08_21_100000_make_description_nullable_on_enterprises_table.php):
 * ApplicationController::store()/update() validan 'description' como
 * 'nullable|string', pero la columna se creó como NOT NULL sin default.
 * Crear/actualizar una aplicación sin description tronaba con un 500 por
 * violación de integridad en vez de aceptar el campo opcional que el
 * controller ya declara.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('description')->nullable(false)->change();
        });
    }
};
