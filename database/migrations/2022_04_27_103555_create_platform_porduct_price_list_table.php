<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformPorductPriceListTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_porduct_price_list', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('platform_product_id')->nullable()->default(0)->comment('platform_product primary id');
            $table->integer('platform_object_data_id')->default(0)->comment('platform_object_data primary id');
            $table->float('price', 10, 0)->default(0);
            $table->string('api_currency_code', 10)->nullable();
            $table->integer('status')->nullable()->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');

            $table->index(['platform_product_id', 'platform_object_data_id'], 'platform_product_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_porduct_price_list');
    }
}
