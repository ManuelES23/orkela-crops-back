<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sf_field_checks', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->after('id')->constrained('enterprises')->cascadeOnDelete();
            $table->index(['enterprise_id', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::table('sf_field_checks', function (Blueprint $table) {
            $table->dropIndex(['enterprise_id', 'verification_status']);
            $table->dropConstrainedForeignId('enterprise_id');
        });
    }
};
