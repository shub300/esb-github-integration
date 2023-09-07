<?php
namespace App\Helper;
use App\Helper\MainModel;
use DB;
class MyappSnippet
{
    public function getMyApps($userId,$searchVal=null)
    {
        $org_id = config('org_details.organization_id');

        $integration_query = DB::table('user_integrations as ui')
            ->join('platform_integrations as pi', 'ui.platform_integration_id', 'pi.id')
            ->join('platform_lookup as pl', 'pi.source_platform_id', 'pl.id')
            ->join('platform_lookup as pl1', 'pi.destination_platform_id', 'pl1.id')
            ->join('es_platform_access as pf_acs', 'pf_acs.platform_integration_id', '=', 'pi.id')
            ->select(
                'ui.id as usrIntegId',
                'ui.flow_name',
                'ui.platform_integration_id as IntegPlateformId',
                'pl.platform_id as source',
                'pl.platform_name as sourcePltName',
                'pl1.platform_id as destination',
                'pl1.platform_name as destinationPltName',
                'ui.workflow_status as ui_workflow_status',
                'ui.created_at',
                'ui.updated_at',
                'pl.platform_image as sourceImg',
                'pl1.platform_image as destinationImg'
            )
            ->where(['ui.user_id'=>$userId, 'pf_acs.organization_id'=>$org_id, 'pf_acs.status'=>1])
            ->where('pi.status', 1);

            if($searchVal){

                $integration_query->where(function($query) use ($searchVal){
                    $query->where('ui.flow_name', 'like', '%'.$searchVal.'%')
                   ->orWhere('pl.platform_name', 'like', '%'.$searchVal.'%')
                   ->orWhere('pl1.platform_name', 'like', '%'.$searchVal.'%');
                });
            }

            $integration = $integration_query->paginate(6);

        for ($m = 0; $m < count($integration); $m++) {
            $countFlow  = DB::table('platform_workflow_rule')->where('platform_integration_id', $integration[$m]->IntegPlateformId)
                ->where('status', 1)
                ->count();
            $integration[$m]->flowCount = $countFlow;

            $countActiveFlow = DB::table('user_workflow_rule as uwfr')
                ->join('user_integrations as ui', 'uwfr.user_integration_id', 'ui.id')
                ->join('platform_workflow_rule as pwfr', 'uwfr.platform_workflow_rule_id', 'pwfr.id')
                ->where('uwfr.user_integration_id', $integration[$m]->usrIntegId)
                ->where('uwfr.status', 1)
                ->where('ui.workflow_status', 'active')
                ->where('pwfr.status', 1)
                ->where('uwfr.user_id', $userId)
                ->count();
            $integration[$m]->ActiveflowCount = $countActiveFlow;
        }
        return $integration;
    }
    public function getTimezone($user_id)
    {
         //get timezone diffrence to show in logs & everywhere with datetime
         $timezoneData = DB::table('users_information as ui')->join('es_timezone as tz','tz.ISO_country_code','ui.iso_country_code')->select('tz.timezone')->where('ui.user_id',$user_id)->first();
        if($timezoneData){
            $timezone = $timezoneData->timezone;

            if($timezone > 0){
                $timezone = "+".str_replace(".",":",$timezone);
            } else {
                $timezone = str_replace(".",":",$timezone);
            }
        } else {
            // $timezone = "+00:00";
            $timezone =null;
        }

        return $timezone;
    }

    public function GetFlowList($userId,$userIntegId,$platformIntegId)
    {
        //get timezone
        $timezone = $this->getTimezone($userId);
        $gmtOffset = "+00:00";

        $result = DB::table('platform_workflow_rule as pfwfr')
            ->leftJoin('user_workflow_rule as uwfr','pfwfr.id','uwfr.platform_workflow_rule_id')
            ->join('platform_integrations as pi', 'pfwfr.platform_integration_id', 'pi.id')
            ->join('platform_events as pe', 'pfwfr.source_event_id', 'pe.id')
            ->join('platform_events as pe1', 'pfwfr.destination_event_id', 'pe1.id')
            ->join('platform_lookup as pl1','pe.platform_id','pl1.id')
            ->join('platform_lookup as pl2','pe1.platform_id','pl2.id')
            ->select(
                'pfwfr.id as pfwfrID',
                'pfwfr.platform_integration_id as pfIntegId',
                'pfwfr.status as wfrStatus',
                'pe.event_description as sourceEvent',
                DB::raw("convert_tz(uwfr.updated_at,'".$gmtOffset."','".$timezone."') AS last_update"),
                'pe1.event_description as destinationEvent',
                DB::raw("CONCAT(pe.event_description,' to ',pe1.event_description) AS full_name"),
                'pe.event_name as sourceEventType',
                'pe1.event_name as destEventType',
                'pl1.platform_name as sourcePlt','pl2.platform_name as destPlt',
                'pfwfr.tooltip_text',
                'pfwfr.is_transactional_flow as isTransFlow'
            )
            ->where('pfwfr.platform_integration_id', $platformIntegId)
            ->where('uwfr.user_integration_id', $userIntegId)
            ->where('pfwfr.status', 1) //check platform service is active or not
            ->orderBy('pfwfr.is_transactional_flow','asc')
            ->get();

        for ($m = 0; $m < count($result); $m++) {
            $dataUsrWFStatus  = DB::table('user_workflow_rule')
                ->where('platform_workflow_rule_id', $result[$m]->pfwfrID)
                ->where('user_integration_id', $userIntegId)
                // ->where('user_id', $userId)
                ->select('id', 'status', 'user_integration_id','is_all_data_fetched','updated_at')
                ->first();
            $result[$m]->uwfrID = null;
            $result[$m]->uwfrStatus = null;
            $result[$m]->userIntegId = null;

            if ($dataUsrWFStatus) {
                $result[$m]->uwfrID = $dataUsrWFStatus->id;
                $result[$m]->uwfrStatus = $dataUsrWFStatus->status;
                $result[$m]->userIntegId = $dataUsrWFStatus->user_integration_id;
                $result[$m]->IsAllDataFetched= ucfirst($dataUsrWFStatus->is_all_data_fetched);
                //$dataUsrWFStatus->updated_at
                $result[$m]->last_update= date_format(date_create($dataUsrWFStatus->updated_at),'d M Y H:i');
            }
        }
        return $result;
    }

    public function getPlatformWorkflowData($platform_integration_id)
    {
        $PlatformFlowData = DB::table('platform_workflow_rule as pfwfr')
            ->join('platform_integrations as pi','pfwfr.platform_integration_id','pi.id')
            ->join('platform_events as pe', 'pfwfr.source_event_id', 'pe.id')
            ->join('platform_events as pe1', 'pfwfr.destination_event_id', 'pe1.id')
            ->join('platform_lookup as pL','pe.platform_id','pL.id')
            ->join('platform_lookup as pL1','pe1.platform_id','pL1.id')
            //,'pe.mapping_rule as sourceRules','pe1.mapping_rule as destRules',
            ->select('pfwfr.id as pfwfrID', 'pfwfr.platform_integration_id as pltIntegId','pe.event_name as s_event_name', 'pe1.event_name', 'pe.event_name as sourceEvent',
            'pe1.event_name as destEvent','pfwfr.source_event_id',
            'pfwfr.destination_event_id','pL.id as sourcePlt','pL1.id as destPlt','pL.platform_name as sourcePltName','pL1.platform_name as destPltName')
            ->where('pfwfr.platform_integration_id', $platform_integration_id)
            ->where('pi.id',$platform_integration_id)
            ->get();
        return $PlatformFlowData;
    }

}
