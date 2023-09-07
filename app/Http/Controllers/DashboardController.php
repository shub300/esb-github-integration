<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use App\Helper\WorkflowSnippet;
use App\Helper\MainModel;
use DB;
use App\Http\Controllers\PanelControllers\ModuleAccessController;
use App\Models\UserStaffIntegrationAccess;

class DashboardController extends Controller
{
    public $wfsnip;
    public function __construct()
    {
        $this->wfsnip = new WorkflowSnippet();
    }

    public function index(Request $request)
    {
        if(Auth::user()->role!="master_staff"){
            $view = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'integrations', 'view');
            $integration = [];

            $modify = ModuleAccessController::getAccessRight(Auth::user()->id, Auth::user()->role, 'integrations', 'modify');
            $tigger_integration_id = $this->getReferredIntegration();

            $searchVal = isset($request['term'])? $request['term'] : null;

            if($view == 1){
                $integration = $this->wfsnip->getIntegrations($searchVal);
            }

            //get failed log details
            $record_failed_alert_msg = $this->getFailedLogDetails(Auth::user()->id);

            if(isset($request['term'])){
                return response()->json([
                    "items" => '',
                    "pagination" => ["more"=>true],
                    'integration' => $integration,
                    'modify' => $modify,
                    'tigger_integration_id' => $tigger_integration_id,
                    'searchVal' => $searchVal,
                    'record_failed_alert_msg' => $record_failed_alert_msg
                ]);
            }
            
                return view("pages.dashboard", compact('integration', 'tigger_integration_id', 'modify','record_failed_alert_msg'));

        }else{
            return redirect('launchpad');
        }

    }

    //get integration details where any failed log exist
    public function getFailedLogDetails($userId)
    {   
        $msg_string = "";
        $integration_link_array = [];
        //get userWorkflow list
        $user_integ_wf_data = DB::table('user_integrations')
        ->join('user_workflow_rule', 'user_integrations.id', '=', 'user_workflow_rule.user_integration_id')
        ->join('platform_workflow_rule as pwfr','pwfr.id','user_workflow_rule.platform_workflow_rule_id')
        ->join('sync_logs','sync_logs.user_workflow_rule_id','user_workflow_rule.id')
        ->select('user_integrations.id as userIntegId', 'user_integrations.flow_name')
        ->where('user_integrations.workflow_status', 'active')
        ->where('user_integrations.user_id',$userId)
        ->where('user_workflow_rule.status', 1)
        ->where('sync_logs.user_id',$userId)
        ->where('sync_logs.sync_status', 'failed')
        ->orderBy('user_integrations.id')
        ->groupBy('user_integrations.id')
        ->get();    

        foreach($user_integ_wf_data as $user_integration)
		{
            $userIntegId = $user_integration->userIntegId;
            $userIntegName = strip_tags($user_integration->flow_name);
            $integration_link = '<a href="'.url('/integration_flow/'.$userIntegId).'"><i class="fa fa-external-link-square" aria-hidden="true"></i> &nbsp;'.$userIntegName.'</a>';
            array_push($integration_link_array,$integration_link); 
        }

        if($integration_link_array) {
            $msg_string = implode(",",$integration_link_array);
        }

        return $msg_string;
    }

    /* display users list when master staff logged in */
    public function launchpad(Request $request)
    {
        if(Auth::user()->role=="master_staff" || Auth::user()->role=="user_staff"){
            
            $organization_id =  config('org_details.organization_id'); //Get Organization Id by host name
            if(Auth::user()->role=="user_staff"){
                $uIds = Auth::user()->parentUsers()->pluck('parent_id')->toArray();
                $q = DB::table('users')->whereIn('id',$uIds)->where(['organization_id'=>$organization_id,'role'=>"user"]);
            }else{
                $q = DB::table('users')->where(['organization_id'=>$organization_id,'role'=>"user"]);
            }
            $search = $request->input('search');
            $status=$request->input('status');
            if (isset($search)) {
                $q->where(function ($query) use ($search) {
                    $query->where('email', 'Like', '%' . $search . '%')
                    ->orwhere('name', 'Like', '%' . $search . '%');
                });
            }
            if(isset($status)){
                $q->where('status', $status);
            }
            
            $limit = ($q->count() > 10 || $q->count() == 0) ? 10 : $q->count();
            $users=$q->orderBy('id', 'DESC')->paginate($limit);
            
            return view("pages.launchpad", compact('users'));

        }else{
            return redirect('integrations');
        }


    }

    public function getReferredIntegration()
    {
        $obj = new MainModel;
        $user_id = \Session::get('user_data')->id;
        $es_platform_refrer = $obj->getFirstResultByConditions('es_platform_refrer', ['user_id' => $user_id, 'status' => 1], ['id', 'platform_integrations_id']);
        $tigger_integration_id = 0;
        if ($es_platform_refrer) {
            $obj->makeUpdate('es_platform_refrer', ['status' => 0], ['id' => $es_platform_refrer->id]);
            $tigger_integration_id = $es_platform_refrer->platform_integrations_id;
        }
        return $tigger_integration_id;
    }

    public function connectWorkflow(Request $request)
    {
        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];
        $obj = new MainModel;
        try {
            $id = $request->id;
            $flow_name = $request->flow_name;
            $flow_name_info = $obj->getFirstResultByConditions('user_integrations', ['user_id' => $user_id, 'flow_name' => $flow_name], ['id']);
            if (!$flow_name_info) {

                $wfid = $obj->makeInsertGetId(
                    'user_integrations',
                    ['user_id' => $user_id, 'flow_name' => $flow_name, 'platform_integration_id' => $id]
                );

                $redirect_url = url("connection-settings/$wfid");
                return response()->json(['status_code' => 1, 'status_text' => 'Success', 'id' => $wfid, 'redirect_url' => $redirect_url], 200);
            } else {
                return response()->json(['status_code' => 0, 'status_text' => 'Flow Name Already in use!'], 200);
            }
        } catch (\Exception $e) {
            return response()->json(['status_code' => 0, 'status_text' => $e->getMessage()], 200);
        }
    }
}
