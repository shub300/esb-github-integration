<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformFieldsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_fields', function (Blueprint $table) {
            $table->foreign(['platform_id'], 'platform_fields_ibfk_1')->references(['id'])->on('platform_lookup');
            $table->foreign(['platform_object_id'], 'platform_fields_ibfk_2')->references(['id'])->on('platform_objects');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_fields', function (Blueprint $table) {
            $table->dropForeign('platform_fields_ibfk_1');
            $table->dropForeign('platform_fields_ibfk_2');
        });
    }
}
