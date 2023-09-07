<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformProductDetailAttributesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_product_detail_attributes', function (Blueprint $table) {
            $table->foreign(['platform_product_id'], 'linked_with_product_table')->references(['id'])->on('platform_product')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_product_detail_attributes', function (Blueprint $table) {
            $table->dropForeign('linked_with_product_table');
        });
    }
}
