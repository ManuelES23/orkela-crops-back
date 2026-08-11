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
        // Catálogo de características disponibles por Tipo/Subtipo de Activo.
        // Ej.: Subtipo "Laptops" -> Procesador, RAM, Almacenamiento.
        // Se van registrando sobre la marcha al capturar activos (igual que
        // Tipos/Marcas), y también se pueden gestionar desde el catálogo.
        Schema::create('asset_characteristic_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('asset_categories')->cascadeOnDelete();
            $table->string('name', 150);
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['category_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_characteristic_definitions');
    }
};
