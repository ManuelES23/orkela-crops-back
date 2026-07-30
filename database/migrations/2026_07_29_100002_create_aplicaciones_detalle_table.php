<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aplicaciones_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos_aplicacion')->restrictOnDelete();
            $table->decimal('dosis', 10, 4);
            $table->string('unidad_medida', 50);
            $table->timestamps();

            $table->index('aplicacion_id');
            $table->index('producto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicaciones_detalle');
    }
};
