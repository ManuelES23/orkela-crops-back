<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->foreignId('prospecto_id')->nullable()->constrained('crm_prospectos')->onDelete('set null');
            $table->string('nombre');
            $table->string('rfc', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->enum('estatus', ['activo', 'inactivo', 'suspendido'])->default('activo');
            $table->foreignId('vendedor_id')->nullable()->constrained('crm_vendedores')->onDelete('set null');
            $table->foreignId('region_id')->nullable()->constrained('crm_regiones')->onDelete('set null');
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('empresa_id');
            $table->index('vendedor_id');
            $table->index('estatus');
            $table->index(['empresa_id', 'vendedor_id']);
            $table->index(['empresa_id', 'estatus']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_clientes');
    }
};
