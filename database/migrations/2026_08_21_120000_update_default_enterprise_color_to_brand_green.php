<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El default original de `color` (#3B82F6, azul) era un resabio de
 * SENTINEL. En Orkela Crops, cuando una empresa se da de alta sin elegir
 * color explícitamente, debe salir en el verde de marca (#59b45f, ver
 * VITE_BRAND_PRIMARY_COLOR / --color-brand en el front) en vez de azul.
 *
 * Solo cambia el default de la columna a nivel de esquema (para futuras
 * empresas creadas sin `color` en el request) — no toca filas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->string('color', 7)->default('#59b45f')->change();
        });
    }

    public function down(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->string('color', 7)->default('#3B82F6')->change();
        });
    }
};
