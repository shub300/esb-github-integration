<?php

namespace App\Http\Controllers\Klaviyo;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Auth;
use DB;
use App\Helper\MainModel;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\ConnectionHelper;
use Illuminate\Support\Facades\Session;
use Lang;
class KlaviyoApiController extends Controller
{
    public static $my_platform_name = 'klaviyo';

    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->map = new FieldMappingHelper();
        $this->log = new Logger();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$my_platform_name);
    }

    public function InitiateKlaviyoAuth(Request $request)
    {
        $platform = self::$my_platform_name;
        return view("pages.apiauth.klaviyo_auth", compact('platform'));
    }

    public function checkExistingConnectedAc($platform_id, $public_key, $private_key){
        $enc_public_key = $this->mobj->encrypt_decrypt($public_key);
        $enc_private_key = $this->mobj->encrypt_decrypt($private_key);
        $obj_existing = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $platform_id, 'app_id' => $enc_public_key,  'app_secret' => $enc_private_key], ['user_id']);
        if ($obj_existing) {
            return true;
        }
        else{
            return false;
        }
    }

    public function ConnectKlaviyo(Request $request)
    {
        $klaviyo_account_name = trim($request->klaviyo_account_name);
        $klaviyo_public_key = trim($request->klaviyo_public_key);
        $klaviyo_private_key = trim($request->klaviyo_private_key);
        $base_url = \Config::get('apiconfig.klaviyoBaseUrl');
        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];

        $flag = true;
        $data = [];

        if( $this->mobj->checkHtmlTags( $request->all() ) ){
            $data['status_code'] = 0;
            $data['status_text'] = Lang::get('tags.validate');
            return json_encode($data);
        }

        try {
            $is_existing_name = $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'account_name' => $klaviyo_account_name, 'platform_id' => $this->platformId], ['id']);
            if($is_existing_name){
                $data['status_code'] = 0;
                $data['status_text'] = 'Account name identifier is already exist with the same user, Try with another name.';
                return json_encode($data);
            }

            $is_existing_ac = $this->checkExistingConnectedAc($this->platformId, $klaviyo_public_key, $klaviyo_private_key);
            if($is_existing_ac){
                $data['status_code'] = 0;
                $data['status_text'] = 'Given details are already in use, Try with other details.';
                return json_encode($data);
            }

            $result = $this->mobj->makeCurlRequest('GET', $base_url . '/v2/lists?api_key='.$klaviyo_private_key, [], []);
            $response = json_decode($result, true);

            if(count($response)){
                $arr_field = [
                    'account_name' => $klaviyo_account_name,
                    'app_id' => $this->mobj->encrypt_decrypt($klaviyo_public_key),
                    'app_secret' => $this->mobj->encrypt_decrypt($klaviyo_private_key),
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                ];
                $response = $this->mobj->makeInsertGetId('platform_accounts', $arr_field);
            }
            else{
                $flag = false;
                $data['status_code'] = 0;
                $data['status_text'] = 'Authentication Error';
            }

            if ($flag) {
                $data['status_code'] = 1;
                $data['status_text'] = 'Account connected successfully.';
            }
            return json_encode($data);
        } catch (\Exception $e) {
            $data['status_code'] = 0;
            $data['status_text'] = $e->getMessage();
            return json_encode($data);
        }
    }

    public function storeListData($user_id, $user_integration_id)
    {
        try {
            $return_response = false;
            $platform_id = $this->platformId;

            $klaviyo_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['app_id', 'app_secret', 'platform_id']);

            if ($klaviyo_account) {
                $public_key = $this->mobj->encrypt_decrypt($klaviyo_account->app_id, 'decrypt');
                $private_key = $this->mobj->encrypt_decrypt($klaviyo_account->app_secret, 'decrypt');
                $endpoint = '/v2/lists?api_key='.$private_key;
                $base_url = \Config::get('apiconfig.klaviyoBaseUrl');
                $request_url =  $base_url . $endpoint;

                $result = $this->mobj->makeCurlRequest('GET', $request_url, [], []);
                $response = json_decode($result, true);

                if(count($response)){
                    // Insert/Update Klaviyo List details
                    $object_id = $this->ConnectionHelper->getObjectId('member_group_list');
                    $arrListIds = [];
                    foreach ($response as $list) {
                        $arr_list = [];
                        $arr_list['user_id'] = $user_id;
                        $arr_list['user_integration_id'] = $user_integration_id;
                        $arr_list['platform_id'] = $platform_id;
                        $arr_list['platform_object_id'] = $object_id;
                        $arr_list['api_id'] = $list['list_id'];
                        $arr_list['name'] = $list['list_name'];

                        array_push($arrListIds, $list['list_id']);

                        $existing_list = $this->mobj->getFirstResultByConditions('platform_object_data',
                                        [
                                            'platform_id' => $platform_id,
                                            'user_integration_id' => $user_integration_id,
                                            'platform_object_id' => $object_id,
                                            'api_id' => $list['list_id']
                                        ], ['id']);

                        if ($existing_list) {
                            $list_id = $existing_list->id;
                            $this->mobj->makeUpdate('platform_object_data', $arr_list, ['id' => $list_id]);
                        }
                        else {
                            $list_id = $this->mobj->makeInsertGetId('platform_object_data', $arr_list);
                        }
                    }

                    DB::table('platform_object_data')->whereNotIn('api_id', $arrListIds)
                    ->where([
                        'platform_id' => $platform_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_object_id' => $object_id
                    ])->delete();
                }
                else {
                    $return_response = $result;
                }
            }
            else{
                $return_response = 'Account details not found!';
            }
        } catch (\Exception $e) {
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    public function createUpdateCustomers($user_id = '', $user_integration_id = '', $user_workflow_rule_id = '', $source_platform_name = '', $platform_workflow_rule_id = '', $record_id = '')
    {
        try {
            $return_response = true;
            $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
            $object_id = $this->ConnectionHelper->getObjectId('customer');
            $klaviyo_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['app_id', 'app_secret', 'platform_id']);
            if ($klaviyo_account) {
                $public_key = $this->mobj->encrypt_decrypt($klaviyo_account->app_id, 'decrypt');
                $private_key = $this->mobj->encrypt_decrypt($klaviyo_account->app_secret, 'decrypt');
                $list_map = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, 'member_group_list', ['api_id'], 'default', '', 'single');
                if(!$list_map){
                    return false;
                }
                $list_id = $list_map->api_id;
                $endpoint = '/v2/list/'.$list_id.'/members?api_key='.$private_key;
                $base_url = \Config::get('apiconfig.klaviyoBaseUrl');
                $request_url =  $base_url . $endpoint;

                $page_limit = 20;
                do {
                    $flag = false;
                    $arr_customer_post = [];

                    $query = DB::table('platform_customer AS cust')
                    ->leftJoin('es_country_codes AS code', 'code.iso', '=', 'cust.country')
                    ->select('cust.id', 'cust.email', 'cust.phone', 'cust.first_name', 'cust.last_name', 'cust.customer_name', 'cust.postal_addresses', 'cust.address2',
                    'cust.address3', 'code.name AS country_name')
                    ->where(['cust.user_id'=>$user_id, 'cust.user_integration_id'=>$user_integration_id, 'cust.platform_id'=>$source_platform_id]);
                    if($record_id){
                        $query->where('cust.id', $record_id);
                    }else{
                        $query->where('cust.sync_status', 'Ready');
                    }
                    $customer_arr = $query->orderBy('cust.updated_at', 'ASC')->limit($page_limit)->get();

                    if( count($customer_arr) == 0 ){
                        $customer_arr = DB::table('platform_customer AS cust')
                        ->leftJoin('es_country_codes AS code', 'code.iso', '=', 'cust.country')
                        ->select('cust.id', 'cust.email', 'cust.phone', 'cust.first_name', 'cust.last_name', 'cust.customer_name', 'cust.postal_addresses', 'cust.address2',
                        'cust.address3', 'code.name AS country_name')
                        ->where(['cust.user_id'=>$user_id, 'cust.user_integration_id'=>$user_integration_id, 'cust.platform_id'=>$source_platform_id, 'cust.sync_status'=>'Failed'])
                        ->whereNotNull('cust.email')->orderBy('cust.updated_at', 'ASC')
                        ->limit($page_limit)->get();
                    }
                    ///\Storage::append('zyx_klaviyo_issue.txt', 'user_intg_id: ' . print_r($user_integration_id, true) . ' | customer_arr: ' . print_r($customer_arr, true));
                    if(count($customer_arr)){
                        $arr_customer_id = [];
                        foreach ($customer_arr as $key => $value) {
                            if($value->email){

                                // Validate email before proceed
                                if (!filter_var($value->email, FILTER_VALIDATE_EMAIL)) {
                                    $msg = "Invalid email address.";
                                    $this->mobj->makeUpdate('platform_customer', ['sync_status' => 'Failed'], ['id' => $value->id]);
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $value->id, $msg);
                                    $return_response = $msg;
                                    continue;
                                }

                                $temp_arr = [];
                                $arr_customer_id[] = $value->id;

                                // Getting customer's first and last name
                                if($value->first_name && $value->last_name){
                                    $first_name = $value->first_name;
                                    $last_name = $value->last_name;
                                } else if($value->customer_name){
                                    $parts = explode(" ", $value->customer_name);
                                    $first_name = $parts[0];
                                    $last_name = (isset($parts[1]) ? $parts[1] : null);
                                } else{
                                    $first_name = $last_name = null;
                                }

                                // Getting customer's address details
                                $city = (!$value->address2 ? null : $value->address2);
                                $region = (!$value->address3 ? null: $value->address3 );
                                $country = (!$value->country_name ? null : $value->country_name);
                                $zip = (!$value->postal_addresses ? null : $value->postal_addresses);
                                $phone = (!$value->phone ? null : $value->phone);
                                $temp_arr = [
                                    "email" => trim($value->email),
                                    "phone_number"=> $phone,
                                    "first_name"=> $first_name,
                                    "last_name"=> $last_name,
                                    "city"=> $city,
                                    "region"=> $region,
                                    "country"=> $country,
                                    "zip"=> $zip,
                                ];
                                $arr_customer_post["profiles"][] = $temp_arr;
                            }
                            else{
                                $msg = "Member email not found.";
                                $this->mobj->makeUpdate('platform_customer', ['sync_status' => 'Failed'], ['id' => $value->id]);
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $value->id, $msg);
                                $return_response = $msg;
                            }
                        }

                        if($arr_customer_post && !empty($arr_customer_post)){
                            $post_data = json_encode($arr_customer_post, true);
                            $header = [
                                "Accept: application/json",
                                "Content-Type: application/json"
                            ];

                            \Storage::append('klaviyo/'.date('Y-m-d').'/customer_sync_log.txt', "\r\n" . 'user_intg_id: ' . print_r($user_integration_id, true) . ' | post_data: ' . print_r($post_data, true));
                            $result = $this->mobj->makeCurlRequest('POST', $request_url, $post_data, $header);
                            $response = json_decode($result, true);
                            \Storage::append('klaviyo/'.date('Y-m-d').'/customer_sync_log.txt', 'user_intg_id: ' . print_r($user_integration_id, true) . ' | response_data: ' . print_r($response, true));

                            if(isset($response[0]['id'])){
                                foreach ($response as $key => $custr) {
                                    $msg = 'Customer details synced successfully!';
                                    $this->mobj->makeUpdate('platform_customer', ['sync_status' => 'Synced'], ['email' => $custr['email']]);
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', '', $msg);
                                }
                            }else{
                                $msg = (isset($response['detail']) ? $response['detail'] : "Member email not found.");
                                $this->mobj->makeUpdate('platform_customer', ['sync_status' => 'Failed'], ['id' => $value->id]);
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $value->id, $msg);
                                $return_response = $msg;
                            }
                        }
                        /*if (count($customer_arr) <  $page_limit) {
                            $flag = false;
                        }*/
                    }
                } while ($flag);
            }
            else{
                $return_response = "Account details not found.";
            }
        }
        catch (\Exception $e) {
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    public function createUpdateOrders($user_id = '', $user_integration_id = '', $user_workflow_rule_id = '', $source_platform_name = '', $platform_workflow_rule_id = '', $record_id = '')
    {
        $return_response = true;
        $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);

        $klaviyo_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['app_id', 'app_secret', 'platform_id']);

        if ($klaviyo_account) {
            $public_key = $this->mobj->encrypt_decrypt($klaviyo_account->app_id, 'decrypt');
            $private_key = $this->mobj->encrypt_decrypt($klaviyo_account->app_secret, 'decrypt');

            $endpoint = '/track';
            $base_url = \Config::get('apiconfig.klaviyoBaseUrl');
            $request_url =  $base_url . $endpoint;
            $object_id = $this->ConnectionHelper->getObjectId('sales_order');
            $page_limit = 20;
            do {
                $flag = false;
                $query = DB::table('platform_order')->select('id', 'platform_customer_id')
                ->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id]);
                if($record_id){
                    $query->where('id', $record_id);
                }
                else{
                    $query->where('sync_status', 'Ready');
                }
                $order_arr = $query->limit($page_limit)->get();

                if(count($order_arr)){
                    foreach ($order_arr as $key => $ord) {
                        sleep(2);
                        // Retrieve customer details
                        $customer_arr = DB::table('platform_customer AS cust')
                        ->leftJoin('es_country_codes AS code', 'code.iso', '=', 'cust.country')
                        ->select('cust.id', 'cust.email', 'cust.first_name', 'cust.last_name', 'cust.customer_name', 'cust.postal_addresses', 'cust.address2',
                        'cust.address3', 'code.name AS country_name')
                        ->where(['cust.id' => $ord->platform_customer_id])->first();
                        if($customer_arr){
                            if($customer_arr->email){
                                // Getting customer's first and last name
                                if($customer_arr->first_name && $customer_arr->last_name){
                                    $first_name = $customer_arr->first_name;
                                    $last_name = $customer_arr->last_name;
                                } else if($customer_arr->customer_name){
                                    $parts = explode(" ", $customer_arr->customer_name);
                                    $first_name = $parts[0];
                                    $last_name = (isset($parts[1]) ? $parts[1] : null);
                                } else{
                                    $first_name = $last_name = null;
                                }

                                // Getting customer's address details
                                $city = (!$customer_arr->address2 ? null : $customer_arr->address2);
                                $region = (!$customer_arr->address3 ? null: $customer_arr->address3 );
                                $country = (!$customer_arr->country_name ? null : $customer_arr->country_name);
                                $zip = (!$customer_arr->postal_addresses ? null : $customer_arr->postal_addresses);

                                // Retrieve line item details
                                $item_arr = $this->mobj->getResultByConditions('platform_order_line', ['platform_order_id' => $ord->id], ['api_product_id', 'product_name', 'sku', 'qty', 'total_tax', 'total', 'unit_price']);
                                if(count($item_arr)){
                                    $arr_line_items = [];
                                    $arr_item_details = [];
                                    $arrItemNames = [];
                                    $order_total = 0;
                                    foreach ($item_arr as $key => $item) {
                                        $temp_arr = [];
                                        $temp_item_arr = [];
                                        $order_total = $order_total + ($item->total_tax + $item->total);
                                        $arrItemNames[] = $item->product_name;
                                        $temp_item_arr = [
                                            "ProductID"=> $item->api_product_id,
                                            "SKU"=> $item->sku,
                                            "ProductName"=> $item->product_name,
                                            "Quantity"=> $item->qty,
                                            "ItemPrice"=> $item->unit_price,
                                        ];

                                        $temp_arr['ItemNames'] = $arrItemNames;
                                        $temp_arr['$value'] = $order_total;
                                        $arr_item_details[] = $temp_item_arr;
                                        $arr_line_items = $temp_arr;
                                    }
                                    $arr_line_items['Items'] = $arr_item_details;

                                    // Creating post data
                                    $arr_order_post = [];
                                    $EMAIL = '$email'; $FNAME = '$first_name'; $LNAME = '$last_name';
                                    $CITY = '$city'; $REGION = '$region'; $COUNTRY = '$country'; $ZIP = '$zip';
                                    $arr_order_post = [
                                        "token"=> $public_key,
                                        "event"=> "Placed Order",
                                        "customer_properties"=> [
                                            "$EMAIL"=> trim($customer_arr->email),
                                            "$FNAME"=> $first_name,
                                            "$LNAME"=> $last_name,
                                            "$CITY"=> $city,
                                            "$REGION"=> $region,
                                            "$COUNTRY"=> $country,
                                            "$ZIP"=> $zip,
                                        ],
                                        "properties"=> $arr_line_items,
                                    ];

                                    $post_data = 'data='.json_encode($arr_order_post, true);
                                    $header = [
                                        "Accept: text/html",
                                        "Content-Type: application/x-www-form-urlencoded"
                                    ];

                                    \Storage::append('klaviyo/'.date('Y-m-d').'/order_sync_log.txt', "\r\n" . 'user_intg_id: ' . print_r($user_integration_id, true) . ' | post_data: ' . print_r($post_data, true));
                                    $result = $this->mobj->makeCurlRequest('POST', $request_url, $post_data, $header);
                                    \Storage::append('klaviyo/'.date('Y-m-d').'/order_sync_log.txt', 'user_intg_id: ' . print_r($user_integration_id, true) . ' | response_data: ' . print_r($result, true));

                                    if($result == 1){
                                        $msg = 'Placed Order Matrics synced successfully!';
                                        $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Synced'], ['id' => $ord->id]);
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $ord->id, $msg);
                                    }
                                    else{
                                        $msg = "Unknown error found.";
                                        $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $ord->id]);
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $ord->id, $msg);
                                        $return_response = $msg;
                                    }
                                }
                                else{
                                    $msg = "Order line item not found.";
                                    $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $ord->id]);
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $ord->id, $msg);
                                    $return_response = $msg;
                                }
                            }
                            else{
                                $msg = "Member email not found, can't sync order without valid member email.";
                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $ord->id]);
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $ord->id, $msg);
                                $return_response = $msg;
                            }
                        }
                    }
                }
            } while ($flag);
        }
        else{
            $return_response = "Account details not found.";
        }
        return $return_response;
    }

    public function ExecuteEventKlaviyo($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        try {
            $response = true;
            if ($method == 'GET' && $event == 'MEMBERGROUPLIST') {
                $response = $this->storeListData($user_id, $user_integration_id);
            }
            else if ($method == 'MUTATE' && $event == 'CUSTOMER') {
                $response = $this->createUpdateCustomers($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
            }
            else if ($method == 'MUTATE' && $event == 'SALESORDER') {
                $response = $this->createUpdateOrders($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id);
            }

            return $response;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}