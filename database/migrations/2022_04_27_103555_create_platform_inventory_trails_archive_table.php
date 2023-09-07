<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformInventoryTrailsArchiveTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_inventory_trails_archive', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('user_id');
            $table->integer('user_integration_id');
            $table->integer('platform_id')->nullable();
            $table->integer('api_id')->nullable()->default(0);
            $table->integer('platform_product_id')->nullable()->index('platform_product_relation')->comment('FK from platform_product table');
            $table->string('api_product_id', 30)->nullable()->index('api_product_id');
            $table->integer('api_quantity')->default(0);
            $table->string('api_warehouse_id', 100)->nullable();
            $table->string('api_location_id', 20)->nullable()->default('NULL');
            $table->string('api_currency_code', 10)->nullable()->default('NULL');
            $table->string('api_type_code', 10)->nullable();
            $table->string('api_updated_at')->nullable()->default('NULL');
            $table->enum('sync_status', ['Pending', 'Ready', 'Synced', 'Failed'])->default('Ready');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();

            $table->index(['user_id', 'user_integration_id', 'platform_id', 'api_id', 'api_type_code'], 'group_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_inventory_trails_archive');
    }
}
