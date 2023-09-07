<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformOrderShipmentsArchiveTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_order_shipments_archive', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('user_id')->nullable()->default(0);
            $table->integer('platform_id')->default(0);
            $table->integer('user_integration_id')->default(0);
            $table->string('shipment_id', 100)->nullable()->comment('API primary id of shipment');
            $table->enum('sync_status', ['Pending', 'Synced', 'Ready', 'Failed', 'Inactive', 'Processing', 'Ignore'])->default('Pending');
            $table->bigInteger('platform_order_id')->nullable()->index('platform_order_id')->comment('your order table primary id');
            $table->string('order_id', 30)->nullable()->index('order_id')->comment('API primary id of the order');
            $table->integer('shipment_sequence_number')->comment('api shipment sequence number');
            $table->string('warehouse_id', 20)->nullable()->comment('API primary id of warehouse');
            $table->string('to_warehouse_id', 20)->nullable()->comment('	API primary id of destination warehouse');
            $table->boolean('shipment_transfer')->nullable();
            $table->text('shipment_status')->nullable()->comment('Use for API shipment status json or array values (For BP it is storing JSON but don\'t use json or array for any other platform [use plain text] )');
            $table->integer('boxes')->nullable();
            $table->string('tracking_info', 100)->nullable()->comment('tracking number info');
            $table->string('shipping_method', 20)->nullable()->comment('Api shipment method');
            $table->string('carrier_code', 50)->nullable();
            $table->string('ship_class', 50)->nullable()->comment('also known as ship_speed');
            $table->enum('type', ['Shipment', 'Transfer'])->default('Shipment');
            $table->string('realease_date', 30)->nullable()->comment('Api shipment release date');
            $table->string('created_on', 30)->nullable()->comment('shipment created date as a string ');
            $table->float('weight', 10, 0)->nullable();
            $table->string('created_by', 30)->nullable()->comment('API created by id use for storing API user ids');
            $table->text('tracking_url')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');
            $table->string('shipment_file_name')->nullable()->comment('using for uploading file names save for acknowledge');
            $table->integer('linked_id')->nullable()->comment('shipment id of other row');

            $table->index(['user_id', 'platform_id', 'user_integration_id', 'shipment_id', 'sync_status'], 'user_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_order_shipments_archive');
    }
}
