<?php

namespace App\Http\Controllers\Brightpearl;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use App\Helper\MainModel;
use App\Helper\Api\BrightpearlApi;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\PlatformOrder;
use App\Models\SyncLog;

use function PHPSTORM_META\type;

class BrightpearlCustomProcessController extends Controller
{
    public $mobj, $helper, $platformId, $bp, $map, $log;
    public static $myPlatform = 'brightpearl';

    public function __construct()
    {
        $this->helper = new ConnectionHelper;
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
        $this->mobj = new MainModel();
        $this->bp = new BrightpearlApi();
        $this->log = new Logger();
        $this->map = new FieldMappingHelper();
    }

    /**
     * Sales Order
     * Sales Credit
     * Goods Receive
     */
    public function CustomSalesOrderAndCreditsWithReceivedGoods($userId = null, $userIntegrationId = NULL, $PlatformWorkFlowRuleID = null, $UserWorkFlowRuleID = null, $SourcePlatformName = null, $sync_status = "Ready", $RecordID = null, $order_type = 'SO')
    {

        $return_response = true;
        $sync_error = '';
        try {

            $order_limit = 3;
            $chunk_limit = 9;
            $default_notes = ['receive_goods' => false, 'sales_order_close' => false, 'sales_credit_close' => false];


            $query = PlatformOrder::select(['id', 'api_order_id', 'notes', 'linked_id']);
            if ($RecordID && $RecordID !== 0) {
                $query->where('id', $RecordID);
            } else {
                $query->where(
                    [
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $userIntegrationId,
                        'sync_status' => $sync_status,
                        'order_type' => $order_type
                    ]
                );
            }
            $listorders = $query->orderby('updated_at', 'ASC')->take($order_limit)->get();

            if (count($listorders) > 0) {

                $ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

                if (!empty($ufound)) {

                    $object_id = $this->helper->getObjectId('sales_order');

                    $gettimezone = $this->mobj->getFirstResultByConditions('platform_account_addtional_information', ['account_id' => $ufound->id, 'user_integration_id' => $userIntegrationId], ['account_timezone']);
                    if ($gettimezone) {
                        date_default_timezone_set($gettimezone->account_timezone);
                    }

                    $PlateformOrderIds = [];
                    foreach ($listorders as $order) {
                        $PlateformOrderIds[] = $order->id;
                    }
                    PlatformOrder::whereIn('id', $PlateformOrderIds)->update(['sync_status' => 'Processing']);

                    $chunkOfOrders = array_chunk($listorders->toArray(), $chunk_limit, true);
                    if (!empty($chunkOfOrders)) {
                        foreach ($chunkOfOrders as $chunk) {

                            $response = $api_order_ids = $platform_order_ids = $warehouse_id = $linked_ids = $proccessing_order_ids = $proccessing_platform_order_ids = $proccessing_platform_linked_ids = $failed_platform_order_ids = $assignedNotes = $processingNotes = [];

                            if (count($chunk) > 1) {

                                $messages = [];
                                $count = 1;
                                // Creating payload for closing order using multimsg concept
                                foreach ($chunk as $obj_order) {
                                    $order = (object) $obj_order;

                                    $api_order_ids[] = $order->api_order_id;
                                    $platform_order_ids[] = $order->id;
                                    $linked_ids[] = $order->linked_id;
                                    $notes = @$order->notes ? json_decode($order->notes, true) : $default_notes;
                                    $assignedNotes[] = $notes;

                                    $messages[] = [
                                        "label" => "LABEL$count",
                                        "uri" => "/order-service/sales-order/{$order->api_order_id}/close",
                                        "httpMethod" => "POST",
                                        "body" => ["taxDate" => date('Y-m-d\TH:i:s', time())]
                                    ];
                                    $count++;
                                }

                                $payload = [
                                    "processingMode" => "SEQUENTIAL",
                                    "onFail" => "CONTINUE",
                                    "messages" => (count($messages) > 0) ? $messages : []
                                ];

                                // Closing multiples order when order is not close
                                if ($notes['sales_order_close'] == false) {
                                    $response = $this->bp->MultiMessage($ufound, $payload);
                                }
                            } else {

                                // single order closing concept
                                if (isset($chunk[0]['api_order_id'])) {
                                    $api_order_ids[] = $chunk[0]['api_order_id'];
                                    $platform_order_ids[] = $chunk[0]['id'];
                                    $linked_ids[] = $chunk[0]['linked_id'];
                                    $notes = @$chunk[0]['notes'] ? json_decode($chunk[0]['notes'], true) : $default_notes;
                                    $assignedNotes[] = $notes;
                                    // Closing single order when order is not close
                                    if ($notes['sales_order_close'] == false) {
                                        $response = $this->bp->CloseSalesOrderByID($ufound, $chunk[0]['api_order_id']);
                                    }
                                }
                            }

                            $data = (!empty($response)) ? json_decode($response->getBody(), true) : '';

                            if (isset($data['errors'])) {
                                // Error handler for close sales order
                                PlatformOrder::whereIn('id', $platform_order_ids)->update(['sync_status' => 'Failed', 'notes' => json_encode($notes, true)]);

                                foreach ($data['errors'] as $key => $error) {
                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformName, $this->platformId, $object_id, 'failed', $platform_order_ids[$key], "Sales Order Close Failed - " . $error['message']);
                                }

                                $return_response = (isset($data['errors'][0]['message']))  ? "Sales Order Close Failed - " . $data['errors'][0]['message'] : "Sales Order Close Failed - Api error occured on sales order close.";
                            } else {
                                // Success handler for close sales order
                                if (!empty($data)) {

                                    foreach ($api_order_ids as $key => $soid) {
                                        if ($data['response']['processedMessages'][$key]['statusCode'] == 200) {
                                            // Succefully closed orders
                                            $proccessing_order_ids[] = $soid;
                                            $proccessing_platform_order_ids[] = $platform_order_ids[$key];
                                            $proccessing_platform_linked_ids[] = $linked_ids[$key];
                                            // $notes['sales_order_close'] = true;
                                            $processingNotes[] = $assignedNotes[$key];
                                        } else {
                                            // Error handler for close sales order
                                            $failed_platform_order_ids[] = $platform_order_ids[$key];

                                            $cso_contentBody = json_decode($data['response']['processedMessages'][$key]['body']['content'], true);
                                            if (isset($cso_contentBody['error']) || (isset($cso_contentBody['errors']) && is_array($cso_contentBody['errors']))) {
                                                $sync_error = isset($cso_contentBody['errors'][0]) ? $cso_contentBody['errors'][0]['message'] : 'Close sales order failed';
                                            } elseif (isset($cso_contentBody['response'])) {
                                                $sync_error = $cso_contentBody['message'];
                                            }

                                            if ($sync_error == "You have sent too many requests. Please wait before sending another request") {
                                                PlatformOrder::where(['id' => $platform_order_ids[$key]])->update(['sync_status' => 'Ready']);
                                            }

                                            $return_response = "Sales Order Close Failed - " . $sync_error;

                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformName, $this->platformId, $object_id, 'failed', $platform_order_ids[$key], $return_response);
                                        }
                                    }
                                } else {
                                    $proccessing_order_ids = $api_order_ids;
                                    $proccessing_platform_order_ids = $platform_order_ids;
                                    $proccessing_platform_linked_ids = $linked_ids;
                                    $processingNotes = $assignedNotes;
                                }

                                // updated due to closing process is completed
                                if (count($proccessing_platform_order_ids) > 0) {
                                    foreach ($proccessing_platform_order_ids as $key => $id) {
                                        $processingNotes[$key]['sales_order_close'] = true;
                                        PlatformOrder::where(['id' => $id])->update(['notes' => json_encode($processingNotes[$key], true)]);
                                    }
                                }

                                // updated due to failed here so we can resume process it from here
                                if (count($failed_platform_order_ids) > 0) {
                                    foreach ($failed_platform_order_ids as $key => $id) {
                                        $processingNotes[$key]['sales_order_close'] = false;
                                        PlatformOrder::where(['id' => $id])->update(['notes' => json_encode($processingNotes[$key], true)]);
                                    }
                                }


                                // next steps for successfull closed orders
                                if (count($proccessing_order_ids) > 0) {

                                    asort($proccessing_order_ids);
                                    $proccessing_order_ids = array_unique($proccessing_order_ids);

                                    $orderids = implode(',', $proccessing_order_ids);

                                    // Get Orders using api
                                    $getorders = $this->bp->GetOrder($ufound, $url = NULL, $orderids);
                                    $getordersData = (!empty($getorders)) ? json_decode($getorders->getBody(), true) : '';

                                    if (isset($getordersData['response']) && count($getordersData['response'])  > 0) {
                                        // sucess response from order api

                                        $payloadData = $exist_linked_order_ids = [];
                                        $i = 1;
                                        foreach ($getordersData['response'] as $key => $order) {
                                            $warehouse_id[] = $order['warehouseId'];

                                            if (!empty($proccessing_platform_linked_ids[$key])) {
                                                $linked_order_data = PlatformOrder::find($proccessing_platform_linked_ids[$key]);

                                                if (!empty($linked_order_data->api_order_id)) {
                                                    $exist_linked_order_ids[]['id'] = $linked_order_data->api_order_id;
                                                }
                                                continue;
                                            }

                                            //create payload for credit process
                                            $PayloadcreditData = $this->PayloadOrderResponseData($order);

                                            if (count($getordersData['response']) > 1) {
                                                // Multi-Message Payload For Create Sales Credit
                                                $payloadData[] = [
                                                    "label" => "LABEL$i",
                                                    "uri" => "/order-service/sales-credit",
                                                    "httpMethod" => "POST",
                                                    "body" => $PayloadcreditData
                                                ];
                                                $i++;
                                            } else {
                                                // Single Payload For Create Sales Credit
                                                $payloadData[] = $PayloadcreditData;
                                            }
                                        }

                                        // Sales Credit Create Payload
                                        if (count($payloadData) > 0) {

                                            if (count($payloadData) > 1) {
                                                // Multi-Message For Create Sales Credit
                                                $payload = [
                                                    "processingMode" => "SEQUENTIAL",
                                                    "onFail" => "CONTINUE",
                                                    "messages" => $payloadData
                                                ];

                                                $response = $this->bp->MultiMessage($ufound, $payload);
                                                $jsonArr = (!empty($response)) ? json_decode($response->getBody(), true) : '';
                                                $failed_credit_ids = [];
                                                if (!empty($jsonArr['response']['processedMessages'])) {
                                                    foreach ($jsonArr['response']['processedMessages'] as $key => $arr_response) {
                                                        if ($arr_response['statusCode'] == 201) {
                                                            $createsalesorderJsonParse = json_decode($arr_response['body']['content'], true);

                                                            $exist_linked_order_ids[]['id'] = $createsalesorderJsonParse['response'];
                                                        } else {

                                                            $failed_credit_ids[] = $proccessing_platform_order_ids[$key];

                                                            // Error handler for sales credit
                                                            $sc_contentBody = json_decode($arr_response['body']['content'], true);
                                                            $sync_error_sc = ''; // Sync Error Sales Credit
                                                            if (isset($sc_contentBody['error']) || (isset($sc_contentBody['errors']) && is_array($sc_contentBody['errors']))) {
                                                                $sync_error_sc = isset($sc_contentBody['errors'][0]) ? $sc_contentBody['errors'][0]['message'] : 'Sales Credit failed';
                                                            } elseif (isset($sc_contentBody['response'])) {
                                                                $sync_error_sc = $sc_contentBody['message'];
                                                            }
                                                            $return_response = "Sales Credit Close Failed - " . $sync_error_sc;

                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformName, $this->platformId, $object_id, 'failed', $proccessing_platform_order_ids[$key], $return_response);
                                                        }
                                                    }
                                                } else {
                                                    // Error handler for close sales credit
                                                    if (isset($jsonArr['errors'][0])) {
                                                        $failed_credit_ids[] = $proccessing_platform_order_ids;
                                                        foreach ($jsonArr['errors'] as $key => $error) {
                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformName, $this->platformId, $object_id, 'failed', $proccessing_platform_order_ids[$key], "Create Sales Credit Failed- " . $error['message']);
                                                        }
                                                        $return_response = "Create Sales Credit Failed - " . $jsonArr['errors'][0]['message'];
                                                    }
                                                }

                                                if (count($failed_credit_ids) > 0) {
                                                    PlatformOrder::whereIn('id', $failed_credit_ids)->update(['sync_status' => 'Failed']);
                                                }
                                            } else {
                                                // Create Sales Credit For Single Order
                                                $response = $this->bp->CreateSalesCredit($ufound, $url = NULL, $payloadData[0]);
                                                $jsonArr = (!empty($response)) ? json_decode($response->getBody(), true) : '';

                                                if (!empty($jsonArr['response'])) {
                                                    $exist_linked_order_ids[]['id'] = $jsonArr['response'];
                                                } else {
                                                    // Error handler for sales credit
                                                    if (isset($jsonArr['errors'][0])) {

                                                        $return_response = "Create Sales Credit Failed - " . $jsonArr['errors'][0]['message']; // return

                                                        PlatformOrder::where(['id' => $proccessing_platform_order_ids[0]])->update(['sync_status' => 'Failed']);

                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformName, $this->platformId, $object_id, 'failed', $proccessing_platform_order_ids[0], $return_response);
                                                    }
                                                }
                                            }
                                        }


                                        // Goods Received & Close Sales Credit
                                        if (count($exist_linked_order_ids) > 0) {

                                            if (count($exist_linked_order_ids) > 1) {
                                                $j = 1;
                                                $SCClosePayload = [];

                                                foreach ($exist_linked_order_ids as $key => $salesCredit) {

                                                    $platform_order = [
                                                        'user_id' => $userId,
                                                        'user_workflow_rule_id' => $UserWorkFlowRuleID,
                                                        'platform_id' => $this->platformId,
                                                        'user_integration_id' => $userIntegrationId,
                                                        'api_order_id' => $salesCredit['id'],
                                                        'order_number' => $salesCredit['id'],
                                                        'order_type' => 'SC',
                                                        'linked_id' => $proccessing_platform_order_ids[$key],
                                                        'sync_status' => 'Ready',
                                                    ];

                                                    $existsSalesCredit = PlatformOrder::where(['api_order_id' => $salesCredit['id']])->first();

                                                    // Store sales credit data in platformorder table
                                                    $order_linked_id = (!empty($existsSalesCredit->id))  ? $existsSalesCredit->id : PlatformOrder::insertGetId($platform_order);


                                                    if ($processingNotes[$key]['receive_goods'] == false) {

                                                        // Goods Receive in BP
                                                        $rrsci = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->ReceiveRefundSalesCreditInventory($ufound, $salesCredit['id'], $warehouse_id[$key]);

                                                        $processingNotes[$key]['receive_goods'] = (!is_null($rrsci) && gettype($rrsci) == 'integer') ? true : false;

                                                        // $processingNotes[$key]['receive_goods'] = true;
                                                    }

                                                    // Update Refund Sync Status As Synced And Linked with sales credit
                                                    PlatformOrder::where(['id' => $proccessing_platform_order_ids[$key]])->update(['linked_id' => $order_linked_id, 'sync_status' => 'Synced', 'notes' => json_encode($processingNotes[$key], true)]);


                                                    if (isset($processingNotes[$key]['sales_credit_close']) && $processingNotes[$key]['sales_credit_close'] == false) {

                                                        $SCClosePayload[] = [
                                                            "label" => "LABEL$j",
                                                            "uri" => "/order-service/sales-credit/" . $salesCredit['id'] . "/close",
                                                            "httpMethod" => "POST",
                                                            "body" => ["taxDate" => date('Y-m-d\TH:i:s', time())]
                                                        ];
                                                        $j++;
                                                    } else {
                                                        unset($proccessing_platform_order_ids[$key]);
                                                    }
                                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformName, $this->platformId, $object_id, 'success', $salesCredit['id'], null);
                                                }

                                                // Start Proccess for Close Sales Crdit
                                                if (count($SCClosePayload) > 1) {

                                                    $SCpayload = [
                                                        "processingMode" => "SEQUENTIAL",
                                                        "onFail" => "CONTINUE",
                                                        "messages" => $SCClosePayload
                                                    ];

                                                    // Multi-Message For Close Sales Credit
                                                    $scresponse = $this->bp->MultiMessage($ufound, $SCpayload);
                                                    $csc_response = (!empty($scresponse)) ? json_decode($scresponse->getBody(), true) : '';

                                                    if (isset($csc_response['errors'][0])) {

                                                        // Error handler for close sales credit
                                                        foreach ($csc_response['errors'] as $key => $error) {

                                                            $sync_status = ($error['message'] == "You have sent too many requests. Please wait before sending another request") ? "Ready" : "Failed";

                                                            $processingNotes[$key]['sales_credit_close'] = false;
                                                            PlatformOrder::where(['id' => $proccessing_platform_order_ids[$key]])->update(['sync_status' => $sync_status, 'notes' => json_encode($processingNotes[$key], true)]);



                                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformName, $this->platformId, $object_id, 'failed', $proccessing_platform_order_ids[$key], "Sales Credit Close Failed - " . $error['message']);
                                                        }

                                                        $return_response = "Sales Credit Close Failed - " . $csc_response['errors'][0]['message'];
                                                    } else {

                                                        // success
                                                        foreach ($proccessing_platform_order_ids as $key => $platform_order_id) {
                                                            $processingNotes[$key]['sales_credit_close'] = true;
                                                            PlatformOrder::where(['id' => $platform_order_id])->update(['sync_status' => 'Synced', 'notes' => json_encode($processingNotes[$key], true)]);
                                                        }
                                                    }
                                                }
                                            } else {
                                                $platform_order = [
                                                    'user_id' => $userId,
                                                    'user_workflow_rule_id' => $UserWorkFlowRuleID,
                                                    'platform_id' => $this->platformId,
                                                    'user_integration_id' => $userIntegrationId,
                                                    'api_order_id' => $exist_linked_order_ids[0]['id'],
                                                    'order_number' => $exist_linked_order_ids[0]['id'],
                                                    'order_type' => 'SC',
                                                    'linked_id' => $proccessing_platform_order_ids[0],
                                                    'sync_status' => 'Ready',
                                                ];


                                                $existsSalesCredit = PlatformOrder::where(['api_order_id' => $exist_linked_order_ids[0]['id']])->first();

                                                // Store sales credit data in platformorder table
                                                $order_linked_id = (!empty($existsSalesCredit->id))  ? $existsSalesCredit->id : PlatformOrder::insertGetId($platform_order);

                                                if ($processingNotes[0]['receive_goods'] == false) {

                                                    // Goods Receive in BP
                                                    $rrsci = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->ReceiveRefundSalesCreditInventory($ufound, $exist_linked_order_ids[0]['id'], $warehouse_id[0]);

                                                    $processingNotes[0]['receive_goods'] = (!is_null($rrsci) && gettype($rrsci) == 'integer') ? true : false;
                                                    // $processingNotes[0]['receive_goods'] = true;
                                                }

                                                // Update Refund Sync Status As Synced And Linked with sales credit
                                                PlatformOrder::where(['id' => $proccessing_platform_order_ids[0]])->update(['linked_id' => $order_linked_id, 'sync_status' => 'Synced', 'notes' => json_encode($notes)]);

                                                // Start Proccess for Close Sales Crdit
                                                if (isset($processingNotes[0]['sales_credit_close']) && $processingNotes[0]['sales_credit_close'] == false) {

                                                    // Close Sales Credit For Single Order
                                                    $scresponse = $this->bp->CloseSalesCreditByID($ufound, $exist_linked_order_ids[0]['id']);

                                                    $sc_array = json_decode($scresponse->getBody(), true);
                                                    if (isset($sc_array['errors'][0])) {

                                                        // Error handler for close sales credit
                                                        $return_response = "Sales Credit Close Failed - " . $sc_array['errors'][0]['message'];

                                                        $sync_status = ($sc_array['errors'][0]['message'] == "You have sent too many requests. Please wait before sending another request") ? "Ready" : "Failed";

                                                        $processingNotes[0]['sales_credit_close'] = false;
                                                        PlatformOrder::where(['id' => $proccessing_platform_order_ids[0]])->update(['sync_status' => $sync_status, 'notes' => json_encode($processingNotes[0], true)]);

                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformName, $this->platformId, $object_id, 'failed', $proccessing_platform_order_ids[0], $return_response);
                                                    } else {

                                                        // Success
                                                        $processingNotes[0]['sales_credit_close'] = true;
                                                        PlatformOrder::where(['id' => $proccessing_platform_order_ids[0]])->update(['sync_status' => 'Synced', 'notes' => json_encode($processingNotes[0], true)]);
                                                    }
                                                }
                                            }
                                        }
                                    } else {
                                        //handle error

                                        $error = $this->bp->handleResponseError($getordersData);
                                        $return_response = isset($error) ? $error : "API Error";

                                        $sync_status = ($error == "You have sent too many requests. Please wait before sending another request") ? "Ready" : "Failed";

                                        if (count($proccessing_platform_order_ids) > 0) {

                                            // Update Refund Sync Status As Failed Due To ERROR
                                            PlatformOrder::whereIn('id', $proccessing_platform_order_ids)->update(['sync_status' => $sync_status]);

                                            $this->log->syncLogBulk($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformName, $this->platformId, $object_id, 'failed', $proccessing_platform_order_ids, $return_response);
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $return_response = "Platform Account Credentials Not Found";
                }
            } else {
                $return_response = "Orders Not Found";
            }
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            $return_response = "Something Went Wrong";
        }

        return $return_response;
    }

    /**
     * Order Response
     * Payload
     */
    public function PayloadOrderResponseData($order = [])
    {
        $response = [];
        if (is_array($order) && count($order) > 0) {
            $api_order_id   = (isset($order['id'])) ? $order['id'] : "";
            $delivery       = (isset($order['parties']['delivery'])) ? $order['parties']['delivery'] : "";
            $shipping_add   = [
                "addressFullName" => $delivery['addressFullName'],
                "companyName"   => $delivery['companyName'],
                "addressLine1" => $delivery['addressLine1'],
                "addressLine2" => $delivery['addressLine2'],
                "addressLine3" => $delivery['addressLine3'],
                "addressLine4" => $delivery['addressLine4'],
                "postalCode" => $delivery['postalCode'],
                "countryIsoCode" => $delivery['countryIsoCode'],
                "telephone" => $delivery['telephone'],
                "mobileTelephone" => $delivery['mobileTelephone'],
                "email" => $delivery['email']
            ];


            $lineItems = [];
            if (isset($order['orderRows']) && count($order['orderRows']) > 0) {
                foreach ($order['orderRows'] as $item) {
                    if (isset($item['composition']['bundleChild']) && $item['composition']['bundleChild'] == false) {
                        $lineItems[] = [
                            "productId" => $item['productId'],
                            "name" => $item['productName'],
                            "quantity" => (float)$item['quantity']['magnitude'],
                            "taxCode" => $item['rowValue']['taxCode'],
                            "net" => (float)$item['rowValue']['rowNet']['value'],
                            "tax" => (float)$item['rowValue']['rowTax']['value'],
                            "discountPercentage" => (isset($item['discountPercentage'])) ? (float)$item['discountPercentage'] : 0,
                            "nominalCode" => $item['nominalCode'],
                        ];
                    }
                }
            }
            $currency = [
                "code" => (isset($order['currency']['orderCurrencyCode'])) ? $order['currency']['orderCurrencyCode'] : "",
                "fixedExchangeRate" => (isset($order['currency']['fixedExchangeRate'])) ? $order['currency']['fixedExchangeRate'] : false,
            ];
            if (((isset($order['currency']['fixedExchangeRate'])) && $order['currency']['fixedExchangeRate'] == true && isset($order['currency']['exchangeRate']))) {
                $currency['exchangeRate'] =     $order['currency']['exchangeRate'];
            }
            $creditData = [
                "customerId" => (isset($order['parties']['customer']['contactId'])) ? $order['parties']['customer']['contactId'] : 0,
                "ref" => (isset($order['reference'])) ? $order['reference'] : "",
                "placedOn" => date('Y-m-d\TH:i:s', time()),
                "taxDate" => date('Y-m-d\TH:i:s', time()),
                "parentId" => $api_order_id,
                "statusId" => (isset($order['orderStatus']['orderStatusId'])) ? $order['orderStatus']['orderStatusId'] : 0,
                "warehouseId" => (isset($order['warehouseId'])) ? $order['warehouseId'] : "",
                "staffOwnerId" => (isset($order['assignment']['current']['staffOwnerContactId'])) ? $order['assignment']['current']['staffOwnerContactId'] : 0,
                "projectId" => (isset($order['assignment']['current']['projectId'])) ? $order['assignment']['current']['projectId'] : 0,
                "channelId" => (isset($order['assignment']['current']['channelId'])) ? $order['assignment']['current']['channelId'] : 0,
                "leadSourceId" => (isset($order['assignment']['current']['leadSourceId'])) ? $order['assignment']['current']['leadSourceId'] : 0,
                "teamId" => (isset($order['assignment']['current']['teamId'])) ? $order['assignment']['current']['teamId'] : 0,
                "priceListId" => (isset($order['priceListId'])) ? $order['priceListId'] : "",
                "priceModeCode" => (isset($order['priceModeCode'])) ? $order['priceModeCode'] : "",
                "assignment" => (isset($order['priceListId'])) ? $order['priceListId'] : "",
                "currency" => $currency,
                "delivery" => [
                    "date" => (isset($order['delivery']['deliveryDate'])) ? $order['delivery']['deliveryDate'] : "",
                    "address" => $shipping_add,
                    "shippingMethodId" => (isset($order['delivery']['shippingMethodId'])) ? $order['delivery']['shippingMethodId'] : 0
                ],
                "settlementDiscount" => [
                    "percentage" => (isset($order['settlementDiscount']['percentage'])) ? (float)$order['settlementDiscount']['percentage'] : 0,
                    "days" => (isset($order['settlementDiscount']['days'])) ? (float)$order['settlementDiscount']['days'] : 0,
                ],
                "rows" => $lineItems
            ];
            $response = $creditData;
        }
        return $response;
    }

    /**
     * Write Off Order Inventory
     */
    public function UpdateWriteoffOrderInventory($userId = null, $userIntegrationId = NULL, $PlatformWorkFlowRuleID = null, $UserWorkFlowRuleID = null, $SourcePlatformName = null, $sync_status = "Ready", $RecordID = null, $order_type = 'SC')
    {

        $return_response = true;
        try {
            $order_limit = 2;

            $query = PlatformOrder::select(['id', 'api_order_id'])->orderby('updated_at', 'ASC');
            if ($RecordID && $RecordID !== 0) {
                $query->where('id', $RecordID);
            } else {
                $query->where(
                    [
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $userIntegrationId,
                        'sync_status' => $sync_status,
                        'order_type' => $order_type
                    ]
                );
            }
            $orders = $query->take($order_limit)->get();

            if (count($orders) > 0) {

                $object_id = $this->helper->getObjectId('sales_order');

                /* Platform Account Credentials */
                $ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
                if (!empty($ufound)) {
                    $PlateformOrderIds = $apiOrderIds = [];
                    foreach ($orders as $order) {
                        $apiOrderIds[] = $order->api_order_id;
                        $PlateformOrderIds[$order->api_order_id] = $order->id;
                    }
                    PlatformOrder::whereIn('id', $PlateformOrderIds)->update(['sync_status' => 'Processing']);

                    $chunkOfOrders = array_chunk($orders->toArray(), 5, true);

                    if (!empty($chunkOfOrders)) {
                        foreach ($chunkOfOrders as $chunk) {
                            if (count($chunk) > 0) {

                                asort($apiOrderIds);
                                $OrderIds = implode(',', $apiOrderIds);

                                // Get Sales Credit Order By ID
                                $getorders = $this->bp->GetSalesCreditByID($ufound, $OrderIds);
                                $salescreditData = json_decode($getorders->getBody(), true);

                                if (count($salescreditData['response']) > 0) {
                                    $locations = [];
                                    foreach ($salescreditData['response'] as $key => $order) {
                                        $order = (object) $order;
                                        $error = false;

                                        $warehouseId = isset($order->warehouseId) ? $order->warehouseId : 0;
                                        $defaultLocationId = $this->GetDefaultLocationIdOfWarehouse($ufound, $warehouseId, $locations);

                                        // set warehouse_id & defalt_location_id
                                        $locations[$warehouseId] = $defaultLocationId;

                                        if (isset($defaultLocationId)) {
                                            // Items Payload
                                            $items = [];
                                            foreach ($order->rows as $i => $item) {
                                                $items[$i]['orderId']   = $order->id;
                                                $items[$i]['quantity']  = -$item['quantity'];
                                                $items[$i]['productId'] = $item['productId'];
                                                $items[$i]['reason']    = "Inventory write off";
                                                $items[$i]['locationId'] = $defaultLocationId;
                                                $items[$i]['cost'] = [
                                                    "currency" => (!empty($order->currency['code'])) ? $order->currency['code'] : "USD",
                                                    "value" => (isset($order->currency['exchangeRate'])) ? $order->currency['exchangeRate'] : 0,
                                                ];
                                            }

                                            // Write Off Inventory API
                                            $stockCorrection = $this->bp->UpdateInventory($ufound, $warehouseId, ['corrections' => $items]);
                                            $ResStockCorrection = json_decode($stockCorrection->getBody(), true);

                                            if (isset($ResStockCorrection['errors'][0]['message'])) {

                                                // Error Handler For Update Inventory
                                                $return_response = $error = "Write Off Inventory Failed - " . $ResStockCorrection['errors'][0]['message'];
                                            } else {

                                                // success
                                                PlatformOrder::where(['id' => $PlateformOrderIds[$order->id]])->update(['sync_status' => 'Synced']);

                                                if (count($ResStockCorrection['response']) > 0) {
                                                    foreach ($ResStockCorrection['response'] as $stock_response) {
                                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformName, $this->platformId, $object_id, 'success', $PlateformOrderIds[$order->id], $stock_response);
                                                    }
                                                }
                                            }
                                        } else {
                                            $return_response = $error = "Default location not found";
                                        }

                                        if (!empty($error)) {

                                            $sync_status = ($error == "You have sent too many requests. Please wait before sending another request") ? "Ready" : "Failed";

                                            PlatformOrder::where(['id' => $PlateformOrderIds[$order->id]])->update(['sync_status' => $sync_status]);

                                            $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformName, $this->platformId, $object_id, 'failed', $PlateformOrderIds[$order->id], $error);
                                        }
                                    }
                                } else {
                                    $return_response = (isset($salescreditData['errors'][0]['message'])) ? "Get Sales Credit Failed - " . $salescreditData['errors'][0]['message'] : "Sales Credit not found";
                                }
                            }
                        }
                    }
                }
            } else {
                $return_response = "Orders not found";
            }
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            $return_response = "Something went wrong";
        }

        return $return_response;
    }

    public function GetDefaultLocationIdOfWarehouse($ufound, $warehouseId, $locations = [])
    {
        $defaultLocationId = '';
        // check warehouse_id & defalt_location_id exist or not
        if (isset($locations[$warehouseId])) {
            $defaultLocationId = $locations[$warehouseId];
        } else {
            // Get Default Location ID BY Warehouse ID
            $defaultLocationResponse = $this->bp->GetWarehouseDefaultLocation($ufound, $warehouseId);
            $response = $this->bp->getResponse($defaultLocationResponse);

            if (isset($response['status_code']) && (in_array($response['status_code'], [200, 201]))) {
                $defaultLocation = $response['body'];
                if (is_numeric($defaultLocation['response']) && isset($defaultLocation['response'])) {
                    $defaultLocationId = $defaultLocation['response'];
                }
            }
        }

        return $defaultLocationId;
    }
}
