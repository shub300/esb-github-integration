<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserIntegrationSubEventTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_integration_sub_event', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_integration_id')->nullable();
            $table->integer('sub_event_id')->nullable();
            $table->enum('status', ['pending', 'inprocess', 'completed', 'failed'])->default('pending');
            $table->string('message', 500)->nullable();
            $table->dateTime('last_run_time')->nullable()->comment('For storing last ran time of the event use this with run_in_minute of platform_sub_event to calculate next backup sub event run time');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');

            $table->index(['user_integration_id', 'sub_event_id'], 'subevent_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_integration_sub_event');
    }
}
