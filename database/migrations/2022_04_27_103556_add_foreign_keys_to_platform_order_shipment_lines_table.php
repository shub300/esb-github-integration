<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformOrderShipmentLinesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_order_shipment_lines', function (Blueprint $table) {
            $table->foreign(['platform_order_shipment_id'], 'cascade_shipment_line')->references(['id'])->on('platform_order_shipments')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_order_shipment_lines', function (Blueprint $table) {
            $table->dropForeign('cascade_shipment_line');
        });
    }
}
