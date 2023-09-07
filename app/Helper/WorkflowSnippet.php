<?php

namespace App\Helper;

use App\Helper\MainModel;
use DB;

class WorkflowSnippet
{

    /**
     * Provides platform list options
     *
     * @return Array of platform options & data
     */
    public function __construct()
    {
        $this->mobj = new MainModel();
    }

    public function getPlatformOptions()
    {

        $platform = $this->mobj->getResultByConditions('platform_lookup', ['status' => 1], ['platform_id', 'platform_name', 'platform_image', 'auth_endpoint']);

        $options = '';
        $opt_data = [];
        foreach ($platform as $pv) {
            $platform_image = '';
            $auth_url = '';
            try {
                if ($pv->platform_image)
                    $platform_image = asset($pv->platform_image);
                if ($pv->auth_endpoint)
                    $auth_url = url($pv->auth_endpoint);
            } catch (\Exception $e) { }


            $options .= '<option data-icon="' . $platform_image . '" data-url="' . $auth_url . '" data-name="' . $pv->platform_name . '" value="' . $pv->platform_id . '">' . $pv->platform_name . '</option>';
            $opt_data[] = $pv;
        }

        return ['options' => $options, 'data' => $opt_data];
    }

    /**
     * Provides suggested workflow
     *
     * @return Collection
     */
    public function getSuggestedWorkflow()
    {

        $workflowd = DB::table('suggested_workflow')->join('platform_lookup as p1', 'suggested_workflow.source_platform_id', '=', 'p1.platform_id')
            ->join('platform_lookup as p2', 'suggested_workflow.destination_platform_id', '=', 'p2.platform_id')
            ->where(['suggested_workflow.status' => 1, 'p1.status' => 1, 'p2.status' => 1])
            ->select(
                'suggested_workflow.id as workflow_id',
                'p1.platform_id as p1_id',
                'p1.platform_name as p1_name',
                'p1.platform_image as p1_image',
                'p2.platform_id as p2_id',
                'p2.platform_name as p2_name',
                'p2.platform_image as p2_image'
            )->limit(10)->get();


        return $workflowd;
    }

    /**
     * Provides platform events
     * @param $platform_id
     * @return Array of platform options & data
     */
    public function getPlatformEvents($platform_id = '')
    {
        $mobj = new MainModel;

        $platform = DB::table('platform_events')->join('platform_lookup', 'platform_lookup.platform_id', '=', 'platform_events.platform_id')
            ->where(['platform_lookup.status' => 1, 'platform_events.status' => 1, 'platform_events.platform_id' => $platform_id])
            ->select(['platform_events.platform_id', 'platform_events.id', 'platform_events.event_id', 'platform_lookup.platform_image', 'platform_events.event_name'])->get();

        $options = '';
        $opt_data = [];
        foreach ($platform as $pv) {
            $platform_image = asset($pv->platform_image);
            $options .= '<option data-icon="' . $platform_image . '" value="' . $pv->id . '">' . $pv->event_name . '</option>';
            $opt_data[] = $pv;
        }

        return ['options' => $options, 'data' => $opt_data];
    }

    /**
     * Provides suggested workflow
     *
     * @return Collection
     */
    //lis of available integrations
    public function getIntegrations($searchVal=null)
    {
        $mobj = new MainModel;
        $org_id = config('org_details.organization_id');

        $integration_query = DB::table('platform_integrations')
            ->join('platform_lookup as p1', 'platform_integrations.source_platform_id', '=', 'p1.id')
            ->join('platform_lookup as p2', 'platform_integrations.destination_platform_id', '=', 'p2.id')
            ->join('es_platform_access as pf_acs', 'pf_acs.platform_integration_id', '=', 'platform_integrations.id')
            ->where(['platform_integrations.status' => 1, 'p1.status' => 1, 'p2.status' => 1, 'pf_acs.status'=>1, 'pf_acs.organization_id'=>$org_id]);
            if($searchVal){
                $integration_query->where(function($query) use ($searchVal){
                    $query->where('platform_integrations.description', 'like', '%'.$searchVal.'%')
                   ->orWhere('p1.platform_name', 'like', '%'.$searchVal.'%')
                   ->orWhere('p2.platform_name', 'like', '%'.$searchVal.'%');
                });
            }
            $workflowd = $integration_query->select(
                'platform_integrations.id as integration_id',
                'platform_integrations.description as integration_description',
                'p1.platform_id as p1_id',
                'p1.platform_name as p1_name',
                'p1.platform_image as p1_image',
                'p2.platform_id as p2_id',
                'p2.platform_name as p2_name',
                'p2.platform_image as p2_image'
            )->paginate(6);

        return $workflowd;
    }

    public function ExtractEventType($event)
    {
        $event_ex = explode("_", $event);
        $resposne = ['method' => $event_ex[0], 'primary_event' => $event_ex[1]];
        return $resposne;
    }

