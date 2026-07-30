<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos_aplicacion', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 200);
            $table->string('ingrediente_activo', 200)->nullable();
            $table->string('marca', 150)->nullable();
            $table->enum('tipo', ['agroquimico', 'fertilizante'])->default('agroquimico');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('tipo');
            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos_aplicacion');
    }
};
