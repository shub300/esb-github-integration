<?php

namespace App\Http\Controllers\JamesAndJames;

use Illuminate\Http\Request;
use Auth;
use DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\JamesAndJames\Api\JamesApi;
use App\Helper\MainModel;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\ConnectionHelper;
use App\Models\PlatformAccount;
use App\Models\PlatformUrl;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderLine;
use App\Models\PlatformCustomer;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformObjectData;
use App\Models\PlatformProduct;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Carbon\Carbon;
use Lang;

class JamesApiController extends JamesApi
{
    /**
     * Default name of the controller platform name
     */
    private const PLATFORMNAME = 'jamesandjames';

    public $connectionHelper, $mainModel, $logger, $fieldMapHelper, $platformId;
    public function __construct()
    {
        $this->connectionHelper = new ConnectionHelper();
        $this->mainModel = new MainModel();
        $this->logger = new Logger();
        $this->fieldMapHelper = new FieldMappingHelper();
        // Set the platform ID
        $this->platformId = $this->connectionHelper->getPlatformIdByName(self::PLATFORMNAME);
    }

    /**
     * Auth function return the view page of authentication
     *
     * @param $request Request class
     */
    public function InitiateJamesAuth(Request $request)
    {
        $platform = self::PLATFORMNAME;
        return view("pages.apiauth.auth_james", compact('platform'));
    }

    /**
     * Auth function to connect to the platform with response to the front
     *
     * @param $request Request class
     *
     * @return json_encoded data to be return with 2 parameters as `status_code` and `status_text`
     */
    public function ConnectJames(Request $request)
    {
        $response = ['status_code' => 0]; // array for return response with status_code default to 0 (false)

        if ($this->mainModel->checkHtmlTags($request->all())) {
            $response['status_text'] = Lang::get('tags.validate');
            return $response;
        }

        try {
            $validator = Validator::make($request->all(), [
                'jamesAccountName' => 'required',
                'jamesApiKey' => 'required',
                'jamesChannelApiKey' => 'required',
            ], [
                'jamesAccountName.required' => 'Account name is required.',
                'jamesApiKey.required' => 'API key is required.',
                'jamesChannelApiKey.required' => 'API key is required.',
            ]);
            if ($validator->fails()) {
                $statustext = array_values(json_decode($validator->messages()->toJson(), true))[0][0];
            } else {
                $validated = array_map(function ($val) {
                    return htmlspecialchars($val);
                }, $validator->validated());
                $validated = (object) $validated;

                // Set and Decrypt the values for security measures
                $accountName = $validated->jamesAccountName;
                $apiKey = $this->mainModel->encrypt_decrypt($validated->jamesApiKey);
                $channelApiKey = $this->mainModel->encrypt_decrypt($validated->jamesChannelApiKey);

                // Get Current User Id
                $user_data = Session::get('user_data');
                $userId = $user_data['id'];

                // Check for the account
                $account = PlatformAccount::select('id')->where([
                    'user_id' => $userId,
                    'platform_id' => $this->platformId,
                    'account_name' => $accountName
                ])->count();
                if ($account === 0) {
                    $conncetion = static::checkAuthCredential($validated);
                    if (isset($conncetion['status_code']) && $conncetion['status_code'] == 1) {
                        // Add the given data
                        $newAccount = PlatformAccount::create([
                            'user_id' => $userId,
                            'platform_id' => $this->platformId,
                            'account_name' => $accountName,
                            'app_id' => $apiKey,
                            'app_secret' => $channelApiKey
                        ]);
                        if ($newAccount->id) {
                            $response['status_code'] = true;
                            $statustext = 'Account Connected.';
                        } else {
                            $statustext = 'Account not created! Please try again.';
                        }
                    } else {
                        $statustext = 'Please check for the given credential.';
                        if (isset($conncetion['status_data'])) {
                            $statustext = $conncetion['status_data'];
                        }
                    }
                } else {
                    $statustext = "Account already connected.";
                }
            }
            $response['status_text'] = $statustext;
        } catch (\Exception $e) {
            $response['status_text'] = $e->getMessage();
        }
        return $response;
    }

