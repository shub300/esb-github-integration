<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformOrderLineTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_order_line', function (Blueprint $table) {
            $table->integer('id', true);
            $table->bigInteger('platform_order_id')->default(0);
            $table->string('api_order_line_id', 20)->nullable()->index('api_order_line_id');
            $table->string('api_product_id', 30)->nullable()->comment('amazonProductIdentifier');
            $table->string('product_name', 5000)->nullable();
            $table->integer('item_row_sequence')->default(0);
            $table->string('ean', 100)->nullable();
            $table->string('sku', 100)->nullable()->comment('vendorProductIdentifier');
            $table->string('gtin', 100)->nullable();
            $table->string('upc', 100)->nullable();
            $table->string('mpn', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->double('qty')->default(0);
            $table->double('subtotal')->default(0);
            $table->double('subtotal_tax')->default(0);
            $table->double('total')->default(0);
            $table->double('total_tax')->default(0);
            $table->text('taxes')->nullable()->comment('Api Tax Class Id');
            $table->string('variation_id', 20)->default('0');
            $table->double('price')->default(0);
            $table->double('unit_price')->default(0);
            $table->string('uom', 50)->nullable()->comment('unit of measure');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('api_code', 100)->nullable()->comment('for storing Brightpearl nominalCode');
            $table->enum('row_type', ['ITEM', 'SHIPPING', 'DISCOUNT', 'TAX', 'GIFTCARD'])->default('ITEM')->comment('when discounted item=DISCOUNT
shipment item or charge=SHIPPING
Discount/Coupons = DISCOUNT
tax or tax charge=TAX');
            $table->integer('linked_id')->default(0);
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');

            $table->index(['platform_order_id', 'api_product_id', 'sku', 'gtin', 'upc'], 'platform_order_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_order_line');
    }
}
