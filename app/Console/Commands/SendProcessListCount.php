<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendProcessListCount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:process_list_count';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'send heavy process list count notification ';
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
        $processList = DB::select('SHOW PROCESSLIST');
        $processListCount = count($processList);
        if($processListCount > 110){
            //send heavy process list count
            $data = ['processListCount' => $processListCount];
            $body = view('template.process_list_template', compact('data'))->render();
            $emails = ['shubhamk.constacloud@gmail.com','virendran.constacloud@gmail.com'];
            foreach($emails as $email){
                $mailInfo = array(
                    'body_msg' => $body,
                    'to' => $email,
                    'subject' => 'Heavy process occured.',
                    'from' => env('MAIL_REGISTRATION_FROM_ADDRESS'),
                    'from_name' => env('MAIL_FROM_NAME'),
                );
                app('App\Http\Controllers\CommonController')->sendMailByDefaultConfiguration($mailInfo);
            }
        }
    }
}
