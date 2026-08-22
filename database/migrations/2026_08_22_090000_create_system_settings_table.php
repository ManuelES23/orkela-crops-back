<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();   // 'security.two_factor_enabled'
            $table->string('group', 50);             // 'security' | 'database' | 'notifications' | 'email'
            $table->string('label', 150);            // Nombre legible mostrado en el panel
            $table->enum('type', ['boolean', 'integer', 'string', 'email']);
            $table->text('value')->nullable();       // Guardado siempre como texto; se interpreta según `type`
            $table->unsignedTinyInteger('order')->default(1);
            $table->timestamps();

            $table->index(['group', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
