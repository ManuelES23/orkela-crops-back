<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_actividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->enum('tipo', ['llamada', 'whatsapp', 'correo', 'visita', 'reunion', 'nota']);
            // Polimórfico: puede asociarse a CrmProspecto, CrmCliente, CrmOportunidad
            $table->string('entidad_type')->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->foreignId('vendedor_id')->nullable()->constrained('crm_vendedores')->onDelete('set null');
            $table->text('descripcion');
            $table->datetime('fecha_actividad');
            $table->unsignedInteger('duracion_minutos')->nullable();
            $table->text('resultado')->nullable();
            $table->json('archivos_adjuntos')->nullable();
            // Para trazabilidad de importaciones externas
            $table->string('fuente')->nullable()->comment('manual, dialpad, etc.');
            $table->string('dialpad_call_id')->nullable()->unique();
            $table->timestamps();

            $table->index('empresa_id');
            $table->index('vendedor_id');
            $table->index('tipo');
            $table->index('fecha_actividad');
            $table->index(['entidad_type', 'entidad_id']);
            $table->index(['empresa_id', 'vendedor_id']);
            $table->index(['empresa_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_actividades');
    }
};
