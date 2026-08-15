<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sf_field_checks', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('sf_employee_id')->nullable()->constrained('sf_employees')->nullOnDelete();
            $table->foreignId('checked_by_user_id')->constrained('users');
            $table->string('type', 20); // check_in | check_out
            $table->timestamp('checked_at');
            $table->timestamp('synced_at')->nullable();
            $table->string('evidence_photo_path')->nullable();
            $table->decimal('client_confidence', 5, 4)->nullable();
            $table->decimal('server_confidence', 5, 4)->nullable();
            $table->string('verification_status', 30)->default('pending');
            $table->boolean('manual_override')->default(false);
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('device_info')->nullable();
            $table->integer('clock_skew_seconds')->nullable();
            $table->timestamps();
            $table->index(['sf_employee_id', 'checked_at']);
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sf_field_checks');
    }
};
