<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEsPlatformAccessTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('es_platform_access', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('organization_id')->nullable();
            $table->integer('platform_integration_id')->nullable()->index('platform_integration_id');
            $table->string('status', 45)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'platform_integration_id'], 'intg_access');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('es_platform_access');
    }
}
