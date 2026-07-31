<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_bodegas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->foreignId('zona_id')->constrained('crm_zonas')->onDelete('cascade');
            $table->string('nombre');
            $table->string('direccion')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
            $table->index('zona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_bodegas');
    }
};
