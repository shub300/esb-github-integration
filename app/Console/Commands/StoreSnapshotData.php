<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StoreSnapshotData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:storesnapshotdata';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Store snapshot data';

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
     * @return int
     */
    public function handle()
    {
        $user_id = 523;
		$user_integration_id = 521;
		$is_initial_sync = false;


        $time = time();
        $timeZone = config('app.timezone');
        $logFileName = 'custom_cron_test_'.date('Y-m-d').'.txt';

        //log
        $start_time = microtime(true);
        \Storage::disk('local')->append($logFileName,' store snapshot(7m) Arrival time: ' . date('Y-m-d H:i:s'). ' timeZone : '.$timeZone. ' start_time : '.$start_time. 'constant time : '.$time);

        app('App\Http\Controllers\Jasci\JasciController')->storeInventorySnapshot($user_id, $user_integration_id, $is_initial_sync);

        //log
        $end_time = microtime(true);
        \Storage::disk('local')->append($logFileName,' store snapshot(7m) exit time: ' . date('Y-m-d H:i:s'). ' timeZone : '.$timeZone. ' end_time : '.$end_time. 'constant time : '.$time);

    

    }

}