    /**
     * Function to create or update transfer order from Snowflake to Peoplevox using API call
     *
     * @param $userId, user_id of this account
     * @param $userIntegrationId, unique id to identify integration between two platforms
     * @param $sourcePlatformName, name of the source platform from where PO is being created. here 'snowflake'
     * @param $userWorkflowRuleId, it contains data sync flag whether data properly synced or not
     * @param $recordId, unique id to identify integration between two platforms
     *
     * @return, bool or string data to be return with either TRUE if succeed else error message
     */
    public function createUpdateTransferOrders($userId, $userIntegrationId, $sourcePlatformName, $userWorkflowRuleId, $platformWorkflowRuleId, $recordId = 0)
    {
        $returnStatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $sourcePlatformId = $this->connectionHelper->getPlatformIdByName($sourcePlatformName);
                $objectId = $this->connectionHelper->getObjectId('transfer_order');  // stag-objectId:96
                if ($sourcePlatformId && $objectId) {
                    $limit = 40;
                    $orderType = 'TO';
                    $queryPOrdIds = PlatformOrder::where([
                        'platform_id' => $sourcePlatformId,
                        'user_integration_id' => $userIntegrationId,
                        'is_deleted' => 0, // This is basically accept only not deleted purchase orders
                        'order_type' => $orderType
                    ]);
                    if ($recordId) {
                        $queryPOrdIds->where(['platform_order.id' => $recordId, 'platform_order.sync_status' => 'Failed']);
                    } else {
                        $queryPOrdIds->where('platform_order.sync_status', 'Ready');
                    }
                    $queryPOrdIds->select('id', 'api_order_reference', 'order_date', 'currency');
                    $platformPOrdIds = $queryPOrdIds->limit($limit)->orderBy('platform_order.updated_at', 'ASC')->get();

                    if (count($platformPOrdIds)) { // check if there are vendor to sync
                        $postDataCreds = static::createPostData($accountInfo, $orderType);
                        if (!$postDataCreds || ($postDataCreds && !count($postDataCreds))) {
                            return "Api key is invalid or undefined.";
                        }

                        // Add some basic informations which are common for all orders
                        $postDataCreds["order"] = [
                            "callback_url" => route('callback.order', ['user_id' => $userId, 'user_integration_id' => $userIntegrationId]),
                            "asn_callback_url" => route('callback.asn', ['user_id' => $userId, 'user_integration_id' => $userIntegrationId])
                            // "signed_for"=> false, // optional
                        ];

                        $processedOrderIds = [];
                        foreach ($platformPOrdIds as $pfTOrder) { // Loop the purchase orders
                            $processedOrderIds[] = $pfTOrder->id;
                            $postData = $postDataCreds;

                            $ordShipment = PlatformOrderShipment::where('platform_order_id', $pfTOrder->id)->first();
                            if ($ordShipment) {

                                // Get mapped warehouse id
                                $destWarehouseId = $sourceWarehouseId = null;
                                $sourceWarehouseObj = PlatformObjectData::where('id', $ordShipment->warehouse_id)->select('api_id')->first();
                                if($sourceWarehouseObj){
                                    $sourceWarehouse = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, 0, "inventory_warehouse", ['api_id'], 'regular', $sourceWarehouseObj->api_id);
                                    if($sourceWarehouse){
                                        $sourceWarehouseId = $sourceWarehouse->api_id;
                                    }
                                }
                                $destWarehouseObj = PlatformObjectData::where('id', $ordShipment->to_warehouse_id)->select('api_id')->first();
                                if($destWarehouseObj){
                                    $destWarehouse = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, 0, "inventory_warehouse", ['api_id'], 'regular', $destWarehouseObj->api_id);
                                    if($destWarehouse){
                                        $destWarehouseId = $destWarehouse->api_id;
                                    }
                                }

                                if( !$sourceWarehouseId || !$destWarehouseId ) {
                                    $errorMsg = "Source or destination warehouse not found. These fields are must to create a transfer order.";
                                    $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $errorMsg, $pfTOrder);
                                    continue;
                                }

                                $warehouseAddress = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, $platformWorkflowRuleId, "transfer_order_warehouse", ['api_id'], "cross", $destWarehouseId);
                                $gotWhFullAddress = false;
                                $name = $phone = $address = $city = $country = $postcode = null;
                                if($warehouseAddress){
                                    $arrWhAddress = explode(' | ', $warehouseAddress->custom_data);
                                    if( count($arrWhAddress) == 7 ) // if containing all: Name,Phone,Address,City,State,Zip,Country
                                    {
                                        $gotWhFullAddress = true;
                                        $name = $arrWhAddress[0]; // Name
                                        $phone = $arrWhAddress[1]; // Phone
                                        $address = $arrWhAddress[2]; // Address
                                        $city = $arrWhAddress[3]; // City
                                        $county = $arrWhAddress[4]; // State
                                        $postcode = $arrWhAddress[5]; // Postal Code
                                        $country = $arrWhAddress[4]; // Country
                                    }
                                }
                                if( !$gotWhFullAddress ){
                                    $errorMsg = "Billing address not found mapped with warehouse.";
                                    $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $errorMsg, $pfTOrder);
                                    continue;
                                }

                                // Calculate total cost amt
                                $totalCost = PlatformOrderShipmentLine::where('platform_order_shipment_id', $ordShipment->id)->sum(DB::raw('price * quantity'));

                                $postData["order"] += [
                                    "client_ref" => $pfTOrder->api_order_reference,
                                    "date_placed" => $pfTOrder->order_date,
                                    "total_value" => number_format($totalCost, 2),
                                    "currency_code" => $pfTOrder->currency,
                                    "warehouse_id" => $sourceWarehouseId,
                                    "transfer_to_warehouse_id" => $destWarehouseId
                                ];

                                // Set billing address
                                $postData["order"]["BillingContact"] = [
                                    "name"=> $name,
                                    //"email"=> "recipient@order.com",
                                    "phone"=> $phone,
                                    "address"=> $address,
                                    "city"=> $city,
                                    "county"=> $county,
                                    "country"=> $country,
                                    "postcode"=> $postcode,
                                ];

                                // Set line-item detail
                                $lineItems = static::setLineItemsInPostReq($ordShipment->id, $orderType);
                                if (count($lineItems)) {
                                    $postData["order"]["items"] = $lineItems;
                                }

