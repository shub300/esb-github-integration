<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformObjectDataAdditionalInformationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_object_data_additional_information', function (Blueprint $table) {
            $table->foreign(['platform_object_data_id'], 'platform_object_data_additional_information_ibfk_1')->references(['id'])->on('platform_object_data')->onDelete('CASCADE');
            $table->foreign(['user_integration_id'], 'platform_object_data_additional_information_ibfk_2')->references(['id'])->on('user_integrations')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_object_data_additional_information', function (Blueprint $table) {
            $table->dropForeign('platform_object_data_additional_information_ibfk_1');
            $table->dropForeign('platform_object_data_additional_information_ibfk_2');
        });
    }
}
