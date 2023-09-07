<?php

namespace App\Http\Controllers\Woocommerce;

use App\Helper\Api\WoocommerceApi;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\MainModel;
use App\Http\Controllers\Controller;
use App\Models\PlatformAccount;
use App\Models\PlatformCustomer;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderTransaction;
use App\Models\PlatformProduct;
use App\Models\PlatformProductOption;
use App\Models\PlatformUrl;
use Auth;
use DB;
use Illuminate\Http\Request;
use Validator;
use Lang;

class WoocommerceApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $mobj, $wc, $helper, $map, $platformId, $log, $platformOrder, $platformUrl;
    public static $myPlatform = 'woocommerce';
    public function __construct()
    {
        $this->platformOrder = new PlatformOrder();
        $this->platformUrl = new PlatformUrl();
        $this->mobj = new MainModel();
        $this->wc = new WoocommerceApi();
        $this->map = new FieldMappingHelper();
        $this->log = new Logger();
        $this->helper = new ConnectionHelper;
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }

    /* Check if already have payment done for a work | insert and update*/
    public function CheckAndSaveTransaction($post)
    {
        if ($post) {
            $find = PlatformOrderTransaction::select('sync_status', 'transaction_id', 'transaction_datetime', 'transaction_type', 'transaction_method', 'transaction_amount', 'transaction_reference')->where('platform_order_id', $post['platform_order_id'])->first();
            if ($find) {
                if ($find->sync_status != "Synced") {
                    $find->transaction_id = isset($post['transaction_id']) ? $post['transaction_id'] : null;
                    $find->transaction_datetime = isset($post['transaction_datetime']) ? $post['transaction_datetime'] : null;
                    $find->transaction_type = isset($post['transaction_type']) ? $post['transaction_type'] : null;
                    $find->transaction_method = isset($post['transaction_method']) ? $post['transaction_method'] : null;
                    $find->transaction_amount = isset($post['transaction_amount']) ? $post['transaction_amount'] : null;
                    $find->transaction_reference = isset($post['transaction_reference']) ? $post['transaction_reference'] : null;
                    $find->save();
                    return true;
                } else {
                    return false;
                }
            } else {
                PlatformOrderTransaction::insert($post);
                return true;
            }
        }
    }

    /* Receive Order create/update/delete webhook from WooCommerce */
    public function ReceiveOrderWebhook(Request $request, $userIntegrationId)
    {
        $this->mobj->AddMemory(); //Add memory for execution
        if ($request->isMethod('post')) {
            if (isset($request->id)) {
                $orderApiId = (string) $request->id;
                $userIntegrationId = (int)$userIntegrationId;
                //\Log::channel('webhook')->info("Integration " . $userIntegrationId . " Order_WOO_hook -OrderPrimaryId=" . $request->id . " Created Date : " . date('Y-m-d H:i:s'));
                $EventID = "GET_SALESORDER";
                $user_work_flow = [];

                $integration = $this->map->getUserIntegrationDetailsById($userIntegrationId, self::$myPlatform);
                if ($integration) {
                    $userId = (int)$integration->user_id;
                    $selectFields = ['e.event_id', 'ur.status', 'ur.sync_start_date', 'ur.platform_workflow_rule_id', 'ur.user_id'];
                    $user_work_flow = $this->map->getUserIntegWorkFlow($userIntegrationId, $EventID, $selectFields, self::$myPlatform);
                    if (isset($user_work_flow[$EventID])) {
                        $userId = $user_work_flow[$EventID]['user_id'];
                        $user_workflow = array_keys($user_work_flow); //in below $user_workflow is used as array
                        $order_sync_start_date = $user_work_flow[$EventID]['sync_start_date'];
                        $platform_workflow_id = $user_work_flow[$EventID]['platform_workflow_rule_id'];
                        if ($user_work_flow[$EventID]['status'] == 1) {
                            /* First Check Sync Start Date time Set or Not */
                            $byPass = app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->isValidOrder($order_sync_start_date, $request->date_created);

                            if ($byPass) {
                                /* Check whether shipment is ON */
                                if (in_array($EventID, $user_workflow) && $user_work_flow[$EventID]['status'] == 1) {
                                    $warehouse_object_id = $this->helper->getObjectId('warehouse');
                                    /* Return all multi selected order status */
                                    $orderStatusArray = $this->map->getMappedDataByName($userIntegrationId, $platform_workflow_id, "get_sorder_status", ['api_code'], "regular", null, "multi", "source");
                                    /* If we have meta data acceptance */
                                    $AcceptMetaData = $AcceptWarehouseMetaData = "no";
                                    $MetaData = $this->map->getMappedDataByName($userIntegrationId, $platform_workflow_id, "accept_meta_data", ['custom_data'], "default");
                                    $AcceptMetaData = isset($MetaData->custom_data) && strtolower($MetaData->custom_data) == "yes" ? "yes" : "no";
                                    /* If we have switch warehouse */
                                    $switch_warehouse = $this->map->getMappedDataByName($userIntegrationId, $platform_workflow_id, "switch_warehouse", ['custom_data'], "default");
                                    $AcceptWarehouseMetaData = isset($switch_warehouse->custom_data) && strtolower($switch_warehouse->custom_data) == "yes" ? "yes" : "no";

                                    $orderStatusArray[] = "refunded";

                                    if ($orderStatusArray) {

                                        if (in_array($request->status, $orderStatusArray)) {
                                            $findOrder = PlatformOrder::where([
                                                ['user_integration_id', '=', $userIntegrationId],
                                                ['platform_id', '=', $this->platformId],
                                                ['api_order_id', '=', $orderApiId],
                                            ])->first();
                                            if ($findOrder) {

                                                if ($findOrder->sync_status == "Synced") {
                                                    $refundStatus = "Pending";
                                                    $cancelStatus = $request->status == "cancelled" ? 1 : 0;
                                                    if ($cancelStatus) {
                                                        //If when we have cancelled status
                                                        $findOrder->api_updated_at = $request->date_modified;
                                                        $findOrder->file_name = $request->status;
                                                        $findOrder->sync_status = "Ready";
                                                        $findOrder->is_voided = $cancelStatus;
                                                        $findOrder->order_updated_at = date('Y-m-d H:i:s');
                                                        $findOrder->save();
                                                    } else if ($request->status == "completed" || $request->status == "processing") {
                                                        //If payments comes with completed status
                                                        $paymentDetails =
                                                            [
                                                                'platform_order_id' => $findOrder->id,
                                                                'transaction_id' => $request->transaction_id,
                                                                'transaction_datetime' => $request->date_paid,
                                                                'transaction_type' => $request->created_via,
                                                                'transaction_method' => $request->payment_method,
                                                                'transaction_amount' => $request->total,
                                                                'transaction_reference' => $request->payment_method_title,
                                                                'sync_status' => "Ready",
                                                            ];
                                                        $this->CheckAndSaveTransaction($paymentDetails);
                                                        $findOrder->api_updated_at = $request->date_modified;
                                                        $findOrder->file_name = $request->status;
                                                        $findOrder->sync_status = "Ready";
                                                        $findOrder->order_updated_at = date('Y-m-d H:i:s');
                                                        $findOrder->save();
                                                    } else {
                                                        /* Accept if GET_REFUND is ON */
                                                        if ($request->status == "refunded" && in_array("GET_REFUND", $user_workflow)) {
                                                            $refundStatus = "Ready";
                                                            if (isset($request->refunds)) {
                                                                $refundsCount = count($request->refunds);
                                                                if ($refundsCount > 0) {
                                                                    $lineItems = [];
                                                                    foreach ($request->refunds as $value) {
                                                                        $find = $this->mobj->getFirstResultByConditions('platform_order_refunds', [
                                                                            'platform_order_id' => $findOrder->id,
                                                                            'api_id' => $value['id'],
                                                                        ], ['id', 'sync_status']);

                                                                        if (!$find) {
                                                                            $lineItems[] = [
                                                                                'platform_order_id' => $findOrder->id,
                                                                                'api_id' => $value['id'],
                                                                                'amount' => $value['total'],
                                                                            ];
                                                                        }
                                                                    }
                                                                    if (!empty($lineItems)) {
                                                                        $this->mobj->makeInsert('platform_order_refunds', $lineItems);
                                                                        $findOrder->file_name = $request->status;
                                                                        $findOrder->refund_sync_status = $refundStatus;
                                                                        $findOrder->order_updated_at = date('Y-m-d H:i:s');
                                                                        $findOrder->save();
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                } else if (in_array($findOrder->sync_status, ['Ready', 'Pending', 'Failed'])) {
                                                    if (in_array($request->status, ["completed", "processing"])) {
                                                        //If payments comes with completed status
                                                        $this->StorePaymentDetails($request, $findOrder->id);
                                                        $findOrder->file_name = $request->status;
                                                        $findOrder->api_updated_at = $request->date_modified;
                                                        $findOrder->sync_status = "Ready";
                                                        $findOrder->order_updated_at = date('Y-m-d H:i:s');
                                                        $findOrder->save();
                                                    }
                                                    $order_warehouse_id = $this->GetOrderWarehouse($request, $userId, $userIntegrationId, $warehouse_object_id);
                                                    $findOrder->api_updated_at = $request->date_modified;
                                                    $findOrder->warehouse_id = $order_warehouse_id;
                                                    $findOrder->customer_email = $request->billing['email'];
                                                    $findOrder->api_order_id = $orderApiId;
                                                    $findOrder->order_number = $request->number;
                                                    $findOrder->order_date = isset($request->date_created) ? $request->date_created : null;
                                                    $findOrder->total_discount = $request->discount_total;
                                                    $findOrder->discount_tax = $request->discount_tax; //discount_tax
                                                    $findOrder->shipping_total = $request->shipping_total; //shipping_total
                                                    $findOrder->shipping_tax = $request->shipping_tax; //shipping_tax
                                                    $findOrder->total_tax = $request->total_tax;
                                                    $findOrder->total_amount = $request->total;
                                                    $findOrder->notes = $request->customer_note;
                                                    $findOrder->file_name = $request->status;
                                                    $findOrder->carrier_code = $request->payment_method;
                                                    $findOrder->payment_date = $request->date_paid;
                                                    $findOrder->currency = $request->currency;
                                                    $findOrder->shipping_method = isset($request->shipping_lines[0]['instance_id']) ? $request->shipping_lines[0]['instance_id'] : null;
                                                    $findOrder->order_updated_at = date('Y-m-d H:i:s');
                                                    $findOrder->save();
                                                    /* --Update Address-- */
                                                    $this->StoreAddress($request, $findOrder->id, "update");
                                                    /* --Insert Line Items-- */
                                                    $isLineItemSaved = $this->StoreLineItems($request, $findOrder->id, $AcceptMetaData, $AcceptWarehouseMetaData, "update");
                                                    $switchWarehouse = $isLineItemSaved['return'];

                                                    /* --Insert Line Items--*/

                                                    // \Log::channel('webhook')->info("switch WOO -value" . $switchWarehouse);
                                                    if ($switchWarehouse == "false") {
                                                        PlatformOrder::where('id', $findOrder->id)->update(["warehouse_id" => null]);
                                                    }
                                                    /* --Custom Field-- */
                                                    $this->StoreCustomField($request, $findOrder->id, $userIntegrationId);
                                                }
                                            } else {
                                                //IF order not found then create new one
                                                if (app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->isOldDate($request->date_created)) { //validate 1 day old order date

                                                    $ignoreOrders = true;
                                                    $refundStatus = "Pending";
                                                    /*  cancelled
                                                        refunded
                                                        failed
                                                        trash
                                                        this kind of order can not accept
                                                        */
                                                    if (in_array($request->status, ["cancelled", "refunded", "failed", "trash"])) {
                                                        $ignoreOrders = false;
                                                    }
                                                    if ($ignoreOrders) {
                                                        /* Check Customer ID If not found search via API Call */
                                                        if (isset($request->customer_id) && $request->customer_id !== 0) {
                                                            $CustomerID = $request->customer_id;
                                                            $CustomerID = $this->SearchCustomerByID($CustomerID, $userId, $userIntegrationId, $this->platformId);
                                                            if (is_int($CustomerID)) {
                                                                $CustomerID = $CustomerID;
                                                            } else {
                                                                $CustomerID = 0;
                                                            }
                                                        } else {
                                                            $CustomerID = 0;
                                                        }
                                                        /* ----------------- */
                                                        /* Set Cancel order status for is_voided=1 if found */
                                                        $cancelStatus = $request->status == "cancelled" ? 1 : 0;
                                                        /* When we have multiwarehouse mapping one, Store warehouse ID */
                                                        $order_warehouse_id = $this->GetOrderWarehouse($request, $userId, $userIntegrationId, $warehouse_object_id);
                                                        /* Asign some object values */
                                                        $request->warehouse_id = $order_warehouse_id;
                                                        $request->platform_customer_id = $CustomerID;
                                                        $request->is_voided = $cancelStatus;
                                                        $request->refund_sync_status = $refundStatus;

                                                        $lastOrderID = $this->StoreOrderDetails($request, $userId, $userIntegrationId, $platform_workflow_id);

                                                        if ($lastOrderID) {

                                                            /*-- Store Address-- */
                                                            $isAddressSaved = $this->StoreAddress($request, $lastOrderID);
                                                            /* --Insert Line Items--*/
                                                            $isLineItemSaved = $this->StoreLineItems($request, $lastOrderID, $AcceptMetaData, $AcceptWarehouseMetaData);
                                                            $switchWarehouse = $isLineItemSaved['return'];
                                                            $itemsaved = $isLineItemSaved['items'];

                                                            // \Log::channel('webhook')->info("switch WOO -value" . $switchWarehouse);
                                                            if ($switchWarehouse == "false") {
                                                                PlatformOrder::where('id', $lastOrderID)->update(["warehouse_id" => null]);
                                                            }

                                                            /* --Custom Field-- */
                                                            $this->StoreCustomField($request, $lastOrderID, $userIntegrationId);
                                                            /* --Insert Transaction/Payments-- */
                                                            $this->StorePaymentDetails($request, $lastOrderID);
                                                            /* If address or item is missing| set order status as Pending */
                                                            if (!$itemsaved || !$isAddressSaved) {
                                                                PlatformOrder::where('id', $lastOrderID)->update(["sync_status" => "Pending"]);
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
                    }
                }
            }
        }
        return true;
    }

    public function updateDateTimeISOFormat($dateTime, $sign = "+")
    {
        $date_slice = explode($sign, $dateTime);
        if (isset($date_slice[0])) {
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
    public function checkPlatformOrderExist($userId, $userIntegrationId, $orderId)
    {
        return $this->platformOrder->where(['platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'api_order_id' => $orderId])->first();
    }
    public function checkPlatformURLViaURlName($userId, $userIntegrationId, $urlName)
    {
        return $this->platformUrl->where(['platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'url_name' => $urlName])
            ->select('url', 'id')->first();
    }
    /* Save Existing Order Details */
    private function SaveExistingOrderDetails($order, $has_order_exist)
    {

        $has_order_exist->customer_email = isset($order->billing['email']) ? $order->billing['email'] : '';
        $has_order_exist->api_updated_at = $order->date_modified;
        $has_order_exist->api_order_id = $order->id;
        $has_order_exist->order_number = $order->number;
        $has_order_exist->order_date = isset($order->date_created) ? $order->date_created : null;
        $has_order_exist->total_discount = $order->discount_total;
        $has_order_exist->discount_tax = $order->discount_tax; //discount_tax
        $has_order_exist->shipping_total = $order->shipping_total; //shipping_total
        $has_order_exist->shipping_tax = $order->shipping_tax; //shipping_tax
        $has_order_exist->total_tax = $order->total_tax;
        $has_order_exist->total_amount = $order->total;
        $has_order_exist->notes = $order->customer_note;
        $has_order_exist->file_name = $order->status;
        $has_order_exist->carrier_code = $order->payment_method;
        $has_order_exist->payment_date = $order->date_paid;
        $has_order_exist->currency = $order->currency;
        $has_order_exist->shipping_method = isset($order->shipping_lines[0]['instance_id']) ? $order->shipping_lines[0]['instance_id'] : null;
        $has_order_exist->order_updated_at = date('Y-m-d H:i:s');
        if (isset($order->sync_status)) {
            $has_order_exist->sync_status = $order->sync_status;
        }

        $has_order_exist->save();
    }
    /* Accpet Payment Detail */
    private function AcceptOrderPaymentDetails($order, $has_order_exist)
    {
        $paymentDetails =
            [
                'platform_order_id' => $has_order_exist->id,
                'transaction_id' => $order->transaction_id,
                'transaction_datetime' => $order->date_paid,
                'transaction_type' => $order->created_via,
                'transaction_method' => $order->payment_method,
                'transaction_amount' => $order->total,
                'transaction_reference' => $order->payment_method_title,
                'sync_status' => "Ready",
            ];
        $return = $this->CheckAndSaveTransaction($paymentDetails);
        if ($return) {
            $has_order_exist->api_updated_at = $order->date_modified;
            $has_order_exist->file_name = $order->status;
            $has_order_exist->sync_status = "Ready";
            $has_order_exist->order_updated_at = date('Y-m-d H:i:s');
            $has_order_exist->save();
        }
    }
    /* Accept Refund Orders If First Order exist and sync to next platform*/
    private function AcceptRefundOrders($user_workflow, $order, $has_order_exist)
    {
        if ($order->status == "refunded" && in_array("GET_REFUND", $user_workflow)) {
            $refundStatus = "Ready";

            if (isset($order->refunds)) {
                $refundsCount = count($order->refunds);

                if ($refundsCount > 0) {
                    $refundOrders = [];
                    foreach ($order->refunds as $value) {

                        $find = $this->mobj->getFirstResultByConditions('platform_order_refunds', [
                            'platform_order_id' => $has_order_exist->id,
                            'api_id' => $value['id'],
                        ], ['id', 'sync_status']);

                        if (!$find) {
                            $refundOrders[] = [
                                'platform_order_id' => $has_order_exist->id,
                                'api_id' => $value['id'],
                                'amount' => $value['total'],
                            ];
                        }
                    }

                    if (!empty($refundOrders)) {
                        $this->mobj->makeInsert('platform_order_refunds', $refundOrders);
                        $has_order_exist->api_updated_at = $order->date_modified;
                        $has_order_exist->file_name = $order->status;
                        $has_order_exist->refund_sync_status = $refundStatus;
                        $has_order_exist->order_updated_at = date('Y-m-d H:i:s');
                        $has_order_exist->save();
                    }
                }
            }
        }
    }
    public function GetSalesOrderBackup($userId, $userIntegrationId, $platform_workflow_id)
    {
        $return_response = false;
        try {
            $limit = 100;
            $account = $this->getPrimaryAccount($userIntegrationId);
            if ($account) {
                $EventID = "GET_SALESORDER";
                $selectFields = ['e.event_id', 'ur.status', 'ur.sync_start_date'];

                $user_work_flow = $this->map->getUserIntegWorkFlow($userIntegrationId, $EventID, $selectFields, self::$myPlatform);
                if (isset($user_work_flow[$EventID])) {

                    $order_sync_start_date = $user_work_flow[$EventID]['sync_start_date'];
                    $user_workflow = array_keys($user_work_flow); //in below $user_workflow is used as array
                    /* Check whether shipment is ON */
                    if ($user_work_flow[$EventID]['status'] == 1) {

                        $platform_urls = $this->checkPlatformURLViaURlName($userId, $userIntegrationId, 'wc_order_lasttime');
                        if ($platform_urls) {
                            /* If Order last time found */
                            $modified_after = $this->updateDateTimeISOFormat(trim($platform_urls->url), "|");
                            $five_sec_minus = date(DATE_ISO8601, strtotime($modified_after . ' -5 sec'));
                            $modified_after = $this->updateDateTimeISOFormat($five_sec_minus);
                            //dd( $modified_after,"url");
                        } else {
                            $modified_after = $this->getLastOrderDateTime($userId, $userIntegrationId);
                            //  dd( $modified_after,"db");
                        }

                        $end_created_on_date = $modified_after;
                        $current_date_60_minute = date(DATE_ISO8601, strtotime('+60 min'));
                        $modified_before = $this->updateDateTimeISOFormat($current_date_60_minute);

                        $url = "orders?modified_after=" . $modified_after . "&modified_before=" . $modified_before . "&per_page=" . $limit . "&orderby=modified&order=asc&";
                        $response = $this->wc->GetOrders($account, $url);
                        if ($orders = json_decode($response->getBody(), true)) {

                            if (!isset($orders['code'])) {

                                if ((is_array($orders))  && count($orders) > 0) {
                                    $warehouse_object_id = $this->helper->getObjectId('warehouse');
                                    /* Return all multi selected order status */
                                    $orderStatusArray = $this->map->getMappedDataByName($userIntegrationId, $platform_workflow_id, "get_sorder_status", ['api_code'], "regular", null, "multi", "source");
                                    /* If we have meta data acceptance */
                                    $AcceptMetaData = $AcceptWarehouseMetaData = "no";
                                    $MetaData = $this->map->getMappedDataByName($userIntegrationId, $platform_workflow_id, "accept_meta_data", ['custom_data'], "default");
                                    $AcceptMetaData = isset($MetaData->custom_data) && strtolower($MetaData->custom_data) == "yes" ? "yes" : "no";
                                    /* If we have switch warehouse */
                                    $switch_warehouse = $this->map->getMappedDataByName($userIntegrationId, $platform_workflow_id, "switch_warehouse", ['custom_data'], "default");
                                    $AcceptWarehouseMetaData = isset($switch_warehouse->custom_data) && strtolower($switch_warehouse->custom_data) == "yes" ? "yes" : "no";
                                    $orderStatusArray[] = "refunded";

                                    foreach ($orders as $key => $order) {

                                        $order = (object) $order; //casting array of object
                                        if ($orderStatusArray) {
                                            $end_created_on_date = $order->date_modified . "|" . $order->date_modified_gmt;
                                            /* First Check Sync Start Date time Set or Not */
                                            $byPass = app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->isValidOrder($order_sync_start_date, $order->date_created_gmt);

                                            if ($byPass) {
                                                if (in_array($order->status, $orderStatusArray)) {
                                                    if (isset($order->id)) {
                                                        $findOrder = PlatformOrder::where([
                                                            'user_id' => $userId,
                                                            'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId,
                                                            'api_order_id' => $order->id
                                                        ])->first(); //$this->checkPlatformOrderExist($userId, $userIntegrationId, $order->id);

                                                        if ($findOrder) {
                                                            if ($findOrder->api_updated_at != $order->date_modified) { //if date_modified is not equal to api_updated_at
                                                                //If order record found
                                                                if ($findOrder->sync_status == "Synced") {
                                                                    $refundStatus = "Pending";
                                                                    $cancelStatus = $order->status == "cancelled" ? 1 : 0;

                                                                    if ($cancelStatus) {
                                                                        //If when we have cancelled status
                                                                        $findOrder->file_name = $order->status;
                                                                        $findOrder->api_updated_at = $order->date_modified;
                                                                        $findOrder->sync_status = "Ready";
                                                                        $findOrder->is_voided = $cancelStatus;
                                                                        $findOrder->order_updated_at = date('Y-m-d H:i:s');
                                                                        $findOrder->save();
                                                                    } else if ($order->status == "completed" || $order->status == "processing") {
                                                                        //If payments comes with completed/processing status
                                                                        $this->AcceptOrderPaymentDetails($order, $findOrder);
                                                                    } else {
                                                                        /* Accept if GET_REFUND is ON */
                                                                        $this->AcceptRefundOrders($user_workflow, $order, $findOrder);
                                                                    }
                                                                } else if ($findOrder->sync_status == "Ready" || $findOrder->sync_status == "Pending" || $findOrder->sync_status == "Failed") {
                                                                    if ($order->status == "completed" || $order->status == "processing") {
                                                                        //If payments comes with completed/processing status
                                                                        $this->AcceptOrderPaymentDetails($order, $findOrder);
                                                                    } else if ($order->status == "refunded" && ($findOrder->linked_id == 0 || empty($findOrder->linked_id))) {
                                                                        /* If order exist but till not sync in destination platform | set ignore if refunded found */
                                                                        $order->sync_status = "Ignore";
                                                                    } else if (isset($order->refunds) && is_array($order->refunds) && count($order->refunds) > 0 && ($findOrder->linked_id == 0 || empty($findOrder->linked_id))) {
                                                                        /* If order exist but till not sync in destination platform | set ignore if refunded found */
                                                                        $order->sync_status = "Ignore";
                                                                    }
                                                                    $order_warehouse_id = $this->GetOrderWarehouse($order, $userId, $userIntegrationId, $warehouse_object_id);
                                                                    $findOrder->warehouse_id = $order_warehouse_id;
                                                                    /* Save Order Details */
                                                                    $this->SaveExistingOrderDetails($order, $findOrder);
                                                                    /* --Update Address-- */
                                                                    $this->StoreAddress($order, $findOrder->id, "update");
                                                                    /* --Insert Line Items-- */
                                                                    $isLineItemSaved = $this->StoreLineItems($order, $findOrder->id, $AcceptMetaData, $AcceptWarehouseMetaData, "update");
                                                                    $switchWarehouse = $isLineItemSaved['return'];

                                                                    /* --Insert Line Items--*/
                                                                    if ($switchWarehouse == "false") {
                                                                        $this->platformOrder->where('id', $findOrder->id)->update(["warehouse_id" => null]);
                                                                    }
                                                                    /* --Custom Field-- */
                                                                    $this->StoreCustomField($order, $findOrder->id, $userIntegrationId);
                                                                }
                                                            }
                                                        } else {
                                                            //IF order not found then create new one
                                                            //  if (app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->isOldDate($request->date_created)) { //validate 1 day old order date
                                                            // if order record not found
                                                            $ignoreOrders = true;
                                                            $refundStatus = "Pending";
                                                            if (in_array($order->status, ["cancelled", "refunded", "failed", "trash"])) {
                                                                $ignoreOrders = false;
                                                            }
                                                            if ($ignoreOrders) {
                                                                $findDuplicateOrder = PlatformOrder::where([
                                                                    'user_id' => $userId,
                                                                    'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId,
                                                                    'api_order_id' => $order->id
                                                                ])->count();
                                                                if (!$findDuplicateOrder) {


                                                                    /* Check  Customer ID If not found search via API Call */
                                                                    if (isset($order->customer_id) && $order->customer_id !== 0) {
                                                                        $CustomerID = $order->customer_id;
                                                                        $CustomerID = $this->SearchCustomerByID($CustomerID, $userId, $userIntegrationId, $this->platformId);
                                                                        if (is_int($CustomerID)) {
                                                                            $CustomerID = $CustomerID;
                                                                        } else {
                                                                            $CustomerID = 0;
                                                                        }
                                                                    } else {
                                                                        $CustomerID = 0;
                                                                    }
                                                                    $order->platform_customer_id = $CustomerID;
                                                                    /* Set Cancel order status for is_voided=1 if found */
                                                                    $cancelStatus = $order->status == "cancelled" ? 1 : 0;
                                                                    $order->is_voided = $cancelStatus;
                                                                    $order_warehouse_id = $this->GetOrderWarehouse($order, $userId, $userIntegrationId, $warehouse_object_id);
                                                                    $order->warehouse_id = $order_warehouse_id;
                                                                    $order->refund_sync_status = $refundStatus;
                                                                    $lastOrderID = $this->StoreOrderDetails($order, $userId, $userIntegrationId, $platform_workflow_id);
                                                                    /*-- Store Address-- */
                                                                    $isAddressSaved = $this->StoreAddress($order, $lastOrderID);
                                                                    /* --Insert Line Items--*/
                                                                    $isLineItemSaved = $this->StoreLineItems($order, $lastOrderID, $AcceptMetaData, $AcceptWarehouseMetaData);
                                                                    $switchWarehouse = $isLineItemSaved['return'];
                                                                    $itemsaved = $isLineItemSaved['items'];
                                                                    if ($switchWarehouse == "false") {
                                                                        $this->platformOrder->where('id', $lastOrderID)->update(["warehouse_id" => null]);
                                                                    }

                                                                    /* --Custom Field-- */
                                                                    $this->StoreCustomField($order, $lastOrderID, $userIntegrationId);
                                                                    /* --Insert Transaction/Payments-- */
                                                                    $this->StorePaymentDetails($order, $lastOrderID);
                                                                    /* If address or item is missing| set order status as Pending */
                                                                    if (!$itemsaved || !$isAddressSaved) {
                                                                        $this->platformOrder->where('id', $lastOrderID)->update(["sync_status" => "Pending"]);
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    if ($orders) {
                                        if ($platform_urls) {
                                            //Update last order fetch created time
                                            $platform_urls->url = $end_created_on_date;
                                            $platform_urls->save();
                                        } else {
                                            //insert last order fetch created time
                                            $this->platformUrl->insert([
                                                'user_id' => $userId,
                                                'platform_id' => $this->platformId,
                                                'user_integration_id' => $userIntegrationId,
                                                'url' => $end_created_on_date,
                                                'url_name' => 'wc_order_lasttime',
                                            ]);
                                        }
                                    }
                                }
                            } else {
                                $return_response = $orders['code'];
                            }
                        } else {
                            if ($platform_urls == "" || empty($platform_urls)) {
                                //insert last order fetch created time
                                $this->platformUrl->insert([
                                    'user_id' => $userId,
                                    'platform_id' => $this->platformId,
                                    'user_integration_id' => $userIntegrationId,
                                    'url' => $end_created_on_date,
                                    'url_name' => 'wc_order_lasttime',
                                ]);
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
    public function GetSalesOrderBackupDemo($userId, $userIntegrationId, $platform_workflow_id, $orderId)
    {
        $return_response = false;
        try {
            $limit = 100;
            $account = $this->getPrimaryAccount($userIntegrationId);
            if ($account) {
                $EventID = "GET_SALESORDER";
                $selectFields = ['e.event_id', 'ur.status', 'ur.sync_start_date'];

                $user_work_flow = $this->map->getUserIntegWorkFlow($userIntegrationId, $EventID, $selectFields, self::$myPlatform);
                if (isset($user_work_flow[$EventID])) {

                    $order_sync_start_date = $user_work_flow[$EventID]['sync_start_date'];
                    $user_workflow = array_keys($user_work_flow); //in below $user_workflow is used as array
                    /* Check whether shipment is ON */
                    if ($user_work_flow[$EventID]['status'] == 1) {
                        $url = "orders/{$orderId}?";
                        $response = $this->wc->GetOrders($account, $url);
                        if ($orders = json_decode($response->getBody(), true)) {

                            if (!isset($orders['code'])) {
                                $orders = [$orders];
                                if ((is_array($orders))  && count($orders) > 0) {
                                    $warehouse_object_id = $this->helper->getObjectId('warehouse');
                                    /* Return all multi selected order status */
                                    $orderStatusArray = $this->map->getMappedDataByName($userIntegrationId, $platform_workflow_id, "get_sorder_status", ['api_code'], "regular", null, "multi", "source");
                                    /* If we have meta data acceptance */
                                    $AcceptMetaData = $AcceptWarehouseMetaData = "no";
                                    $MetaData = $this->map->getMappedDataByName($userIntegrationId, $platform_workflow_id, "accept_meta_data", ['custom_data'], "default");
                                    $AcceptMetaData = isset($MetaData->custom_data) && strtolower($MetaData->custom_data) == "yes" ? "yes" : "no";
                                    /* If we have switch warehouse */
                                    $switch_warehouse = $this->map->getMappedDataByName($userIntegrationId, $platform_workflow_id, "switch_warehouse", ['custom_data'], "default");
                                    $AcceptWarehouseMetaData = isset($switch_warehouse->custom_data) && strtolower($switch_warehouse->custom_data) == "yes" ? "yes" : "no";
                                    $orderStatusArray[] = "refunded";

                                    foreach ($orders as $key => $order) {

                                        $order = (object) $order; //casting array of object
                                        if ($orderStatusArray) {
                                            $end_created_on_date = $order->date_modified . "|" . $order->date_modified_gmt;
                                            /* First Check Sync Start Date time Set or Not */
                                            $byPass = app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->isValidOrder($order_sync_start_date, $order->date_created_gmt);

                                            if ($byPass) {
                                                if (in_array($order->status, $orderStatusArray)) {
                                                    if (isset($order->id)) {
                                                        $findOrder = PlatformOrder::where([
                                                            'user_id' => $userId,
                                                            'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId,
                                                            'api_order_id' => $order->id
                                                        ])->first(); // $this->checkPlatformOrderExist($userId, $userIntegrationId, $order->id);
                                                        if ($findOrder) {
                                                            if ($findOrder->api_updated_at != $order->date_modified) { //if date_modified is not equal to api_updated_at
                                                                //If order record found
                                                                if ($findOrder->sync_status == "Synced") {
                                                                    $refundStatus = "Pending";
                                                                    $cancelStatus = $order->status == "cancelled" ? 1 : 0;

                                                                    if ($cancelStatus) {
                                                                        //If when we have cancelled status
                                                                        $findOrder->file_name = $order->status;
                                                                        $findOrder->api_updated_at = $order->date_modified;
                                                                        $findOrder->sync_status = "Ready";
                                                                        $findOrder->is_voided = $cancelStatus;
                                                                        $findOrder->order_updated_at = date('Y-m-d H:i:s');
                                                                        $findOrder->save();
                                                                    } else if ($order->status == "completed" || $order->status == "processing") {
                                                                        //If payments comes with completed/processing status
                                                                        $this->AcceptOrderPaymentDetails($order, $findOrder);
                                                                    } else {
                                                                        /* Accept if GET_REFUND is ON */
                                                                        $this->AcceptRefundOrders($user_workflow, $order, $findOrder);
                                                                    }
                                                                } else if ($findOrder->sync_status == "Ready" || $findOrder->sync_status == "Pending" || $findOrder->sync_status == "Failed") {
                                                                    if ($order->status == "completed" || $order->status == "processing") {
                                                                        //If payments comes with completed/processing status
                                                                        $this->AcceptOrderPaymentDetails($order, $findOrder);
                                                                    } else if ($order->status == "refunded" && ($findOrder->linked_id == 0 || empty($findOrder->linked_id))) {
                                                                        /* If order exist but till not sync in destination platform | set ignore if refunded found */
                                                                        $order->sync_status = "Ignore";
                                                                    } else if (isset($order->refunds) && is_array($order->refunds) && count($order->refunds) > 0 && ($findOrder->linked_id == 0 || empty($findOrder->linked_id))) {
                                                                        /* If order exist but till not sync in destination platform | set ignore if refunded found */
                                                                        $order->sync_status = "Ignore";
                                                                    }
                                                                    $order_warehouse_id = $this->GetOrderWarehouse($order, $userId, $userIntegrationId, $warehouse_object_id);
                                                                    $findOrder->warehouse_id = $order_warehouse_id;
                                                                    /* Save Order Details */
                                                                    $this->SaveExistingOrderDetails($order, $findOrder);
                                                                    /* --Update Address-- */
                                                                    $this->StoreAddress($order, $findOrder->id, "update");
                                                                    /* --Insert Line Items-- */
                                                                    $isLineItemSaved = $this->StoreLineItems($order, $findOrder->id, $AcceptMetaData, $AcceptWarehouseMetaData, "update");
                                                                    $switchWarehouse = $isLineItemSaved['return'];

                                                                    /* --Insert Line Items--*/
                                                                    if ($switchWarehouse == "false") {
                                                                        $this->platformOrder->where('id', $findOrder->id)->update(["warehouse_id" => null]);
                                                                    }
                                                                    /* --Custom Field-- */
                                                                    $this->StoreCustomField($order, $findOrder->id, $userIntegrationId);
                                                                }
                                                            }
                                                        } else {
                                                            //IF order not found then create new one
                                                            //  if (app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->isOldDate($request->date_created)) { //validate 1 day old order date
                                                            // if order record not found
                                                            $ignoreOrders = true;
                                                            $refundStatus = "Pending";
                                                            if (in_array($order->status, ["cancelled", "refunded", "failed", "trash"])) {
                                                                $ignoreOrders = false;
                                                            }
                                                            if ($ignoreOrders) {
                                                                /* Check  Customer ID If not found search via API Call */
                                                                if (isset($order->customer_id) && $order->customer_id !== 0) {
                                                                    $CustomerID = $order->customer_id;
                                                                    $CustomerID = $this->SearchCustomerByID($CustomerID, $userId, $userIntegrationId, $this->platformId);
                                                                    if (is_int($CustomerID)) {
                                                                        $CustomerID = $CustomerID;
                                                                    } else {
                                                                        $CustomerID = 0;
                                                                    }
                                                                } else {
                                                                    $CustomerID = 0;
                                                                }
                                                                $order->platform_customer_id = $CustomerID;
                                                                /* Set Cancel order status for is_voided=1 if found */
                                                                $cancelStatus = $order->status == "cancelled" ? 1 : 0;
                                                                $order->is_voided = $cancelStatus;
                                                                $order_warehouse_id = $this->GetOrderWarehouse($order, $userId, $userIntegrationId, $warehouse_object_id);
                                                                $order->warehouse_id = $order_warehouse_id;
                                                                $order->refund_sync_status = $refundStatus;
                                                                $lastOrderID = $this->StoreOrderDetails($order, $userId, $userIntegrationId, $platform_workflow_id);
                                                                /*-- Store Address-- */
                                                                $isAddressSaved = $this->StoreAddress($order, $lastOrderID);
                                                                /* --Insert Line Items--*/
                                                                $isLineItemSaved = $this->StoreLineItems($order, $lastOrderID, $AcceptMetaData, $AcceptWarehouseMetaData);
                                                                $switchWarehouse = $isLineItemSaved['return'];
                                                                $itemsaved = $isLineItemSaved['items'];
                                                                if ($switchWarehouse == "false") {
                                                                    $this->platformOrder->where('id', $lastOrderID)->update(["warehouse_id" => null]);
                                                                }

                                                                /* --Custom Field-- */
                                                                $this->StoreCustomField($order, $lastOrderID, $userIntegrationId);
                                                                /* --Insert Transaction/Payments-- */
                                                                $this->StorePaymentDetails($order, $lastOrderID);
                                                                /* If address or item is missing| set order status as Pending */
                                                                if (!$itemsaved || !$isAddressSaved) {
                                                                    $this->platformOrder->where('id', $lastOrderID)->update(["sync_status" => "Pending"]);
                                                                }
                                                            }

                                                            //  }

                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            } else {
                                $return_response = $orders['code'];
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

    /* Process Pending Orders */
    public function ProcessPendingOrders($userId, $userIntegrationId, $platform_workflow_id, $sync_status = "Pending", $account = null)
    {
        $return_response = false;
        try {
            $limit = 20;
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($userIntegrationId);
            }

            if ($account) {
                $list = PlatformOrder::where([
                    'user_integration_id' => $userIntegrationId,
                    'platform_id' => $this->platformId,
                    'sync_status' => $sync_status,
                    'linked_id' => 0,
                ])->limit($limit)->orderBy('updated_at', 'ASC')->get();

                if (count($list) > 0) {
                    $warehouse_object_id = $this->helper->getObjectId('warehouse');
                    /* Return all multi selected order status */
                    $orderStatusArray = $this->map->getMappedDataByName($userIntegrationId, $platform_workflow_id, "get_sorder_status", ['api_code'], "regular", null, "multi", "source");
                    /* If we have meta data acceptance */
                    $AcceptMetaData = $AcceptWarehouseMetaData = "no";
                    $MetaData = $this->map->getMappedDataByName($userIntegrationId, $platform_workflow_id, "accept_meta_data", ['custom_data'], "default");
                    $AcceptMetaData = isset($MetaData->custom_data) && strtolower($MetaData->custom_data) == "yes" ? "yes" : "no";
                    /* If we have switch warehouse */
                    $switch_warehouse = $this->map->getMappedDataByName($userIntegrationId, $platform_workflow_id, "switch_warehouse", ['custom_data'], "default");
                    $AcceptWarehouseMetaData = isset($switch_warehouse->custom_data) && strtolower($switch_warehouse->custom_data) == "yes" ? "yes" : "no";
                    foreach ($list as $order) {
                        if (isset($order->api_order_id)) {
                            $url = "orders/{$order->api_order_id}?";
                            $response = $this->wc->CallAPI($account, "GET", $url);
                            $value = json_decode($response->getBody(), true);

                            if ($value) {

                                if (!isset($value['code'])) {
                                    $value = (object) $value; //casting array to object

                                    if ($orderStatusArray) {

                                        if (in_array($value->status, $orderStatusArray)) {
                                            if (in_array($value->status, ["completed", "processing"])) {
                                                //If payments comes with completed status
                                                $this->StorePaymentDetails($value, $order->id);
                                                $order->file_name = $value->status;
                                                $order->save();
                                            }
                                            /* Check Customer ID If not found search via API Call */
                                            if (isset($value->customer_id) && $value->customer_id !== 0) {
                                                $CustomerID = $value->customer_id;
                                                $CustomerID = $this->SearchCustomerByID($CustomerID, $userId, $userIntegrationId, $this->platformId);
                                                if (is_int($CustomerID)) {
                                                    $CustomerID = $CustomerID;
                                                } else {
                                                    $CustomerID = 0;
                                                }
                                            } else {
                                                $CustomerID = 0;
                                            }
                                            /* ----------------- */
                                            /* Set Cancel order status for is_voided=1 if found */
                                            $cancelStatus = $value->status == "cancelled" ? 1 : 0;
                                            $order_warehouse_id = $this->GetOrderWarehouse($value, $userId, $userIntegrationId, $warehouse_object_id);
                                            /* Asign some object values */
                                            $value->platform_customer_id = $CustomerID;
                                            $value->is_voided = $cancelStatus;

                                            $order->warehouse_id = $order_warehouse_id;
                                            $order->customer_email = $value->billing['email'];
                                            $order->api_order_id = $value->id;
                                            $order->order_number = $value->number;
                                            $order->order_date = isset($value->date_created) ? $value->date_created : null;
                                            $order->total_discount = $value->discount_total;
                                            $order->discount_tax = $value->discount_tax; //discount_tax
                                            $order->shipping_total = $value->shipping_total; //shipping_total
                                            $order->shipping_tax = $value->shipping_tax; //shipping_tax
                                            $order->total_tax = $value->total_tax;
                                            $order->total_amount = $value->total;
                                            $order->notes = $value->customer_note;
                                            $order->file_name = $value->status;
                                            $order->carrier_code = $value->payment_method;
                                            $order->payment_date = $value->date_paid;
                                            $order->currency = $value->currency;
                                            $order->shipping_method = isset($value->shipping_lines[0]['instance_id']) ? $value->shipping_lines[0]['instance_id'] : null;
                                            $order->order_updated_at = date('Y-m-d H:i:s');
                                            $order->save();
                                            /* --Update Address-- */
                                            $isAddressSaved = $this->StoreAddress($value, $order->id, "update");
                                            /* --Insert Line Items-- */
                                            $isLineItemSaved = $this->StoreLineItems($value, $order->id, $AcceptMetaData, $AcceptWarehouseMetaData, "update");
                                            $switchWarehouse = $isLineItemSaved['return'];
                                            $itemsaved = $isLineItemSaved['items'];

                                            if ($switchWarehouse == "false") {
                                                $order->warehouse_id = null;
                                            }
                                            /* --Custom Field-- */
                                            $this->StoreCustomField($value, $order->id, $userIntegrationId);
                                            /* If address or item is missing| set order status as Pending */
                                            if (!$itemsaved || !$isAddressSaved) {
                                                $order->sync_status = "Pending";
                                            } else {
                                                $order->sync_status = "Ready";
                                            }
                                            $order->updated_at = date('Y-m-d H:i:s');
                                            $order->save();
                                        }
                                    }
                                } else if (isset($value['code']) && $value['code'] == "woocommerce_rest_shop_order_invalid_id") {
                                    $order->sync_status = "Inactive";
                                    $order->updated_at = date('Y-m-d H:i:s');
                                    $order->save();
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

    public function isOldDate($orderDate)
    {
        $olddate = date(DATE_ISO8601, strtotime('- 12 day'));
        $order_created_date = date(DATE_ISO8601, strtotime($orderDate));
        if ($olddate < $order_created_date) {
            return true;
        }
        return false;
    }
    /* Check Sync Start Date And Order date */
    public function isValidOrder($order_sync_start_date, $date_created)
    {
        if (isset($order_sync_start_date) && !empty($order_sync_start_date)) {
            $FromDate = date(DATE_ISO8601, strtotime($order_sync_start_date));
            $ToDate = date(DATE_ISO8601, strtotime($date_created));
            if ($FromDate < $ToDate) {
                $byPass = true;
            } else {
                $byPass = false;
            }
        } else {
            $byPass = true;
        }
        return $byPass;
    }
    /* Store Order */
    private function StoreOrderDetails($order, $user_id, $user_integration_id, $platform_workflow_rule_id)
    {
        $order_shipping_method =  isset($order->shipping_lines[0]['instance_id']) ? $order->shipping_lines[0]['instance_id'] : null;

        if ($order_shipping_method && count($order->shipping_lines) > 1) {

            $ignoreShippingMethods = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "ignore_shipping_method", ['custom_data'], "default");

            //For multiple shipping method ignore shipping methods as user given in mapping == $ignoreShippingMethods
            if ($ignoreShippingMethods && $ignoreShippingMethods->custom_data) {
                $ignoreShippingMethods = explode(',', $ignoreShippingMethods->custom_data);
                $ignoreShippingMethods = array_filter($ignoreShippingMethods);

                $object_id = $this->helper->getObjectId('shipping_method');

                for ($i = 0; $i < count($order->shipping_lines); $i++) {
                    $shipping_method = $this->map->getObjectDataByFilterData($user_id, $user_integration_id, $this->platformId, $object_id, "api_id", $order->shipping_lines[$i]['instance_id'], ["name"]);

                    if (isset($shipping_method->name) && !in_array($shipping_method->name, $ignoreShippingMethods)) {
                        $order_shipping_method = $order->shipping_lines[$i]['instance_id'];
                        $ignore = false;
                        break;
                    } else {
                        $ignore = true;
                    }
                }
                if ($ignore) { //if all shipping method in ignore list make shipping method == null
                    $order_shipping_method = null;
                }
            }
        }

        $order = [
            'user_id' => $user_id,
            'platform_id' => $this->platformId,
            'user_integration_id' => $user_integration_id,
            'platform_customer_id' => $order->platform_customer_id,
            'order_type' => "SO",
            'customer_email' => $order->billing['email'],
            'api_order_id' => $order->id,
            'order_number' => $order->number,
            'order_date' => isset($order->date_created) ? $order->date_created : null,
            'total_discount' => $order->discount_total,
            'discount_tax' => $order->discount_tax, //discount_tax
            'shipping_total' => $order->shipping_total, //shipping_total
            'shipping_tax' => $order->shipping_tax, //shipping_tax
            'total_tax' => $order->total_tax,
            'total_amount' => $order->total,
            'notes' => $order->customer_note,
            'file_name' => $order->status,
            'sync_status' => "Ready",
            'carrier_code' => $order->payment_method,
            'payment_date' => $order->date_paid,
            'currency' => $order->currency,
            'warehouse_id' => $order->warehouse_id,
            'shipping_method' => $order_shipping_method,
            'refund_sync_status' => $order->refund_sync_status,
            'is_voided' => $order->is_voided,
            'order_updated_at' => date('Y-m-d H:i:s'),
            'api_updated_at' => $order->date_modified,
        ];

        return $this->mobj->makeInsertGetId('platform_order', $order);
    }
    /* Store Address */
    private function StoreAddress($order, $platform_order_id, $type = "insert")
    {
        /* Custom Meta Field Data that contain a "no_field" attribute which is a basically a phone no. and will pass to shipping phone number */
        $shipPhoneNumber = null;
        if (isset($order->meta_data)) {
            foreach ($order->meta_data as $index => $meta_data) {
                if (isset($meta_data['key']) && $meta_data['key'] == "no_field") {
                    $shipPhoneNumber = isset($meta_data['value']) ? 'PO-' . $meta_data['value'] : null;
                    break;
                }
            }
        }

        $returnId = true;
        /* If country and address1 not found ,Copy billing address as shipping address */
        $billingAddress = [
            'platform_order_id' => $platform_order_id,
            'address_type' => 'billing',
            'firstname' => $order->billing['first_name'],
            'lastname' => $order->billing['last_name'],
            'company' => $order->billing['company'],
            'address_name' => $order->billing['first_name'] . " " . $order->billing['last_name'],
            'address1' => $order->billing['address_1'],
            'address2' => $order->billing['address_2'],
            'city' => $order->billing['city'],
            'state' => $order->billing['state'],
            'postal_code' => $order->billing['postcode'],
            'country' => $order->billing['country'],
            'phone_number' => $order->billing['phone'],
            'phone_number2' => null,
            'email' => $order->billing['email'],
        ];
        if (empty($order->shipping['address_1']) || empty($order->shipping['country']) || !isset($order->shipping['address_1']) || !isset($order->shipping['country'])) {
            $shippingAddress = [
                'platform_order_id' => $platform_order_id,
                'address_type' => 'shipping',
                'firstname' => $order->billing['first_name'],
                'lastname' => $order->billing['last_name'],
                'company' => $order->billing['company'],
                'address_name' => $order->billing['first_name'] . " " . $order->billing['last_name'],
                'address1' => $order->billing['address_1'],
                'address2' => $order->billing['address_2'],
                'city' => $order->billing['city'],
                'state' => $order->billing['state'],
                'postal_code' => $order->billing['postcode'],
                'country' => $order->billing['country'],
                'phone_number' => $order->billing['phone'],
                'phone_number2' =>  !empty($shipPhoneNumber) ? $shipPhoneNumber : null, //add as mobile number
                'email' => $order->billing['email'],
            ];
        } else {
            $shippingAddress = [
                'platform_order_id' => $platform_order_id,
                'address_type' => 'shipping',
                'firstname' => $order->shipping['first_name'],
                'lastname' => $order->shipping['last_name'],
                'company' => $order->shipping['company'],
                'address_name' => $order->shipping['first_name'] . " " . $order->shipping['last_name'],
                'address1' => $order->shipping['address_1'],
                'address2' => $order->shipping['address_2'],
                'city' => $order->shipping['city'],
                'state' => $order->shipping['state'],
                'postal_code' => $order->shipping['postcode'],
                'country' => $order->shipping['country'],
                'phone_number' => isset($order->shipping['phone']) ? $order->shipping['phone'] : null,
                'phone_number2' =>  !empty($shipPhoneNumber) ? $shipPhoneNumber : null, //add as mobile number
                'email' => $order->billing['email'],
            ];
        }
        if ($type == "insert") {
            $addresses = [
                $billingAddress,
                $shippingAddress,
            ];
            $update = $this->mobj->makeInsert('platform_order_address', $addresses);
            if (!isset($update)) {
                $returnId = false;
            }
        } else if ($type == "update") {
            /* Update Billing Address */
            $whereBilling = [
                'platform_order_id' => $platform_order_id,
                'address_type' => 'billing',
            ];
            $update = PlatformOrderAddress::updateOrCreate($whereBilling, $billingAddress);
            if (!isset($update->id)) {
                $returnId = false;
            }

            /* Update Shipping Address */
            $whereShipping = [
                'platform_order_id' => $platform_order_id,
                'address_type' => 'shipping',
            ];

            $update = PlatformOrderAddress::updateOrCreate($whereShipping, $shippingAddress);
            if (!isset($update->id)) {
                $returnId = false;
            }
        }
        return $returnId;
    }
    /* Store Custom Fields */
    private function StoreCustomField($order, $platform_order_id, $user_integration_id)
    {
        if (isset($order->customer_note) && $order->customer_note) {
            $ObjectId = $this->helper->getObjectId('sales_order');
            $find_attribute = $this->mobj->getFirstResultByConditions('platform_fields', [
                'user_id' => 0,
                'name' => 'customer_note',
                'platform_id' => $this->platformId,
                'type' => 'sales_order',
                'field_type' => 'custom',
                'user_integration_id' => 0,
                'platform_object_id' => $ObjectId,
                'status' => 1,
            ], ['id']);
            if ($find_attribute) {
                $fields = array(
                    'platform_field_id' => $find_attribute->id,
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'field_value' => $order->customer_note,
                    'record_id' => $platform_order_id,
                );
                $platform_custom_field = $this->mobj->getFirstResultByConditions('platform_custom_field_values', ['record_id' => $platform_order_id, 'user_integration_id' => $user_integration_id, 'platform_field_id' => $find_attribute->id], ['id']);
                if ($platform_custom_field) {
                    $this->mobj->makeUpdate('platform_custom_field_values', $fields, ['id' => $platform_custom_field->id]);
                } else {
                    $this->mobj->makeInsert('platform_custom_field_values', $fields);
                }
            }
        }
    }
    /* Store Line Items */
    private function StoreLineItems($order, $platform_order_id, $AcceptMetaData = "no", $AcceptWarehouseMetaData = "no", $type = "insert")
    {
        $return = null;
        $itemInserted = true;
        if ($type == "insert") {
            /* Main Line Items */

            if (isset($order->line_items)) {
                $lineItems = [];
                $swarehouse = [];
                foreach ($order->line_items as $key => $value) {
                    $taxcode = null;
                    if (!empty($value['taxes']) && isset($value['taxes'])) {
                        $taxcode = $value['taxes'][0]['id'];
                    }
                    $meta_name = "\n\n";
                    if (strtolower($AcceptMetaData) == "yes") {
                        if (!empty($value['meta_data']) && isset($value['meta_data'])) {

                            foreach ($value['meta_data'] as $mkey => $mval) {
                                if (isset($mval['display_key']) && isset($mval['display_value']) && $mval['display_key'] != "_reduced_stock" && !is_array($mval['display_value']) && !is_object($mval['display_value'])) {
                                    $meta_name .= $mval['display_key'] . "-" . $mval['display_value'] . "\n\n";
                                }
                            }
                        }
                    }
                    /* Switch Warehouse */

                    if (strtolower($AcceptWarehouseMetaData) == "yes") {
                        if (!empty($value['meta_data']) && isset($value['meta_data'])) {

                            foreach ($value['meta_data'] as $mkey => $mval) {

                                if ($mval['key'] == "_order_item_wh" && isset($mval['key'])) {
                                    array_push($swarehouse, $mval['value']);
                                    break;
                                }
                            }
                        }
                    }
                    $meta_name = rtrim($meta_name, "\n\n");
                    $lineItems[] = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                        'api_product_id' => $value['product_id'],
                        'product_name' => $value['name'] . " " . $meta_name,
                        'sku' => $value['sku'],
                        'qty' => $value['quantity'],
                        'taxes' => $taxcode,
                        'total_tax' => 0, //total tax
                        'subtotal' => $value['subtotal'], //sub total
                        'subtotal_tax' => $value['total_tax'], //sub total tax
                        'total' => $value['total'],
                        'unit_price' => $value['price'],
                        'variation_id' => $value['variation_id'],
                        'row_type' => "ITEM",
                        'item_row_sequence' => 1,
                    ];
                }
                if (!empty($swarehouse)) {
                    //if all array value is common return true
                    $return = (count(array_unique($swarehouse)) === 1) ? "true" : "false";
                }
                if (!empty($lineItems)) {
                    $productId = $this->mobj->makeInsert('platform_order_line', $lineItems);
                    if (!isset($productId)) {
                        $itemInserted = false;
                    }
                    $lineItems = null;
                }
            }
            /* Shipping Lines */
            if (isset($order->shipping_lines)) {
                $lineItems = [];
                foreach ($order->shipping_lines as $key => $value) {
                    $taxcode = null;

                    if (!empty($value['taxes']) && isset($value['taxes'])) {
                        $taxcode = $value['taxes'][0]['id'];
                    }

                    $lineItems[] = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                        'api_product_id' => null,
                        'product_name' => $value['method_title'],
                        'sku' => null,
                        'qty' => 1,
                        'taxes' => $taxcode,
                        'total_tax' => $value['total_tax'], //total tax
                        'subtotal_tax' => $value['total_tax'], //sub total tax
                        'subtotal' => $value['total'], //sub total
                        'total' => $value['total'],
                        'row_type' => "SHIPPING",
                        'item_row_sequence' => 2,

                    ];
                }
                if (!empty($lineItems)) {
                    $productId = $this->mobj->makeInsert('platform_order_line', $lineItems);
                    if (!isset($productId)) {
                        $itemInserted = false;
                    }
                    $lineItems = null;
                }
            }
            /* Coupons Lines */
            if (isset($order->coupon_lines)) {
                $lineItems = [];
                foreach ($order->coupon_lines as $key => $value) {

                    $lineItems[] = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                        'api_product_id' => null,
                        'product_name' => $value['code'],
                        'sku' => null,
                        'qty' => 1,
                        'taxes' => null,
                        'total_tax' => "-" . $value['discount_tax'], //total tax
                        'subtotal_tax' => 0, //"-" . $value['discount_tax'], //sub total tax
                        'subtotal' => "-" . $value['discount'], //sub total
                        'total' => "-" . $value['discount'],

                        'row_type' => "DISCOUNT",
                        'item_row_sequence' => 3,

                    ];
                }
                if (!empty($lineItems)) {
                    $productId = $this->mobj->makeInsert('platform_order_line', $lineItems);
                    if (!isset($productId)) {
                        $itemInserted = false;
                    }
                    $lineItems = null;
                }
            }
            /* Fee Lines */
            if (isset($order->fee_lines)) {
                $lineItems = [];
                foreach ($order->fee_lines as $key => $value) {
                    $taxcode = null;

                    if (!empty($value['taxes']) && isset($value['taxes'])) {
                        $taxcode = $value['taxes'][0]['id'];
                    }
                    $lineItems[] = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                        'api_product_id' => null,
                        'product_name' => "Fee" . $value['name'],
                        'sku' => null,
                        'qty' => 1,
                        'taxes' => $taxcode,
                        'total_tax' => $value['total_tax'], //total tax
                        'subtotal_tax' => $value['total_tax'], //sub total tax
                        'subtotal' => $value['total'], //sub total
                        'total' => $value['total'],
                        'row_type' => "DISCOUNT",

                        'item_row_sequence' => 3,

                    ];
                }
                if (!empty($lineItems)) {
                    $productId = $this->mobj->makeInsert('platform_order_line', $lineItems);
                    if (!isset($productId)) {
                        $itemInserted = false;
                    }
                    $lineItems = null;
                }
            }
            /* Gift cards */
            if (isset($order->gift_cards)) {
                $lineItems = [];
                foreach ($order->gift_cards as $key => $value) {

                    $lineItems[] = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                        'api_product_id' => null,
                        'product_name' => "Gift Card-" . isset($value['code']) ? $value['code'] : 0,
                        'sku' => null,
                        'qty' => 1,
                        'taxes' => null,
                        'total_tax' => 0, //total tax
                        'subtotal_tax' => 0, //sub total tax
                        'subtotal' => "-" . $value['amount'], //sub total
                        'total' => "-" . $value['amount'],
                        'row_type' => "GIFTCARD",
                        'item_row_sequence' => 4,

                    ];
                }
                if (!empty($lineItems)) {
                    $productId = $this->mobj->makeInsert('platform_order_line', $lineItems);
                    if (!isset($productId)) {
                        $itemInserted = false;
                    }
                    $lineItems = null;
                }
            }
        } else if ($type == "update") {
            /* ----------------Insert Line Items----------- */
            $line_items = $shipping_lines = $coupon_lines = $fee_lines = $gift_cards = [];
            /* Main Line Items */
            if (isset($order->line_items)) {
                $swarehouse = [];
                foreach ($order->line_items as $key => $value) {
                    $taxcode = null;
                    if (!empty($value['taxes']) && isset($value['taxes'])) {
                        $taxcode = $value['taxes'][0]['id'];
                    }
                    $meta_name = "\n\n";
                    if (strtolower($AcceptMetaData) == "yes") {
                        if (!empty($value['meta_data']) && isset($value['meta_data'])) {

                            foreach ($value['meta_data'] as $mkey => $mval) {
                                if (isset($mval['display_key']) && isset($mval['display_value']) && $mval['display_key'] != "_reduced_stock" && !is_array($mval['display_value']) && !is_object($mval['display_value'])) {
                                    $meta_name .= $mval['display_key'] . "-" . $mval['display_value'] . "\n\n";
                                }
                            }
                        }
                    }
                    /* Switch Warehouse */

                    if (strtolower($AcceptWarehouseMetaData) == "yes") {
                        if (!empty($value['meta_data']) && isset($value['meta_data'])) {

                            foreach ($value['meta_data'] as $mkey => $mval) {

                                if ($mval['key'] == "_order_item_wh" && isset($mval['key'])) {
                                    array_push($swarehouse, $mval['value']);
                                    break;
                                }
                            }
                        }
                    }
                    $meta_name = rtrim($meta_name, "\n\n");
                    $line_items[] = $value['id'];
                    $lineItems = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                        'api_product_id' => $value['product_id'],
                        'product_name' => $value['name'] . " " . $meta_name,
                        'sku' => $value['sku'],
                        'qty' => $value['quantity'],
                        'taxes' => $taxcode,
                        'total_tax' => $value['total_tax'], //total tax
                        'subtotal' => $value['subtotal'], //sub total
                        'subtotal_tax' => $value['total_tax'], //sub total tax
                        'total' => $value['total'],
                        'unit_price' => $value['price'],
                        'variation_id' => $value['variation_id'],
                        'row_type' => "ITEM",
                        'item_row_sequence' => 1,
                    ];
                    $whereItem = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                    ];

                    $product = PlatformOrderLine::updateOrCreate($whereItem, $lineItems);
                    if (!isset($product->id)) {
                        $itemInserted = false;
                    }
                }
                if (!empty($swarehouse)) {
                    //if all array value is common return true
                    $return = (count(array_unique($swarehouse)) === 1) ? "true" : "false";
                }
            }

            /* Shipping Lines */
            if (isset($order->shipping_lines)) {

                foreach ($order->shipping_lines as $key => $value) {
                    $taxcode = null;

                    if (!empty($value['taxes']) && isset($value['taxes'])) {
                        $taxcode = $value['taxes'][0]['id'];
                    }
                    $shipping_lines[] = $value['id'];
                    $lineItems = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                        'api_product_id' => null,
                        'product_name' => $value['method_title'],
                        'sku' => null,
                        'qty' => 1,
                        'taxes' => $taxcode,
                        'total_tax' => $value['total_tax'], //total tax
                        'subtotal_tax' => $value['total_tax'], //sub total tax
                        'subtotal' => $value['total'], //sub total
                        'total' => $value['total'],
                        'row_type' => "SHIPPING",
                        'taxes' => $taxcode,
                        'item_row_sequence' => 2,

                    ];
                    $whereItem = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                    ];
                    $product = PlatformOrderLine::updateOrCreate($whereItem, $lineItems);
                    if (!isset($product->id)) {
                        $itemInserted = false;
                    }
                }
            }
            /* Coupons Lines */
            if (isset($order->coupon_lines)) {

                foreach ($order->coupon_lines as $key => $value) {

                    $coupon_lines[] = $value['id'];
                    $lineItems = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                        'api_product_id' => null,
                        'product_name' => $value['code'],
                        'sku' => null,
                        'qty' => 1,
                        'taxes' => null,
                        'total_tax' => "-" . $value['discount_tax'], //total tax
                        'subtotal_tax' => 0, // "-" . $value['discount_tax'], //sub total tax
                        'subtotal' => "-" . $value['discount'], //sub total
                        'total' => "-" . $value['discount'],
                        'taxes' => null,
                        'row_type' => "DISCOUNT",
                        'item_row_sequence' => 3,

                    ];
                    $whereItem = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                    ];
                    $product = PlatformOrderLine::updateOrCreate($whereItem, $lineItems);
                    if (!isset($product->id)) {
                        $itemInserted = false;
                    }
                }
            }
            /* Fee Lines */
            if (isset($order->fee_lines)) {

                foreach ($order->fee_lines as $key => $value) {
                    $taxcode = null;
                    if (!empty($value['taxes']) && isset($value['taxes'])) {
                        $taxcode = $value['taxes'][0]['id'];
                    }

                    $fee_lines[] = $value['id'];
                    $lineItems = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                        'api_product_id' => null,
                        'product_name' => "Fee" . $value['name'],
                        'sku' => null,
                        'qty' => 1,
                        'total_tax' => $value['total_tax'], //total tax
                        'subtotal_tax' => $value['total_tax'], //sub total tax
                        'subtotal' => $value['total'], //sub total
                        'total' => $value['total'],
                        'row_type' => "DISCOUNT",
                        'taxes' => $taxcode,
                        'item_row_sequence' => 3,

                    ];
                    $whereItem = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                    ];
                    $product = PlatformOrderLine::updateOrCreate($whereItem, $lineItems);
                    if (!isset($product->id)) {
                        $itemInserted = false;
                    }
                }
            }
            /* Gift cards */
            if (isset($order->gift_cards)) {

                foreach ($order->gift_cards as $key => $value) {

                    $gift_cards[] = $value['id'];
                    $lineItems = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                        'api_product_id' => null,
                        'product_name' => "Gift Card-" . isset($value['code']) ? $value['code'] : 0,
                        'sku' => null,
                        'qty' => 1,
                        'taxes' => null,
                        'total_tax' => 0, //total tax
                        'subtotal_tax' => 0, //sub total tax
                        'subtotal' => "-" . $value['amount'], //sub total
                        'total' => "-" . $value['amount'],
                        'row_type' => "GIFTCARD",
                        'item_row_sequence' => 4,

                    ];
                    $whereItem = [
                        'platform_order_id' => $platform_order_id,
                        'api_order_line_id' => isset($value['id']) ? $value['id'] : 0,
                    ];
                    $product = PlatformOrderLine::updateOrCreate($whereItem, $lineItems);
                    if (!isset($product->id)) {
                        $itemInserted = false;
                    }
                }
            }
            /* Merge All Line Item Ids */
            $final_lines = array_merge($line_items, $shipping_lines, $coupon_lines, $fee_lines, $gift_cards);
            /* Set is_deleted=1 if line items not found */
            PlatformOrderLine::where('platform_order_id', $platform_order_id)->whereNotIn('api_order_line_id', $final_lines)->update(['is_deleted' => 1]);
        }
        return ['return' => $return, 'items' => $itemInserted];
    }
    /* Store Payment Details */
    private function StorePaymentDetails($order, $platform_order_id)
    {
        /* -------------Insert Transaction/Payments------------------ */
        if (in_array($order->status, ["completed", "processing"])) {
            $paymentDetails =
                [
                    'platform_order_id' => $platform_order_id,
                    'transaction_id' => $order->transaction_id,
                    'transaction_datetime' => $order->date_paid,
                    'transaction_type' => $order->created_via,
                    'transaction_method' => $order->payment_method,
                    'transaction_amount' => $order->total,
                    'transaction_reference' => $order->payment_method_title,
                    'sync_status' => "Ready",
                ];
            $this->CheckAndSaveTransaction($paymentDetails);
        }
    }

    /* Get Warehouse and Update */
    public function GetOrderWarehouse($order, $user_id, $user_integration_id, $warehouse_object_id)
    {
        $return = null;

        if (isset($order->warehouse) && $order->warehouse !== false) {
            $ord_warehouse = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $warehouse_object_id, 'api_id' => $order->warehouse], ['id']);
            if ($ord_warehouse) {
                $order_warehouse_id = $ord_warehouse->id;
            } else {

                $order_warehouse_id = $this->mobj->makeInsertGetId('platform_object_data', [
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'api_id' => $order->warehouse,
                    'name' => $order->warehouse,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $warehouse_object_id,

                ]);
            }
            $return = $order_warehouse_id;
        }
        return $return;
    }
    /* Receive Product create/update/delete webhook from WooCommerce */
    public function ReceiveProductWebhook(Request $request, $userIntegrationId)
    {
        $this->mobj->AddMemory();
        if ($request->isMethod('post')) {
            // \Log::channel('webhook')->info("userIntegrationId-" . $userIntegrationId . " Webhook Created Date: " . date('Y-m-d H:i:s') . "Product ID-" . $request->id);

            if (isset($request->id)) {
                $productApiId = (string) $request->id;
                $userIntegrationId = (int)$userIntegrationId;
                \Log::channel('webhook')->info("Integration " . $userIntegrationId . " Product_WOO_hook -ProductPrimaryId=" . $productApiId . " Created Date : " . date('Y-m-d H:i:s'));
                // $EventIDs = ["GET_PRODUCT", "GET_SALESORDER", "GET_INVENTORY"];
                $integration = $this->map->getUserIntegrationDetailsById($userIntegrationId, self::$myPlatform);

                if ($integration) {
                    $userId = (int)$integration->user_id;
                    $is_deleted = false;
                    if (!isset($request->date_created)) {
                        //This part is check if we don't get the attribute means product is deleted
                        $is_deleted = true;
                    }
                    // $user_work_flow = DB::table('user_workflow_rule as ur')->select('e.event_id')
                    //     ->join('platform_workflow_rule as pr', 'ur.platform_workflow_rule_id', '=', 'pr.id')
                    //     ->join('platform_events as e', 'pr.source_event_id', '=', 'e.id')
                    //     ->where('pr.status', 1)
                    //     ->where('ur.status', 1)
                    //     ->where('e.status', 1)
                    //     ->where('ur.user_id', $integration->user_id)
                    //     ->where('ur.user_integration_id', $userIntegrationId);

                    // $user_workflow_array = $user_work_flow->pluck('e.event_id')->toArray();

                    // if ($user_workflow_array) {
                    //     /* Check whether product sync or order is ON or OFF */
                    //     $findEvents = array_intersect($EventIDs, $user_workflow_array);
                    //     if (!empty($findEvents)) {
                    $categories = $wholeSalePrice = null;
                    if ($is_deleted) {
                        PlatformProduct::where([
                            'user_id' => $userId,
                            'user_integration_id' => $userIntegrationId,
                            'platform_id' => $this->platformId,
                            'api_product_id' => $productApiId,
                        ])->update(['is_deleted' => 1]);
                    } else {
                        if (isset($request->categories)) {
                            foreach ($request->categories as $key => $cat) {
                                $categories .= $cat['id'] . ",";
                            }
                            $categories = rtrim($categories, ",");
                        }
                        /* Check wholesale_customer_wholesale_price */
                        if (isset($request->meta_data) && is_array($request->meta_data)) {
                            foreach ($request->meta_data as $key => $val) {
                                if ($val['key'] == "wholesale_customer_wholesale_price") {
                                    $wholeSalePrice = isset($val['value']) ? $val['value'] : null;
                                    break;
                                }
                            }
                        }
                        $productList = array(
                            'user_id' => $integration->user_id,
                            'user_integration_id' => $userIntegrationId,
                            'platform_id' => $this->platformId,
                            'api_product_id' => $productApiId,
                            'api_updated_at' => $request->date_modified,
                            'sku' => $request->sku,
                            'product_name' => $request->name,
                            'description' => $request->description,
                            'product_status' => $request->status,
                            'stock_track' => $request->manage_stock ? 1 : 0,
                            'category_id' => $categories,
                            'weight' => $request->weight,
                            'product_sync_status' => "Ready",
                            'is_deleted' => $request->status == "trash" ? 1 : 0,
                        );
                        $has_variations = false;
                        if (isset($request->variations) && is_array($request->variations) && !empty($request->variations)) {
                            $has_variations = true;
                            $productList['has_variations'] = $has_variations;
                        } else {
                            $productList['has_variations'] = $has_variations;
                        }
                        $AttributeData = [
                            'lenght' => isset($request->dimensions->length) ? $request->dimensions->length : null,
                            'height' => isset($request->dimensions->height) ? $request->dimensions->height : null,
                            'width' => isset($request->dimensions->width) ? $request->dimensions->width : null,
                            'shortdescription' => $request->short_description,
                        ];
                        $find = PlatformProduct::where([
                            'user_id' => $userId,
                            'user_integration_id' => $userIntegrationId,
                            'platform_id' => $this->platformId,
                            'api_product_id' => $productApiId,
                        ])->select('id', 'has_variations')->first();
                        if ($find) {
                            $this->mobj->makeUpdate(
                                'platform_product',
                                $productList,
                                ['id' => $find->id]
                            );
                            $AttributeData['platform_product_id'] = $find->id;
                            $ProductPrimaryID = $find->id;
                            $this->CreateOrUpdateProductAttributes($find->id, $AttributeData);
                            $this->CreatePriceList($find->id, "pricelist", $request->price, $request->sale_price, $request->regular_price, $wholeSalePrice);

                            /* Set Update All Variations is_deleted=1 */
                            // PlatformProduct::where('parent_product_id', $ProductPrimaryID)->update(['is_deleted' => 1]);
                        } else {
                            $ProductPrimaryID = $this->mobj->makeInsertGetId('platform_product', $productList);
                            $AttributeData['platform_product_id'] = $ProductPrimaryID;
                            $this->CreateOrUpdateProductAttributes($ProductPrimaryID, $AttributeData);
                            $this->CreatePriceList($ProductPrimaryID, "pricelist", $request->price, $request->sale_price, $request->regular_price, $wholeSalePrice);
                        }

                        /* if woo product have ant variations and only insert variation ids to DB and this will process for detail later*/
                        if ($has_variations) {

                            if (is_array($request->variations)) {
                                /* Set Update All Variations is_deleted=1 */
                                //PlatformProduct::where('parent_product_id', $ProductPrimaryID)->update(['is_deleted' => 1]);
                                foreach ($request->variations as $variant) {

                                    $findVariant = PlatformProduct::where([
                                        'user_id' => $userId,
                                        'user_integration_id' => $userIntegrationId,
                                        'platform_id' => $this->platformId,
                                        'api_product_id' => (string)$variant,
                                    ])->select('id')->first();
                                    if ($findVariant) {
                                        $this->mobj->makeUpdate(
                                            'platform_product',
                                            [
                                                'is_deleted' => 0,
                                                'parent_product_id' => $ProductPrimaryID,
                                            ],
                                            ['id' => $findVariant->id]
                                        );
                                    } else {

                                        $this->mobj->makeInsert('platform_product', [
                                            'user_id' => $integration->user_id,
                                            'user_integration_id' => $userIntegrationId,
                                            'platform_id' => $this->platformId,
                                            'api_product_id' => (string)$variant,
                                            'parent_product_id' => $ProductPrimaryID,
                                            'product_sync_status' => "Pending",
                                        ]);
                                    }
                                }
                            }
                        }
                        /* end variations code */
                        // }
                        // }
                    }
                }
            }
        }
        return true;
    }
    /* Create Or Update Product After Resposne */
    public function CreateOrUpdateProductAfterResponse($userID, $userIntegrationId, $platformId, $request = [], $linkedID = 0)
    {
        $categories = $ProductPrimaryID = $wholeSalePrice = null;
        if (isset($request['categories'])) {
            foreach ($request['categories'] as $key => $cat) {
                $categories .= $cat['id'] . ",";
            }
            $categories = rtrim($categories, ",");
        }
        /* Check wholesale_customer_wholesale_price */
        if (isset($request['meta_data']) && is_array($request['meta_data'])) {
            foreach ($request['meta_data'] as $key => $val) {
                if ($val['key'] == "wholesale_customer_wholesale_price") {
                    $wholeSalePrice = isset($val['value']) ? $val['value'] : null;
                    break;
                }
            }
        }
        $productList = array(
            'user_id' => $userID,
            'user_integration_id' => $userIntegrationId,
            'platform_id' => $platformId,
            'api_product_id' => $request['id'],
            'api_updated_at' => $request['date_modified'],
            'sku' => $request['sku'],
            'product_name' => isset($request['name']) ? $request['name'] : null,
            'description' => isset($request['description']) ? $request['description'] : null,
            'product_status' => isset($request['status']) ? $request['status'] : null,
            'stock_track' => $request['manage_stock'] ? 1 : 0,
            'weight' => isset($request['weight']) ? $request['weight'] : null,
            'category_id' => $categories,
            'product_sync_status' => "Synced",
            'linked_id' => $linkedID,
            'parent_product_id' => isset($request['parent_product_id']) ? $request['parent_product_id'] : null,
        );
        $AttributeData = [
            'lenght' => isset($request['dimensions']['length']) ? $request['dimensions']['length'] : null,
            'height' => isset($request['dimensions']['height']) ? $request['dimensions']['height'] : null,
            'width' => isset($request['dimensions']['width']) ? $request['dimensions']['width'] : null,
            'shortdescription' => isset($request['short_description']) ? $request['short_description'] : null,
        ];
        $find = PlatformProduct::where([
            'user_id' => $userID,
            'user_integration_id' => $userIntegrationId,
            'platform_id' => $this->platformId,
            'api_product_id' => (string)$request['id'],
        ])->select('id')->first();

        if ($find) {
            $this->mobj->makeUpdate(
                'platform_product',
                $productList,
                ['id' => $find->id]
            );
            $ProductPrimaryID = $find->id;
            $AttributeData['platform_product_id'] = $find->id;
            $this->CreateOrUpdateProductAttributes($find->id, $AttributeData);
            $this->CreatePriceList($find->id, "pricelist", $request['price'], $request['sale_price'], $request['regular_price'], $wholeSalePrice);
        } else {
            $ProductPrimaryID = $this->mobj->makeInsertGetId('platform_product', $productList);
            $AttributeData['platform_product_id'] = $ProductPrimaryID;
            $this->CreateOrUpdateProductAttributes($ProductPrimaryID, $AttributeData);
            $this->CreatePriceList($ProductPrimaryID, "pricelist", $request['price'], $request['sale_price'], $request['regular_price'], $wholeSalePrice);
        }
        return $ProductPrimaryID;
    }
    public function CreateOrUpdateVariationProductAfterResponse($userID, $userIntegrationId, $platformId, $request = [], $linkedID = 0)
    {
        $categories = $ProductPrimaryID = $wholeSalePrice = null;
        if (isset($request['categories'])) {
            foreach ($request['categories'] as $key => $cat) {
                $categories .= $cat['id'] . ",";
            }
            $categories = rtrim($categories, ",");
        }
        /* Check wholesale_customer_wholesale_price */
        if (isset($request['meta_data']) && is_array($request['meta_data'])) {
            foreach ($request['meta_data'] as $key => $val) {
                if ($val['key'] == "wholesale_customer_wholesale_price") {
                    $wholeSalePrice = isset($val['value']) ? $val['value'] : null;
                    break;
                }
            }
        }
        $productList = array(
            'user_id' => $userID,
            'user_integration_id' => $userIntegrationId,
            'platform_id' => $platformId,
            'api_product_id' => $request['id'],
            'api_updated_at' => $request['date_modified'],
            'sku' => $request['sku'],
            'product_name' => $request['name'],
            'description' => $request['description'],
            'product_status' => $request['status'],
            'stock_track' => $request['manage_stock'] ? 1 : 0,
            'weight' => $request['weight'],
            'category_id' => $categories,
            'product_sync_status' => "Synced",
            'linked_id' => $linkedID,
        );
        $AttributeData = [
            'lenght' => isset($request['dimensions']['length']) ? $request['dimensions']['length'] : null,
            'height' => isset($request['dimensions']['height']) ? $request['dimensions']['height'] : null,
            'width' => isset($request['dimensions']['width']) ? $request['dimensions']['width'] : null,
            'shortdescription' => $request['short_description'],
        ];
        $find = PlatformProduct::where([
            'user_id' => $userID,
            'user_integration_id' => $userIntegrationId,
            'platform_id' => $this->platformId,
            'api_product_id' => (string)$request['id'],
        ])->select('id')->first();


        if ($find) {
            $this->mobj->makeUpdate(
                'platform_product',
                $productList,
                ['id' => $find->id]
            );
            $ProductPrimaryID = $find->id;
            $AttributeData['platform_product_id'] = $find->id;
            $this->CreateOrUpdateProductAttributes($find->id, $AttributeData);
            $this->CreatePriceList($find->id, "pricelist", $request['price'], $request['sale_price'], $request['regular_price'], $wholeSalePrice);
        } else {
            $ProductPrimaryID = $this->mobj->makeInsertGetId('platform_product', $productList);
            $AttributeData['platform_product_id'] = $ProductPrimaryID;
            $this->CreateOrUpdateProductAttributes($ProductPrimaryID, $AttributeData);
            $this->CreatePriceList($ProductPrimaryID, "pricelist", $request['price'], $request['sale_price'], $request['regular_price'], $wholeSalePrice);
        }
        return $ProductPrimaryID;
    }
    /* Create or Update Product Prices */
    public function CreateOrUpdateProductPrice($ProductPrimaryID, $ObjectDataPrimaryId, $PostData)
    {
        if ($ProductPrimaryID && !empty($PostData)) {
            $find = $this->mobj->getFirstResultByConditions('platform_porduct_price_list', [
                'platform_product_id' => $ProductPrimaryID,
                'platform_object_data_id' => $ObjectDataPrimaryId,
            ], ['id']);
            if ($find) {
                $this->mobj->makeUpdate('platform_porduct_price_list', $PostData, [
                    'id' => $find->id,
                ]);
            } else {
                $this->mobj->makeInsert('platform_porduct_price_list', $PostData);
            }
        }
    }
    /* Create Price List */
    public function CreatePriceList($ProductPrimaryID, $ObjectName, $Price = null, $SalePrice = null, $RegularPrice = null, $wholeSalePrice = null)
    {

        if ($ProductPrimaryID) {
            $ObjectId = $this->helper->getObjectId($ObjectName);
            if ($ObjectId) {
                $find = $this->mobj->getResultByConditions('platform_object_data', [
                    'user_id' => 0,
                    'user_integration_id' => 0,
                    'platform_id' => $this->platformId,
                    'platform_object_id' => $ObjectId,
                ], ['id', 'api_id']);

                if (!empty($find)) {
                    $priceArr = [];
                    foreach ($find as $key => $value) {
                        $priceArr[$value->id] = $value->api_id;
                    }
                    if (!empty($priceArr)) {
                        /* Normal Price */
                        if (!empty($Price) && $Price) {
                            $price_object_data_id = array_search("price", $priceArr);
                            if ($price_object_data_id) {
                                $this->CreateOrUpdateProductPrice($ProductPrimaryID, $price_object_data_id, ['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $price_object_data_id, 'price' => $Price]);
                            }
                        }
                        /* Sale Price */
                        if (!empty($SalePrice) && $SalePrice) {

                            $sale_price_object_data_id = array_search("sale_price", $priceArr);
                            if ($sale_price_object_data_id) {
                                $this->CreateOrUpdateProductPrice($ProductPrimaryID, $sale_price_object_data_id, ['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $sale_price_object_data_id, 'price' => $SalePrice]);
                            }
                        }
                        /* Regular Price */
                        if (!empty($RegularPrice) && $RegularPrice) {
                            $regular_price_object_data_id = array_search("regular_price", $priceArr);
                            if ($regular_price_object_data_id) {
                                $this->CreateOrUpdateProductPrice($ProductPrimaryID, $regular_price_object_data_id, ['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $regular_price_object_data_id, 'price' => $RegularPrice]);
                            }
                        }
                        /* whole sale price if available in plugin  */
                        if (!empty($wholeSalePrice) && $wholeSalePrice) {
                            $whole_sale_price_object_data_id = array_search("whole_sale_price", $priceArr);
                            if ($whole_sale_price_object_data_id) {
                                $this->CreateOrUpdateProductPrice($ProductPrimaryID, $whole_sale_price_object_data_id, ['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $whole_sale_price_object_data_id, 'price' => $wholeSalePrice]);
                            }
                        }
                    }
                }
            }
        }
    }
    /* Receive Customer create/update/delete webhook from WooCommerce */
    public function ReceiveCustomerWebhook(Request $request, $userIntegrationId)
    {
        $this->mobj->AddMemory();
        if ($request->isMethod('post')) {
            // \Log::channel('webhook')->info("userIntegrationId-" . $userIntegrationId . " Webhook Created Date: " . date('Y-m-d H:i:s') . "Customer ID-" . $request->id);
            if (isset($request->id)) {
                $customerApiId = (string) $request->id;
                $userIntegrationId = (int)$userIntegrationId;
                //$EventIDs = ["GET_SALESORDER"];
                $integration = $this->map->getUserIntegrationDetailsById($userIntegrationId, self::$myPlatform);
                if ($integration) {
                    // $user_work_flow = DB::table('user_workflow_rule as ur')->select('e.event_id')
                    //     ->join('platform_workflow_rule as pr', 'ur.platform_workflow_rule_id', '=', 'pr.id')
                    //     ->join('platform_events as e', 'pr.source_event_id', '=', 'e.id')
                    //     ->where('pr.status', 1)
                    //     ->where('ur.status', 1)
                    //     ->where('e.status', 1)
                    //     ->where('ur.user_id', $integration->user_id)
                    //     ->where('ur.user_integration_id', $userIntegrationId);
                    // $user_workflow_array = $user_work_flow->pluck('e.event_id')->toArray();

                    // if ($user_workflow_array) {
                    //     /* Check whether order is ON or OFF */
                    //     $findEvents = array_intersect($EventIDs, $user_workflow_array);

                    //     if (!empty($findEvents)) {
                    $customersList = array(
                        'user_id' => $integration->user_id,
                        'user_integration_id' => $userIntegrationId,
                        'platform_id' => $this->platformId,
                        'api_customer_id' => $customerApiId,
                        'first_name' => $request->first_name,
                        'last_name' => $request->last_name,
                        'email' => $request->email,
                        'customer_name' => $request->first_name . " " . $request->last_name,
                    );

                    $findHook = $this->mobj->getFirstResultByConditions('platform_customer', [
                        'user_integration_id' => $userIntegrationId,
                        'platform_id' => $this->platformId,
                        'api_customer_id' => $customerApiId,
                    ], ['id']);
                    if ($findHook) {
                        $this->mobj->makeUpdate(
                            'platform_customer',
                            $customersList,
                            ['id' => $findHook->id]
                        );
                    } else {
                        $this->mobj->makeInsert('platform_customer', $customersList);
                    }
                }
                //}
                //}
            }
        }
        return true;
    }
    /* Insert Update Product Attributes */
    public function CreateOrUpdateProductAttributes($ProductID = null, $PostData = [])
    {

        if ($ProductID && !empty($PostData)) {
            $find = $this->mobj->getFirstResultByConditions('platform_product_detail_attributes', [
                'platform_product_id' => $ProductID,
            ], ['id']);
            if ($find) {
                $this->mobj->makeUpdate('platform_product_detail_attributes', $PostData, [
                    'platform_product_id' => $ProductID,
                ]);
            } else {
                $this->mobj->makeInsert('platform_product_detail_attributes', $PostData);
            }
        }
    }
    /* Sync Shipment */
    public function SyncShipmentBackup($userId = null, $userIntegrationId = null, $PlatformWorkFlowRuleID = null, $UserWorkFlowRuleID = null, $SorucePlatformName = null, $sync_status = "Ready")
    {
        $return_response = false;
        try {
            $limit = 20;
            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);
                $SourceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id', 'platform_id']);
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId && isset($SourceUfound->platform_id)) {

                    $list = DB::table('platform_order_shipments as s')->select('s.tracking_info', 's.shipping_method', 's.realease_date', 's.tracking_url', 'o.shipment_status', 'o.api_order_id', 'o.id as order_primary_id', 's.id', 's.created_at', 'o.linked_id')
                        ->join('platform_order as o', 'o.id', '=', 's.platform_order_id')
                        ->where([['s.user_id', '=', $userId], ['s.platform_id', '=', $SourceUfound->platform_id], ['s.user_integration_id', '=', $userIntegrationId], ['s.sync_status', '=', $sync_status]])
                        ->take($limit)->get();

                    if (!empty($list) && count($list) > 0) {
                        $orderIds = [];
                        foreach ($list as $key => $value) {
                            $find = PlatformOrder::select('id', 'api_order_id')->where('id', $value->linked_id)->first();
                            if ($find) {
                                $object_id = $this->helper->getObjectId('shipping_method');
                                $shipping_method = $this->map->getObjectDataByFilterData($userId, $userIntegrationId, $SourcePlatformId, $object_id, "api_id", $value->shipping_method, ["name"]);

                                if (isset($shipping_method->name)) {
                                    $shippingMethodName = $shipping_method->name;
                                } else {
                                    $shippingMethodName = $value->shipping_method;
                                }
                                $data = [
                                    'note' => "shipped via {$shippingMethodName} with tracking number  {$value->tracking_info} and release date {$value->created_at}",
                                ];

                                $response = $this->CreateOrderNote($userIntegrationId, $find->api_order_id, $data);

                                if ($response) {
                                    if ($value->shipment_status == "Ready") {
                                        array_push($orderIds, [
                                            'id' => $find->api_order_id,
                                            'status' => 'completed',
                                        ]);
                                    }
                                    $this->mobj->makeUpdate('platform_order_shipments', ['platform_order_id' => $value->order_primary_id, 'sync_status' => "Synced"], ['id' => $value->id]);
                                }
                            }
                        }

                        if (!empty($orderIds)) {
                            $postData = [
                                'create' => [],
                                'update' =>
                                $orderIds,
                                'delete' => [],
                            ];
                            sleep(1);
                            $response = $this->OrderBulkUpdate($userIntegrationId, $postData);
                            // \Log::channel('webhook')->info("woo_live_response -" . json_encode($response) . " Integration " . $userIntegrationId . "Created Date : " . date('Y-m-d H:i:s'));

                            if (1) {
                                $object_id = $this->helper->getObjectId('sales_order_shipment');
                                foreach ($list as $key => $value) {
                                    //  \Log::channel('webhook')->info("woo_order_shipment_status - LinkedID- " . $value->linked_id . " shipment_status-" . $value->shipment_status . " Integration " . $userIntegrationId . "Created Date : " . date('Y-m-d H:i:s'));

                                    if ($value->shipment_status == "Ready") {
                                        $find = PlatformOrder::select('id', 'api_order_id')->where('id', $value->linked_id)->first();
                                        if ($find) {
                                            //\Log::channel('webhook')->info("woo_order_find_order- woo" . $find->id . " bp-" . $value->order_primary_id . " Integration " . $userIntegrationId . "Created Date : " . date('Y-m-d H:i:s'));
                                            $this->mobj->makeUpdate('platform_order', ['shipment_status' => "Synced"], [
                                                'id' => $value->order_primary_id,
                                            ]);
                                            $this->mobj->makeUpdate('platform_order', ['shipment_status' => "Synced"], [
                                                'id' => $find->id,
                                            ]);
                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $value->order_primary_id, null);
                                        }
                                    }
                                }
                            }
                            $return_response = true;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Sync Shipments */
    public function SyncShipment($userId = null, $userIntegrationId = null, $PlatformWorkFlowRuleID = null, $UserWorkFlowRuleID = null, $SorucePlatformName = null, $sync_status = "Ready")
    {
        $return_response = true;
        try {
            $limit = 20;
            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);
                $SourceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id', 'platform_id']);
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId && isset($SourceUfound->platform_id)) {

                    $list = DB::table('platform_order_shipments as s')->select('s.tracking_info', 's.shipping_method', 's.realease_date', 's.tracking_url', 'o.shipment_status', 'o.api_order_id', 'o.id as order_primary_id', 's.id', 's.created_at', 'o.linked_id')
                        ->join('platform_order as o', 'o.id', '=', 's.platform_order_id')
                        ->where([['s.user_id', '=', $userId], ['s.platform_id', '=', $SourceUfound->platform_id], ['s.user_integration_id', '=', $userIntegrationId], ['s.sync_status', '=', $sync_status]])->whereIn('o.shipment_status', ['Partial', 'Ready'])
                        ->take($limit)->get();

                    if (!empty($list) && count($list) > 0) {
                        foreach ($list as $key => $value) {
                            $find = PlatformOrder::select('id', 'api_order_id')->where('id', $value->linked_id)->first();
                            if ($find) {
                                $object_id = $this->helper->getObjectId('shipping_method');
                                $shipping_method = $this->map->getObjectDataByFilterData($userId, $userIntegrationId, $SourcePlatformId, $object_id, "api_id", $value->shipping_method, ["name"]);

                                if (isset($shipping_method->name)) {
                                    $shippingMethodName = $shipping_method->name;
                                } else {
                                    $shippingMethodName = $value->shipping_method;
                                }
                                $data = [
                                    'note' => "shipped via {$shippingMethodName} with tracking number  {$value->tracking_info} and release date {$value->created_at}",
                                    'customer_note' => true,
                                ];

                                $response = $this->CreateOrderNote($userIntegrationId, $find->api_order_id, $data);

                                if ($response) {

                                    $this->mobj->makeUpdate('platform_order_shipments', ['platform_order_id' => $value->order_primary_id, 'sync_status' => "Synced"], ['id' => $value->id]);

                                    // \Log::channel('webhook')->info("before_wooship - Integration-" . $userIntegrationId . " Order-" . $find->api_order_id . " Shipment Status-" . $value->shipment_status . " Created Date : " . date('Y-m-d H:i:s'));
                                    if ($value->shipment_status == "Ready") {
                                        $payload = [
                                            'status' => 'completed',
                                        ];
                                        sleep(1);

                                        $responseOrderStatus = $this->OrderUpdate($userIntegrationId, $find->api_order_id, $payload, $ufound);
                                        // \Log::channel('webhook')->info("after_wooship - Integration-" . $userIntegrationId . " Order-" . $find->api_order_id . " Shipment Status-" . $value->shipment_status . " Created Date : " . date('Y-m-d H:i:s'));
                                        if (isset($responseOrderStatus['id'])) {
                                            // \Log::channel('webhook')->info("after_wooship_response_success - Integration-" . $userIntegrationId . " Order-" . $find->api_order_id . " Shipment Status-" . $value->shipment_status . " ResponseID=" . $responseOrderStatus['id'] . " Created Date : " . date('Y-m-d H:i:s'));
                                            $orderStatus = "Synced";
                                            $logStatus = "success";
                                            $error = null;
                                        } else {
                                            $orderStatus = "Failed";
                                            $logStatus = "failed";
                                            $error = isset($responseOrderStatus['message']) ? $responseOrderStatus['message'] : "API Error";
                                            // \Log::channel('webhook')->info("after_wooship_response_failure - Integration-" . $userIntegrationId . " Order-" . $find->api_order_id . " Shipment Status-" . $value->shipment_status . " ResponseID=" . $error . " Created Date : " . date('Y-m-d H:i:s'));
                                        }
                                        $object_id = $this->helper->getObjectId('sales_order_shipment');
                                        $this->mobj->makeUpdate('platform_order', ['shipment_status' => $orderStatus], [
                                            'id' => $value->order_primary_id,
                                        ]);
                                        $find->shipment_status = $orderStatus;
                                        $find->save();

                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, $logStatus, $value->order_primary_id, $error);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Sync Inventory */
    public function SyncInventory($userId = null, $userIntegrationId = null, $PlatformWorkFlowID = null, $UserWorkFlowID = null, $SorucePlatformName = null, $sync_status = "Ready", $record_id = null)
    {
        $this->mobj->AddMemory();
        try {

            $ufound = $this->getPrimaryAccount($userIntegrationId);

            if ($ufound && $this->platformId) {
                $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);

                $SourceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id', 'platform_id']);
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId && isset($SourceUfound->platform_id)) {
                    $Inventory_arr = $update_inventory_data = $productsIndex = $VariantProductsIndex = $VariantProducts = [];
                    $process_limit = 50;
                    $product_identity_obj_id = $this->helper->getObjectId('product_identity');
                    $maping_data = $this->map->getMappedField($userIntegrationId, $PlatformWorkFlowID, $product_identity_obj_id, ['db_field_name']);
                    $IsMultipleWarehouseInventory = 0;
                    $InventoryType = "NORMAL";
                    $PluginType = null;
                    $PluginName = null;
                    if (!empty($maping_data)) {

                        $query = DB::table('platform_product as source_platform_product')->join('platform_product as destination_platform_product', 'destination_platform_product.' . $maping_data['source_row_data'], '=', 'source_platform_product.' . $maping_data['destination_row_data']);

                        if ($record_id > 0) {
                            $query->where([
                                ['source_platform_product.id', '=', $record_id],
                                ['destination_platform_product.user_integration_id', '=', $userIntegrationId],
                                ['destination_platform_product.platform_id', '=', $this->platformId],
                                ['destination_platform_product.is_deleted', '=', 0]
                            ]);
                        } else {
                            $query->where([
                                ['source_platform_product.user_integration_id', '=', $userIntegrationId],
                                ['source_platform_product.platform_id', '=', $SourceUfound->platform_id],
                                ['destination_platform_product.user_integration_id', '=', $userIntegrationId],
                                ['destination_platform_product.platform_id', '=', $this->platformId],
                                ['destination_platform_product.is_deleted', '=', 0],
                                ['source_platform_product.is_deleted', '=', 0],
                            ]);
                        }
                        $Inventory_arr = $query->whereIn('source_platform_product.inventory_sync_status', [$sync_status, 'Failed'])
                            ->select('source_platform_product.id', 'destination_platform_product.sku as sku', 'destination_platform_product.id as woo_id', 'destination_platform_product.api_product_id as woo_api_product_id', 'source_platform_product.api_product_id as sku_api_product_id', 'destination_platform_product.parent_product_id as parent_product_id')->orderBy('source_platform_product.inventory_sync_status', 'desc')->orderBy('source_platform_product.updated_at', 'asc')->limit($process_limit)->get();


                        if (count($Inventory_arr) > 0) {
                            /* chech has multiple warehouse button is on or off | always Platform_workflow_rule_id=0 */
                            $IsMultipleWarehouse = $this->map->getMappedDataByName($userIntegrationId, 0, "has_multi_warehouse", ['custom_data'], "default");
                            $IsMultipleWarehouseInventory = isset($IsMultipleWarehouse->custom_data) ? $IsMultipleWarehouse->custom_data : 0;
                            if ($IsMultipleWarehouseInventory) {
                                /* If multi warehouse inventory set is 1 or ON */
                                $InventoryType = "MULTIWAREHOUSE";
                                $plugin = $this->map->getMappedDataByName($userIntegrationId, null, "warehouse_plugins", ['name'], 'default', null, 'single');
                                $PluginName = isset($plugin->name) ? $plugin->name : null;
                                if ($PluginName) {
                                    if ($PluginName == "myworks") {
                                        $PluginType = "METADATA";
                                    }
                                    $modal_response = app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->PrepareInventoryData($userId, $userIntegrationId, $PlatformWorkFlowID, $PluginType, $Inventory_arr);
                                    $VariantProductsIndex = $modal_response['update_variant_inventory_data'];
                                    $update_inventory_data = $modal_response['update_inventory_data'];
                                    $productsIndex = $modal_response['normal_product'];
                                    $VariantProducts = $modal_response['variant_product'];
                                }
                            } else {
                                /* If no multi warehouse inventory set is 0 or OFF */
                                $modal_response = app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->PrepareInventoryData($userId, $userIntegrationId, $PlatformWorkFlowID, $PluginType, $Inventory_arr);
                                $VariantProductsIndex = $modal_response['update_variant_inventory_data'];
                                $update_inventory_data = $modal_response['update_inventory_data'];
                                $productsIndex = $modal_response['normal_product'];
                                $VariantProducts = $modal_response['variant_product'];
                            }
                        }
                    }

                    /* Non Variant Product Update */
                    if (!empty($update_inventory_data)) {
                        app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->UpdateNormalProductInventory($userId, $userIntegrationId, $PlatformWorkFlowID, $UserWorkFlowID, $SourcePlatformId, $update_inventory_data, $productsIndex, $InventoryType, $ufound);
                    }
                    /* Variant Product Update */
                    if (!empty($VariantProductsIndex)) {
                        app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->UpdateVariantProductInventory($userId, $userIntegrationId, $PlatformWorkFlowID, $UserWorkFlowID, $SourcePlatformId, $VariantProductsIndex, $VariantProducts, $InventoryType, $ufound);
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return false;
        }
    }

    public function SyncInventory1($userId = null, $userIntegrationId = null, $PlatformWorkFlowID = null, $UserWorkFlowID = null, $SorucePlatformName = null, $sync_status = "Ready")
    {
        $this->mobj->AddMemory();
        try {

            $ufound = $this->getPrimaryAccount($userIntegrationId);

            if ($ufound && $this->platformId) {
                $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);

                $SourceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id', 'platform_id']);
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId && isset($SourceUfound->platform_id)) {

                    $Inventory_arr = $update_inventory_data = $productsIndex = $VariantProductsIndex = $VariantProducts = [];
                    $process_limit = 100;
                    $product_identity_obj_id = $this->helper->getObjectId('product_identity');
                    $maping_data = $this->map->getMappedField($userIntegrationId, $PlatformWorkFlowID, $product_identity_obj_id, ['db_field_name']);
                    if (!empty($maping_data)) {
                        $Inventory_arr = DB::table('platform_product as source_platform_product')->join('platform_product as destination_platform_product', 'destination_platform_product.' . $maping_data['source_row_data'], '=', 'source_platform_product.' . $maping_data['destination_row_data'])
                            ->where([
                                ['source_platform_product.inventory_sync_status', '=', $sync_status],
                                ['source_platform_product.user_integration_id', '=', $userIntegrationId],
                                ['source_platform_product.platform_id', '=', $SourceUfound->platform_id],
                                ['destination_platform_product.user_integration_id', '=', $userIntegrationId],
                                ['destination_platform_product.platform_id', '=', $this->platformId],
                                ['destination_platform_product.is_deleted', '=', 0],
                                ['source_platform_product.is_deleted', '=', 0],
                            ])
                            ->select('source_platform_product.id', 'destination_platform_product.sku as sku', 'destination_platform_product.id as woo_id', 'destination_platform_product.api_product_id as woo_api_product_id', 'source_platform_product.api_product_id as sku_api_product_id', 'destination_platform_product.parent_product_id as parent_product_id')->orderBy('source_platform_product.updated_at', 'asc')->limit($process_limit)->get();

                        if (count($Inventory_arr) > 0) {
                            /* Return all multi selected warehouse ids */
                            $warehouseArray = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowID, "inventory_warehouse", ['api_id'], "regular", null, "multi", "source");
                            if (is_array($warehouseArray) && !empty($warehouseArray)) {
                                foreach ($Inventory_arr as $Inventory) {

                                    $productsIndex[$Inventory->woo_api_product_id] = $Inventory->id;
                                    $product_inventory_arr = $this->mobj->getResultByConditions('platform_product_inventory', ['user_integration_id' => $userIntegrationId, 'api_product_id' => $Inventory->sku_api_product_id], ['id', 'api_warehouse_id', 'quantity']);

                                    if (count($product_inventory_arr) > 0) {

                                        $sum = 0;
                                        foreach ($product_inventory_arr as $product_inventory) {
                                            if (in_array($product_inventory->api_warehouse_id, $warehouseArray)) {
                                                $sum += $product_inventory->quantity;
                                            }
                                        }
                                        /* Add total sum  as stock for Woo */
                                        if ($Inventory->parent_product_id) {
                                            $find = PlatformProduct::select('api_product_id')->where('id', $Inventory->parent_product_id)->first();
                                            if ($find) {
                                                $VariantProducts[$Inventory->woo_api_product_id] = $Inventory->id;
                                                if (isset($VariantProductsIndex[$find->api_product_id])) {

                                                    $VariantProductsIndex[$find->api_product_id][] = [
                                                        'id' => $Inventory->woo_api_product_id,
                                                        'stock_quantity' => $sum,
                                                    ];
                                                } else {
                                                    $VariantProductsIndex[$find->api_product_id][] = [
                                                        'id' => $Inventory->woo_api_product_id,
                                                        'stock_quantity' => $sum,
                                                    ];
                                                }
                                            }
                                        } else {
                                            array_push($update_inventory_data, [
                                                'id' => $Inventory->woo_api_product_id,
                                                'stock_quantity' => $sum,
                                            ]);
                                        }
                                    } else {
                                        if ($Inventory->parent_product_id) {
                                            $find = PlatformProduct::select('api_product_id')->where('id', $Inventory->parent_product_id)->first();
                                            if ($find) {
                                                $VariantProducts[$Inventory->woo_api_product_id] = $Inventory->id;
                                                if (isset($VariantProductsIndex[$find->api_product_id])) {
                                                    $VariantProductsIndex[$find->api_product_id][] = [
                                                        'id' => $Inventory->woo_api_product_id,
                                                        'stock_quantity' => 0,
                                                    ];
                                                } else {
                                                    $VariantProductsIndex[$find->api_product_id][] = [
                                                        'id' => $Inventory->woo_api_product_id,
                                                        'stock_quantity' => 0,
                                                    ];
                                                }
                                            }
                                        } else {
                                            array_push($update_inventory_data, [
                                                'id' => $Inventory->woo_api_product_id,
                                                'stock_quantity' => 0,
                                            ]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                    /* Non Variant Product Update */
                    if (!empty($update_inventory_data)) {
                        $postData = [
                            'create' => [],
                            'update' =>
                            $update_inventory_data,
                            'delete' => [],
                        ];
                        $response = $this->ProductBulkUpdate($userIntegrationId, $postData);
                        if (isset($response['update']) && !empty($response['update'])) {
                            $object_id = $this->helper->getObjectId('inventory');
                            foreach ($response['update'] as $key => $value) {
                                if (!isset($value['error'])) {
                                    if (isset($productsIndex[$value['id']])) {
                                        $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => "Synced"], ['id' => $productsIndex[$value['id']]]);
                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowID, $SourcePlatformId, $this->platformId, $object_id, 'success', $productsIndex[$value['id']], null);
                                    }
                                }
                            }
                        }
                    }
                    /* Variant Product Update */
                    if (!empty($VariantProductsIndex)) {
                        foreach ($VariantProductsIndex as $key => $val) {
                            $postData = [
                                'create' => [],
                                'update' => $val,
                                'delete' => [],
                            ];
                            $url = "/wp-json/wc/v3/products/{$key}/variations/batch";
                            $response = $this->ProductVariantBulkUpdate($userIntegrationId, $url, $postData);
                            if (isset($response['update']) && !empty($response['update'])) {
                                $object_id = $this->helper->getObjectId('inventory');
                                foreach ($response['update'] as $key => $value) {
                                    if (!isset($value['error'])) {
                                        if (isset($VariantProducts[$value['id']])) {
                                            $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => "Synced"], ['id' => $VariantProducts[$value['id']]]);
                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowID, $SourcePlatformId, $this->platformId, $object_id, 'success', $VariantProducts[$value['id']], null);
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
    /* Update Order Status  */
    public function OrderUpdate($userIntegrationId = null, $orderID = null, array $payload, $account = null)
    {
        try {

            if ($account) {
                $response = $this->wc->OrderUpdate($account, $orderID, $payload);

                return json_decode($response->getBody(), true);
            } else {
                $account = $this->getPrimaryAccount($userIntegrationId);
                if ($account && $this->platformId) {
                    if (isset($account->platform_id) && $account->platform_id == $this->platformId) {
                        $response = $this->wc->OrderUpdate($account, $orderID, $payload);

                        return json_decode($response->getBody(), true);
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return false;
        }
    }

    /* Order Bulk Update */
    public function OrderBulkUpdate($userIntegrationId = null, array $postData)
    {
        try {

            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

                    $response = $this->wc->OrderBulkUpdate($ufound, null, $postData);
                    if (json_decode($response->getBody(), true)) {
                        return json_decode($response->getBody(), true);
                    }
                    return false;
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return false;
        }
    }
    /* Product Bulk Update */
    public function ProductBulkUpdate($userIntegrationId = null, array $postData)
    {
        try {

            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

                    $response = $this->wc->ProductBulkUpdate($ufound, null, $postData);
                    if (json_decode($response->getBody(), true)) {
                        return json_decode($response->getBody(), true);
                    }
                    return false;
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return false;
        }
    }
    /* Product Variant Bulk Update */
    public function ProductVariantBulkUpdate($userIntegrationId = null, $url, array $postData)
    {
        try {

            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

                    $response = $this->wc->ProductVariantBulkUpdate($ufound, $url, $postData);
                    if (json_decode($response->getBody(), true)) {
                        return json_decode($response->getBody(), true);
                    }
                    return false;
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return false;
        }
    }
    /* Products */
    public function CreateOrUpdateOrDeleteProduct($userIntegrationId = null, $url = null, array $postData, $type = null, $ufound = null)
    {
        try {
            if ($ufound) {
                $response = $this->wc->CreateOrUpdateOrDeleteProduct($ufound, $url, $postData, $type);

                return json_decode($response->getBody(), true);
            } else {
                $ufound = $this->getPrimaryAccount($userIntegrationId);
                if ($ufound && $this->platformId) {
                    if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

                        $response = $this->wc->CreateOrUpdateOrDeleteProduct($ufound, $url, $postData, $type);

                        return json_decode($response->getBody(), true);
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return false;
        }
    }
    public function CreateOrUpdateOrDeleteVariationProduct($userIntegrationId = null, $url = null, array $postData, $type = null, $ufound = null)
    {
        try {
            if ($ufound) {
                $response = $this->wc->CreateOrUpdateOrDeleteVariationProduct($ufound, $url, $postData, $type);

                return json_decode($response->getBody(), true);
            } else {
                $ufound = $this->getPrimaryAccount($userIntegrationId);
                if ($ufound && $this->platformId) {
                    if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

                        $response = $this->wc->CreateOrUpdateOrDeleteVariationProduct($ufound, $url, $postData, $type);

                        return json_decode($response->getBody(), true);
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return false;
        }
    }
    /* Create Order Note */
    public function CreateOrderNote($userIntegrationId = null, $OrderID = null, array $postData)
    {
        try {

            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                    $response = $this->wc->CreateOrderNote($ufound, null, $OrderID, $postData);
                    if (json_decode($response->getBody(), true)) {
                        $data = json_decode($response->getBody(), true);

                        if (isset($data['code'])) {
                            return false;
                        } else {
                            return isset($data['id']) ? true : false;
                        }
                        return false;
                    }
                    return false;
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return false;
        }
    }
    /* Display woocommerce form for credentials */
    public function InitiateWCAuth(Request $request)
    {
        if ($request->isMethod('get')) {
            $platform = 'woocommerce';
            return view("pages.apiauth.woocommerce_auth", compact('platform'));
        }
    }
    public function checkExistingConnectedAc($platform_id, $api_domain, $consumer_id, $consumer_secret)
    {
        $enc_consumer_id = $this->mobj->encrypt_decrypt($consumer_id);
        $enc_consumer_secret = $this->mobj->encrypt_decrypt($consumer_secret);
        $obj_existing = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $platform_id, 'api_domain' => $api_domain, 'app_id' => $enc_consumer_id, 'app_secret' => $enc_consumer_secret], ['user_id']);
        if ($obj_existing) {
            return true;
        } else {
            return false;
        }
    }
    /* Save woocommerce credentials */
    public function ConnectWCAuth(Request $request)
    {
        if ($request->isMethod('post')) {

            if ($this->mobj->checkHtmlTags($request->all())) {
                $data['status_code'] = 0;
                $data['status_text'] = Lang::get('tags.validate');
                return json_encode($data);
            }

            $flag = true;
            $regex = '/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/';
            $validator = Validator::make($request->all(), [
                'consumer_id' => 'required',
                'consumer_secret' => 'required',
                'api_domain' => 'required|regex:' . $regex,
            ]);
            if ($validator->fails()) {
                $flag = false;
                $data['status_code'] = 0;
                $data['status_text'] = $validator->getMessageBag()->toArray();
            } else {
                // to check whether given account is already in use or not.
                $checkExistingAc = $this->checkExistingConnectedAc($this->platformId, $request->api_domain, $request->consumer_id, $request->consumer_secret);

                if ($checkExistingAc) {
                    $flag = false;
                    $data['status_code'] = 0;
                    $data['status_text'] = 'This account detail is already exist, Try with another account.';
                } else {
                    if (filter_var($request->api_domain, FILTER_VALIDATE_URL) === false) {
                        $flag = false;
                        $data['status_code'] = 0;
                        $data['status_text'] = 'This is not a valid URL.';
                    } else {
                        $checkCredentials = $this->wc->CheckCredentials($request->consumer_id, $request->consumer_secret, $request->api_domain);
                        if (!$checkCredentials || !isset($this->platformId)) {

                            $flag = false;
                            $data['status_code'] = 0;
                            $data['status_text'] = 'Invalid WooCommerce credentials!';
                        } else {
                            $user_data = Auth::user();
                            $user_id = $user_data->id;
                            $consumer_id = $this->mobj->encrypt_decrypt($request->consumer_id);
                            $consumer_secret = $this->mobj->encrypt_decrypt($request->consumer_secret);
                            $domain = parse_url($request->api_domain, PHP_URL_HOST);
                            $count = PlatformAccount::where('platform_id', self::$myPlatform)->get()->count();
                            $increment = $count > 0 ? '_' . $domain . "_" . $count . "_" . date('m-d-Y') : '_' . $domain . "_" . date('m-d-Y');
                            $arr_field = [
                                'account_name' => self::$myPlatform . $increment,
                                'user_id' => $user_id,
                                'platform_id' => $this->platformId,
                                'app_id' => $consumer_id,
                                'app_secret' => $consumer_secret,
                                'api_domain' => $request->api_domain,
                                'allow_refresh' => 0,
                            ];
                            $this->mobj->makeInsertGetId('platform_accounts', $arr_field);
                        }
                    }
                }
            }
            if ($flag) {
                $data['status_code'] = 1;
                $data['status_text'] = 'Account connected successfully.';
            }
            return response()->json($data);
        }
    }
    /* Create Webhook */
    public function CreateOrDeleteWebhook($userId = null, $userIntegrationId = null, array $wooksType, $attempt)
    {
        $return_response = false;
        try {

            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                    if ($attempt == 1) { // create webhook

                        if (!empty($wooksType)) {
                            $orderhook = $producthook = $customerhook = $createWook = [];
                            $check_already_subscribed = DB::table('platform_webhook_info')->where('user_integration_id', $userIntegrationId)->where('platform_id', $ufound->platform_id)->where('status', 1)->pluck('description')->toArray();
                            /* Please pass last param as if APP_ENV=stag or local then 0 for staging/local mode and APP_ENV=prod then 1=for live mode */
                            $Mode = env('APP_ENV') == 'prod' ? "1" : "0";
                            if (in_array('order', $wooksType) && !in_array('order.created', $check_already_subscribed)) {

                                $orderhook = [
                                    [
                                        'name' => 'Order Created',
                                        'topic' => 'order.created',
                                        'delivery_url' => env('APP_WEBHOOK_URL') . '/woocommerce/public/order/' . $userIntegrationId . '/' . $Mode,
                                    ],
                                    [
                                        'name' => 'Order Updated',
                                        'topic' => 'order.updated',
                                        'delivery_url' => env('APP_WEBHOOK_URL') . '/woocommerce/public/order/' . $userIntegrationId . '/' . $Mode,
                                    ],
                                    [
                                        'name' => 'Order Deleted',
                                        'topic' => 'order.deleted',
                                        'delivery_url' => env('APP_WEBHOOK_URL') . '/woocommerce/public/order/' . $userIntegrationId . '/' . $Mode,
                                    ],
                                ];
                            }
                            if (in_array('product', $wooksType) && !in_array('product.created', $check_already_subscribed)) {
                                $producthook = [
                                    [
                                        'name' => 'Product Created',
                                        'topic' => 'product.created',
                                        'delivery_url' => env('APP_WEBHOOK_URL') . '/woocommerce/public/product/' . $userIntegrationId . '/' . $Mode,
                                    ],
                                    [
                                        'name' => 'Product Deleted',
                                        'topic' => 'product.deleted',
                                        'delivery_url' => env('APP_WEBHOOK_URL') . '/woocommerce/public/product/' . $userIntegrationId . '/' . $Mode,
                                    ],
                                    [
                                        'name' => 'Product Updated',
                                        'topic' => 'product.updated',
                                        'delivery_url' => env('APP_WEBHOOK_URL') . '/woocommerce/public/product/' . $userIntegrationId . '/' . $Mode,
                                    ],
                                ];
                            }
                            if (in_array('customer', $wooksType) && !in_array('customer.created', $check_already_subscribed)) {
                                $customerhook = [
                                    [
                                        'name' => 'Customer Created',
                                        'topic' => 'customer.created',
                                        'delivery_url' => env('APP_WEBHOOK_URL') . '/woocommerce/public/customer/' . $userIntegrationId . '/' . $Mode,
                                    ],
                                    [
                                        'name' => 'Customer Updated',
                                        'topic' => 'customer.updated',
                                        'delivery_url' => env('APP_WEBHOOK_URL') . '/woocommerce/public/customer/' . $userIntegrationId . '/' . $Mode,
                                    ],
                                    [
                                        'name' => 'Customer Deleted',
                                        'topic' => 'customer.deleted',
                                        'delivery_url' => env('APP_WEBHOOK_URL') . '/woocommerce/public/customer/' . $userIntegrationId . '/' . $Mode,
                                    ],
                                ];
                            }

                            $createWook = array_merge($orderhook, $producthook, $customerhook);
                            if (!empty($createWook)) {
                                $postData = [
                                    'create' => $createWook,
                                    'delete' => [],
                                ];

                                $response = $this->wc->CreateOrDeleteWebhook($ufound, null, $postData);

                                if ($webhook = json_decode($response->getBody(), true)) {

                                    if (!empty($webhook) && isset($webhook['create'])) {
                                        $hookList = [];
                                        foreach ($webhook['create'] as $key => $value) {
                                            if (!isset($value['error'])) {
                                                $hookList = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $ufound->platform_id, 'api_id' => $value['id'], 'description' => $value['topic'], 'status' => 1];

                                                $findHook = $this->mobj->getFirstResultByConditions('platform_webhook_info', [
                                                    'user_integration_id' => $userIntegrationId,
                                                    'platform_id' => $ufound->platform_id,
                                                    'api_id' => $value['id'],
                                                ], ['id']);
                                                if ($findHook) {
                                                    $this->mobj->makeUpdate(
                                                        'platform_webhook_info',
                                                        $hookList,
                                                        ['id' => $findHook->id]
                                                    );
                                                } else {
                                                    $this->mobj->makeInsert('platform_webhook_info', $hookList);
                                                }
                                            }
                                        }
                                    }
                                    $return_response = true;
                                } else {
                                    $return_response = $response;
                                }
                            }
                        }
                    } else if ($attempt == 2) { // Delete webhook
                        if (!empty($wooksType)) {
                            if (in_array('all', $wooksType)) {
                                $hookList = $this->mobj->getResultByConditions('platform_webhook_info', [
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' => $ufound->platform_id,
                                ], ['api_id'], ['id' => 'asc']);

                                if ($hookList->count() > 0) {
                                    $hook = $hookList->pluck('api_id')->toArray();
                                    $postData = [
                                        'create' => [],
                                        'delete' => $hook,
                                    ];
                                    $response = $this->wc->CreateOrDeleteWebhook($ufound, null, $postData);
                                    if ($webhook = json_decode($response->getBody(), true)) {

                                        if (!empty($webhook) && isset($webhook['delete'])) {

                                            foreach ($webhook['delete'] as $key => $value) {

                                                if (!isset($value['error'])) {
                                                    $hookList = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $ufound->platform_id, 'api_id' => $value['id']];
                                                    $this->mobj->makeDelete(
                                                        'platform_webhook_info',
                                                        $hookList
                                                    );
                                                }
                                            }
                                            $return_response = true;
                                        } else {
                                            $return_response = isset($webhook['code']) ? $webhook['message'] : "Error";
                                        }
                                    } else {
                                        $return_response = $response;
                                    }
                                } else {
                                    $return_response = true;
                                }
                            } else {
                                $hookList = DB::table('platform_webhook_info')->where([
                                    [
                                        'user_integration_id', '=', $userIntegrationId,
                                    ],
                                    ['platform_id', '=', $ufound->platform_id],
                                ])->whereIn('api_id', $wooksType)->get();

                                if ($hookList->count() > 0) {
                                    $hook = $hookList->pluck('api_id')->toArray();
                                    $postData = [
                                        'create' => [],
                                        'delete' => $hook,
                                    ];
                                    $response = $this->wc->CreateOrDeleteWebhook($ufound, null, $postData);
                                    if ($webhook = json_decode($response->getBody(), true)) {

                                        if (!empty($webhook) && isset($webhook['delete'])) {

                                            foreach ($webhook['delete'] as $key => $value) {
                                                if (!isset($value['error'])) {
                                                    $hookList = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $ufound->platform_id, 'api_id' => $value['id']];
                                                    $this->mobj->makeDelete(
                                                        'platform_webhook_info',
                                                        $hookList
                                                    );
                                                }
                                            }
                                            $return_response = true;
                                        } else {
                                            $return_response = isset($webhook['code']) ? $webhook['message'] : "Error";
                                        }
                                    } else {
                                        $return_response = $response;
                                    }
                                } else {
                                    $return_response = true;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Sales Order */
    public function GetSalesOrder($userId = null, $userIntegrationId = null, $platform_workflow_rule_id = null, array $wooksType, $attempt, $is_initial_syn)
    {
        if ($is_initial_syn) {
            return $this->CreateOrDeleteWebhook($userId, $userIntegrationId, $wooksType, $attempt);
        } else {
            // if (env('APP_ENV') == "stag") {
            return $this->GetSalesOrderBackup($userId, $userIntegrationId, $platform_workflow_rule_id);
            // } else {
            //     return  $this->ProcessPendingOrders($userId, $userIntegrationId, $platform_workflow_rule_id, "Pending");
            // }
        }
    }
    /* Get Webhook List */
    public function GetWebhookList($userIntegrationId = null)
    {
        try {

            $ufound = $this->getPrimaryAccount($userIntegrationId);

            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

                    $response = $this->wc->GetWebhookList($ufound, null);
                    if ($webhook = json_decode($response->getBody(), true)) {
                        return $webhook;
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
        }
    }

    /* Get Options */
    public function GetProductAttributes($arr, $productId)
    {
        if (!empty($arr)) {
            //Set Status 0

            $find = PlatformProductOption::where([['platform_product_id', '=', $productId], ['api_option_id', '=', $arr['api_option_id']]])->first();
            if ($find) {
                $find->api_option_id = $arr['api_option_id'];
                $find->option_name = $arr['option_name'];
                $find->option_value = $arr['option_value'];
                $find->status = 1;
                $find->save();
            } else {
                PlatformProductOption::insert($arr);
            }
        }
    }
    /* Prepare Modal Data */
    public function PrepareModalData($value, $user_id, $user_integration_id, $platform_id, $attribute = false)
    {
        $ProductPrimaryID = $categories = $wholeSalePrice = null;
        $user_integration_id = (int)$user_integration_id;
        $user_id = (int)$user_id;
        $apiProductId = (string)$value['id'];
        if (isset($value['categories'])) {
            foreach ($value['categories'] as $key => $cat) {
                $categories .= $cat['id'] . ",";
            }
            $categories = rtrim($categories, ",");
        }
        /* Check wholesale_customer_wholesale_price */
        if (isset($value['metadata']) && is_array($value['metadata'])) {
            foreach ($value['metadata'] as $key => $val) {
                if ($val['key'] == "wholesale_customer_wholesale_price") {
                    $wholeSalePrice = isset($val['value']) ? $val['value'] : null;
                    break;
                }
            }
        }
        $productList = array(
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $platform_id,
            'api_product_id' => $apiProductId,
            'api_updated_at' => $value['date_modified'],
            'sku' => $value['sku'],
            'product_name' => $value['name'],
            'description' => $value['description'],
            'product_status' => $value['status'],
            'stock_track' => $value['manage_stock'] ? 1 : 0,
            'category_id' => $categories,
            'weight' => $value['weight'],
            'parent_product_id' => isset($value['parent_product_id']) ? $value['parent_product_id'] : 0,
            'is_deleted' => 0,
            'product_sync_status' => "Ready",
        );
        if (isset($value['variations']) && is_array($value['variations']) && !empty($value['variations'])) {
            $productList['has_variations'] = true;
        } else {
            $productList['has_variations'] = false;
        }
        $AttributeData = [
            'lenght' => isset($value['dimensions']['length']) ? $value['dimensions']['length'] : null,
            'height' => isset($value['dimensions']['height']) ? $value['dimensions']['height'] : null,
            'width' => isset($value['dimensions']['width']) ? $value['dimensions']['width'] : null,
            'shortdescription' => $value['short_description'],
        ];
        $findProduct = PlatformProduct::where([
            'user_id' => $user_id,
            'user_integration_id' => (int)$user_integration_id,
            'platform_id' => $this->platformId,
            'api_product_id' => $apiProductId,
        ])->select('id')->first();
        if ($findProduct) {
            $this->mobj->makeUpdate(
                'platform_product',
                $productList,
                ['id' => $findProduct->id]
            );

            $AttributeData['platform_product_id'] = $findProduct->id;
            $this->CreateOrUpdateProductAttributes($findProduct->id, $AttributeData);
            $this->CreatePriceList($findProduct->id, "pricelist", $value['price'], $value['sale_price'], $value['regular_price'], $wholeSalePrice);
            if ($attribute) {

                if (isset($value['attributes']) && $value['attributes']) {
                    PlatformProductOption::where('platform_product_id', $findProduct->id)->update(['status' => 0]);
                    foreach ($value['attributes'] as $attr) {

                        $optionarr[] = isset($attr['option']) ? $attr['option'] : $attr['options']; //assing if option and options available
                        if (!empty($optionarr)) {
                            //If multiple option available
                            foreach ($optionarr as $option) {
                                $attrOption = [
                                    'api_option_id' => $attr['id'],
                                    'platform_product_id' => $findProduct->id,
                                    'option_name' => isset($attr['name']) ? isset($attr['name']) : null,
                                    'option_value' => $option,
                                    'status' => 1,
                                ];
                                $this->GetProductAttributes($attrOption, $findProduct->id);
                            }
                        }
                    }
                }
            }
            $ProductPrimaryID = $findProduct->id;
        } else {
            $productLinkId = $this->mobj->makeInsertGetId('platform_product', $productList);
            $AttributeData['platform_product_id'] = $productLinkId;
            $this->CreateOrUpdateProductAttributes($productLinkId, $AttributeData);
            $this->CreatePriceList($productLinkId, "pricelist", $value['price'], $value['sale_price'], $value['regular_price'], $wholeSalePrice);
            if ($attribute) {

                if (isset($value['attributes']) && $value['attributes']) {
                    PlatformProductOption::where('platform_product_id', $productLinkId)->update(['status' => 0]);
                    foreach ($value['attributes'] as $attr) {

                        $optionarr[] = isset($attr['option']) ? $attr['option'] : $attr['options']; //assing if option and options available
                        if (!empty($optionarr)) {
                            //If multiple option available
                            foreach ($optionarr as $option) {
                                $attrOption = [
                                    'api_option_id' => $attr['id'],
                                    'platform_product_id' => $productLinkId,
                                    'option_name' => isset($attr['name']) ? isset($attr['name']) : null,
                                    'option_value' => $option,
                                    'status' => 1,
                                ];
                                $this->GetProductAttributes($attrOption, $productLinkId);
                            }
                        }
                    }
                }
            }
            $ProductPrimaryID = $productLinkId;
        }
        return $ProductPrimaryID;
    }
    /* Prepare Customer Modal Data */
    public function PrepareCustomerModalData($value, $userId, $userIntegrationId, $platform_id)
    {
        $customersList = array(
            'user_id' => $userId,
            'user_integration_id' => $userIntegrationId,
            'platform_id' => $platform_id,
            'api_customer_id' => $value['id'],
            'first_name' => $value['first_name'],
            'last_name' => $value['last_name'],
            'email' => $value['email'],
            'customer_name' => $value['first_name'] . " " . $value['last_name'],
        );

        $find = $this->mobj->getFirstResultByConditions('platform_customer', [
            'user_id' => $userId,
            'user_integration_id' => $userIntegrationId,
            'platform_id' => $platform_id,
            'api_customer_id' => (string)$value['id'],
        ], ['id']);
        if ($find) {
            $this->mobj->makeUpdate(
                'platform_customer',
                $customersList,
                ['id' => $find->id]
            );
        } else {
            $this->mobj->makeInsert('platform_customer', $customersList);
        }
    }
    /* Get Variants of products which is coming from webhooks and where status is Pending */

    public function ProcessProductVariationInfomation($account = null, $userId = null, $userIntegrationId = null, $sync_status = "Pending")
    {
        $return_response = false;
        try {
            $process_limit = 50;
            if (!$account) {
                //if account is null
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            if ($account && $this->platformId) {
                if (isset($account->platform_id) && $account->platform_id == $this->platformId) {
                    $variants = PlatformProduct::where([
                        ['user_id', '=', $userId],
                        ['user_integration_id', '=', $userIntegrationId],
                        ['platform_id', '=', $this->platformId],
                        ['product_sync_status', '=', $sync_status],
                        ['parent_product_id', '!=', 0],
                        ['is_deleted', '=', 0],
                    ])->select("api_product_id", "parent_product_id")->limit($process_limit)->get();
                    if (!empty($variants) && count($variants) > 0) {

                        foreach ($variants as $vproduct) {
                            if (isset($vproduct->api_product_id) && isset($vproduct->parent_product_id) && $vproduct->parent_product_id != 0) {
                                $url = "/wp-json/wc/v3/products/{$vproduct->api_product_id}?page=1&per_page=1";
                                $response = $this->wc->GetProducts($account, $url);
                                if (isset($response['status_code']) && ($response['status_code'] == 200 || $response['status_code'] == 201)) {
                                    $productV = $response['body'];
                                    if (!empty($productV) || is_array($productV)) {
                                        if (!isset($productV['error'])) {
                                            $productV['parent_product_id'] = $vproduct->parent_product_id;
                                            $this->PrepareModalData($productV, $userId, $userIntegrationId, $this->platformId);
                                        } else {
                                            continue;
                                        }
                                    }
                                }
                            }
                        }
                        $return_response = true;
                    }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Products New Method */
    public function GetProducts($userId = null, $userIntegrationId = null, $attempt = 1, $is_initial_syn = 0)
    {
        $this->mobj->AddMemory(); //Add extra memory to execute
        $return_response = false;
        try {

            $ufound = $this->getPrimaryAccount($userIntegrationId);

            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                    if ($attempt == 1 && $is_initial_syn == 1) {
                        // get prducts by chunks in loop when intial sync=1
                        $x = 1;
                        while ($x <= 2) {
                            $loopBreaker = true;
                            $pageNo = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $this->platformId], ['url_name', '=', 'products']])->first();
                            if (isset($pageNo->url)) {
                                if ($pageNo->url == 0 && $pageNo->status == 1) {

                                    $loopBreaker = false;
                                } else {
                                    $page = $pageNo->url + 1;
                                }
                            } else {
                                $page = 1;
                            }
                            if ($loopBreaker) {
                                $pageCounter = $page;
                                $pageLimit = 100;
                                $breakCounter = 0;
                                $response = $this->wc->GetProducts($ufound, null, $page, $pageLimit);
                                if (isset($response['status_code']) && ($response['status_code'] == 200 || $response['status_code'] == 201)) {
                                    $product = $response['body'];
                                    if (!empty($product) && is_array($product) && !is_bool($product)) {
                                        if (!isset($product['code'])) {

                                            foreach ($product as $key => $value) {
                                                if (!isset($value['error'])) {
                                                    // if (!empty($value['sku'])) {
                                                    $ProductPrimaryID = $this->PrepareModalData($value, $userId, $userIntegrationId, $this->platformId);

                                                    if (!empty($value['variations']) && isset($value['variations'])) {
                                                        //if we've parent product
                                                        // PlatformProduct::where('parent_product_id', $ProductPrimaryID)->update(['is_deleted' => 1]);

                                                        /* If we have variants */
                                                        foreach ($value['variations'] as $variants) {
                                                            $url = "/wp-json/wc/v3/products/{$variants}?page=1&per_page=1";
                                                            $response = $this->wc->GetProducts($ufound, $url);
                                                            if (isset($response['status_code']) && ($response['status_code'] == 200 || $response['status_code'] == 201)) {
                                                                $productV = $response['body'];
                                                                if (!empty($productV) || is_array($productV)) {
                                                                    if (!isset($productV['error'])) {
                                                                        //Set parent product id
                                                                        $productV['parent_product_id'] = $ProductPrimaryID;
                                                                        $this->PrepareModalData($productV, $userId, $userIntegrationId, $this->platformId);
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }

                                                    //  }
                                                } else {
                                                    $breakCounter = 1;
                                                    break;
                                                }
                                            }
                                            if ($breakCounter == 0) {

                                                if (isset($pageNo->url)) {
                                                    $pageNo->url = $page;
                                                    $pageNo->status = 0;
                                                    $pageNo->save();
                                                } else {
                                                    PlatformUrl::insert([
                                                        'user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId,
                                                        'url' => $page + 1,
                                                        'url_name' => 'products',
                                                        'status' => 0,
                                                    ]);
                                                }
                                                $return_response = "Page-{$pageCounter} data processed";
                                            } else {
                                                $return_response = "API Error to get products from Woocommerce";
                                            }
                                        } else {
                                            $return_response = "API Error to get products from Woocommerce";
                                        }
                                    } else {
                                        if (isset($pageNo->url)) {
                                            $pageNo->url = 0;
                                            $pageNo->status = 1;
                                            $pageNo->save();
                                        }
                                        $return_response = true;
                                    }
                                }
                            } else {
                                $return_response = true;
                            }
                            $x++;
                        }
                    } else if ($attempt == 2 && $is_initial_syn == 0) {
                        $return_response = $this->ProcessProductVariationInfomation($ufound, $userId, $userIntegrationId, "Pending");
                    }
                }
            }
        } catch (\Exception $e) {
            //\Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Customers */
    public function GetCustomers($userId = null, $userIntegrationId = null, $attempt = 1, $is_initial_syn = 0)
    {
        $this->mobj->AddMemory(); //Add extra memory to execute
        $return_response = false;
        try {

            $ufound = $this->getPrimaryAccount($userIntegrationId);

            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                    if ($attempt == 1 && $is_initial_syn == 1) {
                        // get all customer in chunks in loop

                        $x = 1;
                        while ($x <= 5) {
                            $loopBreaker = true;
                            $pageNo = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $this->platformId], ['url_name', '=', 'customers']])->first();
                            if (isset($pageNo->url)) {
                                if ($pageNo->url == 0 && $pageNo->status == 1) {

                                    $loopBreaker = false;
                                } else {
                                    $page = $pageNo->url + 1;
                                }
                            } else {
                                $page = 1;
                            }
                            if ($loopBreaker) {
                                $pageCounter = $page;
                                $pageLimit = 100;
                                $breakCounter = 0;
                                $response = $this->wc->GetCustomers($ufound, null, $page, $pageLimit);
                                $customer = json_decode($response->getBody(), true);

                                if (!empty($customer) && is_array($customer) && !is_bool($customer)) {
                                    if (!isset($customer['code'])) {
                                        foreach ($customer as $key => $value) {
                                            if (!isset($value['error'])) {

                                                $this->PrepareCustomerModalData($value, $userId, $userIntegrationId, $this->platformId);
                                            } else {
                                                $breakCounter = 1;
                                                break;
                                            }
                                        }
                                        if ($breakCounter == 0) {

                                            if (isset($pageNo->url)) {
                                                $pageNo->url = $page;
                                                $pageNo->status = 0;
                                                $pageNo->save();
                                            } else {
                                                PlatformUrl::insert([
                                                    'user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId,
                                                    'url' => $page + 1,
                                                    'url_name' => 'customers',
                                                    'status' => 0,
                                                ]);
                                            }
                                            $return_response = "Page-{$pageCounter} data processed";
                                        } else {
                                            $return_response = "API Error to get customers from Woocommerce";
                                        }
                                    } else {
                                        $return_response = "API Error to get customers from Woocommerce";
                                    }
                                } else {
                                    if (isset($pageNo->url)) {
                                        $pageNo->url = 0;
                                        $pageNo->status = 1;
                                        $pageNo->save();
                                    }
                                    $return_response = true;
                                }
                            } else {
                                $return_response = true;
                            }
                            $x++;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            //\Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Payments Gateways */
    public function GetPaymentGateways($userId = null, $userIntegrationId = null, $attempt)
    {
        $return_response = false;
        try {

            $ufound = $this->getPrimaryAccount($userIntegrationId);

            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                    if ($attempt == 1) { // get all prducts by one time in loop

                        $response = $this->wc->GetPaymentGateways($ufound, null);

                        if ($payments = json_decode($response->getBody(), true)) {

                            if (!empty($payments)) {
                                $paymentList = [];

                                $objectId = $this->helper->getObjectId("payment");
                                if (isset($objectId)) {
                                    // update users integration payment gateway method status to 0.
                                    $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $ufound->platform_id, 'platform_object_id' => $objectId]);

                                    foreach ($payments as $key => $value) {
                                        if (!isset($value['error'])) {
                                            $paymentList = [
                                                'user_id' => $userId,
                                                'platform_id' => $ufound->platform_id,
                                                'user_integration_id' => $userIntegrationId,
                                                'name' => $value['title'],
                                                'api_id' => $value['id'],
                                                'api_code' => $value['id'],
                                                'status' => 1,
                                                'platform_object_id' => $objectId,
                                            ];

                                            $findPayment = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                                'user_integration_id' => $userIntegrationId,
                                                'platform_id' => $ufound->platform_id,
                                                'platform_object_id' => $objectId,
                                                'api_id' => $value['id'],
                                            ], ['id']);
                                            if ($findPayment) {
                                                $this->mobj->makeUpdate(
                                                    'platform_object_data',
                                                    $paymentList,
                                                    ['id' => $findPayment->id]
                                                );
                                            } else {
                                                $this->mobj->makeInsert('platform_object_data', $paymentList);
                                            }
                                        }
                                    }
                                    $return_response = true;
                                }
                            }
                        } else {
                            $return_response = false;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Shipping Methods */
    public function GetShippingMethods($userId = null, $userIntegrationId = null, $attempt)
    {
        $return_response = false;
        try {
            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                    if ($attempt == 1) { // get all prducts by one time in loop
                        $response = $this->wc->GetShippingMethods($ufound, null);
                        if ($shipping = json_decode($response->getBody(), true)) {

                            if (!empty($shipping)) {
                                $shippingList = [];
                                $objectId = $this->helper->getObjectId("shipping_method");
                                if (isset($objectId)) {
                                    // update users integration shipping method status to 0.
                                    $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $ufound->platform_id, 'platform_object_id' => $objectId]);

                                    foreach ($shipping as $key => $value) {
                                        if (!isset($value['error'])) {
                                            $shippingList = [
                                                'user_id' => $userId,
                                                'platform_id' => $ufound->platform_id,
                                                'user_integration_id' => $userIntegrationId,
                                                'name' => $value['title'],
                                                'api_id' => $value['id'],
                                                'description' => $value['description'],
                                                'status' => 1,
                                                'platform_object_id' => $objectId,
                                            ];

                                            $findShipping = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                                'user_integration_id' => $userIntegrationId,
                                                'platform_id' => $ufound->platform_id,
                                                'platform_object_id' => $objectId,
                                                'api_id' => $value['id'],
                                            ], ['id']);
                                            if ($findShipping) {
                                                $this->mobj->makeUpdate(
                                                    'platform_object_data',
                                                    $shippingList,
                                                    ['id' => $findShipping->id]
                                                );
                                            } else {
                                                $this->mobj->makeInsert('platform_object_data', $shippingList);
                                            }
                                        }
                                    }
                                }
                                $return_response = true;
                            }
                        } else {
                            $return_response = false;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            //\Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Zone  */
    public function GetZone($userId = null, $userIntegrationId = null, $attempt)
    {
        $return_response = false;
        try {
            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                    if ($attempt == 1) { // get zone for shipping methods
                        $response = $this->wc->GetZone($ufound, null);
                        if ($zone = json_decode($response->getBody(), true)) {

                            if (!empty($zone)) {
                                $zoneList = [];

                                $objectId = $this->helper->getObjectId("zone");
                                if (isset($objectId)) {
                                    // update users integration shipping method status to 0.
                                    $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $ufound->platform_id, 'platform_object_id' => $objectId]);

                                    foreach ($zone as $key => $value) {
                                        if (!isset($value['error'])) {
                                            $zoneList = [
                                                'user_id' => $userId,
                                                'platform_id' => $ufound->platform_id,
                                                'user_integration_id' => $userIntegrationId,
                                                'name' => $value['name'],
                                                'api_id' => $value['id'],
                                                'status' => 1,
                                                'platform_object_id' => $objectId,
                                            ];
                                            $findZone = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                                'user_integration_id' => $userIntegrationId,
                                                'platform_id' => $ufound->platform_id,
                                                'platform_object_id' => $objectId,
                                                'api_id' => $value['id'],
                                            ], ['id']);
                                            if ($findZone) {
                                                $this->mobj->makeUpdate(
                                                    'platform_object_data',
                                                    $zoneList,
                                                    ['id' => $findZone->id]
                                                );
                                            } else {
                                                $this->mobj->makeInsert('platform_object_data', $zoneList);
                                            }
                                        }
                                    }
                                }
                                $return_response = true;
                            }
                        } else {
                            $return_response = false;
                        }
                    }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Zone For Shipping Methods */
    public function GetZoneShippingMethod($userId = null, $userIntegrationId = null, $zonePrimaryID = null)
    {
        $return_response = false;
        try {
            if ($zonePrimaryID && $zonePrimaryID !== 0) {
                $findZone = PlatformObjectData::find($zonePrimaryID);
                if ($findZone) {
                    $ufound = $this->getPrimaryAccount($userIntegrationId);
                    if ($ufound && $this->platformId) {
                        if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                            // get zone for shipping methods
                            $response = $this->wc->GetShippingMethodByZoneID($ufound, null, $findZone->api_id);
                            if ($zone = json_decode($response->getBody(), true)) {

                                if (!empty($zone)) {
                                    $zoneList = [];

                                    $objectId = $this->helper->getObjectId("shipping_method");
                                    if (isset($objectId)) {
                                        // update users integration shipping method status to 0.
                                        $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $ufound->platform_id, 'platform_object_id' => $objectId, 'parent_id' => $zonePrimaryID]);

                                        foreach ($zone as $key => $value) {
                                            if (!isset($value['error'])) {
                                                $zoneList = [
                                                    'user_id' => $userId,
                                                    'platform_id' => $ufound->platform_id,
                                                    'user_integration_id' => $userIntegrationId,
                                                    'name' => isset($value['title']) ? $value['title'] : $value['method_title'],
                                                    'api_id' => isset($value['instance_id']) ? $value['instance_id'] : $value['instance_id'],
                                                    'api_code' => $value['method_id'],
                                                    'status' => 1,
                                                    'parent_id' => $zonePrimaryID,
                                                    'platform_object_id' => $objectId,
                                                ];

                                                $findZone = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                                    'user_integration_id' => $userIntegrationId,
                                                    'platform_id' => $ufound->platform_id,
                                                    'platform_object_id' => $objectId,
                                                    'api_id' => $value['instance_id'],
                                                    'parent_id' => $zonePrimaryID,
                                                ], ['id']);
                                                if ($findZone) {
                                                    $this->mobj->makeUpdate(
                                                        'platform_object_data',
                                                        $zoneList,
                                                        ['id' => $findZone->id]
                                                    );
                                                } else {
                                                    $this->mobj->makeInsert('platform_object_data', $zoneList);
                                                }
                                            }
                                        }
                                    }
                                    $return_response = true;
                                }
                            } else {
                                $return_response = false;
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
    /* Get Tax Codes */
    public function GetTaxCodes($userId = null, $userIntegrationId = null, $attempt)
    {
        $return_response = false;
        try {
            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                    if ($attempt == 1) { // get all prducts by one time in loop
                        $response = $this->wc->GetTaxCodes($ufound, null);

                        $taxcode = json_decode($response->getBody(), true);

                        if (!empty($taxcode)) {
                            $taxcodeList = [];

                            $objectId = $this->helper->getObjectId("taxcode");
                            if (isset($objectId)) {
                                // update users integration taxcode status to 0.
                                $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $ufound->platform_id, 'platform_object_id' => $objectId]);

                                foreach ($taxcode as $key => $value) {
                                    if (!isset($value['error'])) {
                                        $taxcodeList = [
                                            'user_id' => $userId,
                                            'platform_id' => $ufound->platform_id,
                                            'user_integration_id' => $userIntegrationId,
                                            'name' => $value['name'],
                                            'api_id' => $value['id'],
                                            'api_code' => $value['country'],
                                            'description' => $value['state'],
                                            'status' => 1,
                                            'platform_object_id' => $objectId,
                                        ];

                                        $findtaxcode = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                            'user_integration_id' => $userIntegrationId,
                                            'platform_id' => $ufound->platform_id,
                                            'platform_object_id' => $objectId,
                                            'api_id' => $value['id'],
                                        ], ['id']);
                                        if ($findtaxcode) {
                                            $this->mobj->makeUpdate(
                                                'platform_object_data',
                                                $taxcodeList,
                                                ['id' => $findtaxcode->id]
                                            );
                                        } else {
                                            $this->mobj->makeInsert('platform_object_data', $taxcodeList);
                                        }
                                    }
                                }
                                $return_response = true;
                            }
                        }
                        $return_response = true;
                    }
                }
            }
        } catch (\Exception $e) {
            //\Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Product Attributes Codes & Values */
    public function GetAttributesAndValues($userId = null, $userIntegrationId = null, $attempt)
    {
        $return_response = false;
        try {
            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                    if ($attempt == 1) { // get all prducts attributes by one time in loop
                        $response = $this->wc->GetAttributes($ufound, null);

                        $result = json_decode($response->getBody(), true);

                        if (!empty($result)) {
                            $List = [];
                            $AttributeObjectId = $this->helper->getObjectId('attribute');
                            $AttributeValueObjectId = $this->helper->getObjectId('attribute_value');
                            if (isset($AttributeObjectId)) {
                                // update status to 0.
                                $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $AttributeObjectId]);

                                foreach ($result as $key => $value) {
                                    if (!isset($value['error'])) {
                                        $attributeID = $value['id'];
                                        $List = [
                                            'user_id' => $userId,
                                            'platform_id' => $this->platformId,
                                            'user_integration_id' => $userIntegrationId,
                                            'name' => $value['name'],
                                            'api_id' => $attributeID,
                                            'status' => 1,
                                            'platform_object_id' => $AttributeObjectId,
                                        ];

                                        $findtaxcode = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                            'user_integration_id' => $userIntegrationId,
                                            'platform_id' => $this->platformId,
                                            'platform_object_id' => $AttributeObjectId,
                                            'api_id' => $attributeID,
                                        ], ['id']);
                                        if ($findtaxcode) {

                                            $attributePrimaryID = $findtaxcode->id;
                                            /* update attributes */
                                            $this->mobj->makeUpdate(
                                                'platform_object_data',
                                                $List,
                                                ['id' => $attributePrimaryID]
                                            );
                                            /* get attrubute values by API call */
                                            $this->GetAttributesValues($attributeID, $userId, $userIntegrationId, $attributePrimaryID, $AttributeValueObjectId, $ufound);
                                        } else {
                                            $attributePrimaryID = $this->mobj->makeInsertGetId('platform_object_data', $List);
                                            /* get attrubute values by API call */
                                            $this->GetAttributesValues($attributeID, $userId, $userIntegrationId, $attributePrimaryID, $AttributeValueObjectId, $ufound);
                                        }
                                    }
                                }
                                $return_response = true;
                            }
                        }
                        $return_response = true;
                    }
                }
            }
        } catch (\Exception $e) {
            //\Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Attribute Values By ID */
    public function GetAttributesValues($attributeID, $userId, $userIntegrationId, $attributePrimaryID, $ObjectID, $account)
    {
        $response = $this->wc->CallAPI($account, "GET", "products/attributes/{$attributeID}/terms?");
        $attributeValuesResponse = json_decode($response->getBody(), true);
        /* first update status=0 of parent id */
        $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $ObjectID, 'parent_id' => $attributePrimaryID]);
        if (!empty($attributeValuesResponse) && is_array($attributeValuesResponse)) {

            foreach ($attributeValuesResponse as $key => $value) {
                if (!isset($value['error'])) {
                    $List = [
                        'user_id' => $userId,
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $userIntegrationId,
                        'name' => $value['name'],
                        'api_id' => $value['id'],
                        'status' => 1,
                        'platform_object_id' => $ObjectID,
                        'parent_id' => $attributePrimaryID,
                    ];

                    $find = $this->mobj->getFirstResultByConditions('platform_object_data', [
                        'user_integration_id' => $userIntegrationId,
                        'platform_id' => $this->platformId,
                        'platform_object_id' => $ObjectID,
                        'api_id' => $value['id'],
                        'parent_id' => $attributePrimaryID,
                    ], ['id']);
                    if ($find) {

                        /* update attribute values*/
                        $this->mobj->makeUpdate(
                            'platform_object_data',
                            $List,
                            ['id' => $find->id]
                        );
                    } else {
                        $this->mobj->makeInsert('platform_object_data', $List);
                    }
                }
            }
        }
    }
    public function CreateUpdateDeleteAttributes($attributeID = null, $userId, $userIntegrationId, $ObjectID, $account = null, $payload, $type = null)
    {

        try {
            if (!isset($account)) { //if account detail not pass
                $account = $this->getPrimaryAccount($userIntegrationId);
            }
            if ($attributeID) { //if attribute ID pass, Call API by Attribute ID

                if ($type == "create") {
                    $response = $this->wc->CallAPI($account, "POST", "products/attributes?", $payload);
                } else if ($type == "update") {
                    $response = $this->wc->CallAPI($account, "PUT", "products/attributes/{$attributeID}?", $payload);
                } else if ($type == "delete") {
                    $response = $this->wc->CallAPI($account, "DELETE", "products/attributes/{$attributeID}?");
                }
                $attributeValuesResponse = json_decode($response->getBody(), true);
            } else {

                //Update,Create and Delete by Bulk
                $response = $this->wc->CallAPI($account, "POST", "products/attributes/batch?", $payload, 'json');

                $attributeValuesResponse = json_decode($response->getBody(), true);

                if (!empty($attributeValuesResponse)) {
                    if (isset($attributeValuesResponse['create']) && is_array($attributeValuesResponse['create'])) {
                        foreach ($attributeValuesResponse['create'] as $key => $value) {
                            if (!isset($value['error'])) {
                                $List = [
                                    'user_id' => $userId,
                                    'platform_id' => $this->platformId,
                                    'user_integration_id' => $userIntegrationId,
                                    'name' => $value['name'],
                                    'api_id' => $value['id'],
                                    'status' => 1,
                                    'platform_object_id' => $ObjectID,
                                ];

                                $find = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' => $this->platformId,
                                    'platform_object_id' => $ObjectID,
                                    'api_id' => $value['id'],

                                ], ['id']);
                                if ($find) {

                                    /* update attribute values*/
                                    $this->mobj->makeUpdate(
                                        'platform_object_data',
                                        $List,
                                        ['id' => $find->id]
                                    );
                                } else {
                                    $this->mobj->makeInsert('platform_object_data', $List);
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
    /* Get Tax Codes */
    public function GetCategories($userId = null, $userIntegrationId = null, $is_initial_syn = 0)
    {
        $return_response = false;
        try {
            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

                    $objectId = $this->helper->getObjectId("category");
                    // get all categories by one time in loop
                    $this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $objectId]);

                    $x = true;
                    $page = 1;
                    while ($x) {
                        $pageLimit = 100;
                        $response = $this->wc->GetCategories($ufound, null, $page, $pageLimit);
                        $result = json_decode($response->getBody(), true);

                        if (!empty($result) && is_array($result) && count($result) > 0) {

                            foreach ($result as $key => $value) {
                                if (!isset($value['code'])) {
                                    $List = [
                                        'user_id' => $userId,
                                        'platform_id' => $ufound->platform_id,
                                        'user_integration_id' => $userIntegrationId,
                                        'name' => strip_tags(htmlspecialchars_decode($value['name'])),
                                        'api_id' => $value['id'],
                                        'api_code' => $value['parent'],
                                        'description' => $value['description'],
                                        'status' => 1,
                                        'platform_object_id' => $objectId,
                                    ];

                                    $findtaxcode = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                        'user_integration_id' => $userIntegrationId,
                                        'platform_id' => $ufound->platform_id,
                                        'platform_object_id' => $objectId,
                                        'api_id' => $value['id'],
                                    ], ['id']);
                                    if ($findtaxcode) {
                                        $this->mobj->makeUpdate(
                                            'platform_object_data',
                                            $List,
                                            ['id' => $findtaxcode->id]
                                        );
                                    } else {
                                        $this->mobj->makeInsert('platform_object_data', $List);
                                    }
                                } else {
                                    $return_response = "API Error";
                                }
                            }
                            $page++;
                        } else {
                            $x = false;
                        }
                    }
                    $return_response = true;
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Find Price List By Product ID */
    public function FindPriceList($productID, $userIntegrationId, $PlatformWorkFlowRuleID, $object_id = null)
    {
        $regular_price = $price = $sale_price = $whole_sale_price = "";
        $has_regular_price = $has_sales_price = false;
        $priceListArray = DB::table('platform_porduct_price_list as pp')
            ->join('platform_object_data as data', 'pp.platform_object_data_id', '=', 'data.id')
            ->where('pp.platform_product_id', $productID)
            ->select('pp.platform_product_id', 'pp.price', 'pp.api_currency_code', 'pp.platform_object_data_id')->get();

        if (!empty($priceListArray)) {
            foreach ($priceListArray as $key => $value) {
                $priceName = $this->map->getObjectDataByID($value->platform_object_data_id, ['api_id']);

                if (isset($priceName->api_id)) {
                    $res = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowRuleID, "product_pricelist", ['api_id'], "regular", $priceName->api_id, "single");
                    if ($res) {
                        if ($res->api_id == "regular_price") {
                            $has_regular_price = true;
                            $regular_price = (string) $value->price;
                        }
                        if ($res->api_id == "sale_price") {
                            $has_sales_price = true;
                            $sale_price = (string) $value->price;
                        }
                        if ($res->api_id == "whole_sale_price") {
                            $whole_sale_price = (string) $value->price;
                        }
                    }
                }
            }
        }

        return ['price' => $price, 'sale_price' => $sale_price, 'regular_price' => $regular_price, 'whole_sale_price' => $whole_sale_price, 'has_regular_price' => $has_regular_price, 'has_sales_price' => $has_sales_price];
    }
    /* Find and campare Category IDs*/
    public function FindCategoriesMappingByNames($CategoriesID, $userID, $userIntegrationId, $SourcePlatformId, $ObjectID)
    {

        $return_response = [];
        if ($ObjectID) {
            $source = DB::table("platform_object_data")->whereIn('api_id', $CategoriesID)->select('name')->where([['platform_object_id', '=', $ObjectID], ['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $SourcePlatformId]])->pluck('name')->toArray();

            $destination = DB::table("platform_object_data")->select('name', 'api_id')->where([['platform_object_id', '=', $ObjectID], ['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $this->platformId]])->get()->toArray();

            if (!empty($source) && !empty($destination)) {

                foreach ($source as $key => $value) {
                    foreach ($destination as $dkey => $dvalue) {
                        $source_name = strip_tags(htmlspecialchars_decode($value));
                        $destination_name = strip_tags(htmlspecialchars_decode($dvalue->name));
                        if (strtolower($source_name) == strtolower($destination_name)) {
                            $return_response[] = ['id' => $dvalue->api_id];
                            break;
                        }
                    }
                }
            }
        }
        return $return_response;
    }
    /* Find Attribute By Name */
    public function FindAttributeMappingByNames($AttributeName, $userID, $userIntegrationId, $ObjectID = null)
    {

        $return_response = [];

        if (!isset($ObjectID)) {
            $ObjectID = $this->helper->getObjectId("attribute");
        }

        if ($ObjectID) {
            $destinationRows = DB::table("platform_object_data")->select('name', 'api_id')->where([['platform_object_id', '=', $ObjectID], ['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $this->platformId], ['status', '=', 1]])->get();
            if (count($destinationRows) > 0) {
                foreach ($destinationRows as $attribute) {
                    if (strtolower($AttributeName) == strtolower($attribute->name)) {
                        return $attribute->api_id;
                        break;
                    }
                }
            }
        }
        return $return_response;
    }
    /* Find /create/update/delete Categories */
    public function FindCategories($CategoriesID, $userID, $userIntegrationId, $SourcePlatformId, $ObjectID, $Account)
    {

        $return_response = false;
        if ($ObjectID) {
            $source = DB::table("platform_object_data")->whereIn('api_id', $CategoriesID)->select('name')->where([['platform_object_id', '=', $ObjectID], ['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $SourcePlatformId]])->pluck('name')->toArray();

            $destination = DB::table("platform_object_data")->select('name')->where([['platform_object_id', '=', $ObjectID], ['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $this->platformId]])->pluck('name')->toArray();

            if (!empty($source)) {
                $names = [];
                foreach ($source as $key => $value) {
                    $nm = strip_tags(htmlspecialchars_decode($value));
                    if (!in_array(strtolower($nm), array_map('strtolower', $destination))) {
                        $names[] = ['name' => $nm];
                    }
                }

                if (!empty($names)) {
                    $data = [
                        'create' =>
                        $names,
                        'update' => [],
                        'delete' => [],
                    ];

                    $return_response = $this->CreateOrUpdateOrDeleteCategories($userID, $userIntegrationId, $data, $Account, $ObjectID);
                }
            }
        }
        return $return_response;
    }
    /* Create | Update | Delete Categories */
    public function CreateOrUpdateOrDeleteCategories($userId = null, $userIntegrationId = null, $postData = [], $account = null, $ObjectID = null)
    {
        $return_response = false;
        try {
            if ($account) {
                $response = $this->wc->CreateOrUpdateOrDeleteCategories($account, null, $postData);

                if (json_decode($response->getBody(), true)) {

                    $result = json_decode($response->getBody(), true);

                    if (!empty($result)) {
                        $List = [];
                        if ($ObjectID) {
                            $ObjectID = $ObjectID;
                        } else {
                            $ObjectID = $this->helper->getObjectId('category');
                        }

                        if (isset($ObjectID)) {

                            foreach ($result['create'] as $key => $value) {
                                if (isset($value['id']) && $value['id'] && !isset($value['error'])) {
                                    $List = [
                                        'user_id' => $userId,
                                        'platform_id' => $account->platform_id,
                                        'user_integration_id' => $userIntegrationId,
                                        'name' => strip_tags(htmlspecialchars_decode($value['name'])),
                                        'api_id' => $value['id'],
                                        'api_code' => $value['parent'],
                                        'description' => $value['description'],
                                        'status' => 1,
                                        'platform_object_id' => $ObjectID,
                                    ];

                                    $findtaxcode = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                        'user_integration_id' => $userIntegrationId,
                                        'platform_id' => $account->platform_id,
                                        'platform_object_id' => $ObjectID,
                                        'api_id' => $value['id'],
                                    ], ['id']);
                                    if ($findtaxcode) {
                                        $this->mobj->makeUpdate(
                                            'platform_object_data',
                                            $List,
                                            ['id' => $findtaxcode->id]
                                        );
                                    } else {
                                        $this->mobj->makeInsert('platform_object_data', $List);
                                    }
                                }
                            }
                            $return_response = true;
                        }
                    }
                }
            } else {
                $account = $this->getPrimaryAccount($userIntegrationId);
                if ($account && $this->platformId) {
                    if (isset($account->platform_id) && $account->platform_id == $this->platformId) {
                        $response = $this->wc->CreateOrUpdateOrDeleteCategories($account, null, $postData);
                        if (json_decode($response->getBody(), true)) {

                            $result = json_decode($response->getBody(), true);
                            if (!empty($result)) {
                                $List = [];
                                if ($ObjectID) {
                                    $ObjectID = $ObjectID;
                                } else {
                                    $ObjectID = $this->helper->getObjectId('category');
                                }

                                if (isset($ObjectID)) {

                                    foreach ($result['create'] as $key => $value) {
                                        if (isset($value['id']) && $value['id'] && !isset($value['error'])) {
                                            $List = [
                                                'user_id' => $userId,
                                                'platform_id' => $account->platform_id,
                                                'user_integration_id' => $userIntegrationId,
                                                'name' => $value['name'],
                                                'api_id' => $value['id'],
                                                'api_code' => $value['parent'],
                                                'description' => $value['description'],
                                                'status' => 1,
                                                'platform_object_id' => $ObjectID,
                                            ];

                                            $findtaxcode = $this->mobj->getFirstResultByConditions('platform_object_data', [
                                                'user_integration_id' => $userIntegrationId,
                                                'platform_id' => $account->platform_id,
                                                'platform_object_id' => $ObjectID,
                                                'api_id' => $value['id'],
                                            ], ['id']);
                                            if ($findtaxcode) {
                                                $this->mobj->makeUpdate(
                                                    'platform_object_data',
                                                    $List,
                                                    ['id' => $findtaxcode->id]
                                                );
                                            } else {
                                                $this->mobj->makeInsert('platform_object_data', $List);
                                            }
                                        }
                                    }
                                    $return_response = true;
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
    /* Get Product By ID */
    private function GetProductByID($productId, $account, $userId, $userIntegrationId)
    {

        $url = "/wp-json/wc/v3/products/{$productId}?page=1&per_page=1";
        $response = $this->wc->GetProducts($account, $url);
        if (isset($response['status_code']) && ($response['status_code'] == 200 || $response['status_code'] == 201)) {
            $productV = $response['body'];
            if (!empty($productV) || is_array($productV)) {
                if (!isset($productV['code'])) {
                    //$this->PrepareModalData($productV, $userId, $userIntegrationId, $this->platformId);
                    return $productV['attributes'];
                } else {
                    return false;
                }
            }
        }
        return false;
    }
    private function MergeAttributes($MainAttribute, $SecondAttribute)
    {

        $memo = [];
        foreach ($MainAttribute as $key => $val) {

            if (isset($memo[$val['id']])) {
                $memo[$val['id']] = $memo[$val['id']];
            } else {
                $options = isset($val['options']) ? $val['options'] : $val['option'];
                if (is_array($options)) {
                    $opt = [];
                    foreach ($options as $k => $option) {
                        $opt[] = $option;
                    }
                    $memo[$val['id']] = [
                        "id" => $val['id'],
                        "visible" => true,
                        "variation" => true,
                        "options" => $opt,
                    ];
                } else {
                    $memo[$val['id']] = [
                        "id" => $val['id'],
                        "visible" => true,
                        "variation" => true,
                        "option" => $val['option'],
                    ];
                }
            }
        }

        if (count($memo) > 0 && count($SecondAttribute) > 0) {
            $is_merge = true;

            foreach ($memo as $key => $val) {
                $options = isset($val['options']) ? $val['options'] : $val['option'];

                if (isset($SecondAttribute[$key])) {

                    $is_merge = false;

                    $second_option = isset($SecondAttribute[$key]['options']) ? $SecondAttribute[$key]['options'] : $SecondAttribute[$key]['option'];

                    if (is_array($second_option) && is_array($options)) {
                        $new_arr = array_merge($second_option, $options);
                        $SecondAttribute[$key]['options'] = array_unique($new_arr);
                    } else if (is_array($second_option) && $options) {

                        array_push($second_option, $options);
                    } else if ($second_option && is_array($options)) {
                        $current = $second_option;

                        unset($SecondAttribute[$key]['option']);
                        $SecondAttribute[$key]['options'][] = $current;

                        $new_arr = array_merge($SecondAttribute[$key]['options'], $options);
                        $SecondAttribute[$key]['options'] = array_unique($new_arr);
                    } else if ($second_option && $options) {
                        $current = $SecondAttribute[$key]['option'];
                        unset($SecondAttribute[$key]['option']);
                        $SecondAttribute[$key]['options'][] = $current;
                        array_push($SecondAttribute[$key]['options'], $options);
                    }
                }
            }

            if ($is_merge) {

                $SecondAttribute = array_merge($SecondAttribute, $memo);
            }
        } else if (count($memo) > 0 && count($SecondAttribute) == 0) {
            $SecondAttribute = $memo;
        } else if (count($memo) == 0 && count($SecondAttribute) > 0) {
            $SecondAttribute = $SecondAttribute;
        }

        return $SecondAttribute;
    }
    /* Find Woo Parent Product By Parent ID */
    private function FindParentProductByID($ParentID)
    {
        return PlatformProduct::where('id', $ParentID)->first();
    }
    /* Sync Products in Woocommerce */
    public function SyncCreateOrUpdateProduct($userId = null, $userIntegrationId = null, $PlatformWorkFlowRuleID = null, $UserWorkFlowRuleID = null, $SorucePlatformName = null, $sync_status = "Ready", $RecordID = null)
    {
        $return_response = false;
        try {

            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                $SourcePlatformId = $this->helper->getPlatformIdByName($SorucePlatformName);
                $SourceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id', 'platform_id']);
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId && isset($SourceUfound->platform_id)) {

                    /* Identify Product Uniqueness */
                    $identity = app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->ProductIdentityMapping($userIntegrationId, $PlatformWorkFlowRuleID);
                    $source_row_data = $identity['source_identity']; //Source Identity
                    $destination_row_data = $identity['destination_identity']; //Destination Identity
                    if ($source_row_data && $destination_row_data) {
                        $process_limit = 25;
                        $q = DB::table('platform_product as s')
                            ->Leftjoin('platform_product_detail_attributes as at', 'at.platform_product_id', '=', 's.id');
                        if ($RecordID && $RecordID !== 0) {
                            $q->where([
                                //['s.user_id', '=', $userId],
                                ['s.user_integration_id', '=', $userIntegrationId],
                                ['s.platform_id', '=', $SourceUfound->platform_id],
                                ['s.id', '=', $RecordID],
                                ['s.is_deleted', '=', 0],
                            ]);
                        } else {

                            $q->where([
                                //['s.user_id', '=', $userId],
                                ['s.user_integration_id', '=', $userIntegrationId],
                                ['s.platform_id', '=', $SourceUfound->platform_id],
                                ['s.product_sync_status', '=', $sync_status],
                                ['s.is_deleted', '=', 0],
                            ]);
                        }
                        $product_arr = $q->select('s.id', 's.api_product_id', 's.product_name', 's.linked_id', 's.sku', 's.weight', 's.category_id', 's.stock_track', 's.description', 'at.shortdescription', 'at.lenght', 'at.height', 'at.width', 'at.volume', 'at.taxcode_ids', 'at.product_type_ids', 's.has_variations', 's.parent_product_id')->orderBy('s.id', 'asc')->limit($process_limit)->get();

                        if (count($product_arr) > 0) {
                            /* --------Get Object Ids------- */
                            $category_Object_ID = $this->helper->getObjectId("category");
                            $attribute_ObjectID = $this->helper->getObjectId("attribute");
                            $object_id = $this->helper->getObjectId('product');
                            $product_price_list_object_id = $this->helper->getObjectId('product_pricelist');
                            /* ------------------- */
                            /* Accept whole sale price acceptance */
                            $MetaData = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowRuleID, "accept_whole_sale_price", ['custom_data'], "default");
                            $AcceptWholeSalePrice = isset($MetaData->custom_data) && strtolower($MetaData->custom_data) == "yes" ? "yes" : "no";
                            /* Default Mapping for product status */
                            $product_status_mapping = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowRuleID, "default_product_status", ['api_id'], "default");
                            $product_status = isset($product_status_mapping->api_id) ? strtolower($product_status_mapping->api_id) : "publish";

                            $mappedProductFields = $this->map->getMappedDataByName($userIntegrationId, null, "get_product_field", ['name'], "regular", null, "multi", "destination");
                            $wooProducts = [];
                            foreach ($product_arr as $product) {
                                $findProduct = null;
                                if (isset($product->linked_id) && $product->linked_id) {
                                    $findProduct = DB::table('platform_product as s')
                                        ->Leftjoin('platform_product_detail_attributes as at', 'at.platform_product_id', '=', 's.id')->where([
                                            ['s.id', '=', $product->linked_id],
                                            ['s.is_deleted', '=', 0],
                                        ])->select('s.id', 's.api_product_id', 's.product_name', 's.linked_id', 's.sku', 's.weight', 's.category_id', 's.stock_track', 's.description', 'at.shortdescription', 'at.lenght', 'at.height', 'at.width', 'at.volume', 'at.taxcode_ids', 'at.product_type_ids', 's.has_variations', 's.parent_product_id')->first();
                                }

                                if ($findProduct) {
                                    $is_create_update_product = true;
                                    /* Get Product Price List with out whole sale price */
                                    $price = $this->FindPriceList($product->id, $userIntegrationId, $PlatformWorkFlowRuleID, $product_price_list_object_id);
                                    /* Check regular price mapping if when we have sales price update */
                                    if (isset($price['has_sales_price']) && $price['has_sales_price']) {
                                        if (isset($price['has_regular_price']) && $price['has_regular_price']) {
                                            $is_create_update_product = true;
                                        } else {
                                            $is_create_update_product = false;
                                        }
                                    }
                                    if ($is_create_update_product) {

                                        $SameProductCount = $this->FindParentProductByID($findProduct->parent_product_id);
                                        /* Find same name products from source platform to set product type variable or simple */
                                        $findDuplicateName = app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->FindDuplicateProductName($product->product_name, $product->api_product_id, $userId, $userIntegrationId, $SourcePlatformId, $this->platformId);
                                        $productType = $findDuplicateName['count'] > 0 ? "variable" : "simple";
                                        $hasBaseProduct = false;
                                        $WooBaseProductID = null;

                                        if ($SameProductCount) {

                                            $hasBaseProduct = true;
                                            $WooBaseProductID = $SameProductCount->api_product_id;

                                            if (isset($wooProducts[$SameProductCount->id])) {
                                                $wooProducts[$SameProductCount->id] = $wooProducts[$SameProductCount->id];
                                            } else {

                                                $getAttributeInJsonForm = $this->GetProductByID($WooBaseProductID, $ufound, $userId, $userIntegrationId);

                                                if (is_array($getAttributeInJsonForm)) {
                                                    $wooProducts[$SameProductCount->id] = $getAttributeInJsonForm;
                                                }
                                            }
                                        } else {

                                            $WooBaseProductID = $findProduct->api_product_id;
                                            if (isset($wooProducts[$findProduct->id])) {
                                                $wooProducts[$findProduct->id] = $wooProducts[$findProduct->id];
                                            } else {

                                                $getAttributeInJsonForm = $this->GetProductByID($WooBaseProductID, $ufound, $userId, $userIntegrationId);

                                                if (is_array($getAttributeInJsonForm)) {
                                                    $wooProducts[$findProduct->id] = $getAttributeInJsonForm;
                                                }
                                            }
                                        }

                                        /* ------------------------- */
                                        $categories = explode(",", $product->category_id);
                                        $categoriesID = [];
                                        if (!empty($categories)) {
                                            /* Create New Category */
                                            $this->FindCategories($categories, $userId, $userIntegrationId, $SourcePlatformId, $category_Object_ID, $ufound);

                                            $categoriesID = $this->FindCategoriesMappingByNames($categories, $userId, $userIntegrationId, $SourcePlatformId, $category_Object_ID);
                                        }

                                        $attribute = $attributeMemo = $variantAttribute = [];

                                        if ($product->has_variations) {

                                            $findProductOptions = DB::table('platform_product_options')
                                                ->where('platform_product_id', $product->id)->where('status', 1)
                                                ->select('option_name', 'option_value')->get();

                                            if (count($findProductOptions) > 0) {

                                                foreach ($findProductOptions as $value) {
                                                    if (isset($value->option_name)) {
                                                        $findAttrAPI_ID = $this->FindAttributeMappingByNames($value->option_name, $userId, $userIntegrationId, $attribute_ObjectID);

                                                        if ($findAttrAPI_ID) {

                                                            if (isset($attributeMemo[$findAttrAPI_ID])) {
                                                                $current = $attributeMemo[$findAttrAPI_ID]['option'];
                                                                unset($attributeMemo[$findAttrAPI_ID]['option']);

                                                                array_push($attributeMemo[$findAttrAPI_ID]['options'], $current);
                                                                $attributeMemo[$findAttrAPI_ID]['options'][] = $value->option_value;
                                                            } else {
                                                                $attributeMemo[$findAttrAPI_ID]['id'] = $findAttrAPI_ID;
                                                                $attributeMemo[$findAttrAPI_ID]['visible'] = true;
                                                                $attributeMemo[$findAttrAPI_ID]['variation'] = true;

                                                                $attributeMemo[$findAttrAPI_ID]['option'] = $value->option_value;
                                                            }
                                                        } else {
                                                            $attribute[] = ["name" => $value->option_name];
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        if (isset($attribute)) {
                                            //create new attributes
                                            $payload['create'] = $attribute;
                                            $this->CreateUpdateDeleteAttributes(null, $userId, $userIntegrationId, $attribute_ObjectID, $ufound, $payload);
                                        }

                                        if ($hasBaseProduct) {

                                            if (count($attributeMemo) > 0) {
                                                $variantAttribute = $attributeMemo;
                                                foreach ($attributeMemo as $key => $att) {
                                                    $current_val = isset($att['option']) ? $att['option'] : null;
                                                    unset($attributeMemo[$key]['option']);
                                                    $attributeMemo[$key]['options'][] = $current_val;
                                                }
                                            }

                                            $FinalAttributeForParentProduct = $this->MergeAttributes($wooProducts[$SameProductCount->id], $attributeMemo);
                                        } else {

                                            $FinalAttributeForParentProduct = $attributeMemo;
                                        }
                                        // dd($FinalAttributeForParentProduct,$WooBaseProductID ,$attributeMemo);

                                        if ($findProduct->parent_product_id != 0 && !is_null($findProduct->parent_product_id)) {

                                            $baseAtt = ['sku' => null, 'attributes' => $FinalAttributeForParentProduct];

                                            $responseBase = $this->CreateOrUpdateOrDeleteProduct($userIntegrationId, "products/{$WooBaseProductID}", $baseAtt, "update", $ufound);
                                            if (!isset($responseBase['code'])) {
                                                $update = [
                                                    'id' => $findProduct->api_product_id,
                                                    'sku' => $product->sku,
                                                    //'status' => $product_status,
                                                    'sale_price' => $price['regular_price'] > $price['sale_price'] ? $price['sale_price'] : null,
                                                    'regular_price' => $price['regular_price'],
                                                    'manage_stock' => $product->stock_track,
                                                    'description' => $product->description,
                                                    'weight' => isset($product->weight) ? (string) $product->weight : null,
                                                    'dimensions' => [
                                                        'length' => isset($product->lenght) ? (string) $product->lenght : null,
                                                        'width' => isset($product->width) ? (string) $product->width : null,
                                                        'height' => isset($product->height) ? (string) $product->height : null,
                                                    ],
                                                    'attributes' => $variantAttribute,
                                                ];

                                                if (!empty($mappedProductFields)) {

                                                    if (in_array('desciption', $mappedProductFields)) {
                                                        unset($update['description']);
                                                    }
                                                }
                                                if ($AcceptWholeSalePrice == "yes") {

                                                    $update['meta_data'][] = [
                                                        'key' => "wholesale_customer_wholesale_price",
                                                        'value' => $price['whole_sale_price'],
                                                    ];
                                                }

                                                // \Log::channel('webhook')->info("awa - Integration " . $userIntegrationId . " Response: " . print_r($update, true) . " Created Date : " . date('Y-m-d H:i:s'));
                                                $response = $this->CreateOrUpdateOrDeleteVariationProduct($userIntegrationId, "products/{$WooBaseProductID}/variations/{$findProduct->api_product_id}", $update, "update", $ufound);
                                                if (!isset($response['code'])) {

                                                    $ProductPrimaryID = $this->CreateOrUpdateProductAfterResponse($userId, $userIntegrationId, $this->platformId, $response, $product->id);

                                                    if ($ProductPrimaryID) {
                                                        /* Update BP product status */
                                                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $ProductPrimaryID], ['id' => $product->id]);
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $product->id, null);
                                                    }
                                                } else {

                                                    if (isset($response['message'])) {
                                                        if ($response['message'] == "Invalid ID.") {
                                                            $error =  "Product does not exist";
                                                        } else {
                                                            $error =  $response['message'];
                                                        }
                                                    } else {
                                                        $error =  "Internal Error";
                                                    }
                                                    $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $product->id]);
                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $product->id, $error);
                                                }
                                            }
                                        } else {

                                            if ($productType == "simple") {
                                                if (count($attributeMemo) > 0) {
                                                    // $arr_key = array_keys($attributeMemo);
                                                    // $current_val = isset($attributeMemo[$arr_key[0]]['option']) ? $attributeMemo[$arr_key[0]]['option'] : NULL;
                                                    // unset($attributeMemo[$arr_key[0]]['option']);
                                                    // $attributeMemo[$arr_key[0]]['options'][] = $current_val;

                                                    foreach ($attributeMemo as $key => $att) {
                                                        $current_val = isset($att['option']) ? $att['option'] : null;
                                                        unset($attributeMemo[$key]['option']);
                                                        $attributeMemo[$key]['options'][] = $current_val;
                                                    }
                                                }
                                                $FinalAttributeForParentProduct = $this->MergeAttributes($wooProducts[$findProduct->id], $attributeMemo);
                                            } else {

                                                $FinalAttributeForParentProduct = $attributeMemo;
                                            }
                                            $create_variant = [];
                                            $update = $create_variant = [
                                                //'status' => $product_status,
                                                'sale_price' => $price['regular_price'] > $price['sale_price'] ? $price['sale_price'] : null,
                                                'regular_price' => $price['regular_price'],
                                                'manage_stock' => $product->stock_track,
                                                'description' => $product->description,
                                                'weight' => isset($product->weight) ? (string) $product->weight : null,
                                                'dimensions' => [
                                                    'length' => isset($product->lenght) ? (string) $product->lenght : null,
                                                    'width' => isset($product->width) ? (string) $product->width : null,
                                                    'height' => isset($product->height) ? (string) $product->height : null,
                                                ],

                                            ];
                                            // dd($findProduct,$create_variant,$update);

                                            if ($productType == "simple") {
                                                $update['id'] = $findProduct->api_product_id;
                                                $update['name'] = $product->product_name;
                                                $update['sku'] = $product->sku;
                                                $update['type'] = $productType;
                                                $update['short_description'] = $product->shortdescription;
                                                $update['categories'] = $categoriesID;
                                                $update['attributes'] = $FinalAttributeForParentProduct;
                                            } else {
                                                $update['id'] = $findProduct->api_product_id;
                                                $update['name'] = $product->product_name;
                                                $update['sku'] = null;
                                                $update['type'] = $productType;
                                                $update['short_description'] = $product->shortdescription;
                                                $update['categories'] = $categoriesID;
                                                $update['attributes'] = $FinalAttributeForParentProduct;
                                                $create_variant['sku'] = $product->sku;
                                                $create_variant['attributes'] = $attributeMemo;
                                            }

                                            if ($AcceptWholeSalePrice == "yes") {

                                                $update['meta_data'][] = $create_variant['meta_data'] = [
                                                    'key' => "wholesale_customer_wholesale_price",
                                                    'value' => $price['whole_sale_price'],
                                                ];
                                            }

                                            /* If description and short description mapped | unset from array */
                                            if (!empty($mappedProductFields)) {

                                                if (in_array('desciption', $mappedProductFields)) {
                                                    unset($update['description']);
                                                    unset($create_variant['description']);
                                                }
                                                if (in_array('short_description', $mappedProductFields)) {
                                                    unset($update['short_description']);
                                                }
                                            }

                                            // \Log::channel('webhook')->info("Update2_WOO -" . json_encode($update) . "Created Date : " . date('Y-m-d H:i:s'));

                                            $response = $this->CreateOrUpdateOrDeleteProduct($userIntegrationId, "products/{$findProduct->api_product_id}", $update, "update", $ufound);

                                            if (!isset($response['code'])) {
                                                if (isset($response['type']) && $response['type'] == "variable") {
                                                    /* Base Product Response */
                                                    $response['parent_product_id'] = null;
                                                    $response['linked_id'] = 0;
                                                    $BaseProductPrimaryID = $this->CreateOrUpdateProductAfterResponse($userId, $userIntegrationId, $this->platformId, $response, $product->id);
                                                    if ($BaseProductPrimaryID) {

                                                        /* ---Create Base Product Variant---*/
                                                        $WooBaseProductID = $response['id'];
                                                        $create_variation_response = $this->CreateOrUpdateOrDeleteVariationProduct($userIntegrationId, "products/{$WooBaseProductID}/variations", $create_variant, "create", $ufound);
                                                        if (!isset($create_variation_response['code'])) {
                                                            $create_variation_response['parent_product_id'] = $BaseProductPrimaryID;
                                                            $ProductPrimaryID = $this->CreateOrUpdateProductAfterResponse($userId, $userIntegrationId, $this->platformId, $create_variation_response, $product->id);

                                                            if ($ProductPrimaryID) {
                                                                /* Update BP product status */
                                                                $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $ProductPrimaryID], ['id' => $product->id]);
                                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $product->id, null);
                                                            }
                                                        } else {
                                                            $error = isset($response['message']) ? $response['message'] : "Internal Error";
                                                            $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $product->id]);
                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $product->id, $error);
                                                        }
                                                        /* --End Create Base Product Variant--- */
                                                    }
                                                    /* ---------------- */
                                                } else {
                                                    $ProductPrimaryID = $this->CreateOrUpdateProductAfterResponse($userId, $userIntegrationId, $this->platformId, $response, $product->id);
                                                    //   \Log::channel('webhook')->info("Update2_WOO -" . $ProductPrimaryID ."=".$product->id. "Created Date : " . date('Y-m-d H:i:s'));
                                                    if ($ProductPrimaryID) {
                                                        /* Update BP product status */
                                                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $ProductPrimaryID], ['id' => $product->id]);
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $product->id, null);
                                                    }
                                                }
                                            } else {
                                                if (isset($response['message'])) {
                                                    if ($response['message'] == "Invalid ID.") {
                                                        $error =  "Product does not exist";
                                                    } else {
                                                        $error =  $response['message'];
                                                    }
                                                } else {
                                                    $error =  "Internal Error";
                                                }
                                                $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $product->id]);
                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $product->id, $error);
                                            }
                                        }
                                    } else {
                                        $error = "Need to map regular price to update sales price";
                                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $product->id]);
                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $product->id, $error);
                                    }
                                } else {

                                    $prod = (array) $product;
                                    $findProduct = DB::table('platform_product as s')
                                        ->Leftjoin('platform_product_detail_attributes as at', 'at.platform_product_id', '=', 's.id')
                                        ->where([
                                            //['s.user_id', '=', $userId],
                                            ['s.user_integration_id', '=', $userIntegrationId],
                                            ['s.platform_id', '=', $this->platformId],
                                            ['s.is_deleted', '=', 0],
                                            ['s.' . $destination_row_data, '=', $prod[$source_row_data]],

                                        ])
                                        ->select('s.id', 's.api_product_id', 's.product_name', 's.linked_id', 's.sku', 's.weight', 's.category_id', 's.stock_track', 's.description', 'at.shortdescription', 'at.lenght', 'at.height', 'at.width', 'at.volume', 'at.taxcode_ids', 'at.product_type_ids', 's.has_variations', 's.parent_product_id')->first();
                                    $is_create_update_product = true;
                                    $price = $this->FindPriceList($product->id, $userIntegrationId, $PlatformWorkFlowRuleID, $product_price_list_object_id);
                                    /* Check regular price mapping if when we have sales price update */
                                    if (isset($price['has_sales_price']) && $price['has_sales_price']) {
                                        if (isset($price['has_regular_price']) && $price['has_regular_price']) {
                                            $is_create_update_product = true;
                                        } else {
                                            $is_create_update_product = false;
                                        }
                                    }
                                    if ($is_create_update_product) {

                                        if ($findProduct) {

                                            $SameProductCount = $this->FindParentProductByID($findProduct->parent_product_id);

                                            /* Find same name products from source platform to set product type variable or simple */
                                            // $SameProductCount = app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->FindDuplicateProductName($product->product_name, $product->api_product_id, $userId, $userIntegrationId, $SourcePlatformId, $this->platformId, $source_row_data, $destination_row_data);
                                            $hasBaseProduct = false;
                                            $GetWooProduct = isset($SameProductCount->api_product_id) ? $SameProductCount->api_product_id : null;
                                            $WooBaseProductID = null;

                                            if ($SameProductCount) {
                                                $hasBaseProduct = true;
                                                $WooBaseProductID = $SameProductCount->api_product_id;
                                                if (isset($wooProducts[$SameProductCount->id])) {
                                                    $wooProducts[$SameProductCount->id] = $wooProducts[$SameProductCount->id];
                                                } else {

                                                    $getAttributeInJsonForm = $this->GetProductByID($WooBaseProductID, $ufound, $userId, $userIntegrationId);

                                                    if (is_array($getAttributeInJsonForm)) {
                                                        $wooProducts[$SameProductCount->id] = $getAttributeInJsonForm;
                                                    }
                                                }
                                            } else {
                                                $WooBaseProductID = $findProduct->api_product_id;
                                                if (isset($wooProducts[$findProduct->id])) {
                                                    $wooProducts[$findProduct->id] = $wooProducts[$findProduct->id];
                                                } else {

                                                    $getAttributeInJsonForm = $this->GetProductByID($WooBaseProductID, $ufound, $userId, $userIntegrationId);

                                                    if (is_array($getAttributeInJsonForm)) {
                                                        $wooProducts[$findProduct->id] = $getAttributeInJsonForm;
                                                    }
                                                }
                                            }

                                            /* ------------------------- */
                                            $categories = explode(",", $product->category_id);
                                            $categoriesID = [];
                                            if (!empty($categories)) {
                                                /* Create New Category */
                                                $this->FindCategories($categories, $userId, $userIntegrationId, $SourcePlatformId, $category_Object_ID, $ufound);
                                                $categoriesID = $this->FindCategoriesMappingByNames($categories, $userId, $userIntegrationId, $SourcePlatformId, $category_Object_ID);
                                            }
                                            $attribute = $attributeMemo = $variantAttribute = [];

                                            if ($product->has_variations) {
                                                $findProductOptions = DB::table('platform_product_options')
                                                    ->where('platform_product_id', $product->id)->where('status', 1)
                                                    ->select('option_name', 'option_value')->get();

                                                if (count($findProductOptions) > 0) {

                                                    foreach ($findProductOptions as $value) {
                                                        if (isset($value->option_name)) {

                                                            $findAttrAPI_ID = $this->FindAttributeMappingByNames($value->option_name, $userId, $userIntegrationId, $attribute_ObjectID);

                                                            if ($findAttrAPI_ID) {

                                                                if (isset($attributeMemo[$findAttrAPI_ID])) {

                                                                    $current = $attributeMemo[$findAttrAPI_ID]['option'];
                                                                    unset($attributeMemo[$findAttrAPI_ID]['option']);

                                                                    array_push($attributeMemo[$findAttrAPI_ID]['options'], $current);
                                                                    $attributeMemo[$findAttrAPI_ID]['options'][] = $value->option_value;
                                                                } else {
                                                                    $attributeMemo[$findAttrAPI_ID]['id'] = $findAttrAPI_ID;
                                                                    $attributeMemo[$findAttrAPI_ID]['visible'] = true;
                                                                    $attributeMemo[$findAttrAPI_ID]['variation'] = true;

                                                                    $attributeMemo[$findAttrAPI_ID]['option'] = $value->option_value;
                                                                }
                                                            } else {
                                                                $attribute[] = ["name" => $value->option_name];
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                            if (isset($attribute)) {
                                                //create new attributes
                                                $payload['create'] = $attribute;
                                                $this->CreateUpdateDeleteAttributes(null, $userId, $userIntegrationId, $attribute_ObjectID, $ufound, $payload);
                                            }
                                            if ($hasBaseProduct) {
                                                $FinalAttributeForParentProduct = $this->MergeAttributes($wooProducts[$SameProductCount->id], $attributeMemo);
                                            } else {
                                                $FinalAttributeForParentProduct = $attributeMemo;
                                            }

                                            if ($findProduct->parent_product_id != 0 && !is_null($findProduct->parent_product_id)) {
                                                $baseAtt = ['sku' => null, 'attributes' => $FinalAttributeForParentProduct];

                                                $responseBase = $this->CreateOrUpdateOrDeleteProduct($userIntegrationId, "products/{$WooBaseProductID}", $baseAtt, "update", $ufound);
                                                if (!isset($responseBase['code'])) {
                                                    $update = [
                                                        'id' => $findProduct->api_product_id,
                                                        //'status' => $product_status,
                                                        'sku' => $product->sku,
                                                        'sale_price' => $price['regular_price'] > $price['sale_price'] ? $price['sale_price'] : null,
                                                        'regular_price' => $price['regular_price'],
                                                        'manage_stock' => $product->stock_track,
                                                        'description' => $product->description,
                                                        'weight' => isset($product->weight) ? (string) $product->weight : null,
                                                        'dimensions' => [
                                                            'length' => isset($product->lenght) ? (string) $product->lenght : null,
                                                            'width' => isset($product->width) ? (string) $product->width : null,
                                                            'height' => isset($product->height) ? (string) $product->height : null,
                                                        ],
                                                        'attributes' => $attributeMemo,
                                                    ];
                                                    /* If description and short description mapped | unset from array */
                                                    if (!empty($mappedProductFields)) {

                                                        if (in_array('desciption', $mappedProductFields)) {
                                                            unset($update['description']);
                                                        }
                                                    }
                                                    if ($AcceptWholeSalePrice == "yes") {

                                                        $update['meta_data'][] = [
                                                            'key' => "wholesale_customer_wholesale_price",
                                                            'value' => $price['whole_sale_price'],
                                                        ];
                                                    }
                                                    $response = $this->CreateOrUpdateOrDeleteVariationProduct($userIntegrationId, "products/{$WooBaseProductID}/variations/{$findProduct->api_product_id}", $update, "update", $ufound);
                                                    if (!isset($response['code'])) {

                                                        $ProductPrimaryID = $this->CreateOrUpdateProductAfterResponse($userId, $userIntegrationId, $this->platformId, $response, $product->id);

                                                        if ($ProductPrimaryID) {
                                                            /* Update BP product status */
                                                            $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $ProductPrimaryID], ['id' => $product->id]);
                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $product->id, null);
                                                        }
                                                    } else {
                                                        $error = isset($response['message']) ? $response['message'] : "Internal Error";
                                                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $product->id]);
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $product->id, $error);
                                                    }
                                                }
                                            } else {
                                                if (count($attributeMemo) > 0) {
                                                    // $arr_key = array_keys($attributeMemo);
                                                    // $current_val = isset($attributeMemo[$arr_key[0]]['option']) ? $attributeMemo[$arr_key[0]]['option'] : NULL;
                                                    // unset($attributeMemo[$arr_key[0]]['option']);
                                                    // $attributeMemo[$arr_key[0]]['options'][] = $current_val;
                                                    foreach ($attributeMemo as $key => $att) {
                                                        $current_val = isset($att['option']) ? $att['option'] : null;
                                                        unset($attributeMemo[$key]['option']);
                                                        $attributeMemo[$key]['options'][] = $current_val;
                                                    }
                                                }
                                                $FinalAttributeForParentProduct = $this->MergeAttributes($wooProducts[$findProduct->id], $attributeMemo);
                                                $update = [
                                                    'id' => $findProduct->api_product_id,
                                                    'name' => $product->product_name,
                                                    'sku' => $product->sku,
                                                    //'status' => $product_status,
                                                    //'type' => $productType,
                                                    'sale_price' => $price['regular_price'] > $price['sale_price'] ? $price['sale_price'] : null,
                                                    'regular_price' => $price['regular_price'],
                                                    'description' => $product->description,
                                                    'short_description' => $product->shortdescription,
                                                    'manage_stock' => $product->stock_track,
                                                    'weight' => isset($product->weight) ? (string) $product->weight : null,
                                                    'dimensions' => ['length' => isset($product->lenght) ? (string) $product->lenght : null, 'width' => isset($product->width) ? (string) $product->width : null, 'height' => isset($product->height) ? (string) $product->height : null],
                                                    'categories' => $categoriesID,
                                                    'attributes' => $FinalAttributeForParentProduct,
                                                ];
                                                /* If description and short description mapped | unset from array */
                                                if (!empty($mappedProductFields)) {

                                                    if (in_array('desciption', $mappedProductFields)) {
                                                        unset($update['description']);
                                                    }
                                                    if (in_array('short_description', $mappedProductFields)) {
                                                        unset($update['short_description']);
                                                    }
                                                }
                                                if ($AcceptWholeSalePrice == "yes") {

                                                    $update['meta_data'][] = [
                                                        'key' => "wholesale_customer_wholesale_price",
                                                        'value' => $price['whole_sale_price'],
                                                    ];
                                                }

                                                // \Log::channel('webhook')->info("Update1_WOO -" . json_encode($update) . "Created Date : " . date('Y-m-d H:i:s'));
                                                $response = $this->CreateOrUpdateOrDeleteProduct($userIntegrationId, "products/{$findProduct->api_product_id}", $update, "update", $ufound);

                                                if (!isset($response['code'])) {
                                                    $ProductPrimaryID = $this->CreateOrUpdateProductAfterResponse($userId, $userIntegrationId, $this->platformId, $response, $product->id);
                                                    if ($ProductPrimaryID) {
                                                        /* Update BP product status */
                                                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $ProductPrimaryID], ['id' => $product->id]);
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $product->id, null);
                                                    }
                                                } else {

                                                    $error = isset($response['message']) ? $response['message'] : "Internal Error";
                                                    $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $product->id]);
                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $product->id, $error);
                                                }
                                            }
                                        } else {

                                            if (!empty($product->sku)) {
                                                $exist = app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->StoreProduct($product->sku, $ufound, $userId, $userIntegrationId); //Before Create find product by sku

                                                if (!$exist) {

                                                    /* Find same name products from source platform to set product type variable or simple */
                                                    $SameProductCount = app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->FindDuplicateProductName($product->product_name, $product->api_product_id, $userId, $userIntegrationId, $SourcePlatformId, $this->platformId);
                                                    $productType = $SameProductCount['count'] > 0 ? "variable" : "simple";

                                                    $hasBaseProduct = false;
                                                    $GetWooProduct = isset($SameProductCount['woo_product']->id) ? $SameProductCount['woo_product'] : null;
                                                    $WooBaseProductID = null;
                                                    if ($GetWooProduct) {

                                                        $hasBaseProduct = true;
                                                        $WooBaseProductID = $GetWooProduct->api_product_id;

                                                        if (isset($wooProducts[$GetWooProduct->id])) {
                                                            $wooProducts[$GetWooProduct->id] = $wooProducts[$GetWooProduct->id];
                                                        } else {
                                                            $getAttributeInJsonForm = $this->GetProductByID($WooBaseProductID, $ufound, $userId, $userIntegrationId);
                                                            if (is_array($getAttributeInJsonForm)) {
                                                                $wooProducts[$GetWooProduct->id] = $getAttributeInJsonForm;
                                                            }
                                                        }
                                                    }

                                                    $categories = explode(",", $product->category_id);
                                                    $categoriesID = [];
                                                    if (!empty($categories)) {
                                                        /* Create New Category */
                                                        $this->FindCategories($categories, $userId, $userIntegrationId, $SourcePlatformId, $category_Object_ID, $ufound);
                                                        $categoriesID = $this->FindCategoriesMappingByNames($categories, $userId, $userIntegrationId, $SourcePlatformId, $category_Object_ID);
                                                    }
                                                    $attribute = $attributeMemo = $variantAttribute = [];

                                                    if ($product->has_variations) {
                                                        $findProductOptions = DB::table('platform_product_options')
                                                            ->where('platform_product_id', $product->id)->where('status', 1)
                                                            ->select('option_name', 'option_value')->get();

                                                        if (count($findProductOptions) > 0) {

                                                            foreach ($findProductOptions as $value) {
                                                                if (isset($value->option_name)) {

                                                                    $findAttrAPI_ID = $this->FindAttributeMappingByNames($value->option_name, $userId, $userIntegrationId, $attribute_ObjectID);

                                                                    if ($findAttrAPI_ID) {

                                                                        if (isset($attributeMemo[$findAttrAPI_ID])) {

                                                                            $current = $attributeMemo[$findAttrAPI_ID]['option'];
                                                                            unset($attributeMemo[$findAttrAPI_ID]['option']);

                                                                            array_push($attributeMemo[$findAttrAPI_ID]['options'], $current);
                                                                            $attributeMemo[$findAttrAPI_ID]['options'][] = $value->option_value;
                                                                        } else {
                                                                            $attributeMemo[$findAttrAPI_ID]['id'] = $findAttrAPI_ID;
                                                                            $attributeMemo[$findAttrAPI_ID]['visible'] = true;
                                                                            $attributeMemo[$findAttrAPI_ID]['variation'] = true;

                                                                            $attributeMemo[$findAttrAPI_ID]['option'] = $value->option_value;
                                                                        }
                                                                    } else {
                                                                        $attribute[] = ["name" => $value->option_name];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }

                                                    if (isset($attribute)) {
                                                        //create new attributes
                                                        $payload['create'] = $attribute;
                                                        $this->CreateUpdateDeleteAttributes(null, $userId, $userIntegrationId, $attribute_ObjectID, $ufound, $payload);
                                                    }
                                                    if ($hasBaseProduct) {

                                                        if (count($attributeMemo) > 0) {
                                                            $variantAttribute = $attributeMemo;
                                                            foreach ($attributeMemo as $key => $att) {
                                                                $current_val = isset($att['option']) ? $att['option'] : null;
                                                                unset($attributeMemo[$key]['option']);
                                                                $attributeMemo[$key]['options'][] = $current_val;
                                                            }
                                                        }

                                                        $FinalAttributeForParentProduct = $this->MergeAttributes($wooProducts[$GetWooProduct->id], $attributeMemo);
                                                    } else {
                                                        if (count($attributeMemo) > 0) {
                                                            $variantAttribute = $attributeMemo;
                                                            foreach ($attributeMemo as $key => $att) {
                                                                $current_val = isset($att['option']) ? $att['option'] : null;
                                                                unset($attributeMemo[$key]['option']);
                                                                $attributeMemo[$key]['options'][] = $current_val;
                                                            }
                                                        }

                                                        $FinalAttributeForParentProduct = $attributeMemo;
                                                    }
                                                    // if ($hasBaseProduct) {

                                                    //     $FinalAttributeForParentProduct = $this->MergeAttributes($wooProducts[$GetWooProduct->id], $attributeMemo);

                                                    // } else {
                                                    //     if (count($attributeMemo) > 0) {
                                                    //         foreach ($attributeMemo as $key => $att) {
                                                    //             $attributeMemo[$key]['options'][] = $attributeMemo[$key]['option'];
                                                    //             unset($attributeMemo[$key]['option']);
                                                    //         }
                                                    //     }
                                                    //     $FinalAttributeForParentProduct = $attributeMemo;
                                                    // }

                                                    if ($hasBaseProduct) {
                                                        $baseAtt = ['sku' => null, 'type' => $productType, 'attributes' => $FinalAttributeForParentProduct];

                                                        $responseBase = $this->CreateOrUpdateOrDeleteProduct($userIntegrationId, "products/{$WooBaseProductID}", $baseAtt, "update", $ufound);
                                                        if (!isset($responseBase['code'])) {
                                                            $create = [
                                                                'sku' => $product->sku,
                                                                // 'status' => $product_status,
                                                                'sale_price' => $price['regular_price'] > $price['sale_price'] ? $price['sale_price'] : null,
                                                                'regular_price' => $price['regular_price'],
                                                                'manage_stock' => $product->stock_track,
                                                                'description' => $product->description,
                                                                'weight' => isset($product->weight) ? (string) $product->weight : null,
                                                                'dimensions' => [
                                                                    'length' => isset($product->lenght) ? (string) $product->lenght : null,
                                                                    'width' => isset($product->width) ? (string) $product->width : null,
                                                                    'height' => isset($product->height) ? (string) $product->height : null,
                                                                ],
                                                                'attributes' => $variantAttribute,
                                                            ];
                                                            if ($AcceptWholeSalePrice == "yes") {

                                                                $create['meta_data'][] = [
                                                                    'key' => "wholesale_customer_wholesale_price",
                                                                    'value' => $price['whole_sale_price'],
                                                                ];
                                                            }

                                                            $response = $this->CreateOrUpdateOrDeleteVariationProduct($userIntegrationId, "products/{$WooBaseProductID}/variations", $create, "create", $ufound);

                                                            if (!isset($response['code'])) {
                                                                $ProductPrimaryID = $this->CreateOrUpdateProductAfterResponse($userId, $userIntegrationId, $this->platformId, $response, $product->id);

                                                                if ($ProductPrimaryID) {
                                                                    /* Update BP product status */
                                                                    $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $ProductPrimaryID], ['id' => $product->id]);
                                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $product->id, null);
                                                                }
                                                            } else {
                                                                $error = isset($response['message']) ? $response['message'] : "Internal Error";
                                                                $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $product->id]);
                                                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $product->id, $error);
                                                            }
                                                        }
                                                    } else {

                                                        $create_new_variant = $is_simple_product = false;
                                                        if ($productType == "variable") {
                                                            $create_new_variant = true;
                                                        } else if ($productType == "simple") {
                                                            $is_simple_product = true;
                                                        }
                                                        $create = [
                                                            'status' => $product_status,
                                                            'sale_price' => $price['regular_price'] > $price['sale_price'] ? $price['sale_price'] : null,
                                                            'regular_price' => $price['regular_price'],
                                                            'description' => $product->description,
                                                            'manage_stock' => $product->stock_track,
                                                            'weight' => isset($product->weight) ? (string) $product->weight : null,
                                                            'dimensions' => ['length' => isset($product->lenght) ? (string) $product->lenght : null, 'width' => isset($product->width) ? (string) $product->width : null, 'height' => isset($product->height) ? (string) $product->height : null],
                                                            'categories' => $categoriesID,
                                                        ];
                                                        if ($create_new_variant && !$is_simple_product) {
                                                            $create['sku'] = null;
                                                            $create['name'] = $product->product_name;
                                                            $create['type'] = $productType;
                                                            $create['short_description'] = $product->shortdescription;
                                                            $create['attributes'] = $FinalAttributeForParentProduct;
                                                        } else if (!$create_new_variant && $is_simple_product) {
                                                            $create['sku'] = $product->sku;
                                                            $create['name'] = $product->product_name;
                                                            $create['type'] = $productType;
                                                            $create['short_description'] = $product->shortdescription;
                                                            $create['attributes'] = $FinalAttributeForParentProduct;
                                                        }
                                                        if ($AcceptWholeSalePrice == "yes") {

                                                            $create['meta_data'][] = [
                                                                'key' => "wholesale_customer_wholesale_price",
                                                                'value' => $price['whole_sale_price'],
                                                            ];
                                                        }

                                                        $response = $this->CreateOrUpdateOrDeleteProduct($userIntegrationId, "products", $create, "create", $ufound);

                                                        if (!isset($response['code'])) {

                                                            if (isset($response['type']) && $response['type'] == "variable") {
                                                                /* Base Product Response */
                                                                $BaseProductPrimaryID = $this->CreateOrUpdateProductAfterResponse($userId, $userIntegrationId, $this->platformId, $response, $product->id);
                                                                if ($BaseProductPrimaryID) {

                                                                    /* ---Create Base Product Variant---*/
                                                                    $WooBaseProductID = $response['id'];
                                                                    $create['sku'] = $product->sku;
                                                                    $create['attributes'] = $variantAttribute;
                                                                    unset($create['status']); //no need status for variant of base product
                                                                    $create_variation_response = $this->CreateOrUpdateOrDeleteVariationProduct($userIntegrationId, "products/{$WooBaseProductID}/variations", $create, "create", $ufound);
                                                                    if (!isset($create_variation_response['code'])) {
                                                                        $create_variation_response['parent_product_id'] = $BaseProductPrimaryID;
                                                                        $ProductPrimaryID = $this->CreateOrUpdateProductAfterResponse($userId, $userIntegrationId, $this->platformId, $create_variation_response, $product->id);

                                                                        if ($ProductPrimaryID) {
                                                                            /* Update BP product status */
                                                                            $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $ProductPrimaryID], ['id' => $product->id]);
                                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $product->id, null);
                                                                        }
                                                                    } else {
                                                                        $error = isset($response['message']) ? $response['message'] : "Internal Error";
                                                                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $product->id]);
                                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $product->id, $error);
                                                                    }
                                                                    /* --End Create Base Product Variant--- */
                                                                }
                                                                /* ---------------- */
                                                            } else {
                                                                $ProductPrimaryID = $this->CreateOrUpdateProductAfterResponse($userId, $userIntegrationId, $this->platformId, $response, $product->id);

                                                                if ($ProductPrimaryID) {
                                                                    /* Update BP product status */
                                                                    $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Synced", 'linked_id' => $ProductPrimaryID], ['id' => $product->id]);
                                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $product->id, null);
                                                                }
                                                            }
                                                        } else {
                                                            $error = isset($response['message']) ? $response['message'] : "Internal Error";
                                                            $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $product->id]);
                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $product->id, $error);
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    } else {
                                        $error = "Need to map regular price to update sales price";
                                        $this->mobj->makeUpdate('platform_product', ['product_sync_status' => "Failed"], ['id' => $product->id]);
                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $product->id, $error);
                                    }
                                }
                            }
                        }
                    }

                    $return_response = true;
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Customer By ID */
    public function GetCustomerById($CustomerID = null, $userId = null, $userIntegrationId = null)
    {
        $return_response = false;
        try {
            $ufound = $this->getPrimaryAccount($userIntegrationId);
            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                    if ($CustomerID !== 0 && isset($CustomerID)) {
                        $url = "customers/{$CustomerID}";
                        $response = $this->wc->GetCustomerById($ufound, $url);
                        if ($value = json_decode($response->getBody(), true)) {
                            if (!isset($value['error'])) {
                                $customersList = array(
                                    'user_id' => $userId,
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' => $ufound->platform_id,
                                    'api_customer_id' => $value['id'],
                                    'first_name' => $value['first_name'],
                                    'last_name' => $value['last_name'],
                                    'email' => $value['email'],
                                    'customer_name' => $value['first_name'] . " " . $value['last_name'],
                                );
                                $find = $this->mobj->getFirstResultByConditions('platform_customer', [
                                    'user_integration_id' => $userIntegrationId,
                                    'platform_id' => $ufound->platform_id,
                                    'api_customer_id' => $value['id'],
                                ], ['id']);
                                if ($find) {

                                    $this->mobj->makeUpdate(
                                        'platform_customer',
                                        $customersList,
                                        ['id' => $find->id]
                                    );
                                    $return_response = $find->id;
                                } else {
                                    $return_response = $this->mobj->makeInsertGetId('platform_customer', $customersList);
                                }
                            }
                        } else {
                            $return_response = $response;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            //\Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Refund Code */
    public function GetOrderRefundDetail($OrderID = null, $RefundID = null, $userIntegrationId = null, $ufound = null)
    {
        try {
            if ($ufound) {

                $url = "orders/{$OrderID}/refunds/{$RefundID}";
                $response = $this->wc->GetOrderRefundDetail($ufound, $url, $OrderID);

                if (json_decode($response->getBody(), true)) {

                    return json_decode($response->getBody(), true);
                }
                return false;
            } else {
                $ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['app_id', 'app_secret', 'platform_id', 'id', 'user_id', 'api_domain']);
                if ($ufound && $this->platformId) {
                    if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                        $url = "orders/{$OrderID}/refunds";
                        $response = $this->wc->GetOrderRefundDetail($ufound, $url, $OrderID);
                        if (json_decode($response->getBody(), true)) {
                            return json_decode($response->getBody(), true);
                        }
                        return false;
                    }
                }
            }
        } catch (\Exception $e) {
            //\Log::error($e->getMessage());
            return false;
        }
    }
    /* Get Ready Refund Order Details */
    public function GetReadyRefundOrder($userID = null, $userIntegrationId = null, $sync_status = "Pending")
    {
        $return_response = false;
        try {
            $EventID = "GET_REFUND";

            $selectFields = ['e.event_id', 'ur.status'];
            $user_work_flow = $this->map->getUserIntegWorkFlow($userIntegrationId, $EventID, $selectFields, self::$myPlatform);

            if (isset($user_work_flow[$EventID])) {

                if ($user_work_flow[$EventID]['status'] == 1) { //If refund flow is ON
                    $ufound = $this->getPrimaryAccount($userIntegrationId);

                    if ($ufound && $this->platformId) {
                        if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
                            $process_limit = 25;
                            $list = DB::table('platform_order_refunds as s')->select('s.api_id', 's.platform_order_id', 'o.api_order_id', 's.id')->join('platform_order as o', 's.platform_order_id', '=', 'o.id')->where('s.sync_status', $sync_status)->orderBy('s.id', 'asc')->take($process_limit)->get();
                            if (!empty($list) && count($list) > 0) {
                                foreach ($list as $okey => $order) {

                                    $response = $this->GetOrderRefundDetail($order->api_order_id, $order->api_id, $userIntegrationId, $ufound);
                                    if ($response && isset($response) && !empty($response)) {
                                        if (isset($response['date_created'])) {
                                            $this->mobj->makeUpdate('platform_order_refunds', ['date_created' => $response['date_created'], 'sync_status' => 'Ready'], ['api_id' => $response['id'], 'platform_order_id' => $order->platform_order_id]);
                                            if (!empty($response['line_items'])) {
                                                $lineItems = [];
                                                foreach ($response['line_items'] as $item) {
                                                    $taxcode = null;
                                                    if (!empty($item['taxes']) && isset($item['taxes'])) {
                                                        $taxcode = $item['taxes'][0]['id'];
                                                    }
                                                    if (!empty($lineItems)) {
                                                        $this->mobj->makeInsert('platform_order_refund_lines', $lineItems);
                                                    }
                                                }
                                                if (!empty($response['shipping_lines'])) {
                                                    $lineItems = [];
                                                    foreach ($response['shipping_lines'] as $item) {
                                                        $taxcode = null;
                                                        if (!empty($item['taxes']) && isset($item['taxes'])) {
                                                            $taxcode = $item['taxes'][0]['id'];
                                                        }

                                                        $lineItems[] = [
                                                            'platform_order_refund_id' => $order->id,
                                                            'product_name' => $item['method_title'],
                                                            'qty' => 1,
                                                            'subtotal' => isset($item['total']) ? $item['total'] : null,
                                                            'subtotal_tax' => isset($item['total_tax']) ? $item['total_tax'] : null,
                                                            'total' => isset($item['total']) ? $item['total'] : null,
                                                            'total_tax' => isset($item['total_tax']) ? $item['total_tax'] : null,
                                                            'taxes' => $taxcode,
                                                            'row_type' => "SHIPPING",
                                                        ];
                                                    }
                                                    if (!empty($lineItems)) {
                                                        $this->mobj->makeInsert('platform_order_refund_lines', $lineItems);
                                                    }
                                                }
                                                if (!empty($response['fee_lines'])) {
                                                    $lineItems = [];
                                                    foreach ($response['fee_lines'] as $item) {
                                                        $taxcode = null;
                                                        if (!empty($item['taxes']) && isset($item['taxes'])) {
                                                            $taxcode = $item['taxes'][0]['id'];
                                                        }
                                                        $lineItems[] = [
                                                            'platform_order_refund_id' => $order->id,
                                                            'product_name' => "Fee" . $item['name'],
                                                            'qty' => 1,
                                                            'subtotal' => isset($item['total']) ? $item['total'] : null,
                                                            'subtotal_tax' => isset($item['total_tax']) ? $item['total_tax'] : null,
                                                            'total' => isset($item['total']) ? $item['total'] : null,
                                                            'total_tax' => isset($item['total_tax']) ? $item['total_tax'] : null,
                                                            'taxes' => $taxcode,
                                                            'row_type' => "DISCOUNT",
                                                        ];
                                                    }
                                                    if (!empty($lineItems)) {
                                                        $this->mobj->makeInsert('platform_order_refund_lines', $lineItems);
                                                    }
                                                }
                                                $return_response = true;
                                            }
                                        } else {
                                            $return_response = false;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Search customer id in platform_customer table */
    public function SearchCustomerByID($CustomerID = null, $userId = null, $userIntegrationId = null, $PlatformId = null)
    {
        $return_response = false;
        $find = PlatformCustomer::select('id')->where([
            ['user_integration_id', '=', $userIntegrationId],
            ['platform_id', '=', $PlatformId],
            ['api_customer_id', '=', $CustomerID],
            ['is_deleted', '=', 0],
        ])->first();

        if ($find) {
            $return_response = $find->id;
        } else {
            $return_response = $this->GetCustomerById($CustomerID, $userId, $userIntegrationId);
        }
        return $return_response;
    }
    /* Extra Delete Only Order Webhook */
    public function DeleteOrderWebhook($user_integration_id)
    {
        $return_response = false;
        try {

            $account =  $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                $hookList = DB::table('platform_webhook_info')->where([
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,

                    'status' => 1,
                ])->whereIn('description', ['order.created', 'order.updated', 'order.deleted'])->pluck('api_id')->toArray();
                if ($hookList) {
                    $postData = [
                        'create' => [],
                        'delete' => $hookList,
                    ];
                    $response = $this->wc->CreateOrDeleteWebhook($account, null, $postData);
                    if ($webhook = json_decode($response->getBody(), true)) {

                        if (!empty($webhook) && isset($webhook['delete'])) {

                            foreach ($webhook['delete'] as $key => $value) {

                                if (!isset($value['error'])) {
                                    $hookList = ['user_id' => $account->user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_id' => $value['id']];
                                    $this->mobj->makeDelete(
                                        'platform_webhook_info',
                                        $hookList
                                    );
                                }
                            }
                            $return_response = true;
                        } else {
                            $return_response = isset($webhook['code']) ? $webhook['message'] : "Error";
                        }
                    } else {
                        $return_response = $response;
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    /* Get Account Details */
    private function getPrimaryAccount($user_integration_id, $platformName = null, $platformId = null)
    {
        if (!$platformName) {
            $platformName = self::$myPlatform;
        }
        if (!$platformId) {
            $platformId = $this->platformId;
        }

        $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platformId);

        return $account;
    }
    /* Execute Woocommerce Method */
    public function ExecuteWoocommerce($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = null)
    {
        $response = true;
        if ($method == 'GET' && $event == 'CUSTOMER') {
            $response = $this->GetCustomers($user_id, $user_integration_id, 1, 1);
        } else if ($method == 'GET' && $event == 'PRODUCT') {

            if ($is_initial_sync) {
                //To get all products by product url at intial sync=1 || please notice value 2
                $response = $this->GetProducts($user_id, $user_integration_id, 1, $is_initial_sync);
            } else {
                //To get process products which is comes from webhooks when intial sync=0 || please notice value 2
                $response = $this->GetProducts($user_id, $user_integration_id, 2, $is_initial_sync);
            }
        } else if ($method == 'GET' && $event == 'PAYMENTGATEWAY') {
            $response = $this->GetPaymentGateways($user_id, $user_integration_id, 1);
            // \Log::channel('webhook')->info("GetPaymentGateways WOO -" . $user_id . " Integration " . $user_integration_id . "Response = " . $response . " Created Date : " . date('Y-m-d H:i:s'));
        } else if ($method == 'GET' && $event == 'TAXCODE') {
            $response = $this->GetTaxCodes($user_id, $user_integration_id, 1);
            // \Log::channel('webhook')->info("GetTaxCodes WOO -" . $user_id . " Integration " . $user_integration_id . "Response = " . $response . " Created Date : " . date('Y-m-d H:i:s'));
        } else if ($method == 'GET' && $event == 'ATTRIBUTE') {
            $response = $this->GetAttributesAndValues($user_id, $user_integration_id, 1);
            // \Log::channel('webhook')->info("GetTaxCodes WOO -" . $user_id . " Integration " . $user_integration_id . "Response = " . $response . " Created Date : " . date('Y-m-d H:i:s'));
        } else if ($method == 'GET' && $event == 'ZONE') {
            $response = $this->GetZone($user_id, $user_integration_id, 1);
            // \Log::channel('webhook')->info("GetZone WOO -" . $user_id . " Integration " . $user_integration_id . "Response = " . $response . " Created Date : " . date('Y-m-d H:i:s'));
        } else if ($method == 'GET' && $event == 'SALESORDER') {
            // if(env('APP_ENV')=="stag"){
            $webhook = ['customer', 'product'];
            // }else{
            //     $webhook=['customer', 'product','order'];
            // }

            $response = $this->GetSalesOrder($user_id, $user_integration_id, $platform_workflow_rule_id, $webhook, 1, $is_initial_sync);
            //\Log::channel('webhook')->info("SALESORDER WOO -" . $user_id . " Integration " . $user_integration_id . "Response = " . $response . " Created Date : " . date('Y-m-d H:i:s'));
        } else if ($method == 'GET' && $event == 'SHIPPINGMETHOD') {
            $response = $this->GetZoneShippingMethod($user_id, $user_integration_id, $record_id);
            //  \Log::channel('webhook')->info("GetShippingMethods WOO -" . $user_id . " Integration " . $user_integration_id . "Response = " . $response . " Created Date : " . date('Y-m-d H:i:s'));
        } else if ($method == 'GET' && $event == 'CATEGORY') {
            $response = $this->GetCategories($user_id, $user_integration_id, $is_initial_sync);
            // \Log::channel('webhook')->info("GetCategories WOO -" . $user_id . " Integration " . $user_integration_id . "Response = " . $response . " Created Date : " . date('Y-m-d H:i:s'));
        } else if ($method == 'MUTATE' && $event == 'SHIPMENT') {
            $sync_status = 'Ready';
            $this->SyncShipment($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $sync_status);
        } else if ($method == 'MUTATE' && $event == 'INVENTORY') {
            $sync_status = 'Ready';
            $this->SyncInventory($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
            // \Log::channel('webhook')->info("MUTATE_INVENTORY_WOO -" . $user_id . " Integration " . $user_integration_id . "PlatformWorkFlow=" . $platform_workflow_rule_id . " UserWorkFlow: " . $user_workflow_rule_id . " Created Date : " . date('Y-m-d H:i:s'));
        } else if ($method == 'MUTATE' && $event == 'PRODUCT') {
            $sync_status = 'Ready';
            $response = $this->SyncCreateOrUpdateProduct($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
            // \Log::channel('webhook')->info("SyncCreateOrUpdateProduct_WOO -" . $user_id . " Integration " . $user_integration_id . " Created Date : " . date('Y-m-d H:i:s'));
        } else if ($method == 'GET' && $event == 'REFUND') {
            $sync_status = 'Pending';
            $response = $this->GetReadyRefundOrder($user_id, $user_integration_id, $sync_status);
            // \Log::channel('webhook')->info("GetReadyRefundOrder WOO -" . $user_id . " Integration " . $user_integration_id . "Created Date : " . date('Y-m-d H:i:s'));
        }
        return $response;
    }
}
