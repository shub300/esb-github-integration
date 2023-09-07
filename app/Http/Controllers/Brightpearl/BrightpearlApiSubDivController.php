<?php
namespace App\Http\Controllers\Brightpearl;

use App\Http\Controllers\Controller;
use App\Helper\Logger;
use App\Helper\MainModel;
use App\Models\PlatformOrder;
use App\Helper\ConnectionHelper;
use App\Models\PlatformCustomer;
use App\Helper\Api\BrightpearlApi;
use App\Helper\FieldMappingHelper;
use App\Models\PlatformAccountAdditionalInfo;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderRefund;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformProductInventoryCredit;
use Illuminate\Support\Facades\Config;

class BrightpearlApiSubDivController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $mobj, $bp,  $helper, $platformId, $map, $log;
    public static $myPlatform = 'brightpearl';
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->bp = new BrightpearlApi;
        $this->log = new Logger();
        $this->map = new FieldMappingHelper();
        $this->helper = new ConnectionHelper;

        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }
    
    /* Update Order Status of PO */
    public function PurchaseOrderStatusUpdate($userId, $userIntegrationId, $PlatformWorkFlowRuleID, $UserWorkFlowRuleID, $SourcePlatformId, $order_primary_id, $account)
    {

        $return_response = false;
        try {
            $object_id = $this->helper->getObjectId('purchase_order');
            $DestinationOrder = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->FindOrderIDByLinkedID($order_primary_id, ["linked_id"]);
            if ($DestinationOrder) {
                $SourceOrder = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->FindOrderIDByLinkedID($DestinationOrder->linked_id, ["api_order_id", "order_status"]);

                $OrderStatus = $this->map->getMappedDataByName($userIntegrationId, NULL, "get_order_status", ['api_id']);
                $OrderStatusID = isset($OrderStatus->api_id) ? $OrderStatus->api_id : NULL;

                if ($OrderStatusID) {

                    $changeStatus =  app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->UpdateOrderStatus($account, $SourceOrder->api_order_id, $OrderStatusID);

                    if (is_array($changeStatus)) {

                        if (!isset($changeStatus['errors'])) {
                            $this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Synced'], ['id' => $order_primary_id]);

                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'success', $order_primary_id, NULL);

                            $return_response = true;
                        } else if (isset($changeStatus['errors']) && is_array($changeStatus['errors'])) {

                            $error = $this->bp->handleResponseError($changeStatus);
                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $error);
                            $return_response =  $error;
                        } else {
                            $return_response = isset($changeStatus['response']) ? ($changeStatus['response']) : "API Error";
                        }
                    }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Update Sales Order Status */
    public function UpdateSalesOrderStatus($userId=NULL, $userIntegrationId=NULL, $PlatformWorkFlowRuleID=NULL, $UserWorkFlowRuleID=NULL, $SourcePlatformName=NULL, $RecordID=NULL)
    {
        $return_response = false;
        try{
            /* Get Source Platform Details */
            $SourcePlatformId = $this->helper->getPlatformIdByName($SourcePlatformName);

            $platform_account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'api_domain', 'account_name', 'app_id', 'app_secret']);
            if($platform_account && $SourcePlatformId)
            {
                $sales_order_object_id = $this->helper->getObjectId('sales_order');
                $order_status_object_id = $this->helper->getObjectId('order_status');

                $query = PlatformOrder::select('id', 'order_status', 'linked_id', 'is_deleted', 'is_voided', 'notes');
                if($RecordID)
                {
                    $query->where('id', $RecordID);
                }
                else
                {
                    $query->where(['user_integration_id'=>$userIntegrationId, 'platform_id'=>$SourcePlatformId, 'sync_status'=>'Ready', 'order_type'=>'SO']);
                }

                $platform_orders = $query->where('linked_id', '<>', 0)->orderBy('id', 'ASC')->take(25)->get();

                foreach($platform_orders as $platform_order)
                {
                    $destination_platform_order = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->FindOrderIDByLinkedID($platform_order->linked_id, ['api_order_id']);
                    if($destination_platform_order)
                    {
                        //Check one to one order status mapping
                        $status = app('App\Http\Controllers\Brightpearl\BrightpearlUtility')->OneToOneOrderStatusMapping($userId, $userIntegrationId, $SourcePlatformId, $order_status_object_id, $platform_order->order_status); 
                        $statusId = isset($status->api_id) ? $status->api_id : NULL;

                        if(!$statusId){
                            // To set acknowledge status use this block
                            $DefaultOrderStatus = $this->map->getMappedDataByName($userIntegrationId, null, "sorder_approval_status", ['api_id']);
                            if ($DefaultOrderStatus) {
                                $statusId = isset($DefaultOrderStatus->api_id) ? $DefaultOrderStatus->api_id : NULL;
                            }
                        }
                        
                        if($platform_order->is_deleted && $statusId == NULL)
                        {
                            $delete_order_status = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowRuleID, 'sorder_delete_status', ['api_id']);
                            if($delete_order_status)
                            {
                                $statusId = $delete_order_status->api_id;
                            }
                        }

                        $AllowAddCancelOrderNoteInBP = false;
                        if($platform_order->is_voided && $statusId == NULL)
                        {
                            $sorder_error_status = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowRuleID, 'sorder_error_status', ['api_id']);
                            if($sorder_error_status)
                            {
                                $statusId = $sorder_error_status->api_id;
                                $AllowAddCancelOrderNoteInBP = true;
                            }
                        }

                        if($statusId)
                        {
                            $result = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->UpdateOrderStatus($platform_account, $destination_platform_order->api_order_id, $statusId);
                            if(is_array($result))
                            {
                                if(is_array($result) && count($result) == 0)
                                {
                                    /* change source platform order sync status */
                                    $platform_order->sync_status = 'Synced';
                                    $platform_order->save();

                                    /* save log */
                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'success', $platform_order->id, NULL);

                                    if (isset(Config::get('apisettings.AllowAddCancelOrderNoteInBP')[$SourcePlatformName]) && $AllowAddCancelOrderNoteInBP && $platform_order->notes)
                                    {
                                        app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->AddOrderNotes($platform_account, $destination_platform_order->api_order_id, $platform_order->notes);
                                    }
                                }
                                elseif(isset($result['errors']) && is_array($result['errors']))
                                {
                                    /* change source platform order sync status */
                                    $platform_order->sync_status = 'Failed';
                                    $platform_order->save();

                                    /* save log */
                                    $error = $this->bp->handleResponseError($result);

                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $platform_order->id, $error);
                                    $return_response = $error;
                                }
                                else
                                {
                                    $return_response = 'API Error';
                                }

                                sleep(1);
                            }
                            else
                            {
                                $return_response = "Order status can't update, please check information in tooltip.";
                            }
                        }
                    }
                }
            }
        }
        catch(\Exception $e)
        {
            \Log::error($userIntegrationId.' --> BrightpearlApiSubDivController --> UpdateSalesOrderStatus --> '.$e->getLine().' --> '.$e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Update Order Status and  GON */
    public function UpdateOrderStatusAndGoodOutNotes($userId = NULL, $userIntegrationId = NULL, $PlatformWorkFlowRuleID = NULL, $UserWorkFlowRuleID = NULL, $SourcePlatformName = NULL, $order_type = "SO", $sync_status = "Ready", $RecordID = NULL, $account = NULL)
    {
        /* This method can be changed when we have print,pick and pack case */
        $return_response = false;
        try {
            $recordExist = 0;
            /* Get object id by order type */
            $object_id = $this->helper->getObjectId('sales_order');
            /* If account not set */
            if (!isset($account)) {
                $account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
            }
            /* Get Source Platform Details */
            $SourcePlatformId = $this->helper->getPlatformIdByName($SourcePlatformName);

            if ($account && $this->platformId && $SourcePlatformId) {
                if (isset($account->platform_id) && $account->platform_id == $this->platformId) {
                    $limit = 30;
                    $query = PlatformOrder::select('api_order_id', 'customer_email', 'shipment_status', 'order_number', 'notes', 'id', 'linked_id');
                    if ($RecordID && $RecordID !== 0) {
                        $query->where('id', $RecordID);
                    } else {
                        $query->where([                                       
                            'platform_id'=> $SourcePlatformId,                           
                            'user_integration_id'=> $userIntegrationId,
                            'shipment_status'=> $sync_status,
                            'order_type'=> $order_type,
                            'is_deleted'=>0                            
                        ]);
                    }
                    $list = $query->orderBy('updated_at', 'ASC')->take($limit)->get();


                    if (!empty($list) && count($list) > 0) {
                        $recordExist = 1;
                        foreach ($list as $key => $order) {
                            $order_primary_id = $order->id;
                            $linked_id = $order->linked_id;
                            /* If this is sales order and linked id available*/
                            $q = PlatformOrderShipment::select('tracking_info', 'shipping_method','linked_id')->where([
                                'platform_order_id' => $order_primary_id
                            ])->first();
                            if (isset($linked_id) && isset($q->linked_id) && $q->linked_id) {
                                if($q->sync_status!="Synced"){
                                    $find = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->FindOrderIDByLinkedID($order->linked_id, ["api_order_id", "order_number", "platform_customer_id"]);
                                    if (isset($find->api_order_id) && $find) {
                                        $OrderStatus = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowRuleID, "default_final_sorder_status", ['api_id']);
                                        $OrderStatusID = isset($OrderStatus->api_id) ? $OrderStatus->api_id : NULL;
    
                                        if ($OrderStatusID) {
                                            $changeStatus = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->UpdateOrderStatus($account, $find->api_order_id, $OrderStatusID);
                                            if (is_array($changeStatus)) {
                                                if (!isset($changeStatus['errors'])) {
                                                    /* change source platform order sync status */
                                                    $order->shipment_status = 'Synced';
                                                    $order->save();
                                                    /* save log */
                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'success', $order_primary_id, NULL);
    
                                                    sleep(1);
                                                    /* Update GON and Shipped */
                                                    $findCustomer = PlatformCustomer::select('api_customer_id')->where('id', $find->platform_customer_id)->first();
                                                    if (isset($findCustomer->api_customer_id)) {
                                                        $shippingMethod = null;                                                  
                                                      
                                                        /*----------------Start to find order shipping method----------------*/
                                                        $shipping_method_object_id = $this->helper->getObjectId('shipping_method');
                                                        $sales_order_shipping_method = $this->map->getObjectDataByFilterData($userId, $userIntegrationId, $SourcePlatformId, $shipping_method_object_id, "api_id",  $q->shipping_method, ["name"]);
                                                        if ($sales_order_shipping_method) {
    
    
                                                            $sourceMethod = $this->map->getObjectDataByFilterData($userId, $userIntegrationId, $this->platformId, $shipping_method_object_id, "name",  $sales_order_shipping_method->name, ["api_id"]);
                                                            if (isset($sourceMethod)) {
                                                                $shippingMethod = $sourceMethod->api_id;
                                                            }
                                                        }
                                                        /*----------------End to find order shipping method----------------*/
                                                        $shippingValues = [];
                                                        if (!empty($shippingMethod)) {
                                                            $shippingValues['shippingMethodId'] = $shippingMethod;
                                                        }
                                                        $shippingValues['reference'] = $q->tracking_info;
                                                        $UpdateGoodsOutNoteData = [
                                                            "shipping" => $shippingValues
                                                        ];
                                                        
                                                        if($UpdateGoodsOutNoteData){
                                                            app('App\Http\Controllers\Brightpearl\BrightPearlApiSubController')->UpdateGoodOutNote($userId, $userIntegrationId, $find->order_number, $findCustomer->api_customer_id, $account, 'UpdateOrderStatusAndGoodOutNotes', $UpdateGoodsOutNoteData, true,$q->linked_id);
                                                        }
                                                       
                                                    }
                                                } else if (isset($changeStatus['errors']) && is_array($changeStatus['errors'])) {
                                                    /* change source platform order sync status */
                                                    $order->shipment_status = 'Failed';
                                                    $order->save();
                                                    /* save log */
                                                    $error = $this->bp->handleResponseError($changeStatus);
    
                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $error);
                                                    $return_response = $error;
                                                } else {
                                                    $return_response = "API Error";
                                                }
                                            }
                                        }
                                    }
                                }else{
                                    $order->shipment_status="Synced";
                                    $order->save();
                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'success', $order_primary_id, NULL);
    
                                }
                                
                            }else{
                                $order->shipment_status="Ignore";
                                $order->save();
                                $return_response = "Order can't ship, please check information in in tooltip.";
                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
    
                            }
                        }
                    }

                    if ($recordExist == 0) {
                        $return_response = "Record not exist";
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($userIntegrationId . "--UpdateOrderStatusAndNotes-->" . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /** Function is used to receive inventory from Sales Credit

     **/
    public function ReceiveSalesCreditInventory($sales_credit_primary_id, $sales_credit_order_id, $warehouse_id, $currencyCode, $account)
    {
        $return = true;
        try {
            $defaultLocationResponse = $this->bp->GetWarehouseDefaultLocation($account, $warehouse_id);
            $defaultLocationId = NULL;
            if ($defLoc = json_decode($defaultLocationResponse->getBody(), true)) {
                if (is_numeric($defLoc['response']) && isset($defLoc['response'])) {
                    $defaultLocationId = $defLoc['response'];
                    if ($defaultLocationId) {
                        $items = PlatformOrderLine::where('platform_order_id', $sales_credit_primary_id)->get();
                        $product = [];
                        if (count($items) > 0) {
                            foreach ($items as $item) {
                                $product[] = [
                                    'productId' => $item->api_product_id,
                                    'purchaseOrderRowId' => $item->api_order_line_id,
                                    'quantity' => $item->qty,
                                    'destinationLocationId' => $defaultLocationId,
                                    'productValue' => [
                                        'currency' => $currencyCode,
                                        'value' => isset($item->subtotal) ? $this->helper->getNumberFormat($item->subtotal, 4) : $this->helper->getNumberFormat(0, 4)
                                    ]
                                ];
                            }

                            if ($product) {
                                $payload = [
                                    'transfer' => false,
                                    'warehouseId' => $warehouse_id,
                                    'goodsMoved' => $product,
                                    'receivedOn' => date(DATE_ISO8601),
                                    'userBatchReference' => 'ourWarehouseRef:' . rand(1000000, 9999999) //random number
                                ];
                                \Log::channel('webhook')->info("ReceiveSalesCreditInventory -" . $sales_credit_primary_id . " - " . $sales_credit_order_id . " Response: " . print_r($payload, true) . " Created Date : " . date('Y-m-d H:i:s'));
                                $response = $this->bp->MoveInventoryOfSalesCreditByID($account, $sales_credit_order_id, $payload);
                                if ($result = json_decode($response->getBody(), true)) {
                                    if (is_numeric($result['response']) && isset($result['response'])) {
                                        $return = true;
                                    } else if (isset($result['errors']) && is_array($result['errors'])) {
                                        $return = $this->bp->handleResponseError($result);
                                    }
                                } else {
                                    $return = "API Error: Internal Error";
                                }
                            }
                        } else {
                            $return = "No line items found for current sales credit";
                        }
                    } else {
                        $return = "No default location found for warehouse under current sales credit";
                    }
                } else if (isset($defLoc['errors']) && is_array($defLoc['errors'])) {
                    $return = $this->bp->handleResponseError($defLoc);
                }
            } else {
                $return = "No default location found for warehouse under current sales credit";
            }
        } catch (\Exception $e) {
            $return = "API Error: Catch Exception";
        }
        return $return;
    }
    /* Update Sale Credit Order Status And Receive Inventory for Sales Credit */
    public function UpdatesSalesCreditOrderStatus($userId = NULL, $userIntegrationId = NULL, $PlatformWorkFlowRuleID = NULL, $UserWorkFlowRuleID = NULL, $SorucePlatformName = NULL, $order_type, $sync_status = "Ready", $RecordID = NULL, $receiveSCInventory = false, $account = NULL)
    {
        $return_response = false;
        try {

            /* Get object id by order type */
            $object_id = $this->helper->getObjectId('sales_credit');
            /* If account not set */
            if (!isset($account)) {
                $account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
            }
            /* Get Source Platform Details */
            $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);
            $SOurceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id', 'app_secret', 'platform_id', 'id', 'user_id', 'api_domain']);

            if ($account && $this->platformId && $SourcePlatformId && $SOurceUfound) {
                if (isset($account->platform_id) && $account->platform_id == $this->platformId) {
                    $limit = 20;
                    $query = PlatformOrder::select('api_order_id', 'shipment_status', 'order_number', 'notes', 'id', 'linked_id', 'refund_sync_status');

                    if ($RecordID && $RecordID !== 0) {
                        $query->where('id', $RecordID);
                    } else {
                        $query->where([
                            [
                                'platform_id', '=', $SOurceUfound->platform_id
                            ], [
                                'user_integration_id', '=', $userIntegrationId
                            ],
                            [
                                'refund_sync_status', '=', $sync_status
                            ],
                            ['order_type', '=', $order_type]

                        ]);
                    }
                    $list = $query->orderBy('id', 'ASC')->orderBy('updated_at', 'ASC')->take($limit)->get();


                    if (!empty($list) && count($list) > 0) {

                        foreach ($list as $key => $order) {
                            $order_primary_id = $order->id;
                            $linked_id = $order->linked_id;
                            /* If this is sales order and linked id available*/
                            if (isset($linked_id)) {
                                $find = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->FindOrderIDByLinkedID($order->linked_id, ["api_order_id", 'id', 'warehouse_id']);
                                if (isset($find->api_order_id)) {
                                    /* If you want to receive SC inventory */
                                    if ($receiveSCInventory) {
                                        $acountInfo = PlatformAccountAdditionalInfo::select('account_currency_code')->where([
                                            'account_id' => $account->id, 'user_integration_id' => $userIntegrationId
                                        ])->first();
                                        $result = $this->ReceiveSalesCreditInventory($find->id, $find->api_order_id, $find->warehouse_id, $acountInfo->account_currency_code, $account);
                                        if (!is_bool($result)) {
                                            $order->refund_sync_status = 'Failed';
                                            $order->save();
                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id,  $result);
                                            $return_response = $result;
                                        } else {
                                            $OrderStatus = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowRuleID, "default_final_sales_credit_status", ['api_id']);
                                            $OrderStatusID = isset($OrderStatus->api_id) ? $OrderStatus->api_id : NULL;

                                            if ($OrderStatusID) {
                                                $changeStatus = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->UpdateOrderStatus($account, $find->api_order_id, $OrderStatusID);
                                                if (is_array($changeStatus)) {

                                                    if (!isset($changeStatus['errors'])) {
                                                        /* change source platform order sync status */
                                                        $order->refund_sync_status = 'Synced';
                                                        $order->save();
                                                        /* Distination platform order sync status*/
                                                        $find->refund_sync_status = 'Synced';
                                                        $find->save();
                                                        /* save log */
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'success', $order_primary_id, NULL);
                                                    } else if (isset($changeStatus['errors']) && is_array($changeStatus['errors'])) {
                                                        /* change source platform order sync status */
                                                        $order->refund_sync_status = 'Failed';
                                                        $order->save();
                                                        /* save log */
                                                        $error = $this->bp->handleResponseError($changeStatus);

                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $error);
                                                        $return_response = $error;
                                                    } else {
                                                        $return_response = "API Error";
                                                    }
                                                }
                                            }
                                        }
                                    } else {
                                        $OrderStatus = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowRuleID, "default_final_sales_credit_status", ['api_id']);
                                        $OrderStatusID = isset($OrderStatus->api_id) ? $OrderStatus->api_id : NULL;

                                        if ($OrderStatusID) {
                                            $changeStatus = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->UpdateOrderStatus($account, $find->api_order_id, $OrderStatusID);
                                            if (is_array($changeStatus)) {

                                                if (!isset($changeStatus['errors'])) {
                                                    /* change source platform order sync status */
                                                    $order->refund_sync_status = 'Synced';
                                                    $order->save();
                                                    /* Distination platform order sync status*/
                                                    $find->refund_sync_status = 'Synced';
                                                    $find->save();
                                                    /* save log */
                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'success', $order_primary_id, NULL);
                                                } else if (isset($changeStatus['errors']) && is_array($changeStatus['errors'])) {
                                                    /* change source platform order sync status */
                                                    $order->refund_sync_status = 'Failed';
                                                    $order->save();
                                                    /* save log */
                                                    $error = $this->bp->handleResponseError($changeStatus);

                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $error);
                                                    $return_response = $error;
                                                } else {
                                                    $return_response = "API Error";
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Find Inventory Credit Records */
    public function GetInventoryCredits($InventoryId = NULL)
    {
        $query = PlatformProductInventoryCredit::select('quantity', 'platform_refund_order_id', 'id', 'user_workflow_rule_id')->where([
            'platform_inventory_id' => $InventoryId,
            'sync_status' => "Ready"
        ]);
        $sum = $user_workflow_rule_id = 0;
        $platform_refund_id = $inventory_credit_ids = [];
        if ($query->count() > 0) {
            $list = $query->get();
            foreach ($list as $value) {
                $sum = $sum + $value->quantity;
                $inventory_credit_ids[] = $value->id;
                $platform_refund_id[] = $value->platform_refund_order_id;
                $user_workflow_rule_id = $value->user_workflow_rule_id;
            }
        }

        return ['platform_refund_id' => $platform_refund_id, 'inventory_credit_ids' => $inventory_credit_ids, 'total_quantity' => $sum, 'user_workflow_rule_id' => $user_workflow_rule_id];
    }
    /* Update Inventory Credit Records Status */
    public function UpdateInventoryCreditStatus($InventoryCreditId = [])
    {
        PlatformProductInventoryCredit::whereIn('id', $InventoryCreditId)->update(['sync_status' => "Synced"]);
    }
    /* Update Order Refund Table Status */
    public function UpdateOrderRefundStatus($RefundIds = [], $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id)
    {
        // foreach($RefundIds as $value){
        //     $find=PlatformOrderRefund::find('id',$value);
        //     if($find){
        //         $find->sync_status="Synced";
        //         $find->save();
        //         $order=PlatformOrder::find();
        //         if($order){
        //             $order->refund_sync_status="Synced";
        //             $order->save();
        //         }
        //     }
        // }
        $object_id = $this->helper->getObjectId('refund_order');
        PlatformOrderRefund::whereIn('id', $RefundIds)->update(['sync_status' => "Synced"]);
        foreach ($RefundIds as $refund) {
            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $refund, NULL);
        }
    }
    /* External Transfer */
    public function ExternalTransfer($account, $warehouseId, $payload)
    {
        $url = "/warehouse-service/warehouse/$warehouseId/external-transfer";
        $response = $this->bp->ExternalTransfer($account, $url, $payload);
        $result = json_decode($response->getBody(), true);
        if (isset($result['response']) && is_numeric($result['response'])) {
            return $result['response'];
        } else {
            return $this->bp->handleResponseError($result);
        }
    }
    /* Quarantine Release */
    public function QuarantineRelease($account, $warehouseId, $payload)
    {
        $url = "/warehouse-service/warehouse/$warehouseId/quarantine/release";
        $response = $this->bp->QuarantineRelease($account, $url, $payload);
        $result = json_decode($response->getBody(), true);

        if (!isset($result['errors']) && !isset($result['response'])) {
            return true;
        } else {
            return $this->bp->handleResponseError($result);
        }
    }
    /* Update GON as Shipped */
    public function ReceiveExternalTransfer($userId = NULL, $userIntegrationId = NULL,  $UserWorkFlowRuleID = NULL, $SourcePlatformName = NULL,  $sync_status = "Ready", $RecordID = NULL, $account = NULL)
    {

        $return_response = false;
        try {
            $recordExist = 0;
            /* Get object id by order type */
            $object_id = $this->helper->getObjectId('transfer_order');
            /* If account not set */
            if (!isset($account)) {
                $DestinationAccount = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
            }
            /* Get Source Platform Details */
            $SourcePlatformId = $this->helper->getPlatformIdByName($SourcePlatformName);


            if ($DestinationAccount && $this->platformId && $SourcePlatformId) {

                $limit = 20;
                $query = PlatformOrderShipment::select('id', 'shipment_sequence_number', 'shipment_id', 'sync_status',  'shipping_method', 'linked_id');
                if ($RecordID && $RecordID !== 0) {
                    $query->where('id', $RecordID);
                } else {
                    $query->where([
                        [
                            'platform_id', '=', $SourcePlatformId
                        ], [
                            'user_integration_id', '=', $userIntegrationId
                        ], [
                            'sync_status', '=', $sync_status
                        ],
                        [
                            'type', '=', "Transfer"
                        ]
                    ]);
                }
                $list = $query->orderBy('order_id', 'ASC')->take($limit)->get();


                if (!empty($list) && count($list) > 0) {
                    $recordExist = 1;
                    $WHandLoc = [];
                    foreach ($list as $key => $order) {
                        $order_primary_id = $order->id;
                        $linked_id = $order->linked_id;
                        /* If this is sales order and linked id available*/
                        if (isset($linked_id)) {
                            $find = PlatformOrderShipment::with('platformShippingLines')->select('shipment_id', 'shipping_method', 'linked_id', 'event_owner_id', 'created_by', 'id', 'warehouse_id', 'to_warehouse_id', 'stock_transfer_id', 'realease_date', 'shipment_sequence_number', 'is_shipped')->find($linked_id);

                            if (isset($find->shipment_id)) {
                                /*-----Start  Update Goods Out Note------ */
                                $order_number = $find->shipment_id . "/" . $find->shipment_sequence_number;
                                /* Update GON as Shipped */
                                $event_owner_id = !empty($find->event_owner_id) ? $find->event_owner_id : $find->created_by;
                                /* check if GON updated then is_shipped return true value*/
                                if ($find->is_shipped) {
                                    $return = true;
                                } else {
                                    /* check if GON not updated then go for update GON*/
                                    $return = app('App\Http\Controllers\Brightpearl\BrightPearlApiSubController')->UpdateGoodOutNote($userId, $userIntegrationId, $order_number, $event_owner_id, $DestinationAccount, 'ReceiveExternalTransfer');
                                }


                                if (is_bool($return)) {
                                    $find->is_shipped = true;
                                    $find->save();
                                    $order->sync_status = "Synced";
                                    $order->is_shipped = true;
                                    $order->save();
                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'success', $order_primary_id, NULL);
                                    $lineItems = $find->platformShippingLines; //Line Items
                                    if (count($lineItems) > 0) {

                                        /* ---Start QuarantineRelease----*/
                                        $defaultLocationId = NULL;
                                        if (isset($WHandLoc[$find->to_warehouse_id])) {
                                            $defaultLocationId = $WHandLoc[$find->to_warehouse_id];
                                        } else {
                                            $defaultLocationResponse = $this->bp->GetWarehouseDefaultLocation($DestinationAccount, $find->to_warehouse_id);

                                            if ($defLoc = json_decode($defaultLocationResponse->getBody(), true)) {
                                                if (is_numeric($defLoc['response']) && isset($defLoc['response'])) {
                                                    $defaultLocationId = $defLoc['response'];
                                                    $WHandLoc[$find->to_warehouse_id] = $defaultLocationId;
                                                }
                                            }
                                        }
                                        if ($defaultLocationId) {

                                            $lineItems = $lineItems->toArray();

                                            foreach (array_chunk($lineItems, 10) as $arrays) {
                                                $postMethodType = "single";
                                                $payloadQuarantineRelease = [];

                                                if (count($arrays) > 1) {
                                                    $postMethodType = "multimessage";
                                                    $post_url = "/warehouse-service/warehouse/$find->to_warehouse_id/quarantine/release";
                                                } else if (count($arrays) == 1) {
                                                    $postMethodType = "single";
                                                }

                                                foreach ($arrays as $key => $item) {
                                                    if ($item['sync_status'] == "Ready") {


                                                        if ($postMethodType == "single") {
                                                            $payloadQuarantineRelease =
                                                                [
                                                                    "id" => $item['id'],
                                                                    "productId" => $item['product_id'],
                                                                    "quantity" => $item['quantity'],
                                                                    "toLocationId" => $defaultLocationId
                                                                ];
                                                        } else {
                                                            $payloadQuarantineRelease[] =
                                                                [
                                                                    'label' => "LABEL" . $key . "-" . $item['id'],
                                                                    'uri' => $post_url,
                                                                    'httpMethod' => 'POST',
                                                                    'body' =>
                                                                    [
                                                                        "productId" => $item['product_id'],
                                                                        "quantity" => $item['quantity'],
                                                                        "toLocationId" => $defaultLocationId

                                                                    ],
                                                                ];
                                                        }
                                                    }
                                                }

                                                if ($payloadQuarantineRelease) {
                                                    if ($postMethodType == "single") {

                                                        \Log::channel('webhook')->info("single_payload_for_quarantine -" . $userId . " Integration " . $userIntegrationId . " Response: " . print_r($payloadQuarantineRelease, true) . " Created Date : " . date('Y-m-d H:i:s'));

                                                        /* Unset Primary ID from payload */
                                                        $payloadQuarantineReleaseMain = $payloadQuarantineRelease; //assign array payload to new array
                                                        unset($payloadQuarantineRelease['id']);

                                                        $payloadQuarantineReleaseActual = $payloadQuarantineRelease; //assign array payload to new array
                                                        $res = $this->QuarantineRelease($DestinationAccount, $find->to_warehouse_id, $payloadQuarantineReleaseActual);

                                                        \Log::channel('webhook')->info("single_payload_for_quarantine_response -" . $userId . " Integration " . $userIntegrationId . " Response: " . print_r($res, true) . " Created Date : " . date('Y-m-d H:i:s'));

                                                        if (!is_bool($res)) {
                                                            $order->sync_status = "Failed";
                                                            $order->save();
                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $res);
                                                            $return_response = $res;
                                                        } else {
                                                            /* update platform shipment line item status by primary id */
                                                            PlatformOrderShipmentLine::where('id', $payloadQuarantineReleaseMain['id'])->update(['sync_status' => "Synced"]);

                                                            // $order->sync_status = "Synced";
                                                            // $order->save();
                                                            // $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'success', $order_primary_id, NULL);
                                                        }
                                                    } else {



                                                        $payload = [
                                                            "processingMode" => "SEQUENTIAL",
                                                            "onFail" => "CONTINUE",
                                                            "messages" => $payloadQuarantineRelease
                                                        ];
                                                        $resp = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->MultiMessage($userIntegrationId, $payload, $DestinationAccount);

                                                        \Log::channel('webhook')->info("multimessage_payload_for_quarantine -" . $userId . " Integration " . $userIntegrationId . " Response: " . print_r($payload, true) . " Created Date : " . date('Y-m-d H:i:s'));


                                                        if ($multimessageResponse = json_decode($resp->getBody(), true)) {


                                                            \Log::channel('webhook')->info("multimessage_payload_for_quarantine_response -" . $userId . " Integration " . $userIntegrationId . " Response: " . print_r($multimessageResponse, true) . " Created Date : " . date('Y-m-d H:i:s'));


                                                            /* Get Multimessage Error List */
                                                            $errors_list = null;

                                                            if (isset($multimessageResponse['response']['processedMessages'])) {

                                                                foreach ($multimessageResponse['response']['processedMessages'] as $key => $res) {

                                                                    \Log::channel('webhook')->info("multimessage_payload_for_quarantine_response___res -" . $userId . " Integration " . $userIntegrationId . " Response: " . print_r($res, true) . " Created Date : " . date('Y-m-d H:i:s'));

                                                                    if ($res['statusCode'] === 200) {
                                                                        /* update platform shipment line item status by primary id */
                                                                        $splitLable = explode("-", $res['label']);
                                                                        $primary_shipment_line_item_id = isset($splitLable[1]) ? $splitLable[1] : NULL;
                                                                        PlatformOrderShipmentLine::where('id', $primary_shipment_line_item_id)->update(['sync_status' => "Synced"]);
                                                                    } else {
                                                                        $contentbody = json_decode($res['body']['content'], true);

                                                                        /* Error Handling 1 */
                                                                        if (isset($contentbody['error']) || (isset($contentbody['errors']) && is_array($contentbody['errors']))) {
                                                                            $errors = isset($contentbody['errors']) ? $contentbody['errors'] : $contentbody['error'];
                                                                            foreach ($errors as $key => $error) {
                                                                                $errors_list .= $error['message'] . ",";
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            } else {
                                                                $errors_list = $this->bp->handleResponseError($multimessageResponse);
                                                            }
                                                            $mError = rtrim($errors_list, ",");

                                                            if (!empty($mError)) { //if multimessage error is not null
                                                                $order->sync_status = "Failed";
                                                                $order->save();
                                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $mError);
                                                                $return_response = $mError;
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        } else {
                                            $order->sync_status = "Failed";
                                            $order->save();
                                            $return_response = "No default location found for QuarantineRelease in Brightpearl";
                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                        }
                                        /*---------- End QuarantineRelease-------- */
                                    } else {
                                        $order->sync_status = "Failed";
                                        $order->save();
                                        $return_response = "No line items are found for quarantine in Brightpearl";
                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                    }
                                } else {
                                    $order->sync_status = "Failed";
                                    $order->save();
                                    $return_response = $return;
                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $order_primary_id, $return_response);
                                }
                                /* ---End Update Goods Out Note---*/
                            }
                        }
                    }
                }


                if ($recordExist == 0) {
                    $return_response = "Record not exist";
                }
            }
        } catch (\Exception $e) {
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    
}