<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformOrderRefundLinesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_order_refund_lines', function (Blueprint $table) {
            $table->integer('id', true);
            $table->bigInteger('platform_order_refund_id')->default(0)->index('platform_order_refund_id')->comment('platform_order_refund primary id');
            $table->integer('api_order_line_id')->default(0)->comment('api order refund line id');
            $table->string('api_product_id', 30)->nullable()->comment('api product id');
            $table->string('variation_id', 20)->default('0')->comment('api product variation_id');
            $table->string('product_name')->nullable()->comment('api product name');
            $table->string('sku', 200)->nullable()->comment('api sku');
            $table->double('qty')->default(0)->comment('api product id');
            $table->double('price')->default(0)->comment('api product price');
            $table->double('subtotal')->default(0)->comment('api subtotal');
            $table->double('subtotal_tax')->default(0)->comment('api subtotal tax');
            $table->double('total')->default(0)->comment('api total');
            $table->double('total_tax')->default(0)->comment('api subtotal tax');
            $table->text('taxes')->nullable()->comment('api tax code or tax id.');
            $table->enum('row_type', ['ITEM', 'SHIPPING', 'TAX', 'DISCOUNT'])->default('ITEM')->comment('when discounted item=DISCOUNT shipment item or charge=SHIPPING Discount/Coupons = DISCOUNT tax or tax charge=TAX');
            $table->integer('api_warehouse_id')->nullable()->comment('store api_warehouse_id');
            $table->dateTime('api_release_date')->nullable()->comment('store api_release_date');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');

            $table->index(['platform_order_refund_id', 'api_product_id', 'product_name'], 'platform_order_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_order_refund_lines');
    }
}
