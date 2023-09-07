<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformSubEventTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_sub_event', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('platform_event_id')->index('platform_event_id');
            $table->string('name', 50);
            $table->boolean('is_primary')->default(false);
            $table->tinyInteger('prefetch')->default(0)->comment('Set 1 for the object data that needs to be pre processed while mapping');
            $table->boolean('status')->default(true);
            $table->boolean('run_backup')->default(false)->comment('Make this 1 if the non-primary object data(product, channel, etc) data needs to be processed frequently. ');
            $table->integer('run_in_min')->default(0)->comment('For Allowing backup events to run after certain time');
            $table->integer('priority')->default(0)->comment('tells the execute priority to cron');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_sub_event');
    }
}
