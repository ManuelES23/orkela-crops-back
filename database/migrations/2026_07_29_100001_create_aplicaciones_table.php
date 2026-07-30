<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $table->string('folio', 50)->nullable();
            $table->date('fecha');
            $table->enum('tipo_aplicacion', ['agroquimico', 'fertilizante']);
            $table->foreignId('productor_id')->constrained('productores')->restrictOnDelete();
            $table->foreignId('zona_cultivo_id')->nullable()->constrained('zonas_cultivo')->nullOnDelete();
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();
            $table->foreignId('variedad_id')->nullable()->constrained('variedades')->nullOnDelete();
            $table->decimal('superficie_aplicada', 10, 2)->nullable();
            $table->string('metodo_aplicacion', 150)->nullable();
            $table->text('problematica');
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('temporada_id');
            $table->index('productor_id');
            $table->index('lote_id');
            $table->index('fecha');
            $table->index('tipo_aplicacion');
            $table->index(['temporada_id', 'folio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicaciones');
    }
};
