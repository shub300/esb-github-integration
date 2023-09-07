<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use App\Http\Controllers\WorkflowController;
use App\Helper\Api\CronHelper;
use App\Helper\MainModel;
use App\Models\KernelUWFLimit;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
// use Illuminate\Support\Stringable;
use DB;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{

    protected  $executionStartTime;
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\InitialSyncNotification::class,
        Commands\RefreshToken::class,
        Commands\CronWorkflowGetData::class,
        Commands\CronWorkflowMutationData::class,
        Commands\CronWorkInitialGetData::class,
        Commands\DataRetention::class,
        Commands\RestartCrons::class,
        Commands\NotificationEmails::class,
        Commands\AllowWayfairInventoryUpdate::class,

        //dammy added
        Commands\StoreSnapshotData::class,
        Commands\SyncBulkInventory::class

    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
// exit;
        date_default_timezone_set('UTC');
        $schedule->command('success:initialDataSync')->cron('*/5 * * * *')->onOneServer(); // cron to send success notification to user if initial data sync process has done.
        $cronHelper = new CronHelper();
        $app_url = env('APP_URL');
        if ($app_url == 'https://esb-stag.apiworx.net/integration') {
            $run_mode = 'STAG_';
        } else {
            $run_mode = 'PROD_';
        }

        //default record limit as max limit
        $record_limit = 10;
        //update database after every call 10
        $db_update_limit = 10;
        $countUpdateCall = 0;

        //if schedule call before 1 mint then return else process the do while

        //get kernal_uwf_limit data from cache
        $uwf_limit_data = $cronHelper->getDataFromCache('kernal_uwf_limit');
        if (!$uwf_limit_data) {
            $uwf_limit_data = DB::table('kernal_uwf_limit')->where('type', 'WORKFLOW')->select('id', 'max_limit', 'updated_at')->first();

            //set in cache with key kernal_uwf_limit
            if ($uwf_limit_data) {
                $cronHelper->setDataInCache('kernal_uwf_limit', $uwf_limit_data);
            }
        }


        if ($uwf_limit_data) {

            $uwf_limit_data_id = $uwf_limit_data->id;
            $record_limit = $uwf_limit_data->max_limit;

            //if last run time min is same then return
            if( isset($uwf_limit_data->last_run_time) && ($uwf_limit_data->last_run_time == date('Y-m-d H:i')) ) {
                // \Storage::disk('local')->append('kernal_log.txt', 'Return by min check : '.time().' lastUpdateTime :' .$uwf_limit_data->last_run_time);
                return true;
            }

            //update run time.. on instance for check, when execute event response get delay
            $updateArr = ['id' => $uwf_limit_data_id, 'max_limit' => $record_limit, 'updated_at' => date("Y-m-d H:i:s"), 'last_run_time' => date('Y-m-d H:i')];
            $cronHelper->setDataInCache('kernal_uwf_limit', $updateArr);
            //end

            // \Storage::disk('local')->append('kernal_log.txt', 'Execute Event call  timestamp :'.time().' current dt :' .date('Y-m-d H:i'). ' data from cache : '.json_encode($uwf_limit_data) );


            // $logFileName = 'cron_run_for_user_wf_log_'.date('Y-m-d').'.txt';
            $logFileName = 'execute_event_custom_'.date('Y-m-d').'.txt';
            // \Storage::disk('local')->append($logFileName, ' Cron run for user workflow start ');

            //run crons..if time diff > 1 min
            $page = 0;
            do {
                $flag = false; // If flag is true then GetUserWorkFlow function will execute.
                $user_arr = $cronHelper->GetUserWorkFlow($record_limit, $page);

                foreach ($user_arr as $user) {


                    if ($user->is_all_data_fetched == 'pending') {

                        $schedule->command('command:workflow_initial_sync', [$user->user_workflow_rule_id])
                            ->cron('*/2 * * * *')
                            ->name($run_mode . $user->user_workflow_rule_id . $user->source_event_id . $user->destination_event_id . 'CronWorkInitialGetData' . $user->user_workflow_rule_id)
                            ->withoutOverlapping(5)
                            ->runInBackground()->onOneServer();
                    } elseif ($user->is_all_data_fetched == 'completed') {

                        $isIntialRemaining = \DB::table('user_workflow_rule')
                            ->where(['status' => 1, 'user_integration_id' => $user->user_integration_id])->where('is_all_data_fetched', '<>', 'completed')->count();
                        if ($isIntialRemaining > 0) { // if entire integration has any running intial call then prevent regular sync api calls
                            continue;
                        }
                        $source_event = $cronHelper->PlatformEvent($user->source_event_id, ['run_in_min', 'event_id','run_in_min_custom','platform_name']);
                        $destination_event = $cronHelper->PlatformEvent($user->destination_event_id, ['run_in_min', 'event_id','run_in_min_custom','platform_name']);

                        //return array with source run time and destination run time
                        $calculateRunTime=$cronHelper->CalculateRunTime($source_event,$destination_event);

                        //call aditional cron for full inventory sync based on selected frequency
                        if ($source_event && $source_event->event_id == 'GET_INVENTORY') {
                            $respFullInv = $cronHelper->HandleFullEnventory($user->platform_workflow_rule_id, $user->user_integration_id);
                            // \Storage::disk('local')->append('testCrone.txt', 'resp : ' . json_encode($respFullInv));
                            if (isset($respFullInv['status_code']) && ($respFullInv['status_code'] == 1)) {
                                $SynTime = $respFullInv['SynTime'];
                                $nextSynTime = $respFullInv['nextSynTime'];

                                if ($respFullInv['frequency'] == "Twice") {
                                    // \Storage::disk('local')->append('testCrone.txt', 'Called Full Inventory Sync fron get Invent yes from twice');
                                    $schedule->command('command:workflow_get_data', [$user->user_workflow_rule_id, 'FULLINVENTORY'])->dailyAt($SynTime)
                                        ->name($run_mode . $user->user_workflow_rule_id . $user->source_event_id . 'cronforFullInvtwiceDailyFirst' . $user->user_workflow_rule_id)->withoutOverlapping(10)->runInBackground()->onOneServer();

                                    $schedule->command('command:workflow_get_data', [$user->user_workflow_rule_id, 'FULLINVENTORY'])->dailyAt($nextSynTime)
                                        ->name($run_mode . $user->user_workflow_rule_id . $user->source_event_id . 'cronforFullInvtwiceDailyNext' . $user->user_workflow_rule_id)->withoutOverlapping(10)->runInBackground()->onOneServer();
                                } else {
                                    // \Storage::disk('local')->append('testCrone.txt', 'Called Full Inventory Sync fron get Invent yes from once');
                                    $schedule->command('command:workflow_get_data', [$user->user_workflow_rule_id, 'FULLINVENTORY'])->dailyAt($SynTime)->name($run_mode . $user->user_workflow_rule_id . $user->source_event_id . 'cronforFullInvdaily' . $user->user_workflow_rule_id)->withoutOverlapping(10)->runInBackground()->onOneServer();
                                }
                            }
                        }
                        //end


                        if ($source_event && $calculateRunTime['sourceRunTime'] > 0) {

                            $additionalParam = $user->user_workflow_rule_id."_".$user->source_event_id.'_'.$source_event->event_id.'_'.$calculateRunTime['sourceRunTime'];

                            $schedule->command('command:workflow_get_data', [$user->user_workflow_rule_id, ''])
                                ->cron('*/' . $calculateRunTime['sourceRunTime'] . ' * * * *')
                                ->name($run_mode . $user->user_workflow_rule_id . $user->source_event_id . 'CronWorkflowGetData' . $user->user_workflow_rule_id)
                                ->withoutOverlapping(5)
                                //addition added
                                // ->before(function () {
                                //     $this->executionStartTime = microtime(true);
                                // })
                                // ->after(function () use ($additionalParam) {
                                //     $executionEndTime = microtime(true);
                                //     $executionTime = ($executionEndTime - $this->executionStartTime) / 1000000;
                                //     \Storage::disk('local')->append('cron_time_logger.txt', 'workflow_get_data cron complete...Run time (in sec) :'.$executionTime.' current dt :' .date('Y-m-d H:i').' workflow_event_event_name_sourceRunTime : '.$additionalParam);
                                // })
                                //end addition logger
                                ->runInBackground()->onOneServer();
                        }
                        if ($destination_event && $calculateRunTime['destinationRunTime'] > 0) {

                            $additionalParam = $user->user_workflow_rule_id."_".$user->destination_event_id.'_'.$destination_event->event_id.'_'.$calculateRunTime['destinationRunTime'];
                            $schedule->command('command:workflow_mutate_data', [$user->user_workflow_rule_id])
                                ->cron('*/' . $calculateRunTime['destinationRunTime'] . ' * * * *')
                                ->name($run_mode . $user->user_workflow_rule_id . $user->destination_event_id . 'CronWorkflowMutationData' . $user->user_workflow_rule_id)
                                ->withoutOverlapping(5)
                                //addition added
                                // ->before(function () {
                                //     $this->executionStartTime = microtime(true);
                                // })
                                // ->after(function () use ($additionalParam) {
                                //     $executionEndTime = microtime(true);
                                //     $executionTime = ($executionEndTime - $this->executionStartTime) / 1000000;
                                //     \Storage::disk('local')->append('cron_time_logger.txt', 'workflow_mutate_data cron complete...Run time (in sec) :'.$executionTime.' current dt :' .date('Y-m-d H:i').' workflow_event_event_name_destinationRunTime : '.$additionalParam);
                                // })
                                //end addition logger
                                ->runInBackground()->onOneServer();
                        }


                    }
                }

                if (count($user_arr) == $record_limit) {
                    $flag = true;
                }
                $page++;
            } while ($flag);

            //update in cache for every call
            $dataFromCache = $cronHelper->getDataFromCache('kernal_uwf_limit');
            if ($dataFromCache) {

                //get countUpdateCall from cache
                if (isset($dataFromCache->count)) {
                    $countUpdateCall = $dataFromCache->count;
                }
                //if count call is = 10 then update db
                if ($countUpdateCall == $db_update_limit) {
                    KernelUWFLimit::where('id' , $uwf_limit_data_id)->update(['updated_at'=>date("Y-m-d H:i:s")]);
                    $countUpdateCall = 0;
                }

                //update current run time
                $updateArr = ['id' => $uwf_limit_data_id, 'max_limit' => $record_limit, 'updated_at' => date("Y-m-d H:i:s"), 'count' => ($countUpdateCall + 1)];
                $cronHelper->setDataInCache('kernal_uwf_limit', $updateArr);

            }

        } else {
            $uwf_limit_data_id = KernelUWFLimit::insertGetId(['url' => 0, 'type' => 'WORKFLOW', 'max_limit' => $record_limit]);
        }


        //call refresh token
        $dynCronName = "RefreshToken" . rand(9999999, 10000000);
        $schedule->command('comman:refresh_token')->cron('*/5 * * * *')->name($dynCronName)->withoutOverlapping(10)->onOneServer();

        //data retention policy
        // $schedule->command('command:data_retention_bot')->twiceDaily(5, 7)->onOneServer();

        //Send failed Sync record notification email to user
        $schedule->command('command:NotificationEmails')->daily()->onOneServer();

        //run clear cache
        $schedule->command('command:restart_failed_crons')->hourlyAt(23)->onOneServer();

        //call wayfair allow inventory update
        // $schedule->command('command:allow_wayfair_inventory_update')->runInBackground()->everyFourMinutes()->onOneServer();

        //delete duplicate product
        // $schedule->command('command:delete_duplicate_product')->withoutOverlapping(2)->runInBackground()->everyTwoMinutes()->onOneServer();


        //$schedule->command('command:process_list_count')->cron('*/7 * * * *')->onOneServer(); // send heavy process list count notification


        //dammy cron remove this latter
        $schedule->command('command:storesnapshotdata')->cron('*/7 * * * *')->withoutOverlapping()->onOneServer();
        $schedule->command('command:syncbulkinventory')->cron('*/8 * * * *')->withoutOverlapping()->onOneServer(); // 8

        /**
         * Temp
         */
        $schedule->command('command:TiktokSOManual')->cron('*/3 * * * *')->withoutOverlapping()->onOneServer();
        $schedule->command('command:TiktokSODeliveredManual')->cron('*/3 * * * *')->withoutOverlapping()->onOneServer();

        $schedule->command('command:test-on-server', [date('Y-m-d H:i:s')])->cron('*/2 * * * *')->name('testOnServer')->withoutOverlapping(5)->runInBackground()->onOneServer();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
