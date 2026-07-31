<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_oportunidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->foreignId('prospecto_id')->nullable()->constrained('crm_prospectos')->onDelete('set null');
            $table->foreignId('cliente_id')->nullable()->constrained('crm_clientes')->onDelete('set null');
            $table->foreignId('vendedor_id')->constrained('crm_vendedores')->onDelete('cascade');
            $table->string('nombre');
            $table->decimal('monto_esperado', 12, 2)->default(0);
            // 0–100 representando el porcentaje de probabilidad de cierre
            $table->tinyInteger('probabilidad')->default(50);
            $table->enum('etapa', [
                'prospecto',
                'calificado',
                'propuesta',
                'negociacion',
                'cerrado_ganado',
                'cerrado_perdido',
            ])->default('prospecto');
            $table->date('fecha_cierre_esperada')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('empresa_id');
            $table->index('vendedor_id');
            $table->index('etapa');
            $table->index('fecha_cierre_esperada');
            $table->index(['empresa_id', 'vendedor_id']);
            $table->index(['empresa_id', 'etapa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_oportunidades');
    }
};
