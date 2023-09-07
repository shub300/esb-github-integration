<?php

namespace App\Http\Controllers\Shipstation;

use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\MainModel;
use App\Models\PlatformAccount;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformProduct;
use App\Models\PlatformUrl;
use App\Models\PlatformWebhookInformation;
use App\Models\PlatformReceiveWebhook;
use App\Models\PlatformObjectDataAdditionalInformation;
use App\Models\PlatformStates;
use App\Models\PlatformCountry;
use App\Models\PlatformField;
use App\Models\PlatformDataMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Lang;
use DB;
use App\Helper\WorkflowSnippet;
use App\Models\UserIntegration;


use App\Helper\Api\ShipstationApi;

class ShipstationController
{
    const PLATFORM = 'shipstation';
    public $ConnectionHelper, $platformId, $mapping, $mobj, $log;

    public function __construct()
    {
        $this->ConnectionHelper = new ConnectionHelper();
        $this->mobj = new MainModel();
        $this->log = new Logger();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::PLATFORM);
        $this->mapping = new FieldMappingHelper();
        $this->shipstation = new ShipstationApi();
        $this->wfsnip = new WorkflowSnippet();
    }

    // Auth::Initiate
    public function InitiateShipstationAuth(Request $request)
    {
        $platform = self::PLATFORM;
        return view("pages.apiauth.auth_shipstation", compact('platform'));
    }

    //connect shipstation
    public function ConnectShipstationAuth(Request $request){

        $data = [];
    
        $validator = Validator::make($request->all(), [
            'account_name' => 'required|unique:platform_accounts,account_name,NULL,id,platform_id,' . $this->platformId . '|max:100',
            'api_key' => 'required',
			'secret_key' => 'required',
        ], [
            'account_name.required' => 'Account Name is required.',
            'account_name.unique' => 'Account Name is already taken.',
            'account_name.max' => 'Account Name should be of maximum 100 characters.',
            'api_key.required' => 'Api Key is required.',
			'secret_key.required' => 'Secret Key is required.',
        ]);

        if($this->mobj->checkHtmlTags( $request->all() ) ){
            $data['status_code'] = 0;
            $data['status_text'] = Lang::get('tags.validate');
            return json_encode($data);
        }
        
        if ($validator->fails()) {
            $data['status_code'] = 0;
            $data['status_text'] = array_values(json_decode($validator->messages()->toJson(), true))[0][0];
            return $data;
        }

        $validated = array_map(function ($val) {
            return htmlspecialchars($val);
        }, $validator->validated());

        $validated = (object) $validated;
        // extract the username
        $accountname = $validated->account_name;
		
        //current user
        $user_data = Session::get('user_data');
        $user_id = $user_data['id'];
		
        //check for user account
        $checkAccount = PlatformAccount::select('id')->where(['user_id' => $user_id, 'platform_id' => $this->platformId, 'account_name' => $accountname])->first();
        if ($checkAccount) {
            $data['status_code'] = 0;
            $data['status_text'] = 'Account already in use.';
        } else {
			//Test Api call for validate
			$validateCredentials = $this->validateCredentials($validated->api_key, $validated->secret_key);

            if ( $validateCredentials && isset($validateCredentials['webhooks']) ) {

                 // encrypt the data
                $api_key = $this->mobj->encrypt_decrypt($validated->api_key);
                $secret_key = $this->mobj->encrypt_decrypt($validated->secret_key);

                // add new account
                $newaccount = PlatformAccount::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'account_name' => $accountname, 'app_id'=> $api_key,'app_secret' => $secret_key, 'allow_refresh' => 0]);
                if (isset($newaccount->id)) {
                    $data['status_code'] = 1;
                    $data['status_text'] = 'Account Added Successfully.';
                }
            } else {
                $data['status_code'] = 0;
                $data['status_text'] = 'Credential provided are wrong. Please check!';
            }
        }
        return json_encode($data);
    }

    //Validate Credentials before connect
    public function validateCredentials($api_key, $secret_key) {
        $testApiCall = $this->shipstation->ApiCall('GET','/webhooks',$api_key, $secret_key,NULL);
        return $testApiCall;
    }

    //get shipstation warehouse
    public function createUpdateWarehouseFromShipstation($is_initial_sync, $user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id)
    {
        $returnstatus = true;
        try {
            // get the account sub domain
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {

                $api_key = $this->mobj->encrypt_decrypt($account->app_id,'decrypt');
                $secret_key = $this->mobj->encrypt_decrypt($account->app_secret,'decrypt');

                $object_id = $this->ConnectionHelper->getObjectId('warehouse');
                
                if ($object_id) {
                    
                    //get warehouse
                    $warehouses = $this->shipstation->ApiCall('GET','/warehouses',$api_key, $secret_key);

                    if ($warehouses) {

                        //disable old warehouse..
                        $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $object_id]);

                        foreach ($warehouses as $warehouse) {
                            $warehouseData = $wareData = [];
                            //prepare warehouse data for insert update
                            $wareData = $this->createWarehouseArrayForDatabase($warehouse);

                            //fixed data for all warehouse
                            $warehouseData = [
                                'user_id' => $user_id,
                                'platform_object_id' => $object_id,
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $user_integration_id,
                            ];

                            //find already exist warehouse
                            $checkWarehouseInDatabase = PlatformObjectData::where($warehouseData)->where([
                                'api_id' => $wareData['api_id'],
                            ])->first();
                            
                            if ($checkWarehouseInDatabase) {
                                //update dynamic warehouse data only
                                PlatformObjectData::where('id',$checkWarehouseInDatabase->id)->update($wareData);
                            } else {
                                //insert warehouse data + fixed data
                                $warehouseData = $warehouseData + $wareData;
                                PlatformObjectData::create($warehouseData);
                            }
                        }

                    } else {
                        $returnstatus = "Warehouse not found.";
                    }
                } else {
                    $returnstatus = "No object found.";
                }
            } else {
                $returnstatus = "No credential found.";
            }
        }catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> createUpdateWarehouseFromShipstation -> " . $e->getMessage());
            return $e->getMessage();
        }

        return $returnstatus;
    }

    public function createWarehouseArrayForDatabase($warehouse)
    {
        $warehouseData = [
            'api_id' => $warehouse['warehouseId'],
            'api_code' => $warehouse['warehouseId'],
            'name' => $warehouse['warehouseName'],
            'status' => 1,
        ];
        return $warehouseData;
    }


    //get shipstation store
    public function createUpdateStoreFromShipstation($is_initial_sync, $user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id)
    {   
        $returnstatus = true;
        try {
            // get the account sub domain
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {

                $api_key = $this->mobj->encrypt_decrypt($account->app_id,'decrypt');
                $secret_key = $this->mobj->encrypt_decrypt($account->app_secret,'decrypt');

                $object_id = $this->ConnectionHelper->getObjectId('store');

                if ($object_id) {
                    
                    //get warehouse
                    $list_store = $this->shipstation->ApiCall('GET','/stores',$api_key, $secret_key);
        
                    if ($list_store) {

                        //disable old warehouse..
                        $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $object_id]);


                        foreach ($list_store as $store_row) {
                            $storeFixedData = $storeData = [];
                            //prepare warehouse data for insert update
                            $storeData = $this->createStoreArrayForDatabase($store_row);

                            //fixed data for all warehouse
                            $storeFixedData = [
                                'user_id' => $user_id,
                                'platform_object_id' => $object_id,
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $user_integration_id,
                            ];

                            //find already exist warehouse
                            $checkStoreInDatabase = PlatformObjectData::where($storeFixedData)->where([
                                'api_id' => $storeData['api_id'],
                            ])->first();
                            
                            if ($checkStoreInDatabase) {
                                //update dynamic warehouse data only
                                PlatformObjectData::where('id',$checkStoreInDatabase->id)->update($storeData);
                            } else {
                                //insert warehouse data + fixed data
                                $warehouse_row_array = $storeFixedData + $storeData;
                                PlatformObjectData::create($warehouse_row_array);
                            }
                        }

                    } else {
                        $returnstatus = "No data of store found.";
                    }

                } else {
                    $returnstatus = "No object found.";
                }
            } else {
                $returnstatus = "No credential found.";
            }

        }catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> createUpdateStoreFromShipstation -> " . $e->getMessage());
            return $e->getMessage();
        }

        return $returnstatus;

    }
    public function createStoreArrayForDatabase($store)
    {
        $storeData = [
            'api_id' => $store['storeId'],
            'api_code' => $store['storeId'],
            'name' => $store['storeName'],
            'status' => ($store['active']==true) ? 1 : 0,
        ];
        return $storeData;
    }


    //get shipstation carriers
    public function createUpdateCarriersFromShipstation($user_id, $user_integration_id)
    {
        $returnstatus = true;
        try {
            // get the account sub domain
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {

                $api_key = $this->mobj->encrypt_decrypt($account->app_id,'decrypt');
                $secret_key = $this->mobj->encrypt_decrypt($account->app_secret,'decrypt');

                $object_id = $this->ConnectionHelper->getObjectId('shipping_method');
                
                if ($object_id) {
                    
                    //get warehouse
                    $carriears = $this->shipstation->ApiCall('GET','/carriers',$api_key, $secret_key);
                    if ($carriears) {

                        //disable old warehouse..
                        $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $object_id]);

                        foreach ($carriears as $carrier) {

                            //select curriear code to pull services
                            $carrierCode = $carrier['code'];

                            $services = $this->shipstation->ApiCall('GET','/carriers/listservices?carrierCode='.$carrierCode,$api_key, $secret_key);

                            if($services && count($services) > 0) {

                                foreach( $services as $shipping_method) {

                                    $shipmethodData = $shipData = [];

                                    $shipData = $this->createCarriersArrayForDatabase($shipping_method);

                                    //fixed data for all 
                                    $shipmethodData = [
                                        'user_id' => $user_id,
                                        'platform_object_id' => $object_id,
                                        'platform_id' => $this->platformId,
                                        'user_integration_id' => $user_integration_id,
                                    ];

                                    //find already exist service code 
                                    $checkCarriearInDatabase = PlatformObjectData::where($shipmethodData)->where([
                                        'api_id' => $shipData['api_id'],
                                    ])->first();
                                    
                                    if ($checkCarriearInDatabase) {
                                        //update dynamic warehouse data only
                                        PlatformObjectData::where('id',$checkCarriearInDatabase->id)->update($shipData);
                                    } else {
                                        //insert warehouse data + fixed data
                                        $shipmethodData = $shipmethodData + $shipData;
                                        PlatformObjectData::create($shipmethodData);
                                    }
                                    
                                }

                            }   

                        }

                    } else {
                        $returnstatus = "shipping method not found.";
                    }
                } else {
                    $returnstatus = "No object found.";
                }
            } else {
                $returnstatus = "No credential found.";
            }
        }catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> createUpdateCarriersFromShipstation -> " . $e->getMessage());
            return $e->getMessage();
        }

        return $returnstatus;
    }
    public function createCarriersArrayForDatabase($shipping_method)
    {
        $shipping_method_Data = [
            //service code
            'api_id' => $shipping_method['code'],
            //carrierCode
            'api_code' => $shipping_method['carrierCode'],
            'name' => $shipping_method['name'],
            'status' => 1,
        ];
        return $shipping_method_Data;
    }





    //sync order in shipstation
    public function syncSalesOrder($is_initial_sync, $user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id, $destination_platform_name)
    {
    
       
        $returnstatus = true;
        $limit = 10;

        try {
            // get the account sub domain
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {

                $api_key = $this->mobj->encrypt_decrypt($account->app_id,'decrypt');
                $secret_key = $this->mobj->encrypt_decrypt($account->app_secret,'decrypt');

                if ($is_initial_sync) {
                    return true;
                } else {
                    // source platform
                    $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
                    $OrderWarehouseId = null;
                    $storeId = null;

                    if ($source_platform_id) {
   
                        $object_id = $this->ConnectionHelper->getObjectId('sales_order');
                        $order_warehouse_obj_id = $this->ConnectionHelper->getObjectId('warehouse');
                        $pricelist_objId = $this->ConnectionHelper->getObjectId('pricelist');
                       
                
                        if ($object_id) {

                            if ($record_id) {
                                $shipment_status = $sync_status = 'Failed';

                            } else {
                                $shipment_status = $sync_status = 'Ready';
                            }

                            //Get brightpearl orders
                            $parent_orders = PlatformOrder::with(['order_extra_information'])->where(['platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id, 'sync_status' => $sync_status, 'shipment_status' => $shipment_status ]);
                            
                           
                            
                            if ($record_id) {
                                $parent_orders = $parent_orders->where('platform_order.id', $record_id);
                            }

                            $parent_orders = $parent_orders->limit($limit)->get();
                            
                            $parentIntgId = null;
                            if ($parent_orders) {

                                $accessControl = app('App\Utility\PlatformConfig')->accessControl($source_platform_name, $destination_platform_name);
                                if($accessControl['status'] == true && $accessControl['action'] == 'share'){
                                    $parentIntgId = UserIntegration::where('id',$user_integration_id)->pluck('parent_integration_id')->first();
                                }
                            
                                /* ------------------------------------------- */
                                //here parent_order is source platform order
                                foreach ($parent_orders as $parent_order) {

                                    if ($parent_order->linked_id == 0 && $parent_order->is_deleted == 1) {

                                        $message = "Order related data deleted in source platform.";

                                        $statusForSync = 'failed';
                                        $parent_order->sync_status = 'Failed';
                                        $parent_order->shipment_status = 'Failed';
                                        $parent_order->order_updated_at = date('Y-m-d H:i:s');
                                        $parent_order->save();

                                        $returnstatus = $message;

                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, $statusForSync, $parent_order->id, $message);

                                    } else {

                                        $apidata = null;
                                        $apiResponse = null;

                                        $shipment = PlatformOrderShipment::where(['platform_id' => $parent_order->platform_id, 'user_integration_id' => $parent_order->user_integration_id, 'platform_order_id' => $parent_order->id])->select('id', 'shipment_id', 'shipping_method', 'warehouse_id')->first();
                                        
                                        //if shipment not found with order number ex. 	4099/1 then find with first index 4099
                                        if (!$shipment && isset($parent_order->order_number)) {
                                            $order_number = explode("/", $parent_order->order_number);
                                            if (is_array($order_number) && count($order_number) == 2) {
                                                $shipment = PlatformOrderShipment::where(['platform_id' => $parent_order->platform_id, 'user_integration_id' => $parent_order->user_integration_id, 'order_id' => $order_number[0], 'shipment_sequence_number' => $order_number[1]])->select('id', 'shipment_id', 'shipping_method', 'warehouse_id')->first();
                                            }
                                        }

                                        if ($shipment) {

                                            //prepare apidata
                                            $apidata['orderNumber'] = $parent_order->order_number;
                                            $apidata['orderDate'] = $parent_order->order_date;

                                            //update order as cancelled
                                            if($parent_order->linked_id > 0 && $parent_order->is_deleted == 1) {

                                                //get shipstaion synced order..details.. here in api_order_reference shipstation order key is stored
                                                $shipstation_order = PlatformOrder::find($parent_order->linked_id)->select('id','api_order_reference','api_order_id')->first();
                                                if($shipstation_order && $shipstation_order->api_order_reference) {
                                                    $apidata['orderKey'] = $shipstation_order->api_order_reference;
                                                    $apidata['orderId'] = $shipstation_order->api_order_id;
                                                }

                                                $apidata['orderStatus'] = 'cancelled';

                                            } else {

                                               /*----------------Start to find order warehouse----------------*/
                                               $warehouse_mapping = $this->mapping->getMappedDataByName($user_integration_id, NULL, "order_warehouse", ['api_id'], 'regular', $shipment->warehouse_id);
                                               if ($warehouse_mapping) {
                                                   $OrderWarehouseId = $warehouse_mapping->api_id;
                                               } 
                                               /*----------------end to find order warehouse----------------*/
   
   
                                               /*----------------Start to find channel to store mapping----------------*/
                                               if( isset($parent_order->order_extra_information) && isset($parent_order->order_extra_information->api_channel_id) ) {
                                                    $channel_to_store_mapping = $this->mapping->getMappedDataByName($user_integration_id, null, "sorder_channel", ['api_id'], 'cross', $parent_order->order_extra_information->api_channel_id);
                                                    if($channel_to_store_mapping) {
                                                        $storeId = $channel_to_store_mapping->api_id;
                                                    }
                                                }

                                               /*----------------End to find channel to store mapping----------------*/

                                               /*----------------Start to find shipping method mapping----------------*/
                                               $shipping_mapping = $this->mapping->getMappedDataByName($user_integration_id, NULL, "sorder_shipping_method", ['api_id','api_code'], 'regular', $shipment->shipping_method);
                                               if ($shipping_mapping) {
                                                   $apidata['serviceCode'] = $shipping_mapping->api_id;
                                                   $apidata['carrierCode'] = $shipping_mapping->api_code;
                                               } 
                                               /*----------------end to find shipping method mapping----------------*/


                                               $apidata['orderStatus'] = 'awaiting_shipment';

                                               //addition info
                                               if($OrderWarehouseId) {
                                                   $apidata['advancedOptions']['warehouseId'] = $OrderWarehouseId;
                                               }
                                               
                                               if($storeId) {
                                                   $apidata['advancedOptions']['storeId'] = $storeId;
                                               }

                                               //additional field under advance option for furture use
                                               // $apidata['advancedOptions']['billToParty'] = 'third_party';
                                               // $apidata['advancedOptions']['billToAccount'] = 45454;
                                               // $apidata['advancedOptions']['billToPostalCode'] = 654678;
                                               // $apidata['advancedOptions']['billToCountryCode'] = 'US';
                                               // $apidata['advancedOptions']['billToMyOtherAccount'] = 5615;


                                            }



                                           //Start dynamic field mapping || get mapped sale order field mappings...
                                            $fieldMappingRows = PlatformDataMapping::where([
                                               'mapping_type' => 'regular', 'data_map_type' => 'field',
                                               'platform_object_id' => $object_id, 'status' => 1, 'user_integration_id' => $user_integration_id
                                           ])->where('destination_row_id', '!=', 0)->get();

                                           foreach ($fieldMappingRows as $filedMap) {

                                               //here source is shipstation & desc it bp
                                               $sourceField = PlatformField::find($filedMap->source_row_id);
                                               $destField = PlatformField::find($filedMap->destination_row_id);
                                               $field_value = null;
                                               
                                               if ($sourceField && $destField) {
                                                   
                                                   //shipstation side field
                                                   $field_name = $sourceField->name;
                                   
                                                   //Ignore TRACKING_NUMBER field. it used in bp side for for update tracking info
                                                   if($field_name=="TRACKING_NUMBER"){
                                                       continue;
                                                   }
                                                   
                                                   if($destField->field_type=="default") {

                                                       //find from object data..currency conditon added for priceListId
                                                       if($destField->name=="priceListId") {
                                                           //here api_id value can be dynamic with db_field_name & platform_object_id 
                                                           $source_priceList_data = PlatformObjectData::where([
                                                               'user_integration_id' => $user_integration_id,
                                                               'platform_id' => $source_platform_id,
                                                               'platform_object_id' => $pricelist_objId,
                                                               'api_id' => $parent_order->api_pricelist_id,
                                                           ])->select('name')->first();
                                   
                                                           if($source_priceList_data) {
                                                               $field_value = $source_priceList_data->name;
                                                           }

                                                       } else if($destField->name=="WAREHOUSE") {

                                                           $source_warehouse_data = PlatformObjectData::where([
                                                               'user_integration_id' => $user_integration_id,
                                                               'platform_id' => $source_platform_id,
                                                               'platform_object_id' => $order_warehouse_obj_id,
                                                               'api_id' => $shipment->warehouse_id,
                                                           ])->select('name')->first();
               
                                                           if($source_warehouse_data) {
                                                               $field_value = $source_warehouse_data->name;
                                                           }

                                                       } else {

                                                           $db_field_name = $destField->db_field_name;
                                                           $field_value =  $parent_order->{$db_field_name};
                           
                                                       } 
                                                       
                                                   } else {
                                                       
                                                       //get CustomField values .. here record_id is order pid
                                                       $field_value = DB::table('platform_custom_field_values')->where(['user_integration_id'=>$user_integration_id,'record_id'=>$parent_order->id,'platform_field_id'=>$destField->id])
                                                       ->select('field_value')->pluck('field_value')->first();
                                   
                                                   }

                                                    if($field_value) {
                                                        if( $field_name == "customField1" || $field_name == "customField2" ||  $field_name == "customField3" || $field_name == "billToAccount") {
                                                            if($field_name=="billToAccount") {
                                                                $apidata['advancedOptions']['billToParty'] = 'third_party';
                                                            }
                                                            $apidata['advancedOptions'][$field_name] = $field_value;
                                                        } else {
                                                            if( $field_name =='giftMessage') {
                                                                $apidata['gift'] = true;
                                                            }
                                                            $apidata[$field_name] = $field_value;
                                                        }
                                                    }

                                   
                                               }
                                   
                                           }
                                           //end dynamic field mapping

                                            

                                            //push shipping/billing address
                                            $salesOrderAddresses = PlatformOrder::find($parent_order->id)->platformOrderAddress->toArray();
                                            //push  Order line items
                                            $salesOrderLines = PlatformOrder::find($parent_order->id)->platformOrderLine->toArray();


                                            $count = (!empty($salesOrderAddresses) || !empty($salesOrderLines)) ? ((count($salesOrderAddresses) > count($salesOrderLines)) ? count($salesOrderAddresses) : count($salesOrderLines)) : 0;
                                            if ($count) {
                                                for ($x = 0; $x < $count; $x++) {
                                                    if (isset($salesOrderAddresses[$x]) && isset($salesOrderAddresses[$x]['id'])) {
                                                        $parent_orderaddress = PlatformOrderAddress::find($salesOrderAddresses[$x]['id']);
                                                        if (isset($parent_orderaddress->id)) {
                                                            //prepare billTo & shipTo
                                                            $apidata = $this->createOrdersAddressObjectForAPI($parent_orderaddress, $shipment, $OrderWarehouseId, $apidata);
                                                        }
                                                    }
                                                    if (isset($salesOrderLines[$x]) && isset($salesOrderLines[$x]['id'])) {
                                                        $parent_orderline = PlatformOrderLine::find($salesOrderLines[$x]['id']);
                                                        if (isset($parent_orderline->id)) {
                                                            if ($shipment) {
                                                                //include orderLineItems
                                                                $apidata = $this->createOrdersLinesObjectForAPI($parent_orderline, $parent_order, $shipment, $apidata, $parentIntgId);
                                                            }
                                                        }
                                                    }
                                                }
                                            }


                                        } else {
                                            $apidata = [];
                                        }

                                        
                                        if($apidata) {
                                            
                           
                                           $apidata = json_encode($apidata);

                                           $apiResponse = $this->shipstation->ApiCall('POST','/orders/createorder',$api_key, $secret_key,$apidata);
               

                                           //log shipstation
                                           \Storage::disk('local')->append('shipstation_log.txt', 'syncSalesOrder Call time: ' . date('Y-m-d H:i:s').PHP_EOL.PHP_EOL.' apiRequestdata : '.$apidata .PHP_EOL.PHP_EOL. ' apiResponse : '.json_encode($apiResponse) );

                                            if (is_array($apiResponse) && isset($apiResponse['orderId']) && isset($apiResponse['orderNumber']) && !isset($apiResponse['error'])) {

                                                $orderdata = $this->createOrderArrayForDatabase($parent_order);
                                            

                                                if ($parent_order->linked_id == 0) {
                                                    $orderdata += ['user_id' => $user_id, 'user_workflow_rule_id' => $user_workflow_rule_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_customer_id' => $parent_order->platform_customer_id, 'linked_id' => $parent_order->id];
                                                    $child_order = PlatformOrder::create($orderdata);
                                                } else {
                                                    PlatformOrder::where('id', $parent_order->linked_id)->update($orderdata);
                                                    $child_order = PlatformOrder::find($parent_order->linked_id);
                                                }

                                                //store orderKey for update order
                                                $child_order->api_order_reference = $apiResponse['orderKey'];
                                                $child_order->api_order_id = $apiResponse['orderId'];
                                                $child_order->order_number = $apiResponse['orderNumber'];
                                                $child_order->order_status = $apiResponse['orderStatus'];
                                                $child_order->order_updated_at = date('Y-m-d H:i:s');

                                                $child_order->warehouse_id = ((isset($apiResponse['advancedOptions']['warehouseId']) && is_array($apiResponse['advancedOptions']['warehouseId'])) ? (isset($apiResponse['advancedOptions']['warehouseId']) ? $apiResponse['advancedOptions']['warehouse_id'] : null) : null);
                                                
            
                                                $child_order->save();

                                                // parent order
                                                $parent_order->sync_status = 'Synced';
                                                $parent_order->shipment_status = 'Synced';
                                                $parent_order->order_updated_at = date('Y-m-d H:i:s');
                                                $parent_order->linked_id = $child_order->id;
                                                $parent_order->save();

                                                $message = "Order synced successfully.";
                                                if($parent_order->linked_id > 0 && $parent_order->is_deleted == 1) {
                                                    $message = "Order related data deleted in source platform.";
                                                } 
                                                
                                                $statusForSync = 'success'; 

                                            } else {

                                                if (is_array($apiResponse) && isset($apiResponse['error'])) {
                                                    $message = is_array($apiResponse['error']) ? implode(',', $apiResponse['error']) : $apiResponse['error'];
                                                } else {
                                                   if(is_string($apiResponse)) {
                                                       $message = $apiResponse;
                                                   } else {
                                                       $message = "Shipping address fields are required";
                                                   }
                                                    
                                                }
                                                $statusForSync = 'failed';
                                                $parent_order->sync_status = 'Failed';
                                                $parent_order->shipment_status = 'Failed';
                                                $parent_order->order_updated_at = date('Y-m-d H:i:s');
                                                $parent_order->save();
                                                $returnstatus = $message;

                                            }

                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, $statusForSync, $parent_order->id, $message);


                                        }
    

                                    }

                                }

                            } else {
                                $returnstatus = 'No data to sync.';
                            }
                        } else {
                            $returnstatus = 'No object found.';
                        }
                    } else {
                        $returnstatus = 'No platform found.';
                    }
                }
            } else {
                $returnstatus = 'No account found.';
            }
            return $returnstatus;
        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> syncSalesOrder -> " . $e->getMessage(). $e->getLine() );
            return $e->getMessage();
        }


    }


    //sync order in shipstation
    public function syncTransferOrder($is_initial_sync, $user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id)
    {
        $returnstatus = true;
        $limit = 10;
        $sync_status = 'Ready';

        try {
            // get the account sub domain
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {

                $api_key = $this->mobj->encrypt_decrypt($account->app_id,'decrypt');
                $secret_key = $this->mobj->encrypt_decrypt($account->app_secret,'decrypt');

                if ($is_initial_sync) {
                    return true;
                } else {
                    // source platform
                    $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);

                    if ($source_platform_id) {

                        $object_id = $this->ConnectionHelper->getObjectId('transfer_order');
                        $order_warehouse_obj_id = $this->ConnectionHelper->getObjectId('warehouse');

                        if ($object_id) {
                          
                            $query = PlatformOrderShipment::select('id', 'user_id', 'platform_id', 'user_integration_id', 'shipment_id', 'sync_status', 'platform_order_id', 'order_id', 'shipping_method', 'shipment_sequence_number', 'warehouse_id', 'to_warehouse_id', 'linked_id', 'stock_transfer_id','created_on');
                            if ($record_id && $record_id !== 0) {
                                $query->where('id', $record_id);
                            } else {
                                $query->where([
                                    [
                                        'platform_id', '=', $source_platform_id
                                    ], [
                                        'user_integration_id', '=', $user_integration_id
                                    ], [
                                        'sync_status', '=', $sync_status
                                    ],
                                    [
                                        'type', '=', 'Transfer'
                                    ]
                                ]);
                            }
        
                            $parent_orders = $query->groupBy()->orderBy('updated_at', 'ASC')->orderBy('shipment_id', 'DESC')->take($limit)->get();


                            if ($parent_orders) {
                                /* ------------------------------------------- */
                                //here parent_order is source platform order
                                foreach ($parent_orders as $parent_order) {

                                        $apidata = null;
                                        $apiResponse = null;

                                        /*----------------Start to find order warehouse----------------*/
                                        $OrderWarehouseId = null;
                                        $warehouse_mapping = $this->mapping->getMappedDataByName($user_integration_id, NULL, "order_warehouse", ['api_id'], 'regular', $parent_order->warehouse_id);

                                        if ($warehouse_mapping) {
                                            $OrderWarehouseId = $warehouse_mapping->api_id;
                                        } 
                                        /*----------------end to find order warehouse----------------*/
 

                                        //prepare apidata
                                        $apidata['orderNumber'] = $parent_order->shipment_id;
                                        $apidata['orderDate'] = $parent_order->created_on;
                                        //give default mapping for this....
                                        $apidata['orderStatus'] = 'awaiting_shipment';

                                        //addition info
                                        if($OrderWarehouseId) {
                                            $apidata['advancedOptions']['warehouseId'] = $OrderWarehouseId;
                                        }

                                        //custom fix default mapping...
                                        $source_warehouse_data = PlatformObjectData::where([
                                            'user_integration_id' => $user_integration_id,
                                            'platform_id' => $source_platform_id,
                                            'platform_object_id' => $order_warehouse_obj_id,
                                            'api_id' => $parent_order->warehouse_id,
                                        ])->select('name')->first();

                                        $source_warehouse_name = NULL;
                                        if($source_warehouse_data) {
                                            //custom field 1
                                            $apidata['advancedOptions']['customField1'] = $source_warehouse_data->name;
                                            $source_warehouse_name = $source_warehouse_data->name;
                                        }

                                        //custom field 2
                                        $apidata['advancedOptions']['customField2'] = $parent_order->shipment_id;

                                    
                                        //get address by warehouse || //prepare billTo & shipTo
                                        $toWarehouse = $this->mapping->getObjectDataByFilterData($user_id, $user_integration_id, $source_platform_id, $order_warehouse_obj_id, "api_id",  $parent_order->warehouse_id, ["id"]);

                                        $addressArr = [];
                                        if ($toWarehouse) {

                                            $toWarehouseAddress = $this->getWarehouseAddress($toWarehouse->id);

                                            if($toWarehouseAddress) {

                                                if($source_warehouse_name) {
                                                    $addressArr['name'] = $source_warehouse_name;
                                                } else {
                                                    $addressArr['name'] = 'Warehouse_Shipping_Billing';
                                                }
                                                

                                                
                                                $addressArr['company'] = $toWarehouseAddress['shipToCompany'];
                                                $addressArr['street1'] = $toWarehouseAddress['shipToStreet'];
                                                $addressArr['street2'] = null;
                                                $addressArr['city'] = $toWarehouseAddress['shipToCity'];
                                                $addressArr['state'] = $toWarehouseAddress['shipToState'];
                                                $addressArr['postalCode'] = $toWarehouseAddress['shipToZip'];
                                                $addressArr['country'] = $toWarehouseAddress['shipToCountry'];
                                                $addressArr['phone'] = null;
                                                $addressArr['residential'] = false;

                                                //push in address array
                                                $apidata['billTo'] = $addressArr;
                                                $apidata['shipTo'] = $addressArr;

                                            
                                                
                                            }
                                        } 

                                        //if warehouse address not found
                                        if(empty($addressArr)) {

                                            $message = "Shipping address fields are required";

                                            $statusForSync = 'failed';
                                            $parent_order->sync_status = 'Failed';
                                            $parent_order->save();

                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, $statusForSync, $parent_order->id, $message);

                                            return $message;
                                        }

                                       
                                        //include shipment line...
                                        $packedItems = [];
                                        $parent_shipmentline = PlatformOrderShipmentLine::where('platform_order_shipment_id',$parent_order->id)->get();
                                        if (isset($parent_shipmentline)) {

                                            foreach($parent_shipmentline as $shipline) {

                                                // $lineItems['lineItemKey'] = "orderKey";
                                                $lineItems['sku'] = $shipline->product_id;
                                                $lineItems['name'] = '';
                                                $lineItems['quantity'] = $shipline->quantity;
                                                $packedItems[] = $lineItems;
                                            }
                                                
                                            $apidata['items'] = $packedItems;
                                        
                                        }                                   


                                        if($apidata) {

                                            $apidata = json_encode($apidata);
                                        
                                            $apiResponse = $this->shipstation->ApiCall('POST','/orders/createorder',$api_key, $secret_key,$apidata);
                                            
                                            //log shipstation
                                            \Storage::disk('local')->append('shipstation_log.txt', 'syncTransferOrder Call time: ' . date('Y-m-d H:i:s').PHP_EOL.PHP_EOL.' apidata : '.$apidata .PHP_EOL.PHP_EOL. ' apiResponse : '.json_encode($apiResponse) );

                                            if (is_array($apiResponse) && isset($apiResponse['orderId']) && isset($apiResponse['orderNumber']) && !isset($apiResponse['error'])) {


                                                /* Insert order details  */
                                                $shipment_data = [
                                                    'user_id' => $user_id,
                                                    'platform_id' => $this->platformId,
                                                    'user_integration_id' => $user_integration_id,
                                                    'shipment_id' => $apiResponse['orderId'],
                                                    'shipment_sequence_number' => 0,
                                                    'warehouse_id' =>  $apiResponse['advancedOptions']['warehouseId'],
                                                    'created_on' => date("Y-m-d H:i:s", strtotime($apiResponse['createDate'])),
                                                    'order_id' => $apiResponse['orderId'],
                                                    'type' => 'Transfer',
                                                    'sync_status' => 'Pending',
                                                    'linked_id' =>  $parent_order->id,
                                                    //store orderKey for update order
                                                    'transaction_id' => $apiResponse['orderKey']
                                                ];


                                                if ($parent_order->linked_id == 0) {
                                                    //insert shipstation side synced shipment
                                                    $child_order = PlatformOrderShipment::create($shipment_data);
                                                } else {
                                                    PlatformOrderShipment::where('id', $parent_order->linked_id)->update($shipment_data);
                                                    $child_order = PlatformOrderShipment::find($parent_order->linked_id);
                                                }

                                              
                                                // $child_order->api_order_id = $apiResponse['orderId'];
                                                // $child_order->order_number = $apiResponse['orderNumber'];
                                                $child_order->shipment_status = $apiResponse['orderStatus'];
                                                // $child_order->order_updated_at = date('Y-m-d H:i:s');

                                                $child_order->warehouse_id = ((isset($apiResponse['advancedOptions']['warehouseId']) && is_array($apiResponse['advancedOptions']['warehouseId'])) ? (isset($apiResponse['advancedOptions']['warehouseId']) ? $apiResponse['advancedOptions']['warehouse_id'] : null) : null);
                                                
            
                                                $child_order->save();

                                                // parent order
                                                $parent_order->sync_status = 'Synced';
                                                $parent_order->linked_id = $child_order->id;
                                                $parent_order->save();

                                                $message = "Order synced successfully.";
                                                $statusForSync = 'success'; 

                                            } else {

                                                if (is_array($apiResponse) && isset($apiResponse['error'])) {
                                                    $message = is_array($apiResponse['error']) ? implode(',', $apiResponse['error']) : $apiResponse['error'];
                                                } else {
                                                    $message = "Shipping address fields are required";
                                                }
                                                $statusForSync = 'failed';
                                                $parent_order->sync_status = 'Failed';
                                                // $parent_order->shipment_status = 'Failed';
                                                // $parent_order->order_updated_at = date('Y-m-d H:i:s');
                                                $parent_order->save();
                                                $returnstatus = $message;

                                            }

                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, $statusForSync, $parent_order->id, $message);


                                        }

                                }

                            } else {
                                $returnstatus = 'No data to sync.';
                            }
                        } else {
                            $returnstatus = 'No object found.';
                        }
                    } else {
                        $returnstatus = 'No platform found.';
                    }
                }
            } else {
                $returnstatus = 'No account found.';
            }
            return $returnstatus;
        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> syncTransferOrder -> " . $e->getMessage());
            return $e->getMessage();
        }


    }


    /* find Warehouse Address */
    public function getWarehouseAddress($platformObjectDataId)
    {
        $address = [];
        $wh = PlatformObjectDataAdditionalInformation::where('platform_object_data_id', $platformObjectDataId)->first();
        if ($wh) {
            $stateName = isset($wh->state) ? $wh->state : null;
            if ($stateName) {
                $FindState = PlatformStates::select('iso2')->where(function ($query) use ($stateName) {
                    $query->where(
                        'name',
                        '=',
                        $stateName
                    )
                        ->orWhere('iso2', '=', $stateName);
                })->first();
                $state = isset($FindState->iso2) ? $FindState->iso2 :  $stateName;
            } else {
                $state = $stateName;
            }
            $countryName = isset($wh->country) ? $wh->country : null;
            if ($countryName) {
                $FindCountry = PlatformCountry::select('iso')->where(function ($query) use ($countryName) {
                    $query->where(
                        'name',
                        '=',
                        $countryName
                    )
                        ->orWhere('iso', '=', $countryName);
                })->first();
                $country = isset($FindCountry->iso) ? $FindCountry->iso :  $countryName;
            } else {
                $country = $countryName;
            }
            $address = [
                "shipToCompany" =>  null,
                "shipToStreet" => isset($wh->address1) ? $wh->address1 : null,
                "shipToCity" =>  isset($wh->city) ? $wh->city : null,
                "shipToState" => $state,
                "shipToZip" => isset($wh->postal_code) ? $wh->postal_code : null,
                "shipToCountry" => $country
            ];
        }
        return $address;
    }



    public function createOrdersAddressObjectForAPI(PlatformOrderAddress $address, $shipment, $OrderWarehouseId, $apidata)
    {
    
        if ($address->address_type == 'shipping') {
            $apidata['shipTo'] = $this->createAddressObjectForOrdersAddressObjectForAPI($address, 'shipTo');
        } elseif ($address->address_type == 'billing') {
            $apidata['billTo'] = $this->createAddressObjectForOrdersAddressObjectForAPI($address, 'billTo');
        } 
        return $apidata;
    }
    public function createAddressObjectForOrdersAddressObjectForAPI(PlatformOrderAddress $address, $type)
    {
        $addressArr = [];

        if($address->address_name) {
            $address_name = $address->address_name;
        } else if($address->company) {
            $address_name = $address->company;
        } else if($address->address1) {
            $address_name = $address->address1;
        } else {
            $address_name = $address->address_name;
        }

        if($type=="billTo") {
            $addressArr['name'] = $address_name;
            $addressArr['company'] = null;
            $addressArr['street1'] = ($address->address1) ? $address->address1 : $address_name;
            $addressArr['street2'] = null;
            $addressArr['city'] = null;
            $addressArr['state'] = null;
            $addressArr['postalCode'] = null;
            $addressArr['country'] = null;
            $addressArr['phone'] = null;
            $addressArr['residential'] = null;
        } else {
            $addressArr['name'] = $address_name;
            $addressArr['company'] = $address->company;
            $addressArr['street1'] = ($address->address1) ? $address->address1 : $address_name;
            $addressArr['street2'] = ($address->address2) ? $address->address2 : NULL;
            $addressArr['city'] = ($address->address3) ? $address->address3 : NULL;
            $addressArr['state'] = ($address->address4) ? $address->address4 : NULL;
            $addressArr['postalCode'] = ($address->postal_code) ? $address->postal_code : NULL;
            $addressArr['country'] = $address->country;
            $addressArr['phone'] = $address->phone_number;
            $addressArr['residential'] = true;
        }
        
        
        return $addressArr;
    }


    public function createOrdersLinesObjectForAPI(PlatformOrderLine $orderline, PlatformOrder $order, PlatformOrderShipment $shipment, $apidata, $parentIntgId=null)
    {
        if ($orderline->row_type == "ITEM" || $orderline->row_type == "GIFTCARD" ) {
            $product = PlatformProduct::where([
                'platform_id' => $order->platform_id,
                'user_integration_id' => $parentIntgId ? $parentIntgId : $order->user_integration_id,
                'api_product_id' => $orderline->api_product_id,
            ])->select('id')->first();
            
            $platform_product_id = NULL;
            if($product) {
                $platform_product_id = $product->id;
            }

            $apidata['items'][] = $this->createLinesObjectForOrdersLinesObjectForAPI($orderline, $shipment, $order->user_integration_id,$platform_product_id);

            

        } elseif ($orderline->row_type == "SHIPPING") {
            $shipping_price = isset($apidata['shipping_price']) ? $apidata['shipping_price'] : 0;
            $apidata['shippingAmount'] = $shipping_price + $orderline->total;
        } elseif ($orderline->row_type == "TAX") {
            $tax_price = isset($apidata['tax_price']) ? $apidata['tax_price'] : 0;
            $apidata['taxAmount'] = $tax_price + $orderline->total;
        }

        return $apidata;
    }
    public function createLinesObjectForOrdersLinesObjectForAPI(PlatformOrderLine $orderline, PlatformOrderShipment $shipment, $user_integration_id, $platform_product_id)
    {
        $lineItems = [];
        $shipmentLine = PlatformOrderShipmentLine::where([
            'platform_order_shipment_id' => $shipment->id,
            'row_id' => $orderline->api_order_line_id,
        ])->first();


        // $lineItems['lineItemKey'] = "orderKey";
		$lineItems['sku'] = $orderline->sku;
        $lineItems['name'] = $orderline->product_name;


        //check mapping for include variant with product name  
        $variant_obj_id = $this->ConnectionHelper->getObjectId('add_variant_in_product_name');
        $allow_include_variant = $this->mapping->getMappedApiIdByObjectId($user_integration_id, $variant_obj_id);
        if ($allow_include_variant == 1) {
            $find_variant = DB::table('platform_product_options')->where('platform_product_id',$platform_product_id)->select('option_value')->pluck('option_value')->toArray();
            if($find_variant) {
                $available_variant = implode('-',$find_variant);
                $lineItems['name'] = $orderline->product_name.'-'.$available_variant;
            }
        } 
        //end variant add condition

        // $optionLine['name'] = 'Color'; 
        // $optionLine['value'] = 'BLONDE'; 
        // array_push($option_line_array,$optionLine);
        // $lineItems['options'] = $option_line_array; 


        $lineItems['quantity'] =  ($shipmentLine) ? $shipmentLine->quantity : $orderline->qty;
        $lineItems['unitPrice'] = ($orderline->price) ? $orderline->price : $orderline->unit_price;  

		// $lineItems['weight']['value'] = ($product) ? strval($product->weight) : '0';
		// $lineItems['weight']['units'] = '';
		// $lineItems['weight']['WeightUnits'] = '';


        // $lineItems['taxAmount'] = 0;
        // $lineItems['shippingAmount'] = 0;

        // $lineItems['productId'] = $orderline->api_product_id;
        // $lineItems['fulfillmentSku'] = $orderline->sku;

        // $lineItems['adjustment'] = '';
        // $lineItems['upc'] = '';


        return $lineItems;
    }

    public function createOrdersLinesArrayForDatabase(PlatformOrderLine $orderline)
    {
        $data = [
            "api_product_id" => $orderline->api_product_id,
            "product_name" => $orderline->product_name,
            "item_row_sequence" => $orderline->item_row_sequence,
            "ean" => $orderline->ean,
            "sku" => $orderline->sku,
            "gtin" => $orderline->gtin,
            "upc" => $orderline->upc,
            "mpn" => $orderline->mpn,
            "qty" => $orderline->qty,
            "subtotal" => $orderline->subtotal,
            "subtotal_tax" => $orderline->subtotal_tax,
            "total" => $orderline->total,
            "total_tax" => $orderline->total_tax,
            "taxes" => $orderline->taxes,
            "variation_id" => $orderline->variation_id,
            "price" => $orderline->price,
            "unit_price" => $orderline->unit_price,
            "uom" => $orderline->uom,
            "description" => $orderline->description,
            "notes" => $orderline->notes,
            "api_code" => $orderline->api_code,
            "row_type" => $orderline->row_type,
        ];
        return $data;
    }
    public function createOrderArrayForDatabase(PlatformOrder $order)
    {
        $is_voided = $order->is_voided;
        if ($order->is_deleted == 1) {
            $is_voided = 1;
        }

        $data = [
            'trading_partner_id' => $order->trading_partner_id,
            'order_type' => $order->order_type,
            'customer_email' => $order->customer_email,
            'order_number' => $order->order_number,
            'currency' => $order->currency,
            'order_date' => $order->order_date,
            'order_status' => $order->order_status,
            'api_order_payment_status' => $order->api_order_payment_status,
            'due_days' => $order->due_days,
            'department' => $order->department,
            'vendor' => $order->vendor,
            'total_discount' => $order->total_discount,
            'total_tax' => $order->total_tax,
            'total_amount' => $order->total_amount,
            'net_amount' => $order->net_amount,
            'shipping_total' => $order->shipping_total,
            'shipping_tax' => $order->shipping_tax,
            'discount_tax' => $order->discount_tax,
            'payment_date' => $order->payment_date,
            'delivery_date' => $order->delivery_date,
            'shipping_method' => $order->shipping_method,
            'notes' => $order->notes,
            'refund_sync_status' => $order->refund_sync_status,
            'is_voided' => $is_voided,
            'invoice_sync_status' => $order->invoice_sync_status,
            'file_name' => $order->file_name,
            'ship_speed' => $order->ship_speed,
            'carrier_code' => $order->carrier_code,
            'warehouse_id' => $order->warehouse_id,
            'order_update_status' => $order->order_update_status,
            'shipment_status' => 'Pending',
            'shipment_api_status' => $order->shipment_api_status,
            'platform_order_shipment_id' => $order->platform_order_shipment_id,
            'sync_status' => 'Ready',
        ];
        return $data;
    }


    //create webhook
    public function createWebhookForTrackingInformation($user_id, $user_integration_id, $is_initial_sync)
    {
        $returnstatus = true;
        try {

            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {

                $api_key = $this->mobj->encrypt_decrypt($account->app_id,'decrypt');
                $secret_key = $this->mobj->encrypt_decrypt($account->app_secret,'decrypt');

                if ($is_initial_sync) {

                    $find = PlatformWebhookInformation::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->first();

                    if(!$find){

                        if(env('APP_ENV') == 'prod'){
                            $env = 1;
                        }else{
                            $env = 2;
                        }

                        $callback_url = env('APP_WEBHOOK_URL')."/shipstation/index.php?for=shipment&uid=$user_integration_id&env=$env";

                        $Events = ['SHIP_NOTIFY','FULFILLMENT_SHIPPED'];

                        foreach($Events as $Event) {

                            $postQuery = [
                                'target_url' => $callback_url,
                                'event' => $Event,
                                'friendly_name' => 'Webhook for '.$Event,
                            ];

                            $webhook = $this->shipstation->ApiCall('POST','/webhooks/subscribe',$api_key, $secret_key,json_encode($postQuery));

                            if (isset($webhook['id'])) {
                                $webhookDetails = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_id' => $webhook['id']];
                                $checkWebhook = PlatformWebhookInformation::where($webhookDetails)->first();
                                if ($checkWebhook) {
                                    return true;
                                } else {
                                    $webhookDetails['description'] = $Event;
                                    PlatformWebhookInformation::create($webhookDetails);
                                }
                            } else {
                                $returnstatus = 'Webhook is not created.';
                            }

                        }

                        
                    }

                }

            }

        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> createWebhookForTrackingInformation -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    //delete webhook on disconnect
    public function deleteWebhook($user_id, $user_integration_id)
    {
        $returnstatus = true;
        try {

            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);

            if ($account) {

                $api_key = $this->mobj->encrypt_decrypt($account->app_id,'decrypt');
                $secret_key = $this->mobj->encrypt_decrypt($account->app_secret,'decrypt');

                //test get webhook
                // $response = $this->shipstation->ApiCall('GET','/webhooks',$api_key, $secret_key,NULL);

    
                $find_webhook_details = PlatformWebhookInformation::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])
                ->select('id','api_id')->get();

                if($find_webhook_details){
                    foreach( $find_webhook_details as $webhook) {
                        $response = $this->shipstation->ApiCall('DELETE','/webhooks/'.$webhook->api_id,$api_key, $secret_key,NULL);
                        if(empty($response)) {
                            PlatformWebhookInformation::where('id',$webhook->id)->delete();
                        }
                    }
                }
                    
            }

        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> deleteWebhook -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }

        return $returnstatus;

    }

    //receive webhook
    public function getShipmentForOrdersFromShipstation(Request $request, $user_integration_id)
    {
        $returnstatus = false;
        $message = 'Done';
        try {

            if ($request->isMethod('post')) {

                $data = $request->getContent();
                $data = json_decode($data, true);

                \Log::channel('webhook')->info("Shipstation Shipment - UserIntegration=" . $user_integration_id . " Data " . print_r($data, true). ' resource_url : '. $data['resource_url'].' resource_type : '.$data['resource_type']  . " Created Date : " . date('Y-m-d H:i:s'));

                //get user details by integration
                $userIntegData = DB::table('user_integrations')->where('id',$user_integration_id)->select('user_id')->first();

                if ($data && $userIntegData ) {

                    $userId = $userIntegData->user_id;

                    // $events = ['SHIP_NOTIFY','FULFILLMENT_SHIPPED'];
                    //&& in_array($data['resource_type'], $events)

                    if ( isset($data['resource_url']) && isset($data['resource_type'])  ) {

                        //insert webhook info in  platform_receive_webhooks
                        PlatformReceiveWebhook::insert(['user_id'=>$userId,'user_integration_id'=>$user_integration_id,'platform_id'=>$this->platformId,'webhook_data'=>$data['resource_url'].','.$data['resource_type'],'type'=>'SHIPMENT']);

                    } else {
                        $message = "Events only related to shipment are taken.";
                    }
                } else {
                    $message = "No data for the shipment";
                }

            } else {
                $message = "Method should be post";
            }

        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> getShipmentForOrdersFromShipstation -> " . $e->getMessage());
            $message = $e->getMessage();
        }
        return $returnstatus;
    }
    //process shipment webhook
    public function processShipmentWebhook($user_id, $user_integration_id)
    {
        $returnstatus = true;
        try {

            $limit = 10;

            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {

                $api_key = $this->mobj->encrypt_decrypt($account->app_id,'decrypt');
                $secret_key = $this->mobj->encrypt_decrypt($account->app_secret,'decrypt');
                
                $webhook_request_data = PlatformReceiveWebhook::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,'type'=>'SHIPMENT','status'=>0])->limit($limit)->get(); 


                if($webhook_request_data) {

                    foreach($webhook_request_data as $webhook) {
                        
                        $explode_data = explode(',',$webhook->webhook_data);
                        if($explode_data) {

                            $webhook_url = $explode_data[0];
                            $event = $explode_data[1];

                            $data = $this->shipstation->ApiCall('GET',$webhook_url,$api_key, $secret_key,NULL,true);

                            if( $event=='FULFILLMENT_SHIPPED' ) {

                                //fulfillments
                                if( isset($data) && $data['fulfillments'] && count($data['fulfillments']) > 0 ) {
                                    foreach( $data['fulfillments'] as $fulfillments ) {
                                        //insert update shipment webhook data
                                        $this->insertUpdateShipment($user_id, $user_integration_id,'FULFILLMENT_SHIPPED',$fulfillments);
                                    }
                                } 

                            } else if( $event=='SHIP_NOTIFY' ) {

                                //shipments
                                if( isset($data) && $data['shipments'] && count($data['shipments']) > 0 ) {
                                    foreach( $data['shipments'] as $shipments ) {
                                        //insert update shipment webhook data
                                        $this->insertUpdateShipment($user_id, $user_integration_id,'SHIP_NOTIFY',$shipments);
                                    }
                                }

                            } 

                            //if shipment null received then update status
                            PlatformReceiveWebhook::where(['id' => $webhook->id])->update(['status'=>1]); 

                        }
    

                    }

                }

                


            }

        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> createWebhookForTrackingInformation -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;


    }


    //insert update shipment...
    public function insertUpdateShipment($user_id, $user_integration_id, $event, $data)
    {
        $returnstatus = true;

        try {

            if ( isset($data) && ( isset($data['shipmentId']) || isset($data['fulfillmentId']) ) && !empty($data['trackingNumber'])) {

                // check order is created by our system.. if order exists in db
                $order_query = PlatformOrder::select('id','linked_id','api_order_id','platform_order_shipment_id','shipment_api_status','shipment_status')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId]);
                $order_query->where(function($query) use ($data){
                    $query->where('api_order_id', $data['orderId'])->orWhere('order_number',$data['orderNumber']);
                });
                $order = $order_query->first();

                //if order found means its sales order / purchase order else transfer order
                if ($order) {

                    //handle common data for SHIP_NOTIFY FULFILLMENT_SHIPPED
                    $newcarrier = $shipment_id = null;
                    if($event=="SHIP_NOTIFY") {
                        
                        $shipment_id = $data['shipmentId'];
                        $newcarrier = isset($data['carrierCode']) ? $data['carrierCode'] . (isset($data['serviceCode']) ? ', ' . $data['serviceCode'] : '') : null;

                        //handle shipment cost
                        if( isset($data['shipmentCost']) ) {

                            //Receive shipmentCost  &  add the cost to the original sales order of Brightpearl as a new line item...insert as new order line
                            $shipmentCost = $data['shipmentCost'];
                            $count_existing_order_line = PlatformOrderLine::where(['platform_order_id'=>$order->linked_id])->count();

                            $new_order_line = [
                                "item_row_sequence" => $count_existing_order_line + 1,
                                "platform_order_id" => $order->linked_id,
                                "qty" => 1,
                                "subtotal" => $shipmentCost,
                                "total" => $shipmentCost,
                                "price" => $shipmentCost,
                                "unit_price" => $shipmentCost,
                                "description" => 'Shipment cost line added from shipment',
                                "notes" => 'shipmentCost ready for update in order line',
                                "row_type" => 'SHIPPING',
                                "product_name" => 'Shipping Cost',
                            ];

                            //find order line for...platform_order_id == by $order->linked_id
                            $find_shipping_cost_line = PlatformOrderLine::where(['platform_order_id'=>$order->linked_id,'row_type'=>'SHIPPING'])->first();
                            if(!$find_shipping_cost_line) {
                                PlatformOrderLine::insert($new_order_line);
                            } else {
                                PlatformOrderLine::where(['platform_order_id'=>$order->linked_id,'row_type'=>'SHIPPING'])->update($new_order_line);
                            }

                            //end if shipment cost
                        }


                    } else if($event=="FULFILLMENT_SHIPPED") {

                        $shipment_id = $data['fulfillmentId'];
                        $newcarrier = isset($data['carrierCode']) ? $data['carrierCode'] . (isset($data['fulfillmentServiceCode']) ? ', ' . $data['fulfillmentServiceCode'] : '') : null;

                    } 
                    //end handle common data

                    
                    // Select the brightpearl inserted shipment or the parent shipment data
                    $shipment = PlatformOrderShipment::select('id','linked_id',)->where([
                        'user_integration_id' => $user_integration_id,
                        //here $order->linked_id == brightpearl order primary id
                        'platform_order_id' => $order->linked_id,
                    ])->first();


                    //always shipment will be available for those order created by our system... "flow get goods note from bp - create so in shipstation"
                    if ($shipment) {
                
                        // Check if the shipment of shipstation side is already in the database
                        if ($shipment->linked_id) {
                  
                            $keyValueArr = [];

                            $order_infos = PlatformOrderShipment::where('platform_order_id',$order->id)->groupBy('shipment_id')->select('order_id','tracking_info')->get();

                            foreach($order_infos as $odr_info){
                                if($odr_info->tracking_info){
                                    $keyValueArr[$odr_info->tracking_info] = $odr_info->order_id;
                                }
                            }

                            $tracking_infos = array_keys($keyValueArr);

                    
                            $shipOrderId = null;
                            foreach($tracking_infos as $key){
                                if(array_key_exists($key, $keyValueArr)) {
                                    $shipOrderId = $keyValueArr[$key];
                                    break;
                                }
                            }

                            if(!empty($tracking_infos) &&  $shipOrderId)
                            {
                                if(!in_array(trim($data['trackingNumber']), $tracking_infos)){
                                   
                                    //starting to insert shipment if order has more than 1 shipment                                                 
                                     $shipmentLatestSequence = PlatformOrderShipment::where('order_id',$shipOrderId)->orderBy('shipment_sequence_number', 'DESC')->pluck('shipment_sequence_number')->first();


                                    PlatformOrderShipment::create([
                                        'user_id' => $user_id,
                                        'platform_id' => $this->platformId,
                                        'user_integration_id' => $user_integration_id,
                                        'shipment_id' => $shipment_id,
                                        'platform_order_id' => $order->id,
                                        'order_id' => $order->api_order_id,
                                        'shipment_status' => $event,
                                        'tracking_info' => $data['trackingNumber'],
                                        'carrier_code' => $newcarrier,
                                        'shipment_sequence_number' => $shipmentLatestSequence + 1,
                                        'shipping_method' => $newcarrier,
                                        'sync_status' => 'Ready',
                                        'linked_id' => $shipment->id,
                                    ]);
                                    $order->shipment_status = 'Ready';
                                    $order->shipment_api_status = $event;
                                    $order->save();
                                }
                            }

                        } else {
                
                            // Here the shipment will get created in link with the parent shipment
                            $newshipment = PlatformOrderShipment::create([
                                'user_id' => $user_id,
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $user_integration_id,
                                'shipment_id' => $shipment_id,
                                'platform_order_id' => $order->id,
                                'order_id' => $order->api_order_id,
                                'shipment_status' => $event,
                                'tracking_info' => $data['trackingNumber'],
                                'carrier_code' => $newcarrier,
                                'shipment_sequence_number' => 1,
                                'shipping_method' => $newcarrier,
                                'sync_status' => 'Ready',
                                'linked_id' => $shipment->id,
                            ]);

                            $shipment->linked_id = $newshipment->id;
                            $shipment->save();

                            $order->platform_order_shipment_id = $newshipment->id;
                            $order->shipment_status = 'Ready';
                            $order->shipment_api_status = $event;
                            $order->save();
                        }

                    }

                } else {

      
                    //check is this transfer type shipment...
                    //handle common data for SHIP_NOTIFY FULFILLMENT_SHIPPED
                    $newcarrier = $shipment_id = null;
                    if($event=="SHIP_NOTIFY") {
                        
                        $shipment_id = $data['shipmentId'];
                        $newcarrier = isset($data['carrierCode']) ? $data['carrierCode'] . (isset($data['serviceCode']) ? ', ' . $data['serviceCode'] : '') : null;

                    } else if($event=="FULFILLMENT_SHIPPED") {

                        $shipment_id = $data['fulfillmentId'];
                        $newcarrier = isset($data['carrierCode']) ? $data['carrierCode'] . (isset($data['fulfillmentServiceCode']) ? ', ' . $data['fulfillmentServiceCode'] : '') : null;

                    } 
                    //end handle common data

                    
                    // Select the brightpearl inserted shipment or the parent shipment data
                    $shipment = PlatformOrderShipment::select('id','linked_id','sync_status','tracking_info')->where([
                        'user_integration_id' => $user_integration_id,
                        'order_id' => $data['orderId'],
                        'platform_id' => $this->platformId
                    ])->first();


                    //always shipment will be available for those order created by our system... "flow get goods note from bp - create so in shipstation"
                    if ($shipment) {
                        
                        // Check if the shipment of shipstation side is already in the database
                        if ( $shipment->linked_id && ($shipment->sync_status !='Synced' || empty($shipment->tracking_info) ) ) {
                            
                            PlatformOrderShipment::where('id',$shipment->id)->update([
                                'shipment_status' => $event,
                                'tracking_info' => $data['trackingNumber'],
                                'carrier_code' => $newcarrier,
                                'shipment_sequence_number' => 1,
                                'shipping_method' => $newcarrier,
                                'sync_status' => 'Ready',
                            ]);
        
                        } 

                    }


                }

            }

            $returnstatus = true;

        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> insertUpdateShipment -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }

        return $returnstatus;
 
    }


    /* Not in use for now | Pull shipstation Shipping Orders/Details by api.. if webhook missing...can be use as backup logic */
    public function GetShippingOrderDetails($user_id, $user_integration_id)
    {
        $returnstatus = true;
        try {
            
            $limit = 100;

            $start_date='2023-04-10';
            $end_date=date('Y-m-d');

            $loop_breaker=5;

            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {
                
                $api_key = $this->mobj->encrypt_decrypt($account->app_id,'decrypt');
                $secret_key = $this->mobj->encrypt_decrypt($account->app_secret,'decrypt');

                $url_with_page = PlatformUrl::select('id','url')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'shipstation_shipment_page_number'])->first();

                if ($url_with_page) {

                        if($url_with_page->url > 10){
                            $last_page_number = 1;
                        }else{
                            $last_page_number = $url_with_page->url;
                        }

                } else {

                    $last_page_number = 1;

                    // insert last order fetch created time
                    PlatformUrl::insert([
                        'user_id' => $user_id,
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'url' => $last_page_number,
                        'url_name' => 'shipstation_shipment_page_number',
                    ]);

                }

                $loop = true; 
                $loop_counter = 1;

                while($loop) {

                    $loop=false;

                    $postQuery = [
                        'sort' => 'CreateDate',
                        'pageSize' => $limit,
                        'page' => $last_page_number,
                        'shipDateStart' => $start_date,
                        'shipDateEnd' => $end_date,
                    ];

                    //need to update
                    $api_response = $this->shipstation->ApiCall('GET','/shipments',$api_key, $secret_key, json_encode($postQuery));


                    $last_page_number++;
                    if ( isset($api_response) && isset($api_response['shipments']) && !isset($api_response['error'])) {

                        $loop=true;

                        
                        foreach ($api_response['shipments'] as $key => $data) {
                            $this->insertUpdateShipment($user_id, $user_integration_id,'SHIP_NOTIFY',$data);

                        }

                        if ($api_response) {

                            if ($url_with_page) {
                                //Update last order fetch created time
                                $url_with_page->url = $last_page_number;
                                $url_with_page->save();

                            } 

                        }

                    }
                    if($loop_counter==$loop_breaker){
                        $loop=false;
                    }
                    $loop_counter++;

                }

            }

        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> GetShippingOrderDetails -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;

    }


    /* Pull shipstation order... currency using for get cancelled orders for delete GON in bp  */
    public function GetOrders($user_id, $user_integration_id,$order_status)
    {
        $returnstatus = true;
        try {

            $modifyDateEnd = date('Y-m-d\TH:i:s.0000000');

            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {
                
                $api_key = $this->mobj->encrypt_decrypt($account->app_id,'decrypt');
                $secret_key = $this->mobj->encrypt_decrypt($account->app_secret,'decrypt');

                $url_with_page = PlatformUrl::select('id','url')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'shipstation_get_order'])->first();

                if ($url_with_page) {
                    $modifyDateStart = $url_with_page->url;
                } else {

                    //get first shipstation order sync time.. 
                    $first_order_sync_time = PlatformOrder::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])
                    ->select('order_updated_at')->orderBy('order_updated_at','asc')->first();
                    if($first_order_sync_time) {
                        $modifyDateStart = date('Y-m-d\TH:i:s.0000000',strtotime($first_order_sync_time->order_updated_at));
                    } else {
                        $modifyDateStart = date('Y-m-d\TH:i:s.0000000');
                    }

                    // insert last order fetch created time
                    PlatformUrl::insert([
                        'user_id' => $user_id,
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'url' => $modifyDateStart,
                        'url_name' => 'shipstation_get_order',
                    ]);

                }

                $url = '/orders?orderStatus='.$order_status.'&sortBy=ModifyDate&sortDir=ASC&modifyDateStart='.$modifyDateStart.'&modifyDateEnd='.$modifyDateEnd;
                $api_response = $this->shipstation->ApiCall('GET',$url,$api_key,$secret_key,NULL);

                
                if ( isset($api_response) && isset($api_response['orders']) && !isset($api_response['error'])) {

                    foreach ($api_response['orders'] as $key => $data) {
                        $this->insertUpdateOrders($user_id, $user_integration_id,$data,$order_status);
                        //update modifyDateStart
                        $modifyDateStart = $data['modifyDate'];
                    }

                    if ($url_with_page) {
                        $url_with_page->url = date('Y-m-d\TH:i:s.0000000',strtotime($modifyDateStart));
                        $url_with_page->save();
                    } 


                } else {
                    if(isset($api_response['error'])) {
                        $returnstatus = 'api_error';
                    }
                }
      

            }


        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> GetOrders -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;

    }
    public function insertUpdateOrders($user_id, $user_integration_id,$data,$order_status)
    {
        // check order is created by our system.. if order exists in db
        $order_query = PlatformOrder::select('id','linked_id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId]);
        $order_query->where(function($query) use ($data){
            $query->where('api_order_id', $data['orderId'])->orWhere('order_number',$data['orderNumber']);
        });
        $order = $order_query->first();

        //if order found means its sales order / purchase order else transfer order , 
        if ($order && $order->linked_id) {

            $order->sync_status = 'Ready';
            $order->shipment_status = 'Ready';
            //delete order if cancelled 
            if($order_status=="cancelled") {
                $order->is_deleted = 1;
            }
            $order->order_updated_at = date('Y-m-d H:i:s');
            $order->api_updated_at = date('Y-m-d H:i:s');
            $order->save();
            
        }

    }


    /* EXECUTE FUNCTION -- START */
    public function ExecuteShipstationApi($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        $response = true;
        try {

            // \Storage::disk('local')->append('shipstation_log.txt', 'ExecuteShipstationApi Call time: ' . date('Y-m-d H:i:s'). ' method :'.$method . ' event : '.$event );

            if ($method == 'MUTATE' && $event == 'SALESORDER') {
                $response = $this->syncSalesOrder($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id, $destination_platform_id);
            } 
            else if ($method == 'MUTATE' && $event == 'TRANSFERORDER') {
                $response = $this->syncTransferOrder($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
            } 
            else if ($method == 'GET' && $event == 'WAREHOUSE') {
                $response = $this->createUpdateWarehouseFromShipstation($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
            } 
            else if ($method == 'GET' && $event == 'STORE') {
                $response = $this->createUpdateStoreFromShipstation($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
            } 
            elseif ($method == 'GET' && $event == 'SHIPMENT') {
                if ($is_initial_sync) {
                    $response = $this->createWebhookForTrackingInformation($user_id, $user_integration_id, $is_initial_sync);
                } else {
                    $response = $this->processShipmentWebhook($user_id, $user_integration_id);
                }
                //Not in use currency shipping details are receiving from webhook
                // else{
                //     $response = $this->GetShippingOrderDetails($user_id, $user_integration_id);
                // }
            } 
            elseif ($method == 'GET' && $event == 'CANCELLEDORDERS') {

                if (!$is_initial_sync) {
                    $response = $this->GetOrders($user_id, $user_integration_id,'cancelled');
                }
            } 
            elseif ($method == 'GET' && $event == 'SHIPPINGMETHOD') {
                $response = $this->createUpdateCarriersFromShipstation($user_id, $user_integration_id);
            } 

            return $response;

        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipstationController -> ExecuteShipstationApi -> " . $e->getMessage());
            return $e->getMessage();
        }
    }
    /* EXECUTE FUNCTION -- END */

    
    //shipstation_test 
    public function test()
    {   
    
        $user_id = 109;
        $user_integration_id = 613;

        // $user_id = 152;
        // $user_integration_id = 684;

        $source_platform_name = 'brightpearl';
        $platform_workflow_rule_id = 187;
        $user_workflow_rule_id = 1185;
        $record_id = 568402;
        $is_initial_sync = false;


        $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
        $api_key = $this->mobj->encrypt_decrypt($account->app_id,'decrypt');
        $secret_key = $this->mobj->encrypt_decrypt($account->app_secret,'decrypt');

        // $response = $this->createUpdateCarriersFromShipstation($user_id, $user_integration_id);
        // dd($response);


        // $orderNumber = '64031/1';
        // $url = '/orders?orderNumber='.$orderNumber;
        // $api_response = $this->shipstation->ApiCall('GET',$url,$api_key,$secret_key,NULL);
        // dd($api_response);

        // $testApiCall = $this->shipstation->ApiCall('GET','/webhooks',$api_key, $secret_key,NULL);
        // dd($testApiCall);

        // $test = $this->syncSalesOrder($is_initial_sync, $user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
        // dd($test);

        // $test = $this->syncTransferOrder($is_initial_sync, $user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
        // dd($test);

  
        // $response = $this->createWebhookForTrackingInformation($user_id, $user_integration_id, true);
        

        // $response = $this->GetShippingOrderDetails($user_id, $user_integration_id);
        // dd($response);

        // $response = $this->processShipmentWebhook($user_id, $user_integration_id);
        // dd($response);
    

        // $test = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->SyncTrackingInformation($user_id, $user_integration_id, 188, 1186, 'shipstation', "Ready", NULL);
        // dd($test);

        // $test = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetSOGoodOutNoteCreated($user_id, $user_integration_id, 'shipstation', [], 1, false, "Pending", true, 1226, 206);
        //  dd($test);


        // $UserWorkFlowRuleID = '';
        // $sync_start_date = '';
        // $getflowEvents = $this->wfsnip->getWorkflowEvents($UserWorkFlowRuleID);
        // if($getflowEvents && $getflowEvents->sync_start_date)
        // {
        //     $sync_start_date = str_replace(' ', 'T', trim($getflowEvents->sync_start_date));
        // }

        // $cancelledOrders = $this->GetOrders($user_id, $user_integration_id,'cancelled');
        // dd($cancelledOrders);

        // $salesOrderLines = PlatformOrder::find(560726)->platformOrderLine->toArray();
        // dd($salesOrderLines);

        // $test = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->ProcessShipmentInfomation($user_id, 'shipstation', $user_integration_id, "Pending");
        // dd($test);
        
        // $test = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->CreateOrDeleteWebhook($user_id, $user_integration_id, ['good_out_note_deleted'], 1, 'shipstation');
        // dd($test);
        
        // $test = app('App\Http\Controllers\Brightpearl\BrightPearlApiSubController')->deleteGoodsoutNote(false,$user_id, $user_integration_id, 'shipstation', 1341, NULL);
        // dd($test);

         //Get GON created
        // $test = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetSOGoodOutNoteCreated(152, 684, 'shipstation', ['good_out_note_deleted'], 3, 0, "Pending", false, 1310, 187);

        //Process GON
        // $test = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetPOGoodsInNotes(152, 684, 1310, ['goodsinnote'], 3, 0, 'shipstation',"Pending");
        // dd($test);
        
        
    }

    
}