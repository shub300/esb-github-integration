<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\History;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Helper\MyappSnippet;
use App\Helper\MappingObjectDetail;

class LogHistoryController extends Controller
{
    public $objMyappSnip, $objMappingObjDetail;
    public function __construct()
    {
        $this->objMyappSnip = new MyappSnippet();
        $this->objMappingObjDetail = new MappingObjectDetail();
    }

    public function index(Request $request)
    {
        try {

    
            $user_id = Auth::user()->id;
            //parse mapping rules & push in array_mapping_label_by_object_id to furture use
            $array_mapping_label_by_object_id = [];

            //get timezone
            $gmtOffset = "+00:00";
            $dyn_updated_at = 'history.updated_at';
            $timezone = $this->objMyappSnip->getTimezone($user_id);
            if (!$timezone) {
                $timezone = $request->get('currentTimezone');
                //if storage has also timezone not found then set it 0 for any failure condition
                if (!$timezone) {
                    $timezone = "+00:00";
                }
            }   

           
    
            $user_integrationFilter = ($request->user_integrationFilter) ? $request->user_integrationFilter : NULL;
            $date_filter = ($request->date_filter) ? $request->date_filter : NULL;
    
    
            //get list of integration of logged user
            // $list_integrations = DB::table('user_integrations')->where('user_id',$user_id)->select('id','flow_name')->get();
            //Load integration those integration having history added
            $list_integrations = DB::table('history as his')
            ->join('user_integrations as ui','his.user_integration_id','ui.id')
            ->join('platform_integrations as pi','ui.platform_integration_id','pi.id')
            ->where('his.action_by',$user_id)->select('ui.id','ui.flow_name','pi.rule','pi.id as pltIntegId')->groupBy('ui.id')
            ->get();


            //loop $list_integrations for formate data
            $formated_rules_data = [];
            foreach($list_integrations as $integraion_row) {
                //passed single imploded wfId insted $listWF
                $pltIntegId = $integraion_row->pltIntegId;
                $decoded_rule = json_decode($integraion_row->rule, TRUE);
                $response_data = $this->objMappingObjDetail->getCustomLabelFromMappingRule($decoded_rule,$pltIntegId);
                if($response_data) {
                    $formated_rules_data[$pltIntegId] = $response_data;
                }
            }
            // dd($formated_rules_data);
        
            //get history
            $sql = DB::table('history')->join('user_integrations as ui','ui.id','history.user_integration_id')
            ->join('platform_integrations as pi','ui.platform_integration_id','pi.id')
            ->join('users','users.id','history.action_by')->whereIn('history.action_by',[$user_id]);
    
            //user integration filter
            if($user_integrationFilter) {
                $sql->where('history.user_integration_id',$user_integrationFilter);
            }
    
            if($date_filter) {
                $dateParts = explode(" - ", $date_filter);
                $fromDate = $dateParts[0];
                $from_date = date('Y-m-d', strtotime($dateParts[0]));
                $to_date = date('Y-m-d', strtotime(strtotime($dateParts[1])));
                $sql->whereBetween('history.updated_at', [$from_date.' 00:00:00', $to_date.' 23:59:59']);
                // $sql->whereBetween('history.updated_at', [$from_date, $to_date]);
            }

            // dd($user_integrationFilter,$date_filter);
    
            $sql->select('history.action','history.old_data','history.new_data','history.action_by','history.user_integration_id','ui.flow_name','ui.platform_integration_id','users.email','pi.id as pltIntegId',DB::raw("(CASE
            WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(history.updated_at,'" . $gmtOffset . "','" . $timezone . "')
            ELSE history.updated_at END) as updated_at"), DB::raw("(CASE
            WHEN '" . $timezone . "' !='" . $gmtOffset . "' THEN convert_tz(history.created_at,'" . $gmtOffset . "','" . $timezone . "')
            ELSE history.created_at END) as created_at"))->orderBy('history.id','desc');

            if($user_integrationFilter) {
                $integration = $sql->paginate(100);
            } else {
                $integration = $sql->paginate(6);
            }
            
            // dd($integration = $sql->toSql());

            return view("pages.user_history", compact('integration','list_integrations','user_integrationFilter','date_filter','formated_rules_data'));


        }  catch (\Exception $e) {
           return $e->getMessage();
        }

       
    
    }

}