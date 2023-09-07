<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformCustomFieldValuesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_custom_field_values', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('platform_field_id');
            $table->integer('user_integration_id')->index('IX_user_integration_id');
            $table->integer('platform_id')->index('IX_platform_id');
            $table->text('field_value')->nullable();
            $table->integer('record_id')->index('IX_record_id')->comment('Primary id of platform_order or platform_product etc.');
            $table->boolean('status')->default(true);
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
        Schema::dropIfExists('platform_custom_field_values');
    }
}
