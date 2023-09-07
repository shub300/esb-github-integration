<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class InitialSyncNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'success:initialDataSync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This cron is responsible to send success notification to user if initial data sync process has done.';

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
        app('App\Http\Controllers\CommonController')->sendInitialDataSyncedNotification();
    }
}
