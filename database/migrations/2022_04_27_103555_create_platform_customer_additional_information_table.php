<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformCustomerAdditionalInformationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_customer_additional_information', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('platform_customer_id')->nullable()->index('fk_del_cascade');
            $table->string('api_tag_id', 50)->nullable()->comment('Brightpearl tag\'s ID can be comma saperated');
            $table->string('location_id', 50)->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_customer_additional_information');
    }
}
