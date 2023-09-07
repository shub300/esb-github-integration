<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformCustomerAdditionalInformationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_customer_additional_information', function (Blueprint $table) {
            $table->foreign(['platform_customer_id'], 'fk_del_cascade')->references(['id'])->on('platform_customer')->onUpdate('NO ACTION')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_customer_additional_information', function (Blueprint $table) {
            $table->dropForeign('fk_del_cascade');
        });
    }
}
