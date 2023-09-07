<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformWebhookInfoTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_webhook_info', function (Blueprint $table) {
            $table->foreign(['platform_id'], 'platform_webhook_info_ibfk_1')->references(['id'])->on('platform_lookup');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_webhook_info', function (Blueprint $table) {
            $table->dropForeign('platform_webhook_info_ibfk_1');
        });
    }
}
