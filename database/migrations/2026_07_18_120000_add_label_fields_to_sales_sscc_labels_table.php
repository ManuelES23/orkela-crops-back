<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_sscc_labels', function (Blueprint $table) {
            $table->string('pallet_tag', 120)->nullable()->after('lote')->index();
            $table->string('grower', 180)->nullable()->after('pallet_tag')->index();
            $table->string('variety', 180)->nullable()->after('grower');
            $table->unsignedInteger('boxes_count')->nullable()->after('variety');
        });
    }

    public function down(): void
    {
        Schema::table('sales_sscc_labels', function (Blueprint $table) {
            $table->dropIndex(['pallet_tag']);
            $table->dropIndex(['grower']);
            $table->dropColumn(['pallet_tag', 'grower', 'variety', 'boxes_count']);
        });
    }
};
