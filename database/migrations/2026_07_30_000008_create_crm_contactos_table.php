<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contactos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            // Polimórfico: entidad puede ser CrmProspecto, CrmCliente, CrmEmpresaExterna
            $table->string('entidad_type');
            $table->unsignedBigInteger('entidad_id');
            $table->string('nombre');
            $table->string('cargo')->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->boolean('es_principal')->default(false);
            $table->timestamps();

            $table->index('empresa_id');
            $table->index(['entidad_type', 'entidad_id']);
            $table->index(['entidad_type', 'entidad_id', 'es_principal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contactos');
    }
};
