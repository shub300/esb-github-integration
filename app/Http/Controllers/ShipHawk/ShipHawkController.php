<?php

namespace App\Http\Controllers\ShipHawk;

use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\MainModel;
use App\Models\PlatformAccount;
use App\Models\PlatformCustomFieldValue;
use App\Models\PlatformField;
use App\Models\PlatformLookup;
use App\Models\PlatformObject;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformProduct;
use App\Models\PlatformUrl;
use App\Models\PlatformWebhookInformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Lang;
class ShipHawkController
{
    const PLATFORM = 'shiphawk';
    public $ConnectionHelper, $platformId, $mapping, $mobj, $log;
    private $shipHawkServices, $user_integration_id;

    public function __construct()
    {
        $this->ConnectionHelper = new ConnectionHelper();
        $this->mobj = new MainModel();
        $this->log = new Logger();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::PLATFORM);
        $this->mapping = new FieldMappingHelper();
    }

    // Auth::Start
    public function InitiateShiphawkAuth(Request $request)
    {
        $platform = self::PLATFORM;
        return view("pages.apiauth.shiphawk_auth", compact('platform'));
    }

    public function ConnectShiphawkAuth(Request $request)
    {
        $data = [];
        // validation
        $validator = Validator::make($request->all(), [
            'account_name' => 'required|unique:platform_accounts,account_name,NULL,id,platform_id,' . $this->platformId . '|max:100',
            'account_name' => 'required|max:100',
            'environment' => 'required',
            'api_key' => 'required',
        ], [
            'account_name.required' => 'Account Name is required.',
            'account_name.unique' => 'Account Name is already taken.',
            'account_name.max' => 'Account Name should be of maximum 100 characters.',
            'environment.required' => 'Environment is required.',
            'api_key.required' => 'Api Key is required.',
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
        $environment = $validated->environment;
        // encrypt the data
        $api_key = $this->mobj->encrypt_decrypt($validated->api_key);
        // current user
        $user_data = Session::get('user_data');
        $user_id = $user_data['id'];
        // check for user account
        $checkAccount = PlatformAccount::select('id')->where(['user_id' => $user_id, 'platform_id' => $this->platformId, 'account_name' => $accountname, 'env_type' => $environment])->first();
        if ($checkAccount) {
            $data['status_code'] = 0;
            $data['status_text'] = 'Account already in use.';
        } else {
            $this->startService((object) ['app_secret' => $api_key, 'env_type' => $environment]);
            if ($this->shipHawkServices->checkForConnectedAPICredential()) {
                // add new account
                $newaccount = PlatformAccount::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'account_name' => $accountname, 'app_secret' => $api_key, 'env_type' => $environment, 'allow_refresh' => 0]);
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

    protected function startService($account)
    {
        $this->shipHawkServices = new ShipHawkServices($account->app_secret, $account->env_type);
    }
    // Auth:End

    public function syncSalesOrder($is_initial_sync, $user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id)
    {
        $returnstatus = true;
        try {
            // get the account sub domain
            $this->user_integration_id = $user_integration_id;
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {
                $this->startService($account);
                if ($is_initial_sync) {
                    return true;
                } else {
                    // source platform
                    $source_platform = PlatformLookup::where(['platform_id' => $source_platform_name, 'status' => 1])->select('id', 'platform_name')->first();
                    if ($source_platform) {
                        $source_platform_id = $source_platform->id;
                        // object id
                        $object = PlatformObject::where(['name' => 'sales_order', 'status' => 1])->select('id')->first();
                        $location_object = PlatformObject::where(['name' => 'location', 'status' => 1])->select('id')->first();
                        $order_status_object = PlatformObject::where(['name' => 'order_status', 'status' => 1])->select('id')->first();
                        $shipping_method_object = PlatformObject::where(['name' => 'shipping_method', 'status' => 1])->select('id')->first();
                        $channel_object = PlatformObject::where(['name' => 'channel', 'status' => 1])->select('id')->first();

                        if ($object && $location_object && $order_status_object) {
                            $object_id = $object->id;
                            $location_object_id = $location_object->id;
                            $order_status_object_id = $order_status_object->id;
                            $shipping_method_object_id = $shipping_method_object->id;
                            $channel_object_id = $channel_object->id;
                            if ($record_id) {
                                $shipment_status = $sync_status = 'Failed';

                            } else {
                                $shipment_status = $sync_status = 'Ready';

                            }
                            $limit = 20;
                            $parent_orders = PlatformOrder::where(['platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id, 'sync_status' => $sync_status, 'shipment_status' => $shipment_status]);
                            if ($record_id) {
                                $parent_orders = $parent_orders->where('platform_order.id', $record_id);
                            }
                            $parent_orders = $parent_orders->limit($limit)->get();

                            if ($parent_orders) {
                                /* Get default order warehouse for shiphawk */
                                $OrderWarehouseId = null;
                                $defaultSelectedWarehouse = $this->mapping->getMappedDataByName($this->user_integration_id, null, "order_warehouse", ['api_id']);

                                if (isset($defaultSelectedWarehouse->api_id)) {
                                    $OrderWarehouseId = $defaultSelectedWarehouse->api_id;
                                }
                                /* ------------------------------------------- */
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
                                        $shipment = PlatformOrderShipment::where(['platform_id' => $parent_order->platform_id, 'user_integration_id' => $parent_order->user_integration_id, 'platform_order_id' => $parent_order->id])->select('id', 'shipment_id', 'shipping_method', 'warehouse_id')->first();
                                        if (!$shipment && isset($parent_order->order_number)) {
                                            $order_number = explode("/", $parent_order->order_number);
                                            if (is_array($order_number) && count($order_number) == 2) {
                                                $shipment = PlatformOrderShipment::where(['platform_id' => $parent_order->platform_id, 'user_integration_id' => $parent_order->user_integration_id, 'order_id' => $order_number[0], 'shipment_sequence_number' => $order_number[1]])->select('id', 'shipment_id', 'shipping_method', 'warehouse_id')->first();
                                            }
                                        }

                                        if ($shipment) {
                                            $apidata = $this->createOrderObjectForAPI($parent_order, $shipment, $source_platform->platform_name, $order_status_object_id, $shipping_method_object_id, $channel_object_id);
                                        } else {
                                            $apidata = [];
                                        }

                                        $salesOrderAddresses = PlatformOrder::find($parent_order->id)->platformOrderAddress->toArray();
                                        $salesOrderLines = PlatformOrder::find($parent_order->id)->platformOrderLine->toArray();

                                        $count = (!empty($salesOrderAddresses) || !empty($salesOrderLines)) ? ((count($salesOrderAddresses) > count($salesOrderLines)) ? count($salesOrderAddresses) : count($salesOrderLines)) : 0;
                                        if ($count) {
                                            for ($x = 0; $x < $count; $x++) {
                                                if (isset($salesOrderAddresses[$x]) && isset($salesOrderAddresses[$x]['id'])) {
                                                    $parent_orderaddress = PlatformOrderAddress::find($salesOrderAddresses[$x]['id']);
                                                    if (isset($parent_orderaddress->id)) {
                                                        $apidata = $this->createOrdersAddressObjectForAPI($parent_orderaddress, $shipment, $OrderWarehouseId, $apidata);
                                                    }
                                                }
                                                if (isset($salesOrderLines[$x]) && isset($salesOrderLines[$x]['id'])) {
                                                    $parent_orderline = PlatformOrderLine::find($salesOrderLines[$x]['id']);
                                                    if (isset($parent_orderline->id)) {
                                                        if ($shipment) {
                                                            $apidata = $this->createOrdersLinesObjectForAPI($parent_orderline, $parent_order, $shipment, $apidata, $location_object_id);
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        $destination_api_order_id = null;
                                        if ($parent_order->linked_id) {
                                            $destination_platform_order = PlatformOrder::select('api_order_id')->where('id', $parent_order->linked_id)->first();
                                            if ($destination_platform_order) {
                                                $destination_api_order_id = $destination_platform_order->api_order_id;
                                            }
                                        }
                                        //dd($apidata);
                                        \Log::channel('webhook')->info("shiphawk -" . $user_id . " Integration " . $user_integration_id . " body: " . print_r($apidata, true) . " Created Date : " . date('Y-m-d H:i:s'));

                                        $apiResponse = $this->shipHawkServices->createOrdersForShiphawk($apidata, $destination_api_order_id);
                                        if (is_array($apiResponse) && isset($apiResponse['id']) && !isset($apiResponse['error'])) {
                                            $orderdata = $this->createOrderArrayForDatabase($parent_order);
                                            if ($parent_order->linked_id == 0) {
                                                $orderdata += ['user_id' => $user_id, 'user_workflow_rule_id' => $user_workflow_rule_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_customer_id' => $parent_order->platform_customer_id, 'linked_id' => $parent_order->id];
                                                $child_order = PlatformOrder::create($orderdata);
                                            } else {
                                                PlatformOrder::where('id', $parent_order->linked_id)->update($orderdata);
                                                $child_order = PlatformOrder::find($parent_order->linked_id);
                                            }
                                            $child_order->api_order_id = $apiResponse['id'];
                                            $child_order->order_number = $apiResponse['id'];
                                            $child_order->order_status = $apiResponse['status'];
                                            $child_order->order_updated_at = date('Y-m-d H:i:s');
                                            $child_order->warehouse_id = ((isset($apiResponse['warehouse']) && is_array($apiResponse['warehouse'])) ? (isset($apiResponse['warehouse']['code']) ? $apiResponse['warehouse']['code'] : null) : null);
                                            $child_order->save();
                                            // parent order
                                            $parent_order->sync_status = 'Synced';
                                            $parent_order->shipment_status = 'Synced';
                                            $parent_order->order_updated_at = date('Y-m-d H:i:s');
                                            $parent_order->linked_id = $child_order->id;
                                            $parent_order->save();
                                            $message = "Order synced successfully.";
                                            $statusForSync = 'success';
                                            // orderline
                                            $api_order_line = $apiResponse['order_line_items'];
                                            $update_count = count($api_order_line);
                                            if ($update_count) {
                                                for ($y = 0; $y < $update_count; $y++) {
                                                    if (isset($api_order_line[$y]['id'])) {
                                                        $parent_orderline = PlatformOrderLine::find($api_order_line[$y]['source_system_id']);
                                                        $orderlinedata = $this->createOrdersLinesArrayForDatabase($parent_orderline);
                                                        if ($parent_orderline->linked_id === 0) {
                                                            $orderlinedata += [
                                                                'linked_id' => $parent_orderline->id,
                                                                'platform_order_id' => $child_order->id,
                                                            ];
                                                            $child_orderline = PlatformOrderLine::create($orderlinedata);
                                                            $parent_orderline->linked_id = $child_orderline->id;
                                                        } else {
                                                            PlatformOrderLine::where('id', $parent_orderline->linked_id)->update($orderlinedata);
                                                            $child_orderline = PlatformOrderLine::find($parent_orderline->linked_id);
                                                        }
                                                        $child_orderline->api_order_line_id = $api_order_line[$y]['id'];
                                                        $child_orderline->save();
                                                        $parent_orderline->save();
                                                    }
                                                }
                                            }
                                            $returnstatus = true;
                                        } else {
                                            if (is_array($apiResponse) && isset($apiResponse['error'])) {
                                                $message = is_array($apiResponse['error']) ? implode(',', $apiResponse['error']) : $apiResponse['error'];
                                            } else {
                                                $message = "Order failed to sync";
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
            \Log::error($user_integration_id . " -> ShipHawkController -> syncSalesOrder -> " . $e->getMessage());
            return $e->getMessage();
        }
    }

    public function createUpdateWarehouseFromShiphawk(
        $is_initial_sync,
        $user_id,
        $user_integration_id,
        $source_platform_name,
        $platform_workflow_rule_id,
        $user_workflow_rule_id,
        $record_id
    ) {
        $returnstatus = true;
        try {
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {
                $this->startService($account);
                $object = PlatformObject::where(['name' => 'warehouse', 'status' => 1])->select('id')->first();
                if ($object) {
                    $object_id = $object->id;
                    $warehouses = $this->shipHawkServices->getWarehouse();
                    $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $object_id]);
                    if ($warehouses) {
                        foreach ($warehouses as $warehouse) {
                            $warehouseData = $wareData = [];
                            $wareData = $this->createWarehouseArrayForDatabase($warehouse);
                            $warehouseData = [
                                'user_id' => $user_id,
                                'platform_object_id' => $object_id,
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $user_integration_id,
                            ];
                            $checkWarehouseInDatabase = PlatformObjectData::where($warehouseData)->where([
                                'api_id' => $wareData['api_id'],
                            ])->first();
                            if ($checkWarehouseInDatabase) {
                                PlatformObjectData::find($checkWarehouseInDatabase->id)->update($wareData);
                            } else {
                                $warehouseData = $warehouseData + $wareData;
                                PlatformObjectData::create($warehouseData);
                            }
                        }
                    } else {
                        $returnstatus = "No data of warehouse found.";
                    }
                } else {
                    $returnstatus = "No object found.";
                }
            } else {
                $returnstatus = "No credential found.";
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipHawkController -> createUpdateWarehouseFromShiphawk -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }
    /* Pull Shipment from shiphawk */
    public function GetShipments($user_id, $user_integration_id, $status = "Pending")
    {
        $returnstatus = true;
        try {
            $limit = 25;
            $this->user_integration_id = $user_integration_id;
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {
                $this->startService($account);
                $source_orders = PlatformOrder::select('id', 'api_order_id', 'shipment_status')->where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'shipment_status' => $status, 'order_type' => "SO"])->take($limit)->orderBy('order_updated_at')->get();
                if (count($source_orders) > 1) {
                    $shipment_instance = new PlatformOrderShipment();
                    // foreach($source_orders as $order){

                    //     // Select the brightpearl inserted shipment or the parent shipment data
                    //     $shipment = $shipment_instance->where([
                    //         'user_integration_id' => $user_integration_id,
                    //         'platform_order_id' => $order->linked_id,
                    //         // 'shipment_id' => $data['order_number'],
                    //     ])->first();
                    //     if ($shipment) {
                    //         // Check if the shipment of shiphawk side is already in the database
                    //         // In my case shipment is always in the creation side, their no module for updating the shipment
                    //         if ($shipment->linked_id) {

                    //            $shipment_instance->find($shipment->linked_id)->update(['shipment_status' => $data['status'], 'shipment_id' => $data['id'], 'tracking_info' => $data['tracking_number']]);
                    //         } else {
                    //             // Here the shipment will get created in link with the parent shipment
                    //             $newcarrier = isset($data['carrier_code']) ? $data['carrier_code'] . (isset($data['service_code']) ? ', ' . $data['service_code'] : '') : null;
                    //             $newshipment = $shipment_instance->create([
                    //                 'user_id' => $shipment->user_id,
                    //                 'platform_id' => $this->platformId,
                    //                 'user_integration_id' => $user_integration_id,
                    //                 'shipment_id' => $data['id'],
                    //                 'platform_order_id' => $order->id,
                    //                 'order_id' => $order->api_order_id,
                    //                 'shipment_status' => $data['status'],
                    //                 'tracking_info' => $data['tracking_number'],
                    //                 'carrier_code' => $newcarrier,
                    //                 'shipping_method' => $newcarrier,
                    //                 'sync_status' => 'Ready',
                    //                 'linked_id' => $shipment->id
                    //             ]);
                    //             $shipment->linked_id = $newshipment->id;
                    //             $shipment->save();
                    //             $order->platform_order_shipment_id = $newshipment->id;
                    //         }
                    //         $order->shipment_status = 'Ready';
                    //         $order->shipment_api_status = $data['status'];
                    //         // $order->total_price = $data['total_price'];
                    //         $order->save();
                    //         $returnstatus = true;
                    //     } else {
                    //         $message = "No Shipment found";
                    //     }

                    //     /* Update each order in loop for next cycle */
                    //     $order->order_updated_at=date('Y-m-d H:i:s');
                    //     $order->save();
                    // }
                }

            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipHawkController -> createUpdateWarehouseFromShiphawk -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }
    public function getShipmentForOrdersFromShiphawk(Request $request, $user_integration_id)
    {
        $returnstatus = false;
        $message = 'Done';
        try {
            if ($request->isMethod('post')) {
                $data = $request->getContent();
                $data = json_decode($data, true);
                \Log::channel('webhook')->info("Shiphawk Shipment - UserIntegration=" . $user_integration_id . " Data " . print_r($data, true) . " Created Date : " . date('Y-m-d H:i:s'));
                if ($data) {
                    $events = ['shipment.create_from_order'];
                    if (isset($data['event']) && isset($data['id']) && in_array($data['event'], $events) && !empty($data['tracking_number']) && in_array($data['status'],['ordered','in_transit','delivered'])) {
                        // Select Order inserted in shiphawk
                        $order = PlatformOrder::where(['api_order_id' => $data['order_id'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->first();
                        if ($order) {
                            // Select the brightpearl inserted shipment or the parent shipment data
                            $shipment = PlatformOrderShipment::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_order_id' => $order->linked_id,
                                // 'shipment_id' => $data['order_number'],
                            ])->first();
                            if ($shipment) {

                                $newcarrier = isset($data['carrier_code']) ? $data['carrier_code'] . (isset($data['service_code']) ? ', ' . $data['service_code'] : '') : null;

                                // Check if the shipment of shiphawk side is already in the database
                                // In my case shipment is always in the creation side, their no module for updating the shipment
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

                                    if(!empty($tracking_infos) &&  $shipOrderId){
                                        if(!in_array(trim($data['tracking_number']), $tracking_infos)){
                                            
                                            \Log::info( "OrderShipmentSequenceNumber: ".json_encode( $data ) );

                                            //starting to insert shipment if order has more than 1 shipment    
                                            $shipmentLatestSequence = PlatformOrderShipment::where('order_id',$shipOrderId)->orderBy('shipment_sequence_number', 'DESC')->pluck('shipment_sequence_number')->first();

                                            PlatformOrderShipment::create([
                                                'user_id' => $shipment->user_id,
                                                'platform_id' => $this->platformId,
                                                'user_integration_id' => $user_integration_id,
                                                'shipment_id' => $data['id'],
                                                'platform_order_id' => $order->id,
                                                'order_id' => $order->api_order_id,
                                                'shipment_status' => $data['status'],
                                                'tracking_info' => $data['tracking_number'],
                                                'carrier_code' => $newcarrier,
                                                'shipment_sequence_number' => $shipmentLatestSequence + 1,
                                                'shipping_method' => $newcarrier,
                                                'sync_status' => 'Ready',
                                                'linked_id' => $shipment->id,
                                            ]);
                                            $order->shipment_status = 'Ready';
                                            $order->shipment_api_status = $data['status'];
                                            $order->save();

                                        }

                                    }

                                } else {
                                    // Here the shipment will get created in link with the parent shipment
                                    $newshipment = PlatformOrderShipment::create([
                                        'user_id' => $shipment->user_id,
                                        'platform_id' => $this->platformId,
                                        'user_integration_id' => $user_integration_id,
                                        'shipment_id' => $data['id'],
                                        'platform_order_id' => $order->id,
                                        'order_id' => $order->api_order_id,
                                        'shipment_status' => $data['status'],
                                        'tracking_info' => $data['tracking_number'],
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
                                    $order->shipment_api_status = $data['status'];
                                    $order->save();
                                }

                                $returnstatus = true;
                            } else {
                                $message = "No Shipment found";
                            }
                        } else {
                            $message = "SH - No order data.";
                        }
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
            \Log::error($user_integration_id . " -> ShipHawkController -> getShipmentForOrdersFromShiphawk -> " . $e->getMessage());
            $message = $e->getMessage();
        }
        return $returnstatus;
    }

    public function createWebhookForTrackingInformation($user_id, $user_integration_id, $is_initial_sync)
    {
        $returnstatus = true;
        try {
            // get the account sub domain
            $this->user_integration_id = $user_integration_id;
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {
                $this->startService($account);
                if ($is_initial_sync) {
                    $find=PlatformWebhookInformation::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'description' => 'shipment.status_update,shipment.tracking_update,shipment.create_from_order'])->first();
                    if(!$find){
                    $webhook = $this->shipHawkServices->createOrUpdateWebhook('shipment', $user_integration_id);
                    if (isset($webhook['id'])) {
                        $webhookdetails = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_id' => $webhook['id']];
                        $checkWebhook = PlatformWebhookInformation::where($webhookdetails)->first();
                        if ($checkWebhook) {
                            return true;
                        } else {
                            $webhookdetails['description'] = (is_array($webhook['events'])) ? implode(',', $webhook['events']) : $webhook['events'];
                            PlatformWebhookInformation::create($webhookdetails);
                        }
                    } else {
                        $returnstatus = 'Webhook is not created.';
                    }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipHawkController -> createWebhookForTrackingInformation -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

    /* EXTRA FUNCTIONS -- START */
    private function createWarehouseArrayForDatabase($warehouse)
    {
        $warehouseData = [
            'api_id' => $warehouse['id'],
            'api_code' => $warehouse['code'],
            'name' => isset($warehouse['address']['name']) ? $warehouse['address']['name'] : $warehouse['address']['company'],
            'status' => 1,
        ];
        return $warehouseData;
    }

    private function getOrderShipmentStatus($platform_order_id, $user_id, $user_integration_id, $source_platform_id)
    {
        $totalOrderQty = PlatformOrderLine::where([
            'platform_order_id' => $platform_order_id,
        ])->whereNotNull([
            'api_product_id',
            'sku',
        ])->sum('qty');

        $conditions = [
            'user_id' => $user_id,
            'platform_id' => $source_platform_id,
            'user_integration_id' => $user_integration_id,
            'sync_status' => 'Synced',
            'platform_order_id' => $platform_order_id,
        ];
        $shipments_ids = PlatformOrderShipment::where($conditions)->select('id')->pluck('id')->toArray();
        $totalShippedQty = PlatformOrderShipmentLine::whereIn('platform_order_shipment_id', $shipments_ids)->sum('quantity');

        if ($totalShippedQty < $totalOrderQty) {
            return 'Partial';
        }
        return 'Synced';
    }

    private function createShipmentArrayForDatabase(array $response, PlatformOrder $order)
    {
        $shipmentArray = [
            // "shipment_id" => $shipment->shipment_id,
            "sync_status" => 'Pending',
            "platform_order_id" => $order->id,
            "order_id" => $order->api_order_id,
            // "warehouse_id" => $shipment->warehouse_id,
            // "shipment_transfer" => $shipment->shipment_transfer,
            // "shipment_status" => $shipment->shipment_status,
            // "boxes" => $shipment->boxes,
            // "tracking_info" => $shipment->tracking_info,
            // "shipping_method" => $shipment->shipping_method,
            // "carrier_code" => $shipment->carrier_code,
            // "ship_class" => $shipment->ship_class,
            // "realease_date" => $shipment->realease_date,
            // "created_on" => $shipment->created_on,
            // "weight" => $shipment->weight,
            // "created_by" => $shipment->created_by,
            // "tracking_url" => $shipment->tracking_url,
            // "shipment_file_name" => $shipment->shipment_file_name,
        ];
        return $shipmentArray;
    }

    private function createOrderArrayForDatabase(PlatformOrder $order)
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

    private function createOrderObjectForAPI(PlatformOrder $order, PlatformOrderShipment $shipment, string $sourceName, $order_status_object_id, $shipping_method_object_id, $channel_object_id)
    {
        $apidata = [];
        if ($shipment) {
            $apidata['order_number'] = $shipment->shipment_id;
        } else {
            $apidata['order_number'] = $order->order_number;
        }

        $apidata['source_system'] = $sourceName;
        $apidata['source'] = $sourceName;
        $apidata['source_system_id'] = $order->api_order_reference; //strtolower($sourceName);
        /* Find Shipping Method Name */
        if (isset($shipment->shipping_method) && !empty($shipment->shipping_method)) {
            $checkShippingMethodName = PlatformObjectData::where(['user_integration_id' => $order->user_integration_id, 'platform_id' => $order->platform_id, 'platform_object_id' => $shipping_method_object_id, 'api_id' => $shipment->shipping_method])->select('name')->first();

            if ($checkShippingMethodName) {
                $apidata['requested_shipping_details'] = $checkShippingMethodName->name; //assign shipping method name

            }
        }
        /* Find Channel Name */
        $channelName = null;
        if (isset($order->order_extra_information->api_channel_id)) {
            $checkChannelName = PlatformObjectData::where(['user_integration_id' => $order->user_integration_id, 'platform_id' => $order->platform_id, 'platform_object_id' => $channel_object_id, 'api_id' => $order->order_extra_information->api_channel_id])->select('name')->first();
            if ($checkChannelName) {
                $channelName = $checkChannelName->name; //assign channel name

            }
        }

        $order_status = (int) $order->order_status;
        if (is_int($order_status) && $order_status) {
            $checkOrderStatus = PlatformObjectData::where(['user_integration_id' => $order->user_integration_id, 'platform_id' => $order->platform_id, 'platform_object_id' => $order_status_object_id, 'api_id' => $order_status])->select('name')->first();
            if ($checkOrderStatus) {
                $order_status = $checkOrderStatus->name;
            }
        } else {
            $order_status = $order->order_status;
        }

        if ($order->linked_id && $order->is_deleted == 1) {
            /* If Order Deleted in source platform set status="cancelled" as well name="Cancelled" */
            $apidata['status'] = "cancelled";
            $order_status = "Cancelled";
        }

        $apidata['source_system_meta'] = ['status' => $order_status];
        $apidata['channel_name'] = $channelName;
        $apidata['source_system_processed_at'] = date("Y/m/d");
        $apidata['total_price'] = $order->total_amount;
        if ($order->total_tax) {
            $apidata['tax_price'] = $order->total_tax;
        }
        $apidata['items_price'] = $order->net_amount;
        $apidata['reference_numbers'] = $this->createReferenceObjectForAPI($order, $sourceName);
        return $apidata;
    }

    private function createReferenceObjectForAPI(PlatformOrder $parent_order, string $sourceName)
    {
        $apidata = [];
        $apidata[] = ['code' => 'other_id', 'name' => $sourceName . ' Order # ', 'value' => $parent_order->api_order_id];
        $defaultSelectedCustomFields = $this->mapping->getMappedDataByName($this->user_integration_id, null, "sales_order", ['name'], 'regular', null, 'multi');
        if (!$defaultSelectedCustomFields) {
            $defaultSelectedCustomFields = $this->mapping->getMappedDataByName($this->user_integration_id, null, "sales_order_custom_fields", ['name'], 'regular', null, 'multi');
        }
        $object = PlatformObject::where(['name' => 'sales_order', 'status' => 1])->first();
        if ($object) {
            $customs = PlatformCustomFieldValue::where(['user_integration_id' => $parent_order->user_integration_id, 'platform_id' => $parent_order->platform_id, 'record_id' => $parent_order->id])->get();
            if ($customs) {
                foreach ($customs as $custom) {
                    $field = PlatformField::find($custom->platform_field_id);
                    if ($field) {
                        if ($defaultSelectedCustomFields) {
                            if (!in_array($field->name, $defaultSelectedCustomFields)) {
                                continue;
                            }
                        }
                        $apidata[] = ['code' => 'other_id', 'name' => $field->description, 'value' => $custom->field_value];
                    }
                }
            }
        }
        return $apidata;
    }

    private function createOrdersAddressArrayForDatabase(PlatformOrderAddress $address)
    {
        $data = [
            "address_type" => $address->address_type,
            "address_name" => $address->address_name,
            "address_id" => $address->address_id,
            "firstname" => $address->firstname,
            "lastname" => $address->lastname,
            "company" => $address->company,
            "address1" => $address->address1,
            "address2" => $address->address2,
            "address3" => $address->address3,
            "address4" => $address->address4,
            "city" => $address->city,
            "state" => $address->state,
            "postal_code" => $address->postal_code,
            "country" => $address->country,
            "email" => $address->email,
            "phone_number" => $address->phone_number,
            "ship_speed" => $address->ship_speed,
            "carrier_code" => $address->carrier_code,
        ];
        return $data;
    }

    private function createOrdersAddressObjectForAPI(PlatformOrderAddress $address, $shipment, $OrderWarehouseId, $apidata)
    {
        // ORIGIN ADDRESS IF DEFAULT WAREHOUSE IS SELECTED IN THE INTEGRATION

        /* --Find one to one warehouse mapping for shiphawk--- */
        if (isset($shipment->warehouse_id)) {
            $warehouseId = $this->mapping->getMappedDataByName($this->user_integration_id, null, "order_warehouse", ['api_id'], 'regular', $shipment->warehouse_id);
            //dd($order->warehouse_id);
            if ($warehouseId) {
                $OrderWarehouseId = $warehouseId->api_id;
            }
        }
        /* ---------------------------------------------- */
        if ($OrderWarehouseId) {
            $warehouse = $this->shipHawkServices->getWarehouseById($OrderWarehouseId);
            if (isset($warehouse['code'])) {
                $apidata['warehouse_code'] = $warehouse['code'];
                $apidata['origin_address'] = $this->createAddressObjectForOrdersAddressObjectForAPIWithWarehouseSelected($warehouse);
            }
        }
        if ($address->address_type == 'shipping') {
            $apidata['destination_address'] = $this->createAddressObjectForOrdersAddressObjectForAPI($address, 'destination');
        } elseif ($address->address_type == 'billing') {
            $apidata['billing_address'] = $this->createAddressObjectForOrdersAddressObjectForAPI($address, 'billing');
        } elseif ($address->address_type == 'shippedfrom') {
            $apidata['origin_address'] = $this->createAddressObjectForOrdersAddressObjectForAPI($address, 'origin');
        }
        return $apidata;
    }

    private function createAddressObjectForOrdersAddressObjectForAPIWithWarehouseSelected($warehouse)
    {
        $addressArr = [];
        $code = $warehouse['code'];
        $warehouse = $warehouse['address'];
        array_shift($warehouse);
        $warehouse['address_type'] = 'origin';
        $warehouse['code'] = $code;
        $addressArr = $warehouse;
        return $addressArr;
    }

    private function createAddressObjectForOrdersAddressObjectForAPI(PlatformOrderAddress $address, $type)
    {
        $addressArr = [];
        $addressArr['name'] = $address->address_name;
        $addressArr['company'] = $address->company;
        $addressArr['street1'] = isset($address->address1) ? $address->address1 : '';
        $addressArr['street2'] = isset($address->address2) ? $address->address2 : '';
        $addressArr['city'] = isset($address->address3) ? $address->address3 : '';
        $addressArr['state'] = isset($address->address4) ? $address->address4 : '';
        $addressArr['zip'] = isset($address->postal_code) ? $address->postal_code : '';
        $addressArr['country'] = $address->country;
        $addressArr['phone_number'] = $address->phone_number;
        $addressArr['email'] = $address->email;
        $addressArr['address_type'] = $type;
        return $addressArr;
    }

    private function createOrdersLinesArrayForDatabase(PlatformOrderLine $orderline)
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

    private function createOrdersLinesObjectForAPI(PlatformOrderLine $orderline, PlatformOrder $order, PlatformOrderShipment $shipment, $apidata, $location_object_id)
    {
        if ($orderline->row_type == "ITEM" || $orderline->row_type == "GIFTCARD" ) {
            $product = PlatformProduct::where([
                'platform_id' => $order->platform_id,
                'user_integration_id' => $order->user_integration_id,
                'api_product_id' => $orderline->api_product_id,
            ])->first();
            $locationCheck = [
                'platform_id' => $order->platform_id,
                'user_id' => $order->user_id,
                'user_integration_id' => $order->user_integration_id,
                'platform_object_id' => $location_object_id,
            ];
            $apidata['order_line_items'][] = $this->createLinesObjectForOrdersLinesObjectForAPI($orderline, $shipment, $product, $locationCheck);
        } elseif ($orderline->row_type == "SHIPPING") {
            $shipping_price = isset($apidata['shipping_price']) ? $apidata['shipping_price'] : 0;
            $apidata['shipping_price'] = $shipping_price + $orderline->total;
        } elseif ($orderline->row_type == "TAX") {
            $tax_price = isset($apidata['tax_price']) ? $apidata['tax_price'] : 0;
            $apidata['tax_price'] = $tax_price + $orderline->total;
        }
        return $apidata;
    }

    private function createLinesObjectForOrdersLinesObjectForAPI(PlatformOrderLine $orderline, PlatformOrderShipment $shipment, $product, array $locationCheck)
    {
        $lineItems = [];
        $shipmentLine = PlatformOrderShipmentLine::where([
            'platform_order_shipment_id' => $shipment->id,
            'row_id' => $orderline->api_order_line_id,
        ])->first();
        $lineItems['source_system_id'] = $orderline->id;
        if (is_array($locationCheck) && $shipmentLine && $shipmentLine->location_id) {
            $bin_data = PlatformObjectData::where($locationCheck)->where('api_id', $shipmentLine->location_id)->select('name')->first();
            if ($bin_data) {
                $lineItems['bin_number'] = $bin_data->name;
            }
        }
        $lineItems['name'] = $orderline->product_name;
        $lineItems['sku'] = $orderline->sku;
        $lineItems['quantity'] = ($shipmentLine) ? $shipmentLine->quantity : $orderline->qty;
        $lineItems['value'] = $orderline->total;
        $lineItems['weight'] = ($product) ? strval($product->weight) : '0';
        if ($product) {
            if ($product->platformProductAttribute) {
                $lineItems['length'] = $product->platformProductAttribute->lenght;
                $lineItems['width'] = $product->platformProductAttribute->height;
                $lineItems['height'] = $product->platformProductAttribute->width;
            }
        }
        return $lineItems;
    }
    /* EXTRA FUNCTIONS -- END */
    public function updateDateTimeISOFormat($dateTime, $sign = "+")
    {      
         $date_slice=explode($sign,$dateTime);
        if(isset($date_slice[0])){
            return trim($date_slice[0]);
        } 
      //return (strstr($dateTime, $sign) ? substr($dateTime, 0, strpos($dateTime, $sign)) : $dateTime);
    }
    public function getLastOrderDateTime($userId, $userIntegrationId)
    {
        /* If Order last time not found  | set 1 hr minus from current time*/

        $get_order_date = $this->platformOrder->select('api_updated_at')
            ->where([
                'platform_id' => $this->platformId,
                'user_integration_id' => $userIntegrationId,
                'order_type' => 'SO',
            ])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")
            ->first();

        if (!empty($get_order_date)) {
            $sync_start_date = date(DATE_ISO8601, strtotime($get_order_date->api_updated_at . '-2 seconds'));
            $sync_start_date = $this->updateDateTimeISOFormat($sync_start_date);
        } else {
            $sync_start_date = date(DATE_ISO8601, strtotime('-30 min'));

            $sync_start_date = $this->updateDateTimeISOFormat($sync_start_date);
        }
        return $sync_start_date;
    }
    /* Pull Shiphawk Shipping Orders/Details */
    public function GetShippingOrderDetails($user_id, $user_integration_id)
    {
        $returnstatus = true;
        try {
            $limit = 100;
            $start_date='2022-07-05';
            $end_date=date('Y-m-d');
            $loop_breaker=5;
            $this->user_integration_id = $user_integration_id;
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
            if ($account) {
                $this->startService($account);
                $url_with_page = PlatformUrl::select('id','url')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'shiphawk_shipment_page_number'])->first();
                if ($url_with_page) {
                        if($url_with_page->url>10){
                            $last_page_number = 1;
                        }else{
                            $last_page_number = $url_with_page->url;
                        }

                } else {
                    $last_page_number = 1;
                }
                $loop=true;$loop_counter=1;
                while($loop) {
                    $loop=false;
                    $postQuery = [
                        'sort' => '-created_at',
                        'per_page' => $limit,
                        'page' => $last_page_number,
                        'filters' =>
                        [
                            ["operator" => "one_of",
                                "field" => "status",
                                "values" => ["ordered", "delivered", "in_transit"],
                            ],

                            [
                                'operator' => 'range',
                                'field' => 'create_date',
                                'values' =>
                                [
                                    $start_date,
                                    $end_date,
                                ],
                            ],
                        ],
                        'match' => 'all',
                    ];
                    $api_response = $this->shipHawkServices->getShipmentOrderDetails($postQuery);

                    $last_page_number++;
                    if (is_array($api_response) && !empty($api_response) && !isset($api_response['error'])) {
                        $loop=true;
                        foreach ($api_response as $key => $data) {
                            if (isset($data['id']) && !empty($data['tracking_number'])) {
                                // Select Order inserted in shiphawk
                                $order = PlatformOrder::select('id','linked_id','api_order_id','platform_order_shipment_id','shipment_api_status','shipment_status')->where(['api_order_id' => $data['order_id'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->first();
                                if ($order) {
                                    // Select the brightpearl inserted shipment or the parent shipment data
                                    $shipment = PlatformOrderShipment::select('id','linked_id',)->where([
                                        'user_integration_id' => $user_integration_id,
                                        'platform_order_id' => $order->linked_id,
                                        // 'shipment_id' => $data['order_number'],
                                    ])->first();
                                    if ($shipment) {
                                        $newcarrier = isset($data['carrier_code']) ? $data['carrier_code'] . (isset($data['service_code']) ? ', ' . $data['service_code'] : '') : null;

                                        // Check if the shipment of shiphawk side is already in the database
                                        // In my case shipment is always in the creation side, their no module for updating the shipment
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

                                            if(!empty($tracking_infos) &&  $shipOrderId){
                                                if(!in_array(trim($data['tracking_number']), $tracking_infos)){
                                                   
                                                    \Log::info( "OrderShipmentSequenceNumberBackup: ".json_encode( $data ) );
                                                    //starting to insert shipment if order has more than 1 shipment                                                 
                                                     $shipmentLatestSequence = PlatformOrderShipment::where('order_id',$shipOrderId)->orderBy('shipment_sequence_number', 'DESC')->pluck('shipment_sequence_number')->first();

                                                    PlatformOrderShipment::create([
                                                        'user_id' => $shipment->user_id,
                                                        'platform_id' => $this->platformId,
                                                        'user_integration_id' => $user_integration_id,
                                                        'shipment_id' => $data['id'],
                                                        'platform_order_id' => $order->id,
                                                        'order_id' => $order->api_order_id,
                                                        'shipment_status' => $data['status'],
                                                        'tracking_info' => $data['tracking_number'],
                                                        'carrier_code' => $newcarrier,
                                                        'shipment_sequence_number' => $shipmentLatestSequence + 1,
                                                        'shipping_method' => $newcarrier,
                                                        'sync_status' => 'Ready',
                                                        'linked_id' => $shipment->id,
                                                    ]);
                                                    $order->shipment_status = 'Ready';
                                                    $order->shipment_api_status = $data['status'];
                                                    $order->save();
                                                }

                                            }
                                        } else {
                                            // Here the shipment will get created in link with the parent shipment
                                            
                                            $newshipment = PlatformOrderShipment::create([
                                                'user_id' => $user_id,
                                                'platform_id' => $this->platformId,
                                                'user_integration_id' => $user_integration_id,
                                                'shipment_id' => $data['id'],
                                                'platform_order_id' => $order->id,
                                                'order_id' => $order->api_order_id,
                                                'shipment_status' => $data['status'],
                                                'tracking_info' => $data['tracking_number'],
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
                                            $order->shipment_api_status = $data['status'];
                                            $order->save();
                                        }
                                        $returnstatus = true;
                                    }
                                }
                            }

                        }
                        if ($api_response) {
                            if ($url_with_page) {
                                //Update last order fetch created time
                                $url_with_page->url = $last_page_number;
                                $url_with_page->save();
                            } else {
                                //insert last order fetch created time
                                PlatformUrl::insert([
                                    'user_id' => $user_id,
                                    'platform_id' => $this->platformId,
                                    'user_integration_id' => $user_integration_id,
                                    'url' => $last_page_number,
                                    'url_name' => 'shiphawk_shipment_page_number',
                                ]);
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
            \Log::error($user_integration_id . " -> ShipHawkController -> createUpdateWarehouseFromShiphawk -> " . $e->getMessage());
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;

    }
    /* EXECUTE FUNCTION -- START */
    public function ExecuteShipHawkApi($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        $response = true;
        try {
            if ($method == 'MUTATE' && $event == 'SALESORDER') {
                $response = $this->syncSalesOrder($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
            } elseif ($method == 'GET' && $event == 'WAREHOUSE') {
                $response = $this->createUpdateWarehouseFromShiphawk($is_initial_sync, $user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
            } elseif ($method == 'GET' && $event == 'SHIPMENT') {
                $response = true;
                if ($is_initial_sync) {
                    $response = $this->createWebhookForTrackingInformation($user_id, $user_integration_id, $is_initial_sync);
                }else{
                    $response = $this->GetShippingOrderDetails($user_id, $user_integration_id);
                }
            } elseif ($method == 'MUTATE' && $event == 'ORDERSTATUS') {
                $response = true;
            }
            return $response;
        } catch (\Exception $e) {
            \Log::error($user_integration_id . " -> ShipHawkController -> ExecuteShipHawkApi -> " . $e->getMessage());
            return $e->getMessage();
        }
    }
    /* EXECUTE FUNCTION -- END */
}