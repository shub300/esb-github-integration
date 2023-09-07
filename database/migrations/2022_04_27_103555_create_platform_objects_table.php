<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformObjectsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_objects', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name', 100)->unique('name')->comment('Object Name');
            $table->string('description', 400)->nullable();
            $table->string('display_name', 50)->nullable();
            $table->string('linked_with', 50)->nullable()->comment('linked mapping object to store in mapping');
            $table->string('store_with', 100)->nullable()->comment('store mapping with object, use when one2one & default mapping both are available');
            $table->enum('object_type', ['data_source', 'mapping_rule'])->nullable()->comment('data_source for retrieve mapping data & mapping_rule for on off mapping rule');
            $table->enum('linked_table', ['platform_object_data', 'platform_fields'])->default('platform_object_data')->comment('Linked table to polulate data in mapping UI');
            $table->boolean('status')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_objects');
    }
}
