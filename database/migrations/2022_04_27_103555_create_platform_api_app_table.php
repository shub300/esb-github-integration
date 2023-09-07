<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformApiAppTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_api_app', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('organization_id')->default(0)->comment('0 means common default configuration. Api apps can be configured in organization level as well.');
            $table->longText('app_ref')->nullable();
            $table->integer('platform_id')->nullable()->index('platform_id');
            $table->longText('client_id')->nullable();
            $table->longText('client_secret')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');

            $table->index(['organization_id', 'platform_id'], 'organization_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_api_app');
    }
}
