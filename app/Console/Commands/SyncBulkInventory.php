<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncBulkInventory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:syncbulkinventory';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync bulk inventory';

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

        $time = time();
        $timeZone = config('app.timezone');
        $logFileName = 'custom_cron_test_'.date('Y-m-d').'.txt';

        //log
        $start_time = microtime(true);
        \Storage::disk('local')->append($logFileName,' Sync bulk inventory (8m) Arrival time: ' . date('Y-m-d H:i:s'). ' timeZone : '.$timeZone. ' start_time : '.$start_time. 'constant time : '.$time);

        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->SyncInventoryBulk($user_id, $user_integration_id, 'jasci', 158, 1114, 'Ready', NULL);

        //log
        $end_time = microtime(true);
        \Storage::disk('local')->append($logFileName,' Sync bulk inventory (8m) exit time: ' . date('Y-m-d H:i:s'). ' timeZone : '.$timeZone. ' end_time : '.$end_time. 'constant time : '.$time);


    }

}
