<?php

namespace App\Http\Controllers;

use Auth;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\WorkflowSnippet;
use App\Helper\ConnectionHelper;
use App\Helper\Cache\CacheDecoder;
use DB;

class IntegrationController extends Controller
{
    public $wfsnip, $ConnectionHelper,$mobj, $cache;
    public function __construct()
    {
        $this->wfsnip = new WorkflowSnippet();
        $this->mobj = new MainModel();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->cache = new CacheDecoder();
    }

    public function index()
    {
        $platforms = $this->wfsnip->getPlatformOptions();
        $workflow = $this->wfsnip->getSuggestedWorkflow();
        return view("pages.integrations", compact('platforms', 'workflow'));
    }


    public function integrtaionStep2()
    {
        return view("pages.step2");
    }
    public function integrtaionStep3()
    {
        return view("pages.step3");
    }
    public function integrtaionStep4()
    {
        return view("pages.step4");
    }
    public function account()
    {
        return view("pages.account");
    }
    public function table()
    {
        return view("pages.table");
    }
    public function mapping()
    {
        return view("pages.mapping");
    }

    /* Delete Webhook */
    public function deleteWebhooks($user_id, $user_integration_id, $platform_id)
    {
        $Pname = $this->ConnectionHelper->getPlatformNameByID($platform_id);
        if($Pname == "woocommerce")
        {
            return app('App\Http\Controllers\Woocommerce\WoocommerceApiController')->CreateOrDeleteWebhook($user_id, $user_integration_id, ['all'], 2);
        }
        elseif($Pname == "brightpearl")
        {
            return app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->CreateOrDeleteWebhook($user_id, $user_integration_id, ['all'], 2);
        }
        elseif($Pname == "googlesheet")
        {
            return app('App\Http\Controllers\Google\GoogleSpreadsheetController')->logoutGoogleAccount($user_integration_id);
        }
        elseif($Pname == "shiphero")
        {
            return app('App\Http\Controllers\ShipHero\ShipHeroApiController')->DeleteWebhooks($user_id, $user_integration_id);
        }
        elseif($Pname == "shipbob")
        {
            return app('App\Http\Controllers\ShipBob\ShipBobApiController')->CreateOrDeleteWebhook($user_id, $user_integration_id, ['all'], 2);
        }
        elseif($Pname == "bigcommerce")
        {
            return app('App\Http\Controllers\Bigcommerce\BigcommerceController')->DeleteWebhooks($user_id, $user_integration_id);
        }
        elseif($Pname == "hubspot")
        {
            return app('App\Http\Controllers\HubSpot\HubSpotApiController')->CreateOrDeleteWebhook($user_id, $user_integration_id, 2);
        }
        elseif($Pname == "whmcs")
        {
            return app('App\Http\Controllers\Whmcs\WhmcsApiController')->CreateOrDeleteWebhook($user_id, $user_integration_id, [], 2);
        }
        elseif($Pname == "shipstation")
        {
            return app('App\Http\Controllers\Shipstation\ShipstationController')->deleteWebhook($user_id, $user_integration_id);
        }
        else
        {
            return true;
        }
        return false;
    }
    public function Disconnect(Request $request)
    {
        //clear Cache to disconnect integration 
        $this->cache->clearAllCacheForIntegration($request->userIntegId);

        $user_id = \Session::get('user_data')->id; // in case user_staff logged-in, it set parent_id (main user) in place of user_id. //Auth::user()->id;
        $user_integration_id = $request['userIntegId'];
        $platform_id = $request['platform_id'];
        $platform_account_id = $request['platform_account_id'];

        //check user integrations where the disconnecting platform in use
        $checkAcUseInMulti = DB::table('user_integrations')->where('user_id',$user_id)
        ->where(function($query) use ($platform_account_id){
                $query->where('selected_sc_account_id', $platform_account_id)
               ->orWhere('selected_dc_account_id', $platform_account_id);
        })->select('id as user_integration_id','user_id')->get();


        
        $response = true;
        if(count($checkAcUseInMulti) > 0){
            foreach($checkAcUseInMulti as $integData){
                //call delete deleteWebhooks
                $response = $this->deleteWebhooks($user_id, $integData->user_integration_id, $platform_id);
            }
        }

        // $response=$this->deleteWebhooks($user_id, $user_integration_id, $platform_id);
        $authUserData = \Session::get('user_data');
        $permissionStatus = $this->mobj->checkParentAndPermission('user_id' , $authUserData,  'platform_accounts' , 'id', $platform_account_id  ) ; //Accept 1. parentColumField  , 2. Auth::user() data , 3. tableName, 4. requestColumField, 5. requestId
        if(!$permissionStatus){
            return response()->json(['status_code' => 0, 'status_text' => "You don't have enough permission."]);
        }

        $status_code = 0;
        $status_text = $response;

        if ($response===true) {
            $proc_res = DB::select("CALL disconnectAccount(?, ?, ?, ?, ?)", [$user_id, $platform_id, $platform_account_id, $user_integration_id, "@response"]);
            if (isset($proc_res[0]) && isset($proc_res[0]->response) && $proc_res[0]->response) { // When response 1
                // return response()->json(['status_code' => 1, 'status_text' => 'Account disconnected successfully!']);
                $status_code = 1;
                $status_text = 'Account disconnected successfully!';
            } else {
                // return response()->json(['status_code' => 0, 'status_text' => 'Account not disconnected!']);
                $status_code = 0;
                $status_text = 'Account not disconnected!';
            }
        } 

        //Log in history before return
        $action = 'Account Disconnect';
        $action_by = Auth::user()->id;
        $log_data = [];
        $log_data['trigger_type'] = 'Account Disconnect';
        $log_data['platform'] = $platform_id;
        $log_data['description'] = $status_text;
        History::insert([ 'action'=>$action,'action_by'=>$action_by,'user_integration_id'=>$user_integration_id,'old_data'=>json_encode($log_data),'new_data' => NULL,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=> date('Y-m-d H:i:s')]);
        //end


        return response()->json(['status_code' => $status_code, 'status_text' => $status_text]);

    }
    public function showIntegrationLogData(Request $request)
    {
        $edit = 1; // TeamMemberManageCtrl::getUserRight(Auth::user()->id, Auth::user()->role, 'developers', 'edit');
        //$user_data =  Session::get('admin_user_data');
        //$org_id = $user_data['organization_id'];
        $user_id = $request->get('user_id');
        $user_intg_id = $request->get('user_intg_id');
        $columns = array(
            'info', 'source_platform_name', 'dest_platform_name'
        );



        $query_products = DB::table('platform_product AS A')
            ->select(
                'A.user_id',
                'A.user_integration_id',
                'A.platform_id',
                'A.product_name AS info',
                DB::raw("'Inventory' AS type"),
                'A.updated_at AS last_run',
                'A.inventory_sync_status AS status',
                'B.flow_name',
                'D.platform_name AS source_platform_name',
                'E.platform_name AS dest_platform_name'
            )
            ->join('user_integrations AS B', 'B.id', '=', 'A.user_integration_id')
            ->join('platform_integrations AS C', 'C.id', '=', 'B.platform_integration_id')
            ->join('platform_lookup AS D', 'D.platform_id', '=', 'C.source_platform_id')
            ->join('platform_lookup AS E', 'E.platform_id', '=', 'C.destination_platform_id')
            ->where(['A.user_integration_id' => 16, 'A.user_id' => 112]);

        $query_orders =  DB::table('platform_order AS A')
            ->select(
                'A.user_id',
                'A.user_integration_id',
                'A.platform_id',
                'A.order_number AS info',
                DB::raw("'Order' AS type"),
                'A.updated_at AS last_run',
                'A.sync_status AS status',
                'B.flow_name',
                'D.platform_name AS source_platform_name',
                'E.platform_name AS dest_platform_name'
            )
            ->join('user_integrations AS B', 'B.id', '=', 'A.user_integration_id')
            ->join('platform_integrations AS C', 'C.id', '=', 'B.platform_integration_id')
            ->join('platform_lookup AS D', 'D.platform_id', '=', 'C.source_platform_id')
            ->join('platform_lookup AS E', 'E.platform_id', '=', 'C.destination_platform_id')
            ->where(['A.user_integration_id' => 16, 'A.user_id' => 112]);

        $union_query = $query_orders->union($query_products);
        $totalData = DB::query()->fromSub($union_query, 'union_query')->count();
        $uniqueData = DB::query()->fromSub($union_query, 'union_query');
        $totalFiltered = $totalData;
        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');
        $search = $request->input('search.value');

        $query = $uniqueData->where(function ($query1) use ($search, $columns) {
            if ($search != '') {
                for ($i = 0; $i < count($columns); $i++) {
                    $query1->orWhere($columns[$i], 'like', '%' . $search . '%');
                }
            }
        });

        $totalFiltered = $query->count();
        $result = $query->orderBy($order, $dir)->skip($start)->take($limit)->get(); //->union($query_products)
        $data = array();
        if (!empty($result)) {
            foreach ($result as $key => $rv) {
                switch ($rv->status) {
                    case 'Pending':
                        $status = '<span class="right badge badge-info" id="badge" >Pending</span>';
                        break;
                    case 'Ready':
                        $status = '<span class="right badge badge-warning" id="badge" >Ready</span>';
                        break;
                    case 'Synced':
                        $status = '<span class="right badge badge-success" id="badge" >Synced</span>';
                        break;
                    case 'Failed':
                        $status = '<span class="right badge badge-danger" id="badge" >Failed</span>';
                        break;
                    default:
                        $status = "Not Found";
                }

                $nestedData['intg_platform'] = $rv->source_platform_name . "&nbsp;<i class='fa fa-long-arrow-right'></i>&nbsp;" . $rv->dest_platform_name;
                $nestedData['last_run'] = date('d-M-Y H:i A', strtotime($rv->last_run));
                $nestedData['info'] = (isset($rv->info) ? $rv->info : 'N/A');
                $nestedData['type'] = $rv->type;
                $nestedData['status'] = $status;
                $data[] = $nestedData;
            }
        }
        $json_data = array(
            "draw"            => intval($request->input('draw')),
            "recordsTotal"    => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data"            => $data
        );
        echo json_encode($json_data);
    }

