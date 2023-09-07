<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformObjectDataTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_object_data', function (Blueprint $table) {
            $table->foreign(['platform_object_id'], 'platform_object_data_ibfk_1')->references(['id'])->on('platform_objects');
            $table->foreign(['platform_id'], 'platform_object_data_ibfk_2')->references(['id'])->on('platform_lookup');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_object_data', function (Blueprint $table) {
            $table->dropForeign('platform_object_data_ibfk_1');
            $table->dropForeign('platform_object_data_ibfk_2');
        });
    }
}
