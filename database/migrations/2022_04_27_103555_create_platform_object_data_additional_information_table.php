<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformObjectDataAdditionalInformationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_object_data_additional_information', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_integration_id')->index('user_integration_id');
            $table->integer('platform_object_data_id')->index('platform_object_data_id');
            $table->integer('api_address_id')->nullable();
            $table->string('address1')->nullable();
            $table->string('city', 40)->nullable();
            $table->string('state', 40)->nullable();
            $table->string('country', 5)->nullable()->comment('Country ISO code');
            $table->string('postal_code', 20)->nullable();
            $table->text('terms_info')->nullable()->comment('json will store here for payterms info');
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
        Schema::dropIfExists('platform_object_data_additional_information');
    }
}
