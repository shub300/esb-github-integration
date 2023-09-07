<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RefreshTokenReSyncNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:RefreshTokenReSyncNotification';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'If an old refresh token is about to expire, this cron is in sending the user a refresh token message.';

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
        app('App\Http\Controllers\CommonController')->sendRefreshTokenReSyncNotification();
    }
}