    public function getWorkflowEvents( $user_workflow_rule_id, $specialCase = null ){
        $getflowEventsQry = DB::table('user_workflow_rule as uwr')
        ->join('platform_workflow_rule as pwr','uwr.platform_workflow_rule_id','=','pwr.id')
        ->join('platform_events as s_event','pwr.source_event_id','=','s_event.id')
        ->join('platform_events as d_event','pwr.destination_event_id','=','d_event.id')
        ->join('platform_lookup as sou_platform_lookup','sou_platform_lookup.id','=','s_event.platform_id')
        ->join('platform_lookup as dest_platform_lookup','dest_platform_lookup.id','=','d_event.platform_id')
        ->select('uwr.user_id','uwr.user_integration_id','uwr.sync_start_date','pwr.source_event_id','pwr.destination_event_id','sou_platform_lookup.platform_id as source_platform','dest_platform_lookup.platform_id as destination_platform','s_event.event_id as source_event','d_event.event_id as destination_event','s_event.platform_id as source_platform_id','d_event.platform_id as destination_platform_id','uwr.platform_workflow_rule_id as platform_workflow_rule_id')
        ->where('uwr.id',$user_workflow_rule_id);

        if( $specialCase == "loadDepDownData" || $specialCase == "ip_s3_access_path" ) {
            $getflowEventsQry->whereIn('uwr.status',[0,1]);
        } else {
            $getflowEventsQry->where('uwr.status',1);
        }

        $getflowEventsQry->where('pwr.status',1)->where('s_event.status',1)->where('d_event.status',1);
        $getflowEvents = $getflowEventsQry->first();

        return $getflowEvents;
    }

    public function getWorkflowEventsByIntegration($user_integration_id,$type=null){
        $query=DB::table('user_workflow_rule as uwr')
        ->join('platform_workflow_rule as pwr','uwr.platform_workflow_rule_id','=','pwr.id')
        ->join('platform_events as s_event','pwr.source_event_id','=','s_event.id')
        ->join('platform_events as d_event','pwr.destination_event_id','=','d_event.id')
        ->join('platform_lookup as sou_platform_lookup','sou_platform_lookup.id','=','s_event.platform_id')
        ->join('platform_lookup as dest_platform_lookup','dest_platform_lookup.id','=','d_event.platform_id')
        ->select('uwr.user_id','uwr.user_integration_id','uwr.sync_start_date','pwr.source_event_id','pwr.destination_event_id','sou_platform_lookup.platform_id as source_platform','dest_platform_lookup.platform_id as destination_platform','s_event.event_id as source_event','d_event.event_id as destination_event','s_event.platform_id as source_platform_id','d_event.platform_id as destination_platform_id','uwr.platform_workflow_rule_id as platform_workflow_rule_id')
        ->where('uwr.user_integration_id',$user_integration_id)->where('uwr.status',1)->where('pwr.status',1)->where('s_event.status',1)->where('d_event.status',1);
       // dd($query->pluck('destination_event')->toArray());
        if($type=="source"){
            $response=$query->pluck('source_event')->toArray();
        }else if($type=="destination"){
          $response=$query->pluck('destination_event')->toArray();
        }else{
          $response=$query->pluck('destination_event','source_event')->toArray();
        }
        return $response;
    }

    public function subeventStatusUpdate($response,$user_subevent_row_id,$user_workflow_rule_id,$is_initial_sync=1,$user_integration_id,$status='failed')
    {
        date_default_timezone_set('UTC');
        if (is_bool($response)) {
            $result = true;
            // $update_arr = ['status' => 'completed', 'message' => null,'last_run_time'=> now()];
            $update_arr = ['status' => 'completed', 'message' => null,'last_run_time'=> date('Y-m-d H:i:s')];
        } else {
            $result = false;
            // $update_arr = ['status' =>$status,'last_run_time'=> now(),'message' => $response];
            $update_arr = ['status' =>$status,'last_run_time'=> date('Y-m-d H:i:s'),'message' => $response];
            if($is_initial_sync){
            $uwf_updateStatus =  $this->mobj->makeUpdate('user_workflow_rule', ['is_all_data_fetched' => 'pending'], ['id' => $user_workflow_rule_id]);
            //   \Storage::disk('local')->append('execute_new_eve.txt.txt','Table user_workflow_rule Update from else block Status : '.$uwf_updateStatus.' user_subevent_row_id : '.$user_subevent_row_id);
            }
        }

        $uise_updateStatus = $this->mobj->makeUpdate('user_integration_sub_event', $update_arr, ['id' => $user_subevent_row_id,'user_integration_id'=>$user_integration_id]);
        // \Storage::disk('local')->append('execute_new_eve.txt.txt','Table user_integration_sub_event Update Status : '.$uise_updateStatus.' user_subevent_row_id : '.$user_subevent_row_id. ' UserIntegId : '. $user_integration_id);

        return $result;
    }

    public function getStatusByWorkflow($platform_workflow_rule_id, $object_name='get_order_status'){
        $get_platform_statusses = DB::table('platform_data_mapping as ps')
        ->join('platform_object_data as uwst','ps.source_row_id','=','uwst.id')
        ->join('platform_objects','platform_objects.id','=','ps.platform_object_id')
        ->where('ps.platform_workflow_rule_id',$platform_workflow_rule_id)
        ->where('platform_objects.name',$object_name)
        ->where('ps.status',1)
        ->where('uwst.status',1)
        ->pluck('uwst.name')->toArray();
        return $get_platform_statusses;
    }

    // It calls while initial sync to check whether same event is already exists for same user integration or not.
    public function checkSimilarEventExists($user_intg_id, $event_name, $platform_id, $status=null){
        $query = DB::table('user_integration_sub_event AS p')
        ->join('platform_sub_event AS c1', 'c1.id', '=', 'p.sub_event_id')
        ->join('platform_events AS c2', 'c2.id', '=', 'c1.platform_event_id')
        ->select('p.id', 'p.status', 'p.sub_event_id', 'c1.name', 'c2.platform_id')
        ->where(['p.user_integration_id' => $user_intg_id, 'c1.name' => $event_name, 'c2.platform_id' => $platform_id]);
        if(isset($status)){
            $result = $query->where(['p.status' => $status])->get();
        }
        else{
            $result_temp = $query->get();
            $result = json_decode($result_temp, true);
        }
        return $result;
    }
}
