<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Helper\Api\CronHelper;

class DataRetention extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:data_retention_bot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Data retention policy to delete unnessesory data after spefic time period';

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
        app('App\Helper\Api\CronHelper')->HandleDataRetention();
    }
}
