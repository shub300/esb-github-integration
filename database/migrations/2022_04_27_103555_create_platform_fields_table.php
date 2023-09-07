<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformFieldsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_fields', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->nullable()->default(0);
            $table->integer('user_integration_id')->default(0);
            $table->string('name', 250)->nullable();
            $table->string('description', 250);
            $table->string('db_field_name', 30)->nullable();
            $table->integer('platform_id')->nullable()->index('platform_id');
            $table->enum('field_type', ['default', 'custom'])->default('default');
            $table->enum('custom_field_type', ['YES_NO', 'TEXT', 'TEXT_AREA', 'DATE', 'DATE_TIME', 'TIME', 'SELECT', 'MULTI_SELECT', 'INTEGER'])->nullable();
            $table->string('custom_field_id', 30)->nullable();
            $table->integer('custom_field_option_group_id')->nullable()->comment('Netsuite record type id (SELECT custom field value group id)');
            $table->enum('type', ['product', 'purchase_order', 'sales_order', 'product_identity'])->nullable();
            $table->boolean('status')->default(true);
            $table->integer('order_val')->nullable()->default(999);
            $table->string('required', 10)->nullable();
            $table->integer('platform_object_id')->nullable()->index('platform_object_id')->comment('primary id from platform object table
');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');

            $table->index(['user_id', 'name', 'platform_id', 'type', 'field_type'], 'field_detail');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_fields');
    }
}
