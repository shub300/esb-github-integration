<?php

namespace App\Http\Controllers\Zulily;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Api\ZulilyApi;
use App\Helper\Api\BrightpearlApi;
use App\Helper\Logger;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
// use App\Http\Controllers\WorkflowController;
use App\Helper\WorkflowSnippet;

use function GuzzleHttp\json_decode;

use Illuminate\Support\Facades\Session;
use Lang;

class ZulilyApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $wfsnip, $mobj, $bp,  $helper, $platformId, $map, $log;
    public static $my_platform_name = 'zulily';
    public function __construct()
    {
        $this->wfsnip = new WorkflowSnippet();
        $this->bp = new BrightpearlApi;
        $this->mobj = new MainModel();
        $this->zuli = new ZulilyApi();
        $this->log = new Logger();
        $this->map = new FieldMappingHelper();
        $this->helper = new ConnectionHelper();
        $this->platformId = $this->helper->getPlatformIdByName(self::$my_platform_name);
    }

    public function initiateZulilyAuth(Request $request)
    {
        $platform = self::$my_platform_name;
        return view("pages.apiauth.zulily_auth", compact('platform'));
    }


    public function connectZulilyAuth(Request $request)
    {
        //server validation
        $validated = $request->validate([
            'zulily_account_name' => 'required',
            'api_key' => 'required',
            'vendor_id' => 'required'
        ]);

        $account_name = trim($request->zulily_account_name);
        $api_key = trim($request->api_key);
        $vendor_id = trim($request->vendor_id);

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


            $obj_existing = $this->mobj->getFirstResultByConditions('platform_accounts', ['access_key' => $this->mobj->encrypt_decrypt($api_key, 'encrypt'), 'marketplace_id' => $this->mobj->encrypt_decrypt($vendor_id, 'encrypt')], ['user_id']);
            if ($obj_existing) {
                $data['status_code'] = 0;
                $data['status_text'] = 'Given details are already in use, Try with other details.';
                return json_encode($data);
            }

            $existing_skuvault = $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'account_name' => $account_name, 'platform_id' => $this->platformId], ['id']);
            $flag = true;
            if (!$existing_skuvault) {
                //make curl request

                if ($env_type == 'on') { // checke account type .
                    $env_type = 'production';
                } else {
                    $env_type = 'sandbox';
                }


                $endpoint = 'edi.zulily.com:8443/platform';

                // store/update zulily creds
                $zulily_tokens = array(
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'account_name' => $account_name,
                    'access_key' => $this->mobj->encrypt_decrypt($api_key, 'encrypt'),
                    'marketplace_id' => $this->mobj->encrypt_decrypt($vendor_id, 'encrypt'),
                    'api_domain' => $endpoint,
                    'env_type' => $env_type,
                );

                DB::table('platform_accounts')->insert($zulily_tokens);
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


    public function getStorePurchaseOrders($user_id, $user_integration_id)
    {

        try {


            $response = true;
            $platform_id = $this->platformId;

            $get_connect_account_id = $this->map->getUserIntegrationDetailsById($user_integration_id, self::$my_platform_name);
            $get_workflow_rule = $this->mobj->getFirstResultByConditions('user_workflow_rule', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'status' => 1], ['platform_workflow_rule_id', 'sync_start_date']);

            $zulilyToken = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_key', 'marketplace_id', 'api_domain']);

            if ($get_workflow_rule) {

                if ($get_connect_account_id) {

                    //get zulilty purchase orders
                    $POs = $this->zuli->getPurchaseOrder($zulilyToken);
                    $result = json_decode($POs, true);

                    // return;

                    //dummy resposne for testing
                    // $ress = [];
                    // $ress["documents"][0]["documentNumber"] = '00f0465d-5025-40c9-a91e-216d09965be5';
                    // $ress["documents"][0]["documentName"] = 'P0T0100034-8-E';
                    // $ress["documents"][0]["tradingPartnerId"] = '###';
                    // $ress["documents"][0]["documentDate"] = '2021-05-18 17:35:21.108';
                    // $ress["documents"][0]["environment"] = 'test';

                    // $result = $ress;

                    if (isset($result['documents']) && (!empty($result['documents']))) {

                        //get insert update order details
                        foreach ($result['documents'] as $res) {
                            $this->insertUpdatePODetails($user_id, $user_integration_id, $res, $zulilyToken);
                        }
                    } else {
                        //if data is empty
                        //return;
                    }
                }
            } else {
                $response =  'GET Zulily Purchase Order workflow rule not found';
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
        }
        return $response;
    }


    public function insertUpdatePODetails($user_id, $user_integration_id, $ord, $zulilyToken)
    {

        //get po by id
        $singleResult = $this->zuli->getPurchaseOrderByID($zulilyToken, $ord['documentNumber']);
        // dd($singleResult);
        $POresult = json_decode($singleResult, true);
        $getPO =  $POresult['purchaseOrder'];
        // dd($getPO);
        if (!empty($getPO)) {

            $arr_order = array();
            $arr_order['user_id'] = $user_id;
            $arr_order['platform_id'] = $this->platformId;
            $arr_order['platform_customer_id'] = 0;
            $arr_order['user_integration_id'] = $user_integration_id;
            $arr_order['order_type'] = "PO";

            $arr_order['api_order_id'] = @$getPO[0]['poId'];
            $arr_order['order_number'] = @$getPO[0]['poName'];
            $arr_order['api_order_reference'] = @$getPO[0]['documentNumber'];
            $arr_order['order_date'] = date('Y-m-d H:i:s', strtotime($ord['documentDate']));
            $arr_order['order_status'] = @$getPO[0]['status'];
            // $arr_order['sync_status'] = 'Ready';

            $order_details = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_order_id' => @$getPO[0]['poId']], ['id']);

            if ($order_details) {
                $platform_order_id = $order_details->id;
                $this->mobj->makeUpdate('platform_order', $arr_order, ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_order_id' => @$getPO[0]['poId']]);
            } else {
                $arr_order = array_merge($arr_order, array("sync_status" => "Ready"));
                $platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
            }

            $order_total = 0;
            //store order items
            foreach ($getPO[0]['orders'][0]['items'] as $lineitem) {
                $arr_order_line = array();
                $arr_order_line['platform_order_id'] = $platform_order_id;
                $arr_order_line['item_row_sequence'] = @$lineitem['lineNumber'];
                $arr_order_line['api_product_id'] = @$lineitem['productId'];
                $arr_order_line['product_name'] = @$lineitem['description'];
                $arr_order_line['sku'] = @$lineitem['sku'];
                $arr_order_line['upc'] = @$lineitem['upc'];
                $arr_order_line['qty'] = $qty = @$lineitem['quantity'] ? @$lineitem['quantity'] : 0;
                $arr_order_line['price'] = $listprice = @$lineitem['UnitCost'] ? @$lineitem['UnitCost'] : 0;
                $arr_order_line['uom'] = @$lineitem['quantityUom'] ? @$lineitem['quantityUom'] : null;
                $arr_order_line['total'] = $this->helper->getNumberFormat($qty * $listprice, 4);
                $arr_order_line['subtotal'] = $this->helper->getNumberFormat($qty * $listprice, 4);

                $order_total += $this->helper->getNumberFormat($qty * $listprice, 4);

                $ct_order_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'sku' => @$lineitem['sku']]);

                if ($ct_order_line > 0) {
                    $this->mobj->makeUpdate('platform_order_line', $arr_order_line, ['platform_order_id' => $platform_order_id, 'sku' => @$lineitem['sku']]);
                } else {
                    $this->mobj->makeInsert('platform_order_line', $arr_order_line);
                }
            }

            //update total amount into order table
            $ordtotal['total_amount'] =  $this->helper->getNumberFormat($order_total, 4);
            $this->mobj->makeUpdate('platform_order', $ordtotal, ["id" => $platform_order_id]);


            $arr_order_address = array();
            $arr_order_address['platform_order_id'] = $platform_order_id;
            $arr_order_address['address_type'] = 'Shipping';
            $arr_order_address['address_name'] = @$getPO[0]['orders'][0]['shipTo']['name'];
            $arr_order_address['address_id'] = @$getPO[0]['orders'][0]['shipTo']['id'];
            $arr_order_address['address1'] = @$getPO[0]['orders'][0]['shipTo']['address1'];
            $arr_order_address['city'] = @$getPO[0]['orders'][0]['shipTo']['cityName'];
            $arr_order_address['state'] = @$getPO[0]['orders'][0]['shipTo']['stateCode'];
            $arr_order_address['postal_code'] = @$getPO[0]['orders'][0]['shipTo']['zipCode'];
            $arr_order_address['country'] = @$getPO[0]['orders'][0]['shipTo']['countryCode'];

            // dd($arr_order_address);
            $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);

            if ($ct_address > 0) {
                $this->mobj->makeUpdate('platform_order_address', $arr_order_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);
            } else {
                $this->mobj->makeInsert('platform_order_address', $arr_order_address);
            }


            //billing address
            $arr_order_bill_address = array();
            $arr_order_bill_address['platform_order_id'] = $platform_order_id;
            $arr_order_bill_address['address_type'] = 'Billing';
            $arr_order_bill_address['address_name'] = @$getPO[0]['orders'][0]['billTo']['name'];
            $arr_order_bill_address['address_id'] = @$getPO[0]['orders'][0]['billTo']['id'];
            $arr_order_bill_address['address1'] = @$getPO[0]['orders'][0]['billTo']['address1'];
            $arr_order_bill_address['city'] = @$getPO[0]['orders'][0]['billTo']['cityName'];
            $arr_order_bill_address['state'] = @$getPO[0]['orders'][0]['billTo']['stateCode'];
            $arr_order_bill_address['postal_code'] = @$getPO[0]['orders'][0]['billTo']['zipCode'];
            $arr_order_bill_address['country'] = @$getPO[0]['orders'][0]['billTo']['countryCode'];


            $bill_ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);

            if ($bill_ct_address > 0) {
                $this->mobj->makeUpdate('platform_order_address', $arr_order_bill_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);
            } else {
                $this->mobj->makeInsert('platform_order_address', $arr_order_bill_address);
            }
        }
    }

    public function syncInventory($userId = NULL, $userIntegrationId = NULL, $WorkFlowID = NULL, $UserWorkFlow = NULL, $SorucePlatformName = NULL, $sync_status = "Ready")
    {

        try {

            $ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_key', 'marketplace_id', 'api_domain', 'env_type', 'platform_id']);

            if ($ufound && $this->platformId) {

                $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);

                $SourceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id',  'platform_id']);
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId && isset($SourceUfound->platform_id)) {

                    $Inventory_arr = $productsIndex = [];
                    $process_limit = 100;
                    $product_identity_obj_id = $this->helper->getObjectId('product_identity');
                    $maping_data = $this->map->getMappedField($userIntegrationId, $WorkFlowID, $product_identity_obj_id, ['db_field_name']);

                    if (!empty($maping_data)) {

                        $Inventory_arr = DB::table('platform_product as source_platform_product')
                            ->where([
                                ['source_platform_product.inventory_sync_status', '=', $sync_status],
                                ['source_platform_product.user_integration_id', '=', $userIntegrationId],
                                ['source_platform_product.platform_id', '=', $SourceUfound->platform_id]
                            ])
                            ->select('source_platform_product.id', 'source_platform_product.sku', 'source_platform_product.upc', 'source_platform_product.api_product_id')
                            ->orderBy('source_platform_product.updated_at', 'asc')
                            ->limit($process_limit)->get();


                        if (count($Inventory_arr) > 0) {

                            $source_row_data = $destination_row_data = '';
                            if ($maping_data['source_platform_id'] == 'zulily') {
                                $destination_row_data = $maping_data['source_row_data'];
                                $source_row_data = $maping_data['destination_row_data'];
                            }


                            $line_number = 1;
                            $item_arr_parent = [];
                            foreach ($Inventory_arr as $Inventory) {

                                $prod = (array) $Inventory;

                                $product_inventory_arr = $this->mobj->getResultByConditions('platform_product_inventory', ['user_integration_id' => $userIntegrationId, 'api_product_id' => $Inventory->api_product_id], ['id', 'api_warehouse_id', 'quantity']);

                                if (count($product_inventory_arr) > 0) {

                                    $sum = 0;
                                    foreach ($product_inventory_arr as $product_inventory) {
                                        $sum += $product_inventory->quantity;
                                    }

                                    $api_product_id = $Inventory->api_product_id;

                                    $item_arr["lineNumber"] = $line_number++;
                                    // $item_arr["status"] = "New";

                                    $item_arr[$destination_row_data] = $prod[$source_row_data];  //item match by sku/upc
                                    $item_arr[$destination_row_data] = '123456789011';  //item match by sku/upc for testing only

                                    $item_arr["tradinerPartnerId"] = $this->mobj->encrypt_decrypt($ufound->marketplace_id, 'decrypt');
                                    $item_arr["quantityOnhand"] = $sum;
                                    $item_arr["quantityReserved"] = 0;
                                    $item_arr["quantityUom"] = "EA";

                                    $item_arr_parent[] = $item_arr;

                                    if (!empty($item_arr)) {
                                        $postData['productUpdate'] = [[
                                            "tradingPartnerId" => $this->mobj->encrypt_decrypt($ufound->marketplace_id, 'decrypt'),
                                            "productUpdateNumber" => $Inventory->sku,
                                            "productUpdateDate" => date('Ymd'),
                                            "items" => $item_arr_parent
                                        ]];

                                        // echo '<pre>';
                                        // print_r(json_encode($postData,JSON_PRETTY_PRINT));
                                        // exit;

                                        $invUpdate = $this->zuli->invetoryUpdate($ufound, json_encode($postData, true));
                                        $response = json_decode($invUpdate, true);
                                        // print_r($response);
                                        // dd($response);


                                        if (isset($response['status']) && $response['status'] == 'Processed') {
                                            $object_id = $this->helper->getObjectId('inventory');

                                            DB::table('platform_product')->where('user_integration_id', $userIntegrationId)->where('platform_id', $SourcePlatformId)->where('api_product_id', $api_product_id)->update(['inventory_sync_status' => 'Synced']);

                                            DB::table('platform_product_inventory')->where('user_integration_id', $userIntegrationId)->where('platform_id', $SourcePlatformId)->where('api_product_id', $api_product_id)->update(['sync_status' => 'Synced']);

                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $SourcePlatformId, $object_id, 'success', $api_product_id, NULL);

                                            return true;
                                        } else {
                                            //failed
                                            // dd($userId,$userIntegrationId,$SourcePlatformId,$api_product_id);
                                            DB::table('platform_product')->where('user_integration_id', $userIntegrationId)->where('platform_id', $SourcePlatformId)->where('api_product_id', $api_product_id)->update(['inventory_sync_status' => 'Failed']);

                                            DB::table('platform_product_inventory')->where('user_integration_id', $userIntegrationId)->where('platform_id', $SourcePlatformId)->where('api_product_id', $api_product_id)->update(['sync_status' => 'Failed']);

                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow,  $SourcePlatformId, $SourcePlatformId, $object_id, 'failed', $api_product_id, $response['description']);

                                            return $response['description'];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return false;
        }
    }

    public function syncShipment($userId = NULL, $userIntegrationId = NULL, $WorkFlowID = NULL, $UserWorkFlow = NULL, $SorucePlatformName = NULL, $sync_status = "Ready")
    {
        try {
            $return_response = false;
            $limit = 50;
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_key', 'marketplace_id', 'env_type','platform_id','api_domain']);
            if ($ufound && $this->platformId) {

                $object_id = $this->helper->getObjectId('sales_order_shipment');
                $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);
                $SourceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id',  'platform_id']);

                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId && isset($SourceUfound->platform_id)) {

                    $list = DB::table('platform_order_shipments as s')
                        ->select('s.tracking_info', 's.shipment_id', 's.shipping_method', 's.carrier_code', 's.realease_date',  's.tracking_url', 's.shipment_status', 'o.order_number', 'o.id as order_primary_id', 's.id', 'a.vendor', 'a.id as zulily_order_id', 'a.api_order_id as zulily_api_order_id', 'a.order_number as zulily_api_order_no')
                        ->join('platform_order as o', 'o.id', '=', 's.platform_order_id')   //synced bp order join
                        ->join('platform_order as a', 'a.id', '=', 'o.linked_id')  // julily PO join for bill to and ship to ids
                        ->where([['s.user_id', '=', $userId], ['s.platform_id', '=', $SourceUfound->platform_id], ['s.user_integration_id', '=', $userIntegrationId], ['s.sync_status', '=', $sync_status]]) // ['o.sync_status', '=', 'Synced']
                        ->take($limit)->get();

                    if (!empty($list) && count($list) > 0) {

                        foreach ($list as $key => $value) {

                            $shipmentStatus = unserialize($value->shipment_status);
                            $items_posting = $this->makeShipmentLineItems($userId, $userIntegrationId, $WorkFlowID, $value->id, $value->zulily_order_id, $SourcePlatformId);

                            $payload['tradingPartnerId'] = $this->mobj->encrypt_decrypt($ufound->marketplace_id, 'decrypt');
                            $payload['shipmentNumber'] = $value->tracking_info; //random unique number
                            $payload['actualShipDate'] = date("Ymd", strtotime($shipmentStatus['shippedOn']));


                             //get mapped shipping method
                             $scac = $this->map->getMappedDataByName($userIntegrationId, $WorkFlowID, "sorder_shipping_method", ['api_id'], 'regular', $value->shipping_method);

                            if ($scac) {

                                $billing_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $value->zulily_order_id], ['address_name', 'address_id']);
                                $shipFrom = $this->map->getMappedDataByName($userIntegrationId, $WorkFlowID, "ShipFrom", ['custom_data']);

                                $payload['scac'] = $scac->api_id;
                                $payload['shipTo']['name'] = $billing_address->address_name;
                                $payload['shipFrom']['id'] = $billing_address->address_id;

                                $payload['shipFrom']['name'] =  $shipFrom->custom_data;
                                $payload['shipFrom']['id'] = $this->mobj->encrypt_decrypt($ufound->marketplace_id, 'decrypt');

                                $order['poName'] = $value->zulily_api_order_no;
                                $order['poId'] = $value->zulily_api_order_id;
                                $order['package'] = $items_posting['items_posting'];

                                $payload['order'] = [$order];

                                $shipmentNotice['shipmentNotice']= [$payload];

                                $shipment_payload = json_encode($shipmentNotice, JSON_PRETTY_PRINT);

                                // echo '<pre>';
                                // print_r($shipment_payload);
                                // exit;

                                $resp = $this->zuli->createShipment($ufound, $shipment_payload);
                                $response = json_decode($resp,true);
                                // echo '<pre>';
                                // print_r($response);
                                // exit;
                                if (isset($response['status']) && $response['status'] == 'Processed') {

                                    $shipmentLinked = $this->mobj->makeInsertGetId('platform_order_shipments', [
                                        'user_id' => $userId,
                                        'platform_id' => $this->platformId,
                                        'user_integration_id' => $userIntegrationId,
                                        'shipment_id' => $response['documentNumber'],
                                        'sync_status' => 'Synced',
                                        'linked_id' => $value->id,
                                    ]);

                                    $this->mobj->makeUpdate('platform_order_shipments', ['linked_id' => $shipmentLinked, 'sync_status' => 'Synced'], ['id' => $value->id]);
                                    $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Synced'], ['id' => $value->order_primary_id]);

                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $this->platformId, $SourcePlatformId, $object_id, 'success', $value->id, NULL);
                                    $return_response = true;
                                } else {

                                    $error = $response['description'];
                                    $this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $value->id]);
                                    $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $value->order_primary_id]);
                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId,  $object_id, 'failed', $value->id, $error);
                                    $return_response = true;
                                }
                            } else {
                                $error = 'Shipping method not found in source platform';
                                $this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $value->id]);
                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $value->order_primary_id]);
                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId,  $object_id, 'failed', $value->id, $error);
                                $return_response = true;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $e->getMessage();
        }
        return $return_response;
    }


    public function makeShipmentLineItems($userId, $userIntegrationId, $WorkFlowID, $shipmentID, $zulily_order_id, $platformId)
    {

        $items_posting = [];

        $product_identity_obj_id = $this->helper->getObjectId('product_identity');

        $maping_data = $this->map->getMappedField($userIntegrationId, $WorkFlowID, $product_identity_obj_id);

        if ($maping_data) {

            $source_row_data = $destination_row_data = '';
            if ($maping_data['source_platform_id'] == 'zulily') {
                $destination_row_data = $maping_data['source_row_data'];
                $source_row_data = $maping_data['destination_row_data'];
            }

            $products = $this->mobj->getResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id' => $shipmentID], ['product_id', 'quantity', 'sku']);


            if (!empty($products)) {
                foreach ($products as $v) {

                    // $prod=(array) $v;

                    /* Item Mapping Checking */

                    $product_value = 0;

                    $get_product = $this->mobj->getFirstResultByConditions('platform_product', ['user_integration_id' => $userIntegrationId, 'platform_id' => $platformId, 'api_product_id' => $v->product_id], [$source_row_data]);
                    if ($get_product) {
                        $found = (array) $get_product;
                        $product_value = ($found[$source_row_data] != null) ? $found[$source_row_data] : 0; //0 is default case so can be used to item_row_sequence directly
                    }
                    //get zulily PO line items
                    if ($product_value != 0) {
                        $get_po_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $zulily_order_id, $destination_row_data => $product_value], ['uom', 'api_product_id', 'upc']);
                    } else {
                        //if not found in product table then get details from order line using sku
                        $get_po_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $zulily_order_id, 'sku' => $v->sku], ['uom', 'api_product_id', 'upc']);
                    }

                    // $vendorProductidentifier = $get_po_line->sku;
                    $uom = $get_po_line->uom;

                    $line['quantity'] = $v->quantity;
                    $line['quantityUom'] = $uom;
                    // $line['item']['sku'] = $v->sku;
                    $line['upc'] = $get_po_line->upc; //required
                    $line['productId'] = $get_po_line->api_product_id; //required

                    $item['item'] = [$line];
                    $items_posting[] = $item;
                }
            }
        }

        return ['items_posting' => $items_posting];
    }


    public function ExecuteEventZulily($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        try {
            $response = true;
            ////////GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.

            if ($method == 'GET' && $event == 'PURCHASEORDER') {
                $response =  $this->getStorePurchaseOrders($user_id, $user_integration_id);
            } else if ($method == 'MUTATE' && $event == 'INVENTORY') {
                $sync_status = 'Ready';
                $response =  $this->syncInventory($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $sync_status);
            } else if ($method == 'MUTATE' && $event == 'SHIPMENT') {
                $sync_status = 'Ready';
                $response =  $this->syncShipment($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $sync_status);
            }

            return $response;
        } catch (\Exception $e) {
            \Log::error('Zulily Execute Event Userid >> '.$user_id.' Userintegration >> '.$user_integration_id.' Error >>'.$e->getMessage());
            return $e->getMessage();
        }
    }


    public function testGetPurchaseOrder()
    { }
}