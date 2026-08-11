<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VendedorController::store()/update() validan 'user_id' como nullable
 * (un vendedor puede existir sin cuenta de usuario ligada — así lo documenta
 * la clase: "Cada vendedor enlaza (opcionalmente) a un usuario del sistema"),
 * pero la columna se creó como NOT NULL. Cualquier intento de crear un
 * vendedor sin user_id truena con un 500 por violación de integridad en vez
 * de un 422 de validación. Esta migración alinea el esquema con la regla de
 * negocio ya documentada/validada en el controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE crm_vendedores MODIFY user_id BIGINT UNSIGNED NULL');

            return;
        }

        // SQLite (tests): dropColumn requiere soltar antes los índices/FK que la referencian.
        Schema::table('crm_vendedores', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'user_id']);
            $table->dropIndex(['user_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('crm_vendedores', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });

        Schema::table('crm_vendedores', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('empresa_id')->constrained('users')->onDelete('cascade');
            $table->index('user_id');
            $table->unique(['empresa_id', 'user_id']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE crm_vendedores MODIFY user_id BIGINT UNSIGNED NOT NULL');
        }
        // No se revierte en SQLite: la BD de test se reconstruye desde cero en cada suite.
    }
};
