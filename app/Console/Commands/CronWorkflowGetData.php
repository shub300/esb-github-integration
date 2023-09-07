<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CronWorkflowGetData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:workflow_get_data {user_workflow_rule_id} {event_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Workflow Get Event data sync';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $user_workflow_rule_id = $this->argument('user_workflow_rule_id');
        $event_id = $this->argument('event_id') ? $this->argument('event_id') : '';

        // \Storage::disk('local')->append('kernal_log.txt', PHP_EOL.'>>>> CommandFile-getData : '.$user_workflow_rule_id. ' time: ' . date('Y-m-d H:i:s'));

        app('App\Http\Controllers\WorkflowController')->CronWorkflowGetData($user_workflow_rule_id,$event_id);
    }
}
