<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AllowWayfairInventoryUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:allow_wayfair_inventory_update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'To allow wayfair inventory update';

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
        $limit = 50;
        $skip = 0;
        $user_integration = '469';
        $user_id = '490';
   
        app('App\Http\Controllers\Wayfair\WayfairApiController')->WayfairUpdateInventorybyintegration($user_integration, $limit, $skip, $user_id);

    }

}
