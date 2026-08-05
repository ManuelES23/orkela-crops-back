<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_sscc_labels', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_sscc_labels', 'product_of_country')) {
                $table->string('product_of_country', 2)->nullable()->after('pack_date')->index();
            }
            if (! Schema::hasColumn('sales_sscc_labels', 'product_of_state')) {
                $table->string('product_of_state', 3)->nullable()->after('product_of_country')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_sscc_labels', function (Blueprint $table) {
            $table->dropIndexIfExists('sales_sscc_labels_product_of_country_index');
            $table->dropIndexIfExists('sales_sscc_labels_product_of_state_index');
            $table->dropColumn(array_filter(
                ['product_of_country', 'product_of_state'],
                fn ($col) => Schema::hasColumn('sales_sscc_labels', $col)
            ));
        });
    }
};
