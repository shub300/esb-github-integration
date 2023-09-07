<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformUrlsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_urls', function (Blueprint $table) {
            $table->foreign(['platform_id'], 'platform_urls_ibfk_1')->references(['id'])->on('platform_lookup');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_urls', function (Blueprint $table) {
            $table->dropForeign('platform_urls_ibfk_1');
        });
    }
}
