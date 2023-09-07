<?php

namespace App\Console\Commands;

use App\Http\Controllers\Tiktok\TiktokApiController;
use Illuminate\Console\Command;

class TiktokSODeliveredManual extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:TiktokSODeliveredManual';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tiktok Manual SO Completed cron set';

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
      $tiktokController->getManuallyCronBaseCompletedSalesOrder( "COMPLETED", 130 );		
    }
}
