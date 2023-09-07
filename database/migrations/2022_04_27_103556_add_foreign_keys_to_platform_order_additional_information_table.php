<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformOrderAdditionalInformationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_order_additional_information', function (Blueprint $table) {
            $table->foreign(['platform_order_id'], 'cascade_order_add_info')->references(['id'])->on('platform_order')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_order_additional_information', function (Blueprint $table) {
            $table->dropForeign('cascade_order_add_info');
        });
    }
}
