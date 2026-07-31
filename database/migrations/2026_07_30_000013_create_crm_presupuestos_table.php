<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_presupuestos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->foreignId('vendedor_id')->constrained('crm_vendedores')->onDelete('cascade');
            $table->tinyInteger('mes')->comment('1–12');
            $table->year('anio');
            $table->decimal('meta_monto', 12, 2)->default(0);
            $table->unsignedInteger('meta_clientes')->default(0);
            $table->unsignedInteger('meta_actividades')->default(0);
            $table->timestamps();

            $table->index('empresa_id');
            $table->index('vendedor_id');
            // Solo un presupuesto por vendedor/mes/año dentro de la empresa
            $table->unique(['empresa_id', 'vendedor_id', 'mes', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_presupuestos');
    }
};
