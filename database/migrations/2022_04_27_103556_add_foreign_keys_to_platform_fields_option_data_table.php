<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformFieldsOptionDataTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_fields_option_data', function (Blueprint $table) {
            $table->foreign(['platform_field_id'], 'platform_fields_option_data_ibfk_2')->references(['id'])->on('platform_fields')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_fields_option_data', function (Blueprint $table) {
            $table->dropForeign('platform_fields_option_data_ibfk_2');
        });
    }
}
