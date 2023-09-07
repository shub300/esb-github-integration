<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformProductOptionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_product_options', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('option_name', 100)->nullable();
            $table->integer('api_option_id')->nullable();
            $table->integer('api_option_value_id')->nullable();
            $table->string('option_value', 100)->nullable();
            $table->integer('platform_product_id')->nullable()->index('platform_product_id');
            $table->boolean('status')->default(false);
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
        Schema::dropIfExists('platform_product_options');
    }
}
