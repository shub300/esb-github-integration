<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformDataMappingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_data_mapping', function (Blueprint $table) {
            $table->integer('id', true);
            $table->enum('mapping_type', ['regular', 'default', 'cross'])->nullable();
            $table->enum('data_map_type', ['field', 'object', 'custom', 'object_and_custom', 'custom_and_object', 'field_and_custom', 'custom_and_field'])->default('object')->comment('If field type refer to platform_field else refer for specific object value');
            $table->integer('platform_workflow_rule_id')->nullable();
            $table->integer('source_row_id')->nullable()->comment('primary id of the specific object eg. for product fields it will look at platform_fields. The same way for inventory mapping will look at platform_warehouses.');
            $table->integer('destination_row_id')->nullable()->comment('primary id of the specific object eg. for product fields it will look at platform_fields. The same way for inventory mapping will look at platform_warehouses.');
            $table->text('custom_data')->nullable()->comment('store custom mapping data like default text,est_day_in,est_month_in');
            $table->integer('user_integration_id')->nullable();
            $table->integer('platform_integration_id')->comment('Need for store field mapping data by integration level from super admin');
            $table->integer('platform_object_id')->nullable()->index('platform_object_id');
            $table->enum('status', ['1', '0'])->default('1');

            $table->index(['mapping_type', 'source_row_id', 'destination_row_id', 'user_integration_id'], 'map_relation');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_data_mapping');
    }
}
