<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_zonas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->foreignId('region_id')->constrained('crm_regiones')->onDelete('cascade');
            $table->string('nombre');
            $table->timestamps();

            $table->index('empresa_id');
            $table->index('region_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_zonas');
    }
};
