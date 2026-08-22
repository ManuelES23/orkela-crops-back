<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EnterpriseController::store()/update() validan 'description' como
 * 'nullable|string|max:500', pero la columna se creó como NOT NULL sin
 * default. Crear una empresa sin description tronaba con un 500 por
 * violación de integridad (SQLSTATE[HY000] 1364) en vez de aceptar el
 * campo opcional que el controller ya declara. Esta migración alinea el
 * esquema con la validación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->text('description')->nullable(false)->change();
        });
    }
};
