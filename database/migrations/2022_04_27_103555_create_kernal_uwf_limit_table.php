<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateKernalUwfLimitTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('kernal_uwf_limit', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('url')->nullable();
            $table->enum('type', ['REFRESHTOKEN', 'WORKFLOW', 'DATA_RETENTION_BOT', 'SYNC_FAILED_NOTIFICATION']);
            $table->integer('max_limit');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('kernal_uwf_limit');
    }
}
