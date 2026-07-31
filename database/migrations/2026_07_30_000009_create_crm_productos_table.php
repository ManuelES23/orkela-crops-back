<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->decimal('precio', 12, 2)->default(0);
            $table->enum('unidad_medida', ['pieza', 'kg', 'litro', 'servicio', 'hora'])->default('pieza');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('empresa_id');
            $table->index(['empresa_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_productos');
    }
};
