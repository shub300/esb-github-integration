<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformWorkflowRuleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_workflow_rule', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('organization_id')->nullable()->default(0)->comment('org 0 is common');
            $table->integer('platform_integration_id');
            $table->integer('source_event_id')->nullable()->index('source_event_id')->comment('Event primary id from platform_event table');
            $table->integer('destination_event_id')->nullable()->index('destination_event_id')->comment('action to be performed primary id from platform_event table');
            $table->boolean('is_file_mapping')->default(false)->comment('Allow file based mapping');
            $table->boolean('status')->default(true);
            $table->string('tooltip_text', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');

            $table->index(['platform_integration_id', 'status'], 'idx_platform_integration_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_workflow_rule');
    }
}
