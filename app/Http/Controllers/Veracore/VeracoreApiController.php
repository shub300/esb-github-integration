<?php

namespace App\Http\Controllers\Veracore;

use App\Http\Controllers\Veracore\Api\VeracoreApi;

use DateTime;
use Exception;
use App\Models\PlatformUrl;
use Illuminate\Http\Request;
use App\Models\PlatformOrder;
use App\Models\PlatformAccount;
use App\Models\PlatformOrderLine;
use Illuminate\Support\Facades\Log;
use App\Models\Enum\PlatformStatus;
use App\Http\Controllers\Controller;
use App\Models\PlatformCustomer;
use App\Models\PlatformObjectData;
use Illuminate\Support\Facades\Auth;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformProduct;
use App\Models\PlatformProductInventory;
use App\Models\PlatformProductPriceList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class VeracoreApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $client_id = "";
    public $client_secret = "";
    public $app_id = "";
    public $api_domain = "";
    public static $myPlatform = 'veracore';
    public $VeracoreApi;
    public $platformId;

    /**
     *
     */
    public function __construct()
    {
        $this->VeracoreApi = new VeracoreApi();
        $this->platformId = $this->VeracoreApi->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
    }

    /**
     *
     */
    public function InitiateVeracoreAuth(Request $request)
    {
        $platform = self::$myPlatform;
        return view("pages.apiauth.veracore_auth", compact('platform'));
    }

    /**
     *
     */
    public function ConnectVeracoreAuth(Request $request)
    {
        if ($request->isMethod('post')) {
            $flag = true;
            $validator = Validator::make($request->all(), [
                'account_name' => 'required',
                'custom_domain' => 'required',
                'app_id' => 'required',
                'app_secret' => 'required',
                'api_domain' => 'required',
                'ownerID' => 'required',
            ]);

            if ($validator->fails()) {
                $flag = false;
                $data['status_code'] = 0;
                $data['status_text'] = $validator->getMessageBag()->toArray();
            } else {
                // to check whether given account is already in use or not.
                $this->api_domain = $request->api_domain;
                $checkExistingAc = $this->checkExistingConnectedAcc($this->platformId, $request->custom_domain, $request->app_id, $request->app_secret);

                if ($checkExistingAc) {
                    $flag = false;
                    $data['status_code'] = 0;
                    $data['status_text'] = 'This account detail already exist, Try with another account.';
                } else {

                    $api_url = "https://".$this->api_domain.".veracore.com/VeraCore/Public.Api/api/Login";
                    $post_data = [
                        'username' => $request->app_id,
                        'password' => $request->app_secret,
                        'systemId' => $request->custom_domain
                    ];
                    $headers = [
                        'Content-Type: application/json',
                    ];

                    $checkCredentials = json_decode( $this->VeracoreApi->MainModel->makeCurlRequest( 'POST', $api_url, json_encode( $post_data ), $headers ), true );
                    if ( $checkCredentials['Error'] == null || !$checkCredentials['Error'] ) {
                        $user_id = Auth::user()->id;
                        $account_name = $request->account_name;
                        $platform_account = PlatformAccount::where([
                            'user_id' => $user_id,
                            'platform_id' => $this->platformId,
                            'account_name' => $account_name
                        ])
                        ->first();

                        if( !$platform_account ){
                            $platform_account = new PlatformAccount();
                        }

                        $platform_account->user_id = $user_id;
                        $platform_account->platform_id = $this->platformId;
                        $platform_account->account_name = $account_name;
                        $platform_account->app_id = $this->VeracoreApi->MainModel->encrypt_decrypt( $request->app_id );
                        $platform_account->app_secret = $this->VeracoreApi->MainModel->encrypt_decrypt( $request->app_secret );
                        $platform_account->access_token = $this->VeracoreApi->MainModel->encrypt_decrypt( $checkCredentials['Token'] );
                        $platform_account->custom_domain = $request->custom_domain;
                        $platform_account->access_key = $request->ownerID;
                        $platform_account->api_domain = $this->api_domain;
                        $platform_account->token_type = '';
                        $platform_account->expires_in = date( 'Y-m-d H:i:s', strtotime( $checkCredentials['UtcExpirationDate'] ) );
                        $platform_account->token_refresh_time = time();
                        $platform_account->allow_refresh = 0;
                        $platform_account->save();
                    } else {
                        $flag = false;
                        $data['status_code'] = 0;
                        $data['status_text'] = $checkCredentials['Error'];
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

    /**
     * GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.
     */
    public function ExecuteEventVeracore($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform='',$platform_workflow_rule_id='', $record_id = '')
    {
        $log = "Method: ".$method.", event: ".$event.", source_platform: ".$source_platform.", is_initial_sync: ".$is_initial_sync;
        Storage::append( 'Veracore/'.$user_integration_id.'/ExecuteEventVeracore/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".$log );
        $response = true;

        $source_platform_id = 0;
        if( $source_platform != "" ){
            $source_platform_id = $this->VeracoreApi->ConnectionHelper->getPlatformIdByName($source_platform);
        }

        if ($method == 'MUTATE' && $event == 'PURCHASEORDER') {
            $response = $this->createPurchaseOrder( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id );
        } else if($method == 'GET' && $event == 'WAREHOUSELOCATION') {
            $response = $this->getWareHouseLists( $user_id, $user_integration_id );
        } else if ($method == 'GET' && $event == 'GETPRODUCTTASKID') {//Create Valuation Report Step 1:
            $response = $this->createValuationReport( $user_id, $user_integration_id );
        } else if ($method == 'GET' && $event == 'GETPRODUCTBYTASKID"') {//Create Valuation Report Step 2:
            $response = $this->getValuationProducts( $user_id, $user_integration_id );
        } else if ($method == 'GET' && $event == 'PRODUCT' && $is_initial_sync ) {//Fetch Valuation Report Step 1:
            $response = $this->createValuationReport( $user_id, $user_integration_id, $is_initial_sync );
        }  else if ($method == 'GET' && $event == 'CREATEREPORT') {//Create expected arrival report Step 1:
            $response = $this->createExpectedArriavalReportStep1( $user_id, $user_integration_id, $user_workflow_rule_id );
        } else if ($method == 'GET' && $event == 'CHECKREPORTSTATUS') {//Fetch expected arrival report status Step 2:
            $response = $this->createExpectedArriavalReportStep2( $user_id, $user_integration_id );
        } else if ($method == 'GET' && $event == 'GETPURCHASERECEIPT') {//fetch expected arrival report status response Step 3:
            $response = $this->createExpectedArriavalReportStep3( $user_id, $user_integration_id );
        }
        return $response;
    }

    /**
     *
     */
    public function checkExistingConnectedAcc($platform_id, $custom_domain, $app_id, $secret_key)
    {
        $checkAccount = PlatformAccount::where( ['platform_id' => $platform_id, 'custom_domain' => $custom_domain, 'app_id' => $app_id, 'secret_key' => $secret_key] )->first();
        if ($checkAccount) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Purchase order sync from IP to Vera core: - We can fetch the purchase order from IP and create a purchase order in Vera core.
     */
    public function createPurchaseOrder( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id ){
        $return_response = true;
        try
        {
            $platform_account = $this->VeracoreApi->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if( $platform_account ){

                $limit = 50;
                $offset = 0;

                $where['platform_id'] = $source_platform_id;
                $where['user_integration_id'] = $user_integration_id;
                $where['order_type'] = 'PO';
                $where['sync_status'] = PlatformStatus::READY;

                if( $record_id ){
                    $where['id'] = $record_id;
                    $where['sync_status'] = PlatformStatus::FAILED;
                }

                $platformOrderArr = PlatformOrder::
                    with( 'platformOrderLine' )
                    ->where($where)
                    ->offset($offset)
                    ->limit($limit)
                    ->get();

                $sourcePlatformAccount = $this->VeracoreApi->MainModel->getPlatformAccountByUserIntegration( $user_integration_id, $source_platform_id );

                if( COUNT( $platformOrderArr ) > 0 && $sourcePlatformAccount ){
                    $sync_object_id = $this->VeracoreApi->ConnectionHelper->getObjectId('purchase_order');

                    $url = "https://".$platform_account->api_domain.".veracore.com/VeraCore/Public.Api/api/expectedarrival";
                    foreach( $platformOrderArr as $order ){

                        $comments = "";
                        $details = [];
                        foreach( $order->platformOrderLine as $k=>$orderLine ){
                            $details[$k]["foreignSystemLineKey"] = $orderLine->api_order_line_id;//335PM
                            $details[$k]["productID"] = trim( $orderLine->api_product_id );//"TEST1-TEST1" //S0B1911S-99280-VSBK-85
                            $details[$k]["productSizeCode"] = "";
                            $details[$k]["productColorCode"] = "";
                            $details[$k]["productVersion"] = "";
                            $details[$k]["quantityExpected"] = $orderLine->qty;
                            $details[$k]["tie"] = "";
                            $details[$k]["high"] = "";
                            $comments .= $orderLine->product_name.', ';
                        }

                        $warehouseOrderObject = $this->VeracoreApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);
                        $warehouse = PlatformObjectData::select('api_id')
                        ->where([
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'platform_object_id' => $warehouseOrderObject->id,
                        ])
                        ->first();

                        $post_data = [
                            "ownerID" => $platform_account->access_key,//"Burju Shoes",
                            "tradingPartnerID" => "",
                            "foreignSystemKey" => $order->api_order_id,
                            "warehouseID" => $warehouse->api_id,//"XFWHSE",
                            "anticipatedArrivalDatetime" => date( "m/d/Y", strtotime( $order->delivery_date ) ),
                            "ourPurchaseOrder" => $order->id,//IP PO
                            "clientPurchaseOrder" => $order->api_order_id,
                            "shippedFrom" => "",
                            "shippingMethod" => "",
                            "comments" => rtrim( $comments, ", " ),
                            "receivingBay" => "",
                            "trailerNumber" => "",
                            "sealNumber" => "",
                            "serialNumber" => "",
                            "billOfLadingNumber" => "",
                            "returnAuthNumber" => "",
                            "loadType" => "",
                            "appointmentNumber" => "",
                            "details" => $details
                        ];

                        $headers = [
                            'Authorization: Bearer '.$this->VeracoreApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' ),
                        ];

                        $response = $this->VeracoreApi->makeAPICall( $url, "POST", $post_data, $headers );
                        Storage::append( 'Veracore/'.$user_integration_id.'/createPurchaseOrder/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $post_data )." : ".json_encode( $response ) );
                        $response = array_change_key_case($response, CASE_LOWER);
                        if( $response ){
                            $updatePlatformOrder = PlatformOrder::find( $order->id );//get selected order object

                            if( isset( $response['result'] ) && $response['result'] === "Expected Arrival with Foreign System Key '$order->api_order_id' was created successfully!" ){//success
                                //create veracore platform new order record and generate linked id
                                $orderObj = PlatformOrder::where([
                                    'user_integration_id' => $user_integration_id,
                                    'platform_id' => $this->platformId,
                                    'api_order_id' => $order->api_order_id,
                                ])
                                ->select('id')
                                ->first();

                                if( !$orderObj ){
                                    $orderObj = new PlatformOrder();
                                    $orderObj->api_order_id = $order->api_order_id;
                                    $orderObj->user_id = $user_id;
                                    $orderObj->user_integration_id = $user_integration_id;
                                    $orderObj->platform_id = $this->platformId;
                                    $orderObj->order_type = 'PO';
                                    $orderObj->sync_status = PlatformStatus::SYNCED;
                                }

                                $orderObj->api_order_reference = $order->api_order_reference;
                                $orderObj->order_status = $order->order_status;
                                $orderObj->platform_customer_id = $order->platform_customer_id;
                                $orderObj->warehouse_id = $order->warehouse_id;
                                $orderObj->order_date = $order->order_date;
                                $orderObj->delivery_date = $order->delivery_date;
                                $orderObj->order_updated_at = $order->order_updated_at;
                                $orderObj->shipping_method = $order->shipping_method;
                                $orderObj->linked_id = $order->id;
                                $orderObj->save();

                                $updatePlatformOrder->linked_id = $orderObj->id;//pass vercora order id in IP order id

                                // PO line items
                                foreach( $order->platformOrderLine as $k=>$orderLine ){
                                    $orderLineArr = PlatformOrderLine::where([
                                        'platform_order_id' => $orderObj->id,
                                        'api_order_line_id' => $orderLine->api_order_line_id,
                                    ])
                                    ->select('id')
                                    ->first();

                                    if( !$orderLineArr ){
                                        $orderLineArr = new PlatformOrderLine();
                                        $orderLineArr->api_order_line_id = $orderLine->api_order_line_id;
                                        $orderLineArr->platform_order_id = $orderObj->id;
                                    }

                                    $orderLineArr->api_product_id = $orderLine->api_product_id;
                                    $orderLineArr->sku = $orderLine->sku;
                                    $orderLineArr->barcode = $orderLine->barcode;
                                    $orderLineArr->product_name = $orderLine->product_name;
                                    $orderLineArr->qty = $orderLine->qty;
                                    $orderLineArr->price = $orderLine->price;
                                    $orderLineArr->total_tax = $orderLine->total_tax;
                                    $orderLineArr->linked_id = $orderLine->id;
                                    $orderLineArr->save();

                                    //
                                    $updatePlatformOrderLine = PlatformOrderLine::find( $orderLine->id );
                                    $updatePlatformOrderLine->linked_id = $orderLineArr->id;
                                    $updatePlatformOrderLine->save();//pass vercora order line id in IP order line id
                                }

                                $updatePlatformOrder->sync_status = PlatformStatus::SYNCED;
                                $this->VeracoreApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $order->id, null );
                            } else {//failed
                                $updatePlatformOrder->sync_status = PlatformStatus::FAILED;
                                $return_response = $response['result'];
                                $this->VeracoreApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $order->id, html_entity_decode( $return_response ) );
                            }
                            $updatePlatformOrder->save();
                        } else { //Trying to access array offset on value of type null
                            $return_response = "Trying to access array offset on value of type null";
                            $this->VeracoreApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $order->id, $return_response );
                        }
                    }
                }
            }
        } catch ( Exception $e) {
            Log::error( $user_integration_id."-- VeracoreApiController createPurchaseOrder -->".$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     * Create expected arrival report Step 1:
     */
    public function createExpectedArriavalReportStep1( $user_id, $user_integration_id, $user_workflow_rule_id ){
        $return_response = true;
        try{
            $platform_account = $this->VeracoreApi->getAccountDetails( $user_integration_id );  // get the account information for the integration

            if( $platform_account ){
                $get_workflow_rule = $this->VeracoreApi->MainModel->getFirstResultByConditions('user_workflow_rule', [
                    'user_integration_id' => $user_integration_id,
                    'status' => 1,
                    'platform_workflow_rule_id' => $user_workflow_rule_id
                ], [
                    'sync_start_date'
                ]);

                if( $get_workflow_rule ){
                    $startDate =  date('m/d/Y', strtotime($get_workflow_rule->sync_start_date));
                } else {
                    $date = new DateTime();
                    $startDate = $date->modify( '-1 day' )->format('m/d/Y');
                }

                $endDate = date( 'm/d/Y' );

                $reportName = "Burju-ExpectedArrivals";
                $url = "https://".$platform_account->api_domain.".veracore.com/VeraCore/Public.Api/api/reports";
                $post_data = [
                    "reportName" => $reportName,
                    "filterCriteria" => [
                        [
                            "filterColumnName" => "Date / Time Entered",
                            "startDate" => $startDate,
                            "endDate" => $endDate,
                        ]
                    ]
                ];

                $headers = [
                    'Authorization: Bearer '.$this->VeracoreApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' )
                ];

                $response = $this->VeracoreApi->makeAPICall( $url, "POST", $post_data, $headers );
                if( $response ){
                    $response = array_change_key_case($response, CASE_LOWER);

                    if( isset( $response['taskid'] ) ) {
                        $platformReceipt = new PlatformUrl();
                        $platformReceipt->user_id = $user_id;
                        $platformReceipt->platform_id = $this->platformId;
                        $platformReceipt->user_integration_id = $user_integration_id;
                        $platformReceipt->url = $response['taskid'].'|'.$startDate.'|'.$endDate;
                        $platformReceipt->url_name = 'ExpectedArriavalReport';
                        $platformReceipt->status = 0;//Processing
                        $platformReceipt->url_filter = json_encode( $post_data );
                        $platformReceipt->allow_retain = 1;
                        $platformReceipt->save();
                    } else if( isset( $response['message'] ) ){
                        Log::error( $user_integration_id."-- ExecuteEventVeracore createExpectedArriavalReportStep1 --> ".$response['message'] );
                        $return_response = false;
                    }
                }
            }
        } catch ( Exception $e) {
            Log::error( $user_integration_id."-- ExecuteEventVeracore createExpectedArriavalReportStep1 -->".$e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * Fetch expected arrival report status Step 2:
     */
    public function createExpectedArriavalReportStep2( $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->VeracoreApi->getAccountDetails( $user_integration_id );  // get the account information for the integration

            if( $platform_account ){

                $platformReceipt = PlatformUrl::where([
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'url_name' => 'ExpectedArriavalReport',
                    'status' => 0,//Processing
                ])
                ->get();

                if( $platformReceipt ){
                    foreach( $platformReceipt as $ar ){
                        $reportArr = explode( "|", $ar->url );
                        $url = "https://".$platform_account->api_domain.".veracore.com/veracore/Public.Api/api/reports/$reportArr[0]/status";
                        $post_data = [];
                        $headers = [
                            'Authorization: Bearer '.$this->VeracoreApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' )
                        ];

                        $response = $this->VeracoreApi->makeAPICall( $url, "GET", $post_data, $headers );
                        if( $response ){
                            $response = array_change_key_case($response, CASE_LOWER);
                            $platformReceipt = PlatformUrl::find( $ar->id );
                            if( isset( $response['status'] ) && $response['status'] === "Done" ) {
                                $platformReceipt->status = 1;//Done
                                $platformReceipt->response = $response['status'];
                            } else if( isset( $response['error'] ) && $response['error'] == "Task Id $reportArr[0] for User Id burjus was not found." ){
                                $platformReceipt->status = 3;//Not Found
                                $platformReceipt->response = $response['error'];
                            } else if( isset( $response['error'] ) ) {
                                $platformReceipt->status = 4;//Failed
                                $platformReceipt->response = $response['error'];
                                Log::error( $user_integration_id."-- ExecuteEventVeracore createExpectedArriavalReportStep2 --> ".$response['error'] );
                                $return_response = false;
                            }
                            $platformReceipt->save();
                        }
                    }
                }
            }
        } catch ( Exception $e) {
            Log::error( $user_integration_id."-- ExecuteEventVeracore createExpectedArriavalReportStep2 -->".$e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * fetch expected arrival report status response Step 3:
     */
    public function createExpectedArriavalReportStep3( $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->VeracoreApi->getAccountDetails( $user_integration_id ); // get the account information for the integration
            if( $platform_account ){

                $platformReceipt = PlatformUrl::where([
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'url_name' => 'ExpectedArriavalReport',
                    'status' => 1,//Done
                ])
                ->get();

                if( $platformReceipt ){
                    foreach( $platformReceipt as $pr ){
                        $reportArr = explode( "|", $pr->url );
                        $url = "https://".$platform_account->api_domain.".veracore.com/veracore/Public.Api/api/reports/".$reportArr[0];
                        $post_data = [];

                        $headers = [
                            'Authorization: Bearer '.$this->VeracoreApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' )
                        ];

                        $response = $this->VeracoreApi->makeAPICall( $url, "GET", $post_data, $headers );

                        Storage::append( 'Veracore/'.$user_integration_id.'/createExpectedArriavalReportStep3/'.date('d-m-Y').'.txt', "[".date( 'H:i:s' )."] Curl Response: ".json_encode( $response ) );
                        if( $response ){
                            $response = array_change_key_case($response, CASE_LOWER);

                            if( isset( $response['data'] ) ) {// use chunk with json file (s3)
                                foreach( $response['data'] as $k=>$ar ){
                                    
                                    if( false ){
                                        //check order details exist?
                                        $order = PlatformOrder::where([
                                            'user_integration_id' => $user_integration_id,
                                            'platform_id' => $this->platformId,
                                            'order_type' => 'POR',
                                            'api_order_id' => $ar['Expected Arrival Foreign System Key'],
                                            'order_number' => $ar['Client Purchase Order']
                                        ])
                                        ->first();

                                        if( !$order ){
                                            $order = new PlatformOrder();

                                            $order->user_id = $user_id;
                                            $order->user_integration_id = $user_integration_id;
                                            $order->platform_id = $this->platformId;
                                            $order->order_type = 'POR';
                                            $order->api_order_id = $ar['Expected Arrival Foreign System Key'];
                                            $order->order_number = $ar['Client Purchase Order'];
                                            $order->sync_status = PlatformStatus::READY;
                                        }

                                        $order->notes = $ar['Comments'];
                                        $order->order_date = $ar['Date / Time Entered'];
                                        $order->delivery_date = $ar['Anticipated Arrival Date / Time'];
                                        $order->shipment_status = PlatformStatus::READY;
                                        $order->save();

                                        //check order line details exist?
                                        if( false ){
                                            $orderLine = PlatformOrderLine::where([
                                                'platform_order_id' => $order->id,
                                                'api_order_line_id' => $order->api_order_id,
                                                'api_product_id' => $ar['Product ID'],
                                            ])
                                            ->first();

                                            if( !$orderLine ){
                                                $orderLine = new PlatformOrderLine();

                                                $orderLine->platform_order_id = $order->id;
                                                $orderLine->api_order_line_id = $order->api_order_id;
                                                $orderLine->api_product_id = $ar['Product ID'];
                                                $orderLine->product_name = $ar['Product Description'];
                                            }

                                            $orderLine->qty = $ar['Expected Arrival Expected Quantity'];
                                            $orderLine->row_type = "ITEM";
                                            $orderLine->save();
                                        }
                                    }
                                    
                                    $api_order_id = $ar['Client Purchase Order'];
                                    $order = PlatformOrder::where([
                                        'user_integration_id' => $user_integration_id, 
                                        'platform_id' => $this->platformId, 
                                        'api_order_id' => $api_order_id, 
                                        'order_type' => 'PO'
                                    ])
                                    ->select('id', 'sync_status', 'api_order_id', 'linked_id', 'shipment_status')
                                    ->first();

                                    if( isset( $order ) ) {
                                        //check order shipment details exist?
                                        $orderShipment = PlatformOrderShipment::where([
                                            'user_integration_id' => $user_integration_id,
                                            'platform_id' => $this->platformId,
                                            'shipment_id' => $ar['Expected Arrival Foreign System Key'],
                                            'order_id' => $ar['Client Purchase Order'],
                                        ])
                                        ->first();

                                        if( !$orderShipment ){
                                            $orderShipment = new PlatformOrderShipment();

                                            $orderShipment->user_id = $user_id;
                                            $orderShipment->user_integration_id = $user_integration_id;
                                            $orderShipment->platform_id = $this->platformId;
                                            $orderShipment->shipment_id = $ar['Expected Arrival Foreign System Key'];
                                            $orderShipment->platform_order_id = $order->id;
                                            $orderShipment->order_id = $ar['Client Purchase Order'];
                                            $orderShipment->shipping_method = $ar['Shipping Method'];
                                            $orderShipment->sync_status = PlatformStatus::READY;
                                        }

                                        $orderShipment->save();

                                        //check order shipment lines detail exist?
                                        $orderShipmentLine = PlatformOrderShipmentLine::where([
                                            'platform_order_shipment_id' => $orderShipment->id,
                                            'row_id' => $ar['Expected Arrival Foreign System Key'],
                                            'product_id' => $ar['Product ID'],
                                        ])
                                        ->first();

                                        if( !$orderShipmentLine ){
                                            $orderShipmentLine = new PlatformOrderShipmentLine();
                                            $orderShipmentLine->platform_order_shipment_id = $orderShipment->id;
                                            $orderShipmentLine->row_id = $ar['Expected Arrival Foreign System Key'];
                                            $orderShipmentLine->product_id = $ar['Product ID'];
                                            $orderShipmentLine->sync_status = PlatformStatus::READY;
                                        }

                                        $orderShipmentLine->quantity = (int)$ar['Expected Arrival Quantity Received'];//$ar['Expected Arrival Expected Quantity'];
                                        // $orderShipmentLine->sent_quantity = (int)$ar['Expected Arrival Quantity Received'];
                                        $orderShipmentLine->save();

                                        if ( $order->shipment_status != PlatformStatus::SYNCED ) {
                                            $order->shipment_status = PlatformStatus::READY;
                                        }

                                        $order->save();
                                    }

                                }
                                
                                $platformReceiptUpdate = PlatformUrl::find( $pr->id );
                                $platformReceiptUpdate->status = 2;//Sync
                                $platformReceiptUpdate->allow_retain = 1;
                                $platformReceiptUpdate->save();
                            } else {
                                // Log::error( $user_integration_id."-- ExecuteEventVeracore createExpectedArriavalReportStep3 --> ".$response['error'] ?? json_encode( $response ) );
                                $return_response = false;
                            }
                        }
                    }
                }
            }
        } catch ( Exception $e) {
            Log::error( $user_integration_id."-- ExecuteEventVeracore createExpectedArriavalReportStep3 -->".$e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * Create expected arrival report Step 1:
     */
    public function getWareHouseLists( $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->VeracoreApi->getAccountDetails( $user_integration_id );  // get the account information for the integration

            if( $platform_account ){

                $warehouseOrderObject = $this->VeracoreApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);

                if($warehouseOrderObject)
                {
                    //revert object data status
                    PlatformObjectData::where([
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'platform_object_id' => $warehouseOrderObject->id,
                    ])
                    ->update(['status' => 0]);

                    $warehouses = [
                        "XFWHSE" => 'Burju Shoes',
                    ];

                    foreach( $warehouses as $key=>$status )
                    {
                        $name = ucfirst( strtolower( str_ireplace( "_", " ", $status ) ) );
                        $dataArr = [
                            'user_id' => $user_id,
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'platform_object_id' => $warehouseOrderObject->id,
                            'api_id' => $key,
                            'name' => $name,
                            'api_code' => Strtoupper( $status ),
                            'description' => $status,
                            'status' => 1
                        ];

                        $platform_object_data = $this->VeracoreApi->MainModel->getFirstResultByConditions('platform_object_data',
                            [
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $user_integration_id,
                                'platform_object_id' => $warehouseOrderObject->id,
                                'api_id' => $key,
                                'api_code' => Strtoupper( $status ),
                            ],
                            ['id']
                        );

                        if($platform_object_data) {
                            $this->VeracoreApi->MainModel->makeUpdate('platform_object_data', $dataArr, ['id'=>$platform_object_data->id]);
                        } else {
                            $this->VeracoreApi->MainModel->makeInsert('platform_object_data', $dataArr);
                        }
                    }
                }
            }
        } catch ( Exception $e) {
            Log::error( $user_integration_id."-- ExecuteEventVeracore createExpectedArriavalReportStep1 -->".$e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * Create Valuation Report Step 1:
     * 1: Proccessing
     * 2: Synced
     * 3: Init Proccessing
     * 4: Init Synced
     */
    public function createValuationReport( $user_id, $user_integration_id, $is_initial_sync=false ){
        date_default_timezone_set('UTC');
        $return_response = true;
        try{
            $platform_account = $this->VeracoreApi->getAccountDetails( $user_integration_id );  // get the account information for the integration

            if( $platform_account ){
                
                $platformReceipt = null;
                $startDate = "01-01-1970";
                $endDate = date( 'm/d/Y' );
                if( $is_initial_sync ){
                    $platformReceipt = PlatformUrl::where([
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'ValuationReport',
                        // 'status' => 4,//Init Sync
                    ])
                    ->whereIn( 'status', [ 3, 4 ] )//1: Processing, 3: Init Processing
                    ->orderBy( 'id', 'desc' )
                    ->first();

                    if( $platformReceipt && $platformReceipt->url != "" ){
                        $explodeArr = explode( "|", $platformReceipt->url );

                        if( strtotime( $startDate ) == strtotime( $explodeArr[1] ) ){
                            return $return_response;
                        }

                        $startDate = $explodeArr[1];
                        $endDate = $explodeArr[2];
                    }
                } else {
                    $date = new DateTime();
                    $startDate = $date->modify( '-1 day' )->format('m/d/Y');
                }

                $reportName = "Valuation Report";
                $url = "https://".$platform_account->api_domain.".veracore.com/VeraCore/Public.Api/api/reports";
                $post_data = [
                    "reportName" => $reportName,
                    "filterCriteria" => [
                        [
                            "filterColumnName" => "Product Date Created",
                            "startDate" => $startDate,
                            "endDate" => $endDate,
                        ]
                    ]
                ];

                $headers = [
                    'Authorization: Bearer '.$this->VeracoreApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' )
                ];

                $response = $this->VeracoreApi->makeAPICall( $url, "POST", $post_data, $headers );
                Storage::append( 'Veracore/'.$user_integration_id.'/createValuationReport/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $response ) );

                if( $response ){
                    $response = array_change_key_case( $response, CASE_LOWER );

                    if( isset( $response['taskid'] ) ) {

                        $platformReceipt = new PlatformUrl();
                        $platformReceipt->user_id = $user_id;
                        $platformReceipt->platform_id = $this->platformId;
                        $platformReceipt->user_integration_id = $user_integration_id;
                        $platformReceipt->status = 1;//Processing
                        
                        if( $is_initial_sync ){
                            $platformReceipt->status = 3;//Init Processing
                        } 

                        $platformReceipt->url = $response['taskid'].'|'.$startDate.'|'.$endDate;
                        $platformReceipt->url_name = 'ValuationReport';
                        $platformReceipt->url_filter = json_encode( $post_data );
                        $platformReceipt->save();
                    } else if( isset( $response['message'] ) ){
                        Log::error( $user_integration_id."-- ExecuteEventVeracore createValuationReportStep1 --> ".$response['message'] );
                        $return_response = false;
                    }
                }
            }
        } catch ( Exception $e) {
            Log::error( $user_integration_id."-- ExecuteEventVeracore createValuationReportStep1 -->".$e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * fetch Valuation Report Step 2:
     * 1: Proccessing
     * 2: Synced
     * 3: Init Proccessing
     * 4: Init Synced
     */
    public function getValuationProducts( $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->VeracoreApi->getAccountDetails( $user_integration_id ); // get the account information for the integration
            if( $platform_account ){

                $productValuationReport = PlatformUrl::where([
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'url_name' => 'ValuationReport',
                    // 'status' => 1,//Proccessing
                ])
                ->whereIn( 'status', [1, 3] )//1: Processing, 3: Init Processing
                ->limit( 10 )
                ->get();

                Storage::append( 'Veracore/'.$user_integration_id.'/getValuationProducts/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".COUNT( $productValuationReport ) );

                if( $productValuationReport ){

                    //check & create customer as vendor
                    // $vendorObj = PlatformCustomer::where([
                    //     'user_id' => $user_id,
                    //     'user_integration_id' => $user_integration_id,
                    //     'platform_id' => $this->platformId,
                    //     'api_customer_id' => 'unknown',
                    // ])
                    // ->first();

                    // if( !$vendorObj ){
                    //     $vendorObj = new PlatformCustomer();
                    //     $vendorObj->user_id = $user_id;
                    //     $vendorObj->user_integration_id = $user_integration_id;
                    //     $vendorObj->platform_id = $this->platformId;
                    //     $vendorObj->api_customer_id = 'unknown';
                    //     $vendorObj->api_customer_code = 'veracore';
                    //     $vendorObj->customer_name = 'unknown';
                    //     $vendorObj->sync_status = PlatformStatus::READY;
                    //     $vendorObj->save();
                    // }

                    foreach( $productValuationReport as $pr ){
                        $reportArr = explode( "|", $pr->url );
                        $url = "https://".$platform_account->api_domain.".veracore.com/veracore/Public.Api/api/reports/".$reportArr[0];
                        $post_data = [];

                        $headers = [
                            'Authorization: Bearer '.$this->VeracoreApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' )
                        ];

                        $response = $this->VeracoreApi->makeAPICall( $url, "GET", $post_data, $headers );
                        Storage::append( 'Veracore/'.$user_integration_id.'/getValuationProducts/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $post_data ) );

                        if( $response ){
                            $response = array_change_key_case($response, CASE_LOWER);
                            $startDate = date( 'm/d/Y' );
                            if( isset( $response['data'] ) ) {

                                $productData = [];
                                if( $pr->status == 3 ){
                                    $productData = array_slice( $response['data'], 0, 4000 );
                                } else {
                                    $productData = $response['data'];
                                }
                                
                                foreach( $productData as $ar ){
                                        
                                    $platform_product = PlatformProduct::where([
                                        'user_integration_id' => $user_integration_id,
                                        'platform_id' => $this->platformId,
                                        'api_product_id' => $ar['Product ID'],
                                    ])
                                    ->first();

                                    if( !$platform_product ){
                                        $platform_product = new PlatformProduct();

                                        $platform_product->user_id = $user_id;
                                        $platform_product->user_integration_id = $user_integration_id;
                                        $platform_product->platform_id = $this->platformId;
                                        $platform_product->api_product_id = $ar['Product ID'];
                                        $platform_product->product_sync_status = PlatformStatus::READY;
                                    }

                                    $platform_product->api_variant_id = $ar['Product ID'];
                                    $platform_product->sku = $ar['Product ID'];
                                    $platform_product->product_name = $ar['Product Description'];
                                    $platform_product->description = $ar['Product Description'];
                                    $platform_product->price = $ar['Product Default Value'];
                                    $startDate = date( 'Y-m-d', strtotime( $ar['Product Date Created'] ) );
                                    $platform_product->api_created_at = $startDate;
                                    $platform_product->api_updated_at = $startDate;
                                    $platform_product->product_status = 1;
                                    $platform_product->save();

                                    $this->VeracoreApi->createPriceList( $platform_product->id, 'cost_price', $ar['Product Default Value'] );
                                    // $this->VeracoreApi->createPricesList( $user_id, $user_integration_id, $platform_product->id, $ar );

                                    $platform_product_inventory = PlatformProductInventory::where([
                                        'user_integration_id' => $user_integration_id,
                                        'platform_id' => $this->platformId,
                                        'api_product_id' => $ar['Product ID'],
                                        'platform_product_id' => $platform_product->id,
                                    ])
                                    ->first();

                                    if( !$platform_product_inventory ){
                                        $platform_product_inventory = new PlatformProductInventory();

                                        $platform_product_inventory->user_id = $user_id;
                                        $platform_product_inventory->user_integration_id = $user_integration_id;
                                        $platform_product_inventory->platform_id = $this->platformId;
                                        $platform_product_inventory->api_product_id = $ar['Product ID'];
                                        $platform_product_inventory->platform_product_id = $platform_product->id;
                                        $platform_product_inventory->sync_status = PlatformStatus::READY;
                                    }
                                    $platform_product_inventory->quantity = $ar['Product On Hand Quantity'];
                                    $platform_product_inventory->api_updated_at = date( 'Y-m-d', strtotime( $ar['Product Date Created'] ) );
                                    $platform_product_inventory->save();
                                }

                                $platformURL = PlatformUrl::find( $pr->id );

                                if( $pr->status == 3 ){//Init Processing
                                    $explodeArr = explode( "|", $platformURL->url );
                                    $taskid = $explodeArr[0];
                                    $endDate = $explodeArr[2];
                                    $platformURL->url = $taskid.'|'.date( 'm/d/Y', strtotime( $startDate ) ).'|'.$endDate;
                                    $platformURL->status = 4;//Init Sync
                                    $platformURL->allow_retain = 0;
                                } else {
                                    $platformURL->status = 2;//Sync
                                    $platformURL->allow_retain = 1;
                                }
                                
                                $platformURL->save();
                            } else {
                                Log::error( $user_integration_id."-- ExecuteEventVeracore getValuationProductsStep2 --> ".$response['error'] );
                                $return_response = false;
                            }
                        }
                    }
                }
            }
        } catch ( Exception $e) {
            Log::error( $user_integration_id."-- ExecuteEventVeracore getValuationProductsStep2 -->".$e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * 
     */
    public function updateProducts( $user_id, $user_integration_id ){

        //797
        $username = 'Tmeo3MGpyDiQVZcRaMSSDkN4nQ7l9R13';//RVpRRXUzcTgxL1lZZjlreFJwTEt6b2Q2U3prNW8vQVR4TVNlUHlSNDl1YUpXQlc0L0UyVVJpd0pRVUFwL1ZsdA==
        $password = 'syxF9dwdqcrql8IY3GlxnuaeMg1sefOu';//cyt6YzNOQlhXd1RucllZcWxOTlZWWlhLRmFFTndDVTZWRHVCT1VWZlpLdTZhYzA4MUNpdS8xRTR5cGg4SUlXMQ==

        echo $this->VeracoreApi->MainModel->encrypt_decrypt( $username, 'encrypt' )."<br><br>";
        echo $this->VeracoreApi->MainModel->encrypt_decrypt( $password, 'encrypt' );
        die;
        $productArr = PlatformProduct::where( [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'product_sync_status' => 'Ready'
        ] )
        ->select( 'id', 'api_product_id', 'sku' )
        // ->limit( 3000 )
        ->get();

        foreach( $productArr as $k=>$pr ){
            echo $k." - ".$pr->id." : ".$pr->api_product_id."<br>";
            PlatformProduct::where( 'id', $pr->id )->update( [
                'sku' => $pr->api_product_id
            ] );
        }
    }

    /**
     *
     */
    public function deleteProductEntry( Request $request ){
        return true;
        echo "Start: ".date( "h:i:s" )."<br>";
        $integrationId = (int)$request->intid ?? 0;
        $productArr = DB::select("SELECT id FROM platform_product WHERE `user_integration_id` = $integrationId LIMIT 2500");
        foreach( $productArr as $k=>$ar ){
            
            //delete platform_product_inventory record
            PlatformProductInventory::where( 'platform_product_id', $ar->id)->delete();

            //delete platform_porduct_price_list record
            PlatformProductPriceList::where( 'platform_product_id', $ar->id)->delete();

            //delete platform product record
            PlatformProduct::where( 'id', $ar->id )->delete();
            echo $k." : ".$ar->id."<br>";
        }
        echo "End: ".date( "h:i:s" );
    }

    /**
     *
     */
    public function deletePlatformUrl( Request $request ){
        return true;
        $integrationId = (int)$request->intid ?? 0;
        //delete platform_porduct_price_list record
        PlatformUrl::where( 'user_integration_id', $integrationId)->delete();
    }
}
