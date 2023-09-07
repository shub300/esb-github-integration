<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformOrderAddressArchiveTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_order_address_archive', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('platform_order_id')->default(0);
            $table->enum('address_type', ['billing', 'shipping', 'shippedfrom', 'customer', 'vendor'])->nullable();
            $table->string('address_name')->nullable()->comment('adress name or person name
');
            $table->string('address_id', 30)->nullable()->comment('zulily adderess id');
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('company')->nullable();
            $table->string('address1', 150)->nullable();
            $table->string('address2', 150)->nullable();
            $table->string('address3', 150)->nullable();
            $table->string('address4', 150)->nullable();
            $table->string('city', 150)->nullable();
            $table->string('state', 150)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country')->nullable();
            $table->string('email', 400)->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->string('ship_speed', 100)->nullable();
            $table->string('carrier_code', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');

            $table->index(['platform_order_id', 'address_type'], 'platform_order_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_order_address_archive');
    }
}
