<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformOrderAdditionalInformationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_order_additional_information', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('platform_order_id')->index('cascade_order_add_info')->comment('primary of platform_order');
            $table->string('api_channel_id', 50)->nullable();
            $table->string('api_owner_id', 50)->nullable();
            $table->string('store_number', 50)->nullable();
            $table->boolean('is_drop_ship')->default(false);
            $table->string('closed_on', 50)->nullable();
            $table->double('exchange_rate')->default(0);
            $table->integer('parent_order_id')->default(0);
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
        Schema::dropIfExists('platform_order_additional_information');
    }
}
