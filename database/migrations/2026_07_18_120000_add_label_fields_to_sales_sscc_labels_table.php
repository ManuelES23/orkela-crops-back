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
            $table->string('product_of_country', 2)->nullable()->after('pack_date')->index();
            $table->string('product_of_state', 3)->nullable()->after('product_of_country')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sales_sscc_labels', function (Blueprint $table) {
            $table->dropIndex(['pallet_tag']);
            $table->dropIndex(['grower']);
            $table->dropIndex(['product_of_country']);
            $table->dropIndex(['product_of_state']);
            $table->dropColumn(['pallet_tag', 'grower', 'variety', 'boxes_count', 'product_of_country', 'product_of_state']);
        });
    }
};
