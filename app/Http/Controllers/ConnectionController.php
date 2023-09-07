<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use App\Helper\ConnectionHelper;
use App\Helper\MainModel;
use DB;
use App\Http\Controllers\MyAppsController;
use App\Helper\Cache\CacheDecoder;

class ConnectionController extends Controller
{
    public $conh,$mobj,$cache;
    public function __construct()
    {
        $this->conh = new ConnectionHelper();
        $this->mobj = new MainModel();
        $this->cache = new CacheDecoder();
    }

    public function index(Request $request, $ID)
    {
        $obj = new MainModel;
        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];
        $id = $ID; //$this->mobj->encrypt_decrypt( $id ,'decrypt');
        if ($id) {

            $con_data = $this->conh->getIntegrationById($id, $user_id);
            if (!$con_data) {
                return redirect('/integrations'); // when workflow id does not belongs to user
            }
            $sc = $dc = $workflow_status = '';
            $ac_connected_source =  $ac_connected_destination = 0;
            $sc = $con_data->source_account_id;
            $dc = $con_data->destination_account_id;
            $workflow_status = $con_data->workflow_status;
            if (isset($con_data->source_platform_id)) {

                $facc_source = $this->conh->getAccountPlatformByOrg($con_data->source_platform_id, $user_id);

                if ($con_data->source_account_id) {
                    $ac_connected_source = 1;
                }
            }
            if (isset($con_data->destination_platform_id)) {
                $facc_destination = $this->conh->getAccountPlatformByOrg($con_data->destination_platform_id, $user_id);
                if ($con_data->destination_account_id) {
                    $ac_connected_destination = 1;
                }
            }

            if ($ac_connected_source == 1 && $ac_connected_destination == 1 &&  $workflow_status == 'active') {
                return redirect('/integrations'); // when workflow id does not belongs to user
            }

            return view("pages.connection_settings", compact('con_data', 'id', 'ac_connected_source', 'ac_connected_destination', 'facc_source', 'facc_destination', 'sc', 'dc', 'workflow_status'));
        }
    }

    public function saveConnection(Request $request)
    {
        $user_data =  Auth::user();
        $obj = new MainModel;
        $objMyApp = new MyAppsController();
        try {
            $wfid = $request->wfid;

            $wf_info = $obj->getFirstResultByConditions('user_integrations', ['id' => $wfid]);

            if ($wf_info) {
                $findDuplicateApp = $obj->getFirstResultByConditions('user_integrations', ['selected_sc_account_id' => $request->selected_sc_account_id, 'selected_dc_account_id' => $request->selected_dc_account_id, 'selected_sc_account_id' => $request->selected_sc_account_id, 'user_id' => $user_data->id]);

                if (0 && $findDuplicateApp) {
                    return response()->json(['status_code' => 0, 'status_text' => 'Same account connection is being used in other integration!'], 200);
                } else {

                    
                    $this->cache->clearAllCacheForIntegration($request->userIntegId); //clear cache before update integration status 
                    

                    $obj->makeUpdate('user_integrations', ['selected_sc_account_id' => $request->selected_sc_account_id, 'selected_dc_account_id' => $request->selected_dc_account_id, 'workflow_status' => 'active'], ['id' => $wfid]);
                    $redirect_url = route('integration.integration_flow', ['id' => $wfid]);

                    //Start Save Mapping Date  Code added by gajendra    'statusData' => $request->statusData, 
                    $request_new = new \Illuminate\Http\Request();
                    $request_new->replace(['userIntegId' => $request->userIntegId, 'data' => $request->data, 'identData' => $request->identData, 'SynStartDate' => $request->SynStartDate,'fileMappingData'=>$request['fileMappingData']]);
                   
                    //if file mapping available upload files
                    $fileMappingData = json_decode($request['fileMappingData'], TRUE);
                    $userIntegId = $request['userIntegId'];
                    if($fileMappingData)
                    {
                        foreach ($fileMappingData['data'] as $data)
                        {
                                //upload images
                                $destinationPath = "public/esb_asset/".$data['platform']."/".$userIntegId;
                                if ($request->hasfile($data['custom_data'])) {
                                    $imagesName = "";
                                    foreach ($request->file($data['custom_data']) as $file) {
                                        $file->move($destinationPath,$file->getClientOriginalName());
                                        $imagesName .= ($imagesName) ? ',' : '';
                                        $imagesName .= $destinationPath.'/'.$file->getClientOriginalName();
                                    }
                                //insert mapping
                                DB::table('platform_data_mapping')->insert(
                                    [
                                        'platform_object_id' => isset($data['platform_object_id']) ? $data['platform_object_id'] : '',
                                        'mapping_type' => isset($data['mapping_type']) ? $data['mapping_type'] : '',
                                        'data_map_type' => isset($data['data_map_type']) ? $data['data_map_type'] : '',
                                        'platform_workflow_rule_id' => isset($data['platform_workflow_rule_id']) ? $data['platform_workflow_rule_id'] : '',
                                        'source_row_id' => isset($data['source_row_id']) ? $data['source_row_id'] : null,
                                        'destination_row_id' => isset($data['destination_row_id']) ? $data['destination_row_id'] : null,
                                        'custom_data' => $imagesName,
                                        'status' => 1,
                                        'user_integration_id' => $userIntegId,
                                    ]);
            
                                }  
                        }
                    }
                    //end upload files
                    //send rest data to store mapping function
                    $res1 = $objMyApp->storeMapping($request_new);
                    //end 

                    return response()->json(['status_code' => 1, 'status_text' => 'Connection activated successfully!', 'redirect_url' => $redirect_url], 200);
                }
            } else {
                return response()->json(['status_code' => 0, 'status_text' => 'Connection not found'], 200);
            }
        } catch (\Exception $e) {
            return response()->json(['status_code' => 0, 'status_text' => $e->getMessage()], 200);
        }
    }


    public function getConnectedAccountInfo(Request $request)
    {
        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];
        try {
            $pltData = DB::table('platform_lookup')->where('platform_id',$request->platform_id)->select('id')->first();
            $platform_id = $pltData->id;
    
            $facc = $this->conh->getAccountPlatformByOrg($platform_id, $user_id);

            $msg =  Session::get('auth_msg');

            if ($msg == '') {
                //$ac_connected = 0;
                // $msg = "";
                if ($facc['data']) {
                    //  $ac_connected = 1;
                    $msg = 'Success';
                    return response()->json(['status_code' => 1, 'status_text' => 'Success', 'ac_connected' => $facc['data']], 200);
                } else {
                    $msg =  Session::get('auth_msg');
                    Session::forget('auth_msg');
                    return response()->json(['status_code' => 0, 'status_text' => $msg, 'ac_connected' => $facc['data']], 200);
                }
            } else {
                Session::forget('auth_msg');
                return response()->json(['status_code' => 0, 'status_text' => $msg, 'ac_connected' => $facc['data']], 200);
            }
        } catch (\Exception $e) {
            return response()->json(['status_code' => 0, 'status_text' => $e->getMessage()], 200);
        }
    }


    public function validateAccountName(Request $request)
    {

        $platform = trim($request->platform);
        $account_id = trim($request->account_id);
        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];

        $checkduplicate =  $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'platform_id' => $platform, 'account_name' => $account_id], ['id']);
        if ($checkduplicate) {
            if ($platform == 'intacct') {
                return response()->json(['status_code' => 0, 'status_text' => 'Company ID Already Exists!'], 200);
            } else {
                return response()->json(['status_code' => 0, 'status_text' => 'Account Name Already Exists!'], 200);
            }
        } else {
            return response()->json(['status_code' => 1, 'status_text' => 'Success!'], 200);
        }
    }
}