                                \Storage::append('james_createUpdateTO.txt', 'post_data: ' . print_r($postData, true));
                                $response = static::makeAPICall('POST', '/order', json_encode($postData));
                                \Storage::append('james_createUpdateTO.txt', 'response: ' . print_r($response, true));
                                if (isset($response['success']) && ($response['success'] === true || $response['success'] == 1) && (isset($response['errors']) && count($response['errors']) == 0) ) {
                                    // $orderReference = isset($response['order_ref']) ? $response['order_ref'] : null;
                                    $linkingResponse = self::manageOrderLinkingInDB($userId, $userIntegrationId, $pfTOrder->id, $orderType);
                                    \Storage::append('james_createUpdateTO.txt', 'linkingResponse: ' . print_r($linkingResponse, true));
                                    if ($linkingResponse === true) { // returns bool:true as response, if linking complete successfully
                                        $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, 'success', $pfTOrder);
                                    } else{
                                        $error = $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $linkingResponse, $pfTOrder);
                                        $returnStatus = $error;
                                    }
                                } else {
                                    $error = $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $pfTOrder);
                                    $returnStatus = $error;
                                }

                            }
                        }

                        if( count($processedOrderIds) ){
                            PlatformOrder::whereIn('id', $processedOrderIds)->update([ 'updated_at' => date('Y-m-d H:i:s') ]);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error($userIntegrationId . " -> JamesApiController -> createUpdateTransferOrders -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    /**
     * Function to create or update purchase order from Snowflake to Peoplevox using API call
     *
     * @param $userId, user_id of this account
     * @param $userIntegrationId, unique id to identify integration between two platforms
     * @param $sourcePlatformName, name of the source platform from where PO is being created. here 'snowflake'
     * @param $userWorkflowRuleId, it contains data sync flag whether data properly synced or not
     * @param $recordId, unique id to identify integration between two platforms
     *
     * @return, bool or string data to be return with either TRUE if succeed else error message
     */
    public function createUpdatePurchaseOrders($userId, $userIntegrationId, $sourcePlatformName, $userWorkflowRuleId, $platformWorkflowRuleId, $recordId = 0)
    {
        $returnStatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $sourcePlatformId = $this->connectionHelper->getPlatformIdByName($sourcePlatformName);
                $objectId = $this->connectionHelper->getObjectId('purchase_order'); // stag-objectId:6
                if ($sourcePlatformId && $objectId) {
                    $limit = 40;
                    $orderType = 'PO';
                    $queryPOrdIds = PlatformOrder::where([
                        'platform_id' => $sourcePlatformId,
                        'user_integration_id' => $userIntegrationId,
                        'is_deleted' => 0, // This is basically accept only not deleted purchase orders
                        'order_type' => $orderType
                    ]);
                    if ($recordId) {
                        $queryPOrdIds->where(['platform_order.id' => $recordId, 'platform_order.sync_status' => 'Failed']);
                    } else {
                        $queryPOrdIds->where('platform_order.sync_status', 'Ready');
                    }
                    $queryPOrdIds->select('id', 'api_order_id', 'api_order_reference', 'platform_customer_id', 'delivery_date', 'shipping_method', 'warehouse_id');
                    $platformPOrdIds = $queryPOrdIds->limit($limit)->orderBy('platform_order.updated_at', 'ASC')->get();

                    if (count($platformPOrdIds)) { // check if there are vendor to sync
                        $postDataCreds = static::createPostData($accountInfo, $orderType);
                        if (!$postDataCreds || ($postDataCreds && !count($postDataCreds))) {
                            return "Api key is invalid or undefined.";
                        }

                        // Add some basic informations which are common for all orders
                        $postDataCreds["asn"] = [
                            "callback_url" => route('callback.asn', ['user_id' => $userId, 'user_integration_id' => $userIntegrationId]),
                        ];

                        $processedOrderIds = [];
                        foreach ($platformPOrdIds as $pfPOrder) { // Loop the purchase orders
                            $processedOrderIds[] = $pfPOrder->id;
                            $postData = $postDataCreds;

                            // Get vendor information by id
                            $vendor_info = PlatformCustomer::where('id', $pfPOrder->platform_customer_id)->select("customer_name AS vendor_name")->first();

                            // Get mapped warehouse id
                            $destWarehouseName = null;

                            // Here second argument (platform_workflow_rule_id) is passing zero because we are getting common warehouse mapping of PO and TO
                            $destWh = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, 0, "inventory_warehouse", ['api_id'], 'regular', $pfPOrder->warehouse_id);
                            if ($destWh && $destWh->api_id) {
                                $destWarehouseInfo = PlatformObjectData::where([
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' => $this->platformId,
                                    'api_id' => $destWh->api_id
                                ])->select('name')->first();
                                if($destWarehouseInfo){
                                    $destWarehouseName = $destWarehouseInfo->name;
                                }
                            }

                            $postData["asn"] += [
                                //"number" => $pfPOrder->api_order_id,
                                //"reference" => $pfPOrder->api_order_reference,
                                "number" => $pfPOrder->api_order_reference,
                                "reference" => $pfPOrder->api_order_id,
                            ];

                            // Supplier name
                            if (isset($vendor_info->vendor_name)) {
                                $postData["asn"]["supplier_name"] = trim($vendor_info->vendor_name);
                            }

                            // Delivery date
                            if (isset($pfPOrder->delivery_date)) {
                                $postData["asn"]["date_delivery_due"] = date("Y-m-d", strtotime($pfPOrder->delivery_date));
                            }

                            // Carrier code
                            if (isset($pfPOrder->carrier_code)) {
                                $postData["asn"]["carrier"] = $pfPOrder->carrier_code;
                            }

                            if( $destWarehouseName ){
                                $postData["asn"]["warehouse"] = $destWarehouseName;
                            }

                            /*// Shipping method
                            if (isset($pfPOrder->shipping_method)) {
                                $postData["asn"]["carrier"] = $pfPOrder->shipping_method;
                            }

                            if (isset($pfPOrder->tracking_info)) {
                                $postData["asn"]["tracking_number"] = $pfPOrder->tracking_info;
                            }*/

                            // Set line-item detail
                            $lineItems = static::setLineItemsInPostReq($pfPOrder->id, $orderType);
                            if (count($lineItems)) {
                                $postData["asn"]["items"] = $lineItems;
                            }

                            \Storage::append('james_createUpdatePO.txt', 'post_data: ' . print_r($postData, true));
                            $response = static::makeAPICall('POST', '/asn/create', json_encode($postData));
                            \Storage::append('james_createUpdatePO.txt', 'response: ' . print_r($response, true));
                            //$response = '{"test":false,"reference":"202305001-XYZ123","success":true,"errors":[]}'; /// for testing and update handling
                            //$response = json_decode($response, true);

                            if (isset($response['success']) && ($response['success'] === true || $response['success'] == 1) && (isset($response['errors']) && count($response['errors']) == 0) ) {
                                $apiOrderId = isset($response['id']) ? $response['id'] : null;
                                $linkingResponse = self::manageOrderLinkingInDB($userId, $userIntegrationId, $pfPOrder->id, $orderType, $apiOrderId);
                                \Storage::append('james_createUpdatePO.txt', 'linkingResponse: ' . print_r($linkingResponse, true));
                                if ($linkingResponse === true) { // returns bool:true as response, if linking complete successfully
                                    $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, 'success', $pfPOrder);
                                } else{
                                    $error = $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $linkingResponse, $pfPOrder);
                                    $returnStatus = $error;
                                }
                            } else {
                                $error = $this->handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $pfPOrder);
                                $returnStatus = $error;
                            }

                        }

                        if( count($processedOrderIds) ){
                            PlatformOrder::whereIn('id', $processedOrderIds)->update([ 'updated_at' => date('Y-m-d H:i:s') ]);
                        }
                    }
                }else{
                    $returnStatus = "Object id not found for given flow.";
                }
            }
        } catch (\Exception $e) {
            Log::error($userIntegrationId . " -> JamesApiController -> createUpdatePurchaseOrders -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    /**
     * Function to make linking between Snowflake PO and the J&J PO
     * Need to create a copy of PO against J&J platform and make linking using their unique key (for further reference and update actions)
     *
     * @param $userId, the user's id with the current integration
     * @param $userIntegrationId, the user_integration id
     * @param $platformOrderId, unique key of platform order table using as a foreign key in platform_order_address table
     * @param $orderReference, PO reference received from J&J post call api response
     * */
    private function manageOrderLinkingInDB($userId, $userIntegrationId, $platformOrderId, $orderType = 'PO', $apiOrderId = null)
    {
        $returnStatus = true;
        // In api response vendor name comes in Reference field for identification
        try {
            if ($platformOrderId) {
                $sourceOrd = PlatformOrder::find($platformOrderId);
                if (!$sourceOrd) {
                    return 'Order information not found.';
                }

                $sourceOrderId = (isset($sourceOrd->api_order_id) && $sourceOrd->api_order_id) ? $sourceOrd->api_order_id : NULL;
                $apiOrderId = isset($apiOrderId) ? $apiOrderId : $sourceOrderId;
                $apiOrderReference = (isset($sourceOrd->api_order_reference) && $sourceOrd->api_order_reference) ? $sourceOrd->api_order_reference : NULL;
                $orderData = [
                    'api_order_reference' => $apiOrderReference,
                    'user_workflow_rule_id' => (isset($sourceOrd->user_workflow_rule_id) && $sourceOrd->user_workflow_rule_id) ? $sourceOrd->user_workflow_rule_id : NULL,
                    'delivery_date' => isset($sourceOrd->delivery_date) ? date("Y-m-d", strtotime($sourceOrd->delivery_date)) : NULL
                ];

                $destPlatformOrderId = null;
                if ($sourceOrd->linked_id === 0) {
                    $orderData += [
                        'user_id' => $userId,
                        'user_integration_id' => $userIntegrationId,
                        'platform_id' => $this->platformId,
                        'order_type' => $orderType,
                        'api_order_id' => $apiOrderId,
                        'linked_id' => $sourceOrd->id,
                    ];
                    $destOrd = PlatformOrder::create($orderData);
                    $sourceOrd->linked_id = $destPlatformOrderId = $destOrd->id;
                } else {
                    PlatformOrder::find($sourceOrd->linked_id)->update($orderData);
                    $destPlatformOrderId = $sourceOrd->linked_id;
                }
                $sourceOrd->sync_status = 'Synced';
                $sourceOrd->save();

                // Shipment Linking Section
                if($orderType == 'TO'){
                    $destShipmentId = $sourceShipmentId = null;
                    $sorceShip = PlatformOrderShipment::where('platform_order_id', $platformOrderId)->select('id')->first();
                    if ($sorceShip && $destPlatformOrderId) {
                        //$shipData = [ 'sync_status'=>'Synced' ];
                        $shipData =  [];

                        if ($sorceShip->linked_id === 0) {
                            $shipData += [
                                'user_id' => $userId,
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $userIntegrationId,
                                'order_id' => $apiOrderId,
                                'shipment_id' => $apiOrderReference,
                                'type' => $orderType,
                                'linked_id' => $sorceShip->id,
                            ];
                            $destOrd = PlatformOrderShipment::create($shipData);
                            $sorceShip->linked_id = $destShipmentId = $destOrd->id;
                        } else {
                            //PlatformOrderShipment::find($sorceShip->linked_id)->update($shipData);
                            $destShipmentId = $sorceShip->linked_id;
                            //$sourceShipmentId = null;
                        }

                        // $sorceShip->sync_status = 'Synced';
                        $sorceShip->save();
                        $sourceShipmentId = $sorceShip->id;
                    }

                    // Shipment line linking section
                    if($sourceShipmentId && $destShipmentId){
                        $sourceShipLines = PlatformOrderShipmentLine::where('platform_order_shipment_id', $sourceShipmentId)->select('id')->get();
                        if( $sourceShipLines ){
                            foreach ($sourceShipLines as $key => $line) {
                                $lineInfo = PlatformOrderShipmentLine::wher([ 'platform_order_shipment_id'=>$destShipmentId, 'sku'=>$line->sku ])->select('id')->first();
                                if (!$lineInfo) {
                                    $shipLineData = [
                                        'platform_order_shipment_id' => $destShipmentId,
                                        'product_id' => $line->product_id ?? null,
                                        'sku' => $line->sku ?? null,
                                        'barcode' => $line->barcode ?? null,
                                        'currency' => $line->currency ?? null,
                                        'price' => $line->price ?? null,
                                        'quantity' => $line->quantity ?? null,
                                        //'sync_status' => 'Synced',
                                    ];
                                    PlatformOrderShipmentLine::create($shipLineData);
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error($userIntegrationId . " -> JamesApiController -> manageOrderLinkingInDB -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    /**
     * This is the function which handle the Purchase order receipt callback (containing received qty)
     *
     * @param $request, PO receipt callback data containing received qty against ordered PO
     */
    public function handleCallbackASN(Request $request)
    {
        $returnStatus = true;
        try {
            if ($request->isMethod('post')) {

                $userId = $request->query('user_id');
                $userIntegrationId = $request->query('user_integration_id');

                $responseBody = $request->getContent();
                $resultData = json_decode($responseBody, true);
                if (isset($resultData['number']) && isset($resultData['status']) && isset($resultData['discrepancies']) ) {
                    if( $resultData['status'] != 'complete' ){
                        return false;
                    }

                    $apiOrderId = $resultData['number'];
                    if($userId && $userIntegrationId){
                        $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
                        if ($accountInfo) {
                            $ordInfo = PlatformOrder::where([
                                'user_integration_id' => $userIntegrationId,
                                'platform_id' => $this->platformId,
                                //'api_order_id' => $apiOrderId,
                                'api_order_reference' => $apiOrderId,
                            ])->select('id', 'order_type', 'linked_id')->first();

                            if($ordInfo){
                                $orderType = $ordInfo->order_type;
                                /**
                                 * In Callback ASN, We need to only handle the discrepancies lines,
                                 * because in discrepancies array all those line-item are listed which qty is either partially fulfilled or not fulfilled
                                 * Apart from discrepancies list, we will consider fulfilled the rest of all the line-items.
                                 */
                                if( count($resultData['discrepancies']) ){
                                    $lineItemReady = false;

                                    if($orderType == 'TO'){
                                        $shipInfo = PlatformOrderShipment::where([
                                            'platform_order_id' => $this->platformId,
                                            //'order_id' => $apiOrderId,
                                            'shipment_id' => $apiOrderId,
                                        ])->select('id', 'order_type', 'linked_id')->first();

                                        if($shipInfo){
                                            $lineInfo = PlatformOrderShipmentLine::where([ 'platform_order_shipment_id'=>$shipInfo->id ])->select('id', 'sku', 'quantity')->get();
                                            if ($lineInfo->isNotEmpty()) {
                                                foreach ($lineInfo as $key => $line) {
                                                    $key = $this->findSkuPosition($resultData['discrepancies'], $line->sku);
                                                    if ($key === -1) {
                                                        $line->quantity = $line->quantity; // received full qty
                                                    }else if($key > -1){
                                                        $line->quantity = $resultData['discrepancies'][$key]["received"] ?? 0;
                                                    }
                                                    $line->sync_status = 'Ready';
                                                    $line->save();
                                                }

                                            }

                                            $shipInfo->sync_status = 'Ready';
                                            $shipInfo->save();
                                            $lineItemReady = true;
                                        }
                                    }else{
                                        $shipInfo = PlatformOrderShipment::where([
                                            'platform_order_id' => $this->platformId,
                                            'shipment_id' => $apiOrderId,
                                        ])->select('id', 'order_type', 'linked_id')->first();

                                        $shipData = [ 'sync_status'=>'Ready' ];
                                        if(!$shipInfo){
                                            $shipData += [
                                                'user_id' => $userId,
                                                'platform_id' => $this->platformId,
                                                'user_integration_id' => $userIntegrationId,
                                                'shipment_id' => $apiOrderId,
                                                'type' => $orderType,
                                                'linked_id' => 0,
                                            ];
                                            $shipInfo = PlatformOrderShipment::create($shipData);
                                        } else {
                                            $shipInfo->sync_status = 'Ready';
                                            $shipInfo->save();
                                        }

                                        // Create/Update shipment lines
                                        if($shipInfo->id){
                                            $lineInfo = PlatformOrderLine::where([ 'platform_order_id'=>$ordInfo->linked_id ])->select('id', 'sku', 'price', 'qty')->get();
                                            if ($lineInfo->isNotEmpty()) {
                                                foreach ($lineInfo as $key => $line) {
                                                    $shipLineData = [
                                                        'platform_order_shipment_id' => $shipInfo->id,
                                                        'sku' => $line->sku ?? null,
                                                        'price' => $line->price ?? null,
                                                        'quantity' => $line->qty ?? null,
                                                        'sync_status' => 'Ready'
                                                    ];

                                                    $key = $this->findSkuPosition($resultData['discrepancies'], $line->sku);
                                                    if ($key === -1) {
                                                        $shipLineData['quantity'] = $line->qty; // received full qty
                                                    }else if($key > -1){
                                                        $shipLineData['quantity'] = $resultData['discrepancies'][$key]["received"] ?? 0;
                                                    }
                                                    $line->sync_status = 'Ready';
                                                    $line->save();

                                                    PlatformOrderShipmentLine::create($shipLineData);
                                                }
                                            }

                                            // Make true if no error
                                            $lineItemReady = true;
                                        }
                                    }

                                    if($lineItemReady){
                                        $ordInfo->sync_status = 'Ready';
                                        $ordInfo->save();

                                        // Update parent order also if linked
                                        if($ordInfo->platform_order_id){
                                            PlatformOrder::find($ordInfo->platform_order_id)->update(['sync_status' => 'Ready']);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("JamesApiController -> handleCallbackASN -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    /**
     * Function to get list of items from uploaded csv file
     *
     * @param $userId, user_id of this account
     * @param $userIntegrationId, unique id to identify integration between two platforms
     *
     * @return, bool or string data to be return with either TRUE if succeed else error message
     */
    public function getProductFromCsvFile($userId, $userIntegrationId, $platformWorkflowRuleId)
    {
        $returnStatus = true;
        try {
            $prodJsonData = DB::table('platform_response_handler')->where([
                'platform_id' => $this->platformId,
                'user_integration_id' => $userIntegrationId,
                'url_name' => 'product_json'
            ])->count();

            if( $prodJsonData > 0 ){
                return $returnStatus;
            }

            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                // Get mapped product csv file information
                $productMappingFile = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, $platformWorkflowRuleId, "product_file_mapping", ['custom_data'], "default");
                if($productMappingFile){
                    $csvFilePath = trim($productMappingFile->custom_data);

                    $limit = 100;
                    $platformId = $this->platformId;

                    LazyCollection::make(function () use($csvFilePath) {
                        // $csvFilePath = storage_path($csvFilePath); // use if file stored in local server
                        $handle = fopen($csvFilePath, 'r');
                        while ($line = fgetcsv($handle)) {
                            yield $line;
                        }
                    })
                    ->chunk($limit) //split in chunk to reduce the number of queries
                    ->each(function ($lines) use($userId, $userIntegrationId, $platformId) {
                        $list = [];
                        foreach ($lines as $key => $line) {
                            if($key === 0){
                                continue; // to skip header
                            }

                            $sku = trim($line[6]) ?? null;
                            if ( $sku ) {
                                $list[] = [
                                    'barcode' => $line[1], // Barcode
                                    'api_created_at' => $line[3], // Date Created
                                    'product_name' => $line[4], // Name
                                    'price' => $line[5], // Sale Price
                                    'sku' => $line[6], // SKU
                                ];
                            }
                        }

                        if( count($list) ){
                            DB::table('platform_response_handler')->insert([
                                'user_id' => $userId,
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $userIntegrationId,
                                'url_name' => 'product_json',
                                'url' => json_encode($list)
                            ]);
                        }
                    });

                }
            }
        } catch (\Exception $e) {
            Log::error($userIntegrationId . " -> JamesApiController -> getProductFromCsvFile -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    public function storeProducts($userId, $userIntegrationId){
        $returnStatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $limit = 1;
                $productJson = DB::table('platform_response_handler')->where([
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $userIntegrationId,
                    'url_name' => 'product_json',
                    'status' => 0
                ])->select('id', 'url')->limit($limit)->get();
                if( $productJson->isNotEmpty() ){
                    foreach ($productJson as $prodData) {
                        $arrProduct = json_decode($prodData->url, true);

                        $cases = $skuToUpdate = $productList = [];
                        $existingSkuList = PlatformProduct::where(['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId])->pluck('sku')->toArray();

                        foreach ($arrProduct as $key => $product) {
                            $sku = $product['sku'];

                            if( in_array($sku, $existingSkuList) ){
                                $skuToUpdate[] = "'{$sku}'";
                                foreach ($product as $columnName => $columnValue) {
                                    if ($columnName === 'sku') {
                                        continue;
                                    }

                                    $cases[$columnName][] = "WHEN '{$sku}' THEN '{$columnValue}'";
                                }
                            } else{
                                array_push($productList, [
                                    "user_id" => $userId,
                                    "user_integration_id" => $userIntegrationId,
                                    "platform_id" => $this->platformId,
                                    "user_id" => $userId,
                                    "sku" => $product['sku'],
                                    "api_product_id" => $product['sku'],
                                    "api_variant_id" => $product['sku'],
                                    "product_status" => 1,
                                    "product_sync_status" => 'Ready',
                                    "product_name" => trim($product['product_name']) ?? null,
                                    "barcode" => isset($product['barcode']) ? number_format((float)$product['barcode'], 0, '.', '') : null,
                                    "price" => trim($product['price']) ?? null,
                                    "api_created_at" => trim($product['api_created_at']) ?? null,
                                ]);
                            }
                        }

                        if( count($skuToUpdate) ){
                            $skuList = implode(',', $skuToUpdate);
                            $updateCases = [];

                            foreach ($cases as $columnName => $columnCases) {
                                $caseStatements = implode(' ', $columnCases);
                                $updateCases[] = "`{$columnName}` = CASE `sku` {$caseStatements} END";
                            }

                            $updateQuery = "UPDATE platform_product SET " . implode(', ', $updateCases) . " WHERE `user_integration_id` = {$userIntegrationId} AND `platform_id` = '{$this->platformId}' AND `sku` IN ({$skuList})";

                            if (!empty($skuToUpdate)) {
                                \DB::update($updateQuery);
                            }
                        }

                        if( count($productList) ){
                            PlatformProduct::insert($productList);
                        }

                        DB::table('platform_response_handler')->where('id', $prodData->id)->update([ 'status'=>1 ]);
                        $existingSkuList = $cases = $skuToUpdate = $productList = []; // unset all array
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error($userIntegrationId . " -> JamesApiController -> storeProducts -> " . $e->getLine() . " -> " . $e->getMessage());
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }
    /**
     * Function to get sales order from james&James
     *
     * @param $userId, user_id of this account
     * @param $userIntegrationId, unique id to identify integration between two platforms
     *
     * @return, bool or string data to be return with either TRUE if succeed else error message
     */
    public function getSalesOrders($userId, $userIntegrationId)
    {
        \Storage::append('james_getSalesOrders.txt', "\r\n" . 'called @ ' . now());
        $returnStatus = true;
        try {
            $accountInfo = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['account_name', 'app_id', 'app_secret', 'access_token']); // get the account information for the integration
            if ($accountInfo) {
                $pageNo = 1;
                $pf_url = PlatformUrl::where([
                    'user_integration_id' => $userIntegrationId,
                    'platform_id' => $this->platformId, 'url_name' => 'so_pageNo', 'status' => 1
                ])->select('id', 'url', 'status')->first();

                if ($pf_url && $pf_url->status == 1) {
                    $pageNo = $pf_url->url;
                } else {
                    $pf_url = new PlatformUrl();
                    $pf_url->user_id = $userId;
                    $pf_url->user_integration_id = $userIntegrationId;
                    $pf_url->platform_id = $this->platformId;
                    $pf_url->url_name = 'so_pageNo';
                    $pf_url->url = $pageNo;
                    $pf_url->status = 1;
                    $pf_url->save();
                }

                $postData = static::createPostData($accountInfo, 'SO');
                if (!$postData || ($postData && !count($postData))) {
                    return "Api credentials are undefined.";
                }

                $postData['order_status'] = [ "despatched" ];
                $postData['order_type'] = [ "b2b" ];
                $postData['page'] = "$pageNo";
                \Storage::append('james_getSalesOrders.txt', 'postData' . print_r($postData, true));
                $response = static::makeAPICall('GET-WITH-POSTDATA', '/order', json_encode($postData));
                \Storage::append('james_getSalesOrders.txt', 'response' . print_r($response, true));
                if (isset($response['orders']) && count($response['orders'])) {
                    $salesOrders = $response['orders'];
                    foreach ($salesOrders as $key => $order) {
                        if( $order && isset($order['id']) ){
                            $objSo = PlatformOrder::where([
                                'user_integration_id' => $userIntegrationId,
                                'platform_id' => $this->platformId,
                                'api_order_id' => $order['id']
                            ])->select('id', 'sync_status')->first();
                            if ( !$objSo ) {
                                $objSo = new PlatformOrder();
                                $objSo->user_id = $userId;
                                $objSo->user_integration_id = $userIntegrationId;
                                $objSo->platform_id = $this->platformId;
                                $objSo->api_order_id = $order['id'];
                                $objSo->order_type = 'SO';
                                $objSo->sync_status = 'Ready';
                            }
                            $objSo->order_status = $order['status'];
                            $objSo->order_date = $order['updated_at'];
                            $objSo->api_order_reference = $order['client_ref'];
                            $objSo->save();

                            if( $objSo->id ){
                                /** Store Sales order lines */
                                $salesOrdersLines = $order['items'];
                                if (count($salesOrdersLines)) {
                                    foreach ($salesOrdersLines as $key => $line) {
                                        $lineId = (isset($line['id']) && $line['id']) ? $line['id'] : NULL;

                                        $ordLine = PlatformOrderLine::where(['platform_order_id'=>$objSo->id, 'api_order_line_id'=>$lineId])->select('id')->first();
                                        if ( !$ordLine ) {
                                            $ordLine = new PlatformOrderLine();
                                            $ordLine->platform_order_id = $objSo->id;
                                            $ordLine->api_order_line_id = $lineId;
                                        }
                                        $ordLine->api_product_id = $line['product_id'];
                                        $ordLine->qty = $line['quantity'];
                                        $ordLine->item_row_sequence = (int)$line['batch_number'];
                                        $ordLine->save();
                                    }
                                }
                            }
                        }
                    }

                    if ( isset($response['next_page']) && $pf_url ) { // because 100 is the default page limit
                        $pf_url->update(['url' => $response['next_page']]);
                    }
                } /*else {
                    $error = "Unable to connect, API call error.";
                    if (isset($response['error'])) {
                        $error = $response['error'];
                    }
                    $returnStatus = $error;
                }*/
            }
        } catch (\Exception $e) {
            $returnStatus = $e->getMessage();
        }
        return $returnStatus;
    }

    /**
     * To get the billing information from the database and set them in an array (for post request) then return the same
     *
     * @param $platform_order_id, unique key of platform order table using as a foreign key in platform_order_address table
     * */
    private static function setLineItemsInPostReq($parent_row_id, $type)
    {
        $returnLineData = [];
        if ($type == 'PO') {
            $lineItems = PlatformOrderLine::where(['platform_order_id' => $parent_row_id])
                ->select('sku', 'product_name', 'qty', 'price', 'total_tax', 'is_deleted')->get();
            if ($lineItems) {
                foreach ($lineItems as $key => $line) {
                    if ($line->is_deleted == 1) {
                        continue;
                    }

                    array_push($returnLineData, [
                        "client_ref" => $line->sku,
                        "quantity" => $line->qty,
                        "price" => $line->price,
                    ]);
                }
            }
        } else if ($type == 'TO') {
            $lineItems = PlatformOrderShipmentLine::where(['platform_order_shipment_id' => $parent_row_id])
                ->select('sku', 'product_id', 'quantity', 'price')->get();
            if ($lineItems) {
                foreach ($lineItems as $key => $line) {
                    array_push($returnLineData, [
                        "client_ref" => $line->sku,
                        "quantity" => $line->quantity,
                        "price" => $line->price,
                    ]);
                }
            }
        }

        return $returnLineData;
    }

    /** Handle API call error and maintain logs */
    public function handleErrorResponse($userId, $userIntegrationId, $userWorkflowRuleId, $sourcePlatformId, $objectId, $response, $pfPOrder)
    {
        $returnStatus = "Failed to sync, API call error or invalid post request.";
        if (isset($response['error'])) {
            if( is_array($response['error']) ){
                $returnStatus = implode(" | ", $response['error']);
            } else{
                $returnStatus = $response['error'];
            }
        } else if (isset($response['errors'])) {
            $returnStatus = implode(" | ", $response['errors']);
        } else if (isset($response)) {
            $returnStatus = $response;
            if ( is_array($response) || is_object($response) ) {
                $returnStatus = json_encode($response, true);
            }
        }

        if($response == 'success'){
            $sync_status = 'success';
            $pfPOrder->sync_status = 'Synced';
        } else{
            $sync_status = 'failed';
            $pfPOrder->sync_status = 'Failed';
        }

        $pfPOrder->updated_at = date('Y-m-d H:i:s');
        $pfPOrder->save();

        $this->logger->syncLog(
            $userId,
            $userIntegrationId,
            $userWorkflowRuleId,
            $sourcePlatformId,
            $this->platformId,
            $objectId,
            $sync_status,
            $pfPOrder->id,
            $returnStatus
        );

        return $returnStatus;
    }

    /** To find ey position of a value in given multidiamentioanal array */
    public function findSkuPosition($discrepancies, $sku) {
        foreach ($discrepancies as $key => $value) {
            if ($value['client_ref'] === $sku) {
                return $key;
            }
        }
        return -1; // SKU not found
    }

    /** ##### Common functions [end] ##### */

    /**
     * To manage function calling of diffeent module
     *
     * @param $method, for 'MUTATE' it's for creation of new data and for 'GET' to get any data from the platform
     * @param $event, the event for the function is initiated
     * @param $userId, the user's id with the current integration
     * @param $userIntegrationId, the user_integration id
     *
     * @return boolean data: either true or false
     */
    public function ExecuteEventJames($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        try {
            $response = true;
            if ($method == 'GET' && $event == 'POITEMRECEIPT') {
                $response = true;
            } else if ($method == 'GET' && $event == 'TRANSFERORDER') {
                $response = true;
            } else if ($method == 'GET' && $event == 'PRODUCT') {
                $resFileRead = $this->getProductFromCsvFile($user_id, $user_integration_id, $platform_workflow_rule_id);
                if( $resFileRead === true ){
                    $response = $this->storeProducts($user_id, $user_integration_id);
                }
            } else if ($method == 'GET' && $event == 'SALESORDER') {
                $response = true; // $this->getSalesOrders($user_id, $user_integration_id);
            } else if ($method == 'MUTATE' && $event == 'PURCHASEORDER') {
                if ($is_initial_sync == 0) {
                    $response = $this->createUpdatePurchaseOrders($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id);
                }
            } else if ($method == 'MUTATE' && $event == 'TRANSFERORDER') {
                if ($is_initial_sync == 0) {
                    $response = $this->createUpdateTransferOrders($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id);
                }
            }

            return $response;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}
