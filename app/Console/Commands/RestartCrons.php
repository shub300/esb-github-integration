<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RestartCrons extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:restart_failed_crons';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'To clear cache so that stopped cron can restart again';

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
        \Illuminate\Support\Facades\Artisan::call('cache:clear');
        \Illuminate\Support\Facades\Artisan::call('view:clear');
        \Illuminate\Support\Facades\Artisan::call('route:clear');
        \Illuminate\Support\Facades\Artisan::call('config:clear');
        \Storage::disk('local')->append('testOverlapping.txt', 'Run Clear Cache at : '.date('Y-m-d H:i:s') );
    }
}
