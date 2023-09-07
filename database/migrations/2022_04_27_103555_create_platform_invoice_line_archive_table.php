<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformInvoiceLineArchiveTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_invoice_line_archive', function (Blueprint $table) {
            $table->integer('id', true);
            $table->bigInteger('platform_invoice_id')->default(0)->index('cascade_invoice_line');
            $table->string('api_invoice_line_id', 20)->nullable();
            $table->string('api_product_id', 30)->nullable();
            $table->string('product_name', 500)->nullable();
            $table->string('ean', 100)->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('gtin', 100)->nullable();
            $table->string('upc', 100)->nullable();
            $table->string('mpn', 100)->nullable();
            $table->double('qty')->default(0);
            $table->double('shipped_qty')->default(0);
            $table->double('unit_price')->default(0);
            $table->double('price')->default(0);
            $table->string('uom', 50)->nullable();
            $table->text('description')->nullable();
            $table->double('total')->default(0);
            $table->double('total_weight')->default(0);
            $table->string('api_code', 100)->nullable();
            $table->enum('row_type', ['ITEM', 'SHIPPING', 'DISCOUNT', 'TAX', 'GIFTCARD'])->default('ITEM')->comment('when discounted item=DISCOUNT shipment item or charge=SHIPPING Discount/Coupons = DISCOUNT tax or tax charge=TAX');
            $table->integer('linked_id')->default(0);
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
        Schema::dropIfExists('platform_invoice_line_archive');
    }
}
