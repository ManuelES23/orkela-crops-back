<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Valores de características capturados en un activo fijo específico.
        // 'name' se guarda duplicado (no solo la FK) para que sobreviva aunque
        // la definición se renombre/elimine, y para soportar características
        // libres que no están en el catálogo de la categoría.
        Schema::create('fixed_asset_characteristics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->foreignId('definition_id')->nullable()
                ->constrained('asset_characteristic_definitions')->nullOnDelete();
            $table->string('name', 150);
            $table->string('value', 500)->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index(['fixed_asset_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_characteristics');
    }
};
