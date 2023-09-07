<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterTablePlatformCustomer extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_customer', function (Blueprint $table) {
            $table->string('email2')->after('email')->nullable()->default(null)->comment('secondary email');;
            $table->string('email3')->after('email2')->nullable()->default(null)->comment('tertiary email');;
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_customer', function (Blueprint $table) {
            $table->dropColumn('email2');
            $table->dropColumn('email3');
        });
    }
}
