<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformOrderShipmentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_order_shipments', function (Blueprint $table) {
            $table->foreign(['platform_order_id'], 'cascade_platform_order_shipments')->references(['id'])->on('platform_order')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_order_shipments', function (Blueprint $table) {
            $table->dropForeign('cascade_platform_order_shipments');
        });
    }
}
