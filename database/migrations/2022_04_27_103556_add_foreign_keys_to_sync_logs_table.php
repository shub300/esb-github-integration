<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToSyncLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->foreign(['source_platform_id'], 'sync_logs_ibfk_1')->references(['id'])->on('platform_lookup');
            $table->foreign(['destination_platform_id'], 'sync_logs_ibfk_2')->references(['id'])->on('platform_lookup');
            $table->foreign(['platform_object_id'], 'sync_logs_ibfk_3')->references(['id'])->on('platform_objects');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropForeign('sync_logs_ibfk_1');
            $table->dropForeign('sync_logs_ibfk_2');
            $table->dropForeign('sync_logs_ibfk_3');
        });
    }
}
