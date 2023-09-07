<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformWorkflowRuleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_workflow_rule', function (Blueprint $table) {
            $table->foreign(['source_event_id'], 'platform_workflow_rule_ibfk_1')->references(['id'])->on('platform_events');
            $table->foreign(['destination_event_id'], 'platform_workflow_rule_ibfk_2')->references(['id'])->on('platform_events');
            $table->foreign(['platform_integration_id'], 'platform_workflow_rule_ibfk_3')->references(['id'])->on('platform_integrations');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_workflow_rule', function (Blueprint $table) {
            $table->dropForeign('platform_workflow_rule_ibfk_1');
            $table->dropForeign('platform_workflow_rule_ibfk_2');
            $table->dropForeign('platform_workflow_rule_ibfk_3');
        });
    }
}
