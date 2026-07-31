<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_vendedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('nombre');
            $table->string('email')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('empresa_id');
            $table->index('user_id');
            $table->index(['empresa_id', 'activo']);
            // Un usuario solo puede ser vendedor una vez por empresa
            $table->unique(['empresa_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_vendedores');
    }
};
