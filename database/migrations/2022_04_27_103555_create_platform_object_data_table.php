<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformObjectDataTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_object_data', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->nullable()->default(0);
            $table->integer('user_integration_id')->default(0);
            $table->integer('platform_id')->nullable()->index('platform_id');
            $table->integer('platform_object_id')->nullable()->index('platform_object_id')->comment('object id from platform_object table');
            $table->string('api_id')->nullable();
            $table->string('name')->nullable();
            $table->string('api_code')->nullable();
            $table->text('description')->nullable();
            $table->integer('parent_id')->nullable()->index('parent_id')->comment('used when you have dependent values');
            $table->boolean('status')->nullable()->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent();

            $table->index(['user_integration_id', 'platform_id', 'platform_object_id', 'api_id'], 'indexes_on_object_data_for_product_category');
            $table->index(['user_id', 'user_integration_id', 'platform_id', 'api_id'], 'user_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_object_data');
    }
}
