<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_oportunidad_productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oportunidad_id')->constrained('crm_oportunidades')->onDelete('cascade');
            $table->foreignId('producto_id')->constrained('crm_productos')->onDelete('cascade');
            $table->decimal('cantidad', 12, 4)->default(1);
            $table->decimal('precio_unitario', 12, 2)->default(0);

            $table->index('oportunidad_id');
            $table->index('producto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_oportunidad_productos');
    }
};
