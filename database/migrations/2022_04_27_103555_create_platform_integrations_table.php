<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformIntegrationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_integrations', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('source_platform_id')->nullable()->index('source_platform_id');
            $table->integer('destination_platform_id')->nullable()->index('destination_platform_id');
            $table->mediumText('description')->nullable()->comment('Integration details');
            $table->text('rule')->comment('Add rule to on mappings for all flow under selected integration Id');
            $table->integer('user_id')->default(0);
            $table->boolean('status')->default(true);
            $table->boolean('data_retention_status')->nullable();
            $table->integer('data_retention_period')->nullable()->comment('Platform level data retention time period in days to remove unused data');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_integrations');
    }
}
