<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformInvoiceTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_invoice', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('user_id')->default(0);
            $table->integer('platform_id')->nullable()->default(0);
            $table->integer('user_integration_id')->default(0);
            $table->bigInteger('platform_order_id')->default(0)->index('platform_order_id');
            $table->string('trading_partner_id', 50)->nullable();
            $table->string('order_doc_number', 30)->nullable();
            $table->string('order_state', 50)->nullable();
            $table->string('api_invoice_id')->nullable();
            $table->string('invoice_code', 50)->nullable();
            $table->integer('api_customer_code')->default(0)->comment('as a customer unique code');
            $table->string('invoice_state', 25)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('ref_number', 50)->nullable();
            $table->string('payment_terms', 50)->nullable();
            $table->string('invoice_date', 50)->nullable();
            $table->string('gl_posting_date', 30)->nullable();
            $table->string('ship_date', 30)->nullable();
            $table->string('pay_date', 30)->nullable();
            $table->double('total_amt')->default(0);
            $table->double('total_paid_amt')->default(0);
            $table->text('message')->nullable();
            $table->string('api_tax_code', 25)->nullable();
            $table->string('currency', 10)->nullable();
            $table->double('exchange_rate')->default(0);
            $table->double('net_total')->default(0);
            $table->double('total_tax')->default(0);
            $table->string('ship_via', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('zip', 15)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->string('ship_by_date', 30)->nullable();
            $table->float('due_amt', 10, 3)->default(0)->comment('transaction due amount');
            $table->string('due_date', 50)->nullable();
            $table->string('due_days', 10)->nullable();
            $table->string('total_qty', 10)->nullable();
            $table->string('api_created_at', 50)->nullable();
            $table->string('api_updated_at', 50)->nullable();
            $table->enum('sync_status', ['Pending', 'Ready', 'Synced', 'Failed', 'Inactive', 'Processing', 'Ignore'])->default('Pending')->comment('individual status for one to many case');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');
            $table->integer('linked_id')->nullable();

            $table->index(['user_id', 'platform_id', 'user_integration_id'], 'user_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_invoice');
    }
}
