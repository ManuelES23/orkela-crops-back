<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sf_employee_face_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sf_employee_id')->constrained('sf_employees')->cascadeOnDelete();
            $table->json('embedding');
            $table->string('photo_path');
            $table->string('model_version', 50);
            $table->foreignId('enrolled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enrolled_at');
            $table->timestamp('consent_signed_at');
            $table->string('consent_document_path')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('sf_employee_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sf_employee_face_templates');
    }
};
