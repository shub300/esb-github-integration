<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformIntegrationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_integrations', function (Blueprint $table) {
            $table->foreign(['source_platform_id'], 'platform_integrations_ibfk_1')->references(['id'])->on('platform_lookup');
            $table->foreign(['destination_platform_id'], 'platform_integrations_ibfk_2')->references(['id'])->on('platform_lookup');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_integrations', function (Blueprint $table) {
            $table->dropForeign('platform_integrations_ibfk_1');
            $table->dropForeign('platform_integrations_ibfk_2');
        });
    }
}
