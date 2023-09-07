<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserIntegrationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_integrations', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->nullable();
            $table->string('flow_name')->nullable();
            $table->integer('platform_integration_id')->nullable();
            $table->integer('selected_sc_account_id')->nullable();
            $table->integer('selected_dc_account_id')->nullable();
            $table->enum('workflow_status', ['draft', 'active', 'inactive'])->default('draft');
            $table->boolean('data_retention_status')->nullable();
            $table->integer('data_retention_period')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');
            $table->boolean('run_status')->default(false);
            $table->timestamp('last_run_updated_at');

            $table->index(['user_id', 'platform_integration_id', 'selected_sc_account_id', 'selected_dc_account_id', 'workflow_status'], 'IX_user_integ');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_integrations');
    }
}
