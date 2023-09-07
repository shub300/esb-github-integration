<?php

namespace App\Http\Controllers\Sitoo;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Auth;
use DB;
use App\Helper\MainModel;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\ConnectionHelper;
use App\Helper\Api\SitooApi;
use Illuminate\Support\Facades\Session;
use Lang;
class SitooApiController extends Controller
{
    public static $my_platform_name = 'sitoo';

    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->SitooApi = new SitooApi();
        $this->map = new FieldMappingHelper();
        $this->log = new Logger();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$my_platform_name);
    }

    public function InitiateSitooAuth(Request $request)
    {
        $platform = self::$my_platform_name;
        return view("pages.apiauth.auth_sitoo", compact('platform'));
    }

    public function checkExistingConnectedAc($platform_id, $api_domain, $api_id, $api_password){
        $enc_api_id = $this->mobj->encrypt_decrypt($api_id);
        $enc_api_password = $this->mobj->encrypt_decrypt($api_password);
        $obj_existing = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $platform_id, 'api_domain' => $api_domain, 'app_id' => $enc_api_id,  'app_secret' => $enc_api_password], ['user_id']);
        if ($obj_existing) {
            return true;
        }
        else{
            return false;
        }
    }

    public function getSitooSites(Request $request)
    {
        $sitoo_base_url = trim($request->sitoo_base_url);
        $sitoo_api_id = trim($request->sitoo_api_id);
        $sitoo_pwd = trim($request->sitoo_password);

        $data = [];
        try {
            $post_data = [];
            $header = [
                "Content-Type: application/json",
                "Accept: application/json",
                "Authorization: Basic " . base64_encode($sitoo_api_id . ":" . $sitoo_pwd)
            ];

            $result = $this->mobj->makeCurlRequest('GET', rtrim($sitoo_base_url, '/') . '/sites.json', $post_data, $header);
            $response = json_decode($result, true);
            if($response && isset($response['totalcount']) && $response['totalcount'] > 0){
                $return_response = $response['items'];
                $data['status_code'] = 1;
                $data['status_text'] = $return_response;
            }
            else{
                $data['status_code'] = 0;
                if($response && isset($response['statuscode'])){
                    $data['status_text'] = $res['errortext'];
                }
                else if($response){
                    $data['status_text'] = $response;
                }
                else{
                    $data['status_text'] = 'Invalid account details. Please check and try again.';
                }
            }
            return json_encode($data);
        }
        catch (\Exception $e) {
            $data['status_code'] = 0;
            $data['status_text'] = $e->getMessage();
            return json_encode($data);
        }
    }

    public function ConnectSitoo(Request $request)
    {
        $sitoo_base_url = trim($request->sitoo_base_url);
        $sitoo_api_id = trim($request->sitoo_api_id);
        $sitoo_pwd = trim($request->sitoo_password);
        $sitoo_site = trim($request->sitoo_site);

        $user_data =  Session::get('user_data');
        $user_id =  $user_data['id'];

        $flag = true;
        $data = [];

        if($this->mobj->checkHtmlTags( $request->all() ) ){
            $data['status_code'] = 0;
            $data['status_text'] = Lang::get('tags.validate');
            return json_encode($data);
        }
        
        try {
            $check_existing_ac = $this->checkExistingConnectedAc($this->platformId, $sitoo_base_url, $sitoo_api_id, $sitoo_pwd);
            if($check_existing_ac){
                $data['status_code'] = 0;
                $data['status_text'] = 'Given details are already in use, Try with other details.';
                return json_encode($data);
            }

            $post_data = [];
            $header = [
                "Content-Type: application/json",
                "Accept: application/json",
                "Authorization: Basic " . base64_encode($sitoo_api_id . ":" . $sitoo_pwd)
            ];

            $response = $this->mobj->makeCurlRequest('GET', rtrim($sitoo_base_url, '/') . '/sites/'.$sitoo_site.'/products.json?start=1&num=1&fields=productid', $post_data, $header);
            $result = json_decode($response, true);

            if(isset($result['totalcount'])){
                $acfound = $this->mobj->getResultByConditions('platform_accounts', ['platform_id' => $this->platformId], ['account_name']);
                $increment = $acfound->count() > 0 ? '_' . $acfound->count() : NULL;
                $arr_field = [
                    'account_name' => 'Sitoo' . $increment,
                    'app_id' => $this->mobj->encrypt_decrypt($sitoo_api_id),
                    'app_secret' => $this->mobj->encrypt_decrypt($sitoo_pwd),
                    'api_domain' => $sitoo_base_url,
                    'installation_instance_id' => $sitoo_site,
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

    public function ExecuteEventSitoo($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        try {
            $response = true;
            if ($method == 'GET' && $event == 'WAREHOUSE') {
                $response = $this->storeSitooWarehouse($user_id, $user_integration_id);
            }
            else if ($method == 'MUTATE' && $event == 'INVENTORY') {
                $response = $this->updateSitooInventory($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
            }
            else if ($method == 'GET' && $event == 'PRODUCT') {
                $response = $this->storeSitooProducts($user_id, $user_integration_id);
            }

            return $response;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function storeSitooWarehouse($user_id, $user_integration_id)
    {
        try {
            $response = true;

			$platform_id =  $this->platformId;

            // Get Sitoo account details
            $sitoo_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platform_id, ['app_id', 'app_secret', 'api_domain', 'installation_instance_id']);

            $base_url = $sitoo_account->api_domain;
            $api_id = $this->mobj->encrypt_decrypt($sitoo_account->app_id, 'decrypt');
            $password = $this->mobj->encrypt_decrypt($sitoo_account->app_secret, 'decrypt');
            $site_id = $sitoo_account->installation_instance_id;
            $endpoint = '/sites/'.$site_id.'/warehouses.json';
            $post_data = [];
            $result = $this->SitooApi->makeSitooRequest('GET', $base_url, $api_id, $password, $endpoint, $post_data);
            $warehouses = json_decode($result, true);
            if($warehouses && isset($warehouses['totalcount']) && $warehouses['totalcount'] > 0){
                // Insert/Update Warehouse details
                $object_id = $this->ConnectionHelper->getObjectId('warehouse');
                foreach ($warehouses['items'] as $wh) {
                    $arr_warehouse = array();
                    $arr_warehouse['user_id'] = $user_id;
                    $arr_warehouse['user_integration_id'] = $user_integration_id;
                    $arr_warehouse['platform_id'] = $platform_id;
                    $arr_warehouse['platform_object_id'] = $object_id;
                    $arr_warehouse['api_id'] = $wh['warehouseid'];
                    $arr_warehouse['name'] = $wh['name'];

                    $ord_warehouse = $this->mobj->getFirstResultByConditions('platform_object_data',
                                    [
                                        'platform_id' => $platform_id,
                                        'user_integration_id' => $user_integration_id,
                                        'platform_object_id' => $object_id,
                                        'api_id' => $wh['warehouseid']
                                    ], ['id']);

                    if ($ord_warehouse) {
                        $order_warehouse_id = $ord_warehouse->id;
                        $this->mobj->makeUpdate('platform_object_data', $arr_warehouse, ['id' => $order_warehouse_id]);
                    }
                    else {
                        $order_warehouse_id = $this->mobj->makeInsertGetId('platform_object_data', $arr_warehouse);
                    }
                }
            }
            else {
                $response = $result;
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
        }
        return $response;
    }

    public function storeSitooInventory($user_id, $platform_name, $user_integration_id)
    {
        try {
            $return_response = true;
			$platform_id = $this->ConnectionHelper->getPlatformIdByName($platform_name);
            // Get Sitoo account details
            $sitoo_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platform_id, ['app_id', 'app_secret', 'api_domain', 'installation_instance_id']);

            $base_url = $sitoo_account->api_domain;
            $api_id = $this->mobj->encrypt_decrypt($sitoo_account->app_id, 'decrypt');
            $password = $this->mobj->encrypt_decrypt($sitoo_account->app_secret, 'decrypt');
            $site_id = $sitoo_account->installation_instance_id;
            $post_data = [];

            $object_id = $this->ConnectionHelper->getObjectId('warehouse');
            $obj_warehouses = $this->mobj->getResultByConditions('platform_object_data', [
                'user_integration_id' => $user_integration_id,
                'platform_id' => $platform_id,
                'platform_object_id' => $object_id,
            ], ['api_id']);

            foreach ($obj_warehouses as $wh) {
                $page = 1;
                $page_limit = 100;
                $flag = true;
                do {
                    $warehouse_id = $wh->api_id;
                    $endpoint = '/sites/'.$site_id.'/warehouses/'.$warehouse_id.'/warehouseitems.json';
                    $response = $this->SitooApi->makeSitooRequest('GET', $base_url, $api_id, $password, $endpoint, $post_data);
                    $inventory = json_decode($response, true);
                    if($inventory && isset($inventory['totalcount']) && $inventory['totalcount'] > 0){
                        $items = $inventory['items'];
                        if (!empty($items) || count($items) ==  $page_limit) {
                            foreach ($items as $key => $value) {
                                $arr_inventory = array();
                                $arr_inventory['user_id'] = $user_id;
                                $arr_inventory['user_integration_id'] = $user_integration_id;
                                $arr_inventory['platform_id'] = $platform_id;
                                //$arr_inventory['api_product_id'] = $api_product_id;
                                $arr_inventory['api_warehouse_id'] = $warehouse_id;
                                $arr_inventory['quantity'] = $value['decimalavailable'];
                                $arr_inventory['sku'] = $value['sku'];

                                $existing_inv = $this->mobj->getFirstResultByConditions('platform_product_inventory',
                                                [
                                                    'user_integration_id' => $user_integration_id,
                                                    'platform_id' => $platform_id,
                                                    'api_warehouse_id' => $warehouse_id,
                                                    'sku' => $value['sku']
                                                ], ['id']);

                                if ($existing_inv) {
                                    $inventory_id = $existing_inv->id;
                                    $this->mobj->makeUpdate('platform_product_inventory', $arr_inventory, ['id' => $inventory_id]);
                                } else {
                                    $inventory_id = $this->mobj->makeInsertGetId('platform_product_inventory', $arr_inventory);
                                }
                            }
                            if (count($items) ==  $page_limit) {
                                $page++;
                            } else if (count($items) <  $page_limit) {
                                $flag = false;
                            }
                        }
                        if ($page % 2 == 0) {
                            sleep(1);
                        }
                    }
                    else {
                        $flag = false;
                    }
                } while ($flag);
                $return_response = true;
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    public function storeSitooProducts($user_id = NULL, $user_integration_id = NULL)
    {
        $return_response = false;
        try {
            // Get Sitoo account details
            $sitoo_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['platform_id', 'app_id', 'app_secret', 'api_domain', 'installation_instance_id']);
            if ($sitoo_account && isset($sitoo_account->platform_id) && $sitoo_account->platform_id == $this->platformId) {
                $base_url = $sitoo_account->api_domain;
                $api_id = $this->mobj->encrypt_decrypt($sitoo_account->app_id, 'decrypt');
                $password = $this->mobj->encrypt_decrypt($sitoo_account->app_secret, 'decrypt');
                $site_id = $sitoo_account->installation_instance_id;

                $post_data = [];
                $page = 1;
                $page_limit = 100;
                $flag = true;
                do {
                    $endpoint = '/sites/'.$site_id.'/products.json?num='.$page_limit;
                    $response = $this->SitooApi->makeSitooRequest('GET', $base_url, $api_id, $password, $endpoint, $post_data);
                    $products = json_decode($response, true);
                    if($products && isset($products['totalcount']) && $products['totalcount'] > 0){
                        $items = $products['items'];
                        if (!empty($items) || count($items) ==  $page_limit) {
                            foreach ($items as $key => $value) {
                                $product_list = array(
                                    'user_id' => $user_id,
                                    'user_integration_id' => $user_integration_id,
                                    'platform_id' => $sitoo_account->platform_id,
                                    'inventory_sync_status' => 'Ready',
                                    'api_product_id' => $value['productid'],
                                    'sku' => $value['sku'],
                                    'product_name' =>  $value['title'],
                                    'price' => $value['moneyfinalprice'],
                                );

                                $is_existing = $this->mobj->getFirstResultByConditions('platform_product', [
                                    'user_integration_id' => $user_integration_id,
                                    'platform_id' => $sitoo_account->platform_id,
                                    'api_product_id' => $value['productid'],
                                ], ['id']);
                                if ($is_existing) {
                                    $this->mobj->makeUpdate(
                                        'platform_product',
                                        $product_list,
                                        ['id' => $is_existing->id]
                                    );
                                } else {
                                    $this->mobj->makeInsertGetId('platform_product', $product_list);
                                }
                            }
                            if (count($items) ==  $page_limit) {
                                $page++;
                            } else if (count($items) <  $page_limit) {
                                $flag = false;
                            }
                        }
                        if ($page % 2 == 0) {
                            sleep(1);
                        }
                    }
                    else {
                        $flag = false;
                    }
                } while ($flag);
                $return_response = true;
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response ;
    }

    public function updateSitooInventory($user_id = '', $user_integration_id = '', $source_platform_name = '', $platform_workflow_rule_id = '', $user_workflow_rule_id = '', $record_id = '')
    {
        $process_limit = 100;
        $return = true;
        $inventory_arr = '';
        $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
        $object_id = $this->ConnectionHelper->getObjectId('inventory');
        $product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');

        $sitoo_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['app_id', 'app_secret', 'api_domain', 'installation_instance_id', 'platform_id']);

        $base_url = $sitoo_account->api_domain;
        $api_id = $this->mobj->encrypt_decrypt($sitoo_account->app_id, 'decrypt');
        $password = $this->mobj->encrypt_decrypt($sitoo_account->app_secret, 'decrypt');
        $site_id = $sitoo_account->installation_instance_id;
        $endpoint = '/sites/'.$site_id.'/warehousetransactions.json';

        if ($sitoo_account) {
            do {
                $allow_next_call = false;

                $maping_data = $this->map->getMappedField($user_integration_id, $platform_workflow_rule_id, $product_identity_obj_id);
                if ($maping_data) {
                    $destination_row_data = $maping_data['destination_row_data'];
                    $source_row_data = $maping_data['source_row_data'];

                    if ($record_id) {
                        $inventory_arr = DB::table('platform_product as source_platform_product')
                        ->join('platform_product as destination_platform_product', 'destination_platform_product.' . $destination_row_data, '=', 'source_platform_product.' . $source_row_data)
                        ->where(['source_platform_product.user_integration_id' => $user_integration_id])
                        ->where(['source_platform_product.platform_id' => $source_platform_id, 'destination_platform_product.platform_id' => $this->platformId])
                        ->where('source_platform_product.id', $record_id)
                        ->select(
                            'source_platform_product.id',
                            'destination_platform_product.sku as sku',
                            'destination_platform_product.api_product_id as sitoo_api_product_id',
                            'source_platform_product.api_product_id as bp_api_product_id'
                        )->limit($process_limit)->orderBy('source_platform_product.id', 'DESC')->get();
                    }
                    else {
                        $inventory_arr = DB::table('platform_product as source_platform_product')
                        ->join('platform_product as destination_platform_product', 'destination_platform_product.' . $destination_row_data, '=', 'source_platform_product.' . $source_row_data)
                        ->where(['source_platform_product.inventory_sync_status' => 'Ready', 'source_platform_product.user_integration_id' => $user_integration_id])
                        ->where(['source_platform_product.platform_id' => $source_platform_id, 'destination_platform_product.platform_id' => $this->platformId])
                        ->select(
                            'source_platform_product.id',
                            'destination_platform_product.sku as sku',
                            'destination_platform_product.api_product_id as sitoo_api_product_id',
                            'source_platform_product.api_product_id as bp_api_product_id'
                        )->limit($process_limit)->orderBy('source_platform_product.id', 'DESC')->get();

                        if (!count($inventory_arr)) { //if Ready not exist then pick Failed inventory.
                            $inventory_arr = DB::table('platform_product as source_platform_product')->join('platform_product as destination_platform_product', 'destination_platform_product.' . $destination_row_data, '=', 'source_platform_product.' . $source_row_data)
                            ->where(['source_platform_product.inventory_sync_status' => 'Failed', 'source_platform_product.user_integration_id' => $user_integration_id])
                            ->where(['source_platform_product.platform_id' => $source_platform_id, 'destination_platform_product.platform_id' => $this->platformId])
                            ->select(
                                'source_platform_product.id',
                                'destination_platform_product.sku as sku',
                                'destination_platform_product.api_product_id as sitoo_api_product_id',
                                'source_platform_product.api_product_id as bp_api_product_id'
                            )->limit($process_limit)->orderBy('source_platform_product.id', 'DESC')->get();
                        }
                    }
                }

                if ($inventory_arr && count($inventory_arr) == $process_limit) { // Don't want to loop contineously
                    $allow_next_call = false;
                }

                if ($inventory_arr && count($inventory_arr)) {
                    $arr_sku = [];
                    foreach ($inventory_arr as $inv) {
                        $arr_sku[] = $inv->sku;
                    }
                    $sku_set_wh_items = implode (",", $arr_sku);

                    $wh_object_id = $this->ConnectionHelper->getObjectId('inventory_warehouse');
                    $bp_warehouses = DB::table('platform_data_mapping AS pfDataMap')
                    ->join('platform_object_data AS scPfObjData', 'scPfObjData.id', '=', 'pfDataMap.source_row_id')
                    ->join('platform_object_data AS dcPfObjData', 'dcPfObjData.id', '=', 'pfDataMap.destination_row_id')
                    ->select('scPfObjData.api_id as sc_wh_id', 'dcPfObjData.api_id as dc_wh_id')
                    ->where(['pfDataMap.user_integration_id'=>$user_integration_id, 'pfDataMap.platform_workflow_rule_id'=>$platform_workflow_rule_id, 'pfDataMap.platform_object_id'=>$wh_object_id, 'pfDataMap.status' => 1])
                    ->get();

                    $arr_wh_map = [];
                    foreach ($bp_warehouses as $key => $value) {
                        $temp_arr_wh = [];
                        $temp_arr_wh['sc_wh_id'] = $value->sc_wh_id;
                        $temp_arr_wh['dc_wh_id'] = $value->dc_wh_id;
                        $get_wh_endpoint = '/sites/'.$site_id.'/warehouses/'.$value->dc_wh_id.'/warehouseitems.json?num='.$process_limit.'&fields=sku,decimaltotal,moneytotal&sku='.$sku_set_wh_items;
                        $result_wh = $this->SitooApi->makeSitooRequest('GET', $base_url, $api_id, $password, $get_wh_endpoint, []);
                        $warehouse_items = json_decode($result_wh, true);
                        $temp_wh_arr = [];
                        if($warehouse_items && isset($warehouse_items['totalcount']) && $warehouse_items['totalcount'] > 0){
                            $temp_wh_arr['warehouse_id'] = $value->dc_wh_id;
                            $temp_wh_arr['items'] = $warehouse_items['items'];
                        }
                        // creating custom array to store Sitoo inventory data
                        if($temp_wh_arr)
                            $arr_warehouse_items[] =  $temp_wh_arr;

                        // creating custom array to store mapped warehouse id
                        if($temp_arr_wh)
                            $arr_wh_map[] =  $temp_arr_wh;
                    }

                    //echo "<pre>"; print_r($arr_warehouse_items);
                    //die;
                    foreach ($inventory_arr as $Inventory) {
                        $pricelist_map = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, 'inventory_pricelist', ['api_id'], 'default', '', 'single');
						$prod_price_list = DB::table('platform_porduct_price_list')->select('price')
                        ->where(['id' => $pricelist_map->api_id])
                        ->first();

                        $product_inventory_arr = DB::table('platform_product_inventory AS pfProdInv')
                        ->join('platform_product AS pfProd', 'pfProd.api_product_id', '=', 'pfProdInv.api_product_id')
                        ->select('pfProdInv.id', 'pfProdInv.api_product_id', 'pfProdInv.api_warehouse_id', 'pfProdInv.quantity', 'pfProd.sku')
                        ->where(['pfProdInv.user_id' => $user_id, 'pfProdInv.user_integration_id' => $user_integration_id, 'pfProdInv.api_product_id' => $Inventory->bp_api_product_id, 'pfProd.platform_id' => $source_platform_id])
                        ->get();
                        if (!count($product_inventory_arr)) {
                            $product_inventory_arr = DB::table('platform_product_inventory AS pfProdInv')
                            ->join('platform_product AS pfProd', 'pfProd.api_product_id', '=', 'pfProdInv.api_product_id')
                            ->select('pfProdInv.id', 'pfProdInv.api_product_id', 'pfProdInv.api_warehouse_id', 'pfProdInv.quantity', 'pfProd.sku')
                            ->where(['pfProdInv.user_id' => $user_id, 'pfProdInv.user_integration_id' => $user_integration_id, 'pfProd.sku' => $Inventory->sku, 'pfProd.platform_id' => $source_platform_id])
                            ->get();
                        }
                        if (count($product_inventory_arr)) {
                            $arr_post = [];
                            foreach ($product_inventory_arr as $product_inventory) {
                                $update_inventory_data = [];

                                $wh_map_key = array_search($product_inventory->api_warehouse_id, array_column($arr_wh_map, 'sc_wh_id'));
                                if(is_numeric($wh_map_key)){
                                    $sitoo_wh_id = $arr_wh_map[$wh_map_key]['dc_wh_id'];
                                    $wh_pkt_id = array_search($sitoo_wh_id, array_column($arr_warehouse_items, 'warehouse_id'));
                                    if(is_numeric($wh_pkt_id)){
                                        $item_arr = $arr_warehouse_items[$wh_pkt_id]['items'];
                                        $sku_pkt_id = array_search($product_inventory->sku, array_column($item_arr, 'sku'));

										$qty = null;
                                        if(is_numeric($sku_pkt_id)){
                                            $bp_qty = $product_inventory->quantity;
                                            $sitoo_qty = $item_arr[$sku_pkt_id]['decimaltotal'];
                                            $qty = (int)$bp_qty - (int)$sitoo_qty;
                                            if($qty > 0)
                                            {
                                                $transactiontype = 10;
                                                $item = [
                                                    "sku" => $product_inventory->sku, // The SKU for this stock item.
                                                    "decimalquantity" => number_format($qty, 3, '.', ''), // The change of stock in this transaction.
                                                    "moneypricein" => number_format($prod_price_list->price, 2, '.', ''), // The purchase price per item for this transaction
                                                ];
                                            }
                                            else if($qty < 0)
                                            {
                                                $transactiontype = 20;
                                                $item = [
                                                    "sku" => $product_inventory->sku, // The SKU for this stock item.
                                                    "decimalquantity" => number_format($qty, 3, '.', '') // The change of stock in this transaction.
                                                ];
                                            }
                                        }

                                        $already_upToDate = false;
                                        if($qty > 0 || $qty < 0){
                                            $already_upToDate = false;
                                            $key = array_search($sitoo_wh_id, array_column($arr_post, 'warehouseid'));
                                            if(is_numeric($key)){
                                                $arr_post[$key]['items'][] = $item;
                                            } else {
                                                $update_inventory_data['warehouseid'] = (int)$sitoo_wh_id;
                                                $update_inventory_data['transactiontype'] = $transactiontype;
                                                $update_inventory_data['items'][] = $item;
                                            }
                                            if($update_inventory_data)
                                                $arr_post[] =  $update_inventory_data;
                                        }
                                        else{
                                            $already_upToDate = true;
                                        }
                                    }
                                }
                            }

                            if($already_upToDate){
                                $msg = 'Inventory synced successfully!';
                                $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Synced'], ['id' => $Inventory->id]);
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $Inventory->id, $msg);
                            }
                            else if (count($arr_post)) {
                                $post_data = json_encode($arr_post, true);
                                $result = $this->SitooApi->makeSitooRequest('POST', $base_url, $api_id, $password, $endpoint, $post_data);
                                $response = json_decode($result, true);
                                foreach ($response as $res) {
                                    if($res && isset($res['statuscode']) && $res['statuscode'] == 200){
                                        $msg = 'Inventory synced successfully!';
                                        $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Synced'], ['id' => $Inventory->id]);
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $Inventory->id, $msg);
                                    }
                                    else {
                                        if($res && isset($res['statuscode'])){
                                            $return = $res; //['errortext'];
                                        }
                                        else{
                                            $return = $res;
                                        }
                                        $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Failed'], ['id' => $Inventory->id]);
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $Inventory->id, $return);
                                    }
                                }
                            }
                            else {
                                $return = 'Inventory Warehouse data not Found!';
                                $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Failed'], ['id' => $Inventory->id]);
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $Inventory->id, $return);
                            }
                        } else {
                            $return = 'Inventory information not Found!';
                            $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Failed'], ['id' => $Inventory->id]);
                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $Inventory->id, $return);
                        }
                    }
                }
            } while ($allow_next_call);
        }
        return $return;
    }

    public function test(){
        //$this->storeSitooWarehouse(143, 'sitoo', 60);
        //$this->storeSitooInventory(143, 9, 58);
        //$this->sitooUpdateInventory(143, 9, 58);
        //$this->updateSitooInventory(143, 68, 'brightpearl', 17, 103);
        //$this->storeSitooProducts(143, 58);
    }
}