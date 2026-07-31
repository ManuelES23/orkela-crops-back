<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_agenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->foreignId('vendedor_id')->constrained('crm_vendedores')->onDelete('cascade');
            // Polimórfico opcional: el evento puede estar relacionado a una entidad CRM
            $table->string('entidad_type')->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->enum('tipo', ['llamada', 'visita', 'reunion', 'tarea', 'correo']);
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->datetime('fecha_inicio');
            $table->datetime('fecha_fin');
            $table->boolean('completado')->default(false);
            $table->datetime('recordatorio_at')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
            $table->index('vendedor_id');
            $table->index('fecha_inicio');
            $table->index('completado');
            $table->index(['entidad_type', 'entidad_id']);
            $table->index(['empresa_id', 'vendedor_id']);
            // Para eficiencia en búsqueda de recordatorios pendientes
            $table->index(['completado', 'recordatorio_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_agenda');
    }
};
