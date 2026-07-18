<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_sscc_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained('enterprises');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_file', 255)->nullable();
            $table->string('batch_code', 80)->index();
            $table->unsignedInteger('row_number')->default(0);

            $table->string('product_code', 120)->nullable()->index();
            $table->string('product_name', 255)->nullable();
            $table->string('lote', 120)->nullable()->index();
            $table->string('presentation', 180)->nullable();
            $table->date('pack_date')->nullable();

            $table->char('sscc', 18)->unique();
            $table->unsignedBigInteger('serial_reference')->index();
            $table->string('company_prefix', 12);
            $table->char('extension_digit', 1);
            $table->string('status', 20)->default('generated')->index();
            $table->timestamp('printed_at')->nullable();
            $table->json('raw_data')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['enterprise_id', 'company_prefix', 'extension_digit'], 'sales_sscc_labels_enterprise_prefix_ext_idx');
            $table->index(['enterprise_id', 'created_at'], 'sales_sscc_labels_enterprise_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_sscc_labels');
    }
};
