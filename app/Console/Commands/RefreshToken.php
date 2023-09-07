<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RefreshToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'comman:refresh_token';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'comman:refresh_token';
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
        app('App\Http\Controllers\WorkflowController')->CronRefreshTokens();
    }
}
