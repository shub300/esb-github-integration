<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformOrderArchiveTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_order_archive', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('user_id')->default(0);
            $table->integer('user_workflow_rule_id')->nullable();
            $table->integer('platform_id')->nullable()->index('platform_id');
            $table->integer('user_integration_id')->default(0);
            $table->integer('platform_customer_id')->default(0)->index('platform_customer_id')->comment('Primary id of platform_customer table');
            $table->integer('platform_customer_emp_id')->nullable()->comment('Primary id of platform_customer table with type employee');
            $table->string('trading_partner_id', 30)->nullable();
            $table->enum('order_type', ['PO', 'SO', 'TO', 'IO'])->nullable();
            $table->string('api_order_id', 30)->nullable()->index('api_order_id');
            $table->string('api_order_reference', 60)->nullable()->comment('for storing brightpearl order reference hash, zulily PO docNumber');
            $table->string('customer_email')->nullable();
            $table->string('order_number', 30)->nullable()->index('IX_order_number');
            $table->string('currency', 5)->nullable();
            $table->string('order_date', 40)->nullable();
            $table->string('order_status', 50)->nullable();
            $table->enum('api_order_payment_status', ['paid', 'unpaid', 'partial_paid'])->default('unpaid');
            $table->integer('due_days')->default(0);
            $table->string('department', 100)->nullable();
            $table->string('vendor', 100)->nullable()->comment('amazon buying,selling,bill/ship to party ids');
            $table->double('total_discount')->default(0);
            $table->double('total_tax')->default(0);
            $table->double('total_amount')->default(0);
            $table->double('net_amount')->default(0);
            $table->double('shipping_total')->nullable()->default(0);
            $table->double('shipping_tax')->nullable()->default(0);
            $table->double('discount_tax')->default(0);
            $table->string('payment_date', 30)->nullable()->comment('paid or payment date');
            $table->string('delivery_date', 30)->nullable();
            $table->string('shipping_method', 50)->nullable();
            $table->text('notes')->nullable();
            $table->enum('sync_status', ['Pending', 'Ready', 'Synced', 'Failed', 'Inactive', 'Processing', 'Ignore'])->default('Pending')->index('IX_sync_status');
            $table->enum('refund_sync_status', ['Pending', 'Ready', 'Partial', 'Synced', 'Failed', 'Inactive', 'Processing', 'Ignore'])->default('Pending')->comment('default pending status utill not refunded and ready when refunded');
            $table->boolean('is_voided')->nullable()->default(false)->comment('when order status is cancelled set 1');
            $table->boolean('is_deleted')->default(false)->comment('When order is deleted set to 1');
            $table->enum('invoice_sync_status', ['Pending', 'Ready', 'Synced', 'Failed', 'Inactive', 'Processing', 'Ignore'])->nullable()->comment('default = null, to avoid unwanted api calls to get invoice details, invoice get will call when status = pending');
            $table->bigInteger('linked_id')->default(0)->index('IX_linked_id')->comment('destination platform order id link with order');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('order_updated_at')->nullable()->useCurrent();
            $table->timestamp('updated_at');
            $table->string('file_name', 100)->nullable();
            $table->string('ship_speed', 100)->nullable()->comment('Also referred as ship_class');
            $table->string('carrier_code', 100)->nullable();
            $table->string('warehouse_id', 50)->nullable()->comment('primary of platform_object_data');
            $table->boolean('order_update_status')->default(false);
            $table->string('api_updated_at', 50)->nullable();
            $table->enum('shipment_status', ['Synced', 'Ready', 'Partial', 'Pending', 'Failed', 'Inactive', 'Processing', 'Ignore'])->default('Pending')->comment('This status is used for displaying log Also Ready(Fully Synced) & Partial(Partial Synced) status can be picked for Syncing');
            $table->string('shipment_api_status', 50)->nullable()->comment('actual shipment api status code');
            $table->integer('platform_order_shipment_id')->nullable()->index('platform_order_shipment_id')->comment('platform_order_shipments table primary id');
            $table->string('api_pricelist_id', 50)->nullable();
            $table->enum('transaction_sync_status', ['Pending', 'Ready', 'Synced', 'Failed', 'Inactive', 'Processing', 'Ignore'])->default('Pending');

            $table->index(['user_id', 'platform_id', 'order_type', 'user_integration_id'], 'field_detail');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_order_archive');
    }
}
