<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformWebhookInfoTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_webhook_info', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->nullable()->default(0);
            $table->integer('user_integration_id')->nullable()->default(0);
            $table->integer('platform_id')->nullable()->index('platform_id');
            $table->string('api_id', 191)->nullable();
            $table->string('description', 100)->nullable();
            $table->boolean('status')->nullable()->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');

            $table->index(['user_id', 'user_integration_id', 'platform_id', 'api_id'], 'account_details');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_webhook_info');
    }
}
