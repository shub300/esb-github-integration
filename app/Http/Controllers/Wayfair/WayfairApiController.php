<?php

namespace App\Http\Controllers\Wayfair;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Api\WayfairApi;
use App\Helper\FieldMappingHelper;
use App\Helper\ConnectionHelper;
use App\Helper\WorkflowSnippet;
use App\Helper\Logger;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Config;
use App\Models\PlatformProduct;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\PlatformObjectData;
use App\Models\Enum\PlatformRecordType;
use Illuminate\Support\Carbon;

class WayfairApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public static $my_platform_name = 'wayfair';
    //public static $my_platform_id='';
    public $mobj,$wayfair,$log,$mapping,$WorkflowSnippet,$ConnectionHelper,$my_platform_id;

    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->wayfair = new WayfairApi();
        $this->log = new Logger();
        $this->mapping = new FieldMappingHelper();
        $this->WorkflowSnippet = new WorkflowSnippet();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->my_platform_id = $this->ConnectionHelper->getPlatformIdByName(self::$my_platform_name);
    }

    public function InitiateWFAuth(Request $request)
    {
        $platform = self::$my_platform_name;
        return view("pages.apiauth.wayfair_auth", compact('platform'));
    }

    public function ConnectWayfairOauth(Request $request)
    {
        $validated = $request->validate([
            'client_secret' => 'required',
            'client_id' => 'required',
            'account_name' => 'required',
        ]);

        $client_secret = trim($request->client_secret);
        $client_id = trim($request->client_id);
        $account_name = trim($request->account_name);
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
            $flag = true;
            $existing_skuvault = $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'account_name' => $account_name, 'platform_id' => $this->my_platform_id], ['id']);
            if ($existing_skuvault) {
                $data['status_code'] = 0;
                $data['status_text'] = 'Account name identifier is already exist with the same user, Try with another name.';
                return json_encode($data);
            }

            $enc_client_id = $this->mobj->encrypt_decrypt($client_id, $action = 'encrypt');
            $obj_existing = $this->mobj->getFirstResultByConditions('platform_accounts', ['app_id' => $enc_client_id, 'platform_id' => $this->my_platform_id], ['user_id']);
            if ($obj_existing) {
                $data['status_code'] = 0;
                $data['status_text'] = 'Given details are already in use, Try with other details.';
                return json_encode($data);
            }

            if ($env_type == 'on') { // checke account type  if on pro.
                $Audience_url = Config::get('apiconfig.WayfairAudience') . '/';
                $env_type = 'production';
            } else {
                $Audience_url = Config::get('apiconfig.WayfairUrlSandbox') . '/';
                $env_type = 'sandbox';
            }
            $response_data = $this->wayfair->GetTokan($client_secret, $client_id, $Audience_url);

            $response = json_decode($response_data, true);

            if (isset($response['access_token'])) {
                $OauthData = [
                    'access_token' => $this->mobj->encrypt_decrypt($response['access_token'], $action = 'encrypt'),
                    'account_name' => $account_name,
                    'app_secret' => $this->mobj->encrypt_decrypt($client_secret, $action = 'encrypt'),
                    'app_id' => $this->mobj->encrypt_decrypt($client_id, $action = 'encrypt'),
                    'expires_in' => $response['expires_in'],
                    'token_type' => $response['token_type'],
                    'user_id' => $user_id,
                    'platform_id' => $this->my_platform_id,
                    'env_type' => $env_type,
                    'token_refresh_time' => time()
                ];
                $this->mobj->makeInsert('platform_accounts', $OauthData);
            } else {
                if (isset($response['error'])) {
                    $flag = false;
                    $data['status_code'] = 0;
                    $data['status_text'] = $response['error'];
                }
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


    public function WayfairGetProduct($user_id = '', $user_integration_id, $source_platform_name, $destination_platform_name)
    {
        $url = '';
        $offset = 0;
        $limit = 100;
        $page = 0;
        $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['access_token', 'platform_id', 'env_type']);

        // $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
        // $dest_platform_id = $this->ConnectionHelper->getPlatformIdByName($destination_platform_name);

        $return_data = true;
        if ($ufound) {
            if ($ufound->env_type == 'production') { // checke account type .
                $url = Config::get('apiconfig.WayfairAudience');
            } else {
                $url = Config::get('apiconfig.WayfairUrlSandbox');
            }
            do {
                if ($page == 0) {
                    $offset = 0;
                } else {
                    $offset =  $page * $limit;
                }
                $allow_next_cal = false;
                $curl_post_data = array("query" => "query productCatalogs {
                productCatalogs (
                    limit:" . $limit . ",
                    offset:" . $offset . "
                ) {
                    supplierPartNumber,
                    manufacturerModelNumber,
                    manufacturerName,
                    supplierId,
                    productName,
                    collectionName,
                    wholesalePrice,
                    mapPrice,
                    upc,
                    fullRetailPrice,
                    minimumOrderQuantity,
                    forceMultiples,
                    displaySetQuantity,
                    manufacturerCountry,
                    harmonizedCode,
                    canadaCode,
                    leadTime,
                    leadTimeForReplacementParts,
                    sku,
                    skuStatus,
                    skuSubstatus,
                    whiteLabeled,
                    wayfairClass,
                    shippingInfo{
                        shipSpeed{
                          id,
                          name
                        },
                        weight{
                          amount,
                          unit
                        }
                      },
                      options{
                        name,
                        category
                      },
                      wayfairClass,
                }
            }", "variables" => array());
                $request_data_json = json_encode($curl_post_data);
                $response = $this->wayfair->GetProduct($this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt'), $url, $request_data_json, $source_platform_name, $destination_platform_name);

                // if($user_integration_id==451) {
                //     dd($request_data_json, $response);
                // }


                $Product_data = json_decode($response, true);
                if (!isset($Product_data['errors'])) {
                    if (isset($Product_data['data'])) {
                        if (isset($Product_data['data']['productCatalogs']) && count($Product_data['data']['productCatalogs'])) {
                            $allow_next_cal = true;
                            foreach ($Product_data['data']['productCatalogs'] as $Product) {
                                $api_product_id = $platform_product_id = '';
                                $Product_Data = [
                                    'user_id' => $user_id,
                                    'user_integration_id' => $user_integration_id,
                                    'platform_id' => $ufound->platform_id,
                                ];
                                if (isset($Product['supplierId'])) {
                                    $Product_Data['api_warehouse_id'] = $Product['supplierId'];
                                    //$api_product_id = $Product['supplierId'];
                                }
                                if (isset($Product['supplierPartNumber'])) {
                                    $Product_Data['api_product_id'] = $Product['supplierPartNumber'];
                                    $api_product_id = $Product['supplierPartNumber'];
                                }
                                if (isset($Product['productName'])) {
                                    $Product_Data['product_name'] = $Product['productName'];
                                }
                                if (isset($Product['upc'])) {
                                    $Product_Data['upc'] = $Product['upc'];
                                }
                                if (isset($Product['manufacturerName'])) {
                                    $Product_Data['brand_id'] = $Product['manufacturerName'];
                                }
                                if (isset($Product['sku'])) {
                                    $Product_Data['sku'] = $Product['sku'];
                                }
                                if (isset($Product['wayfairClass'])) {
                                    $Product_Data['category_id'] = $Product['wayfairClass'];
                                }
                                if (isset($Product['shippingInfo']['weight']['amount'])) {
                                    $Product_Data['weight'] = $Product['shippingInfo']['weight']['amount'];
                                }
                                if (isset($Product['shippingInfo']['weight']['unit'])) {
                                    //$Product_Data['weight_unit'] = $Product['shippingInfo']['weight']['unit'];
                                    if ($Product['shippingInfo']['weight']['unit'] == 'POUNDS') {
                                        $Product_Data['weight_unit'] = 'lbs';
                                    } elseif ($Product['shippingInfo']['weight']['unit'] == '') { } elseif ($Product['shippingInfo']['weight']['unit'] == '') { } elseif ($Product['shippingInfo']['weight']['unit'] == '') { } elseif ($Product['shippingInfo']['weight']['unit'] == '') { }
                                }
                                if (isset($Product['wholesalePrice'])) {
                                    $Product_Data['price'] = $Product['wholesalePrice'];
                                }
                                $api_product = $this->mobj->getFirstResultByConditions('platform_product', ['user_id' => $user_id, 'platform_id' => $ufound->platform_id, 'api_product_id' => $api_product_id], ['id']);
                                if ($api_product) {
                                    $platform_product_id = $api_product->id;
                                    $this->mobj->makeUpdate('platform_product', $Product_Data, ['id' => $api_product->id]);
                                } else {
                                    $Product_Data['inventory_sync_status'] = 'Ready';
                                    $Product_Data['product_sync_status'] = 'Ready';
                                    $platform_product_id = $this->mobj->makeInsertGetId('platform_product', $Product_Data);
                                }
                                if (isset($Product['options'])) {
                                    foreach ($Product['options'] as $options) {
                                        //dd($options);
                                        $options_count = $this->mobj->getFirstResultByConditions('platform_product_options', ['option_name' => $options['category'], 'option_value' => $options['name'], 'platform_product_id' => $platform_product_id], ['id']);
                                        if ($options_count) {
                                            $this->mobj->makeUpdate('platform_product_options', ['option_name' => $options['category'], 'platform_product_id' => $platform_product_id, 'option_value' => $options['name']], ['id' => $options_count->id]);
                                        } else {
                                            $this->mobj->makeInsert('platform_product_options', ['option_name' => $options['category'], 'option_value' => $options['name'], 'platform_product_id' => $platform_product_id]);
                                        }
                                    }
                                }
                            }
                            $page = $page + 1;
                        } else {
                            $allow_next_cal = false;
                        }
                    } else {
                        $allow_next_cal = false;
                    }
                } else {
                    $return_data = $response;
                }
            } while ($allow_next_cal);
        }
        return  $return_data;
    }

    public function RefreshTokens($platform_accounts_id, $user_id, $app_id, $app_secret, $env_type)
    {
        try {
            $url = '';
            //$ufound =  $this->mobj->getResultByConditions('platform_accounts', ['id' => $platform_accounts], ['user_id', 'app_id', 'app_secret', 'env_type']);
            if ($env_type == 'production') { // checke account type .
                $url = Config::get('apiconfig.WayfairAudience');
            } else {
                $url = Config::get('apiconfig.WayfairUrlSandbox');
            }
            $response = $this->wayfair->GetTokan($this->mobj->encrypt_decrypt($app_secret, $action = 'decrypt'), $this->mobj->encrypt_decrypt($app_id, $action = 'decrypt'), $url);
            $Tokan_data = json_decode($response, true);

            Storage::disk('local')->append('testCrone.txt', 'wayfairTokenResp :' . json_encode($response));


            if (isset($Tokan_data['access_token'])) {
                $OauthData = [
                    'access_token' => $this->mobj->encrypt_decrypt($Tokan_data['access_token'], $action = 'encrypt'),
                    'expires_in' => $Tokan_data['expires_in'],
                    'token_type' => $Tokan_data['token_type'],
                    'user_id' => $user_id,
                    'platform_id' => $this->my_platform_id,
                    'token_refresh_time' => time()
                ];
                $this->mobj->makeUpdate('platform_accounts', $OauthData, ['id' => $platform_accounts_id]);
            }
        } catch (\Exception $e) {
            $return_response = $e->getMessage();
            Storage::disk('local')->append('testCrone.txt', 'wayfair' . json_encode($return_response));
        }
    }

    public function WayfairUpdateInventory($user_id = '', $user_integration_id = '', $source_platform_name = '', $platform_workflow_rule_id = '', $user_workflow_rule_id = '', $record_id = '', $destination_platform_name)
    {
        $logFileName = 'wayfair_inventory_sync_log_' . date('Y-m-d') . '.txt';
        
        //dd($user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
        $process_limit = 100;
        $url = '';
        $return = true;
        $Inventory_arr = '';
        $dryRun = '';
        $inventory = '$inventory';
        $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
        // $dest_platform_id = $this->ConnectionHelper->getPlatformIdByName($destination_platform_name);

        $object_id = $this->ConnectionHelper->getObjectId('inventory');
        $product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');
        $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['access_token', 'platform_id', 'env_type']);

        if ($ufound) {
            do {
                $allow_next_call = false;
                if ($ufound->env_type == 'production') { // checke account type .
                    $url = Config::get('apiconfig.WayfairAudience');
                } else {
                    $url = Config::get('apiconfig.WayfairUrlSandbox');
                }
                if ($ufound->env_type == 'production') { // set  dryRun
                    $dryRun =  'false';
                } else {
                    $dryRun = 'true';
                }

                $maping_data = $this->mapping->getMappedField($user_integration_id, $platform_workflow_rule_id, $product_identity_obj_id);
                if ($maping_data) {
                    $source_row_data = $destination_row_data = '';
                    if ($maping_data['source_platform_id'] == 'wayfair') {
                        $destination_row_data = $maping_data['source_row_data'];
                        $source_row_data = $maping_data['destination_row_data'];
                    } elseif ($maping_data['destination_platform_id'] == 'wayfair') {
                        $destination_row_data = $maping_data['destination_row_data'];
                        $source_row_data = $maping_data['source_row_data'];
                    }
                    if ($record_id) {
                        $Inventory_arr = DB::table('platform_product as source_platform_product')->join('platform_product as destination_platform_product', 'destination_platform_product.' . $destination_row_data, '=', 'source_platform_product.' . $source_row_data)
                            ->where(['source_platform_product.user_integration_id' => $user_integration_id])
                            ->where(['source_platform_product.platform_id' => $source_platform_id, 'destination_platform_product.platform_id' => $this->my_platform_id])->where('source_platform_product.id', $record_id)
                            ->select('source_platform_product.id', 'destination_platform_product.sku as sku', 'destination_platform_product.api_product_id as way_api_product_id', 'source_platform_product.api_product_id as sku_api_product_id')->limit($process_limit)->get();
                    } else {
                        $Inventory_arr = DB::table('platform_product as source_platform_product')->join('platform_product as destination_platform_product', 'destination_platform_product.' . $destination_row_data, '=', 'source_platform_product.' . $source_row_data)
                            ->where(['source_platform_product.inventory_sync_status' => 'Ready', 'source_platform_product.user_integration_id' => $user_integration_id])
                            ->where(['source_platform_product.platform_id' => $source_platform_id, 'destination_platform_product.platform_id' => $this->my_platform_id])
                            ->select('source_platform_product.id', 'destination_platform_product.sku as sku', 'destination_platform_product.api_product_id as way_api_product_id', 'source_platform_product.api_product_id as sku_api_product_id')->limit($process_limit)->get();

                        if (!count($Inventory_arr)) { //if Ready not exist then pick Failed inventory.
                            $Inventory_arr = DB::table('platform_product as source_platform_product')->join('platform_product as destination_platform_product', 'destination_platform_product.' . $destination_row_data, '=', 'source_platform_product.' . $source_row_data)
                                ->where(['source_platform_product.inventory_sync_status' => 'Failed', 'source_platform_product.user_integration_id' => $user_integration_id])
                                ->where(['source_platform_product.platform_id' => $source_platform_id, 'destination_platform_product.platform_id' => $this->my_platform_id])->select('source_platform_product.id', 'destination_platform_product.sku as sku', 'destination_platform_product.api_product_id as way_api_product_id', 'source_platform_product.api_product_id as sku_api_product_id')->limit($process_limit)->get();
                        }
                    }
                }

                if ($Inventory_arr && count($Inventory_arr) == $process_limit) { // Don't want to loop contineously
                    $allow_next_call = false;
                }
                if ($Inventory_arr && count($Inventory_arr)) {

                    foreach ($Inventory_arr as $Inventory) {
                        $product_inventory_arr = $this->mobj->getResultByConditions('platform_product_inventory', ['user_integration_id' => $user_integration_id, 'api_product_id' => $Inventory->sku_api_product_id], ['id', 'api_warehouse_id', 'quantity']);
                        if (!count($product_inventory_arr)) {
                            $row_name = 'sku';
                            if ($destination_row_data == 'api_product_id') {
                                $row_name = 'way_api_product_id';
                            }
                            $product_inventory_arr = $this->mobj->getResultByConditions('platform_product_inventory', ['user_integration_id' => $user_integration_id, 'sku' => $Inventory->$row_name], ['id', 'api_warehouse_id', 'quantity']);
                        }
                        $update_inventory_data = [];
                        if (count($product_inventory_arr)) {
                            foreach ($product_inventory_arr as $product_inventory) {
                                $warehouse_mapp = $this->mapping->getMappedWarehouse($user_integration_id, $platform_workflow_rule_id, '', [], $product_inventory->api_warehouse_id);
                                if (isset($warehouse_mapp['Warehouse_id']) && $warehouse_mapp['Warehouse_id']) {
                                    $key = array_search($warehouse_mapp['Warehouse_id'], array_column($update_inventory_data, 'supplierId'));
                                    if (is_numeric($key)) {
                                        $update_inventory_data[$key]['quantityOnHand'] = (($update_inventory_data[$key]['quantityOnHand'] + $product_inventory->quantity) < 0)?0: ($update_inventory_data[$key]['quantityOnHand'] + $product_inventory->quantity);
                                    } else {
                                        $update_inventory_data[] = array("supplierId" => $warehouse_mapp['Warehouse_id'], "supplierPartNumber" => $Inventory->way_api_product_id, "quantityOnHand" => ($product_inventory->quantity < 0)?0: $product_inventory->quantity);
                                    }
                                } else {
                                    $warehouseId = $this->mapping->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "inventory_warehouse", ['api_id']);
                                    if ($warehouseId) {
                                        $update_inventory_data[] = array("supplierId" => $warehouseId->api_id, "supplierPartNumber" => $Inventory->way_api_product_id, "quantityOnHand" => ($product_inventory->quantity < 0)?0: $product_inventory->quantity );
                                    }
                                }
                            }
                            if (count($update_inventory_data)) {
                                $curl_post_data = array("query" => "mutation inventory($inventory: [inventoryInput]!) {
                                inventory {
                                  save(
                                    inventory: $inventory,
                                    feed_kind: DIFFERENTIAL,
                                    dryRun:$dryRun
                                  ) {
                                    id,
                                    handle,
                                    status,
                                    submittedAt,
                                    completedAt
                                  }
                                }
                              } ", "variables" => array(
                                    "inventory" => $update_inventory_data
                                ));
                                $request_data_json = json_encode($curl_post_data);
                                $response = $this->wayfair->UpdateInventory($this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt'), $url, $request_data_json, $source_platform_name, $destination_platform_name);
                                $Inventory_data = json_decode($response, true);

                                //log 
                                Storage::disk('local')->append($logFileName, 'WayfairUpdateInventory '.' time: ' . date('Y-m-d H:i:s').' user_integration_id :'.$user_integration_id .PHP_EOL. ' Request Body :' .json_encode($curl_post_data,true). ' Response : '.json_encode($Inventory_data,true) );


                                if (isset($Inventory_data['errors']) && count($Inventory_data['errors'])) {
                                    $return = $Inventory_data['errors'][0]['message'];
                                    $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Failed'], ['id' => $Inventory->id]);
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $Inventory->id, $return);
                                } else {
                                    if (isset($Inventory_data['data']['inventory'])) {
                                        if (isset($Inventory_data['data']['inventory']['save'])) {
                                            $nmsg = 'Inventory synced successfully!';
                                            $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Synced'], ['id' => $Inventory->id]);
                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'success', $Inventory->id, $nmsg);
                                        }
                                    }
                                }
                            } else {
                                $return = 'Inventory Warehouse data not Found!';
                                $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Failed'], ['id' => $Inventory->id]);
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $Inventory->id, $return);
                            }
                        } else {
                            $return = 'Inventory information not Found!';
                            $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Failed'], ['id' => $Inventory->id]);
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $Inventory->id, $return);
                        }
                    }
                }
            } while ($allow_next_call);
        }
        return $return;
    }

    public function storeWfWarehouse($user_id, $user_integration_id, $source_platform_name, $destination_platform_name)
    {

        try {

            $limit = 200;
            $response = true;

            $FromDate =  date(DATE_ISO8601, strtotime(date('Y-m-d') . '-1 month'));  // -1 month ordres

            //get wayfair tokens
            $wfToken = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['access_token', 'env_type']);

            // $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
            // $dest_platform_id = $this->ConnectionHelper->getPlatformIdByName($destination_platform_name);

            ///// will make a saprate method for this and get status
            if ($wfToken->env_type == 'production') { // checke account type .
                $url = Config::get('apiconfig.WayfairAudience');
            } else {
                $url = Config::get('apiconfig.WayfairUrlSandbox');
            }

            $hasResponse = '';  //to pick both open and closed orders

            //get wayfair warehouse from orders query by from and limit

            $result = $this->wayfair->getWFWarehouse($wfToken->access_token, $url, $FromDate, $limit, $hasResponse, $source_platform_name, $destination_platform_name);


            if ($result == 'Authorization failed.' || $result == 'Invalid environment for application.') {
                $response = $result;
            } else {
                $warehouses = json_decode($result, true);
                //get insert update order details
                $this->insertUpdateWFWarehouseDetails($user_id, $this->my_platform_id, $user_integration_id, $warehouses);
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
        }

        return $response;
    }

    public function storeWfOrders($user_id,  $user_integration_id, $is_onetime_sync, $source_platform_name, $destination_platform_name)
    {
        try {
            //*****************pagination offet not support in wayfair order api, fromdate will be used for offset orders************************/
            $limit = 100;
            $response = true;
            $platform_id = $this->my_platform_id;
            //get wf token based on integration flow
            $get_connect_account_id =  $this->mapping->getUserIntegrationDetailsById($user_integration_id, self::$my_platform_name);
            $get_workflow_rule = $this->mobj->getFirstResultByConditions('user_workflow_rule', ['user_integration_id' => $user_integration_id, 'status' => 1], ['platform_workflow_rule_id', 'sync_start_date']);

            // $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
            // $dest_platform_id = $this->ConnectionHelper->getPlatformIdByName($destination_platform_name);

            //get from based on onetime sync logic
            if ($is_onetime_sync == 1) {
                if ($get_workflow_rule) {
                    $FromDate =  date(DATE_ISO8601, strtotime($get_workflow_rule->sync_start_date));  //get start from date setup by user
                    //get wayfair tokens
                    $wfToken = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['access_token', 'env_type']);

                    ///// will make a saprate method for this and get status
                    if ($wfToken->env_type == 'production') { // checke account type .
                        $url = Config::get('apiconfig.WayfairAudience');
                    } else {
                        $url = Config::get('apiconfig.WayfairUrlSandbox');
                    }

                    //check platfor get order status
                    $hasResponse = '';
                    $get_platform_statusses = $this->WorkflowSnippet->getStatusByWorkflow($get_workflow_rule->platform_workflow_rule_id);  //get mapped statusses

                    if (in_array('Pending', $get_platform_statusses)) {
                        $hasResponse = false; //open orders
                    } else if (in_array('Ready to ship', $get_platform_statusses)) {
                        $hasResponse = true; //open orders
                    } else if (in_array('Pending', $get_platform_statusses) && in_array('Ready to ship', $get_platform_statusses)) {
                        $hasResponse = '';
                    }

                    do {
                        $order_result = []; //set empty for exit do while if no item found

                        //get WF order api
                        if ($get_connect_account_id) {

                            //get wayfair orders by from and limit
                            $result = $this->wayfair->getWFOrders($wfToken->access_token, $url, $FromDate, $limit, $hasResponse, $source_platform_name, $destination_platform_name);

                            if ($result == 'Authorization failed.' || $result == 'Invalid environment for application.') {
                                $response = $result;
                                break;
                            } else {
                                $orders = json_decode($result, true);
                                //get insert update order details
                                $this->insertUpdateWFOrderDetails($user_id, $platform_id, $user_integration_id, $orders);

                                if (isset($orders['data']['getDropshipPurchaseOrders'])) {
                                    $order_result = $orders['data']['getDropshipPurchaseOrders'];
                                } else {
                                    $response =  $result;
                                    break;
                                }
                            }
                            $get_order_date = DB::table('platform_order')->select('order_date')->where('order_type', 'PO')->where('platform_id', $platform_id)->where('user_integration_id', $user_integration_id)->orderByRaw("DATE_FORMAT(order_date, '%Y-%m-%d %H-%i-%s') DESC")->first();


                            if ($get_order_date) {
                                $FromDate =  date(DATE_ISO8601, strtotime($get_order_date->order_date . '+1 seconds'));
                            } else {
                                break;
                            }
                            sleep(1);
                        }
                    } while (count($order_result) > 0);  //until has data

                } else {
                    $response =  'GET Wayfair order workflow rule not found';
                }
            } else {
                $get_order_date = DB::table('platform_order')->select('order_date')->where('order_type', 'PO')->where('platform_id', $platform_id)->where('user_integration_id', $user_integration_id)->orderByRaw("DATE_FORMAT(order_date, '%Y-%m-%d %H-%i-%s') DESC")->first();

                if ($get_order_date) {
                    $FromDate =  date(DATE_ISO8601, strtotime($get_order_date->order_date . '+1 seconds'));
                } else {
                     //added by gajendra on 02-01-2023
                     if ($get_workflow_rule) {
                        $FromDate =  date(DATE_ISO8601, strtotime($get_workflow_rule->sync_start_date));  //get start from date setup by user
                    } else {
                        //set current date if no order found in order table for the first time
                        $FromDate = date(DATE_ISO8601, strtotime(date('Y-m-d H:i:s')));
                    }
                }
                if ($get_connect_account_id) {
                    $wfToken = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['access_token', 'env_type']);
                    if ($wfToken->env_type == 'production') { // checke account type .
                        $url = Config::get('apiconfig.WayfairAudience');
                    } else {
                        $url = Config::get('apiconfig.WayfairUrlSandbox');
                    }
                    $hasResponse = '';
                    $get_platform_statusses = $this->WorkflowSnippet->getStatusByWorkflow($get_workflow_rule->platform_workflow_rule_id);  //get mapped statusses
                    if (in_array('Pending', $get_platform_statusses)) {
                        $hasResponse = false; //open orders
                    } else if (in_array('Ready to ship', $get_platform_statusses)) {
                        $hasResponse = true; //open orders
                    } else if (in_array('Pending', $get_platform_statusses) && in_array('Ready to ship', $get_platform_statusses)) {
                        $hasResponse = '';
                    }
                    //get wayfair orders by from and limit
                    $result = $this->wayfair->getWFOrders($wfToken->access_token, $url, $FromDate, $limit, $hasResponse, $source_platform_name, $destination_platform_name);
                    if ($result == 'Authorization failed.' || $result == 'Invalid environment for application.') {
                        $response = $result;
                    } else {
                        $orders = json_decode($result, true);

                        //get insert update order details
                        $this->insertUpdateWFOrderDetails($user_id, $platform_id, $user_integration_id, $orders);
                    }
                }
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
        }
        return $response;
    }

    public function insertUpdateWFOrderDetails($user_id, $platform_id, $user_integration_id, $orders)
    {
        $object_id = $this->ConnectionHelper->getObjectId('warehouse');
        $ObjectId = $this->ConnectionHelper->getObjectId('sales_order');
        $find_Ship_Date_Record = $this->mobj->getFirstResultByConditions('platform_fields', [
            'platform_id' => $this->my_platform_id, 'user_integration_id' => 0,
            'field_type' => 'custom', 'name' => 'estimated_Ship_Date', 'platform_object_id' => $ObjectId, 'status' => 1
        ], ['id']);
        if (isset($orders['data']['getDropshipPurchaseOrders'])) {

            if (!empty($orders['data']['getDropshipPurchaseOrders'])) {

                foreach ($orders['data']['getDropshipPurchaseOrders'] as $ord) {

                    //customer data
                    $arr_customer = array();
                    $arr_customer['user_id'] = $user_id;
                    $arr_customer['platform_id'] = $this->my_platform_id;
                    $arr_customer['user_integration_id'] = $user_integration_id;
                    $arr_customer['customer_name'] = @$ord['customerName'];
                    $arr_customer['sync_status'] = 'Ready';
                    $platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $arr_customer);
                    $ware = $ord['warehouse'];
                    $arr_warehouse = array();
                    $arr_warehouse['user_id'] = $user_id;
                    $arr_warehouse['platform_id'] = $this->my_platform_id;
                    $arr_warehouse['name'] = @$ware['name'];
                    $arr_warehouse['api_id'] = @$ware['id'];
                    $arr_warehouse['user_integration_id'] = $user_integration_id;
                    $arr_warehouse['platform_object_id'] = $object_id;


                    $ord_warehouse = $this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id' => $this->my_platform_id, 'platform_object_id' => $object_id, 'user_id' => $user_id, 'api_id' => @$ware['id']], ['id']);

                    if ($ord_warehouse) {
                        $order_warehouse_id = $ord_warehouse->id;
                        $this->mobj->makeUpdate('platform_object_data', $arr_warehouse, ['id' => $order_warehouse_id]);
                    } else {
                        $order_warehouse_id = $this->mobj->makeInsertGetId('platform_object_data', $arr_warehouse);
                    }
                    //orders data
                    $arr_order = array();
                    $arr_order['user_id'] = $user_id;
                    $arr_order['platform_id'] = $this->my_platform_id;
                    $arr_order['platform_customer_id'] = $platform_customer_id;
                    $arr_order['user_integration_id'] = $user_integration_id;
                    $arr_order['order_type'] = "PO";

                    $arr_order['api_order_id'] = @$ord['id'];
                    $arr_order['order_number'] = @$ord['poNumber'];
                    $arr_order['order_date'] = date('Y-m-d H:i:s', strtotime($ord['poDate']));
                    $arr_order['warehouse_id'] = $order_warehouse_id;
                    $arr_order['platform_customer_id'] = $platform_customer_id;
                    $arr_order['delivery_date'] = @$ord['estimatedShipDate'];
                    $arr_order['ship_speed'] = @$ord['shippingInfo']['shipSpeed'];
                    $arr_order['carrier_code'] = @$ord['shippingInfo']['carrierCode'];

                    $order_details = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'order_number' => @$ord['poNumber']], ['id']);

                    if ($order_details) {
                        $platform_order_id = $order_details->id;
                        $this->mobj->makeUpdate('platform_order', $arr_order, ['id' => $platform_order_id]);
                    } else {
                        $arr_order['sync_status'] = 'Ready';
                        $platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
                    }

                    //custom_field start .  user not need  custom field
                    // if (isset($ord['estimatedShipDate']) && $ord['estimatedShipDate']) {
                    //     if ($find_Ship_Date_Record) {
                    //         $fields = array(
                    //             'platform_field_id' => $find_Ship_Date_Record->id,
                    //             'user_integration_id' => $user_integration_id,
                    //             'platform_id' => $this->my_platform_id,
                    //             'field_value' => $ord['estimatedShipDate'],
                    //             'record_id' => $platform_order_id
                    //         );
                    //         $platform_custom_field = $this->mobj->getFirstResultByConditions('platform_custom_field_values', ['record_id' => $platform_order_id, 'user_integration_id' => $user_integration_id, 'platform_field_id' => $find_Ship_Date_Record->id], ['id']);
                    //         if ($platform_custom_field) {
                    //             //$this->mobj->makeUpdate('platform_custom_field_values', $fields, ['id' => $platform_custom_field->id]);
                    //         } else {
                    //             $this->mobj->makeInsert('platform_custom_field_values', $fields);
                    //         }
                    //     }
                    // }
                    //custom_field start .

                    $order_total = 0;
                    //store order items
                    foreach (@$ord['products'] as $lineitem) {

                        $arr_order_line = array();
                        $arr_order_line['platform_order_id'] = $platform_order_id;
                        $arr_order_line['api_product_id'] = @$lineitem['partNumber'];
                        $arr_order_line['sku'] = @$lineitem['sku'];
                        $arr_order_line['qty'] = @$lineitem['quantity'] ? @$lineitem['quantity'] : 0;
                        $arr_order_line['price'] = @$lineitem['price'] ? @$lineitem['price'] : 0;
                        $arr_order_line['unit_price'] = @$lineitem['price'] ? @$lineitem['price'] : 0;

                        $line_total = floatval($arr_order_line['unit_price']) * $arr_order_line['qty'];

                        $arr_order_line['total'] = $line_total;
                        $arr_order_line['subtotal'] = $line_total;

                        $order_total += $line_total;

                        $ct_order_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'api_product_id' => @$arr_order_line['partNumber']]);

                        if ($ct_order_line > 0) {
                            $this->mobj->makeUpdate('platform_order_line', $arr_order_line, ['platform_order_id' => $platform_order_id, 'api_product_id' => @$arr_order_line['partNumber'], 'sku' => @$arr_order_line['sku'] ]);
                        } else {
                            $this->mobj->makeInsert('platform_order_line', $arr_order_line);
                        }
                    }

                    //update total amount into order table
                    $ordtotal['total_amount'] =  $order_total;
                    $this->mobj->makeUpdate('platform_order', $ordtotal, ["id" => $platform_order_id]);


                    //shipping address
                    $address = $ord['shipTo'];

                    $arr_order_address = array();
                    $arr_order_address['platform_order_id'] = $platform_order_id;
                    $arr_order_address['address_type'] = 'Shipping';
                    $arr_order_address['address_name'] = @$address['name'];
                    $arr_order_address['address1'] = @$address['address1'];
                    $arr_order_address['address2'] = @$address['address2'];
                    $arr_order_address['address3'] = @$address['address3'];
                    $arr_order_address['city'] = @$address['city'];
                    $arr_order_address['state'] = @$address['state'];
                    $arr_order_address['postal_code'] = @$address['postalCode'];
                    $arr_order_address['country'] = @$address['country'];
                    $arr_order_address['phone_number'] = @$address['phoneNumber'];

                    $arr_order_address['ship_speed'] = @$ord['shippingInfo']['shipSpeed'];
                    $arr_order_address['carrier_code'] = @$ord['shippingInfo']['carrierCode'];


                    $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);

                    if ($ct_address > 0) {
                        $this->mobj->makeUpdate('platform_order_address', $arr_order_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);
                    } else {
                        $this->mobj->makeInsert('platform_order_address', $arr_order_address);
                    }

                    //billing address
                    $billaddress = $ord['billTo'];

                    $arr_order_bill_address = array();
                    $arr_order_bill_address['platform_order_id'] = $platform_order_id;
                    $arr_order_bill_address['address_type'] = 'Billing';
                    $arr_order_bill_address['address_name'] = @$billaddress['name'];
                    $arr_order_bill_address['address1'] = @$billaddress['address1'];
                    $arr_order_bill_address['address2'] = @$billaddress['address2'];
                    $arr_order_bill_address['address3'] = @$billaddress['address3'];
                    $arr_order_bill_address['city'] = @$billaddress['city'];
                    $arr_order_bill_address['state'] = @$billaddress['state'];
                    $arr_order_bill_address['postal_code'] = @$billaddress['postalCode'];
                    $arr_order_bill_address['country'] = @$billaddress['country'];
                    $arr_order_bill_address['phone_number'] = @$address['phoneNumber'];

                    $arr_order_bill_address['ship_speed'] = @$ord['shippingInfo']['shipSpeed'];
                    $arr_order_bill_address['carrier_code'] = @$ord['shippingInfo']['carrierCode'];


                    $bill_ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);

                    if ($bill_ct_address > 0) {
                        $this->mobj->makeUpdate('platform_order_address', $arr_order_bill_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);
                    } else {
                        $this->mobj->makeInsert('platform_order_address', $arr_order_bill_address);
                    }
                    if (isset($ord['warehouse']['address'])) { // save shippedfrom data .
                        $ship_from = $ord['warehouse']['address'];
                        $arr_order_ship_from_address = array();
                        $arr_order_ship_from_address['platform_order_id'] = $platform_order_id;
                        $arr_order_ship_from_address['address_type'] = 'shippedfrom';
                        $arr_order_ship_from_address['address_name'] = @$ship_from['name'];
                        $arr_order_ship_from_address['address1'] = @$ship_from['address1'];
                        $arr_order_ship_from_address['address2'] = @$ship_from['address2'];
                        $arr_order_ship_from_address['address3'] = @$ship_from['address3'];
                        $arr_order_ship_from_address['city'] = @$ship_from['city'];
                        $arr_order_ship_from_address['state'] = @$ship_from['state'];
                        $arr_order_ship_from_address['postal_code'] = @$ship_from['postalCode'];
                        $arr_order_ship_from_address['country'] = @$ship_from['country'];
                        $bill_ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'shippedfrom']);

                        if ($bill_ct_address > 0) {
                            $this->mobj->makeUpdate('platform_order_address', $arr_order_ship_from_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'shippedfrom']);
                        } else {
                            $this->mobj->makeInsert('platform_order_address', $arr_order_ship_from_address);
                        }
                    }
                }
            } else { }
        }
    }

    public function insertUpdateWFWarehouseDetails($user_id, $platform_id, $user_integration_id, $orders)
    {
        $object_id = $this->ConnectionHelper->getObjectId('warehouse');
        if (isset($orders['data']['getDropshipPurchaseOrders'])) {

            if (!empty($orders['data']['getDropshipPurchaseOrders'])) {
                $warehouse = array();
                foreach ($orders['data']['getDropshipPurchaseOrders'] as $ord) {

                    //store and get warehouse
                    $ware = $ord['warehouse'];
                    $arr_warehouse = array();
                    $arr_warehouse['user_id'] = $user_id;
                    $arr_warehouse['platform_id'] = $this->my_platform_id;
                    $arr_warehouse['name'] = @$ware['name'];
                    $arr_warehouse['api_id'] = @$ware['id'];
                    $api_id = @$ware['id'];
                    $arr_warehouse['user_integration_id'] = $user_integration_id;
                    $arr_warehouse['platform_object_id'] = $object_id;
                    if (isset($warehouse[$api_id])) {
                        continue;
                    }
                    $ord_warehouse = $this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $object_id, 'api_id' => @$ware['id']], ['id']);

                    if ($ord_warehouse) {
                        $order_warehouse_id = $ord_warehouse->id;
                        $this->mobj->makeUpdate('platform_object_data', $arr_warehouse, ['id' => $order_warehouse_id]);
                    } else {
                        $order_warehouse_id = $this->mobj->makeInsertGetId('platform_object_data', $arr_warehouse);
                    }
                    $warehouse[$api_id] = $order_warehouse_id;
                }
            } else { }
        }
    }

    public function Wfacceptorder($user_id = '', $user_integration_id = '', $source_platform_name = '', $platform_workflow_rule_id = '', $user_workflow_rule_id = '', $record_id, $destination_platform_name)
    {
        try {
            $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
            // $dest_platform_id = $this->ConnectionHelper->getPlatformIdByName($destination_platform_name);

            $object_id = $this->ConnectionHelper->getObjectId('accept_order');
            $return = true;
            $poNumber = '$poNumber';
            $shipSpeed = '$shipSpeed';
            $lineItems = '$lineItems';
            $estimatedShipDate = '';
            $skToken = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'env_type']);
            if ($skToken) {
                do {
                    $allow_next_call = false;
                    $result_order = '';
                    $dryRun = '';
                    if ($skToken->env_type == 'production') { // checke account type .
                        $url = Config::get('apiconfig.WayfairAudience');
                    } else {
                        $url = Config::get('apiconfig.WayfairUrlSandbox');
                    }
                    if ($skToken->env_type == 'production') { // set  dryRun
                        $dryRun =  'false';
                    } else {
                        $dryRun = 'true';
                    }
                    if ($record_id) {
                        $result_order = $this->mobj->getResultByConditions('platform_order', ['id' => $record_id], ['id', 'linked_id']);
                    } else {
                        $result_order = $this->mobj->getResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'sync_status' => 'Ready'], ['id', 'linked_id'], ['id' => 'asc'], 10);
                        if (!count($result_order)) {
                            $result_order = $this->mobj->getResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'sync_status' => 'Failed'], ['id', 'linked_id'], ['id' => 'asc'], 10);
                        }
                    }
                    $k = 0;
                    foreach ($result_order as $row) {
                        $variables = [];
                        $parent_order = $this->mobj->getFirstResultByConditions('platform_order', ['id' => $row->linked_id], ['id', 'order_number', 'ship_speed', 'order_date']);
                        if ($parent_order) {
                            $purchase_order_object_id = $this->ConnectionHelper->getObjectId('estimate_ship_in_days');
                            $warehouse_mapp = $this->mapping->getMappedWarehouse($user_integration_id, $platform_workflow_rule_id, $purchase_order_object_id, ['custom_data']);
                            $lineItem = [];
                            if ($warehouse_mapp && $parent_order->order_date) {
                                $estimatedShipDate = date(DATE_ISO8601, strtotime('+' . $warehouse_mapp->custom_data . ' days ', strtotime($parent_order->order_date)));
                            }
                            $order_line_arr = $this->mobj->getResultByConditions('platform_order_line', ['platform_order_id' => $parent_order->id], []);
                            foreach ($order_line_arr as $order_line) {
                                $order_line_data['partNumber'] = $order_line->api_product_id;
                                $order_line_data['quantity'] = $order_line->qty;
                                $order_line_data['unitPrice'] = $order_line->price;
                                $order_line_data['estimatedShipDate'] = $estimatedShipDate;
                                $lineItem[] = $order_line_data;
                            }
                            $lineItem1 = json_encode($lineItem);
                            $array_final = preg_replace('/"([a-zA-Z]+[a-zA-Z0-9_]*)":/', '$1:', $lineItem1);
                            $curl_post_data = array('query' => 'mutation accept {
                            purchaseOrders {
                                accept (
                                    dryRun:' . $dryRun . ',
                                    poNumber:"' . "$parent_order->order_number" . '",
                                    shipSpeed:' . "$parent_order->ship_speed" . ',
                                    lineItems:' . $array_final . ',
                                ) {
                                    id,
                                    handle,
                                    status,
                                    submittedAt,
                                    completedAt,
                                    errors {
                                        key,
                                        message
                                    }
                                }
                            }
                        }');

                            $request_data_json = json_encode($curl_post_data);
                            $response = $this->wayfair->Wfacceptorder($this->mobj->encrypt_decrypt($skToken->access_token, $action = 'decrypt'), $url, $request_data_json, $source_platform_name, $destination_platform_name);
                            $order_data = json_decode($response, true);
                            if (isset($order_data['errors']) && count($order_data['errors'])) {
                                $return = $order_data['errors'][0]['message'];
                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $return);
                            } else {
                                if (isset($order_data['data'])) {
                                    $nmsg = 'Order Accepted successfully!';
                                    $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Synced'], ['id' => $row->id]);
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'success', $row->id, $nmsg);
                                }
                            }
                        }
                    }
                } while ($allow_next_call);
            }
        } catch (\Exception $e) {
            $return = $e->getMessage();
        }
        return $return;
    }

    /*Make Inventory sync Pending to sync every product inventory in wayfair in weakly */
    public function wayfairAllInventoryUpdateOnce($user_id, $user_integration_id, $source_platform_name, $destination_platform_name)
    {
        $return = true;
        try {

            $logFileName = 'wayfair_inventory_sync_log_' . date('Y-m-d') . '.txt';

            // run all inventory feed update for wayfair at alternate day
            $alternateDay = Carbon::today()->day % 2 !== 0; //Carbon::today()->day will return day of the month

            $url_name = 'Update_all_inventory_feed';
            $current_date = date('Y-m-d');

            // $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
            $dest_platform_id = $this->ConnectionHelper->getPlatformIdByName($destination_platform_name);

            if ($alternateDay)
            {
                $platform_urls = PlatformUrl::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $dest_platform_id,
                                'url_name' => $url_name
                            ])
                            ->select('id','url')
                            ->first();
                            
                $inv_sync_status = 'Ready';
                if($destination_platform_name == 'brightpearl'){ // if brighpearl we can modify to pending as well to fetch inv again
                    $inv_sync_status = 'Ready';
                }
                if ($platform_urls) {
                    //check last Run Date
                    if($platform_urls->url !=$current_date) {
                        PlatformProduct::whereIn('inventory_sync_status',['Synced','Failed'])->where(['user_integration_id' => $user_integration_id, 'platform_id' => $dest_platform_id])->update(['inventory_sync_status' => $inv_sync_status]);
                        DB::table('platform_urls')->where('id',$platform_urls->id)->update(['url' => $current_date]);
                    }
                } else {
                    PlatformProduct::whereIn('inventory_sync_status',['Synced','Failed'])->where(['user_integration_id' => $user_integration_id, 'platform_id' => $dest_platform_id])->update(['inventory_sync_status' => $inv_sync_status]);
                    DB::table('platform_urls')->insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $dest_platform_id, 'url_name' => $url_name, 'url' => $current_date, 'status'=> 1]);
                }


                //log wayfairAllInventoryUpdateOnce
                Storage::disk('local')->append($logFileName, 'wayfairAllInventoryUpdateOnce '.' time: ' .date('Y-m-d H:i:s').' user_integration_id :'.$user_integration_id.PHP_EOL);

            }
        } catch (\Exception $e) {
            $return = $e->getMessage();
        }
        return $return;

    }


    public function Wfshipment($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id, $destination_platform_name)
    {

        try {
            $limit = 50;
            $return = true;
            $dryRun = '';
            $skToken = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'env_type']);
            $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
            // $dest_platform_id = $this->ConnectionHelper->getPlatformIdByName($destination_platform_name);

            //pull setting for wayfair bundle product mapping check
            $allowBundleCheckInWF  = Config::get('apisettings.allowBundleCheckInWF');


            $object_id = $this->ConnectionHelper->getObjectId('sales_order_shipment');
            $product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');

            if ($skToken) {

                if ($skToken->env_type == 'production') { // checke account type .
                    $url = Config::get('apiconfig.WayfairAudience');
                } else {
                    $url = Config::get('apiconfig.WayfairUrlSandbox');
                }
                if ($skToken->env_type == 'production') { // set  dryRun
                    $dryRun =  'false';
                } else {
                    $dryRun = 'true';
                }
                $maping_data = $this->mapping->getMappedField($user_integration_id, $platform_workflow_rule_id, $product_identity_obj_id);
                $order_arr = '';
                if ($record_id) {
                    $order_arr = $this->mobj->getResultByConditions('platform_order', ['id' => $record_id], ['id', 'api_order_id', 'order_number', 'ship_speed', 'carrier_code', 'linked_id']);
                } else {
                    $order_arr = $this->mobj->getResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'shipment_status' => 'Ready'], ['id', 'api_order_id', 'order_number', 'ship_speed', 'carrier_code', 'linked_id'], [], $limit);
                    if (!count($order_arr)) {
                        $order_arr = $this->mobj->getResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'shipment_status' => 'Failed'], ['id', 'api_order_id', 'order_number', 'ship_speed', 'carrier_code', 'linked_id'], [], $limit);
                    }
                }

                //loop bp order to sync in wayfair
                foreach ($order_arr as $order) {

                    $order_shipment_lines_arr = '';
                    $shipments_data = DB::table('platform_order_shipments')->where(['platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $order->id])->first();


                    if ($shipments_data) {

                        if ($maping_data) {

                            //changes in mapping
                            $source_row_data = $destination_row_data = '';
                            if ($maping_data['source_platform_id'] == 'wayfair') {
                                $destination_row_data = $maping_data['source_row_data'];
                                $source_row_data = $maping_data['destination_row_data'];
                            } elseif ($maping_data['destination_platform_id'] == 'wayfair') {
                                $destination_row_data = $maping_data['destination_row_data'];
                                $source_row_data = $maping_data['source_row_data'];
                            }



                            //Find if source plateform added in checkWayfairBundleFor then check bundle product mapping logic
                            if ( isset($allowBundleCheckInWF[$source_platform_name]) )
                            {
                                //start update logic for order line & shipment line item diffrence...
                                $find_dest_order_line = PlatformOrderLine::where(['platform_order_id' => $order->linked_id])->select('api_product_id','qty','sku')->get();

                                $find_source_shipment_lines = PlatformOrderShipmentLine::where(['platform_order_shipment_id' => $shipments_data->id])
                                ->select('id','product_id','quantity')->get();

                                //formate shipment line data so that can find product & qty latter to avoid loop
                                $shipmentLineDataArray = [];
                                if( count($find_source_shipment_lines) > 0) {
                                    foreach ($find_source_shipment_lines as $shipment_item) {
                                        $shipmentLineDataArray[$shipment_item->product_id]['product_id'] = $shipment_item->product_id;
                                        $shipmentLineDataArray[$shipment_item->product_id]['quantity'] = $shipment_item->quantity;
                                    }
                                }


                                //check count or order line & shipment line..
                                if( count($find_source_shipment_lines) > count($find_dest_order_line) ) {

                                    //loop order line
                                    foreach( $find_dest_order_line as $wayfair_order_line) {

                                        //dynamic identifires $source_row_data = bp identifire,  $destination_row_data = wayfair;
                                        if($destination_row_data=='sku') {
                                        $wayfair_uid = $wayfair_order_line->sku;
                                        } else {
                                            $wayfair_uid = $wayfair_order_line->api_product_id;
                                        }

                                        //find mapped bp (source) product
                                        $find_source_product = PlatformProduct::where(['platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id, $source_row_data => $wayfair_uid ])->select('api_product_id','bundle')->first();

                                        //handle bundle product
                                        $order_shipment_lines_arr = [];
                                        if($find_source_product) {

                                            if( $find_source_product->bundle == 1) {

                                                $bundle_shipment_line['api_product_id'] = $wayfair_uid;
                                                $bundle_shipment_line['quantity'] = $wayfair_order_line->qty;

                                                array_push($order_shipment_lines_arr, (object) $bundle_shipment_line);

                                            } else {

                                                $shipment_api_product_id = null;
                                                $shipment_line_quantity = 0;

                                                //Find find_source_product in shipment Line data
                                                if($shipmentLineDataArray && isset($shipmentLineDataArray[$find_source_product->api_product_id]) ) {

                                                    $bundle_shipment_line['api_product_id'] = $wayfair_uid;
                                                    $bundle_shipment_line['quantity'] = $shipmentLineDataArray[$find_source_product->api_product_id]['quantity'];
                                                    array_push($order_shipment_lines_arr, (object) $bundle_shipment_line);

                                                }


                                            }

                                        }


                                    }


                                } else {

                                    //get order_shipment_lines_arr using old tradition method
                                    $order_shipment_lines_arr = DB::table('platform_order_shipment_lines')->join('platform_order_line', 'platform_order_shipment_lines.sku', '=', 'platform_order_line.' . $destination_row_data)->where(['platform_order_line.platform_order_id' => $order->linked_id, 'platform_order_shipment_lines.platform_order_shipment_id' => $shipments_data->id])->select('platform_order_line.api_product_id', 'platform_order_shipment_lines.quantity')
                                    ->groupBy('platform_order_line.api_product_id')->get();

                                    if (!count($order_shipment_lines_arr)) {

                                        $order_shipment_lines_arr = DB::table('platform_order_shipment_lines')
                                            ->join('platform_product', 'platform_product.api_product_id', '=', 'platform_order_shipment_lines.product_id')
                                            ->join('platform_order_line', 'platform_product.sku', '=', 'platform_order_line.' . $destination_row_data)
                                            ->where(['platform_order_line.platform_order_id' => $order->linked_id, 'platform_order_shipment_lines.platform_order_shipment_id' => $shipments_data->id])
                                            ->select('platform_order_line.api_product_id', 'platform_order_shipment_lines.quantity')
                                            ->groupBy('platform_order_line.api_product_id')
                                            ->get();
                                    }
                                    //end

                                }

                            } else {

                                //get order_shipment_lines_arr using old tradition method
                                $order_shipment_lines_arr = DB::table('platform_order_shipment_lines')->join('platform_order_line', 'platform_order_shipment_lines.sku', '=', 'platform_order_line.' . $destination_row_data)->where(['platform_order_line.platform_order_id' => $order->linked_id, 'platform_order_shipment_lines.platform_order_shipment_id' => $shipments_data->id])->select('platform_order_line.api_product_id', 'platform_order_shipment_lines.quantity')
                                ->groupBy('platform_order_line.api_product_id')->get();

                                if (!count($order_shipment_lines_arr)) {

                                    $order_shipment_lines_arr = DB::table('platform_order_shipment_lines')
                                        ->join('platform_product', 'platform_product.api_product_id', '=', 'platform_order_shipment_lines.product_id')
                                        ->join('platform_order_line', 'platform_product.sku', '=', 'platform_order_line.' . $destination_row_data)
                                        ->where(['platform_order_line.platform_order_id' => $order->linked_id, 'platform_order_shipment_lines.platform_order_shipment_id' => $shipments_data->id])
                                        ->select('platform_order_line.api_product_id', 'platform_order_shipment_lines.quantity')
                                        ->groupBy('platform_order_line.api_product_id')
                                        ->get();
                                }
                                //end


                            }
                            //end..logic update


                        } else {

                            $order_shipment_lines_arr = DB::table('platform_order_shipment_lines')->join('platform_order_line', 'platform_order_shipment_lines.sku', '=', 'platform_order_line.sku')->where(['platform_order_line.platform_order_id' => $order->linked_id, 'platform_order_shipment_lines.platform_order_shipment_id' => $shipments_data->id])->select('platform_order_line.api_product_id', 'platform_order_shipment_lines.quantity')
                                ->groupBy('platform_order_line.api_product_id')
                                ->get();
                        }

                        $items = [];
                        foreach ($order_shipment_lines_arr as $order_shipment_lines) {
                            $items_data['partNumber'] = $order_shipment_lines->api_product_id;
                            $items_data['quantity'] = $order_shipment_lines->quantity;
                            $items[] = $items_data;
                        }


                        if (!count($items)) {
                            $return = 'Shipment line is not matching with the order line item';
                            $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $order->id]);
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $order->id, $return);
                            continue;
                        }

                        $lineItem1 = json_encode($items);
                        $array_final = preg_replace('/"([a-zA-Z]+[a-zA-Z0-9_]*)":/', '$1:', $lineItem1);

                        $sourceAddress = DB::table('platform_order_address')->where(['platform_order_id' => $shipments_data->platform_order_id, 'address_type' => 'shippedfrom'])->first();

                        if (!$sourceAddress) { // if shipped from addres not found..
                            $sourceAddress = DB::table('platform_order_address')->where(['platform_order_id' => $order->linked_id, 'address_type' => 'shippedfrom'])->first();
                        }
                        $sourceAddress_arr = [];
                        if ($sourceAddress) {
                            $sourceAddress_arr['name'] = ($sourceAddress->address_name) ? $sourceAddress->address_name : "";
                            $sourceAddress_arr['streetAddress1'] = ($sourceAddress->address1) ? $sourceAddress->address1 : "";
                            $sourceAddress_arr['streetAddress2'] = ($sourceAddress->address1) ? $sourceAddress->address2 : "";
                            $sourceAddress_arr['city'] = ($sourceAddress->city) ? $sourceAddress->city : "";
                            $sourceAddress_arr['state'] = ($sourceAddress->state) ? $sourceAddress->state : "";
                            $sourceAddress_arr['postalCode'] = ($sourceAddress->postal_code) ? $sourceAddress->postal_code : "";
                            $sourceAddress_arr['country'] = ($sourceAddress->country) ? $sourceAddress->country : "";
                        }
                        $sourceAddress_data = '';
                        if (count($sourceAddress_arr)) {
                            $lineItem1 = json_encode($sourceAddress_arr);
                            $sourceAddress_data = preg_replace('/"([a-zA-Z]+[a-zA-Z0-9_]*)":/', '$1:', $lineItem1);
                        }
                        $destinationAddress = DB::table('platform_order_address')->where(['platform_order_id' => $order->linked_id, 'address_type' => 'shipping'])->first();
                        $destinationAddress_arr = [];
                        if ($destinationAddress) {
                            $destinationAddress_arr['name'] = ($destinationAddress->address_name) ? $destinationAddress->address_name : "";
                            $destinationAddress_arr['streetAddress1'] = ($destinationAddress->address1) ? $destinationAddress->address1 : "";
                            $destinationAddress_arr['streetAddress2'] = ($destinationAddress->address2) ? $destinationAddress->address1 : "";
                            $destinationAddress_arr['city'] = ($destinationAddress->city) ? $destinationAddress->city : "";
                            $destinationAddress_arr['state'] = ($destinationAddress->state) ? $destinationAddress->state : "";
                            $destinationAddress_arr['postalCode'] = ($destinationAddress->postal_code) ? $destinationAddress->postal_code : "";
                            $destinationAddress_arr['country'] = ($destinationAddress->country) ? $destinationAddress->country : "";
                        }
                        $destinationAddress_data = '';
                        if (count($destinationAddress_arr)) {
                            $lineItem1 = json_encode($destinationAddress_arr);
                            $destinationAddress_data = preg_replace('/"([a-zA-Z]+[a-zA-Z0-9_]*)":/', '$1:', $lineItem1);
                        }
                        $warehouse_id = '';
                        $warehouse_mapp = $this->mapping->getMappedWarehouse($user_integration_id, '', '', [], $shipments_data->warehouse_id);
                        if (isset($warehouse_mapp['Warehouse_id']) && $warehouse_mapp['Warehouse_id']) {
                            $warehouse_id = $warehouse_mapp['Warehouse_id'];
                        } else {
                            $warehouseId = $this->mapping->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "inventory_warehouse", ['api_id']);
                            if ($warehouseId) {
                                $warehouse_id = $warehouseId->api_id;
                            } else {
                                $return = 'Warehouse mapping does not exist';
                                $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $order->id]);
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $order->id, $return);
                                continue;
                            }
                        }
                        $packageCount = 1;
                        $shipSpeed = $carrierCode = '';
                        $sOrder = $this->mobj->getFirstResultByConditions('platform_order', ['id' => $order->linked_id], ['ship_speed', 'carrier_code', 'order_number']);
                        if ($sOrder) {
                            $carrierCode = ($shipments_data->carrier_code) ? $shipments_data->carrier_code : $sOrder->carrier_code;
                            if ($shipments_data->shipping_method && !is_numeric($shipments_data->shipping_method)) {
                                $shipSpeed = $shipments_data->shipping_method;
                            } else {
                                $shipSpeed = $sOrder->ship_speed;
                            }
                        }

                        if ($shipments_data->boxes) {
                            $packageCount = $shipments_data->boxes;
                        }

                        $curl_post_data = array('query' => 'mutation shipment {
                                 purchaseOrders {
                                          shipment (
                                               dryRun:' . $dryRun . ',
                                               notice: {
                                                  poNumber:"' . "$sOrder->order_number" . '",
                                                  supplierId:' . $warehouse_id . ',
                                                  packageCount:' . $packageCount . ',
                                                  weight:' . (float) $shipments_data->weight . ',
                                                  carrierCode:"' . "$carrierCode" . '",
                                                  shipSpeed:' . $shipSpeed . ',
                                                  trackingNumber:"' . "$shipments_data->tracking_info" . '",
                                                  shipDate:"' . date('Y-m-d H:i:s', strtotime($shipments_data->created_on)) . '",
                                                  sourceAddress:' . $sourceAddress_data . ',
                                                  destinationAddress:' . $destinationAddress_data . ',
                                                  smallParcelShipments: [
                                                                 {
                                                           package: {
                                                                 code: {
                                                                    type: TRACKING_NUMBER,
                                                                    value:"' . $shipments_data->tracking_info . '"
                                                                    },
                                                               weight:' . (float) $shipments_data->weight . '
                                                          },
                                                      items:' . $array_final . '
                                                     }
                                                  ]
                                              }
                                           ) {
                                               id,
                                               handle,
                                               status,
                                               submittedAt,
                                               completedAt,
                                              errors {
                                                    key,
                                                    message
                                                   }
                                              }
                                          }
                                    }');
                        $request_data_json = json_encode($curl_post_data);


                        $response = $this->wayfair->shipment($this->mobj->encrypt_decrypt($skToken->access_token, $action = 'decrypt'), $url, $request_data_json, $source_platform_name, $destination_platform_name);
                        $order_data = json_decode($response, true);
                        if (isset($order_data['errors']) && count($order_data['errors'])) {
                            $return = $order_data['errors'][0]['message'];
                            $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $order->id]);
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $order->id, $return);
                        } else {
                            if (isset($order_data['data'])) {
                                $nmsg = 'Order Shipment successfully!';
                                $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Synced'], ['id' => $order->id]);
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'success', $order->id, $nmsg);
                            }
                        }

                    }

                }

            }

        } catch (\Exception $e) {
            $return = $e->getMessage();
        }
        return $return;
    }

    //allow wayfair inventory update
    public function WayfairUpdateInventorybyintegration($user_integration_id, $process_limit, $skip, $user_id)
    {

        $platform_urls = DB::table('platform_urls')->where('user_integration_id',$user_integration_id)
        ->where('platform_id',$this->my_platform_id)->where('url_name','Allow_Wayfair_Inventory_update')->select('id','url')->first();

        if ($platform_urls) {
           $platform_urls_id = $platform_urls->id;
           $skip = $platform_urls->url;
           $processed_url = $platform_urls->url + $process_limit;
        } else {
            $processed_url = $skip + $process_limit;
            $platform_urls_id = $this->mobj->makeInsertGetId('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => 'Allow_Wayfair_Inventory_update', 'url' => $processed_url]);
        }


        $url = '';
        $return = true;
        $Inventory_arr = '';
        $dryRun = '';
        $inventory = '$inventory';
        $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['access_token', 'platform_id', 'env_type']);


        if ($ufound) {
            if ($ufound->env_type == 'production') { // checke account type .
                $url = Config::get('apiconfig.WayfairAudience');
            } else {
                $url = Config::get('apiconfig.WayfairUrlSandbox');
            }
            if ($ufound->env_type == 'production') { // set  dryRun
                $dryRun =  'true';
            } else {
                $dryRun =  'true';
            }

            $Inventory_arr = DB::table('platform_product')
                ->where(['inventory_sync_status' => 'Ready', 'user_integration_id' => $user_integration_id])
                ->where(['platform_id' => $this->my_platform_id])
                ->select('id', 'sku', 'api_product_id', 'api_product_id as way_api_product_id')
                ->limit($process_limit)->skip($skip)
                ->orderBy('id','asc')->get();


            $warehouseId = null;
            $warehouse_mapping = $this->mapping->getMappedDataByName($user_integration_id, NULL, "inventory_warehouse", ['api_id']);
            if($warehouse_mapping) {
                $warehouseId = $warehouse_mapping->api_id;
            }

            
            $source_platform_name = 'skuvault';
            $destination_platform_name = 'wayfair';

            if ( count($Inventory_arr) > 0 && $warehouseId) {

                foreach ($Inventory_arr as $Inventory) {
                    $update_inventory_data = [];
                    $update_inventory_data[] = array("supplierId" => $warehouseId, "supplierPartNumber" => $Inventory->way_api_product_id, "quantityOnHand" => 1);
                    if (count($update_inventory_data)) {
                        $curl_post_data = array("query" => "mutation inventory($inventory: [inventoryInput]!) {
                                inventory {
                                  save(
                                    inventory: $inventory,
                                    feed_kind: DIFFERENTIAL,
                                    dryRun:$dryRun
                                  ) {
                                    id,
                                    handle,
                                    status,
                                    submittedAt,
                                    completedAt
                                  }
                                }
                              } ", "variables" => array(
                            "inventory" => $update_inventory_data
                        ));
                        $request_data_json = json_encode($curl_post_data);


                        $response = $this->wayfair->UpdateInventory($this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt'), $url, $request_data_json, $source_platform_name, $destination_platform_name);
                        $Inventory_data = json_decode($response, true);

                        //log response
                        if($Inventory_data) {
                            $log_file_name = "wayfair_allow_inventory_update_".$user_integration_id."_".$warehouseId.".txt";
                            Storage::disk('local')->append($log_file_name, 'time: ' . date('Y-m-d H:i:s').' postData-'
                            .$request_data_json .PHP_EOL. ' Response-'.json_encode($Inventory_data,true) .PHP_EOL .PHP_EOL);
                        }


                    }
                }

            } else {
                return ;
            }

            if( $platform_urls ){
                $this->mobj->makeUpdate('platform_urls', ['url' => $processed_url], ['id' => $platform_urls_id]);
            }


        }
        return $return;
    }


    public function ExecuteEventWayfair($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        // Log::Info( "ExecuteEventWayfair = method: ".$method." - event: ".$event);//."destination_platform_id: ".$destination_platform_id." - user_id: ".$user_id." - user_integration_id: ".$user_integration_id." - is_initial_sync: ".$is_initial_sync." - user_workflow_rule_id: ".$user_workflow_rule_id." - source_platform: ".$source_platform." - platform_workflow_rule_id: ".$platform_workflow_rule_id." - record_id: ".$record_id );
        try {
            $response = true;
            ////////GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.
            if ($method == 'GET' && $event == 'PURCHASEORDER') {
                $response = $this->storeWfOrders($user_id,  $user_integration_id, $is_initial_sync, $source_platform_id, $destination_platform_id);
            } else if ($method == 'GET' && $event == 'PRODUCT') {
                $response = $this->WayfairGetProduct($user_id, $user_integration_id, $source_platform_id, $destination_platform_id);
            } else if ($method == 'GET' && $event == 'WAREHOUSE') {
                $response = $this->storeWfWarehouse($user_id,  $user_integration_id, $source_platform_id, $destination_platform_id);
            } else if ($method == 'MUTATE' && $event == 'INVENTORY') {
                $response = $this->WayfairUpdateInventory($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id, $destination_platform_id);
            } else if ($method == 'MUTATE' && $event == 'ACCEPTSALESORDER') {
                $response = $this->Wfacceptorder($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id, $destination_platform_id);
            } else if ($method == 'MUTATE' && $event == 'LABEL') {
                //Log::Info( "ExecuteEventWayfair SHIPMENT:- user_id: ".$user_id." - user_integration_id: ".$user_integration_id."source_platform_id: ".$source_platform_id." - platform_workflow_rule_id: ".$platform_workflow_rule_id." - user_workflow_rule_id: ".$user_workflow_rule_id." - record_id: ".$record_id." - destination_platform_id: ".$destination_platform_id );
                $response = $this->createShipmentLabel($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id, $destination_platform_id);
            } else if ($method == 'MUTATE' && $event == 'SHIPMENT') {
                //Log::Info( "ExecuteEventWayfair SHIPMENT:- user_id: ".$user_id." - user_integration_id: ".$user_integration_id."source_platform_id: ".$source_platform_id." - platform_workflow_rule_id: ".$platform_workflow_rule_id." - record_id: ".$record_id." - destination_platform_id: ".$destination_platform_id );
                $response = $this->Wfshipment($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id, $destination_platform_id);
            } else if ($method == 'GET' && $event == 'UPDATEALLINVENTORYFEED') {
                //update all inventory once
                $this->wayfairAllInventoryUpdateOnce($user_id,$user_integration_id,$source_platform_id,$destination_platform_id);
            }

            return $response;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }


    public function createShipmentLabel($user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id, $destination_platform_name)
    {
        try {
            $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);

            $object_id = $this->ConnectionHelper->getObjectId('purchase_order');
            $return = true;

            $estimatedShipDate = date(DATE_ISO8601, strtotime('+ 1 days ', strtotime(date("Y-m-d H:i:s"))));

            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'env_type', 'app_secret']);

            if ($ufound) {
                $result_order = '';
                $dryRun = 'true';
                if ($ufound->env_type == 'production') { // checke account type .
                    $url = Config::get('apiconfig.WayfairAudience');
                } else {
                    $url = Config::get('apiconfig.WayfairUrlSandbox');
                }

                if ($ufound->env_type == 'production') { // set  dryRun
                    $dryRun =  'false';
                }

                $limit = 20;
                if ($record_id) {
                    $result_order = $this->mobj->getResultByConditions('platform_order', ['id' => $record_id], ['id', 'linked_id', 'order_number', 'warehouse_id']);
                } else {
                    $result_order = $this->mobj->getResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'shipment_label_status' => 'Ready'], ['id', 'linked_id', 'order_number', 'warehouse_id'], ['id' => 'asc'], $limit);
                    if (!count($result_order)) {
                        $result_order = $this->mobj->getResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'shipment_label_status' => 'Failed'], ['id', 'linked_id', 'order_number', 'warehouse_id'], ['id' => 'asc'], $limit);
                    }
                }

                foreach ($result_order as $row) {
                    $variables = [];
                    $parent_order = $this->mobj->getFirstResultByConditions('platform_order', ['id' => $row->linked_id], ['id', 'order_number', 'ship_speed', 'order_date', 'warehouse_id', 'linked_id']);

                    if ($parent_order) {

                        $OrderWarehouseId = PlatformObjectData::where(['id' => $parent_order->warehouse_id])->pluck('api_id')->first();

                        $curl_post_data = '{"query":"mutation register($params: RegistrationInput!) { purchaseOrders { register(registrationInput: $params) { eventDate, pickupDate, consolidatedShippingLabel { url, }, shippingLabelInfo { carrier, carrierCode, trackingNumber, }, purchaseOrder { poNumber, shippingInfo { carrierCode } } } } }","variables":{"params":{"poNumber":"'.$parent_order->order_number.'","warehouseId":"'.$OrderWarehouseId.'","requestForPickupDate":"'.$estimatedShipDate.'"}}}';


                        $response = $this->wayfair->createShipmentLabel($ufound->access_token, $url, $curl_post_data, $source_platform_name, $destination_platform_name);

                        $order_data = json_decode($response, true);

                        if (isset($order_data['errors']) && count($order_data['errors'])) {
                            $return = $order_data['errors'][0]['message'];
                            $this->mobj->makeUpdate('platform_order', ['shipment_label_status' => 'Failed'], ['id' => $row->id]);
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $return);
                        } else {
                            if (isset($order_data['data']['purchaseOrders']['register'])) {

                                $find_source_shipment = PlatformOrderShipment::where([ 'platform_order_id' => $row->id])->first();

                                $lableCreationResponse = $order_data['data']['purchaseOrders']['register'];

                                $trackingUrl = $lableCreationResponse['consolidatedShippingLabel']['url'];
                                $purchaseOrderNumber = $lableCreationResponse['purchaseOrder']['poNumber'];
                                

                                $shipment = PlatformOrderShipment::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $parent_order->id])->first();
                                if($shipment){ //udapte shipping label info after create shipment label
                                    $shipment->tracking_url = $this->GetShippingLabelAndUploadInS3($user_integration_id, $ufound->access_token, $purchaseOrderNumber, $trackingUrl, $source_platform_name, $destination_platform_name);
                                    $shipment->carrier_code = $lableCreationResponse['shippingLabelInfo'][0]['carrierCode'];
                                    $shipment->shipping_method = $lableCreationResponse['shippingLabelInfo'][0]['carrierCode'];
                                    $shipment->tracking_info = $lableCreationResponse['shippingLabelInfo'][0]['trackingNumber'];
                                    $shipment->shipment_id = $purchaseOrderNumber; // putting order id only since no shipment id available
                                    if($find_source_shipment){
                                        $shipment->linked_id = $find_source_shipment->id;
                                    }
                                    $shipment->save();
                                }else{
                                    $shipment_insert = ['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'platform_order_id' => $parent_order->id, 'user_integration_id' => $user_integration_id, 'sync_status' => "Ready", 'type' => PlatformRecordType::SHIPMENT];

                                    $shipment_insert['created_on'] = $lableCreationResponse['eventDate'];
                                    $shipment_insert['order_id'] = $purchaseOrderNumber;

                                    $shipment_insert['tracking_url'] = $this->GetShippingLabelAndUploadInS3($user_integration_id, $ufound->access_token, $purchaseOrderNumber, $trackingUrl, $source_platform_name, $destination_platform_name);
                                    
                                    $shipment_insert['carrier_code'] = $lableCreationResponse['shippingLabelInfo'][0]['carrierCode'];
                                    $shipment_insert['shipping_method'] = $lableCreationResponse['shippingLabelInfo'][0]['carrierCode'];

                                    if($find_source_shipment)
                                        $shipment_insert['linked_id'] = $find_source_shipment->id;

                                    $shipment_insert['shipment_id'] = $purchaseOrderNumber; // putting order id only since no shipment id available
                                    $shipment_insert['tracking_info'] = $lableCreationResponse['shippingLabelInfo'][0]['trackingNumber'];

                                    PlatformOrderShipment::create($shipment_insert);
                                }
                                $nmsg = 'Label Created successfully!';
                                $this->mobj->makeUpdate('platform_order', ['shipment_label_status' => 'Synced'], ['id' => $row->id]); // Make Order synced
                                $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Ready'], ['id' => $parent_order->id]); // Make parent wayfair order ready for tracking
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'success', $row->id, $nmsg);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $return = $e->getMessage();
        }
        return $return;
    }

    

    public function GetShippingLabelAndUploadInS3($user_integration_id, $access_token, $purchaseOrderNumber, $trackingUrl, $source_platform_name, $destination_platform_name){
        
        $fileConetent = $this->wayfair->GetShippingLabel($access_token, $trackingUrl, [], $source_platform_name, $destination_platform_name);
                                    
        $labled_url = null;
        if(strpos($fileConetent, "PDF") !== false) {
            $labelFormat = '.pdf';
            $dynamic_file_name = 'esb/wayfair/'.$user_integration_id.'/labeled_shipment/'.$purchaseOrderNumber.$labelFormat;
        
            Storage::disk('s3')->put($dynamic_file_name, $fileConetent);
            if (Storage::disk('s3')->exists($dynamic_file_name)) {
                                
                $bucket_name = env('AWS_BUCKET');
                $aws_region = env('AWS_DEFAULT_REGION');
                $labled_url = 'https://'.$bucket_name.'.s3.'.$aws_region.'.amazonaws.com/'.$dynamic_file_name;

            }
        }
        return is_null($labled_url) ? $trackingUrl : $labled_url;
    }


    public function test()
    {   
        $user_id = 150;
        $user_integration_id = 71;
        $wfToken = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['access_token', 'env_type']);
        if ($wfToken->env_type == 'production') { // checke account type .
            $url = Config::get('apiconfig.WayfairAudience');
        } else {
            $url = Config::get('apiconfig.WayfairUrlSandbox');
        }
         
        $FromDate = '2023-08-21T03:39:56+0000';
        $limit = '10';
        //check this with true & false both
        $hasResponse = false;
        $source_platform_name = 'wayfair';
        $destination_platform_name = 'brightpearl';

        //get wayfair orders by from and limit
        $result = $this->wayfair->getWFOrders_test($wfToken->access_token, $url, $FromDate, $limit, $hasResponse, $source_platform_name, $destination_platform_name);
        $orders = json_decode($result, true);


        $this->insertUpdateWFOrderDetails($user_id, $this->my_platform_id, $user_integration_id, $orders);

 
    }


}
