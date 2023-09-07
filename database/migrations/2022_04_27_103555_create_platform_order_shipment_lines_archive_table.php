<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformOrderShipmentLinesArchiveTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_order_shipment_lines_archive', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('platform_order_shipment_id')->index('platform_order_shipment_id');
            $table->string('row_id', 20)->nullable()->comment('API primary id of the shipment lines');
            $table->string('product_id', 20)->nullable()->comment('API primary id of the product');
            $table->string('sku', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('location_id', 20)->nullable()->comment('API primary id of the location');
            $table->string('currency', 20)->nullable();
            $table->double('price')->default(0);
            $table->string('warehouse_id', 20)->nullable();
            $table->integer('quantity')->nullable()->default(0);
            $table->string('user_batch_reference', 20)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_order_shipment_lines_archive');
    }
}
