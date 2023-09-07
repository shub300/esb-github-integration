<?php

namespace App\Http\Controllers;

use App\Helper\MainModel;
use Illuminate\Http\Request;
use DB;
use Illuminate\Support\Facades\Auth;
use App\Helper\MappingHelper;
use App\Helper\ConnectionHelper;
use App\Helper\Api\MappingHelper as MappingHelper2;
use App\Helper\MyappSnippet;
use App\Helper\MappingRules;
use App\Helper\WorkflowSnippet;
use App\Http\Controllers\PanelControllers\ModuleAccessController;
use App\Models\Platform;
use App\Helper\Cache\CacheDecoder;
//for testing
use App\Helper\FieldMappingHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use App\Models\PlatformAccount;
use App\Models\UserIntegration;
use App\Models\PlatformDataMapping;
use App\Models\History;
use App\Models\PlatformObject;
use Illuminate\Support\Facades\Config;

use DateTime;
use DateInterval;

use Illuminate\Support\Facades\Storage;

class MyAppsController extends Controller
{
    public $objMyappSnip, $objMapHelp, $objMappingRules, $objWorkflowSnippet, $mobj, $workflow, $ConnectionHelper, $map, $cache;
    
    public function __construct()
    {
        $this->objMapHelp = new MappingHelper();
        $this->objMyappSnip = new MyappSnippet();
        $this->objMappingRules = new MappingRules();
        $this->objWorkflowSnippet = new WorkflowSnippet();
        $this->mobj = new MainModel();
        $this->workflow = new \App\Http\Controllers\WorkflowController;
        $this->ConnectionHelper = new ConnectionHelper();
        $this->map = new MappingHelper2();
        $this->cache = new CacheDecoder();

        //for testing remove it latter
        $this->fm_helper = new FieldMappingHelper();
    }
    
    //list of users integrated Apps
    public function getMyApps(Request $request)
    {

        $role = \Session::get('user_data')->role;
        $userId = \Session::get('user_data')->id; // in case user_staff logged-in, session consists parent_id (main user) in place of user_id.

        if ($role != "master_staff") {

            $view = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'integrations', 'view');
            if ($view == 0) {
                return redirect()->route('home.integrations');
            }

            $modify = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'integrations', 'modify');
            $searchVal = $condition = isset($request['term']) ? $request['term'] : "";
            $integration = $this->objMyappSnip->getMyApps($userId, $searchVal);

