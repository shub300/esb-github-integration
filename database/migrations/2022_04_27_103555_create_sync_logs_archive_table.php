<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSyncLogsArchiveTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sync_logs_archive', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->unsignedInteger('user_id')->index('user_id');
            $table->integer('user_workflow_rule_id')->nullable();
            $table->string('sync_type', 20)->nullable()->comment('will need to remove and managed in object id
');
            $table->integer('source_platform_id')->nullable()->index('source_platform_id');
            $table->integer('destination_platform_id')->nullable()->index('destination_platform_id');
            $table->enum('sync_status', ['success', 'failed', 'pending'])->index('IX_sync_status');
            $table->integer('platform_object_id')->nullable()->index('platform_object_id');
            $table->longText('response')->nullable();
            $table->bigInteger('record_id')->default(0);
            $table->string('timestamp', 20)->nullable()->comment('This will help to change update time even if the sync outcome is same as earlier.');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');
            $table->boolean('status')->default(true);

            $table->index(['user_workflow_rule_id', 'record_id'], 'log_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sync_logs_archive');
    }
}
