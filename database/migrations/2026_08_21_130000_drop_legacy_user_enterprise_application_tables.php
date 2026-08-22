<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Elimina las tablas del sistema de permisos legado (User::enterprises()/
     * applications(), ya removidas de app/Models/User.php). Confirmado que
     * ambas tablas tienen 0 filas en desarrollo y que nada las referencia ya
     * en el código — ver docs/superpowers/plans/2026-08-21-legacy-user-permissions-cleanup.md.
     * El sistema real es user_enterprise_access/user_application_access.
     */
    public function up(): void
    {
        Schema::dropIfExists('user_enterprises');
        Schema::dropIfExists('user_applications');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('user_enterprises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('enterprise_id')->constrained()->onDelete('cascade');
            $table->string('role')->default('user'); // admin, user, viewer
            $table->boolean('is_active')->default(true);
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'enterprise_id']);
        });

        Schema::create('user_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('application_id')->constrained()->onDelete('cascade');
            $table->json('permissions')->nullable(); // ['read', 'write', 'delete']
            $table->boolean('is_active')->default(true);
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'application_id']);
        });
    }
};