            if (isset($request['term'])) {
                return response()->json([
                    "items" => '',
                    "pagination" => ["more" => true],
                    'integration' => $integration,
                    'modify' => $modify,
                    'searchVal' => $searchVal
                ]);
            }
            return view("myapp.my_apps_list", compact('integration', 'modify'));

        } else {

            return redirect('launchpad');
        }
    }
    

    public function checkIntegStatus(Request $request)
    {
        $userIntegId = $request['user_integ_id'];
        return DB::table('user_integrations')->where('id', $userIntegId)->where('workflow_status', 'active')->count();
    }
    public function integrationFlow($userIntegId)
    {
        $role = \Session::get('user_data')->role;
        $userId = \Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id.
        if ($role != "master_staff") {
            $view = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'integrations', 'view');
            if ($view == 0) {
                return redirect()->route('home.integrations');
            }
            //check activation status
            $res =  DB::table('user_integrations as ui')->join('platform_integrations as pi', 'pi.id', 'ui.platform_integration_id')
                ->where('ui.id', $userIntegId)->where('ui.user_id', $userId)->select('ui.workflow_status', 'pi.source_platform_id', 'pi.destination_platform_id')->first();

            $sourcePltId = "";
            $destPltId = "";
            if ($res == true) {

                $sourcePltId = $res->source_platform_id;
                $destPltId = $res->destination_platform_id;;

                if ($res->workflow_status != "active") {
                    return redirect('/myapps');
                }
            } else {
                return redirect('/myapps');
            }
            $integration_events = DB::table('user_integrations AS usrIntg')
                ->join('platform_workflow_rule AS pfWfRl', 'pfWfRl.platform_integration_id', '=', 'usrIntg.platform_integration_id')
                ->join('user_workflow_rule as uwfr', 'pfWfRl.id', 'uwfr.platform_workflow_rule_id')
                ->join('platform_events AS pfEvtSc', 'pfEvtSc.id', '=', 'pfWfRl.source_event_id')
                ->join('platform_events AS pfEvtDc', 'pfEvtDc.id', '=', 'pfWfRl.destination_event_id')
                //additionl join added for get platform id wo special conditions
                ->join('platform_lookup as pl1','pl1.id','pfEvtSc.platform_id')
                ->join('platform_lookup as pl2','pl2.id','pfEvtDc.platform_id')
                ->select('pfEvtSc.event_id AS event', 'pfEvtDc.event_name as type', 'pfEvtSc.linked_table', 'uwfr.id as uwfId', 'pfEvtSc.platform_id as sourcePlt', 'pfEvtDc.platform_id as destPlt', 'pfEvtDc.event_description as event_description','pl1.platform_id as sourcePltId','pl2.platform_id as destPltId')
                ->groupBy('pfEvtSc.event_description')
                // ->groupBy('pfEvtSc.event_id')
                ->where(['usrIntg.id' => $userIntegId, 'uwfr.user_integration_id' => $userIntegId, 'pfWfRl.status' => 1])
                ->whereNotIn('pfEvtSc.event_id', \Config::get('apisettings.HideEventsFromLog'))->get();

            $module_rights = [];
            // $view_logs = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'logs', 'view');
            $view_integrations = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'integrations', 'view');
            $modify_integrations = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'integrations', 'modify');
            $module_rights['view_integrations'] = $view_integrations;
            //set view log right 1 if view integration access on
            $module_rights['view_logs'] = ($view_integrations == 1) ? 1 : 0;
            $module_rights['modify_integrations'] = $modify_integrations;

            return view("myapp.integration_flow", ['userIntegId' => $userIntegId, 'integration_events' => $integration_events, 'module_rights' => $module_rights, 'sourcePltId' => $sourcePltId, 'destPltId' => $destPltId]);
        } else {
            return redirect('launchpad');
        }
    }
    //Available flow list in selected integration inside integration flow
    public function GetFlowList(Request $request, $userIntegId)
    {
        //get platform Integration ID
        $userIntegData = DB::table('user_integrations')->find($userIntegId);
        $platformIntegId  = $userIntegData->platform_integration_id;
        $userId = \Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id.
        $result = $this->objMyappSnip->GetFlowList($userId, $userIntegId, $platformIntegId);
        $modify = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'integrations', 'modify');
        return response()->json(['data' => $result, 'modify' => $modify]);
    }
    //Connection data in integration flow
    public function getConnections(Request $request, $userIntegId)
    {
        $contentServerPath = env('CONTENT_SERVER_PATH');
        $currentTimezoneFromStorage = $request->get('currentTimezone');


        $view = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'integrations', 'view');
        if ($view == 0) {
            return redirect()->route('home.integrations');
        }

        $result = [];
        $UserInteg =  DB::table('user_integrations')->find($userIntegId);
        $selected_sc_account_id = $UserInteg->selected_sc_account_id;
        $selected_dc_account_id = $UserInteg->selected_dc_account_id;
        $platform_integration_id = $UserInteg->platform_integration_id;
        $userId = \Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id.

        //get timezone
        $timezone = $this->objMyappSnip->getTimezone($userId);
        if (!$timezone) {
            $timezone = $currentTimezoneFromStorage;
            //if storage has also timezone not found then set it 0 for any failure condition
            if (!$timezone) {
                $timezone = "+00:00";
            }
        }


        $gmtOffset = "+00:00";

        $platform_integration_data = DB::table('platform_integrations')
            ->join('platform_lookup as pl1', 'platform_integrations.source_platform_id', 'pl1.id')
            ->join('platform_lookup as pl2', 'platform_integrations.destination_platform_id', 'pl2.id')
            ->where('platform_integrations.id', $platform_integration_id)
            ->select('pl1.platform_id as source_platform_id', 'pl2.platform_id as destination_platform_id', 'pl1.platform_image as source_platform_img', 'pl2.platform_image as destination_platform_img','pl1.allow_reauth as s_allow_reauth','pl1.reauth_in_days as s_reauth_in_days','pl2.allow_reauth as d_allow_reauth','pl2.reauth_in_days as d_reauth_in_days')->first();

        $source_platform_img = "";
        $destination_platform_img = "";
        if ($platform_integration_data) {
            $sourcePltImg  = $contentServerPath . $platform_integration_data->source_platform_img;
            $destPltImg = $contentServerPath . $platform_integration_data->destination_platform_img;
        }

        $selected_sc_account_data = DB::table('platform_accounts')
            ->select('id', 'platform_id', 'account_name', 'status', 'env_type', 'api_domain', DB::raw("convert_tz(updated_at,'" . $gmtOffset . "','" . $timezone . "') AS updated_at"),DB::raw("null AS enable_reauth_button"),'last_refreshed_at')
            ->where(['id' => $selected_sc_account_id])->first();
    
        if (!$selected_sc_account_data) {
            $result[] = ['id' => 1, 'platform_id' => $platform_integration_data->source_platform_id, 'account_name' => $platform_integration_data->source_platform_id, 'status' => 0, 'env_type' => '', 'api_domain' => '', 'updated_at' => ''];
        } else {
            $result[] = $selected_sc_account_data;
            $result[0]->updated_at = date_format(date_create($selected_sc_account_data->updated_at), 'd M Y H:i');
            
            //if source platform exist in showReauthButtonInLog
            if( $platform_integration_data->s_allow_reauth == 1 ){
                
                //check last_refreshed_at 
                $last_refreshed_at  = $selected_sc_account_data->last_refreshed_at;
                $reauth_in_days = $platform_integration_data->s_reauth_in_days; //365

                //start before 30 days for now
                $start_reauth_check_before_days = 30;
                $currentDateTime = new DateTime();

                $next_reauth_date = new DateTime($last_refreshed_at);
                $next_reauth_date->add(new DateInterval("P" . ($reauth_in_days - $start_reauth_check_before_days) . "D"));

                //calculate expire in days
                $expired_in_day = new DateTime($last_refreshed_at);
                $expired_in_day->add(new DateInterval("P" . $reauth_in_days. "D"));

                $day_diff = $expired_in_day->diff($next_reauth_date)->format('%a');

                if ( $next_reauth_date < $currentDateTime ) {
                    $result[0]->enable_reauth_button = 'YES';
                    $result[0]->alertMsg = "The refresh token for ".$selected_sc_account_data->account_name." account is going to invalided in ".$day_diff." days, Please perform re-authentication in order to refresh the token.";
                } 
            }
        }

        $selected_dc_account_data = DB::table('platform_accounts')
            ->select('id', 'platform_id', 'account_name', 'status', 'env_type', 'api_domain', DB::raw("convert_tz(updated_at,'" . $gmtOffset . "','" . $timezone . "') AS updated_at"),DB::raw("null AS enable_reauth_button"),'last_refreshed_at')
            ->where(['id' => $selected_dc_account_id])->first();

        if (!$selected_dc_account_data) {
            $result[] = ['id' => 1, 'platform_id' => $platform_integration_data->destination_platform_id, 'account_name' => $platform_integration_data->destination_platform_id, 'status' => 0, 'status' => 0, 'env_type' => '', 'api_domain' => '', 'updated_at' => ''];
        } else {
            $result[] = $selected_dc_account_data;
            $result[1]->updated_at = date_format(date_create($selected_dc_account_data->updated_at), 'd M Y H:i');
            //if dest platform exist in showReauthButtonInLog
            if( $platform_integration_data->d_allow_reauth == 1 ){
                
                //check last_refreshed_at 
                $last_refreshed_at  = $selected_dc_account_data->last_refreshed_at;
                $reauth_in_days = $platform_integration_data->d_reauth_in_days; //365

                //start before 30 days for now
                $start_reauth_check_before_days = 30;
                $currentDateTime = new DateTime();

                $next_reauth_date = new DateTime($last_refreshed_at);
                $next_reauth_date->add(new DateInterval("P" . ($reauth_in_days - $start_reauth_check_before_days) . "D"));

                //calculate expire in days
                $expired_in_day = new DateTime($last_refreshed_at);
                $expired_in_day->add(new DateInterval("P" . $reauth_in_days. "D"));

                $day_diff = $expired_in_day->diff($next_reauth_date)->format('%a');

                if ($next_reauth_date < $currentDateTime) {
                    $result[1]->enable_reauth_button = 'YES';
                    $result[1]->alertMsg = "The refresh token for ".$selected_dc_account_data->account_name." account is going to invalided in ".$day_diff." days, Please perform re-authentication in order to refresh the token.";
                } 

            }
        }
    
        $modify = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'integrations', 'modify');
        return response()->json(['data' => $result, 'modify' => $modify, 'sourcePltImg' => $sourcePltImg, 'destPltImg' => $destPltImg]);
    }
    // Function to check user workflow status whether it is Completed or Pending
    public function checkUserWorkflowStatus(Request $request)
    {
        $data = [];
        try {
            $user_id = \Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id.
            $user_intg_id = $request->get('user_intg_id');

            $integrations = DB::table('user_workflow_rule AS usrWfRl')
                ->select('usrWfRl.status', 'usrWfRl.is_all_data_fetched', 'pfSc.platform_name AS source_platform_name', 'pfDc.platform_name AS dest_platform_name')
                ->join('platform_workflow_rule AS pfWfRl', 'pfWfRl.id', '=', 'usrWfRl.platform_workflow_rule_id')
                ->join('platform_events AS pfEvtSc', 'pfEvtSc.id', '=', 'pfWfRl.source_event_id')
                ->join('platform_events AS pfEvtDc', 'pfEvtDc.id', '=', 'pfWfRl.destination_event_id')
                ->join('platform_lookup AS pfSc', 'pfSc.id', '=', 'pfEvtSc.platform_id')
                ->join('platform_lookup AS pfDc', 'pfDc.id', '=', 'pfEvtDc.platform_id')
                //'usrWfRl.user_id' => $user_id,
                ->where(['usrWfRl.user_integration_id' => $user_intg_id, 'usrWfRl.status' => 1])
                ->where(function ($query1) {
                    $query1->where('usrWfRl.is_all_data_fetched', 'pending')
                        ->orWhere('usrWfRl.is_all_data_fetched', 'inprocess');
                })
                ->get();

            $source_platform_name = null;
            $dest_platform_name = null;
            if ($integrations->count()) {
                foreach ($integrations as $intg) {
                    $source_platform_name = $intg->source_platform_name;
                    $dest_platform_name = $intg->dest_platform_name;
                }
                $data['status_code'] = 1;
                $data['status_text'] = $source_platform_name . " and " . $dest_platform_name;
            } else {
                $data['status_code'] = 0;
            }
            return json_encode($data);
        } catch (\Exception $e) {
            $data['status_code'] = 0;
            $data['status_text'] = $e->getMessage();
            return json_encode($data);
        }
    }



    //get linked status column
    public function getLinkedLogStatusByEvent($eventId, $sourcePlatformId = null, $destPlatformId = null)
    {
        $linkedData = DB::table('platform_events')->where('event_id', $eventId)->select('linked_status_column')
            ->where('platform_id', $sourcePlatformId)
            ->first();
        if ($linkedData) {
            $statusVal = $linkedData->linked_status_column;
        } else {
            $statusVal = null;
        }
        return $statusVal;
    }
    //get linked table on events
    public function getLinkedTableByEvent($eventId, $sourcePlatformId = null, $destPlatformId = null)
    {
        $linkedData = DB::table('platform_events')->where('event_id', $eventId)->select('linked_table')
            ->where('platform_id', $sourcePlatformId)
            ->first();
        if ($linkedData) {
            $linkTab = $linkedData->linked_table;
        } else {
            $linkTab = null;
        }
        return $linkTab;
    }

    public function resyncPlatformData(Request $request)
    {
        $data = [];
        try {

            $user_id = \Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id.

            //if resync all then call resync for all
            $sPlatformId = $request->get('sourcePlatformId');
            $dPlatformId = $request->get('destPlatformId');

            if ($request->resync_all == "Yes") {
                $userIntegId = $request->userIntegId;
                $logType = $request->logType;
                $logTypeName = $request->logTypeName;

                if ($logType) {

                    $linkedTable =  $this->getLinkedTableByEvent($logType, $sPlatformId, $dPlatformId);
                    $linkedStatus = $this->getLinkedLogStatusByEvent($logType, $sPlatformId, $dPlatformId);

                    if ($linkedTable) {
                        if ($linkedTable == "platform_product") {
                            if (str_contains($logType, 'PRODUCT')) {
                                $linkedStatus = ($linkedStatus) ? $linkedStatus : 'product_sync_status';
                            } else {
                                $linkedStatus = ($linkedStatus) ? $linkedStatus : 'inventory_sync_status';
                            }
                        } else {
                            $linkedStatus = ($linkedStatus) ? $linkedStatus : 'sync_status';
                        }
                    } else {
                        //return back with error msg please update events with linked table data
                        $data['status_code'] = 0;
                        $data['status_text'] = "Please update Event & their linked table.";
                        return json_encode($data);
                    }

                    $fields = array($linkedStatus => 'Ready');

                    //update status
                    $updateStatus = $this->mobj->makeUpdate($linkedTable, $fields, ['user_integration_id' => $userIntegId, $linkedStatus => 'Failed', 'platform_id' => $sPlatformId]);

                    if ($updateStatus) {
                        $data['status_code'] = 1;
                        $data['status_text'] = "All Failed Records has been added in queue for Resync.";
                    } else {
                        $data['status_code'] = 0;
                        $data['status_text'] = "No failed record found for Resync.";
                    }

                    //if log type name get /flow name
                    if(isset($logTypeName)) {
                        $action = 'Resync Trigger';
                        $action_by = Auth::user()->id;
                        $log_data = [];
                        $log_data['trigger_type'] = 'Resync Failed';
                        $log_data['description'] = $logTypeName;
                        History::insert([ 'action'=>$action,'action_by'=>$action_by,'user_integration_id'=>$userIntegId,'old_data'=>json_encode($log_data),'new_data' => NULL,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=> date('Y-m-d H:i:s')]);
                    }

                }
            } else if ($request->resync_all == "Ignore") {
                //update status as failed
                $record_id = $request->get('id');
                $log_user_id = $request->get('user_id');
                $user_intg_id = $request->get('user_integration_id');
                $log_event  = $request->get('log_event');

                if ($log_event) {
                    $linkedTable =  $this->getLinkedTableByEvent($log_event, $sPlatformId, $dPlatformId);
                    $linkedColumn = $this->getLinkedLogStatusByEvent($log_event, $sPlatformId, $dPlatformId);

                    if ($linkedTable) {

                        if ($linkedColumn) {
                            $updateColName = $linkedColumn;
                        } else {
                            if ($linkedTable == "platform_product") {
                                $updateColName = ($log_event == "GET_PRODUCT" || $log_event == "MUTATE_PRODUCT") ? 'product_sync_status' : 'inventory_sync_status';
                            } else {
                                $updateColName = 'sync_status';
                            }
                        }


                        //update status
                        DB::table($linkedTable)->where('id', $record_id)->where('user_integration_id', $user_intg_id)
                            ->update([$updateColName => $request->resync_all]);

                        $data['status_code'] = 1;
                        $data['status_text'] = "Records has been updated successfully.";
                    } else {
                        $data['status_code'] = 0;
                        $data['status_text'] = 'Linked table not updated for this event';
                    }
                } else {
                    $data['status_code'] = 0;
                    $data['status_text'] = 'Something went wrong event not found';
                }
            } else if ($request->resync_all == "Failed") {
                //update status as failed
                $record_id = $request->get('id');
                $log_user_id = $request->get('user_id');
                $user_intg_id = $request->get('user_integration_id');
                $log_event  = $request->get('log_event');

                if ($log_event) {
                    $linkedTable =  $this->getLinkedTableByEvent($log_event, $sPlatformId, $dPlatformId);
                    $linkedColumn = $this->getLinkedLogStatusByEvent($log_event, $sPlatformId, $dPlatformId);

                    if ($linkedTable) {

                        if ($linkedColumn) {
                            $updateColName = $linkedColumn;
                        } else {
                            if ($linkedTable == "platform_product") {
                                $updateColName = ($log_event == "GET_PRODUCT" || $log_event == "MUTATE_PRODUCT") ? 'product_sync_status' : 'inventory_sync_status';
                            } else {
                                $updateColName = 'sync_status';
                            }
                        }
                        //update status
                        DB::table($linkedTable)->where('id', $record_id)->where('user_integration_id', $user_intg_id)
                            ->update([$updateColName => $request->resync_all]);

                        $data['status_code'] = 1;
                        $data['status_text'] = "Records has been updated successfully.";
                    } else {
                        $data['status_code'] = 0;
                        $data['status_text'] = 'Linked table not updated for this event';
                    }
                } else {
                    $data['status_code'] = 0;
                    $data['status_text'] = 'Something went wrong event not found';
                }
            }

            //resync single record directly
            else {
                //call resync for single record
                $record_id = $request->get('id');
                $log_user_id = $request->get('user_id');
                $user_intg_id = $request->get('user_integration_id');
                $source_platform_id = $request->get('source_platform_id');
                $dest_platform_id = $request->get('dest_platform_id');
                $user_wf_rule_id = $request->get('user_wf_rule_id');
                $pf_wf_rule_id = $request->get('pf_wf_rule_id');
                $is_initial_sync = 0;
                $getFlowEvent = DB::table('platform_workflow_rule AS pfWfRl')
                    ->join('platform_events AS pfEvtDc', 'pfEvtDc.id', '=', 'pfWfRl.destination_event_id')
                    ->select('pfEvtDc.event_id')
                    ->where(['pfWfRl.id' => $pf_wf_rule_id])
                    ->first();
                if ($getFlowEvent) {
                    $destinationEventExtract = $this->objWorkflowSnippet->ExtractEventType($getFlowEvent->event_id);
                } else {
                    $data['status_code'] = 0;
                    $data['status_text'] = "Platform event details not found.";
                    return json_encode($data);
                }

                // dd($destinationEventExtract['method'], $destinationEventExtract['primary_event'], $dest_platform_id, $user_id, $user_intg_id, $is_initial_sync, $user_wf_rule_id, $source_platform_id, $pf_wf_rule_id, $record_id);

                $response = $this->workflow->executeEvent($destinationEventExtract['method'], $destinationEventExtract['primary_event'], $dest_platform_id, $user_id, $user_intg_id, $is_initial_sync, $user_wf_rule_id, $source_platform_id, $pf_wf_rule_id, $record_id);

                if ($response && !is_bool($response)) {
                    $data['status_code'] = 0;
                    $data['status_text'] = $response;
                } else {
                    $data['status_code'] = 1;
                    $data['status_text'] = "Records has been synced successfully.";
                }
                //end
            }

            return json_encode($data);
        } catch (\Exception $e) {
            $data['status_code'] = 0;
            $data['status_text'] = $e->getMessage();
            return json_encode($data);
        }
    }


    //Log data in integration flow
    public function getAuditLogList(Request $request)
    {

        if ($request->get('organization_id')) { // for master audit logs
            $user_id = $request->get('user_id');
            $org_id = $request->get('organization_id');
            $user_role  = $request->get('user_role');
            //set staff
            $staff_id = $request->get('user_id');
        } else {
            $user_id = Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id.
            $org_id = Auth::user()->organization_id;
            $user_role = Auth::user()->role;

            //set staff id
            $staff_id = Session::get('user_data')->id;
            if($user_role=="user_staff") {
                $staff_id =  Session::get('user_data')->staff_id;
            }

        }


        $user_intg_id = $request->get('user_intg_id');
        $FilterByDate = $request->get('FilterByDate');
        $from_date = $request->get('from_date');
        $to_date = $request->get('to_date');
        $event = $request->get('event_name');
        $status = $request->get('status');
        $userWfrId = $request->get('uwfrid');

        $sourcePlatformId = $request->get('sourcePlatformId');
        $destPlatformId = $request->get('destPlatformId');

        $sourcePlatformName = $this->getPlatformIdByPrimaryId($sourcePlatformId);
        $destPlatformName = $this->getPlatformIdByPrimaryId($destPlatformId);

        $currentTimezoneFromStorage = $request->get('currentTimezone');



        //get timezone
        $timezone = $this->objMyappSnip->getTimezone($user_id);
        if (!$timezone) {
            $timezone = $currentTimezoneFromStorage;
            //if storage has also timezone not found then set it 0 for any failure condition
            if (!$timezone) {
                $timezone = "+00:00";
            }
        }

        $gmtOffset = "+00:00";

        $productEventArr = [];
        $custEventArr = [];
        $orderEventArr = [];
        $invoiceEventArr = [];
        $potEventArr = [];
        $posEventArr = [];
        $OrdRefEventArr = [];
        $ptEventArr = [];

        $arr_prod_rowQuery = "";
        $arr_ord_rowQuery = "";
        $arr_cust_rowQuery = "";
        $arr_invoice_rowQuery = "";
        $arr_pot_rowQuery = "";
        $arr_pos_rowQuery = "";
        $arr_ord_ref_rowQuery = "";
        $arr_tkt_ref_rowQuery = "";

        // DB::enableQueryLog();
        $IntegEvents = $request->get('arrayIntegEvents');
        if ($IntegEvents) {
            $arrayIntegEvents = json_decode($IntegEvents);
            foreach ($arrayIntegEvents as $integEven) {
                $linkedColumn = $this->getLinkedLogStatusByEvent($integEven->event, $sourcePlatformId, $destPlatformId);
                // Log::info( "Linked Table: ".$integEven->linked_table );
                if ($integEven->linked_table == "platform_product") {
                    array_push($productEventArr, $integEven->event);
                    if ($linkedColumn) {
                        $arr_prod_rowQuery .= " WHEN pfEvtSc.event_id='" . $integEven->event . "' THEN inv." . $linkedColumn;
                    }
                } else if ($integEven->linked_table == "platform_customer") {
                    array_push($custEventArr, $integEven->event);
                    if ($linkedColumn) {
                        $arr_cust_rowQuery .= " WHEN pfEvtSc.event_id='" . $integEven->event . "' THEN custr." . $linkedColumn;
                    }
                } else if ($integEven->linked_table == "platform_invoice") {
                    array_push($invoiceEventArr, $integEven->event);
                    if ($linkedColumn) {
                        $arr_invoice_rowQuery .= " WHEN pfEvtSc.event_id='" . $integEven->event . "' THEN invc." . $linkedColumn;
                    }
                } else if ($integEven->linked_table == "platform_order_transactions") {
                    array_push($potEventArr, $integEven->event);
                    if ($linkedColumn) {
                        $arr_pot_rowQuery .= " WHEN pfEvtSc.event_id='" . $integEven->event . "' THEN pot." . $linkedColumn;
                    }
                } else if ($integEven->linked_table == "platform_order_shipments") {
                    array_push($posEventArr, $integEven->event);
                    if ($linkedColumn) {
                        $arr_pos_rowQuery .= " WHEN pfEvtSc.event_id='" . $integEven->event . "' THEN pos." . $linkedColumn;
                    }
                } else if ($integEven->linked_table == "platform_order_refunds") {
                    array_push($OrdRefEventArr, $integEven->event);
                    if ($linkedColumn) {
                        $arr_ord_ref_rowQuery .= " WHEN pfEvtSc.event_id='" . $integEven->event . "' THEN ordRef." . $linkedColumn;
                    }
                } else if ($integEven->linked_table == "platform_tickets") {
                    array_push($ptEventArr, $integEven->event);

                    if ($linkedColumn) {
                        $arr_tkt_ref_rowQuery .= " WHEN pfEvtSc.event_id='" . $integEven->event . "' THEN pt." . $linkedColumn;
                    }
                } else {
                    array_push($orderEventArr, $integEven->event);
                    if ($linkedColumn) {
                        $arr_ord_rowQuery .= " WHEN pfEvtSc.event_id='" . $integEven->event . "' THEN ord." . $linkedColumn;
                    }
                }
            }
        }

        $columns = [
            'info', 'source_platform_name', 'dest_platform_name', 'event', 'last_run', 'destination_reference'
        ];


        //get linked query table by event & dynamic updated at column by events
        $queryTableName = null;
        $dyn_updated_at = null;
        $dyn_synced_at = null;

        if (isset($event)) {

            $queryTableName =  $this->getLinkedTableByEvent($event, $sourcePlatformId, $destPlatformId);

            //dynamic updated at column as last run
            if ($queryTableName == "platform_product") {
                if ($event == 'GET_INVENTORYTRAIL') {
                    $dyn_updated_at = "inv.updated_at";//"prod_inv_trail.updated_at";
                } else {
                    $dyn_updated_at = "inv.updated_at";
                }
            } else if ($queryTableName == "platform_customer") {
                $dyn_updated_at = "custr.updated_at";
            } else if ($queryTableName == "platform_invoice") {
                $dyn_updated_at = "invc.updated_at";
            } else if ($queryTableName == "platform_order_transactions") {
                $dyn_updated_at = "pot.updated_at";
            } else if ($queryTableName == "platform_order_shipments") {
                $dyn_updated_at = "pos.created_at";
            } else if ($queryTableName == "platform_order_refunds") {
                $dyn_updated_at = "ordRef.created_at";
            } else if ($queryTableName == "platform_tickets") {
                $dyn_updated_at = "pt.updated_at";
            } else {
                $dyn_updated_at = "ord.updated_at";
                // if ($event == 'GET_SHIPMENT') {
                //     $dyn_updated_at = "ord.updated_at";
                // } else {
                //     $dyn_updated_at = "ord.order_updated_at";
                // }
            }

            //dynamic sync_log updated at as synced_at
            $dyn_synced_at = "sync_log.updated_at";
        }

        //manage info filed based on identity mapping source platform side
        $platform_object_id = $this->ConnectionHelper->getObjectId('product_identity');
        $mapped_field = $this->map->getMappedField($user_intg_id, $platform_object_id);


        //condition for unique identity mapping
        $sourceSide = 'inv.sku';
        $destSide = 'inv.sku';
        $source_platform_id = "";
        if ($mapped_field) {
            $sourceSide = "inv." . $mapped_field['source_row_data'];
            $destSide = "inv." . $mapped_field['destination_row_data'];
            $source_platform_id = $mapped_field['source_platform_id'];
        }

        $combinaton = app('App\Utility\PlatformConfig')->platformCombination($sourcePlatformName, $destPlatformName);
        if($combinaton){
            //to display specific field for source reference
            if (isset(\Config::get('logfieldconfiguration.displaySpecificFieldByCase')[$combinaton]['sourceRef'][$event])) {
                $source_ref_field = \Config::get('logfieldconfiguration.displaySpecificFieldByCase')[$combinaton]['sourceRef'][$event];
            }

            //to display specific field for destination reference
            if (isset(\Config::get('logfieldconfiguration.displaySpecificFieldByCase')[$combinaton]['destinationRef'][$event])) {
                $dest_ref_field = \Config::get('logfieldconfiguration.displaySpecificFieldByCase')[$combinaton]['destinationRef'][$event];
            }
        }

        if ($queryTableName == "platform_product") {
            //$elo_inv
            $elo_table_query = DB::table('platform_product AS inv')
                ->select(
                    'inv.id',
                    'inv.user_id',
                    'inv.user_integration_id',
                    'inv.api_product_id as apiItemId',
                    'inv.platform_id',
                    'inv.product_name',
                    DB::raw("(CASE
                WHEN pfSc.id='" . $source_platform_id . "' THEN " . (isset($source_ref_field) ? 'inv.' . $source_ref_field : $sourceSide) . "
                ELSE " . (isset($dest_ref_field) ? 'inv.' . $dest_ref_field : $destSide) . " END) as info"),
                    // DB::raw("null AS destination_reference"),
                    //'pro_dest.api_product_id as destination_reference',
                    DB::raw((isset($dest_ref_field) ? 'pro_dest.' . $dest_ref_field : 'pro_dest.api_product_id') . ' as destination_reference'),
                    DB::raw("null AS linked_id"),
                    //DB::raw("null AS destination_reference"),
                    'pfEvtDc.event_name as type',
                    'pfEvtSc.event_id AS event',

                    'inv.is_deleted',

                    // ''.$dyn_synced_at.' as synced_at',
                    DB::raw("(CASE
                WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_synced_at . ",'" . $gmtOffset . "','" . $timezone . "')
                ELSE " . $dyn_synced_at . " END) as synced_at"),

                    // ''.$dyn_updated_at.' as last_run',
                    DB::raw("(CASE
                WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_updated_at . ",'" . $gmtOffset . "','" . $timezone . "')
                ELSE " . $dyn_updated_at . " END) as last_run"),


                    DB::raw("null AS record_type"),

                    DB::raw("(CASE
                WHEN pfEvtSc.event_id='' THEN null
                " . $arr_prod_rowQuery . "
                ELSE inv.inventory_sync_status END) as status"),

                    'usrIntg.flow_name',
                    'usrWfRl.id AS user_wf_rule_id',
                    'pfSc.platform_name AS source_platform_name',
                    'pfDc.platform_name AS dest_platform_name',
                    'pfSc.platform_id AS source_platform_id',
                    'pfDc.platform_id AS dest_platform_id',

                    'pfSc.id AS source_platform_pid',
                    'pfDc.id AS dest_platform_pid',

                    'pfWfRl.id AS pf_wf_rule_id'
                )
                ->join('user_integrations AS usrIntg', 'usrIntg.id', '=', 'inv.user_integration_id')
                ->join('platform_workflow_rule AS pfWfRl', 'pfWfRl.platform_integration_id', '=', 'usrIntg.platform_integration_id')
                ->join('platform_events AS pfEvtSc', 'pfEvtSc.id', '=', 'pfWfRl.source_event_id')
                ->join('platform_events AS pfEvtDc', 'pfEvtDc.id', '=', 'pfWfRl.destination_event_id')
                ->join('platform_lookup AS pfSc', 'pfSc.id', '=', 'pfEvtSc.platform_id')
                ->join('platform_lookup AS pfDc', 'pfDc.id', '=', 'pfEvtDc.platform_id')
                ->join('users AS usr', 'usr.id', '=', 'usrIntg.user_id')
                ->join('user_workflow_rule AS usrWfRl', 'usrWfRl.user_integration_id', '=', 'inv.user_integration_id')
                ->leftJoin('platform_product as pro_dest', 'inv.linked_id', 'pro_dest.id'); //new join for destination reference

            if ($event == 'GET_INVENTORYTRAIL') {
                $elo_table_query->leftJoin('platform_inventory_trails as prod_inv_trail', function ($query) use ($user_intg_id, $user_id, $sourcePlatformId, $destPlatformId) {
                    $query->on('prod_inv_trail.platform_product_id', '=', 'inv.id')
                        ->where('prod_inv_trail.platform_id', $sourcePlatformId)
                        ->where('prod_inv_trail.user_integration_id', $user_intg_id)
                        ->where('prod_inv_trail.user_id', $user_id)
                        ->orderBy('prod_inv_trail.updated_at', 'desc');
                });
            }

            //join sync log
            $elo_table_query->leftJoin('sync_logs as sync_log', function ($query) use ($user_id, $userWfrId, $sourcePlatformId, $destPlatformId) {
                $query->on('sync_log.record_id', '=', 'inv.id')
                    ->where('sync_log.user_workflow_rule_id', $userWfrId)
                    ->where('sync_log.user_id', $user_id)
                    ->where('sync_log.source_platform_id', $sourcePlatformId)
                    ->where('sync_log.destination_platform_id', $destPlatformId)
                    ->orderBy('sync_log.updated_at', 'desc');
            });

            $elo_table = $elo_table_query->whereColumn([['inv.platform_id', '=', 'pfEvtSc.platform_id']])
                ->whereColumn([['pfWfRl.id', '=', 'usrWfRl.platform_workflow_rule_id']])
                ->where('inv.platform_id', $sourcePlatformId)
                ->whereIn('pfEvtSc.event_id', $productEventArr)
                ->where(['usr.organization_id' => $org_id, 'inv.user_integration_id' => $user_intg_id, 'inv.is_deleted' => 0]);
        } else if ($queryTableName == "platform_customer") {
            //$elo_custr
            $elo_table_query = DB::table('platform_customer AS custr')
                ->select(
                    'custr.id',
                    'custr.user_id',
                    'custr.user_integration_id',
                    'custr.api_customer_id as apiItemId',
                    'custr.platform_id',
                    'custr.customer_name',
                    DB::raw((isset($source_ref_field) ? 'custr.' . $source_ref_field : 'custr.email') . ' as info'),
                    DB::raw((isset($dest_ref_field) ? 'custr_dest.' . $dest_ref_field : 'custr_dest.email') . ' as destination_reference'),
                    DB::raw("null AS linked_id"),
                    'pfEvtDc.event_name as type',
                    'pfEvtSc.event_id AS event',

                    DB::raw("null AS is_deleted"),

                    // ''.$dyn_synced_at.' as synced_at',
                    DB::raw("(CASE
                WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_synced_at . ",'" . $gmtOffset . "','" . $timezone . "')
                ELSE " . $dyn_synced_at . " END) as synced_at"),

                    // ''.$dyn_updated_at.' as last_run',
                    DB::raw("(CASE
                WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_updated_at . ",'" . $gmtOffset . "','" . $timezone . "')
                ELSE " . $dyn_updated_at . " END) as last_run"),

                    // DB::raw("null AS record_type"),
                    'custr.type AS record_type',

                    DB::raw("(CASE
                WHEN pfEvtSc.event_id=' ' THEN null
                " . $arr_cust_rowQuery . "
                ELSE custr.sync_status END) as status"),
                    'usrIntg.flow_name',
                    'usrWfRl.id AS user_wf_rule_id',
                    'pfSc.platform_name AS source_platform_name',
                    'pfDc.platform_name AS dest_platform_name',
                    'pfSc.platform_id AS source_platform_id',
                    'pfDc.platform_id AS dest_platform_id',

                    'pfSc.id AS source_platform_pid',
                    'pfDc.id AS dest_platform_pid',

                    'pfWfRl.id AS pf_wf_rule_id'
                )
                ->join('user_integrations AS usrIntg', 'usrIntg.id', '=', 'custr.user_integration_id')
                ->join('platform_workflow_rule AS pfWfRl', 'pfWfRl.platform_integration_id', '=', 'usrIntg.platform_integration_id')
                ->join('platform_events AS pfEvtSc', 'pfEvtSc.id', '=', 'pfWfRl.source_event_id')
                ->join('platform_events AS pfEvtDc', 'pfEvtDc.id', '=', 'pfWfRl.destination_event_id')
                ->join('platform_lookup AS pfSc', 'pfSc.id', '=', 'pfEvtSc.platform_id')
                ->join('platform_lookup AS pfDc', 'pfDc.id', '=', 'pfEvtDc.platform_id')
                ->join('users AS usr', 'usr.id', '=', 'usrIntg.user_id')
                ->join('user_workflow_rule AS usrWfRl', 'usrWfRl.user_integration_id', '=', 'custr.user_integration_id')
                ->leftJoin('platform_customer as custr_dest', 'custr.linked_id', 'custr_dest.id'); //new join for destination reference

            //join sync log
            $elo_table_query->leftJoin('sync_logs as sync_log', function ($query) use ($user_id, $userWfrId, $sourcePlatformId, $destPlatformId) {
                $query->on('sync_log.record_id', '=', 'custr.id')
                    ->where('sync_log.user_workflow_rule_id', $userWfrId)
                    ->where('sync_log.user_id', $user_id)
                    ->where('sync_log.source_platform_id', $sourcePlatformId)
                    ->where('sync_log.destination_platform_id', $destPlatformId)
                    ->orderBy('sync_log.updated_at', 'desc');
            });


            $elo_table = $elo_table_query->whereColumn([['custr.platform_id', '=', 'pfEvtSc.platform_id']])
                ->whereColumn([['pfWfRl.id', '=', 'usrWfRl.platform_workflow_rule_id']])
                ->where('custr.platform_id', $sourcePlatformId)
                ->whereIn('pfEvtSc.event_id', $custEventArr)
                ->where(['usr.organization_id' => $org_id, 'custr.user_integration_id' => $user_intg_id]);
            //, 'usrWfRl.status' => 1
        } else if ($queryTableName == "platform_invoice") {
            //$elo_invc
            $elo_table_query = DB::table('platform_invoice AS invc')
                ->select(
                    'invc.id',
                    'invc.user_id',
                    'invc.user_integration_id',
                    'invc.api_invoice_id as apiItemId',
                    'invc.platform_id',
                    DB::raw((isset($source_ref_field) ? 'invc.' . $source_ref_field : 'invc.ref_number') . ' as info'),
                    DB::raw((isset($dest_ref_field) ? 'invc_dest.' . $dest_ref_field : 'invc_dest.ref_number') . ' as destination_reference'),
                    DB::raw("null AS linked_id"),
                    'pfEvtDc.event_name as type',
                    'pfEvtSc.event_id AS event',
                    'invc.is_deleted',

                    // ''.$dyn_synced_at.' as synced_at',
                    DB::raw("(CASE
                    WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_synced_at . ",'" . $gmtOffset . "','" . $timezone . "')
                    ELSE " . $dyn_synced_at . " END) as synced_at"),

                    // ''.$dyn_updated_at.' as last_run',
                    DB::raw("(CASE
                    WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_updated_at . ",'" . $gmtOffset . "','" . $timezone . "')
                    ELSE " . $dyn_updated_at . " END) as last_run"),

                    DB::raw("null AS record_type"),
                    DB::raw("(CASE
                    WHEN pfEvtSc.event_id=' ' THEN null
                    " . $arr_invoice_rowQuery . "
                    ELSE invc.sync_status END) as status"),
                    'usrIntg.flow_name',
                    'usrWfRl.id AS user_wf_rule_id',
                    'pfSc.platform_name AS source_platform_name',
                    'pfDc.platform_name AS dest_platform_name',
                    'pfSc.platform_id AS source_platform_id',
                    'pfDc.platform_id AS dest_platform_id',

                    'pfSc.id AS source_platform_pid',
                    'pfDc.id AS dest_platform_pid',

                    'pfWfRl.id AS pf_wf_rule_id'
                )
                ->join('user_integrations AS usrIntg', 'usrIntg.id', '=', 'invc.user_integration_id')
                ->join('platform_workflow_rule AS pfWfRl', 'pfWfRl.platform_integration_id', '=', 'usrIntg.platform_integration_id')
                ->join('platform_events AS pfEvtSc', 'pfEvtSc.id', '=', 'pfWfRl.source_event_id')
                ->join('platform_events AS pfEvtDc', 'pfEvtDc.id', '=', 'pfWfRl.destination_event_id')
                ->join('platform_lookup AS pfSc', 'pfSc.id', '=', 'pfEvtSc.platform_id')
                ->join('platform_lookup AS pfDc', 'pfDc.id', '=', 'pfEvtDc.platform_id')
                ->join('users AS usr', 'usr.id', '=', 'usrIntg.user_id')
                ->join('user_workflow_rule AS usrWfRl', 'usrWfRl.user_integration_id', '=', 'invc.user_integration_id')
                ->leftJoin('platform_invoice as invc_dest', 'invc.linked_id', 'invc_dest.id'); //new join for destination reference

            //join sync log
            $elo_table_query->leftJoin('sync_logs as sync_log', function ($query) use ($user_id, $userWfrId, $sourcePlatformId, $destPlatformId) {
                $query->on('sync_log.record_id', '=', 'invc.id')
                    ->where('sync_log.user_workflow_rule_id', $userWfrId)
                    ->where('sync_log.user_id', $user_id)
                    ->where('sync_log.source_platform_id', $sourcePlatformId)
                    ->where('sync_log.destination_platform_id', $destPlatformId)
                    ->orderBy('sync_log.updated_at', 'desc');
            });

            $elo_table = $elo_table_query->whereColumn([['invc.platform_id', '=', 'pfEvtSc.platform_id']])
                ->whereColumn([['pfWfRl.id', '=', 'usrWfRl.platform_workflow_rule_id']])
                ->where('invc.platform_id', $sourcePlatformId)
                ->whereIn('pfEvtSc.event_id', $invoiceEventArr)
                ->where(['usr.organization_id' => $org_id, 'invc.user_integration_id' => $user_intg_id]);
        } else if ($queryTableName == "platform_order_transactions") {
            //$elo_pot
            $elo_table_query = DB::table('platform_order_transactions AS pot')
                ->select(
                    'pot.id',
                    // 'pot.user_id',
                    DB::raw("null AS user_id"),
                    'pot.user_integration_id',
                    'pot.api_transaction_index_id as apiItemId',
                    'pot.platform_id',
                    'pot.transaction_reference AS info',
                    DB::raw((isset($dest_ref_field) ? 'pot_dest.' . $dest_ref_field : 'pot_dest.transaction_reference') . ' as destination_reference'),
                    DB::raw("null AS linked_id"),
                    'pfEvtDc.event_name as type',
                    'pfEvtSc.event_id AS event',

                    DB::raw("null AS is_deleted"),

                    // ''.$dyn_synced_at.' as synced_at',
                    DB::raw("(CASE
                WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_synced_at . ",'" . $gmtOffset . "','" . $timezone . "')
                ELSE " . $dyn_synced_at . " END) as synced_at"),

                    // ''.$dyn_updated_at.' as last_run',
                    DB::raw("(CASE
                WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_updated_at . ",'" . $gmtOffset . "','" . $timezone . "')
                ELSE " . $dyn_updated_at . " END) as last_run"),

                    // DB::raw("null AS record_type"),
                    'pot.row_type AS record_type',

                    DB::raw("(CASE
                WHEN pfEvtSc.event_id=' ' THEN null
                " . $arr_pot_rowQuery . "
                ELSE pot.sync_status END) as status"),
                    'usrIntg.flow_name',
                    'usrWfRl.id AS user_wf_rule_id',
                    'pfSc.platform_name AS source_platform_name',
                    'pfDc.platform_name AS dest_platform_name',
                    'pfSc.platform_id AS source_platform_id',
                    'pfDc.platform_id AS dest_platform_id',

                    'pfSc.id AS source_platform_pid',
                    'pfDc.id AS dest_platform_pid',

                    'pfWfRl.id AS pf_wf_rule_id'
                )
                ->join('user_integrations AS usrIntg', 'usrIntg.id', '=', 'pot.user_integration_id')
                ->join('platform_workflow_rule AS pfWfRl', 'pfWfRl.platform_integration_id', '=', 'usrIntg.platform_integration_id')
                ->join('platform_events AS pfEvtSc', 'pfEvtSc.id', '=', 'pfWfRl.source_event_id')
                ->join('platform_events AS pfEvtDc', 'pfEvtDc.id', '=', 'pfWfRl.destination_event_id')
                ->join('platform_lookup AS pfSc', 'pfSc.id', '=', 'pfEvtSc.platform_id')
                ->join('platform_lookup AS pfDc', 'pfDc.id', '=', 'pfEvtDc.platform_id')
                ->join('users AS usr', 'usr.id', '=', 'usrIntg.user_id')
                ->join('user_workflow_rule AS usrWfRl', 'usrWfRl.user_integration_id', '=', 'pot.user_integration_id')
                ->leftJoin('platform_order_transactions as pot_dest', 'pot.linked_id', 'pot_dest.id'); //new join for destination reference

            //join sync log
            $elo_table_query->leftJoin('sync_logs as sync_log', function ($query) use ($user_id, $userWfrId, $sourcePlatformId, $destPlatformId) {
                $query->on('sync_log.record_id', '=', 'pot.id')
                    ->where('sync_log.user_workflow_rule_id', $userWfrId)
                    ->where('sync_log.user_id', $user_id)
                    ->where('sync_log.source_platform_id', $sourcePlatformId)
                    ->where('sync_log.destination_platform_id', $destPlatformId)
                    ->orderBy('sync_log.updated_at', 'desc');
            });

            $elo_table = $elo_table_query->whereColumn([['pot.platform_id', '=', 'pfEvtSc.platform_id']])
                ->whereColumn([['pfWfRl.id', '=', 'usrWfRl.platform_workflow_rule_id']])
                ->where('pot.platform_id', $sourcePlatformId)
                ->whereIn('pfEvtSc.event_id', $potEventArr)
                ->where(['usr.organization_id' => $org_id, 'pot.user_integration_id' => $user_intg_id]);
        } else if ($queryTableName == "platform_order_shipments") {
            //$elo_pos
            $elo_table_query = DB::table('platform_order_shipments AS pos')
                ->select(
                    'pos.id',
                    'pos.user_id',
                    'pos.user_integration_id',
                    'pos.shipment_id as apiItemId',
                    'pos.platform_id',
                    'pos.shipment_id AS info',
                    // DB::raw("null AS linked_id"),
                    'pos.linked_id',
                    DB::raw((isset($dest_ref_field) ? 'pos_dest.' . $dest_ref_field : 'pos_dest.shipment_id') . ' as destination_reference'),
                    'pfEvtDc.event_name as type',
                    'pfEvtSc.event_id AS event',
                    DB::raw("null AS is_deleted"),
                    // ''.$dyn_synced_at.' as synced_at',
                    DB::raw("(CASE
               WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_synced_at . ",'" . $gmtOffset . "','" . $timezone . "')
               ELSE " . $dyn_synced_at . " END) as synced_at"),
                    // ''.$dyn_updated_at.' as last_run',
                    DB::raw("(CASE
               WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_updated_at . ",'" . $gmtOffset . "','" . $timezone . "')
               ELSE " . $dyn_updated_at . " END) as last_run"),
                    DB::raw("null AS record_type"),
                    DB::raw("(CASE
               WHEN pfEvtSc.event_id=' ' THEN null
               " . $arr_pos_rowQuery . "
               ELSE pos.sync_status END) as status"),
                    'usrIntg.flow_name',
                    'usrWfRl.id AS user_wf_rule_id',
                    'pfSc.platform_name AS source_platform_name',
                    'pfDc.platform_name AS dest_platform_name',
                    'pfSc.platform_id AS source_platform_id',
                    'pfDc.platform_id AS dest_platform_id',

                    'pfSc.id AS source_platform_pid',
                    'pfDc.id AS dest_platform_pid',

                    'pfWfRl.id AS pf_wf_rule_id'
                )
                ->join('user_integrations AS usrIntg', 'usrIntg.id', '=', 'pos.user_integration_id')
                ->join('platform_workflow_rule AS pfWfRl', 'pfWfRl.platform_integration_id', '=', 'usrIntg.platform_integration_id')
                ->join('platform_events AS pfEvtSc', 'pfEvtSc.id', '=', 'pfWfRl.source_event_id')
                ->join('platform_events AS pfEvtDc', 'pfEvtDc.id', '=', 'pfWfRl.destination_event_id')
                ->join('platform_lookup AS pfSc', 'pfSc.id', '=', 'pfEvtSc.platform_id')
                ->join('platform_lookup AS pfDc', 'pfDc.id', '=', 'pfEvtDc.platform_id')
                ->join('users AS usr', 'usr.id', '=', 'usrIntg.user_id')
                ->join('user_workflow_rule AS usrWfRl', 'usrWfRl.user_integration_id', '=', 'pos.user_integration_id')
                ->leftJoin('platform_order_shipments as pos_dest', 'pos_dest.linked_id', 'pos.id'); //new join for destination reference
            //join sync log
            $elo_table_query->leftJoin('sync_logs as sync_log', function ($query) use ($user_id, $userWfrId, $sourcePlatformId, $destPlatformId) {
                $query->on('sync_log.record_id', '=', 'pos.id')
                    ->where('sync_log.user_workflow_rule_id', $userWfrId)
                    ->where('sync_log.user_id', $user_id)
                    ->where('sync_log.source_platform_id', $sourcePlatformId)
                    ->where('sync_log.destination_platform_id', $destPlatformId)
                    ->orderBy('sync_log.updated_at', 'desc');
            });
            $elo_table_query->whereColumn([['pos.platform_id', '=', 'pfEvtSc.platform_id']])
                ->whereColumn([['pfWfRl.id', '=', 'usrWfRl.platform_workflow_rule_id']])
                ->where('pos.platform_id', $sourcePlatformId)
                ->whereIn('pfEvtSc.event_id', $posEventArr)
                ->where(['usr.organization_id' => $org_id, 'pos.user_integration_id' => $user_intg_id]);

            if ($event == 'GET_TRANSFEREDGOODSOUTNOTE' || $event == 'GET_ALLTRANSFERITEMRECEIVED' || $event == 'GET_TRANSFEREDGOODSOUTNOTECREATED' || $event == 'GET_SHIPMENTTO') {
                $elo_table = $elo_table_query->where('pos.type', 'Transfer');
            } else if ($event == 'GET_GOODSINNOTE' || $event == 'GET_PURCHASEORDERRECIEPT') {
                $elo_table = $elo_table_query->where('pos.type', 'POShipment');
            } else {
                $elo_table = $elo_table_query->where('pos.type', 'Shipment');
            }
        } else if ($queryTableName == "platform_order_refunds") {
            // $elo_ord
            $elo_table_query = DB::table('platform_order AS ord')
                ->select(
                    'ord.id',
                    'ord.user_id',
                    'ord.user_integration_id',
                    'ord.platform_id',

                    'ordRef.api_id as apiItemId',
                    'ordRef.refund_order_number AS info',
                    'ordRef.linked_id',
                    DB::raw((isset($dest_ref_field) ? 'ordRef_dest.' . $dest_ref_field : 'ordRef_dest.refund_order_number') . ' as destination_reference'),
                    'pfEvtDc.event_name as type',
                    'pfEvtSc.event_id AS event',

                    DB::raw("null AS is_deleted"),

                    // ''.$dyn_synced_at.' as synced_at',
                    DB::raw("(CASE
                WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_synced_at . ",'" . $gmtOffset . "','" . $timezone . "')
                ELSE " . $dyn_synced_at . " END) as synced_at"),

                    // ''.$dyn_updated_at.' as last_run',
                    DB::raw("(CASE
                WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_updated_at . ",'" . $gmtOffset . "','" . $timezone . "')
                ELSE " . $dyn_updated_at . " END) as last_run"),

                    'ord.order_type AS record_type',

                    DB::raw("(CASE
                WHEN pfEvtSc.event_id='' THEN null
                " . $arr_ord_ref_rowQuery . "
                ELSE ordRef.sync_status END) as status"),
                    'usrIntg.flow_name',
                    'usrWfRl.id AS user_wf_rule_id',
                    'pfSc.platform_name AS source_platform_name',
                    'pfDc.platform_name AS dest_platform_name',
                    'pfSc.platform_id AS source_platform_id',
                    'pfDc.platform_id AS dest_platform_id',

                    'pfSc.id AS source_platform_pid',
                    'pfDc.id AS dest_platform_pid',

                    'pfWfRl.id AS pf_wf_rule_id'
                )
                ->join('user_integrations AS usrIntg', 'usrIntg.id', '=', 'ord.user_integration_id')
                ->join('platform_workflow_rule AS pfWfRl', 'pfWfRl.platform_integration_id', '=', 'usrIntg.platform_integration_id')
                ->join('platform_events AS pfEvtSc', 'pfEvtSc.id', '=', 'pfWfRl.source_event_id')
                ->join('platform_events AS pfEvtDc', 'pfEvtDc.id', '=', 'pfWfRl.destination_event_id')
                ->join('platform_lookup AS pfSc', 'pfSc.id', '=', 'pfEvtSc.platform_id')
                ->join('platform_lookup AS pfDc', 'pfDc.id', '=', 'pfEvtDc.platform_id')
                ->join('users AS usr', 'usr.id', '=', 'usrIntg.user_id')
                ->join('user_workflow_rule AS usrWfRl', 'usrWfRl.user_integration_id', '=', 'ord.user_integration_id')

                //new join
                ->join('platform_order_refunds AS ordRef', 'ordRef.platform_order_id', 'ord.id')
                ->leftJoin('platform_order_refunds as ordRef_dest', 'ordRef_dest.linked_id', 'ordRef.id'); //new join for destination reference


            //join sync log
            $elo_table_query->leftJoin('sync_logs as sync_log', function ($query) use ($user_id, $userWfrId, $sourcePlatformId, $destPlatformId) {
                $query->on('sync_log.record_id', '=', 'ordRef.id')
                    ->where('sync_log.user_workflow_rule_id', $userWfrId)
                    ->where('sync_log.user_id', $user_id)
                    ->where('sync_log.source_platform_id', $sourcePlatformId)
                    ->where('sync_log.destination_platform_id', $destPlatformId)
                    ->orderBy('sync_log.updated_at', 'desc');
            });

            $elo_table = $elo_table_query->whereColumn([['ord.platform_id', '=', 'pfEvtSc.platform_id']])
                ->whereColumn([['pfWfRl.id', '=', 'usrWfRl.platform_workflow_rule_id']])
                ->where('ord.platform_id', $sourcePlatformId)
                ->whereIn('pfEvtSc.event_id', $OrdRefEventArr)
                ->where(['usr.organization_id' => $org_id, 'ord.user_integration_id' => $user_intg_id]);
        } else if ($queryTableName == "platform_tickets") {

            $columns = [
                'subject', 'source_platform_name', 'dest_platform_name', 'event', 'last_run', 'destination_reference'
            ];

            // $elo_ord
            // DB::enableQueryLog();
            $elo_table = DB::table('platform_tickets AS pt')
                ->select(
                    'pt.id',
                    'pt.user_id',
                    'pt.subject',
                    'pt.user_integration_id',
                    'pt.platform_id',
                    'pt.linked_id',
                    'pfEvtDc.event_name as type',
                    'pfEvtSc.event_id AS event',
                    'pt.is_deleted',
                    DB::raw("(CASE WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_synced_at . ",'" . $gmtOffset . "','" . $timezone . "') ELSE " . $dyn_synced_at . " END) as synced_at"),
                    DB::raw("(CASE WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_updated_at . ",'" . $gmtOffset . "','" . $timezone . "') ELSE " . $dyn_updated_at . " END) as last_run"),
                    DB::raw("(CASE WHEN pfEvtSc.event_id='' THEN null " . $arr_tkt_ref_rowQuery . " ELSE pt.sync_reply_status END) as status"),
                    'usrIntg.flow_name',
                    'usrWfRl.id AS user_wf_rule_id',
                    'pfSc.platform_name AS source_platform_name',
                    'pfDc.platform_name AS dest_platform_name',
                    'pfSc.platform_id AS source_platform_id',
                    'pfDc.platform_id AS dest_platform_id',
                    'pfSc.id AS source_platform_pid',
                    'pfDc.id AS dest_platform_pid',
                    'pfWfRl.id AS pf_wf_rule_id',
                    DB::raw((isset($dest_ref_field) ? 'pt_dest.' . $dest_ref_field : 'pt_dest.api_ticket_id') . ' as destination_reference'),
                )
                ->join('user_integrations AS usrIntg', 'usrIntg.id', '=', 'pt.user_integration_id')
                ->join('platform_workflow_rule AS pfWfRl', 'pfWfRl.platform_integration_id', '=', 'usrIntg.platform_integration_id')
                ->join('platform_events AS pfEvtSc', 'pfEvtSc.id', '=', 'pfWfRl.source_event_id')
                ->join('platform_events AS pfEvtDc', 'pfEvtDc.id', '=', 'pfWfRl.destination_event_id')
                ->join('platform_lookup AS pfSc', 'pfSc.id', '=', 'pfEvtSc.platform_id')
                ->join('platform_lookup AS pfDc', 'pfDc.id', '=', 'pfEvtDc.platform_id')
                ->join('users AS usr', 'usr.id', '=', 'usrIntg.user_id')
                ->join('user_workflow_rule AS usrWfRl', 'usrWfRl.user_integration_id', '=', 'pt.user_integration_id')
                ->leftJoin('platform_tickets as pt_dest', 'pt.linked_id', 'pt_dest.id'); //new join for destination reference
            // ->where('pt.user_integration_id', $user_intg_id);

            //join sync log
            $elo_table->leftJoin('sync_logs as sync_log', function ($query) use ($user_id, $userWfrId, $sourcePlatformId, $destPlatformId) {
                $query->on('sync_log.record_id', '=', 'pt.id')
                    ->where([
                        'sync_log.user_workflow_rule_id' => $userWfrId,
                        'sync_log.user_id' => $user_id,
                        'sync_log.source_platform_id' => $sourcePlatformId,
                        'sync_log.destination_platform_id' => $destPlatformId
                    ])
                    ->orderBy('sync_log.updated_at', 'desc');
            });

            $elo_table = $elo_table->whereColumn([['pt.platform_id', '=', 'pfEvtSc.platform_id']])
                ->whereColumn([['pfWfRl.id', '=', 'usrWfRl.platform_workflow_rule_id']])
                ->where('pt.platform_id', $sourcePlatformId)
                ->whereIn('pfEvtSc.event_id', $ptEventArr)
                ->where(['usr.organization_id' => $org_id, 'pt.user_integration_id' => $user_intg_id]);
        } else {

            // $elo_ord
            $elo_table_query = DB::table('platform_order AS ord')
                ->select(
                    'ord.id',
                    'ord.user_id',
                    'ord.user_integration_id',
                    'ord.api_order_id as apiItemId',
                    'ord.platform_id',
                    DB::raw((isset($source_ref_field) ? 'ord.' . $source_ref_field : 'ord.order_number') . ' As info'),
                    'ord.linked_id',
                    DB::raw((isset($dest_ref_field) ? 'ord_dest.' . $dest_ref_field : 'ord_dest.api_order_id') . ' as destination_reference'),
                    'pfEvtDc.event_name as type',
                    'pfEvtSc.event_id AS event',
                    'ord.is_deleted',

                    // ''.$dyn_synced_at.' as synced_at',
                    DB::raw("(CASE
                WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_synced_at . ",'" . $gmtOffset . "','" . $timezone . "')
                ELSE " . $dyn_synced_at . " END) as synced_at"),

                    // ''.$dyn_updated_at.' as last_run',
                    DB::raw("(CASE
                WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(" . $dyn_updated_at . ",'" . $gmtOffset . "','" . $timezone . "')
                ELSE " . $dyn_updated_at . " END) as last_run"),

                    'ord.order_type AS record_type',

                    DB::raw("(CASE
                WHEN pfEvtSc.event_id='' THEN null
                " . $arr_ord_rowQuery . "
                ELSE ord.sync_status END) as status"),
                    'usrIntg.flow_name',
                    'usrWfRl.id AS user_wf_rule_id',
                    'pfSc.platform_name AS source_platform_name',
                    'pfDc.platform_name AS dest_platform_name',
                    'pfSc.platform_id AS source_platform_id',
                    'pfDc.platform_id AS dest_platform_id',
                    'pfSc.id AS source_platform_pid',
                    'pfDc.id AS dest_platform_pid',
                    'pfWfRl.id AS pf_wf_rule_id'
                )
                ->join('user_integrations AS usrIntg', 'usrIntg.id', '=', 'ord.user_integration_id')
                ->join('platform_workflow_rule AS pfWfRl', 'pfWfRl.platform_integration_id', '=', 'usrIntg.platform_integration_id')
                ->join('platform_events AS pfEvtSc', 'pfEvtSc.id', '=', 'pfWfRl.source_event_id')
                ->join('platform_events AS pfEvtDc', 'pfEvtDc.id', '=', 'pfWfRl.destination_event_id')
                ->join('platform_lookup AS pfSc', 'pfSc.id', '=', 'pfEvtSc.platform_id')
                ->join('platform_lookup AS pfDc', 'pfDc.id', '=', 'pfEvtDc.platform_id')
                ->join('users AS usr', 'usr.id', '=', 'usrIntg.user_id')
                ->join('user_workflow_rule AS usrWfRl', 'usrWfRl.user_integration_id', '=', 'ord.user_integration_id')
                ->leftJoin('platform_order as ord_dest', 'ord.linked_id', 'ord_dest.id'); //new join for destination reference

            //join sync log
            $elo_table_query->leftJoin('sync_logs as sync_log', function ($query) use ($user_id, $userWfrId, $sourcePlatformId, $destPlatformId) {
                $query->on('sync_log.record_id', '=', 'ord.id')
                    ->where('sync_log.user_workflow_rule_id', $userWfrId)
                    ->where('sync_log.user_id', $user_id)
                    ->where('sync_log.source_platform_id', $sourcePlatformId)
                    ->where('sync_log.destination_platform_id', $destPlatformId)
                    ->orderBy('sync_log.updated_at', 'desc');
            });

            //Filter order for GET_GOODSOUTNOTECREATED && Filter order for GET_SHIPMENT
            if ($event == "GET_GOODSOUTNOTECREATED" || $event == "GET_SHIPMENT") {
                $elo_table_query->join('platform_order_shipments as ord_ship', function ($query) use ($user_id, $user_intg_id, $sourcePlatformId, $event) {
                    $query->on('ord_ship.platform_order_id', '=', 'ord.id')
                        ->where('ord_ship.user_integration_id', $user_intg_id)
                        ->where('ord_ship.user_id', $user_id)
                        ->where('ord_ship.platform_id', $sourcePlatformId)
                        ->where(function ($query) use ($event) {
                            if ($event == "GET_SHIPMENT") {
                                $query->where('ord.order_type', 'SO');
                            }
                        })
                        ->groupBy('ord.id');
                });
            }

            //filter only those order which are not in platform_order_shipments table
            if ($event == "GET_SALEORDERACKNOWLEDGE") {
                $skipIds = DB::table('platform_order_shipments')->where(['user_integration_id' => $user_intg_id, 'platform_id' => $sourcePlatformId])->pluck('platform_order_id')->toArray();
                //add filter for GET_SALEORDERACKNOWLEDGE
                $elo_table_query->whereNotIn('ord.id', $skipIds);
            }

            //add filter for GET_CHECKFULFILLMENTORDERSTATUS only cancelled order
            if ($event == "GET_CHECKFULFILLMENTORDERSTATUS") {
                $elo_table_query->where('ord.is_voided', 1);
            }

            $elo_table = $elo_table_query->whereColumn([['ord.platform_id', '=', 'pfEvtSc.platform_id']])
                ->whereColumn([['pfWfRl.id', '=', 'usrWfRl.platform_workflow_rule_id']])
                ->where('ord.platform_id', $sourcePlatformId)
                ->whereIn('pfEvtSc.event_id', $orderEventArr)
                ->where(['usr.organization_id' => $org_id, 'ord.user_integration_id' => $user_intg_id]);
        }

        // $union_query = $elo_ord->union($elo_inv)->union($elo_custr)->union($elo_invc)->union($elo_pot)->union($elo_pot);
        $union_query = $elo_table;
        $uniqueData = DB::query()->fromSub($union_query, 'union_query');

        if (isset($event)) {
            $uniqueData->where(['event' => $event]);

            if ($event == 'GET_SALESORDER' || $event == 'GET_ITEMSHIPPED' || $event == 'GET_GOODSOUTNOTECREATED' || $event == 'GET_SALESORDERINVOICE') {
                $uniqueData->where(['record_type' => 'SO']);
            } else if ($event == 'GET_PURCHASEORDER' || $event == 'GET_POITEMRECEIPT' ||  $event == 'GET_PURCHASEORDERRECEIPT') {//GET_PURCHASEORDERRECEIPT and GET_POITEMRECEIPT both are same but recom. to use GET_POITEMRECEIP
                //conditional filter for amazon
                //use amazon & brightpearl instead there id so that it will work in staging & live
                if ($sourcePlatformName == 'amazonvendor' && $destPlatformName == 'brightpearl') {
                    $uniqueData->whereIn('record_type', ['SO', 'PO']);
                } else {
                    $uniqueData->where(['record_type' => 'PO']);
                }
            } else if ($event == 'GET_TRANSFERORDER' ||  $event == 'GET_TRANSFERORDERRECEIPT') {
                $uniqueData->where(['record_type' => 'TO']);
            } else if ($event == 'GET_ORDERASINVOICE') {
                $uniqueData->where(['record_type' => 'IO']);
            } else if ($event == 'GET_ITEMRECEIPTRETURN' || $event == 'GET_SALESCREDIT' || $event == 'GET_SALESCREDITINVOICE') {
                $uniqueData->where(['record_type' => 'SC']);
            } else if ($event == 'GET_CANCELLEDORDERS') {
                $uniqueData->where(['is_deleted' => 1]);
            }
        }

        // Event filter is by default applied on load so total count comes after above event filter query
        //$totalData = DB::query()->fromSub($uniqueData, 'uniqueData')->count();
        $totalData = DB::query()->fromSub($uniqueData, 'uniqueData')->where('status', '<>', 'Pending')->where('status', '<>', 'Inactive')->count();

        //dynamic variable for filter by from - to date
        if ($FilterByDate) {
            $dyn_date_filter_column = $FilterByDate;
        } else {
            $dyn_date_filter_column = "last_run";
        }

        if (isset($from_date) && isset($to_date)) {
            $uniqueData->whereBetween(DB::raw("DATE_FORMAT(" . $dyn_date_filter_column . ", '%Y-%m-%d')"), [$from_date, $to_date]);
        }

        if (isset($status)) {
            $uniqueData->where(function ($query1) use ($status, $event) {
                if ($status == 'Pending') {
                    $query1->whereIn('status', ['Ready']);
                } else {
                    $query1->where(['status' => $status]);
                }
            });
        }

        $totalFiltered = $totalData;
        $limit = $request->input('length');
        $start = $request->input('start');
        $search = $request->input('search.value');

        $query_part = $uniqueData->where(function ($query1) use ($search, $columns) {
            if ($search != '') {
                for ($i = 0; $i < count($columns); $i++) {
                    $query1->orWhere($columns[$i], 'like', '%' . $search . '%');
                }
            }
        });

        $query = $query_part->distinct('id')->where('status', '<>', 'Pending')->where('status', '<>', 'Inactive');
        // dd(DB::getQueryLog());
        //$query = $query_part;
        $totalFiltered = $query->count();

        //manage shorting by column
        if (isset($request['order'])) {
            $dir = $request['order'][0]['dir'];
            $order = $request['order'][0]['column'];
            $shortcolumn = $request['columns'][$order]['name'];
        } else {
            $dir = "DESC";
            $shortcolumn = "last_run";
        }

        if ($queryTableName == "platform_ticket") {
            $shortcolumn = "subject";
        }

        $result = $query->groupBy('id')->orderBy($shortcolumn, $dir)->skip($start)->take($limit)->get();


        $data = array();
        if (!empty($result)) {

            //check user permission for show hide resync
            $modify_integrations = ModuleAccessController::getAccessRight($staff_id, $user_role, 'integrations', 'modify');

            foreach ($result as $key => $rv) {

                $nestedData['id'] = $rv->id;
                //set last run null if not found from correspond table
                if ($rv->last_run == "" || $rv->last_run == NULL) {
                    $nestedData['last_run'] = "";
                } else {
                    $nestedData['last_run'] = date_format(date_create($rv->last_run), 'd M Y H:i');
                }

                //set sync_at null if not found from correspond table
                if ($rv->synced_at == "" || $rv->synced_at == NULL) {
                    $nestedData['synced_at'] = "";
                } else {
                    $nestedData['synced_at'] = ($rv->status != "Failed") ? date_format(date_create($rv->synced_at), 'd M Y H:i') : "";
                }

                if ($rv->is_deleted == 1) {
                    $status = '<span class="right badge badge-secondary" id="badge">Deleted</span>';
                } else {

                    switch ($rv->status) {
                        case 'Ready':
                            $status = '<span class="right badge badge-info" id="badge">Pending</span>';
                            break;
                        case 'Processing':
                            $status = '<span class="right badge badge-secondary" id="badge">Processing</span>';
                            break;
                        case 'Ignore':
                            $status = '<span class="right badge badge-secondary" id="badge">Ignored</span>';
                            break;
                        case 'Partial':
                            $status = '<span class="right badge badge-info" id="badge">Partial</span>';
                            break;
                        case 'Synced':
                            $linked_info = null;
                            if (isset($rv->destination_reference) && is_null($request->get('organization_id'))) {
                                $linked_info = '&nbsp;<i class="fa fa-info-circle failed-tooltip" data-toggle="tooltip" data-placement="top" data-original-title="Reference: ' . $rv->destination_reference . '" style="cursor: pointer;"></i>';
                            }

                            $status = '<span class="right badge badge-success" id="badge">Synced</span>' . $linked_info;
                            break;
                        case 'Failed':
                            $error = $this->getErrorLogResponse($user_id, $rv->user_wf_rule_id, $rv->id);
                            $status = '<span class="right badge badge-danger" id="badge">Failed</span>&nbsp;<i class="fa fa-info-circle failed-tooltip" data-toggle="tooltip" data-placement="left" data-recordId="' . $rv->id . '" data-original-title="' . (isset($error->response) ? strip_tags($error->response) : null) . '" style="cursor: pointer;"></i>';
                            break;
                        default:
                            $status = "Not Found";
                    }
                }

                $nestedData['intg_platform'] = $rv->source_platform_name . "&nbsp;to&nbsp;" . $rv->dest_platform_name;

                $pltProdId = $rv->id;
                if ($queryTableName == "platform_tickets") {
                    $nestedData['info'] = $rv->subject;
                } else {
                    $pltApiProdId = $rv->apiItemId;
                    $nestedData['info'] = (isset($rv->info) ? $rv->info : 'N/A');
                }

                $nestedData['type'] = $rv->type;

                //modify record type for amazon SO
                if ($sourcePlatformName == 'amazonvendor' && $destPlatformName == 'brightpearl') {
                    if ($rv->record_type == "SO") {
                        $nestedData['type'] = $rv->type . '(DFO)';
                    }
                }

                //source & destination from fetch record
                $nestedData['platform_data_flow'] = $rv->source_platform_pid . '-' . $rv->dest_platform_pid;


                $nestedData['status'] = $status;
                $nestedData['destination_reference'] = (isset($rv->destination_reference) && $rv->destination_reference) ? $rv->destination_reference : "";

                if ($rv->status == 'Failed') {
                    if ($modify_integrations == 1) {
                        $nestedData['action'] =
                            '<span style="display:flex"><button class="btn btn-primary btn-sm btn-resync" data-toggle="tooltip" data-placement="top" data-original-title="Resync" data-id="' . $rv->id . '" data-user_id="' . $user_id . '" data-user_integration_id="' . $rv->user_integration_id . '"
                        data-source_platform_id="' . $rv->source_platform_id . '" data-dest_platform_id="' . $rv->dest_platform_id . '" data-user_wf_rule_id="' . $rv->user_wf_rule_id . '" data-pf_wf_rule_id="' . $rv->pf_wf_rule_id . '" ><i class="fa fa-refresh" style="font-size:20px"></i></button>&nbsp;
                        <button class="btn btn-success btn-sm btn-ignore secondary-btn-style" data-toggle="tooltip" data-placement="top" data-original-title="Is it Resolved? Mark it as ignore using this option" data-id="' . $rv->id . '" data-user_id="' . $user_id . '" data-user_integration_id="' . $rv->user_integration_id . '"
                        data-source_platform_id="' . $rv->source_platform_id . '" data-dest_platform_id="' . $rv->dest_platform_id . '" data-user_wf_rule_id="' . $rv->user_wf_rule_id . '" data-pf_wf_rule_id="' . $rv->pf_wf_rule_id . '" ><i class="fa fa-check-circle" aria-hidden="true" style="font-size:20px"></i></button></span>';
                    } else {
                        $nestedData['action'] = '';
                    }
                } else if ($rv->status == 'Ignore') {
                    if ($modify_integrations == 1) {
                        $nestedData['action'] =
                        '<button class="btn btn-danger btn-sm btn-failed" data-toggle="tooltip" data-placement="top" data-original-title="Mark it as failed using this option" data-id="' . $rv->id . '" data-user_id="' . $user_id . '" data-user_integration_id="' . $rv->user_integration_id . '"
                        data-source_platform_id="' . $rv->source_platform_id . '" data-dest_platform_id="' . $rv->dest_platform_id . '" data-user_wf_rule_id="' . $rv->user_wf_rule_id . '" data-pf_wf_rule_id="' . $rv->pf_wf_rule_id . '" ><i class="fa fa-check-circle" aria-hidden="true" style="font-size:20px"></i></button>';

                    } else {
                        $nestedData['action'] = '';
                    }
                } else {
                    $nestedData['action'] = '';
                    //Start show detail action button for synced record if platform & event added in log
                    if($rv->status="Synced" && (isset(\Config::get('apisettings.showDetailOptionInAuditLog')[$sourcePlatformName]) || isset(\Config::get('apisettings.showDetailOptionInAuditLog')[$destPlatformName])) ) {
                        //check event exist in setting
                        if( in_array($event, Config::get('apisettings.showDetailOptionForEventsInAuditLog')) ) {
                            $nestedData['action'] =
                            '<button class="btn btn-info btn-sm btn-getDetail" data-toggle="tooltip" data-placement="top" data-original-title="Get Details" data-id="' . $rv->id . '" data-user_id="' . $user_id . '" data-user_integration_id="' . $rv->user_integration_id . '"
                            data-source_platform_id="' . $rv->source_platform_id . '" data-dest_platform_id="' . $rv->dest_platform_id . '" data-user_wf_rule_id="' . $rv->user_wf_rule_id . '" data-pf_wf_rule_id="' . $rv->pf_wf_rule_id . '" ><i class="fa fa-info-circle" aria-hidden="true" style="font-size:20px"></i></button>';
                        }
                    }
                    //end new changes
                }

                $data[] = $nestedData;
            }
        }

        $json_data = array(
            "draw"            => intval($request->input('draw')),
            "recordsTotal"    => intval($totalFiltered), //intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data"            => $data,
            'platform_data_flow_by_filter' => $sourcePlatformId . '-' . $destPlatformId,
            'querytable'      => $queryTableName
        );

        if ($request->get('organization_id')) { //for master audit logs
            return $json_data;
        } else {
            echo json_encode($json_data);
        }
    }

    //get audit log details by click action button
    public function getAuditLogRowDetails(Request $request)
    {
        $data = [];
        $modal_title = "";

        try {

            $user_id = \Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id.
            $record_id = $request->id;
            $userIntegId = $request->userIntegId;
            $log_event = $request->log_event;
            $sPlatformId = $request->get('sourcePlatformId');
            $dPlatformId = $request->get('destPlatformId');
            $sourcePlaName = $request->get('sourcePlaName');
            $destPltName = $request->get('destPltName');

            if ($log_event) {

                $linkedTable =  $this->getLinkedTableByEvent($log_event, $sPlatformId, $dPlatformId);
                if ($linkedTable) {

                    //get details data
                    $details_data = DB::table($linkedTable)->where('id',$record_id)->first();

                    $modal_body = "";
                    if($linkedTable=="platform_order") {
                        $modal_title = "Order Details";
                        if($details_data) {
                            $modal_body.= '<p><strong>Order Type</strong> : '.$details_data->order_type.'</p>';
                            $modal_body.= '<p><strong>Order Number</strong> :'.$details_data->order_number.'</p>';
                            $modal_body.= '<p><strong>Order Payment Status</strong> : '.$details_data->api_order_payment_status.'</p>';
                            $modal_body.= '<p><strong>Is Fully Synced</strong> : '.$details_data->is_fully_synced.'</p>';
                            $modal_body.= '<p><strong>Invoice Status</strong> : '.$details_data->invoice_sync_status.'</p>';
                            $modal_body.= '<p><strong>Ship Speed</strong> : '.$details_data->ship_speed.'</p>';
                            $modal_body.= '<p><strong>Carrier Code</strong> : '.$details_data->carrier_code.'</p>';
                            $modal_body.= '<p><strong>Shipment Status</strong> : '.$details_data->shipment_status.'</p>';
                        }
                       
                    } else if($linkedTable=="platform_order_shipments") {
                        $modal_title = "Shipment Details";
                        if($details_data) {
                            $modal_body.= '<p><strong>Shipment Id</strong> : '.$details_data->shipment_id.'</p>';
                            $modal_body.= '<p><strong>Shipment Sequence Number</strong> :'.$details_data->shipment_sequence_number.'</p>';
                            $modal_body.= '<p><strong>Shipment Status</strong> : '.$details_data->shipment_status.'</p>';
                            $modal_body.= '<p><strong>Tracking Info</strong> : '.$details_data->tracking_info.'</p>';
                            $modal_body.= '<p><strong>Shipment Type</strong> : '.$details_data->type.'</p>';
                            $modal_body.= '<p><strong>Tracking Url</strong> : '.$details_data->tracking_url.'</p>';
                            $modal_body.= '<p><strong>Carrier Code</strong> : '.$details_data->carrier_code.'</p>';
                            $modal_body.= '<p><strong>Created On</strong> : '.$details_data->created_on.'</p>';
                        }
                    } else if($linkedTable=="platform_product") {
                        $modal_title = "Product Details";
                        if($details_data) {
                            $modal_body.= '<p><strong>Product Name</strong> : '.$details_data->product_name.'</p>';
                            $modal_body.= '<p><strong>EAN</strong> :'.$details_data->ean.'</p>';
                            $modal_body.= '<p><strong>SKU</strong> : '.$details_data->sku.'</p>';
                            $modal_body.= '<p><strong>ISBN</strong> : '.$details_data->isbn.'</p>';
                            $modal_body.= '<p><strong>Is Stock Tracked</strong> : '.$details_data->stock_track.'</p>';
                            $modal_body.= '<p><strong>Description</strong> : '.$details_data->description.'</p>';
                        }
                    } else if($linkedTable=="platform_invoice") {

                        $modal_title = "Invoice Details";

                        if($log_event=="GET_INVOICE" && ($sourcePlaName=="extensivbillingmanager" || $destPltName="extensivbillingmanager")) {
                            $modal_title = "Invoice History";
                            $invoice_history = DB::table('platform_invoice_history')->where('platform_invoice_id',$record_id)->select('invoice_status','api_created_at')
                            ->orderBy('api_created_at','asc')->get();

                            $modal_body.='<div class="row">
                            <div class="col-md-12">
                            <div class="timeline">';

                            $modal_body.='<div class="time-label">
                            <span class="bg-light">Order Doc Number : '.$details_data->order_doc_number.'</span>
                            </div>';
            
                            if($invoice_history) {
                                foreach($invoice_history as $history) {
                                    $icon_class = "fa fa-dot-circle-o bg-warning";
                                    if($history->invoice_status=="paid") {
                                        $icon_class = "fa fa-dot-circle-o bg-success";
                                    } else if($history->invoice_status=="unpublished") { 
                                        $icon_class = "fa fa-dot-circle-o bg-warning";
                                    } else if($history->invoice_status=="published") { 
                                        $icon_class = "fa fa-dot-circle-o bg-success";
                                    } 

                                    $modal_body.='<div>
                                    <i class="'.$icon_class.'"></i>
                                    <div class="timeline-item">
                                    <span class="time"><i class="fas fa-clock"></i> '.$history->api_created_at.'</span>
                                    <p class="timeline-header no-border">'.ucfirst($history->invoice_status).'</p>
                                    </div>
                                    </div>';
                                }
                            }
                            
                            $modal_body.='<div>
                            </div>
                            <div>
                            <i class="fas fa-clock bg-gray"></i>
                            </div>
                            </div>
                            </div>
                            </div>';

                        } else if ($details_data) {
                            $modal_body.= '<p><strong>Order Doc Number</strong> : '.$details_data->order_doc_number.'</p>';
                            $modal_body.= '<p><strong>Invoice Code</strong> :'.$details_data->invoice_code.'</p>';
                            $modal_body.= '<p><strong>Invoice Payment Status</strong> : '.$details_data->invoice_payment_status.'</p>';
                            $modal_body.= '<p><strong>Refrence Number</strong> : '.$details_data->ref_number.'</p>';
                            $modal_body.= '<p><strong>Payment Amount</strong> : '.$details_data->total_paid_amt.'</p>';
                            $modal_body.= '<p><strong>Tracking Number</strong> : '.$details_data->tracking_number.'</p>';
                            $modal_body.= '<p><strong>Ship Via</strong> : '.$details_data->ship_via.'</p>';
                        }

                    } else if($linkedTable=="platform_tickets") {
                        $modal_title = "Ticket Details";
                        if($details_data) {
                            $modal_body.= '<p><strong>Ticket Number</strong> : '.$details_data->ticket_number.'</p>';
                            $modal_body.= '<p><strong>Subject</strong> :'.$details_data->subject.'</p>';
                            $modal_body.= '<p><strong>Name</strong> : '.$details_data->name.'</p>';
                            $modal_body.= '<p><strong>Ticket Status</strong> : '.$details_data->ticket_status.'</p>';
                            $modal_body.= '<p><strong>Ticket Date</strong> : '.$details_data->ticket_date.'</p>';
                            $modal_body.= '<p><strong>Ticket Update Date</strong> : '.$details_data->ticket_update_date.'</p>';
                        }
                    } else if($linkedTable=="platform_customer") {
                        $modal_title = "Customer Details";
                        if($details_data) {
                            $modal_body.= '<p><strong>Customer Code</strong> : '.$details_data->api_customer_code.'</p>';
                            $modal_body.= '<p><strong>Customer Name</strong> :'.$details_data->customer_name.'</p>';
                            $modal_body.= '<p><strong>Company Name</strong> : '.$details_data->company_name.'</p>';
                            $modal_body.= '<p><strong>Customer Type</strong> : '.$details_data->type.'</p>';
                            $modal_body.= '<p><strong>Email</strong> : '.$details_data->email.'</p>';
                        }
                    } else if($linkedTable=="platform_invoice_history") {
                        $modal_title = "Invoice History Details";
                        if($details_data) {
                            $modal_body.= '<p><strong>Invoice Status</strong> : '.$details_data->invoice_status.'</p>';
                            $modal_body.= '<p><strong>Note</strong> :'.$details_data->notes.'</p>';
                            $modal_body.= '<p><strong>Status</strong> : '.$details_data->status.'</p>';
                            $modal_body.= '<p><strong>Customer Type</strong> : '.$details_data->type.'</p>';
                            $modal_body.= '<p><strong>Created At</strong> : '.$details_data->created_at.'</p>';
                        }
                    } else {
                        $modal_body = "Currently detail view not available for ".$linkedTable;
                    }

                    //get details for 
                    $data['status_code'] = 1;
                    $data['title'] = $modal_title;
                    $data['body'] = $modal_body;

                } else {
                    //return back with error msg please update events with linked table data
                    $data['status_code'] = 0;
                    $data['title'] = '';
                    $data['body'] = "Please update Event & their linked table.";
                    return json_encode($data);
                }

            } else {
                $data['status_code'] = 0;
                $data['title'] = $modal_title;
                $data['body'] = "please update events with linked table data";
            }

            return json_encode($data);

        } catch (\Exception $e) {
            $data['status_code'] = 0;
            $data['title'] = $modal_title;
            $data['body'] = $e->getMessage();
            return json_encode($data);
        }
    }

    public function getLinkedIdDetail($linked_id)
    {
        return $response = DB::table('platform_order')->select('api_order_id', 'order_number')->where(['id' => $linked_id])->first();
    }

    public function getErrorLogResponse($user_id, $user_workflow_rule_id, $record_id)
    {
        return $response = DB::table('sync_logs')->select('response')->where(['user_id' => $user_id, 'user_workflow_rule_id' => $user_workflow_rule_id, 'record_id' => $record_id])->first();
    }
    //Manage user workflows status using switch on/off
    public function updateIntegrationFlow(Request $request)
    {
        //clear Cache on flow on-off
        $this->cache->clearAllCacheForIntegration($request->userIntegId);

        // to check whether both account is in connected state before turning ON any flow of given user_integration_id.
        $both_acc_connected = DB::table('user_integrations')
            ->where('id', $request->userIntegId)->whereNotNull('selected_sc_account_id')->whereNotNull('selected_dc_account_id')
            ->first();
        if (!$both_acc_connected) {
            $data['status_code'] = 0;
            $data['status_text'] = "Please connect both account before turning ON any flow.";
            return json_encode($data);
        }

        $userId = \Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id.
        $findUserWFS = DB::table('user_workflow_rule')
            ->where('user_integration_id', $request->userIntegId)
            ->where('platform_workflow_rule_id', $request->pfwfrID)
            ->first();
        if (!$findUserWFS) {
            //insert
            DB::table('user_workflow_rule')->insert([
                'user_id' => $userId,
                'user_integration_id' => $request->userIntegId,
                'platform_workflow_rule_id' => $request->pfwfrID,
                'status' => $request->status
            ]);

            $data['status_code'] = 1;

        } else {

            $affected = DB::table('user_workflow_rule')
                ->where('user_integration_id', $request->userIntegId)
                ->where('platform_workflow_rule_id', $request->pfwfrID)
                ->update(['status' => $request->status]);

            if ($affected) {
                $data['status_code'] = 1;
                // return json_encode($data);
            }
        }

        //log for history
        if( $both_acc_connected ) {

            $getFlowData = DB::table('platform_workflow_rule as pfwfr')
            ->join('platform_events as sourceEvent','sourceEvent.id','pfwfr.source_event_id')
            ->join('platform_events as destEvent','destEvent.id','pfwfr.destination_event_id')
            ->select('sourceEvent.event_description as sourceEvent','destEvent.event_description as destEvent')
            ->where('pfwfr.id',$request->pfwfrID)->first();


            //Log in history
            $action = 'Flow ON/OFF Trigger';
            $action_by = Auth::user()->id;
            $old_log_data = [];
            $new_log_data = [];

            $flow_name = "";
            if($getFlowData) {
                $flow_name = $getFlowData->sourceEvent ." <->". $getFlowData->destEvent;
            }

            $old_log_data['description'] = $flow_name;
            $new_log_data['description'] = $flow_name;

            if($request->status==1) {
                $old_log_data['trigger_type'] = "Flow OFF";
                $new_log_data['trigger_type'] = "Flow ON";
            } else {
                $old_log_data['trigger_type'] = "Flow ON";
                $new_log_data['trigger_type'] = "Flow OFF";
            }

            History::insert([ 'action'=>$action,'action_by'=>$action_by,'user_integration_id'=>$request->userIntegId,'old_data'=>json_encode($old_log_data),'new_data' => json_encode($new_log_data),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=> date('Y-m-d H:i:s')]);

        }
        //end


        return json_encode($data);

    }
    //save connection data before getMappingFields | case : connection-settings - Refresh Mapping values
    public function saveIntegrationAC($userIntegId, $sourcePlt, $destPlt, $userId)
    {
        $parentInfo = $this->getParentInfo($userIntegId, $userId, $sourcePlt, $destPlt);
        $res =  DB::table('user_integrations')->where('user_id', $userId)->where('id', $userIntegId)->update([
            'selected_sc_account_id' => $sourcePlt,
            'selected_dc_account_id' => $destPlt,
            'parent_integration_id' =>  isset($parentInfo['parentIngId']) ? $parentInfo['parentIngId'] : null,
            'shared_platform_id' =>  isset($parentInfo['platformIds']) ? $parentInfo['platformIds'] : null,
        ]);
    }
    //get mapping UI with data
    public function getMappingFields(Request $request)
    {
        try {
            $iconPath = env('CONTENT_SERVER_PATH') . "/public/esb_asset/icons";
            $userId = \Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id.
            $userIntegId = $request['userIntegId'];

            $mappingActionType = $request['mappingActionType'];
            if ($request->isConnectionPage) {
                $source_acc = $request->sc;
                $dest_acc = $request->dc;

                $active_flow = DB::table('user_integrations')->select('id')
                    ->where(['workflow_status' => 'active', 'selected_sc_account_id' => $source_acc, 'selected_dc_account_id' => $dest_acc])
                    ->first();

                /* // commented this because it is using trading partners which are having same account connections for multiple trading partners
                if ($active_flow) {
                    return response()->json(['status_code' => 0, 'status_text' => 'Given account details are already in use on other flow, Try with other details.']);
                }*/
                $res = $this->saveIntegrationAC($request->userIntegId, $request->sc, $request->dc, $userId);
            }

            //get platform Work flow IDs by userInteg ID
            $userIntegData = DB::table('user_integrations')->find($userIntegId);
            if ($userIntegData) {
                $platform_integration_id = $userIntegData->platform_integration_id;
            } else {
                $platform_integration_id = null;
                return response()->json([
                    'status_code' => 0, 'status_text' => 'User Integration Not Found contact your admin',
                ]);
            }

            //Insert of update User work flow rule....on every refresh mapping
            $pltWFRids = DB::table('platform_workflow_rule')->where('platform_integration_id', $platform_integration_id)->pluck('id');
            if (count($pltWFRids) > 0) {
                foreach ($pltWFRids as $flowID) {
                    $findUserWFS = DB::table('user_workflow_rule')
                        ->where('user_integration_id', $userIntegId)
                        ->where('platform_workflow_rule_id', $flowID)
                        // ->whereIn('user_id', $userId)
                        ->first();
                    if (!$findUserWFS) {
                        DB::table('user_workflow_rule')->insert([
                            'user_id' => $userId,
                            'user_integration_id' => $userIntegId,
                            'platform_workflow_rule_id' => $flowID,
                            'status' => 0
                        ]);
                    }
                }
            }

            //get Rules from Platform Integration
            $dataPltInteg = DB::table('platform_integrations as pi')
                ->join('platform_lookup as pl', 'pl.id', 'pi.source_platform_id')
                ->join('platform_lookup as pl2', 'pl2.id', 'pi.destination_platform_id')
                ->select('pi.rule', 'pl.platform_name as sourcePltformId', 'pl2.platform_name as destPlatformId', 'pi.data_retention_status as integ_drp_status', 'pi.data_retention_period as integ_drp_period')
                ->where('pi.id', $platform_integration_id)->first();
            $intgLevelSourcePlt = $dataPltInteg->sourcePltformId;
            $intgLevelDestPlt = $dataPltInteg->destPlatformId;
            $platIntgRule = $dataPltInteg->rule;

            $selctObjArr = [];
            $allSelctObjArr = [];
            /* decode mapping rule & seperate source & destination  */
            $platIntgRule == "" ? $platIntgRule = "{}" : $platIntgRule;
            $decodedRule = json_decode($platIntgRule, TRUE);
            if ($decodedRule) {
                $wfrId = "";
                foreach ($decodedRule as $key => $value) {
                    $wfrId = $key;

                    if (array_key_exists('source', $decodedRule[$key])) {
                        foreach ($decodedRule[$key]['source'] as $sKey => $sVal) {
                            $si = 0;
                            foreach ($decodedRule[$key]['source'][$sKey] as $childSKey => $childSVal) {
                                if ($si == 0) {
                                    array_push($selctObjArr, $childSKey);
                                    array_push($allSelctObjArr, $childSKey . '-' . $wfrId);
                                    $si = 1;
                                }
                            }
                        }
                    }
                    if (array_key_exists('destination', $decodedRule[$key])) {
                        foreach ($decodedRule[$key]['destination'] as $dKey => $dVal) {
                            $di = 0;
                            foreach ($decodedRule[$key]['destination'][$dKey] as $childDKey => $childDVal) {
                                if ($di == 0) {
                                    array_push($selctObjArr, $childDKey);
                                    array_push($allSelctObjArr, $childDKey . '-' . $wfrId);
                                    $di = 1;
                                }
                            }
                        }
                    }
                }
            } else {
                return response()->json([
                    'status_code' => 0, 'status_text' => 'There is some thing went wrong! Please contact your administrator for mapping',
                ]);
            }


            /* allRequiredObj  = Get All Unique object with there linked & store with record to keep in array to avoid db call */
            $allRequiredObj = DB::table('platform_objects')->where('status', 1)->whereIn('name', array_unique($selctObjArr))
                ->select('id', 'name', 'linked_with', 'store_with', 'display_name', 'linked_table')->get();

            foreach ($allRequiredObj as $keyARO => $valueARO) {
                $allRequiredObj[$keyARO]->linked_with_id = "";
                $allRequiredObj[$keyARO]->store_with_id = "";

                $allRequiredObj[$keyARO]->display_name = $allRequiredObj[$keyARO]->display_name;
                if ($allRequiredObj[$keyARO]->linked_with) {
                    //getLinkedWithID & append to allRequiredObj
                    $linkWithData = DB::table('platform_objects')->where('name', $allRequiredObj[$keyARO]->linked_with)->select('id')->first();
                    $allRequiredObj[$keyARO]->linked_with_id = $linkWithData->id;
                }
                //if linked with not added set self as linked with
                else {
                    $allRequiredObj[$keyARO]->linked_with = $allRequiredObj[$keyARO]->name;
                    $allRequiredObj[$keyARO]->linked_with_id = $allRequiredObj[$keyARO]->id;
                }

                if ($allRequiredObj[$keyARO]->store_with) {
                    //get store with object & append to allRequiredObj
                    $storeWithData = DB::table('platform_objects')->where('name', $allRequiredObj[$keyARO]->store_with)->select('id')->first();
                    $allRequiredObj[$keyARO]->store_with_id = $storeWithData->id;
                }
            }

            /* pass allRequiredObj to mapping helper for store in array */
            $this->objMapHelp->getObjectList($allRequiredObj);



            /* Store All selected mapping object not only unique for making UI /object formate :  mappingobjname-pltIntgId  */
            $RuleObjects = $allSelctObjArr;

            $RulesCollection = [];
            $countAditional = 0;
            $specialMappingCount = 0;
            $mapWithOther = [];

            //selected
            $loadAlMappingObjects = [];

            //store more default field mapping values here to set up dynamic container for this
            $dynamic_default_mapping_fieldset = [];

            //get mapping rule on-off status,validation & labels from mappingRule
            foreach ($RuleObjects as $objItem) {

                $imploded_objItem = "";
                $imploded_pwfId = "";
                $objNdWF_arr = explode('-', $objItem);
                if (isset($objNdWF_arr[0]) && isset($objNdWF_arr[1])) {
                    $imploded_objItem = $objNdWF_arr[0];
                    $imploded_pwfId = $objNdWF_arr[1];
                }

                //passed single imploded wfId insted $listWF
                $ruleStatusData = $this->objMappingRules->getRuleStatusByIntegration($decodedRule, $imploded_pwfId, $imploded_objItem);


                if ($ruleStatusData['status_code'] == 0) {
                    return response()->json([
                        'mappingContents' => '', 'status_code' => $ruleStatusData['status_code'], 'status_text' => $ruleStatusData['status_text'],
                    ]);
                }
                $SourceRule = $ruleStatusData['sRule'];
                $destRule  = $ruleStatusData['dRule'];
                $sValidation = $ruleStatusData['sValidation'];
                $dValidation = $ruleStatusData['dValidation'];
                $source_input_type = $ruleStatusData['source_input_type'];
                $dest_input_type = $ruleStatusData['dest_input_type'];
                $slabel = $ruleStatusData['slabel'];
                $dlabel = $ruleStatusData['dlabel'];
                $pltWFR = $ruleStatusData['pltWFR'];
                $map_with = $ruleStatusData['map_with'];
                $slinkedTable = $ruleStatusData['slinkedTable'];
                $dlinkedTable = $ruleStatusData['dlinkedTable'];
                $sfieldsetLabel = $ruleStatusData['sfieldsetLabel'];
                $dfieldsetLabel = $ruleStatusData['dfieldsetLabel'];
                $sfilterColumn = $ruleStatusData['sfilterColumn'];
                $dfilterColumn = $ruleStatusData['dfilterColumn'];
                $stooltipText = $ruleStatusData['stooltipText'];
                $dtooltipText = $ruleStatusData['dtooltipText'];
                $slabelTooltip = $ruleStatusData['slabelTooltip'];
                $dlabelTooltip = $ruleStatusData['dlabelTooltip'];

                $sfieldsetName = $ruleStatusData['sfieldsetName'];
                $dfieldsetName = $ruleStatusData['dfieldsetName'];

                $sfieldsetNameTooltip = $ruleStatusData['sfieldsetNameTooltip'];
                $dfieldsetNameTooltip = $ruleStatusData['dfieldsetNameTooltip'];

                //load all mapping once
                $sloadAllStatus = $ruleStatusData['sloadAllStatus'];
                $dloadAllStatus = $ruleStatusData['dloadAllStatus'];

                //load all mapping once
                $suniqueMappingCheck = $ruleStatusData['suniqueMappingCheck'];
                $duniqueMappingCheck = $ruleStatusData['duniqueMappingCheck'];

                //setup dynamic container for default field
                if($sfieldsetName !="" && $sfieldsetName != null) {

                    $sfieldsetNameLabel = $sfieldsetName;
                    //formate fieldset label text for create dynamic variable
                    $sfieldsetName = str_replace( array( '\'', '"', ',' , ';', '<', '>','-', ' ', '_' ), '', $sfieldsetName);
                    $sfieldsetName = strtolower($sfieldsetName);

                    if(!isset($dynamic_default_mapping_fieldset[$sfieldsetName])) {
                        $dynamic_default_mapping_fieldset[$sfieldsetName]['label'] = $sfieldsetNameLabel;
                        $dynamic_default_mapping_fieldset[$sfieldsetName]['tooltip'] = $sfieldsetNameTooltip;
                    }

                } else if($dfieldsetName !="" && $dfieldsetName != null) {

                    $dfieldsetNameLabel = $dfieldsetName;
                    //formate fieldset label text for create dynamic variable
                    $dfieldsetName = str_replace( array( '\'', '"', ',' , ';', '<', '>','-', ' ', '_' ), '', $dfieldsetName);
                    $dfieldsetName = strtolower($dfieldsetName);

                    if(!isset($dynamic_default_mapping_fieldset[$dfieldsetName])) {
                        $dynamic_default_mapping_fieldset[$dfieldsetName]['label'] = $dfieldsetNameLabel;
                        $dynamic_default_mapping_fieldset[$dfieldsetName]['tooltip'] = $dfieldsetNameTooltip;
                    }

                }

                if ($source_input_type == "multiselect" || $dest_input_type == "multiselect") {

                    if ($imploded_objItem == "default_inventory_warehouse_ms" || $imploded_objItem == "default_order_warehouse") {
                        $countAditional = $countAditional;
                    } else {
                        $countAditional = $countAditional + 1;
                    }
                } else if ($source_input_type == "datetime" || $dest_input_type == "datetime") {
                    $countAditional = $countAditional + 1;
                }
                //if rules has map with && $sloadAllStatus !=1 && $dloadAllStatus !=1
                if ($map_with !== "" && $sloadAllStatus !=1 && $dloadAllStatus !=1 ) {
                    array_push($mapWithOther, $map_with . '-' . $imploded_pwfId);
                }

                $RulesCollection += [$objItem => [
                    'SourceRule' => $SourceRule, 'destRule' => $destRule, 'sValidation' => $sValidation, 'dValidation' => $dValidation,
                    'source_input_type' => $source_input_type, 'dest_input_type' => $dest_input_type, 'slabel' => $slabel, 'dlabel' => $dlabel, 'pltWFR' => $pltWFR, 'map_with' => $map_with, 'slinkedTable' => $slinkedTable, 'dlinkedTable' => $dlinkedTable,
                    'sfieldsetLabel' => $sfieldsetLabel, 'dfieldsetLabel' => $dfieldsetLabel, 'sfilterColumn' => $sfilterColumn,
                    'dfilterColumn' => $dfilterColumn, 'stooltipText' => $stooltipText, 'dtooltipText' => $dtooltipText, 'slabelTooltip' => $slabelTooltip, 'dlabelTooltip' => $dlabelTooltip, 'sfieldsetName' => $sfieldsetName, 'dfieldsetName' => $dfieldsetName, 'sfieldsetNameTooltip' => $sfieldsetNameTooltip, 'dfieldsetNameTooltip' => $dfieldsetNameTooltip, 'sloadAllStatus' => $sloadAllStatus, 'dloadAllStatus' => $dloadAllStatus, 'suniqueMappingCheck' => $suniqueMappingCheck,'duniqueMappingCheck' => $duniqueMappingCheck
                ]];
            }


            //Start Dynamic Fieldset for mapping with cross object ..ex warehouse to location
            $uniqMapWithOther = array_unique($mapWithOther);
            if ($uniqMapWithOther > 0) {
                foreach ($uniqMapWithOther as $mapWithStr) {

                    $DataDynF = explode("-", $mapWithStr);
                    $dynF = $DataDynF[0];

                    $mapWithObjects = explode("_TO_", $dynF);

                    $sourceObjDN = $this->objMapHelp->dynamicMappingLabel('ON', 'ON', $mapWithObjects[0]);
                    $destObjDN = $this->objMapHelp->dynamicMappingLabel('ON', 'ON', $mapWithObjects[1]);

                    //set fieldset label for cross object mapping
                    if (array_key_exists('dfieldsetLabel', $RulesCollection[$mapWithObjects[1] . '-' . $DataDynF[1]])) {
                        if ($RulesCollection[$mapWithObjects[1] . '-' . $DataDynF[1]]['dfieldsetLabel']) {
                            $fieldsetLabelText = $RulesCollection[$mapWithObjects[1] . '-' . $DataDynF[1]]['dfieldsetLabel'];
                        } else {
                            $fieldsetLabelText = 'Manage ' . $intgLevelSourcePlt . ' ' . str_replace("Default", "", $sourceObjDN) . ' <-> ' . $intgLevelDestPlt . ' ' . str_replace("Default", "", $destObjDN) . ' Mapping';
                        }
                    } else {
                        $fieldsetLabelText = 'Manage ' . $intgLevelSourcePlt . ' ' . str_replace("Default", "", $sourceObjDN) . ' <-> ' . $intgLevelDestPlt . ' ' . str_replace("Default", "", $destObjDN) . ' Mapping';
                    }


                    //set tooltip label for cross object mapping
                    if (array_key_exists('dtooltipText', $RulesCollection[$mapWithObjects[1] . '-' . $DataDynF[1]])) {
                        if ($RulesCollection[$mapWithObjects[1] . '-' . $DataDynF[1]]['dtooltipText']) {
                            $tooltipText = $RulesCollection[$mapWithObjects[1] . '-' . $DataDynF[1]]['dtooltipText'];
                        } else {
                            $tooltipText = 'Manage ' . $sourceObjDN . ' To ' . $destObjDN . ' Mapping';
                        }
                    } else {
                        $tooltipText = 'Manage ' . $sourceObjDN . ' To ' . $destObjDN . ' Mapping';
                    }

                    ${$dynF . '_Count'} = 0;
                    ${$dynF} = '<fieldset class="" style="border:1px solid #999999;padding:20px;width:100%;margin-bottom:20px;padding-bottom:10px;"><legend style="font-size:15px" style="padding: 0 5px 0 1px;">&nbsp;' . $fieldsetLabelText . '</legend>
                    <p class="bullhornTooltipText" style="margin-top:-25px !important;margin-left:-10px"><i class="fa fa-bullhorn" aria-hidden="true"></i> ' . $tooltipText . '</p><div class="col-md-12 ' . $dynF . '_dynemic" style="align-items: center;text-align: center !important;"><div class="row ' . $dynF . '">';
                }
            }


            $status_code = "1";
            $status_text = "";
            $fmSectionCount = 0;
            $identityMappingContainer = "";
            $extensiveidentityMappingContainer = "";

            $CommonMapElemContainer = "";
            $CommonMapElemContainerAditional = "<div style='display:flex;flex-wrap: wrap;justify-content: space-between;'><div class='row' style='display:flex;width:100%;padding: 0px;margin: 0px;justify-content: space-between;'>";

            $defaultMappingContainer = "";
            $defaultMappingContainerCount = 0;

            //new default value fieldset container setup
            if($dynamic_default_mapping_fieldset) {
                foreach( $dynamic_default_mapping_fieldset as $containerName => $value) {

                    $default_fieldset_label = "Choose default values";
                    if($value && $value['label']) {
                        $default_fieldset_label = $value['label'];
                    }
                    $def_fieldset_TooltipText = "Select the below default values";
                    if($value && $value['tooltip']) {
                        $def_fieldset_TooltipText = $value['tooltip'];
                    }

                    ${$containerName} = "";
                    ${$containerName} .= '<fieldset class="" style="border:1px solid #999999;padding:30px;width:100%;margin-bottom:20px;"><legend style="font-size:15px" style="padding: 0 5px 0 1px;">&nbsp;' . $default_fieldset_label . '</legend>
                    <p class="bullhornTooltipText" style=""><i class="fa fa-bullhorn" aria-hidden="true"></i>  ' . $def_fieldset_TooltipText . ' </p><div class="row">';

                }
            }
            //end


            $multiWarehouseSwitch = "";
            $multiWarehouseSwitch_count = 0;
            $SpecialCommonMappingContainer = "";

            //Show ON-OFF button for multi warehouse mapping
            if (($intgLevelSourcePlt == "Brightpearl" && $intgLevelDestPlt == "WooCommerce") || ($intgLevelSourcePlt == "WooCommerce" && $intgLevelDestPlt == "Brightpearl")) {
                //check mapping
                $mh_switch_objId = $this->ConnectionHelper->getObjectId('has_multi_warehouse');

                $countMultiWarehouseMap = $this->checkMultiWarehouseMapping($userIntegId, $mh_switch_objId);
                //if mapping found for has_multi_warehouse
                if ($countMultiWarehouseMap) {
                    $checkedStatus = ($countMultiWarehouseMap->custom_data == 1) ? "checked" : "";
                    $editId = $countMultiWarehouseMap->id;
                } else {
                    $checkedStatus = "";
                    $editId = "";
                }


                $cust_data = "'" . 'clickByUser' . "'";
                $multiWarehouseSwitch .= '<div class="custom-control custom-switch" style="margin-bottom:10px"><input type="checkbox" class="custom-control-input multiWarehouse_switch" id="multiWarehouse_switch" data-userIntegId="' . $userIntegId . '" data-editId ="' . $editId . '" data-pltObjId="' . $mh_switch_objId . '" data-integration="Brightpearl_WooCommerce" onclick="mappingSwitchAction(' . $cust_data . ')" ' . $checkedStatus . '><label class="custom-control-label" for="multiWarehouse_switch">Has Multi Warehouse &nbsp;&nbsp;&nbsp;<i class="fa fa-question-circle" aria-hidden="true" data-bs-toggle="tooltip" data-bs-placement="Right" style="font-size:18px;cursor: pointer;" title="" data-original-title="ON the toggle to allow multi warehouse mapping or OFF to continue with default warehouse mapping"></i></label></div>
                <input type="hidden" value="1" class="multiWarehouse_switch_count">';
            }


            //create default mapping Fieldset
            if (array_key_exists('defaultMapping', $decodedRule)) {
                //set default fieldset label
                if ($decodedRule['defaultMapping']['fieldsetLabel']) {
                    $def_FieldsetLabel = $decodedRule['defaultMapping']['fieldsetLabel'];
                } else {
                    $def_FieldsetLabel = "Choose default values";
                }

                //set default fieldset tooltip
                if ($decodedRule['defaultMapping']['tooltipText']) {
                    $def_TooltipText = $decodedRule['defaultMapping']['tooltipText'];
                } else {
                    $def_TooltipText = "Select the below default values";
                }
            } else {
                $def_FieldsetLabel = "Choose default values";
                $def_TooltipText = "Select the below default values";
            }

            $defaultMappingContainer .= '<fieldset class="" style="border:1px solid #999999;padding:30px;width:100%;margin-bottom:20px;"><legend style="font-size:15px" style="padding: 0 5px 0 1px;">&nbsp;' . $def_FieldsetLabel . '</legend>
            <p class="bullhornTooltipText" style=""><i class="fa fa-bullhorn" aria-hidden="true"></i> ' . $def_TooltipText . '</p><div class="row">';


            $i = 0;
            $IdentityCount = 0;
            $extensive_identityMap = [];
            $validationArr = []; //validationArr for default mapping
            $Many2ManyValidationArr = [];
            $MsValidationArr = [];
            $availSyncStartMap = [];
            $otherMapValidationArr = [];
            $fileMapValidationArr = [];
            $multiFieldMapValidationArr = [];
            $defaultSecItems = "";
            $one2one_multiwarehouseArray = [];
            $default_multiwarehouseArray = [];
            $list_uniqueMappingCheck_object = [];

            //get platform work flow & integration rules
            $PlatformFlowData = $this->objMyappSnip->getPlatformWorkflowData($platform_integration_id);
            foreach ($PlatformFlowData as $item) {

                $pfwfrID = $item->pfwfrID;
                $event_name = $item->event_name;
                $sourceEvent = $item->sourceEvent;
                $destEvent = $item->destEvent;
                $sourceEventID = $item->source_event_id;
                $destEventID = $item->destination_event_id;
                $sourcePlt = $item->sourcePlt;
                $destPlt = $item->destPlt;
                $sourcePltName = $item->sourcePltName;
                $destPltName = $item->destPltName;

                //call api to load mapping data when mapping type refresh
                if ($mappingActionType == "RefreshMapping") {

                    /* Find Event and run for thier sub events */
                    $findEvents = DB::table('platform_events as e')->select('e.id', 'l.platform_id')->join('platform_lookup as l', 'l.id', 'e.platform_id')->whereIn('e.id', [$destEventID, $sourceEventID])->get();

                    if ($findEvents->count() > 0) {
                        foreach ($findEvents as $key => $value) {
                            $ns = $value->platform_id == $destPltName ? $sourcePltName : $value->platform_id;

                            //find sub events for geiven id
                            $findSubEvents = $this->mobj->getResultByConditions('platform_sub_event', ['platform_event_id' => $value->id, 'status' => 1, 'prefetch' => 1], ['id', 'name']);
                            if ($findSubEvents->count() > 0) {

                                foreach ($findSubEvents as $skey => $svalue) {
                                    \Storage::disk('local')->append('quick_error.txt', print_r($svalue, true));
                                    $response_api = $this->workflow->executeEvent('GET', $svalue->name,  $value->platform_id, $userId, $userIntegId, 0, '', $ns, '');
                                    //start insert update user integration sub events for sub event with prefetch 1
                                    if ($response_api && is_bool($response_api)) {
                                        $status = ['status' => 'completed', 'message' => null, 'last_run_time' => date('Y-m-d H:i:s')];
                                    } else {
                                        $status = ['status' => 'failed', 'message' => $response_api, 'last_run_time' => date('Y-m-d H:i:s')];
                                    }

                                    $count_ui_subevent = DB::table('user_integration_sub_event')->where('user_integration_id', $userIntegId)
                                        ->where('sub_event_id', $svalue->id)->count();
                                    if ($count_ui_subevent > 0) {
                                        DB::table('user_integration_sub_event')->where(['user_integration_id' => $userIntegId])
                                            ->where('sub_event_id', $svalue->id)
                                            ->update($status);
                                    } else {
                                        $insert = array_merge($status, ['user_integration_id' => $userIntegId, 'sub_event_id' => $svalue->id]);
                                        $new_user_sub_evt_id = $this->mobj->makeInsertGetId('user_integration_sub_event', $insert);
                                    }
                                    //end insert update user integration sub events for sub event with prefetch 1


                                    if ($response_api && !is_bool($response_api)) {
                                        return response()->json(['status_code' => 0, 'status_text' => $response_api]);
                                    }
                                }
                            }
                        }
                    }

                    //Log in history
                    $action = 'Mapping Refresh';
                    $action_by = Auth::user()->id;
                    $log_data = [];
                    $log_data['trigger_type'] = 'Mapping Refresh';
                    $log_data['description'] = $sourceEvent.' - '.$destEvent;
                    History::insert([ 'action'=>$action,'action_by'=>$action_by,'user_integration_id'=>$userIntegId,'old_data'=>json_encode($log_data),'new_data' => NULL,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=> date('Y-m-d H:i:s')]);
                    //end

                }

                //loop RulesCollection to open selected mapping on rules
                foreach ($RulesCollection as $key => $rule) {
                    //explod mapping objectName & platformWFRid .... this will help to use same map object in diffrent flow many times...
                    $key_with_wfId = $key;
                    $keyNDwfrid = explode('-', $key);
                    $key = $keyNDwfrid[0];
                    $pfwfrIDfromRule = $keyNDwfrid[1];


                    //if platform workflow Match with workFlow of map Rule then run loop to avoid unnesesory checks
                    if ($pfwfrIDfromRule == $pfwfrID) {

                        //make dynamic lable to mapping UI
                        $dynamicLabelText = $this->objMapHelp->dynamicMappingLabel($rule['SourceRule'], $rule['destRule'], $key);

                        //push uniqueMappingCheck in array
                        if(isset($rule['suniqueMappingCheck'])) {
                            array_push($list_uniqueMappingCheck_object,$rule['suniqueMappingCheck']);
                        } else if(isset($rule['duniqueMappingCheck'])) {
                            array_push($list_uniqueMappingCheck_object,$rule['duniqueMappingCheck']);
                        }
                        //end

                        //call identity mapping
                        if (($key == "product_identity") && ($rule['SourceRule'] == "ON" && $rule['destRule'] == "ON") && ($rule['pltWFR'] == $pfwfrID)) {
                            if ($IdentityCount == 0) {
                                $identityMappingContainer .= $this->objMapHelp->getIdentityMapping($pfwfrID, $sourcePlt, $destPlt, $iconPath, $userId, $userIntegId, $rule['SourceRule'], $rule['destRule'], $rule['sValidation'], $rule['dValidation'], $sourcePltName, $destPltName, $rule['slabel'], $rule['dlabel'], $rule['sfilterColumn'], $rule['dfilterColumn'], $rule['dfieldsetLabel'], $rule['dtooltipText']);
                                $IdentityCount = $IdentityCount + 1;
                            }
                        }
                        //call identity mapping extensive - customer_identity/vendor-identity or any thing
                        else if (($key == "customer_identity" || $key == "vendor_identity") && ($rule['SourceRule'] == "ON" && $rule['destRule'] == "ON") && ($rule['pltWFR'] == $pfwfrID)) {
                            if( !in_array($key,$extensive_identityMap) ) {
                                $extensiveidentityMappingContainer .= $this->objMapHelp->getIdentityMappingExtensive($pfwfrID, $sourcePlt, $destPlt, $iconPath, $userId, $userIntegId, $rule['SourceRule'], $rule['destRule'], $rule['sValidation'], $rule['dValidation'], $sourcePltName, $destPltName, $rule['slabel'], $rule['dlabel'], $rule['sfilterColumn'], $rule['dfilterColumn'], $rule['dfieldsetLabel'], $rule['dtooltipText'],$key);
                                //push in extensive_identityMap to avoid ignore multiple mapping
                                array_push($extensive_identityMap,$key);
                            }
                        }

                        //call all other one to one /default/multiselect or custom mappings
                        else {
                            //if both platform have acitve mapping then show one to one always
                            if (($rule['SourceRule'] == "ON" && $rule['destRule'] == "ON") && ($rule['pltWFR'] == $pfwfrID)) {

                                //addition add switch
                                $custObjName = "'" . $key_with_wfId . "'";

                                $fixed_add_field_button = '<br><i class="fa fa-plus-circle addNewFieldButton" aria-hidden="true" onclick="addwhFields(' . $custObjName . ')" style="font-size:25px;margin-top:-95px;margin-right:70px;float:right;cursor:pointer" data-bs-toggle="tooltip" data-bs-placement="Right" title="" data-original-title="Add more row"></i>';
                                //end

                                $source_inputType = $rule['source_input_type'];
                                $dest_inputType = $rule['dest_input_type'];

                                //set fieldset label
                                if ($rule['dfieldsetLabel']) {
                                    $fieldsetLabelText = $rule['dfieldsetLabel'];
                                } else {
                                    $fieldsetLabelText = 'Manage ' . $dynamicLabelText . ' Mapping';
                                }
                                //set tooltip text
                                if ($rule['dtooltipText']) {
                                    $tooltipText = $rule['dtooltipText'];
                                } else {
                                    $tooltipText = 'Map the ' . $dynamicLabelText . ' between the platforms' . "," . ' to be used while syncing Inventory level';
                                }

                            
                                //call only those object which enable load all mapping once
                                if ( $rule['sloadAllStatus']==1 && $rule['dloadAllStatus']==1 )
                                {   
                                    if( !in_array($key,$loadAlMappingObjects) ) {

                                        $x = $this->objMapHelp->loadOpenedRegularMapping($pfwfrID, $sourcePlt, $destPlt, $event_name, $i, $sourceEvent, $destEvent, $userId, $userIntegId,$sourcePltName,$destPltName,$rule['SourceRule'],$rule['destRule'],$rule['sValidation'],$rule['dValidation'],$key,$iconPath,$rule['slabel'], $rule['dlabel']);

                                        $CommonMapElemContainer .= '<fieldset style="border:1px solid #a6a5a8;padding:20px;width:100%;margin-bottom:20px;">
                                        <legend style="font-size:15px"> '.$fieldsetLabelText.'  </legend><p class="bullhornTooltipText"><i class="fa fa-bullhorn" aria-hidden="true"></i> '.$tooltipText.'</p>' . $x['data'] . '</fieldset>';
                                        $fmSectionCount = $fmSectionCount + $x['count'];
                                        $i++;

                                        //push in array
                                        array_push($loadAlMappingObjects,$key);
                                        
                                    }
                                   
                                } else {

                                    $y = $this->objMapHelp->getManytoManyMapping($pfwfrID, $sourcePlt, $destPlt, $event_name, $userId, $userIntegId, $sourcePltName, $destPltName, $rule['SourceRule'], $rule['destRule'], $rule['sValidation'], $rule['dValidation'], $key, 'object', $iconPath, $rule['slabel'], $rule['dlabel'], $source_inputType, $dest_inputType, $rule['sfilterColumn'], $rule['dfilterColumn'], $key_with_wfId);

                                    //show hide on off switch
                                    if ($key == "inventory_warehouse" || $key == "order_warehouse") {
                                        $dyanamicCommonMapCont = "SpecialCommonMappingContainer";

                                        $dynamicClassName = $key . "-" . $pfwfrID . '_dynemic';
                                        array_push($one2one_multiwarehouseArray, $dynamicClassName);
                                    } else {
                                        $dyanamicCommonMapCont = "CommonMapElemContainer";
                                    }

                                    ${$dyanamicCommonMapCont} .= '<fieldset class="' . $key_with_wfId . '_dynemic" style="border:1px solid #999999;padding:20px;width:100%;margin-bottom:20px;padding-bottom:50px;">
                                        <legend style="font-size:15px" style="padding: 0 5px 0 1px;">&nbsp;' . $fieldsetLabelText . '</legend><p class="bullhornTooltipText"><i class="fa fa-bullhorn" aria-hidden="true"></i> ' . $tooltipText . '</p>' . $y['data'] . '</fieldset>';

                                    //push add field button
                                    ${$dyanamicCommonMapCont} .= $fixed_add_field_button;


                                    ${$dyanamicCommonMapCont} .= '<input type="hidden" value="' . $y['count'] . '" id="Mapping' . $key_with_wfId . 'Count" placeholder="Mapping' . $key_with_wfId . 'Count">';

                                    array_push($Many2ManyValidationArr, $key_with_wfId);

                                }

                            }
                            //if only one platform have active mapping then show mapping based on selected input type
                            else if (($rule['SourceRule'] == "ON" || $rule['destRule'] == "ON") && ($rule['pltWFR'] == $pfwfrID)) {
                                //get input type
                                if ($rule['SourceRule'] == "ON") {
                                    $inputType = $rule['source_input_type'];
                                } else if ($rule['destRule'] == "ON") {
                                    $inputType = $rule['dest_input_type'];
                                }
                                //in selected input type is multiselect
                                if ($inputType == "multiselect") {

                                    //add condition for warehouse
                                    if ($key == "default_inventory_warehouse_ms" || $key == "default_order_warehouse") {
                                        $specialMappingCount = $specialMappingCount;
                                    } else {
                                        $specialMappingCount = $specialMappingCount + 1;
                                    }

                                    $dynFieldSetWidth = "col-md-6";
                                    $alignSide = ($specialMappingCount % 2) == 0 ? "padding-right:0px" : "padding-left:0px";
                                    if ($countAditional == $specialMappingCount) {
                                        if (($specialMappingCount % 2) == 0) {
                                            $dynFieldSetWidth = "col-md-6";
                                            $alignSide = "padding-right:0px";
                                        } else {
                                            $dynFieldSetWidth = "col-md-12";
                                            $alignSide = "padding-right:0px;padding-left:0px;";
                                        }
                                    }
                                    //pass has_multi_warehouse id for multiwarehouse
                                    $mh_switch_objId = $this->ConnectionHelper->getObjectId('has_multi_warehouse');

                                    $dataMultiSelectMap = $this->objMapHelp->getMultiSelectMapping($pfwfrID, $sourcePlt, $destPlt, $event_name, $userId, $userIntegId, $sourcePltName, $destPltName, $key, $rule['SourceRule'], $rule['destRule'], $rule['sValidation'], $rule['dValidation'], $rule['slabel'], $rule['dlabel'], $rule['sfilterColumn'], $rule['dfilterColumn'], $rule['slabelTooltip'], $rule['dlabelTooltip'], $key_with_wfId, $mh_switch_objId);

                                    //if source rule on....
                                    if ($SourceRule) {
                                        $foundFieldsetLabel = $rule['sfieldsetLabel'];
                                        $foundTooltipText = $rule['stooltipText'];
                                    } else {
                                        $foundFieldsetLabel = $rule['dfieldsetLabel'];
                                        $foundTooltipText = $rule['dtooltipText'];
                                    }
                                    //set fieldset label
                                    if ($foundFieldsetLabel) {
                                        $fieldsetLabel = $foundFieldsetLabel;
                                    } else {
                                        $fieldsetLabel = $dynamicLabelText . " Sync";
                                    }
                                    //set tooltip text
                                    if ($foundTooltipText) {
                                        $tooltipText = $foundTooltipText;
                                    } else {
                                        $tooltipText = "Select the " . $dataMultiSelectMap['selectedPlt'] . ' ' . $dynamicLabelText . "(s) that will sync with the integration";
                                    }

                                    //if mapping is default warehouse multiselect then show in seperate fieldset or show in common
                                    if ($key == "default_inventory_warehouse_ms" || $key == "default_order_warehouse") {
                                        $dynFieldSetWidth = "col-md-12";
                                        $alignSide = "padding-right:0px;padding-left:0px;";
                                        $dyanamicMScommonMapCont = "SpecialCommonMappingContainer";

                                        $dynamicClassName = $key . "-" . $pfwfrID . '_dynemic';
                                        array_push($default_multiwarehouseArray, $dynamicClassName);
                                    } else {
                                        $dyanamicMScommonMapCont = "CommonMapElemContainerAditional";
                                    }


                                    ${$dyanamicMScommonMapCont} .= '<input type="hidden" value="' . $dataMultiSelectMap['count'] . '" id="Mapping' . $key_with_wfId . 'Count" placeholder="Mapping' . $key_with_wfId . 'Count">';
                                    ${$dyanamicMScommonMapCont} .= '<div class="' . $dynFieldSetWidth . '" style="' . $alignSide . '"><fieldset class="col-md-12 ' . $key_with_wfId . '_dynemic" style="border:1px solid #999999;padding:20px;width:100%;margin-bottom:20px;">
                                            <legend style="font-size:15px" style="padding: 0 5px 0 1px;">&nbsp;' . $fieldsetLabel . '</legend> <p class="bullhornTooltipText"><i class="fa fa-bullhorn" aria-hidden="true"></i> ' . $tooltipText . '</p>' . $dataMultiSelectMap['data'] . '</fieldset></div>';

                                    array_push($MsValidationArr, $key_with_wfId);
                                }
                                //if select input type is datetime
                                else if ($inputType == "datetime") {
                                    $specialMappingCount = $specialMappingCount + 1;
                                    $dynFieldSetWidth = "col-md-6";
                                    $alignSide = ($specialMappingCount % 2) == 0 ? "padding-right:0px" : "padding-left:0px";

                                    if ($countAditional == $specialMappingCount) {
                                        if (($specialMappingCount % 2) == 0) {
                                            $dynFieldSetWidth = "col-md-6";
                                            $alignSide = "padding-right:0px";
                                        } else {
                                            $dynFieldSetWidth = "col-md-12";
                                            $alignSide = "padding-right:0px;padding-left:0px;";
                                        }
                                    }

                                    //if source rule on....
                                    if ($SourceRule) {
                                        $foundFieldsetLabel = $rule['sfieldsetLabel'];
                                        $foundTooltipText = $rule['stooltipText'];
                                    } else {
                                        $foundFieldsetLabel = $rule['dfieldsetLabel'];
                                        $foundTooltipText = $rule['dtooltipText'];
                                    }
                                    //set fieldset label
                                    if ($foundFieldsetLabel) {
                                        $fieldsetLabel = $foundFieldsetLabel;
                                    } else {
                                        $fieldsetLabel = $dynamicLabelText;
                                    }
                                    //set tooltip text
                                    if ($foundTooltipText) {
                                        $tooltipText = $foundTooltipText;
                                    } else {
                                        $tooltipText = "Select the start date and time of the Order Sync. You may only select a past date that is two weeks previous to the current date";
                                    }

                                    $syncStart = $this->objMapHelp->getOrderSyncStart($pfwfrID, $sourcePlt, $destPlt, $event_name, $userId, $userIntegId, $sourcePltName, $destPltName, $rule['SourceRule'], $rule['destRule'], $rule['sValidation'], $rule['dValidation'], $rule['slabel'], $rule['dlabel'], $key, $iconPath, $key_with_wfId);

                                    $CommonMapElemContainerAditional .= '<input type="hidden" value="1" id="Mapping' . $key_with_wfId . 'Count" placeholder="count' . $key_with_wfId . '">';

                                    $CommonMapElemContainerAditional .= '<div class="' . $dynFieldSetWidth . '" style="' . $alignSide . '"><fieldset class="col-md-12 ' . $key_with_wfId . '_dynemic" style="border:1px solid #999999;padding:20px;width:100%;margin-bottom:20px;padding-bottom: 35px;">
                                            <legend style="font-size:15px" style="padding: 0 5px 0 1px;">&nbsp;' . $fieldsetLabel . '</legend><p class="bullhornTooltipText"><i class="fa fa-bullhorn" aria-hidden="true"></i> ' . $tooltipText . '</p>' . $syncStart . '</fieldset></div>';

                                    array_push($availSyncStartMap, $key_with_wfId);
                                }
                                //if selecte input type is file
                                else if ($inputType == "file") {

                                    //if source rule on....
                                    if ($SourceRule) {
                                        $foundFieldsetLabel = $rule['sfieldsetLabel'];
                                        $foundTooltipText = $rule['stooltipText'];
                                    } else {
                                        $foundFieldsetLabel = $rule['dfieldsetLabel'];
                                        $foundTooltipText = $rule['dtooltipText'];
                                    }
                                    //set fieldset label
                                    if ($foundFieldsetLabel) {
                                        $fieldsetLabel = $foundFieldsetLabel;
                                    } else {
                                        $fieldsetLabel = $dynamicLabelText;
                                    }
                                    //set tooltip text
                                    if ($foundTooltipText) {
                                        $tooltipText = $foundTooltipText;
                                    } else {
                                        $tooltipText = "Upload or drag & drop your files here for " . $dynamicLabelText;
                                    }

                                    $dataFileMapping = $this->objMapHelp->fileTypeMapping($pfwfrID, $sourcePlt, $destPlt, $event_name, $userId, $userIntegId, $sourcePltName, $destPltName, $rule['SourceRule'], $rule['destRule'], $rule['sValidation'], $rule['dValidation'], $rule['slabel'], $rule['dlabel'], $key, $rule['slabelTooltip'], $rule['dlabelTooltip'], env('APP_URL'), $key_with_wfId);

                                    $CommonMapElemContainer .= '<fieldset class="col-md-12 ' . $key_with_wfId . '_dynemic" style="border:1px solid #999999;padding:20px;width:100%;margin-bottom:20px;padding-bottom: 35px;">
                                            <legend style="font-size:15px" style="padding: 0 5px 0 1px;">&nbsp;' . $fieldsetLabel . '</legend><p class="bullhornTooltipText"><i class="fa fa-bullhorn" aria-hidden="true"></i> ' . $tooltipText . '</p>' . $dataFileMapping . '</fieldset>';

                                    array_push($fileMapValidationArr, $key_with_wfId);
                                }
                                //if select input type is on-off switch
                                else if ($inputType == "multifield") {
                                    $tooltipText = "";
                                    if ($key == "full_inventory_sync") {
                                        $tooltipText = "Set the frequency and run time of the Full Inventory Sync";
                                    }

                                    $dataMultiField = $this->objMapHelp->loadMappingWithMultiFields($pfwfrID, $sourcePlt, $destPlt, $event_name, $userId, $userIntegId, $sourcePltName, $destPltName, $rule['SourceRule'], $rule['destRule'], $rule['sValidation'], $rule['dValidation'], $rule['slabel'], $rule['dlabel'], $key, $iconPath, $key_with_wfId);

                                    $CommonMapElemContainer .= '<fieldset class="' . $key_with_wfId . '_dynemic" style="border:1px solid #999999;padding:20px;width:100%;margin-bottom:20px;">
                                    <legend style="font-size:15px" style="padding: 0 5px 0 1px;">&nbsp;' . $dynamicLabelText . '</legend><p class="bullhornTooltipText"><i class="fa fa-bullhorn" aria-hidden="true"></i> ' . $tooltipText . '</p>' . $dataMultiField . '</fieldset>';
                                    array_push($multiFieldMapValidationArr, $key_with_wfId);
                                }
                                //if selected input type is selectlist/number/text
                                else {
                                    //if mapping with other object
                                    if ($rule['map_with'] !== "") {

                                        //call only those object which enable load all mapping once
                                        if ( ($rule['sloadAllStatus']==1 || $rule['dloadAllStatus']==1) )
                                        {   
                                           //load only for first cross mapping object
                                            if( !in_array($rule['map_with'],$loadAlMappingObjects) ) {
                                                $prodname_arr = explode("_TO_", $rule['map_with']);
                                                if (isset($prodname_arr[0]) && isset($prodname_arr[1])) {

                                                    $source_pobjName = $this->objMapHelp->dynamicMappingLabel($rule['SourceRule'], $rule['destRule'], $prodname_arr[0]);
                                                    $dest_pobjName = $this->objMapHelp->dynamicMappingLabel($rule['SourceRule'], $rule['destRule'], $prodname_arr[1]);

                                                    //set fieldset label
                                                    if ($rule['sfieldsetLabel']) {
                                                        $fieldsetLabelText = $rule['sfieldsetLabel'];
                                                    } else {
                                                        $fieldsetLabelText = 'Manage ' . $sourcePltName . ' ' . str_replace("Default", "", $source_pobjName) . ' <-> ' . $destPltName . ' ' . str_replace("Default", "", $dest_pobjName) . ' Mapping';
                                                    }
                                                    //set tooltip text
                                                    if ($rule['stooltipText']) {
                                                        $tooltipText = $rule['stooltipText'];
                                                    } else {
                                                        $tooltipText = 'Manage ' . str_replace("Default", "", $source_pobjName) . ' To ' . str_replace("Default", "", $dest_pobjName) . ' Mapping';
                                                    }


                                                    $x = $this->objMapHelp->loadOpenedRegularMapping($pfwfrID, $sourcePlt, $destPlt, $event_name, $i, $sourceEvent, $destEvent, $userId, $userIntegId,$sourcePltName,$destPltName,$rule['SourceRule'],$rule['destRule'],$rule['sValidation'],$rule['dValidation'],$rule['map_with'],$iconPath,$rule['slabel'], $rule['dlabel']);

                                                    //need to update fieldset label & tooltip
                                                    $CommonMapElemContainer .= '<fieldset style="border:1px solid #a6a5a8;padding:20px;width:100%;margin-bottom:20px;">
                                                    <legend style="font-size:15px"> '.$fieldsetLabelText.'  </legend><p class="bullhornTooltipText"><i class="fa fa-bullhorn" aria-hidden="true"></i> '.$tooltipText.'</p>' . $x['data'] . '</fieldset>';
                                                    $fmSectionCount = $fmSectionCount + $x['count'];
                                                    $i++;

                                                    //push in array
                                                    array_push($loadAlMappingObjects,$rule['map_with']);
                                                    

                                                }
                                            }
                                            
                                            

                                        } else {

                                           
                                            $mapWithObjects = explode("_TO_", $rule['map_with']);
                                                $getOsData = $this->objMapHelp->getPlatformObjectId($mapWithObjects[0]);
                                                $new_platform_object_id = ($getOsData->store_with_id) ? $getOsData->store_with_id : $getOsData->id;

                                                $mappingDataArr = PlatformDataMapping::where('platform_object_id', $new_platform_object_id)
                                                    ->where('user_integration_id', $userIntegId)
                                                    ->where('platform_workflow_rule_id', $pfwfrID)->where('mapping_type', 'cross')->get();

                                                //Load store mapping data for other <-> other
                                                if (count($mappingDataArr) > 0) {

                                                    if (${$rule['map_with'] . '_Count'} < 1) {
                                                        $mappingData = $this->objMapHelp->loadDefaultCrossMapStored($pfwfrID, $sourcePlt, $destPlt, $userId, $userIntegId, $sourcePltName, $destPltName, $rule['SourceRule'], $rule['destRule'], $rule['sValidation'], $rule['dValidation'], $key, 'object', $rule['slabel'], $rule['dlabel'], $rule['map_with'], ${$rule['map_with'] . '_Count'}, $iconPath, $mappingDataArr, $inputType, $rule['sfilterColumn'], $rule['dfilterColumn'], $key_with_wfId);

                                                        ${$rule['map_with'] . '_Count'} = ${$rule['map_with'] . '_Count'} + 1;

                                                        ${$rule['map_with']} .= $mappingData['data'];

                                                        ${$rule['map_with']} .= '<input type="hidden" value="' . count($mappingDataArr) . '" id="Mapping' . $rule['map_with'] . 'Count">';


                                                        array_push($otherMapValidationArr, $rule['map_with']);


                                                    }
                                                }
                                                //Load Fresh other <-> other mapping Ex. warehouse to location
                                                else {

                                                    $mappingData = $this->objMapHelp->mappingWithOther($pfwfrID, $sourcePlt, $destPlt, $userId, $userIntegId, $sourcePltName, $destPltName, $rule['SourceRule'], $rule['destRule'], $rule['sValidation'], $rule['dValidation'], $key, 'object', $rule['slabel'], $rule['dlabel'], $rule['map_with'], ${$rule['map_with'] . '_Count'}, $iconPath, $inputType, $rule['sfilterColumn'], $rule['dfilterColumn'], $key_with_wfId);


                                                    ${$rule['map_with'] . '_Count'} = ${$rule['map_with'] . '_Count'} + 1;
                                                    ${$rule['map_with']} .= $mappingData['data'];
                                                    if (${$rule['map_with'] . '_Count'} == 1) {
                                                        ${$rule['map_with']} .= '<div class="col-md-1" style="margin-top:40px"><img src="' . $iconPath . '/repeat.svg"  alt="icon"></div>';
                                                    }

                                                    ${$rule['map_with']} .= '<input type="hidden" value="' . ${$rule['map_with'] . '_Count'} . '" id="Mapping' . $rule['map_with'] . 'Count">';



                                                    if (${$rule['map_with'] . '_Count'} > 1) {
                                                        array_push($otherMapValidationArr, $rule['map_with']);
                                                        //end col-md-12 which start in source side ...
                                                        ${$rule['map_with']} .= '</div>';
                                                    }
                                                }
                                                
                                        }
                                
                                    }
                                    //if mapping available only in one platform with no mapping with (show as default mappings)
                                    else {
                                        $mappingData = $this->objMapHelp->makeMappingObjectHtml(
                                            $pfwfrID,
                                            $sourcePlt,
                                            $destPlt,
                                            $event_name,
                                            $userId,
                                            $userIntegId,
                                            $sourcePltName,
                                            $destPltName,
                                            $rule['SourceRule'],
                                            $rule['destRule'],
                                            $rule['sValidation'],
                                            $rule['dValidation'],
                                            $key,
                                            'object',
                                            $rule['source_input_type'],
                                            $rule['dest_input_type'],
                                            $rule['slabel'],
                                            $rule['dlabel'],
                                            $rule['sfilterColumn'],
                                            $rule['dfilterColumn'],
                                            $rule['slabelTooltip'],
                                            $rule['dlabelTooltip'],
                                            $key_with_wfId
                                        );

                                        array_push($validationArr, $key_with_wfId);
                                        $defaultMappingContainer .= '<input type="hidden" value="1" id="count' . $key_with_wfId . '" placeholder="count' . $key_with_wfId . '">';

                                        if ($key == "warehouse_plugins") {
                                            $multiWarehouseSwitch .= $mappingData['data'];
                                        } else {

                                            if( $rule['sfieldsetName'] ) {
                                                ${$rule['sfieldsetName']} .= $mappingData['data'];
                                            } else  if( $rule['dfieldsetName'] ) {
                                                ${$rule['dfieldsetName']} .= $mappingData['data'];
                                            } else {
                                                $defaultMappingContainer .= $mappingData['data'];
                                            }


                                            $defaultMappingContainerCount = 1;
                                        }


                                        $defaultSecItems .= $mappingData['selMapLabel'] . ', ';
                                    }
                                }
                            }
                        }

                    }
                }
            }

            //end workflow loop





            //close all dynamic fieldset for mapping_with_others which started at the beginning
            if ($uniqMapWithOther > 0) {
                foreach ($uniqMapWithOther as $mapWithStr) {
                    $DataDynF = explode("-", $mapWithStr);
                    $dynF = $DataDynF[0];

                    //addition add switch
                    $custObjName = "'" . $dynF . "'";
                    if($dynF=="inventory_warehouse_TO_ip_s3_access_path") {
                        $fixed_add_field_button1 = '<i class="fa fa-plus-circle" aria-hidden="true" onclick="addOtherMap('.$custObjName.')" style="font-size:25px;margin-right:35px;float:right;cursor:pointer" data-bs-toggle="tooltip" data-bs-placement="Right" title="" data-original-title="Add more row"></i><button type="button" class="btn btn-info btn-sm generateApplicationUrl" style="float:right;margin-right:20px;"><i class="fa fa-link" aria-hidden="true"></i> Generate Url</button>';
                    } else {
                        $fixed_add_field_button1 = '<i class="fa fa-plus-circle" aria-hidden="true" onclick="addOtherMap('.$custObjName.')" style="font-size:25px;margin-right:35px;float:right;cursor:pointer" data-bs-toggle="tooltip" data-bs-placement="Right" title="" data-original-title="Add more row"></i>';
                    }
                    
                    //end
                    ${$dynF} .= "</div></div>".$fixed_add_field_button1."</fieldset>";

                    
                    

                }
            }

            ////new default value fieldset close
            if($dynamic_default_mapping_fieldset) {
                foreach( $dynamic_default_mapping_fieldset as $containerName => $value ) {
                    ${$containerName} .= '</div></fieldset>';
                }
            }

            //close default mapping fieldset
            $defaultMappingContainer .= '</div></fieldset>';

            $mappingContents = "";
            //one to one mapping validation data send to ui
            $mappingContents .= '<textarea rows="4" cols="50" id="validationArray" style="display:none;">' . json_encode($validationArr) . '</textarea>';
            //many to many mapping validation data send to ui
            $mappingContents .= '<textarea rows="4" cols="50" id="Many2ManyValidationArr" style="display:none;">' . json_encode($Many2ManyValidationArr) . '</textarea>';
            //multi select mapping validation data send to ui
            $mappingContents .= '<textarea rows="4" cols="50" id="MsValidationArr" style="display:none;">' . json_encode($MsValidationArr) . '</textarea>';
            $mappingContents .= '<textarea rows="4" cols="50" id="ssValidationArr" style="display:none;">' . json_encode($availSyncStartMap) . '</textarea>';
            $mappingContents .= '<textarea rows="4" cols="50" id="otherMapValidationArr" style="display:none;">' . json_encode($otherMapValidationArr) . '</textarea>';
            $mappingContents .= '<textarea rows="4" cols="50" id="fileMapValidationArr" style="display:none;">' . json_encode($fileMapValidationArr) . '</textarea>';
            $mappingContents .= '<textarea rows="4" cols="50" id="multiFieldMapValidationArr" style="display:none;">' . json_encode($multiFieldMapValidationArr) . '</textarea>';
            $mappingContents .= '<input type="hidden" id="defaultSecItems" value="' . $defaultSecItems . '">';

            //total fild mapping section send to ui
            $mappingContents .= '<input type="hidden" value="' . $fmSectionCount . '" id="TotalFieldMappingSection" placeholder="TotalFieldMappingSection">';
            $mappingContents .= $identityMappingContainer;
            //push identity mapping list in array
            $mappingContents .= $extensiveidentityMappingContainer;
            $mappingContents .= '<textarea rows="4" cols="50" id="list_extensive_identity_mapping" style="display:none;">' . json_encode($extensive_identityMap) . '</textarea>';


            $mappingContents .= '<textarea rows="4" cols="50" id="default_multiwarehouseArray" style="display:none;">' . json_encode($default_multiwarehouseArray) . '</textarea>';
            $mappingContents .= '<textarea rows="4" cols="50" id="one2one_multiwarehouseArray" style="display:none;">' . json_encode($one2one_multiwarehouseArray) . '</textarea>';

            //Include all dynamic fieldset for mapping with others in mapping Contents
            if ($uniqMapWithOther > 0) {
                foreach ($uniqMapWithOther as $mapWithStr) {
                    $DataDynF = explode("-", $mapWithStr);
                    $dynF = $DataDynF[0];
                    $mappingContents .= ${$dynF};
                }
            }
            //SpecialCommonMappingContainer for showing warehouse
            $mappingContents .= $multiWarehouseSwitch;
            $mappingContents .= $SpecialCommonMappingContainer;

            $mappingContents .= $CommonMapElemContainer;
            //default mapping container for all one sided mapping
            if ($defaultMappingContainerCount == 1) {
                $mappingContents .= $defaultMappingContainer;
                //new default value fieldset include
                if($dynamic_default_mapping_fieldset) {
                    foreach( $dynamic_default_mapping_fieldset as $containerName => $value ) {
                        $mappingContents .= ${$containerName};
                    }
                }
            }

            //CommonMapElemContainerAditional for multiselect & sync start date type mapping
            $mappingContents .= $CommonMapElemContainerAditional . "</div></div><br>";

            //start show data retention policy if platform_integration_level status on
            // if($dataPltInteg->integ_drp_status==1)
            // {
            //     $mappingContents .= $this->objMapHelp->getDataRetentionView($dataPltInteg->integ_drp_status,$dataPltInteg->integ_drp_period,$userIntegData->data_retention_status,$userIntegData->data_retention_period);
            //     $mappingContents .= '<input type="hidden" class="data_retention_policy_status" value="'.$dataPltInteg->integ_drp_status.'">';
            // }
            // else
            // {
            //     $mappingContents .= '<input type="hidden" class="data_retention_policy_status" value="'.$dataPltInteg->integ_drp_status.'">';
            // }
            $mappingContents .= '<input type="hidden" class="data_retention_policy_status" value="0">';

            //list_duniqueMappingCheck_object
            $mappingContents .= '<textarea rows="4" cols="50" id="list_uniqueMappingCheck_object" style="display:none;">' . json_encode(array_unique($list_uniqueMappingCheck_object)) . '</textarea>';
            
            

            //end
            return response()->json([
                'mappingContents' => json_encode($mappingContents), 'status_code' => $status_code, 'status_text' => $status_code,
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return response()->json([
                'status_code' => 0, 'status_text' => $e->getMessage(),
            ]);
        }
    }
    
    //store mapping data
    public function storeMapping(Request $request)
    {
        $userId = \Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id.
        $jsonData = $request["data"];
        $dataArray = json_decode($jsonData, TRUE);

        //check unique validaton for selected objects
        if( isset($request['list_uniqueMappingCheck']) && $request['list_uniqueMappingCheck'] !="" ) {
            $uniqueValidationObjectData = explode(",",$request['list_uniqueMappingCheck']);
            foreach($uniqueValidationObjectData as $object) {
                
                $object_details = explode("-",$object);
                if($object_details && count($object_details) > 0) {
                    $source_map_object_id = $this->ConnectionHelper->getObjectId($object_details[0]);
                    $dest_map_object_id = $this->ConnectionHelper->getObjectId($object_details[1]);

                    $compare_map_val_array = [];
                    //now check mapping values... for source & destination mapping objects
                    foreach($dataArray['data'] as $row) {
                        if( $row['platform_object_id']==$source_map_object_id || $row['platform_object_id']==$dest_map_object_id) {
                           $push_line = $row['source_row_id'].'-'.$row['destination_row_id'].'-'.$row['custom_data'];
                           //push in array for compare
                           array_push($compare_map_val_array,$push_line);
                        }
                    }

                    // Step 2: Count the occurrences of each element
                    $countedValues = array_count_values($compare_map_val_array);
                    // Step 3: Identify duplicate values
                    $duplicates = [];
                    foreach ($countedValues as $value => $count) {
                        if ($count > 1) {
                            $duplicates[] = $value;
                        }
                    }

                    if (!empty($duplicates)) {
                        //get object display Name 
                        $duplicate_object_display_name = PlatformObject::whereIn('id',[$source_map_object_id,$dest_map_object_id])
                        ->select('description')->pluck('description')->toArray();
                        $duplicate_object_display_name = implode(" & ",$duplicate_object_display_name);
                        $status_text = "Duplicate mapping values found for ".$duplicate_object_display_name;
                        return response()->json(['status_code' => 0, 'status_text' => $status_text]);
                    }


                }
                
            }  
        }
        //end


        $actionStatus = "";
        $identData = json_decode($request['identData'], TRUE);

        $extensiveIdentData = NULL;
        if( $request['extensiveIdentData'] ) {
            $extensiveIdentData = json_decode($request['extensiveIdentData'], TRUE);
        }


        $userIntegId = $request['userIntegId'];
        $SynStartDate = json_decode($request['SynStartDate'], TRUE);

        //accept file mapping data
        $fileMappingData = json_decode($request['fileMappingData'], TRUE);

        $pltFormData = DB::table('user_integrations')->find($userIntegId);
        $pltformIntegId = $pltFormData->platform_integration_id;
        $FlowData = DB::table('platform_workflow_rule')->where('platform_integration_id', $pltformIntegId)->select('id as flowID')->get();

        //update user_integ_leve data retention
        $drp_status = $request['drp_status'];
        $drp_period = $request['drp_period'];

        DB::table('user_integrations')->where('id', $userIntegId)->update([
            'data_retention_status' => (isset($drp_status)) ? $drp_status : 0,
            'data_retention_period' => (isset($drp_period)) ? $drp_period : 0,
        ]);

        //First update all saved mapping status 0 then status will be update for upcomming data
        DB::table('platform_data_mapping')->where('user_integration_id', $userIntegId)->whereIn('data_map_type', ['object', 'field', 'custom', 'object_and_custom', 'custom_and_object', 'field_and_custom'])->update(['status' => '0']);


        //start identity store
        foreach ($FlowData as $data) {

            //store product identity mapping
            if ($identData) {

                $mappingObjectName = $this->ConnectionHelper->getObjectNameById($identData['platform_object_id']);
                $platform_workflow_rule_id = $data->flowID;

                if ($mappingObjectName == "product_identity") {

                    $find = PlatformDataMapping::where('platform_object_id', $identData['platform_object_id'])
                        ->where('mapping_type', $identData['mapping_type'])->where('data_map_type', $identData['data_map_type'])
                        ->where('platform_workflow_rule_id', $data->flowID)
                        ->where('user_integration_id', $userIntegId)->first();

                    if (!$find) {

                        $mapping_obj = new PlatformDataMapping;
                        $mapping_obj->platform_object_id = $identData['platform_object_id'];
                        $mapping_obj->mapping_type = $identData['mapping_type'];
                        $mapping_obj->data_map_type = $identData['data_map_type'];
                        $mapping_obj->platform_workflow_rule_id = $data->flowID;
                        $mapping_obj->source_row_id = $identData['source_row_id'];
                        $mapping_obj->destination_row_id = $identData['destination_row_id'];
                        $mapping_obj->user_integration_id = $userIntegId;
                        $mapping_obj->status = $identData['status'];
                        $mapping_obj->save();

                        // PlatformDataMapping::insert(
                        //     [
                        //         'platform_object_id' => $identData['platform_object_id'],
                        //         'mapping_type' => $identData['mapping_type'],
                        //         'data_map_type' => $identData['data_map_type'],
                        //         'platform_workflow_rule_id' => $data->flowID,
                        //         'source_row_id' => $identData['source_row_id'],
                        //         'destination_row_id' => $identData['destination_row_id'],
                        //         'status' => $identData['status'],
                        //         'user_integration_id' => $userIntegId,
                        //     ]
                        // );


                    } else {

                            $find->update([
                                'source_row_id' => $identData['source_row_id'], 'destination_row_id' => $identData['destination_row_id'],
                                'status' => 1
                            ]);

                            // PlatformDataMapping::where('platform_object_id', $identData['platform_object_id'])
                            // ->where('data_map_type', $identData['data_map_type'])->where('mapping_type', $identData['mapping_type'])
                            // ->where('platform_workflow_rule_id', $data->flowID)
                            // ->where('user_integration_id', $userIntegId)
                            // ->update([
                            //     'source_row_id' => $identData['source_row_id'], 'destination_row_id' => $identData['destination_row_id'],
                            //     'status' => 1
                            // ]);

                    }

                    //clear mapping Data from cache
                    $this->clearMappingDataCache($userIntegId, $platform_workflow_rule_id, $mappingObjectName);
                }
            }

            //store extensive identity mapping
            if($extensiveIdentData) {

                foreach( $extensiveIdentData['extensiveIdentData'] as $identData ) {

                    $mappingObjectName = $this->ConnectionHelper->getObjectNameById($identData['platform_object_id']);
                    $platform_workflow_rule_id = $data->flowID;

                    $find = PlatformDataMapping::where('platform_object_id', $identData['platform_object_id'])
                    ->where('mapping_type', $identData['mapping_type'])->where('data_map_type', $identData['data_map_type'])
                    ->where('platform_workflow_rule_id', $data->flowID)
                    ->where('user_integration_id', $userIntegId)->first();

                    if (!$find) {

                            $mapping_obj = new PlatformDataMapping;
                            $mapping_obj->platform_object_id = $identData['platform_object_id'];
                            $mapping_obj->mapping_type = $identData['mapping_type'];
                            $mapping_obj->data_map_type = $identData['data_map_type'];
                            $mapping_obj->platform_workflow_rule_id = $data->flowID;
                            $mapping_obj->source_row_id = $identData['source_row_id'];
                            $mapping_obj->destination_row_id = $identData['destination_row_id'];
                            $mapping_obj->user_integration_id = $userIntegId;
                            $mapping_obj->status = $identData['status'];
                            $mapping_obj->save();

                            // PlatformDataMapping::insert(
                            // [
                            //     'platform_object_id' => $identData['platform_object_id'],
                            //     'mapping_type' => $identData['mapping_type'],
                            //     'data_map_type' => $identData['data_map_type'],
                            //     'platform_workflow_rule_id' => $data->flowID,
                            //     'source_row_id' => $identData['source_row_id'],
                            //     'destination_row_id' => $identData['destination_row_id'],
                            //     'status' => $identData['status'],
                            //     'user_integration_id' => $userIntegId
                            // ]);


                    } else {

                        $find->update([
                            'source_row_id' => $identData['source_row_id'], 'destination_row_id' => $identData['destination_row_id'],
                            'status' => 1
                        ]);

                        // PlatformDataMapping::where('platform_object_id', $identData['platform_object_id'])
                        // ->where('data_map_type', $identData['data_map_type'])->where('mapping_type', $identData['mapping_type'])
                        // ->where('platform_workflow_rule_id', $data->flowID)
                        // ->where('user_integration_id', $userIntegId)
                        // ->update([
                        //     'source_row_id' => $identData['source_row_id'], 'destination_row_id' => $identData['destination_row_id'],
                        //     'status' => 1
                        // ]);


                    }

                    //clear mapping Data from cache
                    $this->clearMappingDataCache($userIntegId, $platform_workflow_rule_id, $mappingObjectName);

                }



            }

            //update sync start date time for select workflows
            if (count($SynStartDate) > 0) {
                foreach ($SynStartDate as $syncData) {
                    DB::table('user_workflow_rule')->where('platform_workflow_rule_id', $syncData['pfwfrID'])->whereNull('sync_start_date')
                        ->where('user_integration_id', $userIntegId)->whereIn('user_id', [$userId])
                        ->update([
                            'sync_start_date' => $syncData['SynStartDate']
                        ]);
                }
            }

            //end inser user workflow

        }
        //end identity mapping


        foreach ($dataArray['data'] as $data) {

            $mappingObjectName = $this->ConnectionHelper->getObjectNameById($data['platform_object_id']);
            $platform_workflow_rule_id = $data['platform_workflow_rule_id'];

            if ($data['editId']) {

                $find = PlatformDataMapping::find($data['editId']);

                // $find = PlatformDataMapping::where('platform_object_id', $data['platform_object_id'])
                // ->where('mapping_type', $data['mapping_type'])
                // ->where('data_map_type', $data['data_map_type'])
                // ->where('platform_workflow_rule_id', $data['platform_workflow_rule_id'])
                // ->where('user_integration_id', $userIntegId)
                // ->where('id', $data['editId'])
                // ->first();


            } else {
                $find = PlatformDataMapping::where('platform_object_id', $data['platform_object_id'])
                    ->where('mapping_type', $data['mapping_type'])
                    ->where('data_map_type', $data['data_map_type'])
                    ->where('platform_workflow_rule_id', $data['platform_workflow_rule_id'])
                    ->where('user_integration_id', $userIntegId)
                    ->where('source_row_id', $data['source_row_id'])
                    ->where('destination_row_id', $data['destination_row_id'])
                    ->first();
            }

            if (!$find) {

                $mapping_obj = new PlatformDataMapping;
                $mapping_obj->platform_object_id = isset($data['platform_object_id']) ? $data['platform_object_id'] : '';
                $mapping_obj->mapping_type = isset($data['mapping_type']) ? $data['mapping_type'] : '';
                $mapping_obj->data_map_type = isset($data['data_map_type']) ? $data['data_map_type'] : '';
                $mapping_obj->platform_workflow_rule_id = isset($data['platform_workflow_rule_id']) ? $data['platform_workflow_rule_id'] : '';
                $mapping_obj->source_row_id = isset($data['source_row_id']) ? $data['source_row_id'] : 0;
                $mapping_obj->destination_row_id = isset($data['destination_row_id']) ? $data['destination_row_id'] : 0;
                $mapping_obj->custom_data = isset($data['custom_data']) ? $data['custom_data'] : null;
                $mapping_obj->user_integration_id = $userIntegId;
                $mapping_obj->status = isset($data['status']) ? $data['status'] : 0;
                $mapping_obj->save();

                // PlatformDataMapping::insert(
                //     [
                //         'platform_object_id' => isset($data['platform_object_id']) ? $data['platform_object_id'] : '',
                //         'mapping_type' => isset($data['mapping_type']) ? $data['mapping_type'] : '',
                //         'data_map_type' => isset($data['data_map_type']) ? $data['data_map_type'] : '',
                //         'platform_workflow_rule_id' => isset($data['platform_workflow_rule_id']) ? $data['platform_workflow_rule_id'] : '',
                //         'source_row_id' => isset($data['source_row_id']) ? $data['source_row_id'] : null,
                //         'destination_row_id' => isset($data['destination_row_id']) ? $data['destination_row_id'] : null,
                //         'custom_data' => isset($data['custom_data']) ? $data['custom_data'] : null,
                //         'user_integration_id' => $userIntegId,
                //         'status' => isset($data['status']) ? $data['status'] : 0
                //     ]
                // );


            } else {

                $find->update([
                    'source_row_id' => isset($data['source_row_id']) ? $data['source_row_id'] : 0,
                    'destination_row_id' => isset($data['destination_row_id']) ? $data['destination_row_id'] : 0,
                    'custom_data' => isset($data['custom_data']) ? $data['custom_data'] : null,
                    'status' => isset($data['status']) ? $data['status'] : 0
                ]);

                // if ($data['editId']) {
                //     PlatformDataMapping::where('platform_object_id', $data['platform_object_id'])
                //         ->where('data_map_type', $data['data_map_type'])
                //         ->where('mapping_type', $data['mapping_type'])
                //         ->where('platform_workflow_rule_id', $data['platform_workflow_rule_id'])
                //         ->where('id', $data['editId'])
                //         ->where('user_integration_id', $userIntegId)
                //         ->update([
                //             'source_row_id' => isset($data['source_row_id']) ? $data['source_row_id'] : '',
                //             'destination_row_id' => isset($data['destination_row_id']) ? $data['destination_row_id'] : '',
                //             'custom_data' => isset($data['custom_data']) ? $data['custom_data'] : null,
                //             'status' => isset($data['status']) ? $data['status'] : 0
                //         ]);
                // } else {

                //     PlatformDataMapping::where('platform_object_id', $data['platform_object_id'])
                //         ->where('data_map_type', $data['data_map_type'])
                //         ->where('platform_workflow_rule_id', $data['platform_workflow_rule_id'])
                //         ->where('source_row_id', $data['source_row_id'])
                //         ->where('destination_row_id', $data['destination_row_id'])
                //         ->where('user_integration_id', $userIntegId)
                //         ->update([
                //             'source_row_id' => isset($data['source_row_id']) ? $data['source_row_id'] : '',
                //             'destination_row_id' => isset($data['destination_row_id']) ? $data['destination_row_id'] : '',
                //             'status' => isset($data['status']) ? $data['status'] : 0
                //         ]);
                // }

            }

            //clear mapping Data from cache
            $this->clearMappingDataCache($userIntegId, $platform_workflow_rule_id, $mappingObjectName);
        }
        //start file mapping

        //upload mapping files in bucket or server
        if ($fileMappingData) {

            //store file upload setting s3bucket/local
            $uploadMappingFilesIn = 'local';
            if ( \Config::get('apisettings.uploadMappingFilesIn') ) {
                $uploadMappingFilesIn = \Config::get('apisettings.uploadMappingFilesIn');
            }

            foreach ($fileMappingData['data'] as $data) {

                $findQuery = PlatformDataMapping::where('platform_object_id', $data['platform_object_id'])
                    ->where('mapping_type', $data['mapping_type'])
                    ->where('data_map_type', $data['data_map_type'])
                    ->where('platform_workflow_rule_id', $data['platform_workflow_rule_id'])
                    ->where('user_integration_id', $userIntegId);

                if ($data['editId']) {
                    $findQuery->where('id', $data['editId']);
                }

                $find = $findQuery->first();


                if (!$find) {

                    if ($request->hasfile($data['custom_data'])) {

                        $imagesName = "";
                        foreach ($request->file($data['custom_data']) as $file) {

                            //upload files
                            if($uploadMappingFilesIn=="s3bucket") {

						        $dynamic_file_name = 'esb/mappingfiles/'.str_replace(" ","",$data['platform'])."/" . $userIntegId.'/'.$file->getClientOriginalName();

                                //upload file in s3 bucket
						        Storage::disk('s3')->put($dynamic_file_name, file_get_contents($file));

                                if (Storage::disk('s3')->exists($dynamic_file_name)) {

                                    $bucket_name = env('AWS_BUCKET');
                                    $aws_region = env('AWS_DEFAULT_REGION');
                                    $imagesName = 'https://'.$bucket_name.'.s3.'.$aws_region.'.amazonaws.com/'.$dynamic_file_name;

                                }

                            } else {

                                //upload images in local server path
                                $destinationPath = "public/esb_asset/".$data['platform']."/" . $userIntegId;

                                $file->move($destinationPath, $file->getClientOriginalName());
                                $imagesName .= ($imagesName) ? ',' : '';
                                $imagesName .= $destinationPath . '/' . $file->getClientOriginalName();
                            }

                        }


                        $mapping_obj = new PlatformDataMapping;
                        $mapping_obj->platform_object_id = isset($data['platform_object_id']) ? $data['platform_object_id'] : '';
                        $mapping_obj->mapping_type = isset($data['mapping_type']) ? $data['mapping_type'] : '';
                        $mapping_obj->data_map_type = isset($data['data_map_type']) ? $data['data_map_type'] : '';
                        $mapping_obj->platform_workflow_rule_id = isset($data['platform_workflow_rule_id']) ? $data['platform_workflow_rule_id'] : '';
                        $mapping_obj->source_row_id = isset($data['source_row_id']) ? $data['source_row_id'] : 0;
                        $mapping_obj->destination_row_id = isset($data['destination_row_id']) ? $data['destination_row_id'] : null;
                        $mapping_obj->custom_data = $imagesName;
                        $mapping_obj->user_integration_id = $userIntegId;
                        $mapping_obj->status = 1;
                        $mapping_obj->save();

                        // PlatformDataMapping::insert(
                        //     [
                        //         'platform_object_id' => isset($data['platform_object_id']) ? $data['platform_object_id'] : '',
                        //         'mapping_type' => isset($data['mapping_type']) ? $data['mapping_type'] : '',
                        //         'data_map_type' => isset($data['data_map_type']) ? $data['data_map_type'] : '',
                        //         'platform_workflow_rule_id' => isset($data['platform_workflow_rule_id']) ? $data['platform_workflow_rule_id'] : '',
                        //         'source_row_id' => isset($data['source_row_id']) ? $data['source_row_id'] : null,
                        //         'destination_row_id' => isset($data['destination_row_id']) ? $data['destination_row_id'] : null,
                        //         'custom_data' => $imagesName,
                        //         'status' => 1,
                        //         'user_integration_id' => $userIntegId,
                        //     ]
                        // );


                    }

                } else {
                    $imagesName = "";
                    // dlink images & upload new images
                    $custom_dataQuery = DB::table('platform_data_mapping');
                    if ($data['editId']) {
                        $custom_dataQuery->where('id', $data['editId']);
                    } else {
                        $custom_dataQuery->where('id', $find->id);
                    }
                    $custom_data = $custom_dataQuery->pluck('custom_data');

                    if ($custom_data) {
                        $imgListArr = explode(",", $custom_data[0]);
                        $imagesName = $custom_data[0];

                        if ($request->hasfile($data['custom_data'])) {
                            foreach ($request->file($data['custom_data']) as $file) {

                                //upload files
                                if($uploadMappingFilesIn=="s3bucket") {

                                    $bucket_name = env('AWS_BUCKET');
                                    $aws_region = env('AWS_DEFAULT_REGION');

                                    $dynamic_file_name = 'esb/mappingfiles/'.str_replace(" ","",$data['platform'])."/" . $userIntegId.'/'.$file->getClientOriginalName();

                                    //dynamic image url
                                    $destinationPath = 'https://'.$bucket_name.'.s3.'.$aws_region.'.amazonaws.com/'.$dynamic_file_name;

                                    //Check file duplicacy
                                    if (in_array($destinationPath, $imgListArr)) {
                                        return response()->json(['status_code' => 0, 'status_text' => 'File Already Exist try with defrence name or delete existing']);
                                    }


                                    //upload file in s3 bucket
                                    Storage::disk('s3')->put($dynamic_file_name, file_get_contents($file));
                                    if (Storage::disk('s3')->exists($dynamic_file_name)) {
                                        $imagesName = 'https://'.$bucket_name.'.s3.'.$aws_region.'.amazonaws.com/'.$dynamic_file_name;
                                    }

                                } else {

                                    $destinationPath = "public/esb_asset/" . $data['platform'] . "/" . $userIntegId;

                                    //Check file duplicacy
                                    if (in_array($destinationPath . '/' . $file->getClientOriginalName(), $imgListArr)) {
                                        return response()->json(['status_code' => 0, 'status_text' => 'File Already Exist try with defrence name or delete existing']);
                                    }

                                    //upload new images
                                    $imagesName .= ($imagesName) ? ',' : '';
                                    $file->move($destinationPath, $file->getClientOriginalName());
                                    $imagesName .= $destinationPath . '/' . $file->getClientOriginalName();


                                }



                            }
                        }

                    } else {
                        $imagesName = "";
                    }
                    //update mappings

                    $find->update([
                        'custom_data' => $imagesName, 'status' => 1
                    ]);

                    // $updQyery  = PlatformDataMapping::where('platform_object_id', $data['platform_object_id'])->where('data_map_type', $data['data_map_type'])->where('mapping_type', $data['mapping_type'])->where('platform_workflow_rule_id', $data['platform_workflow_rule_id']);

                    //     if ($data['editId']) {
                    //         $updQyery->where('id', $data['editId']);
                    //     } else {
                    //         $updQyery->where('id', $find->id);
                    //     }

                    //     $updQyery->where('user_integration_id', $userIntegId)
                    //     ->update([
                    //         'custom_data' => $imagesName, 'status' => 1
                    //     ]);


                }
            }
        }


        //Delete 0 status mapping data.. which not in use in case of multiselect mappings..
        foreach ( PlatformDataMapping::where(['user_integration_id'=>$userIntegId,'mapping_type'=>'regular','status'=>'0'])->whereIn('data_map_type', ['object', 'field', 'custom', 'object_and_custom', 'custom_and_object', 'field_and_custom'])->get() as $find_row ) {
            $find_row->delete();
        }
        //end

        $status_text = "Mapping refreshed successsfully";
        return response()->json(['status_code' => 1, 'status_text' => $status_text]);
    }



    //clear mapping data from cache after every store mapping call
    public function clearMappingDataCache($userIntegId, $platform_workflow_rule_id, $mappingObjectName)
    {
        //clear mapping cache key with null workflow id
        $keyPattern = ['wf_available', 'wf_not_available'];
        foreach ($keyPattern as $item) {
            if ($item == "wf_not_available") {
                $platform_workflow_rule_id = '';
            }
            //clear all type of mapping
            $mappingTypes = ['regular', 'default', 'cross'];
            foreach ($mappingTypes as $map_type) {

                $key = $this->mobj->generateIntegrationCacheKey($userIntegId, $platform_workflow_rule_id, $mappingObjectName, $map_type);
                $find_in_cache = $this->cache->get_or_set($key, $value = null, $seconds = null, $cache_type = null);
                if ($find_in_cache) {
                    $this->cache->clear_cache_by_key($key);
                }
            }
        }
    }

    //check regular mapping for warehouse.. to show multi warehous switch on off
    public function checkMultiWarehouseMapping($userIntegId, $objectId)
    {
        $data_multi_wh_map = PlatformDataMapping::where('user_integration_id', $userIntegId)
            ->whereIn('platform_object_id', [$objectId])
            ->where('mapping_type', 'default')
            ->where('data_map_type', 'custom')
            ->first();
        return $data_multi_wh_map;
    }
    //inactive store mapping when warehouse switch change
    public function inactiveWarehouseMappingOnChangeSwitch(Request $request)
    {
        $userIntegId = $request->userIntegId;
        $status = $request->status;
        $inventory_warehouse_id = $this->ConnectionHelper->getObjectId('inventory_warehouse');
        $order_warehouse_id = $this->ConnectionHelper->getObjectId('order_warehouse');
        $mh_switch_objId = $this->ConnectionHelper->getObjectId('has_multi_warehouse');

        $updateStatus = PlatformDataMapping::where('user_integration_id', $userIntegId)
            ->whereIn('platform_object_id', [$inventory_warehouse_id, $order_warehouse_id])
            ->where('mapping_type', 'regular')
            ->delete();
        // ->update(['status' => '0']);


        $updateSwitchStatus = PlatformDataMapping::where('user_integration_id', $userIntegId)
            ->whereIn('platform_object_id', [$mh_switch_objId])
            ->where('mapping_type', 'default')
            ->where('data_map_type', 'custom')
            ->update(['custom_data' => $status]);

        if ($updateStatus) {
            $status_code = 1;
        } else {
            $status_code = 0;
        }

        return $status_code;
    }
    //delete selected warehouse mapping
    public function deleteMapping(Request $request)
    {
        // $userId = \Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id.
        // $res = PlatformDataMapping::where('id', $request->editId)
        //     ->where('platform_workflow_rule_id', $request->pfwfrID)
        //     //->where('source_row_id', $request->source_row_id)
        //     ->delete();

        $mapping = PlatformDataMapping::find($request->editId);
        $res = $mapping->delete();

        if ($res) {
            return response()->json(['status_code' => 1, 'status_text' => 'Mapping Deleted Successfully']);
        } else {
            return response()->json(['status_code' => 0, 'status_text' => 'Some thing went wrong try again!']);
        }
    }
    public function deleteUserInteg(Request $request)
    {
        $userIntegId = $request->userIntegId;

        $authUserData = \Session::get('user_data');
        $permissionStatus = $this->mobj->checkParentAndPermission('user_id', $authUserData,  'user_integrations', 'id', $userIntegId); //Accept 1. parentColumField  , 2. Auth::user() data , 3. tableName, 4. requestColumField, 5. requestId
        if (!$permissionStatus) {
            return response()->json(['status_code' => 0, 'status_text' => "You don't have enough permission."]);
        }

        $delStatus = DB::table('user_integrations')->where('id', $userIntegId)->where('workflow_status', '!=', 'active')->delete();
        if ($delStatus) {
            //Delete User Work flows
            DB::table('user_workflow_rule')->where('user_integration_id', $userIntegId)->delete();
            return response()->json(['status_code' => 1, 'status_text' => 'Integration Deleted Successfully']);
        } else {
            return response()->json(['status_code' => 0, 'status_text' => 'Active Integration can not delete']);
        }
    }
    public function deleteMappingFile(Request $request)
    {
        $editId = $request->editid;
        $userIntegId = $request->userIntegId;
        $platform = $request->platform;
        $name = $request->name;
        $destinationPath = "public/esb_asset/" . $platform . "/" . $userIntegId;
        $delImgPath = $destinationPath . '/' . $name;
        $custom_data = PlatformDataMapping::where('id', $editId)->pluck('custom_data');

        $newImage = "";
        if ($custom_data) {
            $imgListArr = explode(",", $custom_data[0]);
            foreach ($imgListArr as $imgPath) {
                if ($imgPath != $delImgPath) {
                    $newImage .= ($newImage) ? ',' : '';
                    $newImage .= $imgPath;
                }
            }
        }

        //Update DB custom data
        $mapping = PlatformDataMapping::find($editId);
        $updateStatus = $mapping->update(['custom_data' => $newImage]);

        // if db updated with new Images then Remove file
        if ($updateStatus) {
            if ($editId != "") {
                $filename = $destinationPath . '/' . $name;
                unlink($filename);
                return response()->json(['status_code' => 1, 'status_text' => 'File Deleted Successfully']);
            }
        }
        return response()->json(['status_code' => 0, 'status_text' => 'There is something went wrong']);
    }

    public function loadDepDrop(Request $request)
    {
        $zoneId = $request->zone;
        $status_code = 0;
        $status_text = "";
        $userId = \Session::get('user_data')->id;

        $mapObjData = "";
        $status_code = 1;
        $status_text = "Data Synced Successfully";

        //load stored data
        if ($zoneId) {
            //call execute event to get data
            $user_workflow_rule_id = DB::table('user_workflow_rule')->where('platform_workflow_rule_id', $request->wfrid)->where('user_integration_id', $request->userIntegId)->pluck('id')->first();

            $getflowEvents = $this->objWorkflowSnippet->getWorkflowEvents($user_workflow_rule_id, 'loadDepDownData');
            if ($getflowEvents) {
                $user_id = $getflowEvents->user_id;
                $user_integration_id = $getflowEvents->user_integration_id;
                $source_platform_id = $getflowEvents->source_platform;
                $destination_platform_id = $getflowEvents->destination_platform;
                $sourceEventExtract = $this->objWorkflowSnippet->ExtractEventType($getflowEvents->source_event);
                $is_initial_sync = 0;

                //check both platform data
                $this->workflow->executeEvent('GET', 'SHIPPINGMETHOD', $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $getflowEvents->platform_workflow_rule_id, $zoneId);

                $this->workflow->executeEvent('GET', 'SHIPPINGMETHOD', $source_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $destination_platform_id, $getflowEvents->platform_workflow_rule_id, $zoneId);
            }
            $mapObjData = $this->objMapHelp->getPlatformObjectData($request->pltId, $request->userIntegId, $userId, $request->pltObjId, '', '', $zoneId);
        }

        return response()->json(['status_code' => $status_code, 'status_text' => $status_text, 'mapObjData' => $mapObjData]);
    }

    public function getPlatformIdByPrimaryId($id)
    {
        $platformId = Platform::where('id', $id)->select('platform_id')->pluck('platform_id')->first();
        return $platformId;
    }

    public function getParentInfo($userIntegId, $userId, $sourcePlt, $destPlt){

        $reqAccIds = [$sourcePlt,$destPlt];

        $query = UserIntegration::where('id','!=',$userIntegId)
                ->where('user_id','=',$userId)
                ->where('workflow_status','=','active')
                ->where(function ($query) use ($sourcePlt,$destPlt){
                    $query->where('selected_sc_account_id',$sourcePlt)
                    ->orWhere('selected_dc_account_id', '=', $sourcePlt)
                    ->orWhere('selected_sc_account_id', '=', $destPlt)
                    ->orWhere('selected_dc_account_id','=',$destPlt);
                })->orderBy('id','asc')->select('id','selected_sc_account_id','selected_dc_account_id')->first();

        $response = [];
        if($query){
            $connectedAccIds =   [$query->selected_sc_account_id,$query->selected_dc_account_id];
            $matchedAcc = array_intersect($connectedAccIds,$reqAccIds);
            $platformIds = PlatformAccount::whereIn('id',$matchedAcc)->pluck('platform_id')->toArray();
            $response =  ['parentIngId'=>$query->id, 'platformIds'=>implode(',',$platformIds)];
        }
        return $response;

    }

    /**
     * 
     */
    public function checkUserRefreshTokenValidationExpire( Request $request ){
        $data = [];
        try {

            //check expire Platform ID
            $platform_ids = [
                'snowflake'
            ];

            $user_id = Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id.
            $user_intg_id = $request->get('user_intg_id');

            $integrations = DB::table('user_integrations AS usrint')
                ->select(
                    'usrint.selected_sc_account_id', 'usrint.selected_dc_account_id',
                    'pfASc.platform_name AS source_platform_name', 'pfADc.platform_name AS dest_platform_name',
                    'pfASc.platform_id AS source_platform_id', 'pfADc.platform_id AS dest_platform_id',
                    'pfSc.last_refreshed_at as source_refresh_time', 'pfSc.last_refreshed_at as dest_refresh_time',
                )
                ->join('platform_accounts AS pfSc', 'pfSc.id', '=', 'usrint.selected_sc_account_id')
                ->join('platform_accounts AS pfDc', 'pfDc.id', '=', 'usrint.selected_dc_account_id')
                ->join('platform_lookup AS pfASc', 'pfASc.id', '=', 'pfSc.platform_id')
                ->join('platform_lookup AS pfADc', 'pfADc.id', '=', 'pfDc.platform_id')
                ->where([
                    'usrint.id' => $user_intg_id,
                ])
                ->get();

            $data['status_code'] = 0;
            if( $integrations ){

                if( in_array( $integrations->dest_platform_id, $platform_ids ) ){
                    $checkTime = strtotime( '-30 day', (int)$integrations->dest_refresh_time );
                    if( true || $checkTime > time() ){
                        $data['status_code'] = 1;
                    }
                }

                if( in_array( $integrations->source_platform_id, $platform_ids ) ){
                    $checkTime = strtotime( '-30 day', (int)$integrations->source_refresh_time );
                    if( true || $checkTime > time() ){
                        $data['status_code'] = 1;
                    }
                }

                $data['status_text'] = $integrations->source_platform_name . " and " . $integrations->dest_platform_name;
            }

            return json_encode($data);
        } catch (\Exception $e) {
            $data['status_code'] = 0;
            $data['status_text'] = $e->getMessage();
            return json_encode($data);
        }
    }



   //load Ip application Url 
    public function loadIpApplicationUrl(Request $request)
    {
        $status_code = 0;
        $status_text = "Please provide valid input";
        $response_data = [];
        
        $pobjName = $request->pobjName;
        $userIntegId = $request->userIntegId;
        $warehouseIds = $request->urlFormData;

        //call execute event to get data
        $user_workflow_rule_id = DB::table('user_workflow_rule')->where( [
            'platform_workflow_rule_id' => $request->pfwfrID,
            'user_integration_id' => $userIntegId
        ])
        ->pluck('id')
        ->first();

        if( $user_workflow_rule_id ) {
            $getflowEvents = $this->objWorkflowSnippet->getWorkflowEvents( $user_workflow_rule_id, $pobjName );
            
            if ($getflowEvents) {
                $user_id = $getflowEvents->user_id;
                $user_integration_id = $getflowEvents->user_integration_id;
                $source_platform_id = $getflowEvents->source_platform;
                $destination_platform_id = $getflowEvents->destination_platform;
                $sourceEventExtract = $this->objWorkflowSnippet->ExtractEventType( $getflowEvents->source_event );
                $is_initial_sync = 0;
    
                //check both platform data
                $response = json_decode( $this->workflow->executeEvent('GET', 'GENERATES3IPPATH', $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $getflowEvents->platform_workflow_rule_id, $warehouseIds), 1 );
                
                if( $response['status_code'] ) {
                    foreach( $response['data'] as $warehouse_id => $data) {
                        $url_line['source_row_id'] = $warehouse_id;
                        //formate custom data before pass in ajax response "{ProductUrl : https://esb-stag.apiworx.net/prod, OrderUrl : https://esb-stag.apiworx.net/ord}";
                        $url_line['custom_data'] = "{".json_encode( $data )."}";

                        //push in array
                        array_push( $response_data, $url_line );
                    }
                }

                $status_code = $response['status_code'];
                $status_text = $response['status_text'];
            } 
        }

        if( false ){
            //dummy for testing
            $url_line['source_row_id'] = 10;
            $url_line['custom_data'] = "{ProductUrl : https://esb-stag.apiworx.net/prod,OrderUrl : https://esb-stag.apiworx.net/ord}";
            //push in array
            array_push($response_data,$url_line);
            $status_code = 1;
            $status_text = "Application Url Generated";                
        }

        return response()->json([
            'status_code' => $status_code, 
            'status_text' => $status_text, 
            'data' => $response_data
        ]);
    }


}
