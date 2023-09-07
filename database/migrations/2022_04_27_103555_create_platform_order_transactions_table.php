<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformOrderTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_order_transactions', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('platform_id')->nullable()->comment('It can be null. some integration of source platform is changed into destination.so we need this');
            $table->integer('user_integration_id')->nullable()->comment('It can be null. some integration of source platform is changed into destination.so we need this');
            $table->bigInteger('platform_order_id')->nullable()->index('platform_order_id');
            $table->integer('api_transaction_index_id')->nullable();
            $table->string('transaction_id', 50)->nullable();
            $table->string('transaction_datetime', 50)->nullable();
            $table->string('transaction_type', 100)->nullable();
            $table->string('transaction_method')->nullable();
            $table->float('transaction_amount', 10, 0)->nullable();
            $table->string('transaction_approval', 50)->nullable();
            $table->string('transaction_reference')->nullable();
            $table->integer('transaction_gateway_id')->nullable();
            $table->string('transaction_cvv2')->nullable();
            $table->string('transaction_avs')->nullable();
            $table->text('transaction_response_text')->nullable();
            $table->string('transaction_response_code', 50)->nullable();
            $table->integer('transaction_captured')->nullable();
            $table->enum('row_type', ['PAYMENT', 'REFUND'])->default('PAYMENT')->comment('default payment, set refund if you have');
            $table->enum('sync_status', ['Synced', 'Failed', 'Pending', 'Ready', 'Inactive', 'Processing', 'Ignore'])->default('Ready');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');
            $table->integer('platform_customer_id')->nullable()->comment('platform customer id');
            $table->string('currency_code', 10)->nullable()->comment('Currency ISO code');
            $table->double('exchange_rate')->default(0)->comment('Exchange rate');
            $table->string('bank_account', 25)->nullable()->comment('can use for bank account code /nominal code');
            $table->integer('linked_id')->nullable()->comment('primary id of associate row');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_order_transactions');
    }
}
