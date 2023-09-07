<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformProductInventoryTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_product_inventory', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('user_integration_id');
            $table->integer('platform_id')->nullable()->index('platform_id');
            $table->integer('platform_product_id')->nullable()->index('platform_product_id');
            $table->string('api_product_id')->nullable();
            $table->string('api_warehouse_id', 100)->nullable();
            $table->integer('quantity')->default(0);
            $table->string('sku', 100)->nullable()->index('sku');
            $table->string('location_code')->nullable();
            $table->enum('sync_status', ['Pending', 'Ready', 'Synced', 'Failed'])->default('Pending')->comment('This column is not in use. So use platform_product table\'s inventory_sync_status column to find product inventory status ');
            $table->string('api_updated_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();

            $table->index(['user_id', 'user_integration_id', 'platform_id', 'api_product_id'], 'user_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_product_inventory');
    }
}
