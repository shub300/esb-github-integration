<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DeleteDuplicateProduct extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:delete_duplicate_product';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete duplicate products';

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
        $user_integration_id = 422;
		$platform_id = 28;
		//start delete call
        app('App\Http\Controllers\CommonController')->findDuplicateProductAndDelete($user_integration_id,$platform_id);

    }

}
