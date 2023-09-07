<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformDataMappingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_data_mapping', function (Blueprint $table) {
            $table->foreign(['platform_object_id'], 'platform_data_mapping_ibfk_1')->references(['id'])->on('platform_objects');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_data_mapping', function (Blueprint $table) {
            $table->dropForeign('platform_data_mapping_ibfk_1');
        });
    }
}
