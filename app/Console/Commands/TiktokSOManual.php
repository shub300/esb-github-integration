<?php

namespace App\Console\Commands;

use App\Http\Controllers\Tiktok\TiktokApiController;
use Illuminate\Console\Command;

class TiktokSOManual extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:TiktokSOManual';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tiktok Manual SO cron set';

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
		$tiktokController = new TiktokApiController();
		if( false ){
			$counter = 0;
			do {
				$tiktokController->getManuallyCronBaseSalesOrder();        
				$counter++;
			} while ($counter < 1);
		}
		
		$tiktokController->getManuallyCronBaseSalesOrder( "DELIVERED", 122 );
		//$tiktokController->getManuallyCronBaseSalesOrder( "COMPLETED", 130 );		
    }

}
