<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformAccountAddtionalInformationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_account_addtional_information', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('account_id')->nullable();
            $table->integer('user_integration_id')->nullable();
            $table->string('account_currency_code', 10)->nullable();
            $table->string('account_product_lenght_unit', 50)->nullable();
            $table->string('account_product_weight_unit', 10)->nullable();
            $table->string('account_shipping_nominal_code', 10)->nullable();
            $table->string('account_discount_nominal_code', 10)->nullable();
            $table->string('account_sale_nominal_code', 10)->nullable();
            $table->string('account_purchase_nominal_code', 10)->nullable();
            $table->string('account_giftcard_nominal_code', 10)->nullable()->comment('api default gift card nominal code');
            $table->string('account_timezone', 200)->nullable();
            $table->string('account_tax_scheme', 30)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');

            $table->index(['account_id', 'user_integration_id'], 'account_details');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_account_addtional_information');
    }
}
