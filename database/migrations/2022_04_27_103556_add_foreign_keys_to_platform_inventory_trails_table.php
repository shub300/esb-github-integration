<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformInventoryTrailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_inventory_trails', function (Blueprint $table) {
            $table->foreign(['platform_product_id'], 'platform_product_relation')->references(['id'])->on('platform_product')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_inventory_trails', function (Blueprint $table) {
            $table->dropForeign('platform_product_relation');
        });
    }
}
