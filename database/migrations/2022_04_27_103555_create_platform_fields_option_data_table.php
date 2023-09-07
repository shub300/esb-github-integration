<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformFieldsOptionDataTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_fields_option_data', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('platform_field_id')->nullable()->index('platform_field_id');
            $table->string('field_value')->nullable();
            $table->string('field_value_id', 200)->nullable();
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
        Schema::dropIfExists('platform_fields_option_data');
    }
}
