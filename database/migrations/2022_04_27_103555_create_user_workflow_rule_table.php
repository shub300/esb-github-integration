<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserWorkflowRuleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_workflow_rule', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->nullable()->index('idx_user_id');
            $table->integer('user_integration_id')->nullable();
            $table->integer('platform_workflow_rule_id')->index('platform_workflow_rule_id');
            $table->boolean('status')->default(true)->comment('1 active, 0 inactive');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');
            $table->timestamp('last_run_updated_at');
            $table->dateTime('sync_start_date')->nullable()->comment('Specific date from when data to be pulled');
            $table->enum('is_all_data_fetched', ['inprocess', 'completed', 'pending'])->default('pending')->comment('Default: pending;
when sync starts: inprocess;
When sync done: completed;');
            $table->boolean('is_notification_sent')->default(false)->comment('To check whether initial sync success notification is sent or not');

            $table->index(['user_integration_id', 'status', 'is_all_data_fetched'], 'IX_fields');
           
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_workflow_rule');
    }
}
