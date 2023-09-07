<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToUserWorkflowRuleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_workflow_rule', function (Blueprint $table) {
            $table->foreign(['platform_workflow_rule_id'], 'user_workflow_rule_ibfk_1')->references(['id'])->on('platform_workflow_rule');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_workflow_rule', function (Blueprint $table) {
            $table->dropForeign('user_workflow_rule_ibfk_1');
        });
    }
}
