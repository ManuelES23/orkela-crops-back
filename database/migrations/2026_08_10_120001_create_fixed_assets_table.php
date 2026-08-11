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
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();

            // Identificación
            $table->string('code', 50)->unique()->comment('Código único autogenerado, ej. AF-000001');
            $table->string('image')->nullable();
            $table->string('name');
            $table->string('slug')->unique()->nullable();
            $table->string('serial_number', 150)->nullable()->index();
            $table->string('model', 150)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();

            // Clasificación (Tipo de Activo / Subtipo de Activo)
            $table->foreignId('category_id')->constrained('asset_categories');
            $table->foreignId('subcategory_id')->nullable()->constrained('asset_categories')->nullOnDelete();

            // Ubicación física
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('entity_id')->constrained('entities');
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();

            // Datos generales
            $table->string('status', 30)->default('en_uso')->comment('en_uso, en_mantenimiento, disponible, resguardo, baja');
            $table->unsignedSmallInteger('useful_life_years')->nullable()->comment('Vida útil estimada en años');
            $table->foreignId('performance_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete()->comment('Unidad de rendimiento (horas, km, ciclos, etc.)');
            $table->text('description')->nullable();
            $table->text('observations')->nullable();

            // Datos de compra
            $table->date('purchase_date')->nullable();
            $table->string('invoice_number', 100)->nullable();
            $table->decimal('purchase_value', 15, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'entity_id']);
            $table->index(['category_id', 'subcategory_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
