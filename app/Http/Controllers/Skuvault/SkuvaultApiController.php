<?php

namespace App\Http\Controllers\Skuvault;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Logger;
use App\Http\Controllers\Skuvault\Api\SkuvaultApi;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Http\Controllers\WorkflowController;
use App\Helper\WorkflowSnippet;
use Lang;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Carbon; 

class SkuvaultApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public static $my_platform_name = 'skuvault';
    //public static $my_platform_id='';
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->SkuvaultApi = new SkuvaultApi();
        $this->log = new Logger();
        $this->mapping = new FieldMappingHelper();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->my_platform_id = $this->ConnectionHelper->getPlatformIdByName(self::$my_platform_name);
    }

    public function InitiateSkuvaultAuth(Request $request)
    {
        $platform = self::$my_platform_name;
        return view("pages.apiauth.auth_skuvault", compact('platform'));
    }

    public function ConnectSkuvaultAuth(Request $request)
    {
        //server validation
        $validated = $request->validate([
            'skuvault_email' => 'required|email',
            'skuvault_password' => 'required',
            'account_name' => 'required',
        ]);

        $account_name = trim($request->account_name);
        $sku_email = trim($request->skuvault_email);
        $sku_pwd = trim($request->skuvault_password);
        $env_type = trim($request->env_type);

        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];

        $data = [];

        if($this->mobj->checkHtmlTags( $request->all() ) ){
            $data['status_code'] = 0;
            $data['status_text'] = Lang::get('tags.validate');
            return json_encode($data);
        }
        
        try {
            $existing_skuvault = $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'account_name' => $account_name, 'platform_id' => $this->my_platform_id], ['id']);
            $flag = true;
            if (!$existing_skuvault) {
                //make curl request
                $post_data = json_encode(["Email" => $sku_email, "Password" => $sku_pwd], true);
                $header = ['Content-Type: application/json', 'Accept: application/json'];

                if ($env_type == 'on') { // checke account type .
                    $url =  \Config::get('apiconfig.SkuvaultUrl');
                    $env_type = 'production';
                } else {
                    $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
                    $env_type = 'sandbox';
                }

                $getSkuvaultToken = $this->SkuvaultApi->GetSKVToken($sku_email, $sku_pwd, $url);

                $skuvault_result = json_decode($getSkuvaultToken, true);
                // echo "<pre>";print_r($skuvault_result);exit;

                if (isset($skuvault_result['TenantToken']) && $skuvault_result['TenantToken'] != null) {

                    $enc_refresh_token = $this->mobj->encrypt_decrypt($skuvault_result['TenantToken'], $action = 'encrypt');
                    $obj_existing = $this->mobj->getFirstResultByConditions('platform_accounts', ['refresh_token' => $enc_refresh_token, 'platform_id' => $this->my_platform_id], ['user_id']);
                    if ($obj_existing) {
                        $flag = false;
                        $data['status_code'] = 0;
                        $data['status_text'] = 'Given details are already in use, Try with other details.';
                        return json_encode($data);
                    }

                    // store/update skuvault token
                    $skuvault_tokens = array(
                        'user_id' => $user_id,
                        'platform_id' => $this->my_platform_id,
                        'account_name' => $account_name,
                        'refresh_token' => $this->mobj->encrypt_decrypt($skuvault_result['TenantToken'], $action = 'encrypt'),
                        'access_token' => $this->mobj->encrypt_decrypt($skuvault_result['UserToken'], $action = 'encrypt'),
                        'env_type' => $env_type
                    );

                    DB::table('platform_accounts')->insert($skuvault_tokens);
                } else {
                    $flag = false;
                    $data['status_code'] = 0;
                    $data['status_text'] = 'Sign-in information is incorrect';
                }
            } else {
                $flag = false;
                $data['status_code'] = 0;
                $data['status_text'] = 'Account name identifier is already exist with the same user, Try with another name.';
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

    
    public function SkuvaultGetProduct($user_id = '', $user_integration_id = '', $time_sync = false)
    {
        try {
            $url = '';
            $api_updated_at = '';
            $PageNumber = 0;
            $PageSize = 1000;
            $return_data = true;
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'platform_id', 'env_type']);
            if ($ufound) {

                if ($ufound->env_type == 'production') { // checke account type .
                    $url =  \Config::get('apiconfig.SkuvaultUrl');
                } else {
                    $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
                }
                //do {
                if ($time_sync) {
                    $limit = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url_name' => 'getproduct_limit'], ['url','id']);
                    if ($limit) {
                        $PageNumber = $limit->url;
                    }
                }
                $allow_next_cal = false;
                $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
                $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
                $curl_post_data["PageSize"] = $PageSize;

                if (!$time_sync) {
                    $product_backup_url = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url_name' => 'product_backup_time'], ['url', 'id']);
                    if(isset($product_backup_url->url) && $product_backup_url->url){
                        $productsBackupRange = explode('|', $product_backup_url->url);
                        $backupUrl = array_filter($productsBackupRange);
                        if(count($backupUrl)>2){ //getting modified before &  modified after from db (backup url)
                            $ModifiedBeforeDateTimeUtc = Carbon::parse(trim($backupUrl[1]))->format('Y-m-d\TH:i:s.u\Z');
                            $ModifiedAfterDateTimeUtc = Carbon::parse(trim($backupUrl[0]))->sub(3, 'sec')->format('Y-m-d\TH:i:s.u\Z');
                            $PageNumber = isset($backupUrl[2]) ? trim($backupUrl[2]): $PageNumber;
                        }else{ // if product not found on last run getting end date (only) from backup
                            $ModifiedBeforeDateTimeUtc = Carbon::now()->format('Y-m-d\TH:i:s.u\Z');
                            $ModifiedAfterDateTimeUtc = Carbon::parse(trim($backupUrl[0]))->sub(3, 'sec')->format('Y-m-d\TH:i:s.u\Z');

                            $backupUrl = $ModifiedAfterDateTimeUtc.'|'.$ModifiedBeforeDateTimeUtc.'|'.$PageNumber;
                            $this->mobj->makeUpdate('platform_urls', ['url' => $backupUrl], ['id' => $product_backup_url->id]);
                        }
                    }else{
                        $ModifiedBeforeDateTimeUtc = Carbon::now()->format('Y-m-d\TH:i:s.u\Z');
                        $ModifiedAfterDateTimeUtc = "2023-01-01T23:59:50.000000Z";
                        //insert product_backup_time url very first time
                        $backupUrl = $ModifiedAfterDateTimeUtc.'|'.$ModifiedBeforeDateTimeUtc.'|'.$PageNumber;
                        $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url' => $backupUrl, 'url_name' => 'product_backup_time']);
                    }
                    $curl_post_data["ModifiedBeforeDateTimeUtc"] = $ModifiedBeforeDateTimeUtc;
                    $curl_post_data["ModifiedAfterDateTimeUtc"] = $ModifiedAfterDateTimeUtc;
                    
                }
                $curl_post_data["PageNumber"] = $PageNumber;
                $request_data_json = json_encode($curl_post_data);
                $response = $this->SkuvaultApi->GetProduct($request_data_json, $url);
                $Product_data = json_decode($response, true);
                if (count($Product_data['Products'])) {
                    $allow_next_cal = true;
                    foreach ($Product_data['Products'] as $Product) {
                        $api_product_id = '';
                        $Product_Data = [
                            'user_id' => $user_id,
                            'platform_id' => $ufound->platform_id,
                            'user_integration_id' => $user_integration_id,
                        ];
                        if (isset($Product['Id'])) {
                            $Product_Data['api_product_id'] = $Product['Id'];
                            $api_product_id = $Product['Id'];
                        }
                        if (isset($Product['Code'])) {
                            $Product['Code'];
                        }
                        if (isset($Product['Sku'])) {
                            $Product_Data['sku'] = $Product['Sku'];
                        }
                        if (isset($Product['Description'])) {
                            $Product_Data['product_name'] = $Product['Description'];
                            $Product_Data['description'] = $Product['Description'];
                        }
                        if (isset($Product['Classification'])) {
                            $Product_Data['category_id'] = $Product['Classification'];
                        }
                        if (isset($Product['Brand'])) {
                            $Product_Data['brand_id'] = $Product['Brand'];
                        }
                        if (isset($Product['Supplier'])) {
                            $Product_Data['api_warehouse_id'] =  $Product['Supplier'];
                        }
                        if (isset($Product['Cost'])) {
                            $Product_Data['price'] = $Product['Cost'];
                        }
                        if (isset($Product['ModifiedDateUtc'])) {
                            $Product_Data['api_updated_at'] = $Product['ModifiedDateUtc'];
                            $api_updated_at = $Product['ModifiedDateUtc'];
                        }

                        //Changes made by gajendra on 08-11-2022 for accept mising product having same api_product ids

                        // $api_product = $this->mobj->getFirstResultByConditions('platform_product', ['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'api_product_id' => $api_product_id], ['id', 'api_updated_at']);

                        $api_product = $this->mobj->getFirstResultByConditions('platform_product', ['user_integration_id'=>$user_integration_id, 'platform_id' => $this->my_platform_id, 'sku' => $Product['Sku']], ['id', 'api_updated_at']);
                        
                        if ($api_product) {
                            if ($api_product->api_updated_at != $api_updated_at) {
                                $Product_Data['product_sync_status'] = 'Pending';
                            }
                            $this->mobj->makeUpdate('platform_product', $Product_Data, ['id' => $api_product->id]);
                        } else {
                            $this->mobj->makeInsert('platform_product', $Product_Data);
                        }
                    }
                    //update PageNumber for next call 
                    $return_data = 'data Remaining';
                    $PageNumber = $PageNumber + 1;
                    if(!$time_sync){
                        $AllowPageNumberUpdate = 1;
                    }
                }else{
                    $EmptyQty = 1; //set empty flag if product not found
                }
                //} while ($allow_next_cal);
                if ($time_sync) {
                    if ($limit) {
                        $this->mobj->makeUpdate('platform_urls', ['url' => $PageNumber], ['id' => $limit->id]);
                    } else {
                        $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url' => $PageNumber, 'url_name' => 'getproduct_limit']);
                    }
                }

                if(!$time_sync){

                    $product_backup_url = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url_name' => 'product_backup_time'], ['url', 'id']);
    
                    if(isset($product_backup_url->url) && $product_backup_url->url){
    
                        $productsBackupRange = explode('|', $product_backup_url->url);
                        $backupUrl = array_filter($productsBackupRange);
                                    
                        if(isset($AllowPageNumberUpdate)){ 
                            $backupUrl[2] = $PageNumber;
                            $this->mobj->makeUpdate('platform_urls', ['url' => implode('|',$backupUrl)], ['id' => $product_backup_url->id]); //update Pagenumber if product found for next call
                        }
                                    
                        if(isset($EmptyQty)){ 
                            if(count($backupUrl)>1){
                                $this->mobj->makeUpdate('platform_urls', ['url' => $backupUrl[1]], ['id' => $product_backup_url->id]); //save only end date if product not found 
                            }
                        }
                        
                    }
                }
            }
        } catch (\Exception $e) {
            $return_data = $e->getMessage();
        }
        
        return $return_data;
    }

    public function SkuvaultGetWarehouse($user_id = '', $user_integration_id = '')
    {
        try {
            // print_r($user_integration_id);
            $PageNumber = 0;
            $url = '';
            $object_id = '';
            $return_data = true;
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'platform_id', 'env_type']);
            if ($ufound) {
                if ($ufound->env_type == 'production') { // checke account type .
                    $url =  \Config::get('apiconfig.SkuvaultUrl');
                } else {
                    $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
                }
                $object_id = $this->ConnectionHelper->getObjectId('warehouse');
                //do {
                $limit = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url_name' => 'getwarehouse_limit'], ['url','id']);
                if ($limit) {
                    $PageNumber = $limit->url;
                }
                $allow_next_cal = false;
                $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
                $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
                $curl_post_data["PageNumber"] = $PageNumber;

                $request_data_json = json_encode($curl_post_data);
                $response = $this->SkuvaultApi->GetWarehouse($request_data_json, $url);
                $Warehouses_data = json_decode($response, true);

                if (count($Warehouses_data['Warehouses'])) {
                    if (count($Warehouses_data['Warehouses']) <= 100) {
                        $allow_next_cal = true;
                        $return_data = 'data Remaining';
                        $PageNumber = $PageNumber + 1;
                    }
                    //update users integration warehouse status to 0.
                    $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id, 'platform_id' => $this->my_platform_id]);
                    foreach ($Warehouses_data['Warehouses'] as $Warehouses) {
                        $WarehouseCode = $WarehouseName = '';
                        $Warehouses_Data = [
                            'user_id' => $user_id,
                            'platform_object_id' => $object_id,
                            'platform_id' => $this->my_platform_id,
                            'status' => 1,
                            'user_integration_id' => $user_integration_id,
                        ];
                        if (isset($Warehouses['Id'])) {
                            $Warehouses_Data['api_id'] = $Warehouses['Id'];
                        }
                        if (isset($Warehouses['Code'])) {
                            $Warehouses_Data['api_code'] = $Warehouses['Code'];
                            $WarehouseCode = $Warehouses['Code'];
                            $Warehouses_Data['name'] = $Warehouses['Code'];
                            $WarehouseName = $Warehouses['Code'];
                        }

                        $count_warehouses = $this->mobj->getFirstResultByConditions('platform_object_data', ['api_code' => $WarehouseCode, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id, 'platform_id' => $this->my_platform_id], ['id']);

                        if ($count_warehouses) {
                            $this->mobj->makeUpdate('platform_object_data', $Warehouses_Data, ['id' => $count_warehouses->id]);
                        } else {
                            $this->mobj->makeInsert('platform_object_data', $Warehouses_Data);
                        }
                    }
                } else {
                    $PageNumber = 0;
                }
                if ($limit) {
                    $this->mobj->makeUpdate('platform_urls', ['url' => $PageNumber], ['id' => $limit->id]);
                } else {
                    $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url' => $PageNumber, 'url_name' => 'getwarehouse_limit']);
                }
                // } while ($allow_next_cal);

            }
        } catch (\Exception $e) {
            $return_data = $e->getMessage();
        }
        return $return_data;
    }


    public function SkuvaultGetProductInventory($user_id = '', $user_integration_id = '', $is_initial_sync = 0)
    {
        $PageNumber = 0;
        $url = '';
        $PageSize = 500;
        $return_data = true;
        $product_ids_arr = array();
        $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'platform_id', 'env_type']);
        if ($ufound) {
            if ($ufound->env_type == 'production') { // checke account type .
                $url =  \Config::get('apiconfig.SkuvaultUrl');
            } else {
                $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
            }
            if (!$is_initial_sync) { // if $is_initial_sync is 1 that mean don't run this function.
                $this->SkuvaultGetProductInventoryBytime($ufound, $user_id, $user_integration_id);
            }
            // do {
            if ($is_initial_sync) {
                $limit = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url_name' => 'productInventory_limit'], ['url', 'id']);
                if ($limit) {
                    $PageNumber = $limit->url;
                }
            }
            $allow_next_cal = false;
            $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
            $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
            $curl_post_data["PageNumber"] = $PageNumber;
            $curl_post_data["PageSize"] = $PageSize;
            $product_arr = $this->mobj->getResultByConditions('platform_product', ['user_integration_id' => $user_integration_id, 'inventory_sync_status' => 'Pending', 'platform_id' => $this->my_platform_id], ['id', 'api_product_id', 'sku'], $orderby = [], $PageSize);
            if ($product_arr) {
                $product_sku_arr = [];
                foreach ($product_arr as $product) {
                    $product_sku_arr[] = $product->sku;
                    $product_ids_arr[$product->sku] = $product->id; //insert sku with id
                }
                $curl_post_data["ProductSKUs"] = $product_sku_arr;
            }

            $request_data_json = json_encode($curl_post_data);
            $response = $this->SkuvaultApi->GetProductInventory($request_data_json, $url);
            $ProductInventory_data = json_decode($response, true);
            if (isset($ProductInventory_data['Items'])) {
                if (count($ProductInventory_data['Items'])) {
                    $allow_next_cal = true;
                    foreach ($ProductInventory_data['Items'] as $item_sku => $inventory_arr) {
                        $api_product_id = '';
                        foreach ($inventory_arr as $inventory) {
                            $inventory_Data = [
                                'user_id' => $user_id,
                                'api_product_id' => $api_product_id,
                                'platform_id' => $ufound->platform_id,
                                'user_integration_id' => $user_integration_id,
                                'sync_status' => 'Ready',
                                'sku' => $item_sku,
                                'platform_product_id' => $product_ids_arr[$item_sku],
                            ];
                            $find_arr = ['api_product_id' => $api_product_id, 'sku' => $item_sku, 'user_id' => $user_id, 'platform_id' => $this->my_platform_id];
                            if (isset($inventory['WarehouseCode'])) {
                                $inventory_Data['api_warehouse_id'] = $inventory['WarehouseCode'];
                                $find_arr['api_warehouse_id'] = $inventory['WarehouseCode'];
                            }
                            if (isset($inventory['Quantity'])) {
                                $inventory_Data['quantity'] = $inventory['Quantity'];
                            }
                            if (isset($inventory['LocationCode'])) {
                                $inventory_Data['location_code'] = $inventory['LocationCode'];
                                $find_arr['location_code'] = $inventory['LocationCode'];
                            }
                            $count_ProductInventory = $this->mobj->getFirstResultByConditions('platform_product_inventory', $find_arr, ['id']);
                            if ($count_ProductInventory) {
                                $this->mobj->makeUpdate('platform_product_inventory', $inventory_Data, ['id' => $count_ProductInventory->id]);
                            } else {
                                $this->mobj->makeInsert('platform_product_inventory', $inventory_Data);
                            }
                        }
                    }
                    if ($is_initial_sync) {
                        $return_data = 'data Remaining';
                        $PageNumber = $PageNumber + 1;
                    }
                }
            } else if (isset($ProductInventory_data['Errors'])) {
                $return_data = $ProductInventory_data['Errors'];
            }
            if ($is_initial_sync) {
                if ($limit) {
                    $this->mobj->makeUpdate('platform_urls', ['url' => $PageNumber], ['id' => $limit->id]);
                } else {
                    $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url' => $PageNumber, 'url_name' => 'productInventory_limit']);
                }
            }
            // } while ($allow_next_cal);
        }
        return $return_data;
    }

    public function SkuvaultCreateOnlineSales($user_id, $source_platform_name, $user_workflow_rule_id, $user_integration_id, $sync_status, $platform_workflow_rule_id, $record_id = '')
    {
        try {
            $return_data = true;
            $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
            $object_id = $this->ConnectionHelper->getObjectId('sales_order');

            $skToken = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'env_type']);

            $source_platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $source_platform_id, ['account_name']);

            if ($skToken) {

                if ($skToken->env_type == 'production') { // checke account type .
                    $url =  \Config::get('apiconfig.SkuvaultUrl');
                } else {
                    $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
                }

                if ($record_id) {
                    $result_order = $this->mobj->getResultByConditions('platform_order', ['id' => $record_id]);
                } else {
                    $result_order = $this->mobj->getResultByConditions('platform_order', ['order_type' => 'PO', 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'sync_status' => $sync_status], [], ['id' => 'asc'], 10);
                }
                $successs_orders = $failed_orders = array();
                $k = 0;
                foreach ($result_order as $row) {

                    //order data
                    $order_array['OrderId'] = $row->order_number;
                    $order_array['OrderDateUtc'] = date(DATE_ISO8601, strtotime($row->order_date));
                    $order_array['OrderTotal'] = $row->total_amount;
                    $order_array['Notes'] = '';

                    //get order line items
                    $order_lines = $this->mobj->getResultByConditions('platform_order_line', ['platform_order_id' => $row->id], ['sku', 'api_product_id', 'qty', 'price'], []);

                    $product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');

                    $maping_data = $this->mapping->getMappedField($user_integration_id, $platform_workflow_rule_id, $product_identity_obj_id);

                    $makelines = [];
                    if (count($order_lines)) {
                        foreach ($order_lines as $o => $line) {
                            $lines['Quantity'] = $line->qty;
                            if ($maping_data && $maping_data['source_row_data'] == 'api_product_id') {
                                $lines['Sku'] = $line->api_product_id;
                            } else {
                                $lines['Sku'] = $line->sku;
                            }
                            $lines['UnitPrice'] =  number_format($line->price, 2);

                            $makelines[] = $lines;
                        }
                    }
                    $order_array['ItemSkus'] = $makelines;
                    if ($source_platform_account && $source_platform_account->account_name) {
                        $order_array['MarketplaceId'] = ucfirst($source_platform_name).' -' . $source_platform_account->account_name;
                    } else {
                        $order_array['MarketplaceId'] = ucfirst($source_platform_name);
                    }
                    //hardcoded for now, will be updated when warehosue mapping is done

                    //get order address
                    $order_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $row->id, 'address_type' => 'shipping'], []);

                    $order_array['ShippingInfo']['City'] = $order_address->city;
                    $order_array['ShippingInfo']['Country'] = $order_address->country;
                    $order_array['ShippingInfo']['FirstName'] = $order_address->address_name;
                    if ($order_address->address1) {
                        $order_array['ShippingInfo']['Line1'] = $order_address->address1;
                    }
                    if ($order_address->address2) {
                        $order_array['ShippingInfo']['Line2'] = $order_address->address2;
                    }

                    $order_array['ShippingInfo']['PhoneNumber'] = $order_address->phone_number;
                    $order_array['ShippingInfo']['Postal'] = $order_address->postal_code;
                    if ($order_address->state) {
                        $order_array['ShippingInfo']['Region'] = $order_address->state;
                    }
                    $order_array['ShippingInfo']['ShippingCarrier'] = $order_address->carrier_code;
                    $order_array['ShippingInfo']['ShippingClass'] = $order_address->ship_speed;

                    //tokens
                    $order_array['TenantToken'] = $this->mobj->encrypt_decrypt($skToken->refresh_token, $action = 'decrypt');
                    $order_array['Usertoken'] = $this->mobj->encrypt_decrypt($skToken->access_token, $action = 'decrypt');
                    //create online sales api
                    $response = $this->SkuvaultApi->createOnlineSale($order_array, $url);

                    $result = json_decode($response, true);
                    if (isset($result['Status'])) {
                        if ($result['Status'] == 'OK') {
                            $successs_orders[] = $result['OrderId'];

                            //only sync status handle here, linked id will handle in below fetch success order flow
                            $update_arr = ['sync_status' => 'Synced'];
                        } else {

                            // failed order update
                            $update_arr = ['sync_status' => 'Failed', 'order_updated_at' => date('Y-m-d H:i:s')];
                            $this->mobj->makeUpdate('platform_order', $update_arr, ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'id' => $row->id]);

                            $return_data = json_encode($result['Errors'], true);
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $return_data);
                        }
                    }
                }


                //acknowledge order table and logging
                if (!empty($successs_orders)) {

                    $orderGetPayload['OrderIds'] = $successs_orders;
                    $orderGetPayload['TenantToken'] = $this->mobj->encrypt_decrypt($skToken->refresh_token, $action = 'decrypt');
                    $orderGetPayload['Usertoken'] = $this->mobj->encrypt_decrypt($skToken->access_token, $action = 'decrypt');

                    //order get api with multiple ids
                    $response = $this->SkuvaultApi->getOnlineSaleByMultipleIds($orderGetPayload, $url);
                    $success_result = json_decode($response, true);
                    //dd($success_result, $orderGetPayload);
                    if (isset($success_result["Sales"])) {
                        foreach ($success_result["Sales"] as $r => $succ) {
                            $ex = explode('-', $succ['Id']);  //1-1471-7-1-111
                            $ref_order_id = end($ex);   //parent order number

                            $parent_order = $this->mobj->getFirstResultByConditions('platform_order', ['order_type' => 'PO', 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'order_number' => $ref_order_id], ['id']);

                            //store skuvault order details into db and link with parent order
                            $arr_so_order = array();
                            $arr_so_order['user_id'] = $user_id;
                            $arr_so_order['platform_id'] = $this->my_platform_id;
                            $arr_so_order['order_date'] = $succ['SaleDate'];
                            $arr_so_order['user_integration_id'] = $user_integration_id;
                            $arr_so_order['order_type'] = 'SO';
                            $arr_so_order['api_order_id'] = $succ['Id'];
                            $arr_so_order['order_number'] = $ref_order_id;
                            $arr_so_order['sync_status'] = 'Ready';
                            $arr_so_order['linked_id'] = $parent_order->id; //parent platform order row id
                            $arr_so_order['order_updated_at'] = date('Y-m-d H:i:s');
                            //insert skuvault order record
                            $linked_platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_so_order);
                            //update acknowledge
                            $update_arr = ['sync_status' => 'Synced', 'linked_id' => $linked_platform_order_id];
                            //update destination order record
                            $this->mobj->makeUpdate('platform_order', $update_arr, ['order_type' => 'PO', 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'id' => $parent_order->id]);
                            //sync logger
                            $sync_error = null;
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'success', $parent_order->id, $sync_error);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $return_data = $e->getMessage();
        }
        return $return_data;
    }

    public function  SkuvaultGetProductInventoryBytime($ufound, $user_id = '', $user_integration_id = '')
    {
        $PageNumber = 0;
        $url = '';
        $PageSize = 500;
        $return_data = true;
        if ($ufound) {
            if ($ufound->env_type == 'production') { // checke account type .
                $url =  \Config::get('apiconfig.SkuvaultUrl');
            } else {
                $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
            }
            //do {
            $allow_next_cal = false;
            $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
            $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
            $curl_post_data["PageNumber"] = $PageNumber;
            $curl_post_data["PageSize"] = $PageSize;
            $product_arr = DB::table('platform_product')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id])->orderByRaw("DATE_FORMAT(api_inventory_lastmodified_time, '%Y-%m-%d %H-%i-%s') DESC")->select('api_inventory_lastmodified_time')->first();
            if ($product_arr) {
                $curl_post_data["ModifiedAfterDateTimeUtc"] = $product_arr->api_inventory_lastmodified_time;
            } else {
                $pull_time = date('Y-m-d H:i:s', strtotime('-30 minutes', time()));
                $pull_time_sys = str_replace('+00:00', 'T', gmdate('c', strtotime($pull_time)));
                $curl_post_data["ModifiedAfterDateTimeUtc"] = $pull_time_sys;
            }
            $request_data_json = json_encode($curl_post_data);
            $response = $this->SkuvaultApi->GetProductInventoryBytime($request_data_json, $url);
            $ProductInventory_data = json_decode($response, true);
            if (isset($ProductInventory_data['Items'])) {
                if (count($ProductInventory_data['Items'])) {
                    $allow_next_cal = true;
                    foreach ($ProductInventory_data as $inventory) {
                        $item_sku = $api_product_id = $api_inventory_lastmodified_time = '';
                        if (isset($inventory['Code'])) {
                            $api_product_id = $inventory['Code'];
                        }
                        if (isset($inventory['LastModifiedDateTimeUtc'])) {
                            $api_inventory_lastmodified_time = $inventory['LastModifiedDateTimeUtc'];
                        }
                        if (isset($inventory['Sku'])) {
                            $item_sku = $inventory['Sku'];
                        }
                        $count_ProductInventory = $this->mobj->getFirstResultByConditions('platform_product', ['user_integration_id' => $user_integration_id, 'api_product_id' => $api_product_id, 'sku' => $item_sku, 'platform_id' => $this->my_platform_id], ['id', 'api_inventory_lastmodified_time']);
                        if ($count_ProductInventory && $count_ProductInventory->api_inventory_lastmodified_time != $api_inventory_lastmodified_time) {
                            $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Pending', 'api_inventory_lastmodified_time' => $api_inventory_lastmodified_time], ['id' => $count_ProductInventory->id]);
                        } else { }
                    }
                    $PageNumber = $PageNumber + 1;
                }
            } else if (isset($ProductInventory_data['Errors'])) {
                $return_data = $ProductInventory_data['Errors'];
            }
            // } while ($allow_next_cal);
        }
        return $return_data;
    }

    public function SkuvaultCreateProduct($user_id = '', $user_integration_id = '', $source_platform_name = '', $platform_workflow_rule_id = '', $user_workflow_rule_id = '', $record_id = '')
    {
        try {
            $return_data = true;
            $process_limit = 10;
            $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'platform_id', 'env_type']);
            if ($ufound) {
                $object_id = $this->ConnectionHelper->getObjectId('product');

                if ($ufound->env_type == 'production') { // checke account type .
                    $url =  \Config::get('apiconfig.SkuvaultUrl');
                } else {
                    $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
                }
                if ($record_id) {
                    $product_arr = DB::table('platform_product')->where(['id' => $record_id])->select('id', 'sku', 'api_product_id', 'product_name', 'upc', 'api_warehouse_id', 'brand_id', 'category_id', 'weight', 'weight_unit', 'price')->get();
                } else {
                    $product_arr = DB::table('platform_product')->where(['user_integration_id' => $user_integration_id, 'product_sync_status' => 'Ready', 'platform_id' => $source_platform_id])->select('id', 'sku', 'api_product_id', 'product_name', 'upc', 'api_warehouse_id', 'brand_id', 'category_id', 'weight', 'weight_unit', 'price')->limit($process_limit)->get();
                }
                //dd($product_arr);
                foreach ($product_arr as $product) {

                    $endpoint = $parentsku = $product_name = '';

                    // $destination_platform_product = DB::table('platform_product')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'sku' => $product->sku])->select('id', 'sku', 'api_product_id')->first();

                    //check skuvault product by destination platform mapped identity map column
                    $destination_platform_product = DB::table('platform_product')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'sku' => $product->api_product_id])->select('id', 'sku', 'api_product_id')->first();

                    if ($destination_platform_product) {
                        $endpoint = '/products/updateProducts';
                    } else {
                        $endpoint = '/products/createProducts';
                    }
                    $platform_product_options = $this->mobj->getResultByConditions('platform_product_options', ['platform_product_id' => $product->id], ['id', 'option_name', 'option_value']);
                    if ($platform_product_options) {
                        foreach ($platform_product_options as $options) {
                            //dd($options);
                            $product_name = $product_name . '|' . $options->option_value;
                        }
                        $Parent_product = DB::table('platform_product')->where('product_name', 'like', '%' . $product->product_name . '%')->where('platform_id', $this->my_platform_id)->select('sku')->first();
                        if ($Parent_product) {
                            $parentsku = $Parent_product->sku;
                        }
                    }
                    $product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');
                    $maping_data = $this->mapping->getMappedField($user_integration_id, $platform_workflow_rule_id, $product_identity_obj_id);
                    $destination_row_data = '';
                    if ($maping_data['source_platform_id'] == 'wayfair') {
                        $destination_row_data = $maping_data['source_row_data'];
                    } elseif ($maping_data['destination_platform_id'] == 'wayfair') {
                        $destination_row_data = $maping_data['destination_row_data'];
                    }
                    if ($destination_row_data) {
                        $items['Sku'] = $product->$destination_row_data;
                    } else {
                        $items['Sku'] = $product->sku;
                    }
                    $items['Code'] = $product->upc;
                    if ($product_name) {
                        $items['Description'] = $product->product_name . ' - ' . substr($product_name, 1);
                    } else {
                        $items['Description'] = $product->product_name;
                    }
                    if ($parentsku) {
                        $items['VariationParentSku'] = $parentsku; //variation data .
                    }
                    if ($product->weight) {
                        $items['Weight'] = $product->weight;
                    }
                    if ($product->weight_unit) {
                        $items['WeightUnit'] = $product->weight_unit;
                    }
                    if ($product->price) {
                        $items['SalePrice'] = $product->price;
                    }
                    //Classification ...
                    if ($product->category_id) {
                        $category_object_id = $this->ConnectionHelper->getObjectId('category');
                        $category = DB::table('platform_object_data')->where([
                            'platform_object_id' => $category_object_id,
                            'platform_id' => $this->my_platform_id,
                            'name' => $product->category_id,
                            'user_integration_id' => $user_integration_id,
                        ])->select('id',)->first();
                        if ($category) {
                            $items['Classification'] = $product->category_id;
                        } else {
                            $return_data = 'Please Add valid Class';
                            $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed',], ['id' => $product->id]);
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $product->id, $return_data);
                            continue;
                        }
                    } else {
                        $return_data = 'Please Add Class';
                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed',], ['id' => $product->id]);
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $product->id, $return_data);
                        continue;
                    }
                    if (isset($product->api_warehouse_id)) {
                        $warehouse_object_id = $this->ConnectionHelper->getObjectId('warehouse');
                        $warehouse_data = DB::table('platform_object_data')->where(['user_integration_id' => $user_integration_id, 'api_id' => $product->api_warehouse_id, 'platform_id' => $source_platform_id, 'platform_object_id' => $warehouse_object_id])->select('id', 'name')->first();
                        if ($warehouse_data) {
                            $supplier_object_id = $this->ConnectionHelper->getObjectId('supplier');
                            $mapping_data = DB::table('platform_object_data')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'platform_object_id' => $supplier_object_id])->where('name', preg_replace('/^\p{Z}+|\p{Z}+$/u', '', $warehouse_data->name))->select('id', 'name')->first();
                            if ($mapping_data) {
                                $items['Supplier'] = $mapping_data->name;
                            } else { // create supplier ..
                                $result = $this->CreateSupplier(
                                    $user_id,
                                    $user_integration_id,
                                    $warehouse_data->name,
                                    $supplier_object_id,
                                    $ufound
                                );
                                if (is_bool($result)) {
                                    $items['Supplier'] = $warehouse_data->name;
                                } else {
                                    //Create Supplier error .
                                    $return_data = implode(',', $result[0]['ErrorMessages']);
                                    $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed',], ['id' => $product->id]);
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $product->id, $return_data);
                                }
                            }
                        }
                    }
                    if (isset($product->brand_id)) { // brand
                        $brand_object_id = $this->ConnectionHelper->getObjectId('brand');
                        $source_brand_data = DB::table('platform_object_data')->where(['user_integration_id' => $user_integration_id,  'name' => preg_replace('/^\p{Z}+|\p{Z}+$/u', '', $product->brand_id), 'platform_id' => $this->my_platform_id, 'platform_object_id' => $brand_object_id])->select('id', 'name')->first();
                        if ($source_brand_data) {
                            $items['Brand'] = $source_brand_data->name;
                        } else { // create supplier ..
                            $result = $this->CreateBrand($user_id, $user_integration_id, $product->brand_id, $brand_object_id, $ufound);
                            if (is_bool($result)) {
                                $items['Brand'] = $product->brand_id;
                            } else {
                                //Create Supplier error .
                                $return_data = implode(',', $result[0]['ErrorMessages']);
                                //dd($result[0]['ErrorMessages']);
                                $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed',], ['id' => $product->id]);
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $product->id, $return_data);
                            }
                        }
                    }
                    $opst_data['Items'] = array($items);
                    $opst_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
                    $opst_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
                    $request_data_json = json_encode($opst_data);
                    $response = $this->SkuvaultApi->UpdateProduct($request_data_json, $url, $endpoint);
                    $ProductInventory_data = json_decode($response, true);
                    if ($ProductInventory_data['Status'] == 'OK') {
                        if ($destination_platform_product) {
                            $this->mobj->makeUpdate('platform_product', ['linked_id' => $destination_platform_product->id, 'product_sync_status' => 'Synced'], ['id' => $product->id]);
                            $this->mobj->makeUpdate('platform_product', ['linked_id' => $product->id, 'product_sync_status' => 'Synced'], ['id' => $destination_platform_product->id]);
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'success', $product->id, '');
                        } else {
                            $prodect_id = $this->mobj->makeInsertGetId('platform_product', ['user_id' => $user_id, 'sku' => $product->sku, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'product_name' => $items['Description'], 'brand_id' => $product->brand_id, 'api_warehouse_id' => $product->api_warehouse_id, 'category_id' => $product->category_id, 'linked_id' => $product->id, 'weight' => $product->weight, 'weight_unit' => $product->weight_unit]);
                            $this->mobj->makeUpdate('platform_product', ['linked_id' => $prodect_id, 'product_sync_status' => 'Synced'], ['id' => $product->id]);
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'success', $product->id, '');
                        }
                    } else {
                        if (isset($ProductInventory_data['Errors']) && count($ProductInventory_data['Errors'])) {
                            $return_data = implode(',', $ProductInventory_data['Errors'][0]['ErrorMessages']);
                            $this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed',], ['id' => $product->id]);
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $product->id, $return_data);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $return_data = $e->getMessage();
        }
        return $return_data;
    }

    public function SkuvaultGetBrands($user_id = '', $user_integration_id = '')
    {
        try {
            $PageNumber = 0;
            $url = '';
            $limit = '';
            $object_id = '';
            $return_data = true;
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'platform_id', 'env_type']);
            //dd($ufound);
            if ($ufound) {
                if ($ufound->env_type == 'production') { // checke account type .
                    $url =  \Config::get('apiconfig.SkuvaultUrl');
                } else {
                    $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
                }
                $object_id = $this->ConnectionHelper->getObjectId('brand');
                //do {
                $limit = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url_name' => 'getbrands_limit'], ['url','id']);
                if ($limit) {
                    $PageNumber = $limit->url;
                }
                $allow_next_cal = false;
                $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
                $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
                $curl_post_data["PageNumber"] = $PageNumber;

                $request_data_json = json_encode($curl_post_data);
                $response = $this->SkuvaultApi->GetBrands($request_data_json, $url);
                $brands_data = json_decode($response, true);
                //dd($brands_data);
                if (count($brands_data['Brands'])) {
                    $allow_next_cal = true;
                    //update users integration warehouse status to 0.
                    $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id, 'platform_id' => $this->my_platform_id]);
                    foreach ($brands_data['Brands'] as $brands) {
                        $brandsName = '';
                        $brands_Data = [
                            'user_id' => $user_id,
                            'platform_object_id' => $object_id,
                            'platform_id' => $this->my_platform_id,
                            'status' => 1,
                            'user_integration_id' => $user_integration_id,
                        ];
                        if (isset($brands['Name'])) {
                            $brands_Data['name'] = $brands['Name'];
                            $brandsName = $brands['Name'];
                        }
                        $count_brands = $this->mobj->getFirstResultByConditions('platform_object_data', ['name' => $brandsName, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id,'platform_id' => $this->my_platform_id], ['id']);
                        if ($count_brands) {
                            $this->mobj->makeUpdate('platform_object_data', $brands_Data, ['id' => $count_brands->id]);
                        } else {
                            $this->mobj->makeInsert('platform_object_data', $brands_Data);
                        }
                    }
                    $return_data = 'data Remaining';
                    $PageNumber = $PageNumber + 1;
                }
                if ($limit) {
                    $this->mobj->makeUpdate('platform_urls', ['url' => $PageNumber], ['id' => $limit->id]);
                } else {
                    $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url' => $PageNumber, 'url_name' => 'getbrands_limit']);
                }
                //} while ($allow_next_cal);
            }
        } catch (\Exception $e) {
            $return_data = $e->getMessage();
        }
        return $return_data;
    }

    public function SkuvaultGetCategory($user_id = '', $user_integration_id = '')
    {
        try {
            $PageNumber = 0;
            $url = '';
            $limit = '';
            $object_id = '';
            $return_data = true;
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'platform_id', 'env_type']);
            //dd($ufound);
            if ($ufound) {
                if ($ufound->env_type == 'production') { // checke account type .
                    $url =  \Config::get('apiconfig.SkuvaultUrl');
                } else {
                    $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
                }
                $object_id = $this->ConnectionHelper->getObjectId('category');
                //do {
                $limit = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url_name' => 'getcategory_limit'], ['url','id']);
                if ($limit) {
                    $PageNumber = $limit->url;
                }
                $allow_next_cal = false;
                $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
                $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
                $curl_post_data["PageNumber"] = $PageNumber;

                $request_data_json = json_encode($curl_post_data);
                $response = $this->SkuvaultApi->GetCategory($request_data_json, $url);
                $Classifications_data = json_decode($response, true);
                //print_r($Classifications_data);
                if (count($Classifications_data['Classifications'])) {
                    $allow_next_cal = true;
                    //update users integration warehouse status to 0.
                    $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id, 'platform_id' => $this->my_platform_id]);
                    foreach ($Classifications_data['Classifications'] as $Classifications) {
                        $ClassificationsName = '';
                        $Classifications_Data = [
                            'user_id' => $user_id,
                            'platform_object_id' => $object_id,
                            'platform_id' => $this->my_platform_id,
                            'status' => 1,
                            'user_integration_id' => $user_integration_id,
                        ];
                        if (isset($Classifications['Name'])) {
                            $Classifications_Data['name'] = $Classifications['Name'];
                            $ClassificationsName = $Classifications['Name'];
                        }
                        $count_Classifications = $this->mobj->getFirstResultByConditions('platform_object_data', ['name' => $ClassificationsName, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id, 'platform_id' => $this->my_platform_id], ['id']);
                        if ($count_Classifications) {
                            $this->mobj->makeUpdate('platform_object_data', $Classifications_Data, ['id' => $count_Classifications->id]);
                        } else {
                            $this->mobj->makeInsert('platform_object_data', $Classifications_Data);
                        }
                    }
                    $return_data = 'data Remaining';
                    $PageNumber = $PageNumber + 1;
                }
                if ($limit) {
                    $this->mobj->makeUpdate('platform_urls', ['url' => $PageNumber], ['id' => $limit->id]);
                } else {
                    $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url' => $PageNumber, 'url_name' => 'getcategory_limit']);
                }
            }
        } catch (\Exception $e) {
            $return_data = $e->getMessage();
        }
        return $return_data;
    }

    public function SkuvaultGetSuppliers($user_id = '', $user_integration_id = '')
    {
        try {
            $PageNumber = 0;
            $url = '';
            $limit = '';
            $object_id = '';
            $return_data = true;
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'platform_id', 'env_type']);

            //dd($ufound);
            if ($ufound) {
                if ($ufound->env_type == 'production') { // checke account type .
                    $url =  \Config::get('apiconfig.SkuvaultUrl');
                } else {
                    $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
                }
                $supplier_object_id = $this->ConnectionHelper->getObjectId('supplier');
                //do {
                $limit = $this->mobj->getFirstResultByConditions('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url_name' => 'suppliers_limit'], ['url','id']);
                if ($limit) {
                    $PageNumber = $limit->url;
                }
                $allow_next_cal = false;
                $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
                $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
                $curl_post_data["PageNumber"] = $PageNumber;

                $request_data_json = json_encode($curl_post_data);
                $response = $this->SkuvaultApi->GetSuppliers($request_data_json, $url);
                $Supplier_data = json_decode($response, true);
                if (count($Supplier_data['Suppliers'])) {
                    $allow_next_cal = true;
                    //update users integration warehouse status to 0.
                    $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id, 'platform_object_id' => $supplier_object_id, 'platform_id' => $this->my_platform_id]);
                    foreach ($Supplier_data['Suppliers'] as $Supplier) {

                        $SupplierName = '';
                        $Supplier_Data = [
                            'user_id' => $user_id,
                            'platform_object_id' => $supplier_object_id,
                            'platform_id' => $this->my_platform_id,
                            'status' => 1,
                            'user_integration_id' => $user_integration_id,
                        ];
                        if (isset($Supplier['Name'])) {
                            $Supplier_Data['name'] = $Supplier['Name'];
                            $SupplierName = $Supplier['Name'];
                        }

                        $count_Supplier = $this->mobj->getFirstResultByConditions('platform_object_data', ['name' => $SupplierName, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $supplier_object_id, 'platform_id' => $this->my_platform_id], ['id']);
                        //dd()
                        if ($count_Supplier) {
                            $this->mobj->makeUpdate('platform_object_data', $Supplier_Data, ['id' => $count_Supplier->id]);
                        } else {
                            $this->mobj->makeInsert('platform_object_data', $Supplier_Data);
                        }
                    }
                    $return_data = 'data Remaining';
                    $PageNumber = $PageNumber + 1;
                }
                //} while ($allow_next_cal);
                if ($limit) {
                    $this->mobj->makeUpdate('platform_urls', ['url' => $PageNumber], ['id' => $limit->id]);
                } else {
                    $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url' => $PageNumber, 'url_name' => 'suppliers_limit']);
                }
            }
        } catch (\Exception $e) {
            $return_data = $e->getMessage();
        }
        return $return_data;
    }

    public function CreateSupplier($user_id, $user_integration_id, $name, $object_id, $ufound)
    {
        if ($ufound->env_type == 'production') { // checke account type .
            $url =  \Config::get('apiconfig.SkuvaultUrl');
        } else {
            $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
        }
        //$allow_next_cal = false;
        $post_data["Name"] = $name;
        $curl_post_data["Suppliers"] = array($post_data);
        $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
        $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');

        $request_data_json = json_encode($curl_post_data);
        $response = $this->SkuvaultApi->CreateSupplier($request_data_json, $url);
        $Warehouses_data = json_decode($response, true);

        if ($Warehouses_data['Status'] == 'OK') {

            $Warehouses_Data = [
                'user_id' => $user_id,
                'platform_object_id' => $object_id,
                'platform_id' => $this->my_platform_id,
                'status' => 1,
                'user_integration_id' => $user_integration_id,
                'name' => $name
            ];
            $count_warehouses = $this->mobj->getFirstResultByConditions('platform_object_data', ['name' => $name, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id, 'platform_id' => $this->my_platform_id], ['id']);
            if ($count_warehouses) {
                $this->mobj->makeUpdate('platform_object_data', $Warehouses_Data, ['id' => $count_warehouses->id]);
            } else {
                $this->mobj->makeInsert('platform_object_data', $Warehouses_Data);
            }
            return true;
        } else {
            if (isset($Warehouses_data['Errors']) && count($Warehouses_data['Errors'])) {
                return $Warehouses_data['Errors'];
            }
        }
    }


    public function CreateBrand($user_id, $user_integration_id, $name, $object_id, $ufound)
    {
        if ($ufound->env_type == 'production') { // checke account type .
            $url =  \Config::get('apiconfig.SkuvaultUrl');
        } else {
            $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
        }
        //$allow_next_cal = false;
        $post_data["Name"] = $name;
        $curl_post_data["Brands"] = array($post_data);
        $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
        $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');

        $request_data_json = json_encode($curl_post_data);
        $response = $this->SkuvaultApi->CreateBrand($request_data_json, $url);
        $brands_data = json_decode($response, true);
        //dd($brands_data);
        if ($brands_data['Status'] == 'OK') {

            $brands_Data = [
                'user_id' => $user_id,
                'platform_object_id' => $object_id,
                'platform_id' => $this->my_platform_id,
                'status' => 1,
                'user_integration_id' => $user_integration_id,
                'name' => $name
            ];
            $count_brands = $this->mobj->getFirstResultByConditions('platform_object_data', ['name' => $name, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id, 'platform_id' => $this->my_platform_id], ['id']);
            if ($count_brands) {
                $this->mobj->makeUpdate('platform_object_data', $brands_Data, ['id' => $count_brands->id]);
            } else {
                $this->mobj->makeInsert('platform_object_data', $brands_Data);
            }
            return true;
        } else {
            if (isset($brands_data['Errors']) && count($brands_data['Errors'])) {
                return $brands_data['Errors'];
            }
        }
    }


    public function GetShipments($user_id, $user_integration_id, $source_platform_name, $destination_platform_name, $record_id, $is_initial_sync)
    {
        $url = '';
        if ($is_initial_sync) {
            return true;
        }
        $limit = 100;
        $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
        $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'platform_id', 'env_type']);
        if ($ufound) {
            if ($ufound->env_type == 'production') { // checke account type .
                $url =  \Config::get('apiconfig.SkuvaultUrl');
            } else {
                $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
            }
            if ($record_id) {
                $result_order = $this->mobj->getResultByConditions('platform_order', ['id' => $record_id], ['id', 'api_order_id', 'order_number']);
            } else {

               $wheredata = ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'shipment_status' => 'Pending', 'order_status' => 'Completed'];
                if (isset(\Config::get('apisettings.AllowShipmentCheckForNonSyncedStatusOrderInSkuvault')[$destination_platform_name])) {
                    $wheredata['sync_status'] = 'Ready';
                }else{
                    $wheredata['sync_status'] = 'Synced';
                }

                $result_order = $this->mobj->getResultByConditions('platform_order', $wheredata, ['id', 'api_order_id', 'order_number', 'updated_at'], ['updated_at' => 'asc'], $limit);
            }
            $SaleIds = [];
            $shiparr = [];
            foreach ($result_order as $order) {
                $created_at = strtotime($order->updated_at . " +48 hours");
                $current_time = time();
                if ($current_time <= $created_at) {
                    $SaleIds[] = $order->api_order_id;
                    $shiparr[$order->api_order_id] = ['id' => $order->id, 'order_number' => $order->order_number];
                } else {
                    $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $order->id]);
                }
            }
            $curl_post_data["SaleIds"] = $SaleIds;
            $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
            $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
            $request_data_json = json_encode($curl_post_data);
            $response = $this->SkuvaultApi->GetShipments($request_data_json, $url);
            $Shipments_data = json_decode($response, true);
            $SaleIds_recv_from_api = [];
            if (isset($Shipments_data['Status'])) {
                if ($Shipments_data['Status'] == 'OK') {
                    if (isset($Shipments_data['Shipments']) && count($Shipments_data['Shipments'])) {

                        foreach ($Shipments_data['Shipments'] as $Shipments) {
                            $platform_order_shipment_id = '';
                            $data = [];
                            $SaleIds_recv_from_api[] = $Shipments['SaleId'];
                            if (isset($Shipments['SaleId']) && !isset($shiparr[$Shipments['SaleId']])) {
                                continue;
                            }
                            $order_number = $order_id = '';
                            if (isset($Shipments['SaleId']) && isset($shiparr[$Shipments['SaleId']])) {
                                $order_number = $shiparr[$Shipments['SaleId']]['order_number'];
                                $order_id = $shiparr[$Shipments['SaleId']]['id'];
                            }
                            $data = [
                                'user_id' => $user_id,
                                'platform_id' => $this->my_platform_id,
                                'user_integration_id' => $user_integration_id,
                                'sync_status' => 'Ready',
                                'order_id' => $order_number,
                                'platform_order_id' => $order_id,
                                'shipment_id' => $Shipments['SaleId']
                            ];
                            if (isset($Shipments['Source'])) { }
                            if (isset($Shipments['TrackingNumber'])) {
                                $data['tracking_info'] = $Shipments['TrackingNumber'];
                            }
                            if (isset($Shipments['Carrier'])) {
                                $data['carrier_code'] = $Shipments['Carrier'];
                            }
                            if (isset($Shipments['Class'])) {
                                $data['shipping_method'] = $Shipments['Class'];
                            }
                            if (isset($Shipments['Type'])) {
                                $taype = $Shipments['Type'];
                            }
                            if (isset($Shipments['Status'])) {
                                $data['shipment_status'] = $Shipments['Status'];
                            }
                            if (isset($Shipments['AlternateId'])) { }
                            if (isset($Shipments['ManifestId'])) { }
                            if (isset($Shipments['TotalWeight'])) {
                                $data['weight'] = $Shipments['TotalWeight'];
                            }
                            if (isset($Shipments['WeightUnit'])) { }
                            if (isset($Shipments['TrackingUrl'])) {
                                $data['tracking_url'] = $Shipments['TrackingUrl'];
                            }
                            if (isset($Shipments['CreatedDate'])) {
                                $data['created_on'] = $Shipments['CreatedDate'];
                            }
                            if (isset($Shipments['EstimatedShipDate'])) { }
                            if (isset($Shipments['EstimatedDeliveryDate'])) { }
                            if (isset($Shipments['ShippedFrom'])) {
                                if (isset($Shipments['ShippedFrom']['WarehouseCode'])) {
                                    $data['warehouse_id'] = $Shipments['ShippedFrom']['WarehouseCode'];
                                }
                            }
                            if (isset($Shipments['Parcels'])) {
                                $data['boxes'] = count($Shipments['Parcels']);
                            }
                            $platform_order_shipment_data = DB::table('platform_order_shipments')->where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'shipment_id' => $Shipments['SaleId']])->select('id')->first();
                            if ($platform_order_shipment_data) {
                                $platform_order_shipment_id = $platform_order_shipment_data->id;
                                $this->mobj->makeUpdate('platform_order_shipments', $data, ['id' => $platform_order_shipment_data->id]);
                            } else {
                                $platform_order_shipment_id = $this->mobj->makeInsertGetId('platform_order_shipments', $data);
                            }
                            if ($platform_order_shipment_id) {
                                if (isset($Shipments['Parcels'])) {
                                    if (isset($Shipments['Parcels'][0]['Items'])) {
                                        foreach ($Shipments['Parcels'][0]['Items'] as $parcels_items) {
                                            $id = '';
                                            $parcels_items_data = [
                                                'platform_order_shipment_id' => $platform_order_shipment_id,
                                            ];
                                            if (isset($parcels_items['Id'])) {
                                                $parcels_items_data['row_id'] = $parcels_items['Id'];
                                                $id = $parcels_items['Id'];
                                            }
                                            if (isset($parcels_items['Sku'])) {
                                                $parcels_items_data['sku'] = $parcels_items['Sku'];
                                            }
                                            if (isset($parcels_items['Quantity'])) {
                                                $parcels_items_data['quantity'] = $parcels_items['Quantity'];
                                            }
                                            $platform_order_shipment_lines = $this->mobj->getFirstResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id' => $platform_order_shipment_id, 'row_id' => $id], ['id']);

                                            if ($platform_order_shipment_lines) {
                                                $this->mobj->makeUpdate('platform_order_shipment_lines', $parcels_items_data, ['id' => $platform_order_shipment_lines->id]);
                                            } else {
                                                $this->mobj->makeInsertGetId('platform_order_shipment_lines', $parcels_items_data);
                                            }
                                        }
                                    }
                                }

                                if (isset($Shipments['ShippedFrom'])) {
                                    if (isset($Shipments['ShippedFrom']['Address'])) {
                                        $ShippedFrom_data = [
                                            'address_type' => 'shippedfrom',
                                            'platform_order_id' => $order_id
                                        ];

                                        if (isset($Shipments['ShippedFrom']['Address']['FirstName'])) {
                                            $ShippedFrom_data['address_name'] = trim($Shipments['ShippedFrom']['Address']['FirstName'].' '.@$Shipments['ShippedFrom']['Address']['MiddleName'].' '.@$Shipments['ShippedFrom']['Address']['LastName']);

                                            $ShippedFrom_data['firstname'] = $Shipments['ShippedFrom']['Address']['FirstName'];
                                        }


                                        if (isset($Shipments['ShippedFrom']['Address']['LastName'])) {
                                            $ShippedFrom_data['lastname'] = $Shipments['ShippedFrom']['Address']['LastName'];
                                        }
                                        if (isset($Shipments['ShippedFrom']['Address']['MiddleName'])) {

                                        }
                                        if (isset($Shipments['ShippedFrom']['Address']['Company'])) {
                                            $ShippedFrom_data['company'] = $Shipments['ShippedFrom']['Address']['Company'];
                                        }
                                        if (isset($Shipments['ShippedFrom']['Address']['Email'])) {
                                            $ShippedFrom_data['email'] = $Shipments['ShippedFrom']['Address']['Email'];
                                        }

                                        if (isset($Shipments['ShippedFrom']['Address']['Address1'])) {
                                            $ShippedFrom_data['address1'] = $Shipments['ShippedFrom']['Address']['Address1'];
                                        }
                                        if (isset($Shipments['ShippedFrom']['Address']['Address2'])) {
                                            $ShippedFrom_data['address2'] = $Shipments['ShippedFrom']['Address']['Address2'];
                                        }
                                        if (isset($Shipments['ShippedFrom']['Address']['City'])) {
                                            $ShippedFrom_data['city'] = $Shipments['ShippedFrom']['Address']['City'];
                                        }
                                        if (isset($Shipments['ShippedFrom']['Address']['Region'])) {
                                            $ShippedFrom_data['state'] = $Shipments['ShippedFrom']['Address']['Region'];
                                        }
                                        if (isset($Shipments['ShippedFrom']['Address']['Country'])) {
                                            $ShippedFrom_data['country'] = $Shipments['ShippedFrom']['Address']['Country'];
                                        }
                                        if (isset($Shipments['ShippedFrom']['Address']['PostalCode'])) {
                                            $ShippedFrom_data['postal_code'] = $Shipments['ShippedFrom']['Address']['PostalCode'];
                                        }
                                        $platform_order_shipment_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $order_id, 'address_type' => 'shippedfrom'], ['id']);

                                        if ($platform_order_shipment_address) {
                                            $this->mobj->makeUpdate('platform_order_address', $ShippedFrom_data, ['id' => $platform_order_shipment_address->id]);
                                        } else {
                                            $this->mobj->makeInsertGetId('platform_order_address', $ShippedFrom_data);
                                        }
                                    }
                                }
                                $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Ready'], ['id' => $order_id]);
                            }
                        }
                        DB::table('platform_order')->whereIn('api_order_id', $SaleIds)->whereNotIn('api_order_id', $SaleIds_recv_from_api)->where(['platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id])->update(['updated_at' => date('Y-m-d H:i:s')]);
                    } else { // if API not return anything.
                        DB::table('platform_order')->whereIn('api_order_id', $SaleIds)->where(['platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id])->update(['updated_at' => date('Y-m-d H:i:s')]);
                    }
                } else { // if error comming ...

                }
            }
            //}
        }
    }


    public function SkuvaultGetAvailableQuantities($user_id = '', $user_integration_id = '', $is_initial_sync = 0, $is_backup_call = 0)
    {
        $PageNumber = 0;
        $url = '';
        $limit = '';
        $PageSize = 1000;
        $return_data = true;
        $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'platform_id', 'env_type']);
        if ($ufound) {
            if ($ufound->env_type == 'production') { // checke account type .
                $url =  \Config::get('apiconfig.SkuvaultUrl');
            } else {
                $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
            }
            // do {

            $allow_next_cal = false;
            $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
            $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
            $curl_post_data["PageSize"] = $PageSize;
            if ($is_initial_sync) {
                $limit = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url_name' => 'Quantities'], ['url', 'id']);
                if ($limit) {
                    $PageNumber = $limit->url;
                    $curl_post_data["PageNumber"] = $PageNumber;
                }
            }

            if (!$is_initial_sync) {

                $curl_post_data["PageNumber"] =  $PageNumber;
                
                if($is_backup_call){
                    $qty_backup_url = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url_name' => 'quantities_backup_time'], ['url', 'id']);
                    if(isset($qty_backup_url->url) && $qty_backup_url->url){
                        $qtysBackupRange = explode('|', $qty_backup_url->url);
                        $backupUrl = array_filter($qtysBackupRange);

                        if(count($backupUrl)>1){ //getting modified before &  modified after from db (backup url)
                            $ModifiedBeforeDateTimeUtc = Carbon::parse(trim($backupUrl[1]))->format('Y-m-d\TH:i:s.u\Z');
                            $ModifiedAfterDateTimeUtc = Carbon::parse(trim($backupUrl[0]))->sub(3, 'sec')->format('Y-m-d\TH:i:s.u\Z');
                            $PageNumber = isset($backupUrl[2]) ? trim($backupUrl[2]): $PageNumber;
                        }else{ // if quantity not found on last run getting end date (only) from backup
                            $ModifiedBeforeDateTimeUtc = Carbon::now()->format('Y-m-d\TH:i:s.u\Z');
                            $ModifiedAfterDateTimeUtc = Carbon::parse(trim($backupUrl[0]))->sub(3, 'sec')->format('Y-m-d\TH:i:s.u\Z');

                            $backupUrl = $ModifiedAfterDateTimeUtc.'|'.$ModifiedBeforeDateTimeUtc.'|'.$PageNumber;
                            $this->mobj->makeUpdate('platform_urls', ['url' => $backupUrl], ['id' => $qty_backup_url->id]);
                        }

                    }else{
                        $api_inv_last_mftime = DB::table('platform_product')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id])->orderByRaw("DATE_FORMAT(api_inventory_lastmodified_time, '%Y-%m-%d %H-%i-%s') ASC")->pluck('api_inventory_lastmodified_time')->first();
                        if($api_inv_last_mftime){ //getting last modified date & current date
                            $ModifiedBeforeDateTimeUtc = Carbon::now()->format('Y-m-d\TH:i:s.u\Z');
                            $ModifiedAfterDateTimeUtc = Carbon::parse($api_inv_last_mftime)->sub(3, 'sec')->format('Y-m-d\TH:i:s.u\Z');
                        }else{//set default last modified date & current date
                            $ModifiedBeforeDateTimeUtc = Carbon::now()->format('Y-m-d\TH:i:s.u\Z');
                            $ModifiedAfterDateTimeUtc = "2000-12-31T23:59:50.000000Z";
                        }

                        //insert quantities_backup_time url very first time
                        $backupUrl = $ModifiedAfterDateTimeUtc.'|'.$ModifiedBeforeDateTimeUtc.'|'.$PageNumber;
                        $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url' => $backupUrl, 'url_name' => 'quantities_backup_time']);
                    }
                    
                    $curl_post_data["ModifiedBeforeDateTimeUtc"] = $ModifiedBeforeDateTimeUtc;
                    $curl_post_data["ModifiedAfterDateTimeUtc"] = $ModifiedAfterDateTimeUtc;
                    $curl_post_data["PageNumber"] =  $PageNumber;
                }else{

                    $product_arr = DB::table('platform_product')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id])->orderByRaw("DATE_FORMAT(api_inventory_lastmodified_time, '%Y-%m-%d %H-%i-%s') DESC")->select('api_inventory_lastmodified_time')->first();
                    if ($product_arr &&  $product_arr->api_inventory_lastmodified_time) {

                        $curl_post_data["ModifiedAfterDateTimeUtc"] = Carbon::parse($product_arr->api_inventory_lastmodified_time)->sub(1, 'minutes')->format('Y-m-d\TH:i:s.u\Z');

                    } else {
                        $curl_post_data["ModifiedAfterDateTimeUtc"] = Carbon::now()->sub(30, 'minutes')->format('Y-m-d\TH:i:s.u\Z');
                    }

                }
            }
            $request_data_json = json_encode($curl_post_data);

            //temp log
            \Storage::disk('local')->append('skuvault_get_available_inventory.txt', 'Call time: ' . date('Y-m-d H:i:s'). ' user_integration_id : '.$user_integration_id .' Request data : '.$request_data_json .PHP_EOL.PHP_EOL.PHP_EOL);


            $response = $this->SkuvaultApi->GetAvailableQuantities($request_data_json, $url);
            $ProductInventory_data = json_decode($response, true);

            // dd($ProductInventory_data);


            if (isset($ProductInventory_data['Items'])) {

                \Storage::disk('local')->append('skuvault_get_available_inventory.txt', 'Call time: ' . date('Y-m-d H:i:s'). ' user_integration_id : '.$user_integration_id .' total invenotory fetch : '.count($ProductInventory_data['Items']) .PHP_EOL.PHP_EOL.PHP_EOL);
                
                if (count($ProductInventory_data['Items'])) {
                    $allow_next_cal = true;
                    foreach ($ProductInventory_data['Items'] as $inventory) {
                        $item_sku = $api_inventory_lastmodified_time = '';
                        $inventory_Data = [
                            'user_id' => $user_id,
                            'platform_id' => $ufound->platform_id,
                            'user_integration_id' => $user_integration_id,
                            'sync_status' => 'Ready',
                            //'sku' => $item_sku
                        ];

                        // $find_arr = [
                        //     'user_id' => $user_id,
                        //     'user_integration_id' => $user_integration_id,
                        // ];

                        if (isset($inventory['AvailableQuantity'])) {
                            $inventory_Data['quantity'] = $inventory['AvailableQuantity'];
                        }
                        if (isset($inventory['Sku'])) {
                            $inventory_Data['sku'] = $inventory['Sku'];
                            // $find_arr['sku'] = $inventory['Sku'];
                            $item_sku = $inventory['Sku'];
                        }
                        if (isset($inventory['LastModifiedDateTimeUtc'])) {
                            $api_inventory_lastmodified_time = $inventory['LastModifiedDateTimeUtc'];
                        }
                        
                        //changes made by gajendra on 08-11-2022
                        $count_Product = $this->mobj->getFirstResultByConditions('platform_product', ['user_integration_id' => $user_integration_id, 'sku' => $item_sku, 'platform_id' => $this->my_platform_id], ['id', 'api_inventory_lastmodified_time','inventory_sync_status']);
                        if($count_Product) {

                            // $find_arr['platform_product_id'] = $count_Product->id;

                                $platform_product_id = $count_Product->id;
                                $inventory_Data['platform_product_id'] = $platform_product_id;

                            //find platform_product_inventory by platform_product_id
                            // $count_ProductInventory = $this->mobj->getFirstResultByConditions('platform_product_inventory', $find_arr, ['id']);

                               $cp_query = DB::table('platform_product_inventory')->where(['user_integration_id' => $user_integration_id]);

                               $cp_query->where(function($query) use ($platform_product_id, $item_sku){
                                    $query->where('platform_product_id', $platform_product_id)
                                    ->orWhere('sku',$item_sku);
                               });
                               $count_ProductInventory = $cp_query->select('id')->first();


                               if ($count_ProductInventory) {
                                   $this->mobj->makeUpdate('platform_product_inventory', $inventory_Data, ['id' => $count_ProductInventory->id]);
                               } else {
                                   $this->mobj->makeInsert('platform_product_inventory', $inventory_Data);
                               }

                               
                               if ($count_Product->api_inventory_lastmodified_time != $api_inventory_lastmodified_time || is_null($count_Product->api_inventory_lastmodified_time) || $count_Product->inventory_sync_status == 'Pending') {
                                   $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Ready', 'api_inventory_lastmodified_time' => $api_inventory_lastmodified_time], ['id' => $count_Product->id]);
                               }

                        }
                        //end

                        //OlD code
                                // $count_ProductInventory = $this->mobj->getFirstResultByConditions('platform_product_inventory', $find_arr, ['id']);
                                // if ($count_ProductInventory) {
                                //    $this->mobj->makeUpdate('platform_product_inventory', $inventory_Data, ['id' => $count_ProductInventory->id]);
                                // } else {
                                //     $this->mobj->makeInsert('platform_product_inventory', $inventory_Data);
                               // }
   
                               // $count_ProductInventory = $this->mobj->getFirstResultByConditions('platform_product', ['user_integration_id' => $user_integration_id, 'sku' => $item_sku, 'user_id' => $user_id, 'platform_id' => $this->my_platform_id], ['id', 'api_inventory_lastmodified_time']);
   
                               // if ($count_ProductInventory && $count_ProductInventory->api_inventory_lastmodified_time != $api_inventory_lastmodified_time) {
                               //     $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Ready', 'api_inventory_lastmodified_time' => $api_inventory_lastmodified_time], ['id' => $count_ProductInventory->id]);
                               // }                                                                            

                    

                    }
                    if ($is_initial_sync) {
                        $return_data = 'data Remaining';
                        $PageNumber = $PageNumber + 1;
                    }
                    if(!$is_initial_sync && $is_backup_call){
                        $return_data = 'data Remaining';
                        $PageNumber = $PageNumber + 1; //updating Pagenumber by 1 if qunatity found 
                        $AllowPageNumberUpdate = 1;
                    }
                    
                }else{
                    $EmptyQty = 1; //set empty quantity flag on empty quantity
                }
            }
            if ($is_initial_sync) {
                if ($limit) {
                    $this->mobj->makeUpdate('platform_urls', ['url' => $PageNumber], ['id' => $limit->id]);
                } else {
                    $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url' => $PageNumber, 'url_name' => 'Quantities']);
                }
            }

            if(!$is_initial_sync && $is_backup_call){

                $qty_backup_url = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url_name' => 'quantities_backup_time'], ['url', 'id']);

                if(isset($qty_backup_url->url) && $qty_backup_url->url){

                    $qtysBackupRange = explode('|', $qty_backup_url->url);
                    $backupUrl = array_filter($qtysBackupRange);
                                
                    if(isset($AllowPageNumberUpdate)){ 
                        $backupUrl[2] = $PageNumber;
                        $this->mobj->makeUpdate('platform_urls', ['url' => implode('|',$backupUrl)], ['id' => $qty_backup_url->id]); //update Pagenumber if quantity found for next call
                    }
                                
                    if(isset($EmptyQty)){ 
                        if(count($backupUrl)>1){
                            $this->mobj->makeUpdate('platform_urls', ['url' => $backupUrl[1]], ['id' => $qty_backup_url->id]); //save only end date if quantity not found 
                        }
                    }
                    
                }
            }
            
            // } while ($allow_next_cal);
        }
       
        return $return_data;
    }

    //get available kit and quantity
    public function SkuvaultGetAvailableKitAndQuantities($user_id = '', $user_integration_id = '', $is_initial_sync = 0)
    {
        // $PageNumber = 50;
        $url = '';
        $return_data = true;

        $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'platform_id', 'env_type']);
        if ($ufound) {
            if ($ufound->env_type == 'production') { // checke account type .
                $url =  \Config::get('apiconfig.SkuvaultUrl');
            } else {
                $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
            }

            $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
            $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
            // $curl_post_data["PageNumber"] = $PageNumber;
            $curl_post_data["GetAvailableQuantity"] = true;

            //filters for kit for testing
            // $curl_post_data["KitSKUs"] = 'BUNDLE2-NKPM-6PK-2';
            
            $ModifiedBeforeDateTimeUtc = Carbon::now()->format('Y-m-d\TH:i:s.u\Z'); 

            //get this from url if found then pass else not
            $get_kitquantity = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url_name' => 'get_kit_quantities_time'], ['url', 'id']);

            if(isset($get_kitquantity->url) && $get_kitquantity->url){

                //set time filter
                $ModifiedAfterDateTimeUtc = Carbon::parse(trim($get_kitquantity->url))->sub(3, 'sec')->format('Y-m-d\TH:i:s.u\Z');
                //apply date filter
                $curl_post_data["AvailableQuantityModifiedAfterDateTimeUtc"] = $ModifiedAfterDateTimeUtc;
                $curl_post_data["AvailableQuantityModifiedBeforeDateTimeUtc"] = $ModifiedBeforeDateTimeUtc;

            } 

            $request_data_json = json_encode($curl_post_data);
            $response = $this->SkuvaultApi->GetAvailableKitAndQuantities($request_data_json, $url);
            $ProductInventory_data = json_decode($response, true);
            // dd($ProductInventory_data);

            if (isset($ProductInventory_data['Kits'])) {

                if ( count($ProductInventory_data['Kits']) > 0 ) {

                    $last_ModifiedAfterDateTimeUtc = NULL;
                    foreach ($ProductInventory_data['Kits'] as $inventory) {

                        $item_sku = $api_inventory_lastmodified_time = '';

                        $inventory_Data = [
                            'user_id' => $user_id,
                            'platform_id' => $ufound->platform_id,
                            'user_integration_id' => $user_integration_id,
                            'sync_status' => 'Ready',
                        ];

            
                        if (isset($inventory['AvailableQuantity'])) {
                            $inventory_Data['quantity'] = $inventory['AvailableQuantity'];
                        }
                        if (isset($inventory['SKU'])) {
                            $inventory_Data['sku'] = $inventory['SKU'];
                            $item_sku = $inventory['SKU'];
                        }
                        if (isset($inventory['LastModifiedDateTimeUtc'])) {
                            $api_inventory_lastmodified_time = $inventory['LastModifiedDateTimeUtc'];
                        }

                        if (isset($inventory['AvailableQuantityLastModifiedDateTimeUtc'])) {
                            $last_ModifiedAfterDateTimeUtc = $inventory['AvailableQuantityLastModifiedDateTimeUtc'];
                        }

                        $product_name ="";
                        if( isset($inventory['Description']) ) {
                            $product_name = $inventory['Description'];
                        }

                        $api_product_code = "";
                        if( isset($inventory['Code']) ) {
                            $api_product_code = $inventory['Code'];
                        }

                        
                        //changes made by gajendra on 08-11-2022
                        $find_Product = $this->mobj->getFirstResultByConditions('platform_product', ['user_integration_id' => $user_integration_id, 'sku' => $item_sku, 'platform_id' => $this->my_platform_id], ['id', 'api_inventory_lastmodified_time','inventory_sync_status']);
                        if($find_Product) {

                                $platform_product_id = $find_Product->id;
                                $inventory_Data['platform_product_id'] = $platform_product_id;

                               $cp_query = DB::table('platform_product_inventory')->where(['user_integration_id' => $user_integration_id]);

                               $cp_query->where(function($query) use ($platform_product_id, $item_sku){
                                    $query->where('platform_product_id', $platform_product_id)
                                    ->orWhere('sku',$item_sku);
                               });
                               $count_ProductInventory = $cp_query->select('id')->first();


                               if ($count_ProductInventory) {
                                   $this->mobj->makeUpdate('platform_product_inventory', $inventory_Data, ['id' => $count_ProductInventory->id]);
                               } else {
                                   $this->mobj->makeInsert('platform_product_inventory', $inventory_Data);
                               }
                               
                               if ($find_Product->api_inventory_lastmodified_time != $last_ModifiedAfterDateTimeUtc ) {
                                   $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Ready', 'api_inventory_lastmodified_time' => $last_ModifiedAfterDateTimeUtc], ['id' => $find_Product->id]);
                               }

                        } else {

                            //insert product.... & inventory
                            $platform_product_id = $this->mobj->makeInsertGetId('platform_product', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'sku' => $item_sku, 'product_name' => $product_name, 'bundle' => 1, 'product_sync_status' => 'Synced','inventory_sync_status' => 'Ready','api_product_code' => $api_product_code, 'api_inventory_lastmodified_time' => $last_ModifiedAfterDateTimeUtc ]);

                            $inventory_Data['platform_product_id'] = $platform_product_id;

                            $cp_query = DB::table('platform_product_inventory')->where(['user_integration_id' => $user_integration_id]);

                               $cp_query->where(function($query) use ($platform_product_id, $item_sku){
                                    $query->where('platform_product_id', $platform_product_id)
                                    ->orWhere('sku',$item_sku);
                               });
                               $count_ProductInventory = $cp_query->select('id')->first();


                               if ($count_ProductInventory) {
                                   $this->mobj->makeUpdate('platform_product_inventory', $inventory_Data, ['id' => $count_ProductInventory->id]);
                               } else {
                                   $this->mobj->makeInsert('platform_product_inventory', $inventory_Data);
                               }

                        }
                        //end

                    }

                    //update last ModifiedBeforeDateTimeUtc
                    if($last_ModifiedAfterDateTimeUtc) {
                        $ModifiedBeforeDateTimeUtc = $last_ModifiedAfterDateTimeUtc;
                    } 

                    //update platform url
                    if ($get_kitquantity) {
                        if( $get_kitquantity->url != $ModifiedBeforeDateTimeUtc ) {
                            $this->mobj->makeUpdate('platform_urls', ['url' => $ModifiedBeforeDateTimeUtc], ['id' => $get_kitquantity->id]);
                        }
                    } else {
                        $this->mobj->makeInsert('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id, 'url' => $ModifiedBeforeDateTimeUtc, 'url_name' => 'get_kit_quantities_time']);
                    }
                }

            }
        
        }
       
        return $return_data;
    }

    public function GetOrderStatus($source_platform_name,$user_id,$user_integration_id, $is_initial_sync,$order_type='SO')
    {
        if ($is_initial_sync) {
            return true;
        }


        try {

            $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'platform_id', 'env_type']);
            if ($ufound->env_type == 'production') { // checke account type .
                $url =  \Config::get('apiconfig.SkuvaultUrl');
            } else {
                $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
            }

           

            $start_date = date('Y-m-d',strtotime("-10 day", strtotime(date('Y-m-d'))));

            $OrderIds = $CompletedOrderIds = $post_data = [];


            $OrderIds = DB::table('platform_order')->where(['order_type' => $order_type, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id])->whereDate('order_date','>',$start_date)->where(function ($query) {
                $query->whereNull('order_status');
                $query->orWhere('order_status','!=','Completed');
            })->orderBy('updated_at','asc')->take(200)->pluck('api_order_id')->toArray();


            if (!empty($OrderIds)) {
                $post_data['Status'] = "Completed";
                $post_data['OrderIds'] = $OrderIds;
                $post_data['TenantToken'] = $this->mobj->encrypt_decrypt($ufound->refresh_token,'decrypt');
                $post_data['Usertoken'] = $this->mobj->encrypt_decrypt($ufound->access_token,'decrypt');

                //order get api with multiple ids
                $response = $this->SkuvaultApi->getOnlineSaleByMultipleIds($post_data, $url);
                $success_result = json_decode($response, true);

                if (isset($success_result["Sales"])) {
                    foreach ($success_result["Sales"] as $r => $succ) {
                        //$ex = explode('-', $succ['Id']);  //1-1471-7-1-111
                        //$ref_order_id = end($ex);   //parent order number
                        //'order_number' => $ref_order_id
                        if (isset($succ['Status']) && $succ['Status'] == 'Completed') {
                            $CompletedOrderIds[] = $succ['Id'];
                        }
                    }
                }

                $NonCompletedOrderIds = array_diff($OrderIds,$CompletedOrderIds);

                if(count($CompletedOrderIds) > 0){
                    DB::table('platform_order')->where(['order_type' => $order_type, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id])->whereIn('api_order_id', $CompletedOrderIds)->update(['order_status' => 'Completed']);
                }

                if(count($NonCompletedOrderIds) > 0){
                    DB::table('platform_order')->where(['order_type' => $order_type, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id])->whereIn('api_order_id', $NonCompletedOrderIds)->update(['updated_at' => now()]);
                }


            }

        } catch (\Exception $e) {
            \Log::error($user_integration_id . "--GetTestManualOrderUsingJson-->" . $e->getMessage());
            return $e->getMessage();
        }


        return true;
    }


    public function ExecuteEventSkuvault($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        try {
            $response = true;
            ////////GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.
            if ($method == 'GET' && $event == 'INVENTORY') {
                $response = $this->SkuvaultGetAvailableQuantities($user_id, $user_integration_id, $is_initial_sync, 0);
            }else if ($method == 'GET' && $event == 'INVENTORYBACKUP') {
                $response = $this->SkuvaultGetAvailableQuantities($user_id, $user_integration_id, $is_initial_sync, 1);
            } else if ($method == 'MUTATE' && $event == 'SALESORDER') {
                $sync_status = 'Ready';
                $this->SkuvaultCreateOnlineSales($user_id, $source_platform_id, $user_workflow_rule_id, $user_integration_id, $sync_status, $platform_workflow_rule_id, $record_id);
            } else if ($method == 'GET' && $event == 'PRODUCT') {
                $response = $this->SkuvaultGetProduct($user_id, $user_integration_id, $is_initial_sync);
            } else if ($method == 'GET' && $event == 'CATEGORY') {
                $response = $this->SkuvaultGetCategory($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'BRANDS') {
                $response = $this->SkuvaultGetBrands($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'SUPPLIERS') {
                $response = $this->SkuvaultGetSuppliers($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'WAREHOUSE') {
                $response = $this->SkuvaultGetWarehouse($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'SHIPMENT') {
                $response = $this->GetShipments($user_id, $user_integration_id, $source_platform_id,$destination_platform_id, $record_id, $is_initial_sync);
            } else if ($method == 'MUTATE' && $event == 'PRODUCT') {
                $response = $this->SkuvaultCreateProduct($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
            } else if ($method == 'GET' && $event == 'ORDERSTATUS') {
                $response = $this->GetOrderStatus($source_platform_id,$user_id,$user_integration_id, $is_initial_sync,'SO');
            } else if ($method == 'GET' && $event == 'FULLINVENTORY') {
                if ($is_initial_sync) {
                    return true;
                } else { //  all data coming by time so we ignore this call
                    return true;
                }
                //$response = $this->SkuvaultGetAvailableQuantities($user_id, $user_integration_id, 1);
            } else if ($method == 'GET' && $event == 'KITINVENTORY') {
                $response = $this->SkuvaultGetAvailableKitAndQuantities($user_id, $user_integration_id, $is_initial_sync);
            }
            
            return $response;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }


    //test function for pull product
    public function test_SkuvaultGetProduct($user_id = '', $user_integration_id = '', $time_sync = false)
    {
         try {
             $url = '';
             $api_updated_at = '';
             $PageNumber = 0;
             $PageSize = 1;
             $return_data = true;
             $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'platform_id', 'env_type']);
             if ($ufound) {
 
                 if ($ufound->env_type == 'production') { // checke account type .
                     $url =  \Config::get('apiconfig.SkuvaultUrl');
                 } else {
                     $url = \Config::get('apiconfig.SkuvaultUrlSandbox');
                 }
                 
                 $curl_post_data["TenantToken"] = $this->mobj->encrypt_decrypt($ufound->refresh_token, $action = 'decrypt');
                 $curl_post_data["UserToken"] = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
                //  $curl_post_data["PageNumber"] = $PageNumber;
                //  $curl_post_data["PageSize"] = $PageSize;
                // $curl_post_data["ProductSKU"] = 'VELVT-STR-OTTO-MTL-LEGS-15-18-TEAL';
                // $curl_post_data["ProductSKU"] = 'VELVTOTTOLEGS1518TEL';

                $curl_post_data["ProductSKU"] = 'VELVT-OTTO-30-GREY';
 
                 if (!$time_sync) {
                     $pull_time = DB::table('platform_product')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id])
                     ->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")
                     ->first();
                     if ($pull_time) {
                         $Modified_time = date(DATE_ISO8601, strtotime($pull_time->api_updated_at));
                         $pull_time_sys = str_replace('+00:00', 'T', gmdate('c', strtotime($Modified_time)));
                         $curl_post_data['ModifiedAfterDateTimeUtc'] = $pull_time_sys;
                     }
                 }
                 $request_data_json = json_encode($curl_post_data);

                //  dd($request_data_json, $pull_time);
                 $response = $this->SkuvaultApi->GetProduct_by_sku($request_data_json, $url);
                //  $response = $this->SkuvaultApi->GetProduct($request_data_json, $url);
                 $Product_data = json_decode($response, true);

                 if($Product_data) {
                    $Product = $Product_data['Product'];
                    $insertMissingProduct = $this->insertMissingProducts($user_id, $user_integration_id, $ufound, $Product);
                    dd($insertMissingProduct);
                 }
 
             }
 
         } catch (\Exception $e) {
             $return_data = $e->getMessage();
         }
         return $return_data;
    }

    //test function
    public function insertMissingProducts($user_id, $user_integration_id, $ufound, $Product)
    {
    
        $api_product_id = '';
        $Product_Data = [
            'user_id' => $user_id,
            'platform_id' => $ufound->platform_id,
            'user_integration_id' => $user_integration_id,
        ];
        if (isset($Product['Id'])) {
            $Product_Data['api_product_id'] = $Product['Id'];
            $api_product_id = $Product['Id'];
        }
        if (isset($Product['Code'])) {
            $Product['Code'];
        }
        if (isset($Product['Sku'])) {
            $Product_Data['sku'] = $Product['Sku'];
        }
        if (isset($Product['Description'])) {
            $Product_Data['product_name'] = $Product['Description'];
            $Product_Data['description'] = $Product['Description'];
        }
        if (isset($Product['Classification'])) {
            $Product_Data['category_id'] = $Product['Classification'];
        }
        if (isset($Product['Brand'])) {
            $Product_Data['brand_id'] = $Product['Brand'];
        }
        if (isset($Product['Supplier'])) {
            $Product_Data['api_warehouse_id'] =  $Product['Supplier'];
        }
        if (isset($Product['Cost'])) {
            $Product_Data['price'] = $Product['Cost'];
        }
        if (isset($Product['ModifiedDateUtc'])) {
            $Product_Data['api_updated_at'] = $Product['ModifiedDateUtc'];
            $api_updated_at = $Product['ModifiedDateUtc'];
        }
        //'api_product_id' => $api_product_id
        $api_product = $this->mobj->getFirstResultByConditions('platform_product', ['user_integration_id'=> $user_integration_id, 'platform_id' => $this->my_platform_id, 'sku' => $Product['Sku']], ['id', 'api_updated_at']);

        

        if ($api_product) {
            if ($api_product->api_updated_at != $api_updated_at) {
                $Product_Data['product_sync_status'] = 'Pending';
            }
            $this->mobj->makeUpdate('platform_product', $Product_Data, ['id' => $api_product->id]);
        } else {
            $this->mobj->makeInsert('platform_product', $Product_Data);
        }
        dd($api_product,$Product_Data,$Product);
        return true;
    }

}