    public function savePlatformIntegrationReferred($platform_id, $user_id)
    {
        $domain = $_SERVER['HTTP_HOST'];
        if ($domain == 'skuvault.apiworx.net') {
            $source_platform_id = 'skuvault';
            $platform_lookup_arr = DB::table('platform_lookup')->join('platform_integrations', function ($join) {
                $join->on('platform_lookup.id', '=', 'platform_integrations.destination_platform_id')
                    ->orOn('platform_lookup.id', '=', 'platform_integrations.source_platform_id');
            })
                ->where('platform_lookup.platform_id', '=', $platform_id)
                ->orwhere('platform_lookup.platform_id', '=', $source_platform_id)
                ->select('platform_integrations.id as platform_integrations_id')
                ->first();
            if ($platform_lookup_arr) {
                $es_platform_refrer_count =  $this->mobj->getFirstResultByConditions('es_platform_refrer', ['user_id' => $user_id, 'platform_integrations_id' => $platform_lookup_arr->platform_integrations_id, 'status' => 1], ['id']);
                if (!$es_platform_refrer_count) {
                    DB::table('es_platform_refrer')->insert(['user_id' => $user_id, 'domain' => $domain, 'platform_integrations_id' => $platform_lookup_arr->platform_integrations_id, 'platform_name' => 'wayfair']);
                } else { }
            }
        }
        return;
    }
}
