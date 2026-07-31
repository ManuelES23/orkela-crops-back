<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_empresas_externas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->string('razon_social');
            $table->string('rfc', 20)->nullable();
            $table->string('industria')->nullable();
            $table->string('sitio_web')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_empresas_externas');
    }
};
