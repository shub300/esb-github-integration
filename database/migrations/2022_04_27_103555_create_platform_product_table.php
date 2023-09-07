<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformProductTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_product', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->default(0);
            $table->integer('user_integration_id')->default(0);
            $table->integer('platform_id')->nullable()->index('platform_id');
            $table->string('api_product_id', 100)->nullable();
            $table->string('api_product_code', 100)->nullable();
            $table->string('api_variant_id', 30)->nullable()->comment('API id of the variant of the product, if has any. Put the has_variation column to no and set the parent_product_id with the platform_product primary id of the parent product');
            $table->enum('inventory_tracking', ['NONE', 'PRODUCT', 'VARIANT'])->default('PRODUCT')->comment('Inventory is tracked through product / variant.');
            $table->string('product_name')->nullable();
            $table->string('ean', 100)->nullable();
            $table->string('sku', 100)->nullable()->index('sku');
            $table->string('manufacturer_sku', 200)->nullable();
            $table->string('gtin', 100)->nullable();
            $table->string('upc', 100)->nullable()->index('upc');
            $table->string('isbn', 30)->nullable();
            $table->string('mpn', 30)->nullable();
            $table->string('barcode', 100)->nullable()->index('barcode');
            $table->string('brand_id', 300)->nullable();
            $table->string('api_warehouse_id', 100)->nullable();
            $table->boolean('bundle')->nullable();
            $table->float('weight', 10, 4)->nullable();
            $table->enum('weight_unit', ['lbs', 'kg', 'oz', 'g'])->nullable();
            $table->string('uom', 30)->nullable();
            $table->boolean('stock_track')->nullable();
            $table->text('custom_fields')->nullable();
            $table->string('product_status', 30)->nullable();
            $table->double('price')->nullable()->comment('If price is Null then refer to platform_product_price table for individual prices');
            $table->text('description')->nullable();
            $table->string('category_id')->nullable()->comment('Store comma-separated API ids or category name if there is no id');
            $table->boolean('has_variations')->nullable()->default(false)->comment('if any product has variation set true and false');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();
            $table->enum('product_sync_status', ['Pending', 'Ready', 'Synced', 'Failed', 'Inactive', 'Processing', 'Ignore'])->default('Pending')->index('product_sync_status');
            $table->enum('inventory_sync_status', ['Pending', 'Ready', 'Synced', 'Failed', 'Inactive', 'Processing', 'Ignore'])->default('Pending')->index('inventory_sync_status');
            $table->string('api_updated_at', 100)->nullable();
            $table->string('api_inventory_lastmodified_time', 100)->nullable();
            $table->string('parent_product_id', 150)->nullable()->comment('if any product has parent product this will be mention here (platform_product primary id) and if we have multiple then separated by comma');
            $table->integer('linked_id')->default(0)->comment('destination platform product id link with product');
            $table->boolean('is_deleted')->default(false)->comment('is_deleted=true means product deleted');

            $table->index(['api_product_id', 'bundle', 'stock_track', 'is_deleted'], 'Idx_product_details');
            $table->index(['user_id', 'user_integration_id'], 'Ix_user_details');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_product');
    }
}
