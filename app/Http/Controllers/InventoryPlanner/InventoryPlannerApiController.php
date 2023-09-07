<?php
namespace App\Http\Controllers\InventoryPlanner;

use App\Exports\InventoryPlannerOrderXLS;
use App\Exports\InventoryPlannerProductXLS;
use App\Exports\InventoryPlannerProductStockXLS;

use Exception;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\PlatformOrder;
use App\Models\PlatformAccount;
use App\Models\UserWorkflowRule;
use App\Models\PlatformCustomer;
use App\Helper\ConnectionHelper;
use App\Models\PlatformOrderLine;
use App\Models\PlatformObjectData;
use App\Models\Enum\PlatformStatus;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Lang;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderShipment;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformOrderAdditionalInformation;
use App\Http\Controllers\InventoryPlanner\Api\InventoryPlannerApi;
use App\Models\PlatformProduct;
use App\Models\PlatformProductInventory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\File;

class InventoryPlannerApiController extends Controller
{
    public $InventoryPlannerApi = '';
    public $InventoryPlannerService = '';
    public $ConnectionHelper = '';
    public $platformId = '';

    public static $myPlatform = 'inventoryplanner';

    /*
     * 
     */
    public function __construct()
    {
        $this->InventoryPlannerApi = new InventoryPlannerApi();
        $this->InventoryPlannerService = new InventoryPlannerService();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
    }

    /*
     * 
     */
    public function InitiateInventoryPlannerAuth(Request $request)
    {
        $platform = 'InventoryPlanner';
        return view("pages.apiauth.inventoryplanner_auth", compact('platform'));
    }

    /*
     * 
     */
    public function ConnectInventoryPlanner(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account_name' => 'required',
            'user_name' => 'required',//as a account id
            'user_password' => 'required',// as a account key
            'api_domain' => 'required',// as a account key
        ]);

        if($this->InventoryPlannerApi->MainModel->checkHtmlTags( $request->all()) ) {
            $data['error'] = Lang::get('tags.validate');
            return response()->json( $data, 200 );
        }

        if($validator->fails()) {
            return response()->json( $validator->messages(), 200 );
        } else {
            $user_data =  Session::get('user_data');
            $user_id =  $user_data['id'];

            $account_name = trim( $request->account_name );
            $user_name = trim( $request->user_name );
            $app_secret = trim( $request->user_password );
            $api_domain = trim( $request->api_domain );

            $authDetails = array();
            $authDetails['api_domain'] = $api_domain;
            $authDetails['app_id'] = $user_name;
            $authDetails['app_secret'] = $app_secret;

            $url = $api_domain.$this->InventoryPlannerApi->ApiVersion."/vendors?limit=1";
            $response = $this->InventoryPlannerApi->CheckAPIResponse( $url, $authDetails, 'GET', [], true );
            // Storage::append('InventoryPlanner/' . $authDetails['app_id'] . '/ConnectInventoryPlanner/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: Result: " . json_encode( $response ) );

            if ( $response['api_status'] == 'success' ) {
                $account = PlatformAccount::where([
                        'user_id' => $user_id,
                        'platform_id' => $this->platformId,
                        'account_name' => $account_name
                    ])->first();

                if ( !$account ) {
                    $account = new PlatformAccount();
                }

                $account->access_token = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $app_secret, 'encrypt' );
                $account->app_id = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $user_name, 'encrypt' );
                $account->app_secret = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $app_secret, 'encrypt' );
                $account->account_name = $account_name;
                $account->api_domain = $api_domain;
                $account->user_id = $user_id;
                $account->platform_id = $this->platformId;
                $account->expires_in = 0;
                $account->token_refresh_time = time();
                $account->allow_refresh = 0;
                $account->save();

                $data['success'] = "Successfully Connected";
                return response()->json( $data, 200 );
            }else{
                $data['error'] = "Sign-in information is incorrect";
                return response()->json( $data, 200 );
            }
        }
    }

    /**
     *
     * @return void
     */
    public function getWareHouseLists( $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->InventoryPlannerService->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if($platform_account)
            {
                $platform_account = (array)$platform_account;
                $platform_account['app_id'] = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $platform_account['app_id'], 'decrypt' );
                $platform_account['app_secret'] = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $platform_account['app_secret'], 'decrypt' );
                
                $url = $platform_account['api_domain'].$this->InventoryPlannerApi->ApiVersion."/warehouses";
                $response = $this->InventoryPlannerApi->CheckAPIResponse( $url, $platform_account );
                
                Storage::append( 'InventoryPlanner/'.$user_integration_id.'/getWareHouseLists/'.date('d-m-Y').'.txt', "[".date( 'H:i:s' )."]: ".json_encode( $response['warehouses'] ) );

                if (isset($response['api_status']) && $response['api_status'] == "success") {
                    $response = $response['warehouses'];
                    $wareHouseObject = $this->InventoryPlannerApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);

                    //revert object data status
                    PlatformObjectData::where([
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'platform_object_id' => $wareHouseObject->id,
                    ])
                    ->update(['status' => 0]);

                    foreach( $response as $ar ){
                        $platformObjData = PlatformObjectData::where([
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'platform_object_id' => $wareHouseObject->id,
                            'api_id' => $ar['name'],
                        ])
                        ->first();

                        if( !$platformObjData ){
                            $platformObjData = new PlatformObjectData();
                            $platformObjData->user_id = $user_id;
                            $platformObjData->platform_id = $this->platformId;
                            $platformObjData->user_integration_id = $user_integration_id;
                            $platformObjData->platform_object_id = $wareHouseObject->id;
                            $platformObjData->api_id = $ar['name'];
                        }

                        $platformObjData->name = $ar['display_name'];
                        $platformObjData->api_code = $ar['connections'][0]['connection_id'];
                        // $platformObjData->description = $ar['Description'];
                        $platformObjData->status = 1;
                        $platformObjData->save();
                    }
                }
            }
        } catch( Exception $e ) {
            Log::error( $user_integration_id . ' - InventoryPlannerApiController - getWareHouseLists - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    
    /**
     * 
     */
    public function GetOrders( $user_id, $user_integration_id, $user_workflow_rule_id=1587, $platform_workflow_rule_id=257, $order_type='PO' ){
        set_time_limit(0);
        $return_response = true;
        // echo $this->InventoryPlannerApi->MainModel->encrypt_decrypt( "5f0a3aa0c19d9782ed5c13871d0220f5d5f7a2c384323b87252c3712b4a36ae6", 'encrypt' );
        // echo "<br>".$this->InventoryPlannerApi->MainModel->encrypt_decrypt( 'a16551', 'encrypt' );die;
        try {

            $platform_account = $this->InventoryPlannerService->getAccountDetails( $user_integration_id ); // get the account information for the integration
            
            if ($platform_account) {

                // Getting most recent transfer order date to fetch further new orders afer this perticular order's date
                $is_url = $this->InventoryPlannerApi->MainModel->getFirstResultByConditions(
                    'platform_urls',
                    [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'last_trans_' . $order_type . '_date'
                    ],
                    ['url', 'id', 'status']
                );

                $last_updated_at = date('Y-m-d H:i:s', time());
                if ( $is_url && $is_url->status == 1 && $is_url->url != "") {
                    $last_updated_at = $is_url->url;
                } else {
                    $get_workflow_events = UserWorkflowRule::where( 'id', $user_workflow_rule_id )->select( 'sync_start_date' )->first();
                    if ( isset( $get_workflow_events->sync_start_date ) ) {
                        $last_updated_at = date('Y-m-d H:i:s', strtotime($get_workflow_events->sync_start_date));
                    }
                }

                $defaultOrderWareHouse = $this->InventoryPlannerService->getDefaultOrderWarehouse( $user_integration_id );
                
                $api_order_type = strtolower( ( $order_type == 'TO' ) ? 'transfer' : 'po' );
                $limit = 50;
                $platform_account = (array)$platform_account;
                $platform_account['app_id'] = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $platform_account['app_id'], 'decrypt' );
                $platform_account['app_secret'] = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $platform_account['app_secret'], 'decrypt' );
                
                $url = $platform_account['api_domain'].$this->InventoryPlannerApi->ApiVersion."/purchase-orders?type=$api_order_type&created_date=$last_updated_at&limit=$limit&warehouse_in=".$defaultOrderWareHouse['api_id'];
                $response = $this->InventoryPlannerApi->CheckAPIResponse( $url, $platform_account );
                Storage::append( 'InventoryPlanner/'.$user_integration_id.'/get'.$order_type.'Details/'.date('d-m-Y').'.txt', "[".date( 'H:i:s' )."]: UR: ".$url.", Response: ".json_encode( $response['purchase-orders'] ) );
                
                if ( isset( $response['api_status'] ) && $response['api_status'] == "success" ) {
                    $orders = $response['purchase-orders'];
                    
                    if ( count( $orders ) ) {
                        $recentToDate = null;
                        $platformOrderId = null;
                        $warehouse_object_id = $this->InventoryPlannerApi->ConnectionHelper->getObjectId('warehouse');
                                                
                        foreach ($orders as $ord) {
                        
                            $recentToDate = date( 'Y-m-d h:i:s', strtotime( $ord['last_modified'] ) );

                            $ord_number = $ord['id'];

                            /** Section: Order [start] */
                            $orderObj = PlatformOrder::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'api_order_id' => $ord_number,
                                'user_workflow_rule_id' => $user_workflow_rule_id,
                                'order_type' => $order_type,
                            ])
                            ->first();

                            if (!$orderObj) {
                                $orderObj = new PlatformOrder();

                                $orderObj->api_order_id = $ord_number;
                                $orderObj->user_id = $user_id;
                                $orderObj->user_integration_id = $user_integration_id;
                                $orderObj->platform_id = $this->platformId;
                                $orderObj->order_type = $order_type;
                                $orderObj->user_workflow_rule_id = $user_workflow_rule_id;
                                $orderObj->order_number = $ord_number;
                                $orderObj->api_order_reference = $ord['reference'];
                                $orderObj->currency = $ord['currency'];
                                $orderObj->order_date = date( 'Y-m-d h:i:s', strtotime( $ord['created_at'] ) );
                                $orderObj->order_updated_at = date( 'Y-m-d h:i:s', strtotime( $ord['updated_at'] ) );
                                $orderObj->sync_status = PlatformStatus::READY;

                                // Cretae/Update vendor information in Database and link its id in Order record
                                if ( isset( $ord['vendor'] ) ) { // VENDOR (vendor name)
                                    $condition = [
                                        'user_id' => $user_id,
                                        'user_integration_id' => $user_integration_id,
                                        'platform_id' => $this->platformId,
                                        'customer_name' => trim( strtolower( $ord['vendor'] ) ),
                                        'type' => 'Vendor',
                                    ];

                                    $vendor_info = PlatformCustomer::where( $condition )
                                        ->select('id', 'email', 'postal_addresses')
                                        ->first();

                                    if ( !$vendor_info ) {
                                        // If vendor not found, create a new instanse
                                        $vendor_info = new PlatformCustomer();
                                        $vendor_info->api_customer_id = trim( $ord['vendor'] );
                                        $vendor_info->customer_name = trim( strtolower( $ord['vendor'] ) );
                                        $vendor_info->user_id = $user_id;
                                        $vendor_info->user_integration_id = $user_integration_id;
                                        $vendor_info->platform_id = $this->platformId;
                                        $vendor_info->type = 'Vendor';
                                        $vendor_info->sync_status = PlatformStatus::READY;
                                    }
                                    
                                    $vendor_info->email = $ord['email'] ?? '';
                                    $vendor_info->address1 = $ord['vendor_address'] ?? '';
                                    $vendor_info->postal_addresses = $ord['vendor_address'] ?? '';
                                    $vendor_info->save();

                                    if (isset($vendor_info->id)) {
                                        $orderObj->platform_customer_id = $vendor_info->id; // VENDOR (platform customer id)
                                    }
                                }
                            } else {
                                $platformOrderId = $orderObj->id;
                            }

                            $dest_warehouse_id = $source_warehouse_id = null;
                            $dest_warehouse = $ord['warehouse']; // WAREHOUSE (destination warehouse)
                            
                            if ( $dest_warehouse ) { //get store actual warehouse detail in platform_object_data table and return primary id
                                $dest_warehouse_id = $this->InventoryPlannerService->GetOrderWarehouse( $dest_warehouse, $user_id, $user_integration_id, $warehouse_object_id );
                            }
                            
                            if ($order_type == 'PO') {
                                $orderObj->order_status = $ord['status']; // STATUS (order status)
                                $orderObj->warehouse_id =  $dest_warehouse_id; // WAREHOUSE
                                // $orderObj->delivery_date = (isset($ord[10]) && $ord[10]) ? date('Y-m-d h:i:s', $ord[10]) : NULL; // EXPECTED_DATE
                                $orderObj->shipping_method = $ord['shipment_method'] ?? ''; // SHIPMENT_METHOD
                            }

                            $orderObj->save();
                            $platformOrderId = $orderObj->id;

                            /** Section: Order [end] */
                            if ($platformOrderId) {
                                $extraCount = PlatformOrderAdditionalInformation::where('platform_order_id', $platformOrderId)->count();
                                if (!$extraCount) {
                                    $pay_term = 1;
                                    if ($pay_term) {
                                        PlatformOrderAdditionalInformation::create(['platform_order_id' => $platformOrderId, 'pay_terms' => $pay_term])->count();
                                    }
                                }

                                /** 
                                 * Section: Order Address [start] 
                                 * TO billing address
                                 **/
                                $orderBillAddr = PlatformOrderAddress::where([
                                    'platform_order_id' => $platformOrderId,
                                    'address_type' => 'billing'
                                ])
                                ->select('id')
                                ->first();

                                if (!$orderBillAddr) {
                                    $orderBillAddr = new PlatformOrderAddress();
                                    $orderBillAddr->platform_order_id = $platformOrderId;
                                    $orderBillAddr->address_type = 'billing';
                                }

                                $orderBillAddr->address1 = $ord['billing_address'] ?? ''; // BILLING_ADDRESS
                                $orderBillAddr->save();

                                // TO shipping address
                                $orderShipAddr = PlatformOrderAddress::where([
                                    'platform_order_id' => $platformOrderId,
                                    'address_type' => 'shipping'
                                ])
                                ->select('id')
                                ->first();

                                if (!$orderShipAddr) {
                                    $orderShipAddr = new PlatformOrderAddress();
                                    $orderShipAddr->platform_order_id = $platformOrderId;
                                    $orderShipAddr->address_type = 'shipping';
                                }

                                $orderShipAddr->address1 = $ord['shipping_address'] ?? ''; // SHIPPING_ADDRESS
                                $orderShipAddr->save();
                                /** Section: Order Address [end] */

                                if ($order_type == 'TO') {

                                    $source_warehouse = $ord['source_warehouse']; // SOURCE_WAREHOUSE
                                    if ($source_warehouse) {
                                        $source_warehouse_id = $this->InventoryPlannerService->GetOrderWarehouse($source_warehouse, $user_id, $user_integration_id, $warehouse_object_id);
                                    }

                                    /** 
                                     * Section: Order Shipment [start] 
                                     **/
                                    $shipmentObj = PlatformOrderShipment::where('platform_order_id', $platformOrderId)->select('id')->first();
                                    
                                    if (!$shipmentObj) {
                                        $shipmentObj = new PlatformOrderShipment();

                                        $shipmentObj->user_id = $user_id;
                                        $shipmentObj->user_integration_id = $user_integration_id;
                                        $shipmentObj->platform_id = $this->platformId;
                                        $shipmentObj->platform_order_id = $platformOrderId;
                                        $shipmentObj->type = "Transfer";
                                        $shipmentObj->sync_status = PlatformStatus::READY;
                                        //$shipmentObj->shipment_id = (isset($ord[3]) && $ord[3]) ? $ord[3] : NULL; // REFERENCE-2
                                        $shipmentObj->shipment_id = trim( $ord['reference'] ); // REFERENCE
                                    }

                                    $shipmentObj->order_id = $ord_number; // ID (order number)
                                    $shipmentObj->shipment_status = $ord['status']; // STATUS (shipment status)
                                    $shipmentObj->to_warehouse_id = $dest_warehouse_id; // WAREHOUSE (destination warehouse)
                                    $shipmentObj->warehouse_id = $source_warehouse_id; // SOURCE_WAREHOUSE
                                    $shipmentObj->created_on = date('Y-m-d h:i:s', strtotime( $ord['created_at'] ) ); // CREATED_DATE
                                    $shipmentObj->realease_date = date('Y-m-d h:i:s', strtotime( $ord['expected_date'] ) ); // EXPECTED_DATE
                                    $shipmentObj->shipping_method = $ord['shipment_method'] ?? ''; // SHIPMENT_METHOD

                                    $shipmentObj->save();

                                    /** Section: Order Shipment [end] */

                                    /** Section: Order Shipment lines [start] */
                                    if ($shipmentObj->id) {

                                        $orderItems = $ord['items'];
                                        foreach( $orderItems as $item ){
                                            $order_line_id = $ord['id']; // LINE_ITEM_ID
    
                                            // PO line items
                                            $shipmentLineObj = PlatformOrderShipmentLine::where([
                                                'platform_order_shipment_id' => $shipmentObj->id,
                                                'row_id' => $order_line_id,
                                                'product_id' => $item['id'], // VARIANT_ID/Product Id
                                            ])
                                            ->select('id')
                                            ->first();
    
                                            if (!$shipmentLineObj) {
                                                $shipmentLineObj = new PlatformOrderShipmentLine();
                                                $shipmentLineObj->platform_order_shipment_id = $shipmentObj->id;
                                                $shipmentLineObj->row_id = $order_line_id;
                                                $shipmentLineObj->product_id = $item['id']; // VARIANT_ID/Product Id
                                            }
    
                                            $shipmentLineObj->sku = $item['sku']; // SKU
                                            // $shipmentLineObj->barcode = (isset($ord[26]) && $ord[26]) ? $ord[26] : NULL; // BARCODE
                                            $shipmentLineObj->currency = $ord['currency']; // CURRENCY
                                            $shipmentLineObj->price = $item['price']; // COST_PRICE
                                            $shipmentLineObj->warehouse_id = $source_warehouse_id; // SOURCE_WAREHOUSE
                                            $shipmentLineObj->quantity = $item['replenishment']; // REPLENISHMENT (Ordered Qty)
                                            $shipmentLineObj->sent_quantity = $item['sent']; // RECEIVED
                                            $shipmentLineObj->save();
                                        }
                                    }

                                    /** Section: Order Shipment lines [end] */
                                } else if ($order_type == 'PO' || $order_type == 'SO') {

                                    $orderItems = $ord['items'];
                                    foreach( $orderItems as $item ){
                                        $order_line_id = $ord['id']; // LINE_ITEM_ID
    
                                        // PO line items
                                        $orderLineArr = PlatformOrderLine::where([
                                            'platform_order_id' => $orderObj->id,
                                            'api_order_line_id' => $order_line_id,
                                            'api_product_id' => $item['id'], // VARIANT_ID/Product Id
                                        ])
                                        ->select('id')
                                        ->first();
    
                                        if (!$orderLineArr) {
                                            $orderLineArr = new PlatformOrderLine();
                                            $orderLineArr->platform_order_id = $orderObj->id;
                                            $orderLineArr->api_order_line_id = $order_line_id;
                                            $orderLineArr->api_product_id = $item['id']; // VARIANT_ID/Product Id
                                        }
    
                                        $orderLineArr->sku = $item['sku']; // SKU
                                        // $orderLineArr->barcode = (isset($ord[26]) && $ord[26]) ? $ord[26] : NULL; // BARCODE
                                        $orderLineArr->product_name = $item['title']; // TITLE
                                        $orderLineArr->qty = $item['replenishment']; // REPLENISHMENT
                                        $orderLineArr->price = $item['price'] ?? 0; // PRICE
                                        $orderLineArr->total_tax = $item['tax']; // TAX
                                        $orderLineArr->api_code = $item['c_vid'] ?? null; // c_vid
                                        $orderLineArr->notes = $item['product_type'] ?? ''; // product_type
                                        $orderLineArr->save();
                                    }
                                }
                            }
                        }

                        // Update the most recent Transfer order date in PlatformUrl table for the next iteration to fetch TO from Snowflake API
                        if ( $recentToDate ) {
                            if ( $is_url ) {
                                $this->InventoryPlannerApi->MainModel->makeUpdate('platform_urls', [
                                    'url' => $recentToDate
                                ], [
                                    'id' => $is_url->id
                                ]);
                            } else {
                                $this->InventoryPlannerApi->MainModel->makeInsert('platform_urls', [
                                    'user_id' => $user_id,
                                    'user_integration_id' => $user_integration_id,
                                    'platform_id' => $this->platformId,
                                    'url' => $recentToDate,
                                    'url_name' => 'last_trans_' . $order_type . '_date'
                                ]);
                            }
                        }
                    }
                } else {
                    $return_response = "API call error.";
                    if ((isset($response['api_status']) && $response['api_status'] == 0) && isset($response['api_data'])) {
                        $return_response = $response['api_data'];
                    }
                }
            }
        } catch (Exception $e) {
            $return_response = $e->getMessage();
            Log::error($user_integration_id . ' - InventoryPlannerApiController - GetSalesOrders - ' . $return_response);
        }
        return $return_response;
    }

    /**
     * 
     */
    public function createWarehouse( $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->InventoryPlannerService->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if($platform_account)
            {
                $platform_account = (array)$platform_account;
                $platform_account['app_id'] = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $platform_account['app_id'], 'decrypt' );
                $platform_account['app_secret'] = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $platform_account['app_secret'], 'decrypt' );
                
                $postData = [
                    "warehouse" => [
                        "display_name" => "GK Warehouse",
                        "disabled" => false,
                        "kind" => "manual",
                        "ip_warehouse_config" => [],
                        "tracked_inventory" => true,
                        "currency" => "GBP",
                        "name" => "gk_warehouse",
                        "type" => "warehouse"
                    ]
                ];

                $url = $platform_account['api_domain'].$this->InventoryPlannerApi->ApiVersion."/warehouses";
                $response = $this->InventoryPlannerApi->CheckAPIResponse( $url, $platform_account, 'POST', json_encode( $postData ) );
                Storage::append( 'InventoryPlanner/'.$user_integration_id.'/createWareHouse/'.date('d-m-Y').'.txt', "[".date( 'H:i:s' )."]: ".json_encode( $response['warehouse'] ) );

                if (isset($response['api_status']) && $response['api_status'] == "success") {
                    $response = $response['warehouse'];
                    $wareHouseObject = $this->InventoryPlannerApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);

                    $platformObjData = PlatformObjectData::where([
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'platform_object_id' => $wareHouseObject->id,
                        'api_id' => $response['name'],
                    ])
                    ->first();

                    if( !$platformObjData ){
                        $platformObjData = new PlatformObjectData();
                        $platformObjData->user_id = $user_id;
                        $platformObjData->platform_id = $this->platformId;
                        $platformObjData->user_integration_id = $user_integration_id;
                        $platformObjData->platform_object_id = $wareHouseObject->id;
                        $platformObjData->api_id = $response['name'];
                    }

                    $platformObjData->name = $response['display_name'];
                    $platformObjData->api_code = $response['version'];
                    // $platformObjData->description = $response['Description'];
                    $platformObjData->status = 1;
                    $platformObjData->save();
                }
            }
        } catch( Exception $e ) {
            Log::error( $user_integration_id . ' - InventoryPlannerApiController - createWarehouse - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * vendor as a customers, suppliers
     */
    public function CreateVendor($user_id, $user_integration_id, $source_platform_id=0, $source_platform_name='', $user_workflow_rule_id=0, $record_id=0)
    {
        $return_response = true;
        try {
            $platform_account = $this->InventoryPlannerService->getAccountDetails($user_integration_id); // get the account information for the integration
            
            if ($platform_account) {

                if ($record_id) {
                    $where['id'] = $record_id;
                    // $where['sync_status'] = PlatformStatus::FAILED;
                } else {
                    $where['platform_id'] = $source_platform_id;
                    $where['user_integration_id'] = $user_integration_id;
                    $where['sync_status'] = PlatformStatus::READY;
                    $where['is_deleted'] = 0; //new condition added to only pick active customer/verndors
                }

                $vendorArr = PlatformCustomer::where($where)->limit(50)->get();
                
                if ($vendorArr) {
                    $sync_object_id = $this->InventoryPlannerApi->ConnectionHelper->getObjectId('supplier');
                    
                    foreach ($vendorArr as $vendor) {

                        if ( !empty( $vendor->customer_name ) ) {

                            $defaultCurrency = NULL;
                            $currencyFind = $this->InventoryPlannerApi->FieldMappingHelper->getMappedDataByName( $user_integration_id, NULL, "default_currency",  ['custom_data'], "default" );
                            if ($currencyFind) {
                                $defaultCurrency = $currencyFind->custom_data;
                            }

                            $currency = isset($vendor->extraInfo->currency) ? $vendor->extraInfo->currency : null;
                            if (!$currency) {
                                $currency = $defaultCurrency; //set default currency if not found
                            }

                            if ( !empty( $currency ) && !$vendor->linked_id ) {
                                $platform_account = (array)$platform_account;
                                $platform_account['app_id'] = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $platform_account['app_id'], 'decrypt' );
                                $platform_account['app_secret'] = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $platform_account['app_secret'], 'decrypt' );

                                $postData = [
                                    "vendor" => [
                                        "name" => $vendor->api_customer_id,
                                        "display_name" => $vendor->customer_name,
                                        "vendor_address" => $vendor->address1,
                                        "email" => $vendor->email,
                                        "currency" => $currency,
                                        "created_at" => $vendor->api_updated_at,
                                        "published" => True,
                                        "removed" => False
                                    ]
                                ];

                                $url = $platform_account['api_domain'].$this->InventoryPlannerApi->ApiVersion."/vendors";
                                $response = $this->InventoryPlannerApi->CheckAPIResponse( $url, $platform_account, 'POST', json_encode( $postData ) );
                                Storage::append( 'InventoryPlanner/'.$user_integration_id.'/CreateVendor/'.date('d-m-Y').'.txt', "[".date( 'H:i:s' )."]: ".json_encode( $response ) );
                                                                
                                if ( isset( $response['api_status'] ) && $response['api_status'] == "success" ) {
                                    $response = $response['vendor'];

                                    if ( $vendor->linked_id == 0 ) {
                                        $vendorLinking = PlatformCustomer::where([
                                            "linked_id" => $vendor->id,
                                            "platform_id" => $this->platformId,
                                        ])
                                        ->first();

                                        if ( !$vendorLinking ) {
                                            $vendorLinkingNew = PlatformCustomer::find($vendor->id);
                                            $vendorLinking = $vendorLinkingNew->replicate();
                                            $vendorLinking->platform_id = $this->platformId;
                                            $vendorLinking->linked_id = $vendor->id;
                                            $vendorLinking->created_at = Carbon::now();
                                            $vendorLinking->updated_at = Carbon::now();
                                            $vendorLinking->sync_status = PlatformStatus::SYNCED;
                                            $vendorLinking->save();
                                        }

                                        $vendor->linked_id = $vendorLinking->id; //Update the product_sync_status
                                    }
                                    $vendor->sync_status = PlatformStatus::SYNCED;
                                    $vendor->save();

                                    $this->InventoryPlannerApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $vendor->id, null );
                                } else {
                                    $vendor->sync_status = PlatformStatus::FAILED;
                                    $vendor->save();

                                    $return_response = $response['api_data'];
                                    $this->InventoryPlannerApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $vendor->id, $return_response );
                                }
                            } else {
                                $vendor->sync_status = PlatformStatus::FAILED;
                                $vendor->save();

                                $return_response = "Currency detail is not available.";
                                $this->InventoryPlannerApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $vendor->id, $return_response );
                            }
                        } else {
                            $vendor->sync_status = PlatformStatus::FAILED;
                            $vendor->save();

                            $return_response = "Name is not available.";
                            $this->InventoryPlannerApi->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $vendor->id, $return_response);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Log::error($user_integration_id . "-- InventoryPlannerApiController CreateVendor -->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     * Get Supplier or vendor
     */
    public function GetVendors($user_id, $user_integration_id)
    {
        set_time_limit(0);
        $return_response = true;
        try {
            $platform_account = $this->InventoryPlannerService->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {

                $last_updated_at = null;
                $recent_vendor = PlatformCustomer::where([
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'type' => 'Vendor'
                ])
                ->select('api_updated_at')
                ->orderBy('api_updated_at', 'asc')
                ->first();

                if ($recent_vendor && isset($recent_vendor->api_updated_at)) {
                    $last_updated_at = $recent_vendor->api_updated_at;
                }

                $limit = 100;
                $platform_account = (array)$platform_account;
                $platform_account['app_id'] = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $platform_account['app_id'], 'decrypt' );
                $platform_account['app_secret'] = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $platform_account['app_secret'], 'decrypt' );
                
                $url = $platform_account['api_domain'].$this->InventoryPlannerApi->ApiVersion."/vendors?created_date_gte=$last_updated_at&limit=$limit";
                $response = $this->InventoryPlannerApi->CheckAPIResponse( $url, $platform_account );
                // Storage::append( 'InventoryPlanner/'.$user_integration_id.'/getvendors/'.date('d-m-Y').'.txt', "[".date( 'H:i:s' )."]: ".json_encode( $response['vendors'] ) );

                if (isset($response['api_status']) && $response['api_status'] == "success") {
                    $vendors = $response['vendors'];
                    if (count($vendors)) {
                        foreach ($vendors as $vendor) {
                            $vendor_id = $vendor['name']; // VENDOR_ID

                            $vendorObj = PlatformCustomer::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'customer_name' => trim( strtolower( $vendor_id ) ),
                                'type' => 'Vendor',
                            ])
                            ->select('id')
                            ->first();

                            if (!$vendorObj) {
                                $vendorObj = new PlatformCustomer();
                                $vendorObj->user_id = $user_id;
                                $vendorObj->user_integration_id = $user_integration_id;
                                $vendorObj->platform_id = $this->platformId;
                                $vendorObj->customer_name = trim( strtolower( $vendor_id ) );
                                $vendorObj->type = 'Vendor';
                            }

                            $vendorObj->api_customer_id = $vendor['display_name']; // DISPLAY_NAME
                            $vendorObj->api_customer_code = $vendor['version']; // Customer Code
                            $vendorObj->email = ''; // EMAIL
                            $vendorObj->postal_addresses = $vendor['vendor_address'] ?? ''; // VENDOR_ADDRESS
                            $vendorObj->api_created_at = date( 'Y-m-d H:i:s', strtotime( $vendor['created_at'] ) ); // CREATED_AT
                            $vendorObj->api_updated_at = date( 'Y-m-d H:i:s', strtotime( $vendor['updated_at'] ) ); // UPDATED_AT
                            $vendorObj->sync_status = PlatformStatus::READY;
                            $vendorObj->save();
                        }
                    } else {
                        $return_response = "Vendor record not found.";
                    }
                } else {
                    $return_response = "API call error.";
                    if ((isset($response['api_status']) && $response['api_status'] == 0) && isset($response['api_data'])) {
                        $return_response = $response['api_data'];
                    }
                }
            }
        } catch (Exception $e) {
            $return_response = $e->getMessage();
            Log::error($user_integration_id . ' - InventoryPlannerApiController - GetSupplier - ' . $return_response);
        }
        return $return_response;
    }

    /**
     * Sync product in Inventory Planner FTP
     */
    public function createProducts( $user_id, $user_integration_id, $source_platform_id=54, $source_platform_name='peoplevox', $user_workflow_rule_id=1585, $platform_workflow_rule_id=255, $record_id = 0 )
    {
        return $this->createProductWithUpdateInventory( $user_id, $user_integration_id, $source_platform_id, $source_platform_name, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id );

        $return_response = true;
        try {
            $platform_account = $this->InventoryPlannerService->getAccountDetails($user_integration_id); // get the account information for the integration
            if ($platform_account) {

                $object_id = $this->InventoryPlannerApi->ConnectionHelper->getObjectId('product');

                if ($source_platform_id && $object_id) {
                    $limit = 50;

                    $query = PlatformProduct::where([
                        'platform_product.platform_id' => $source_platform_id,
                        'platform_product.user_integration_id' => $user_integration_id,
                        'platform_product.is_deleted' => 0,
                    ])->when($record_id, function ($query) use ($record_id) {
                        return $query->where('platform_product.id', $record_id);
                    }, function ($query) {
                        return $query->where('platform_product.product_sync_status', PlatformStatus::READY);
                    });

                    $platform_products = $query->select(
                        'platform_product.id',
                        'platform_product.api_product_id',
                        'platform_product.api_variant_id',
                        'platform_product.product_name',
                        'platform_product.barcode',
                        'platform_product.uom',
                        'platform_product.weight',
                        'platform_product.api_warehouse_id',
                        'platform_product.price',
                        'platform_product.sku',
                        'platform_product.created_at',
                        'platform_product.updated_at',
                        'platform_product.created_at',
                        'platform_product.api_updated_at',
                        'platform_product.product_status',
                        'platform_product.linked_id',
                        'platform_product.custom_fields',
                        'platform_product.brand_id',
                        'platform_product.category_id',
                        'platform_product.bundle',
                        'platform_product.upc',
                        'platform_product_detail_attributes.images'
                    )
                    ->leftJoin('platform_product_detail_attributes', 'platform_product_detail_attributes.platform_product_id', '=', 'platform_product.id')
                    ->orderBy('platform_product.updated_at', 'ASC')
                    ->limit($limit)->get();

                    if ($platform_products) {

                        $product_identifier = $this->InventoryPlannerService->productIdentityMapping($user_integration_id, $platform_workflow_rule_id);

                        if (
                            (isset($product_identifier['source_identity']) && isset($product_identifier['destination_identity'])
                            ) ||
                            $source_platform_name == "veracore"
                        ) {

                            $default_product_pricelist = $this->InventoryPlannerApi->FieldMappingHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "product_pricelist", ['api_id'], "default");
                            $default_product_currency = $this->InventoryPlannerApi->FieldMappingHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "default_product_currency", ['api_code'], "default");

                            $excelSetData = [];
                            foreach ($platform_products as $product) {
                                $productLinkingNew = PlatformProduct::find($product->id);
                                $productLinking = $productLinkingNew->replicate();
                                $destinationColumn = $product_identifier['destination_identity'];
                                $sourceColumn = $product_identifier['source_identity'];

                                $productImage = $productPrice = $productSku  = $productBarcode = $productVariant = $productBrand = $productCategory = $productSubCategory = null;
                                $productRegularPrice = 0;

                                $product_primary_id = $product->id;
                                $publish = 'false';
                                $remove = 'false';
                                if ($product->product_status) {
                                    $publish = 'true';
                                }

                                $inventoryManagement = 'true';

                                if ( isset( Config::get( 'apisettings.AllowSKUInSnowflake')[$source_platform_name] ) ) {
                                    $productVariant = $productSku = $product->api_product_id;
                                    $productBarcode = $product->upc;
                                } else {
                                    $productSku = $product->sku;
                                    $productBarcode = $product->barcode;
                                    $productVariant = $product->api_variant_id;
                                }

                                if (isset($product->images)) {
                                    $imageArr = explode(",", $product->images);
                                    if (COUNT($imageArr) > 0) {
                                        $productImage = $imageArr[0];
                                    }
                                }
                                $productPrice = $product->price ?? 0;
                                if ( isset( $default_product_currency->api_code ) && isset( $default_product_pricelist->api_id ) ) {
                                    if ( $source_platform_name == "netsuite" ) {
                                        $price = $this->InventoryPlannerService->findPriceList( $product_primary_id, $default_product_currency->api_code, $default_product_pricelist->api_id );
                                        if ( isset( $price['price'] ) ) {
                                            $productPrice = $price['price'] ?? 0;
                                        }
                                    }
                                }
                                $productCategory = $product->category_id;

                                $productBrand = htmlspecialchars(str_replace("'", "\'", $product->brand_id), ENT_QUOTES);

                                $field_mapping = $this->InventoryPlannerApi->FieldMappingHelper->GetMappedFieldRecord($object_id, $user_integration_id, NULL, "source_row_id", NULL, $product_primary_id); //product field mappings | custom fields
                                if ($field_mapping) {
                                    foreach ($field_mapping as $mapping) {
                                        if ($mapping['destination_field_name'] == "IMAGE") {
                                            $productImage = $mapping['source_custom_field_value'];
                                        }
                                        
                                        if ($mapping['destination_field_name'] == "BRAND") {
                                            $productBrand = $mapping['source_custom_field_value'];
                                        }

                                        if ($mapping['destination_field_name'] == "PRICE") {
                                            $productPrice = $mapping['source_custom_field_value'];
                                        }

                                        if ($mapping['destination_field_name'] == "REGULAR_PRICE") {
                                            $productRegularPrice = $mapping['source_custom_field_value'];
                                        }

                                        if ($mapping['destination_field_name'] == "TAGS") {
                                            $productSubCategory = $mapping['source_custom_field_value'];
                                        }

                                        if ($mapping['destination_field_name'] == "CATEGORY") {
                                            $productCategory = $mapping['source_custom_field_value'];
                                        }
                                    }
                                }

                                
                                $updatedAt = date( 'Y-m-d h:i:s' );
                                {
                                    // Accept Format
                                    /**
                                     * A => product_id,
                                     * B => title,
                                     * C => SKU,
                                     * D => regular_price,
                                     * E => price,
                                     * F => stock_quantity,
                                     * G => created_at,
                                     * H => updated_at,
                                     * I => managing_stock,
                                     * J => vendor,
                                     * K => vendor_product_name,
                                     * L => visible,
                                     * M => categories,
                                     * N => image,
                                     * O => barcode,
                                     * P => brand,
                                     * Q => options,
                                     * R => tags,
                                     * S => removed,
                                     */

                                    $data = [];
                                    $data[] = str_replace( "'", "\'", $productVariant );//A
                                    $data[] = str_replace( "'", "\'", $product->product_name );//B
                                    $data[] = str_replace("'", "\'", $productSku);//C
                                    $data[] = (float)$productRegularPrice;//D
                                    $data[] = (float)$productPrice;//E
                                    $data[] = 0;//stock_quantity//F
                                    $data[] = $this->InventoryPlannerService->dateFormat( $source_platform_name, $product->created_at );//G
                                    $data[] = $updatedAt;//H
                                    $data[] = $inventoryManagement;//I
                                    $data[] = '';//vendor//J
                                    $data[] = str_replace("'", "\'", $product->product_name);//K
                                    $data[] = $publish;//L
                                    $data[] = $productCategory;//M
                                    $data[] = $productImage;//N
                                    $data[] = str_replace("'", "\'", $productBarcode);//O
                                    $data[] = $productBrand;//P
                                    $data[] = '';//Q
                                    $data[] = $productSubCategory;//R
                                    $data[] = $remove;//S

                                    // if ( $product->linked_id == 0 ) {
                                    //     $productLinking = PlatformProduct::where([
                                    //         "linked_id" => $product->id,
                                    //         "platform_id" => $this->platformId,
                                    //     ])
                                    //     ->first();

                                    //     if ( !$productLinking ) {
                                    //         $productLinkingNew = PlatformProduct::find($product->id);
                                    //         $productLinking = $productLinkingNew->replicate();

                                    //         if ( $source_platform_name != "veracore" && isset( $productLinking->$destinationColumn ) ) {
                                    //             $productAtt = $productLinkingNew->toArray();
                                    //             if ( isset( $productAtt[$product_identifier['source_identity']] ) ) {
                                    //                 $productLinking->$destinationColumn = $productAtt[$sourceColumn];
                                    //             }
                                    //         }

                                    //         $productLinking->platform_id = $this->platformId;
                                    //         $productLinking->linked_id = $product->id;
                                    //         $productLinking->created_at = Carbon::now();
                                    //         $productLinking->updated_at = Carbon::now();
                                    //         $productLinking->product_sync_status = PlatformStatus::SYNCED;
                                    //         $productLinking->inventory_sync_status = PlatformStatus::PENDING;
                                    //         $productLinking->save();
                                    //     }

                                    //     $product->linked_id = $productLinking->id; // Update the product_sync_status
                                    // }

                                    // $product->product_sync_status = PlatformStatus::SYNCED;
                                    // $product->save();
                                    $excelSetData[] = $data;

                                    // $this->InventoryPlannerApi->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $product->id, null);
                                }
                            }

                            if( COUNT( $excelSetData ) > 0 ){
                                // Storage::append('InventoryPlanner/' . $user_integration_id . '/CreateProduct/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: " . json_encode( $excelSetData ) );
                                // Generate the Excel file using Laravel Excel Export
                                $file = storage_path('app/InventoryPlanner/'. $user_integration_id.'/ip-product-connection');
                                if ( !is_dir( $file ) ) {
                                    mkdir( $file, 0777, true );
                                }
                                //fopen(/var/www/html/integration/storage/app/InventoryPlanner/750/ip-product-connection/products.xlsx): failed to open stream: Permission denied
                                Excel::store( new InventoryPlannerProductXLS( $excelSetData ), $file.'/products.csv', 'public', \Maatwebsite\Excel\Excel::CSV );
                                dd( $file, $excelSetData );
                            }
                        }
                    }
                } else {
                    $return_response = self::$myPlatform . " : Integration account detail not found";
                }
            }
        } catch (Exception $e) {
            $return_response = $e->getMessage();
            Log::error('InventoryPlannerApiController - createUpdateProducts - userIntegration- ' . $user_integration_id . " Error: " . $e->getLine() . " -> " . $return_response);
        }
        return $return_response;
    }

    /**
     * Sync Product and Inventory in One flow
     */
    public function createProductWithUpdateInventory( $user_id, $user_integration_id, $source_platform_id=54, $source_platform_name='peoplevox', $user_workflow_rule_id=1585, $platform_workflow_rule_id=255, $record_id = 0 )
    {
        $return_response = true;
        try {
            
            $platform_account = $this->InventoryPlannerService->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {

                $productObjectId = $this->InventoryPlannerApi->ConnectionHelper->getObjectId('product');
                $inventoryObjectId = $this->InventoryPlannerApi->ConnectionHelper->getObjectId('inventory');
                $productIdentifier = $this->InventoryPlannerService->ProductIdentityMapping( $user_integration_id, $platform_workflow_rule_id ); //Identify Product Uniqueness
                $source_identity = $productIdentifier['source_identity']; //Source Identity
                $destination_identity = $productIdentifier['destination_identity']; //Destination Identity
                $default_product_pricelist = $this->InventoryPlannerApi->FieldMappingHelper->getMappedDataByName( $user_integration_id, $platform_workflow_rule_id, "product_pricelist", ['api_id'], "default" );
                $default_product_currency = $this->InventoryPlannerApi->FieldMappingHelper->getMappedDataByName( $user_integration_id, $platform_workflow_rule_id, "default_product_currency", ['api_code'], "default" );

                if ($source_identity == "" || $destination_identity == "") {
                    $return_response = "Please complete product unique identifier mapping.";
                    PlatformProductInventory::where('sync_status', PlatformStatus::READY)->update(['sync_status' => PlatformStatus::FAILED]);
                    PlatformProduct::where('inventory_sync_status', PlatformStatus::READY)->update(['inventory_sync_status' => PlatformStatus::FAILED]);
                } else {
                    $limit = 50;
                    $query = PlatformProduct::where([
                        'platform_product.platform_id' => $source_platform_id,
                        'platform_product.user_integration_id' => $user_integration_id,
                        'platform_product.is_deleted' => 0,
                    ])->when($record_id, function ($query) use ($record_id) {
                        return $query->where('platform_product.id', $record_id);
                    }, function ($query) {
                        return $query->where('platform_product.inventory_sync_status', PlatformStatus::READY);
                    });

                    $products = $query->select(
                        'platform_product.id',
                        'platform_product.api_product_id',
                        'platform_product.api_variant_id',
                        'platform_product.product_name',
                        'platform_product.barcode',
                        'platform_product.uom',
                        'platform_product.weight',
                        'platform_product.api_warehouse_id',
                        'platform_product.price',
                        'platform_product.sku',
                        'platform_product.created_at',
                        'platform_product.updated_at',
                        'platform_product.created_at',
                        'platform_product.api_updated_at',
                        'platform_product.product_status',
                        'platform_product.linked_id',
                        'platform_product.custom_fields',
                        'platform_product.brand_id',
                        'platform_product.category_id',
                        'platform_product.bundle',
                        'platform_product.upc',
                        'platform_product_detail_attributes.images'
                    )
                    ->leftJoin('platform_product_detail_attributes', 'platform_product_detail_attributes.platform_product_id', '=', 'platform_product.id')
                    ->orderBy('platform_product.updated_at', 'ASC')
                    ->limit($limit)->get();

                    if ($products) {
                        
                        $productPrefixArr = $this->InventoryPlannerService->getDefaultOrderWarehouse( $user_integration_id );
                        foreach ( $products as $product ) {

                            $keyVal = "api_product_id";
                            if ( isset( Config::get( 'apisettings.UniqueIdentityForSnowflakeSoMutate' )[$source_platform_name] ) ) {
                                $keyVal = Config::get( 'apisettings.UniqueIdentityForSnowflakeSoMutate' )[$source_platform_name][1];
                            }

                            $invQuery = PlatformProductInventory::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $source_platform_id,
                                'api_product_id' => $product->$keyVal,
                            ]);

                            if (!$record_id) {
                                $invQuery->where('sync_status', PlatformStatus::READY);
                            }
                            $productInventories = $invQuery->get();

                            $updateProductStatus = PlatformProduct::select( 'id', 'inventory_sync_status' )->where( "id", $product->id )->first();

                            if ( COUNT( $productInventories ) > 0 ) {
                                $filePath = storage_path( 'app/InventoryPlanner/'.$user_integration_id.'/ip-product-connection/products'.$this->InventoryPlannerService->productFileExtension );//Find product xlx file in storage location

                                foreach ($productInventories as $inventory) {
                                    $findRow = 0;
                                    if( $product->linked_id == 0 ){
                                        $this->InventoryPlannerService->createProductRowData(
                                            $product, 
                                            $productObjectId, 
                                            $user_id, 
                                            $user_integration_id, 
                                            $productIdentifier, 
                                            $source_platform_id, 
                                            $source_platform_name, 
                                            $default_product_pricelist, 
                                            $default_product_currency, 
                                            $user_workflow_rule_id,
                                            $inventory->quantity,
                                            $filePath,
                                            $productPrefixArr['api_code'] ?? $user_integration_id
                                        );
                                        $findRow = 1;
                                    } else {
                                        $updatedAt = date( 'Y-m-d h:i:s' );
                                        $columnToVariantIdSearch = 'A'; // Change this to the appropriate column letter ( for now use variant_id )
                                        
                                        $spreadsheet = IOFactory::load( $filePath );
                                        $worksheet = $spreadsheet->getActiveSheet();
                                        $highestRow = $worksheet->getHighestRow();

                                        for ($row = 1; $row <= $highestRow; $row++) {
                                            $cellVariantIdValue = $worksheet->getCell( $columnToVariantIdSearch . $row)->getValue();
                                            if ( $cellVariantIdValue === $product->api_variant_id ) {
                                                // Update the cell value in the same row
                                                $worksheet->setCellValue( 'F' . $row, $inventory->quantity ); // Change 'F' to the appropriate column letter ( for now use stock_quantity )
                                                $worksheet->setCellValue( 'H' . $row, $updatedAt ); // Change 'H' to the appropriate column letter ( for now use updated_ay )
                                                $findRow = 1;
                                                break; // Stop searching once value is found
                                            }
                                        }

                                        if( $findRow ){
                                            $writer = IOFactory::createWriter($spreadsheet, 'Csv');
                                            $writer->save( $filePath );
                                        }
                                    }

                                    if( $findRow ){
                                        $updateProductStatus->inventory_sync_status = PlatformStatus::SYNCED;
                                        $this->InventoryPlannerApi->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $inventoryObjectId, 'success', $product->id, null);
                                        PlatformProductInventory::where('platform_product_id', $product->id)->update(['sync_status' => PlatformStatus::SYNCED]);
                                    } else {
                                        $return_response = "Excel Search Data Missmatch";
                                        $updateProductStatus->inventory_sync_status = PlatformStatus::FAILED;
                                        $this->InventoryPlannerApi->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $inventoryObjectId, 'failed', $product->id, $return_response);
                                        PlatformProductInventory::where('platform_product_id', $product->id)->update(['sync_status' => PlatformStatus::FAILED]);
                                    }
                                    $updateProductStatus->save();
                                }
                            } else {
                                $return_response = "No inventory found";
                                $updateProductStatus->inventory_sync_status = PlatformStatus::FAILED;
                                $this->InventoryPlannerApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $inventoryObjectId, 'failed', $product->id, $return_response );
                                $updateProductStatus->save();
                            }
                        }
                    }
                }
            }
        
        } catch (Exception $e) {
            $return_response = $e->getMessage();
            Log::error('InventoryPlannerApiController - createProductWithUpdateInventory - userIntegration- ' . $user_integration_id . " Error: " . $e->getLine() . " -> " . $return_response);
        }
        return $return_response;
    }

    /**
     *
     */
    public function updateProductInventory($user_id, $user_integration_id, $source_platform_id, $source_platform_name, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id = 0, $mapped_field = 'sku')
    {
        $return_response = true;
        try {
            $platform_account = $this->InventoryPlannerService->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {

                $sync_object_id = $this->InventoryPlannerApi->ConnectionHelper->getObjectId('inventory');
                $identity = $this->InventoryPlannerService->ProductIdentityMapping($user_integration_id, $platform_workflow_rule_id); //Identify Product Uniqueness
                $source_identity = $identity['source_identity']; //Source Identity
                $destination_identity = $identity['destination_identity']; //Destination Identity

                if ($source_identity == "" || $destination_identity == "") {
                    $return_response = "Please complete product unique identifier mapping.";
                    PlatformProductInventory::where('sync_status', PlatformStatus::READY)->update(['sync_status' => PlatformStatus::FAILED]);
                    PlatformProduct::where('inventory_sync_status', PlatformStatus::READY)->update(['inventory_sync_status' => PlatformStatus::FAILED]);
                } else {
                    $query = DB::table('platform_product as source_platform_product')
                        ->join('platform_product as destination_platform_product', 'destination_platform_product.' . $destination_identity, '=', 'source_platform_product.' . $source_identity)
                        ->select(
                            'source_platform_product.id',
                            'source_platform_product.sku',
                            'source_platform_product.barcode',
                            'destination_platform_product.sku',
                            'destination_platform_product.barcode',
                            'destination_platform_product.api_product_id',
                            'destination_platform_product.api_variant_id',
                            'destination_platform_product.id as destination_platform_product_id'
                        )
                        ->where([
                            'source_platform_product.user_integration_id' => $user_integration_id,
                            'destination_platform_product.user_integration_id' => $user_integration_id,
                            'source_platform_product.platform_id' => $source_platform_id,
                            'destination_platform_product.platform_id' => $this->platformId,
                            'source_platform_product.is_deleted' => 0,
                            'destination_platform_product.is_deleted' => 0,
                        ]);

                    if ($record_id) {
                        $query->where('source_platform_product.id', $record_id);
                    } else {
                        $query->where('source_platform_product.inventory_sync_status', PlatformStatus::READY);
                    }

                    $products = $query->limit(50)->distinct()->get();

                    if ($products) {
                        
                        foreach ($products as $product) {

                            $keyVal = "api_product_id";
                            if (isset(Config::get('apisettings.UniqueIdentityForSnowflakeSoMutate')[$source_platform_name])) {
                                $keyVal = Config::get('apisettings.UniqueIdentityForSnowflakeSoMutate')[$source_platform_name][1];
                            }

                            $invQuery = PlatformProductInventory::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $source_platform_id,
                                'api_product_id' => $product->$keyVal,
                            ]);
                            if (!$record_id) {
                                $invQuery->where('sync_status', PlatformStatus::READY);
                            }
                            $productInventories = $invQuery->get();

                            $updateProductStatus = PlatformProduct::select( 'id', 'inventory_sync_status' )->where( "id", $product->id )->first();

                            if (COUNT($productInventories) > 0) {
                                $file = storage_path('app/InventoryPlanner/'.$user_integration_id.'/ip-product-connection/products.xlsx');//Find product xlx file in storage location
                                foreach ($productInventories as $inventory) {
                                    
                                    $updatedAt = date( 'Y-m-d h:i:s' );
                                    $columnToVariantIdSearch = 'A'; // Change this to the appropriate column letter ( for now use variant_id )
                                    
                                    $spreadsheet = IOFactory::load($file);
                                    $worksheet = $spreadsheet->getActiveSheet();
                                    $highestRow = $worksheet->getHighestRow();

                                    $findRow = 0;
                                    for ($row = 1; $row <= $highestRow; $row++) {
                                        $cellVariantIdValue = $worksheet->getCell( $columnToVariantIdSearch . $row)->getValue();
                                        if ( $cellVariantIdValue === $product->api_variant_id ) {
                                            // Update the cell value in the same row
                                            $worksheet->setCellValue( 'G' . $row, $inventory->quantity ); // Change 'G' to the appropriate column letter ( for now use stock_quantity )
                                            $worksheet->setCellValue( 'I' . $row, $updatedAt ); // Change 'I' to the appropriate column letter ( for now use updated_ay )
                                            $findRow = 1;
                                            break; // Stop searching once value is found
                                        }
                                    }

                                    if( $findRow ){
                                        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                                        $writer->save($file);
                                    
                                        $updateProductStatus->inventory_sync_status = PlatformStatus::SYNCED;
                                        $this->InventoryPlannerApi->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $product->id, null);
                                        PlatformProductInventory::where('platform_product_id', $product->id)->update(['sync_status' => PlatformStatus::SYNCED]);
                                    } else {
                                        $return_response = "Excel Search Data Missmatch";
                                        $updateProductStatus->inventory_sync_status = PlatformStatus::FAILED;
                                        $this->InventoryPlannerApi->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $product->id, $return_response);
                                        PlatformProductInventory::where('platform_product_id', $product->id)->update(['sync_status' => PlatformStatus::FAILED]);
                                    }
                                    $updateProductStatus->save();
                                }
                            } else {
                                $return_response = "No inventory found";
                                $updateProductStatus->inventory_sync_status = PlatformStatus::FAILED;
                                $this->InventoryPlannerApi->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $product->id, $return_response);
                                $updateProductStatus->save();
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $return_response = $e->getMessage();
            Log::error($user_integration_id . ' - InventoryPlannerApiController - updateProductInventory - ' . $return_response);
        }
        return $return_response;
    }

    /**
     * We can fetch the SO from source platform and create a SO in IP.
     */
    public function createSalesOrder($user_id, $user_integration_id, $source_platform_id, $source_platform_name, $destination_platform_name, $user_workflow_rule_id, $record_id = 0, $platform_workflow_rule_id)
    {
        $return_response = true;
        try {
            $platform_account = $this->InventoryPlannerService->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {

                $sync_object_id = $this->InventoryPlannerApi->ConnectionHelper->getObjectId('order');

                $limit = 50;
                $offset = 0;

                // Define the search criteria for the query
                $searchCriteria = [
                    'user_id' => $user_id,
                    'platform_id' => $source_platform_id,
                    'user_integration_id' => $user_integration_id,
                ];

                // Execute the query and retrieve the platform orders
                $query = PlatformOrder::with('platformOrderLine', 'platformCustomer');
                if ($record_id) {
                    $query->where('id', $record_id);
                } else {
                    $query->where('sync_status', PlatformStatus::READY);
                }

                $query->where([
                    'linked_id' => 0,
                    'is_deleted' => 0
                ]);
                $platformOrders = $query->where( $searchCriteria )->offset( $offset )->limit( $limit )->get();

                if ($platformOrders) {

                    $identity = $this->InventoryPlannerService->ProductIdentityMapping( $user_integration_id, $platform_workflow_rule_id ); //Identify Product Uniqueness
                    $source_identity = $identity['source_identity']; //Source Identity
                    $destination_identity = $identity['destination_identity']; //Destination Identity

                    if ( isset( Config::get( 'apisettings.UniqueIdentityForInventoryPlannerSoMutate' )[$source_platform_name] ) ) {
                        $source_identity = $destination_identity = Config::get('apisettings.UniqueIdentityForInventoryPlannerSoMutate')[$source_platform_name][0];
                    }

                    Storage::append('InventoryPlanner/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: source_identity: " . $source_identity . ", destination_identity: " . $destination_identity);

                    if ($source_identity == "" || $destination_identity == "") {
                        $return_response = "Please complete product unique identifier mapping.";
                        PlatformOrder::where('sync_status', PlatformStatus::READY)->update(['sync_status' => PlatformStatus::FAILED]);
                    } else {

                        $filePath = storage_path( 'app/InventoryPlanner/'.$user_integration_id.'/ip-product-connection/orders'.$this->InventoryPlannerService->productFileExtension );//Find product xlx file in storage location
                        foreach ( $platformOrders as $sourceOrder ) {
                            $variant_id = "";
                            $result = false;
                            $OrderwarehouseId = null;

                            if ( $source_platform_name == "netsuite" ) { //only for netsuite warehouse mapping
                                $location_object_data = $this->InventoryPlannerApi->MainModel->getFirstResultByConditions('platform_object_data', ['id' => $sourceOrder->warehouse_id, 'status' => 1], ['api_id']);

                                if ( $location_object_data ) {
                                    $warehouseId = $this->InventoryPlannerApi->FieldMappingHelper->getMappedDataByName($user_integration_id, null, "order_warehouse", ['api_id'], 'cross', $location_object_data->api_id);

                                    if ( $warehouseId ) {
                                        $OrderwarehouseId = $warehouseId->api_id;
                                    }
                                }
                            } else {

                                $salesOrderObject = $this->InventoryPlannerApi->MainModel->getFirstResultByConditions('platform_objects', ['name' => 'warehouse'], ['id']);
                                $warehouseObj = PlatformObjectData::where([
                                    'platform_id' => $source_platform_id,
                                    'user_integration_id' => $user_integration_id,
                                    'platform_object_id' => $salesOrderObject->id,
                                    'api_id' => $sourceOrder->warehouse_id,
                                ])
                                    ->select('api_id', 'api_code')
                                    ->first();

                                if ($warehouseObj) {
                                    $OrderwarehouseId = $warehouseObj->api_id;
                                }

                                // Check default warehouse mapping for sales order sync
                                if ( isset( Config::get( 'apisettings.IgnoreWarehouseMapInSoSync' )[$source_platform_name] ) ) {
                                    $default_warehouse = $this->InventoryPlannerApi->FieldMappingHelper->getMappedDataByName( $user_integration_id, $platform_workflow_rule_id, "order_warehouse", ['api_id'] );
                                    if ($default_warehouse) {
                                        $OrderwarehouseId = $default_warehouse->api_id;
                                    }
                                }
                            }

                            if ( is_null( $OrderwarehouseId ) ) {
                                $return_response = "No warehouse mapping found";
                            } else {
                                Storage::append('InventoryPlanner/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]:  OrderwarehouseId: " . $OrderwarehouseId);
                                if ( isset( $sourceOrder->platformOrderLine ) ) {

                                    $olines = PlatformOrderLine::where('platform_order_id', $sourceOrder->id)->where('row_type', 'ITEM')->pluck($source_identity)->toArray();

                                    $destination_identityTemp = $destination_identity;
                                    if ( isset( Config::get( 'apisettings.UniqueIdentityForInventoryPlannerSoMutate' )[$source_platform_name] ) ) {
                                        $destination_identityTemp = Config::get( 'apisettings.UniqueIdentityForInventoryPlannerSoMutate' )[$source_platform_name][1];
                                    }

                                    $olines = array_unique( array_filter( $olines ) );
                                    $totalPlines = PlatformProduct::where([
                                        'user_id' => $user_id,
                                        'user_integration_id' => $user_integration_id,
                                        'platform_id' => $this->platformId,
                                        'is_deleted' => 0
                                    ])
                                    ->whereIn( $destination_identityTemp, $olines )
                                    ->pluck( $destination_identityTemp )
                                    ->toArray();

                                    $totalPlines = array_unique(array_filter($totalPlines));
                                    Storage::append('InventoryPlanner/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: Total OLines: " . count($olines) . ", Total PLines: " . count($totalPlines));

                                    //when order item is bundle and use variant id as product id
                                    if( $source_platform_name == 'tiktok' ){
										if ( count( $olines ) != count( $totalPlines ) ) {

											$diffProducts = array_diff( $olines, $totalPlines );//get only not found product/variant id

											if ( isset( Config::get('apisettings.UniqueIdentityForInventoryPlannerSoMutate')[$source_platform_name] ) ) {
												$destination_identityTemp = Config::get('apisettings.UniqueIdentityForInventoryPlannerSoMutate')[$source_platform_name][0];
											}
											
											$totalPIDlines = PlatformProduct::where([
												'user_id' => $user_id,
												'user_integration_id' => $user_integration_id,
												'platform_id' => $this->platformId,
												'is_deleted' => 0
											])
											->whereIn( $destination_identityTemp, $diffProducts )
											->pluck( $destination_identityTemp )
											->toArray();
											
											$totalPIDlines = array_unique( array_filter( $totalPIDlines ) );
											
											$totalPlines = array_unique( array_merge( $totalPlines, $totalPIDlines ) );
											
											Storage::append('InventoryPlanner/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: Total OLines: " . count($olines) . ", Total PIDLines: " . count($totalPIDlines).", totalPlines: ".count( $totalPlines ) );
										}
									}

                                    if ( count( $olines ) != count( $totalPlines ) ) {
                                        $diffProducts = array_diff( $olines, $totalPlines );

                                        PlatformProduct::where([
                                            'user_id' => $user_id,
                                            'user_integration_id' => $user_integration_id,
                                            'platform_id' => $source_platform_id,
                                        ])
                                        ->where('product_sync_status', '!=', 'Ready')
                                        ->whereIn($destination_identity,  $diffProducts)
                                        ->update([
                                            'product_sync_status' => 'Ready'
                                        ]);

                                        Storage::append('InventoryPlanner/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: productMappingArr: " . json_encode( $olines ) . ", productIds: " . json_encode( $totalPlines ) . ' Diff Product-' . json_encode( $diffProducts ) );

                                        $return_response = "Some of order line item not found " . implode(",", $diffProducts);
                                        $sourceOrder->updated_at = date('Y-m-d H:i:s');
                                        $sourceOrder->save();
                                        continue;
                                    }

                                    $assignShippingCost = false;
                                    if ( $sourceOrder->shipping_total > 0 ) { //if shipping cost is greater than 0
                                        $assignShippingCost = true;
                                    }

                                    foreach ( $sourceOrder->platformOrderLine as $orderLine ) {
                                        if ( $orderLine->row_type == "SHIPPING" ) {
                                            continue; //ignore shipping row_type lines due to aleady added amount in a fist line item
                                        }

                                        $shipCost = 0;
                                        $shipCostTax = 0;

                                        if ( $assignShippingCost ) {
                                            $shipCost = $sourceOrder->shipping_total;
                                            $shipCostTax = $sourceOrder->shipping_tax;
                                            $assignShippingCost = false;
                                        }

                                        $variant_id = $orderLine->$destination_identity;
                                        Storage::append('InventoryPlanner/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: variant_id - " . $variant_id);

                                        // Prepare the column and value arrays for the XLSX statement
                                        /**
                                         * 1 => order_number	
                                         * 2 => date	
                                         * 3 => price	
                                         * 4 => quantity	
                                         * 5 => product_id	
                                         * 6 => SKU	
                                         * 7 => discount	
                                         * 8 => tax	
                                         * 9 => tax_included	
                                         * 10 => shipping	
                                         * 11 => customer	
                                         * 12 => currency	
                                         * 13 => canceled	
                                         * 14 => warehouse	
                                         * 15 => updated_at
                                         */
                                        
                                        if ($source_platform_name == "netsuite") {
                                            $linePrice = $orderLine->unit_price;
                                        } else {
                                            $linePrice = $orderLine->price;
                                        }

                                        $data = [];
                                        $data[] = ($orderLine->api_order_line_id) ? $orderLine->api_order_line_id : $orderLine->id;
                                        $data[] = $sourceOrder->order_date;
                                        $data[] = (float)$linePrice;
                                        $data[] = (int)$orderLine->qty;
                                        $data[] = $variant_id;
                                        $data[] = $orderLine->sku;
                                        $data[] = (float)$orderLine->discount_amount;
                                        $data[] = (float)$orderLine->subtotal_tax;
                                        $data[] = 0;
                                        $data[] = (float)$shipCost ?? 0;
                                        $data[] = $sourceOrder->platformCustomer->customer_name ?? '';
                                        $data[] = $sourceOrder->currency;
                                        $data[] = 0;
                                        $data[] = $OrderwarehouseId;
                                        $data[] = date( 'Y-m-d h:i:s' );

                                        // $excelSetData[] = $data;
                                        if( $data ){
                                            // Check if the CSV file exists, create if not
                                            if (!File::exists( $filePath ) ) {
                                                File::put( $filePath, "order_number,date,price,quantity,product_id,SKU,discount,tax,tax_included,shipping,customer,currency,canceled,warehouse,updated_at\n");
                                            }
                                            
                                            File::append( $filePath, implode(',', $data ) . "\n");
                                            $result = true;
                                        }
                                    }
                                }
                            }

                            if ($result) {
                                $orderLinking = PlatformOrder::create([
                                    'user_id' => $user_id,
                                    'user_integration_id' => $user_integration_id,
                                    'platform_id' => $this->platformId,
                                    'linked_id' => $sourceOrder->id,
                                    'sync_status' => PlatformStatus::PENDING,
                                    'user_workflow_rule_id' => $sourceOrder->user_workflow_rule_id,
                                    'platform_customer_id' => $sourceOrder->platform_customer_id,
                                    'order_type' => $sourceOrder->order_type,
                                    'api_order_id' => $sourceOrder->api_order_id,
                                    'currency' => $sourceOrder->currency,
                                    'warehouse_id' => $sourceOrder->warehouse_id,
                                    'order_number' => $sourceOrder->order_number,
                                ]);

                                if (isset($orderLinking->id)) {
                                    $sourceOrder->linked_id = $orderLinking->id;
                                    $sourceOrder->sync_status = PlatformStatus::SYNCED;
                                    $sourceOrder->save();
                                }
                                $this->InventoryPlannerApi->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $sourceOrder->id, null);
                            }
                        }
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (Exception $e) {
            Log::error($user_integration_id . "-- InventoryPlannerApiController createSalesOrder -->" . $e->getMessage() . '-->' . $e->getLine());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     * We can fetch the po from source platform and create a PO receipt in IP.
     * it's update old entry but function can describe create new one.
     * Note:
     * matchShipLineBy = SKU, VARIANT_ID
     * type = Shipment, Transfer
     */
    public function createOrderReceipt($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id = null, $matchShipLineBy = "SKU", $type = "PO")
    {
        $return_response = true;
        try {
            $platform_account = $this->InventoryPlannerService->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {

                $limit = 20;
                $where = [];

                if ($record_id) {
                    $where['id'] = $record_id;
                } else {
                    $where['user_id'] = $user_id;
                    $where['platform_id'] = $source_platform_id;
                    $where['user_integration_id'] = $user_integration_id;
                    $where['order_type'] = $type;
                    $where['linked_id'] = 0;
                    $where['shipment_status'] = PlatformStatus::READY;
                }

                $orders = PlatformOrder::where($where)
                    ->where( $where )
                    ->limit($limit)
                    ->orderBy( 'updated_at', 'asc' )
                    ->get();

                if ( count( $orders ) >0 ) {
                    $productIdentity = $this->InventoryPlannerService->ProductIdentityMapping( $user_integration_id, $platform_workflow_rule_id ); //Identify Product Uniqueness

                    if ($productIdentity) {

                        $source_identity = $productIdentity['source_identity'];

                        $source_platform_name = $this->InventoryPlannerApi->ConnectionHelper->getPlatformNameByID($source_platform_id); // If custom identifier is required for PO receipt sync
                        if ( isset( Config::get( 'apisettings.UniqueIdentityForSnowflakeOrderReceiptMutate' )[$source_platform_name] ) ) {
                            $source_identity = Config::get('apisettings.UniqueIdentityForSnowflakeOrderReceiptMutate')[$source_platform_name];
                        }

                        if ($source_identity == "api_product_id") {
                            $source_identity = "product_id";
                        }

                        if ($type == "PO") {
                            $objectName = 'purchase_order';
                        } elseif ($type == "TO") {
                            $objectName = 'transfer_order';
                        }

                        $sync_object_id = $this->InventoryPlannerApi->ConnectionHelper->getObjectId($objectName);

                        $platform_account = (array)$platform_account;
                        $platform_account['app_id'] = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $platform_account['app_id'], 'decrypt' );
                        $platform_account['app_secret'] = $this->InventoryPlannerApi->MainModel->encrypt_decrypt( $platform_account['app_secret'], 'decrypt' );
                                            

                        foreach ( $orders as $order ) {

                            $destinationOrderID = @$order->linkedOrder->api_order_id;
                            if ($type == "PO") {
                                $count = $order->linkedOrder->platformOrderLine->sum('qty') ?? 0; //Destination Line Item Product sum
                            } elseif ($type == "TO") {
                                $shipments = PlatformOrderShipment::where('platform_order_id', $order->linked_id)->first(); //Destination Line Item Product sum
                                $count = $shipments->platformShippingLines->sum('quantity') ?? 0;
                            }

                            $query = PlatformOrderShipment::where('platform_order_id', $order->id);
                            if ( $query->count() > 0 ) {
                                $orderShipmentIds = $query->get(); //Find Source Line Item Product Quantity Sum

                                $sum = 0;
                                foreach ($orderShipmentIds as $shipment) {
                                    $sum = $sum + $shipment->platformShippingLines->sum('quantity');
                                }

                                $orderShipment = $query->whereIn('sync_status', ['Ready', 'Failed'])->get();

                                $error = null;
                                $errorOrderFinalFlag = false;
                                foreach ($orderShipment as $shipment) {

                                    $errorShipmentFlag = false;
                                    if ( isset( $shipment->platformShippingLines ) && count( $shipment->platformShippingLines ) ) {

                                        $shipment->platformShippingLines->sum('quantity');
                                        $totalLines=count($shipment->platformShippingLines);
                                        $totalLineProcess=0;
                                        foreach ($shipment->platformShippingLines as $itemRec) {
                                            $skipItem = false;
                                            $receive = $itemRec->quantity;
                                            
                                            // $rec_post_data["statement"] = "SELECT REPLENISHMENT,RECEIVED FROM $database.$schema.$table
                                            //      WHERE ID = '{$destinationOrderID}'
                                            //      AND $matchShipLineBy = '{$itemRec->$source_identity}';";

                                            $url = $platform_account['api_domain'].$this->InventoryPlannerApi->ApiVersion."/purchase-orders?id=$destinationOrderID";
                                            $response = $this->InventoryPlannerApi->CheckAPIResponse( $url, $platform_account ); //get old receive qty

                                            if ( $response['api_status'] && isset( $response['api_data']['data'][0][1] ) ) {
                                                if (is_array($response['api_data']) && !empty($response['api_data'])) {
                                                    $receive += ($response['api_data']['data'][0][1] != "" && $response['api_data']['data'][0][1] != null) ? (int)$response['api_data']['data'][0][1] : 0;

                                                    if($response['api_data']['data'][0][0] < $receive){ //Fallback: do not send more than order qty
                                                        $receive =$response['api_data']['data'][0][0];
                                                    }
                                                } else {
                                                    $skipItem = true;
                                                    continue; // if server response busy error get from SF
                                                }
                                            } else {
                                                $skipItem = true;
                                                $errorShipmentFlag = true;
                                                continue;
                                            }

                                            if (!$skipItem) {
                                                //update receive qty
                                                $updatedAt = date( 'Y-m-d h:i:s' );
                                                // $post_data["statement"] = "UPDATE $database.$schema.$table SET
                                                //                             RECEIVED = {$receive},
                                                //                             RECEIVED_DATE = '{$itemRec->updated_at}',
											    //                             UPDATED_AT = '{$updatedAt}'
                                                //                             WHERE ID = '{$destinationOrderID}'
                                                //                             AND $matchShipLineBy = '{$itemRec->$source_identity}';";

                                                $url = $platform_account['api_domain'].$this->InventoryPlannerApi->ApiVersion."/purchase-orders/$destinationOrderID/items";
                                                $response = $this->InventoryPlannerApi->CheckAPIResponse( $url, $platform_account, "PATCH" ); //get old receive qty

                                                if (isset($response['api_data']['data'][0][0]) && $response['api_status'] && $response['api_data']['data'][0][0] >= 0) {
                                                    $error = null;
                                                    $totalLineProcess++;
                                                } else {
                                                    $errorShipmentFlag = true;
                                                    $return_response = $error = $response['api_data'];
                                                    continue;
                                                }
                                            }
                                        }

                                        if ($errorShipmentFlag) {
                                            if($totalLines==$totalLineProcess){
                                                $errorOrderFinalFlag = true;
                                            }else{
                                                $errorOrderFinalFlag = "partial";
                                            }

                                            // Update the sync status of order to FAILED
                                            $shipment->sync_status = PlatformStatus::FAILED; // Update the sync status of order shipments to FAILED
                                            $shipment->save();
                                            $error = "Please check some of lines are not processed";
                                        } else {
                                            // Update the sync status of order to SYNCED
                                            $shipment->sync_status = PlatformStatus::SYNCED; // Update the sync status of order shipments to SYNCED
                                            $shipment->save();
                                            $error = null;
                                        }
                                    }
                                }

                                if ((is_bool($errorOrderFinalFlag) && $errorOrderFinalFlag==true) || is_string($errorOrderFinalFlag)) {
                                    if( !is_bool($errorOrderFinalFlag)){
                                        $order->shipment_status = PlatformStatus::PARTIAL;
                                        $status = "success";
                                    }else{
                                        $order->shipment_status = PlatformStatus::FAILED;
                                        $status = "failed";
                                    }

                                } else {
                                    if ($sum == $count) {
                                        $order->shipment_status = PlatformStatus::SYNCED;
                                    } else {
                                        $order->shipment_status = PlatformStatus::PARTIAL;
                                    }
                                    $status = "success";
                                }
                                $order->save();
                                $this->InventoryPlannerApi->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, $status, $order->id, $error);
                            } else {
                                $order->shipment_status = PlatformStatus::FAILED;
                                $status = "failed";
                                $error = "No receipt found for this order.";
                                $order->save();
                                $this->InventoryPlannerApi->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, $status, $order->id, $error);
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Log::error($user_integration_id . "-- InventoryPlannerApiController createOrderReceipt --> " . $e->getMessage() . " Line -->" . $e->getLine());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     * 
     */
    public function createWareHouseS3FolderPath( $user_id, $user_integration_id, $warehouseIds=[] ){

        $s3FolderStructure = [];
        $status_code = 1;
        $status_text = "CSV/Excel File Path Generated";

        if( $warehouseIds ){
            $fileArr = [ 
                'ProductUrl' => 'products.csv', 
                'OrderUrl' => 'orders.csv' 
            ];
            $content = '';

            $ipS3Path = $this->InventoryPlannerApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'ip_s3_access_path'], ['id']);

            foreach( $warehouseIds as $warehouseId ){//get selected warehouse

                foreach( $fileArr as $k=>$file ){//get selected csv file

                    $wareHouseObject = PlatformObjectData::where( 'id', $warehouseId )->first();

                    $dynamic_file_name = 'esb/InventoryPlanner/'.$user_id.'-'.$user_integration_id.'/'.str_ireplace( " ", "-", $wareHouseObject->name ).'/'.$file;//store IP connection url

                    Storage::disk('s3')->put( $dynamic_file_name, $content );//upload file in s3 bucket

                    if ( Storage::disk('s3')->exists( $dynamic_file_name ) ) {//if exist s3 location file then track s3 url
                        $trackURL = 'https://'.env('AWS_BUCKET').'.s3.'.env('AWS_DEFAULT_REGION').'.amazonaws.com/' . $dynamic_file_name;

                        //update ip track url structure
                        $s3FolderStructure[$warehouseId][$k] = $trackURL;
                    } else {
                        $status_code = 0;
                        $status_text = "Something wan't wrong, Please try again.";
                    }
                }

                if( isset( $s3FolderStructure[$warehouseId] ) ){
                    $platformObjData = PlatformObjectData::where([
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'platform_object_id' => $ipS3Path->id,
                        'api_id' => $warehouseId,
                    ])
                    ->first();

                    if( !$platformObjData ){
                        $platformObjData = new PlatformObjectData();
                        $platformObjData->user_id = $user_id;
                        $platformObjData->platform_id = $this->platformId;
                        $platformObjData->user_integration_id = $user_integration_id;
                        $platformObjData->platform_object_id = $ipS3Path->id;
                        $platformObjData->api_id = $warehouseId;
                    }

                    $platformObjData->api_code = $wareHouseObject->api_code;
                    $platformObjData->name = $wareHouseObject->name;
                    $platformObjData->description = json_encode( $s3FolderStructure[$warehouseId] );
                    $platformObjData->status = 1;
                    $platformObjData->save();
                }
            }
        }
        
        $response = json_encode([
            'status_code' => $status_code, 
            'status_text' => $status_text, 
            'data' => $s3FolderStructure
        ]);

        // Storage::append('InventoryPlanner/' . $user_integration_id . '/createWareHouseS3FolderPath/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: ".$response );

        return $response;
    }

    /*
     * 
     */
    public function ExecuteInventoryPlannerEvents($method='', $event='', $destination_platform_id='', $user_id='', $user_integration_id='', $is_initial_sync=0, $user_workflow_rule_id='', $source_platform='', $platform_workflow_rule_id='', $record_id='')
    {
        $log = "method: ".$method.", event: ".$event.", destination_platform_id: ".$destination_platform_id.", user_id: ".$user_id.", user_integration_id: ".$user_integration_id.", is_initial_sync: ".$is_initial_sync.", user_workflow_rule_id: ".$user_workflow_rule_id.", source_platform: ".$source_platform.", platform_workflow_rule_id: ".$platform_workflow_rule_id;
        Storage::append( 'InventoryPlanner/'.$user_integration_id.'/ExecuteEvents/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".$log );

        $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform);
        $response = true;
        if ( $method == 'GET' && $event == 'WAREHOUSELOCATION') {
            $response = $this->getWareHouseLists( $user_id, $user_integration_id );
        } else if ( $method == 'MUTATE' && $event == 'CREATEWAREHOUSE') {
            $response = $this->createWarehouse( $user_id, $user_integration_id );
        } else if ( $method == 'GET' && $event == 'VENDOR') {
            $response = $this->GetVendors( $user_id, $user_integration_id );
        } else if ( $method == 'MUTATE' && $event == 'VENDOR') {
            $response = $this->createVendor( $user_id, $user_integration_id, $source_platform_id, $source_platform, $user_workflow_rule_id, $record_id );
        } else if($method == 'GET' && $event == 'PURCHASEORDER') {
            $response = $this->GetOrders($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, 'PO');
        } elseif($method == 'GET' && $event == 'TRANSFERORDER') {
            $response = $this->GetOrders($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, 'TO');
        } else if ($method == 'MUTATE' && $event == 'SALESORDER') {
            $response = $this->createSalesOrder($user_id, $user_integration_id, $source_platform_id, $source_platform, $destination_platform_id, $user_workflow_rule_id, $record_id, $platform_workflow_rule_id);
        } else if ($method == 'MUTATE' && $event == 'PURCHASEORDERRECEIPT') {
            $response = $this->createOrderReceipt($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id, "SKU", "PO");
        } else if ($method == 'MUTATE' && $event == 'PRODUCT') {
            // $response = $this->createProducts( $user_id, $user_integration_id, $source_platform_id, $source_platform, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id );
            $response = $this->createProductWithUpdateInventory( $user_id, $user_integration_id, $source_platform_id, $source_platform, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id );
        } else if ($method == 'MUTATE' && $event == 'INVENTORY') {
            // $response = $this->updateProductInventory($user_id, $user_integration_id, $source_platform_id, $source_platform, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id);
        } else if ($method == 'GET' && $event == 'WAREHOUSE') {
            // $response = $this->getWareHouse($user_id,$user_integration_id, $source_platform_id, $source_platform);
        } else if ($method == 'GET' && $event == 'CLONEWAREHOUSE') {
            // $response = $this->syncWareHouse($user_id, $user_integration_id, $source_platform, $is_initial_sync);
        } else if ($method == 'MUTATE' && $event == 'TRANSFERORDERRECEIPT') {
            // $response = $this->createOrderReceipt($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id, "SKU", "TO");
        } else if ($method == 'GET' && $event == 'GENERATES3IPPATH') {
            //record_id as a warehouse id
            $response = $this->createWareHouseS3FolderPath( $user_id, $user_integration_id, $record_id );
        }

        return $response;
    }

    /**
     * 
     */
    public function updateExcelColumn(){

        return $this->createExcelFile();

        $file = storage_path('app/InventoryPlanner/ip-product-connection/products.xlsx');
        // Excel::import(new InventoryPlannerProductStockXLS, $file);

        $columnToSearch = 'A'; // Change this to the appropriate column letter
        $searchValue = 'KT-TEST-20230708-02';
        $newValue = 'Gautam Kakadiya 1';

        $spreadsheet = IOFactory::load($file);
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();

        for ($row = 1; $row <= $highestRow; $row++) {
            $cellValue = $worksheet->getCell( $columnToSearch . $row)->getValue();
            if ($cellValue === $searchValue) {
                // Update the cell value in the same row
                $worksheet->setCellValue('K' . $row, $newValue); // Change 'B' to the appropriate column letter
                break; // Stop searching once value is found
            }
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($file);

        return 'Excel updated successfully';

    }

    /**
     * 
     */
    public function createExcelFile(){
        $file = str_ireplace( "/var/www/html/integration/storage/app/public", "", storage_path('app/InventoryPlanner/750/ip-product-connection') );
        $excelSetData = [["apitest","apitest","api test item","apitest",0,0,0,"2023-08-03T06:22:08.000000Z","2023-08-05 09:01:07",1,"","api test item",0,"2023-08-03T06:22:08.000000Z","simple","",null,"","apitest","","","",0,0,0,"","",""],["KT-TEST-20230708-01","KT-TEST-20230708-01","Denim Jacket","KT-TEST-20230708-01",0,0,0,"2023-08-03T06:22:10.000000Z","2023-08-05 09:01:07",1,"","Denim Jacket",0,"2023-08-03T06:22:10.000000Z","simple","",null,"","2023070801","","","",0,0,0,"","",""],["KT-TEST-20230708-02","KT-TEST-20230708-02","Cargo Jeans","KT-TEST-20230708-02",0,0,0,"2023-08-03T06:22:11.000000Z","2023-08-05 09:01:07",1,"","Cargo Jeans",0,"2023-08-03T06:22:11.000000Z","simple","",null,"","2023070802","","","",0,0,0,"","",""],["MBTEST-12","MBTEST-12","MBTEST-12","MBTEST-12",0,0,0,"2023-08-03T06:22:11.000000Z","2023-08-05 09:01:07",1,"","MBTEST-12",0,"2023-08-03T06:22:11.000000Z","simple","",null,"","MBTEST-12","","","",0,0,0,"","",""],["MBTEST-13","MBTEST-13","MBTEST-13","MBTEST-13",0,0,0,"2023-08-03T06:22:11.000000Z","2023-08-05 09:01:07",1,"","MBTEST-13",0,"2023-08-03T06:22:11.000000Z","simple","",null,"","MBTEST-13","","","",0,0,0,"","",""],["MBTEST-2","MBTEST-2","MBTEST-2","MBTEST-2",0,0,0,"2023-08-03T06:32:11.000000Z","2023-08-05 09:01:07",1,"","MBTEST-2",0,"2023-08-03T06:32:11.000000Z","simple","",null,"","6544566544565","","","",0,0,0,"","",""],["MBTEST-7","MBTEST-7","","MBTEST-7",0,0,0,"2023-08-03T06:32:12.000000Z","2023-08-05 09:01:07",1,"","",0,"2023-08-03T06:32:12.000000Z","simple","",null,"","MBTEST-7","","","",0,0,0,"","",""],["MBTEST-6","MBTEST-6","","MBTEST-6",0,0,0,"2023-08-03T06:32:12.000000Z","2023-08-05 09:01:07",1,"","",0,"2023-08-03T06:32:12.000000Z","simple","",null,"","MBTEST-6","","","",0,0,0,"","",""],["Test123","Test123","Test123","Test123",0,0,0,"2023-08-03T06:32:13.000000Z","2023-08-05 09:01:07",1,"","Test123",0,"2023-08-03T06:32:13.000000Z","simple","",null,"","Test123","","","",0,0,0,"","",""],["PROD0001","PROD0001","Test Item 1","PROD0001",0,0,0,"2023-08-03T06:32:13.000000Z","2023-08-05 09:01:07",1,"","Test Item 1",0,"2023-08-03T06:32:13.000000Z","simple","",null,"","12345678","","","",0,0,0,"","",""],["MBTEST-9","MBTEST-9","MBTEST-9","MBTEST-9",0,0,0,"2023-08-03T06:32:13.000000Z","2023-08-05 09:01:07",1,"","MBTEST-9",0,"2023-08-03T06:32:13.000000Z","simple","",null,"","MBTEST-9","","","",0,0,0,"","",""],["MBTEST-8","MBTEST-8","MBTEST-8","MBTEST-8",0,0,0,"2023-08-03T06:32:13.000000Z","2023-08-05 09:01:07",1,"","MBTEST-8",0,"2023-08-03T06:32:13.000000Z","simple","",null,"","MBTEST-8","","","",0,0,0,"","",""],["MBTEST-3","MBTEST-3","MBTEST-3","MBTEST-3",0,0,0,"2023-08-03T06:32:11.000000Z","2023-08-05 09:01:07",1,"","MBTEST-3",0,"2023-08-03T06:32:11.000000Z","simple","",null,"","6544566544500","","","",0,0,0,"","",""],["MBTEST-4","MBTEST-4","MBTEST-4","MBTEST-4",0,0,0,"2023-08-03T06:32:11.000000Z","2023-08-05 09:01:07",1,"","MBTEST-4",0,"2023-08-03T06:32:11.000000Z","simple","",null,"","6544566544511","","","",0,0,0,"","",""],["MBTEST-5","MBTEST-5","MBTEST-5","MBTEST-5",0,0,0,"2023-08-03T06:32:12.000000Z","2023-08-05 09:01:07",1,"","MBTEST-5",0,"2023-08-03T06:32:12.000000Z","simple","",null,"","6544566544522","","","",0,0,0,"","",""],["ItemTest1","ItemTest1","ItemTest1","ItemTest1",0,0,0,"2023-08-03T06:22:08.000000Z","2023-08-05 09:01:07",1,"","ItemTest1",0,"2023-08-03T06:22:08.000000Z","simple","",null,"","5901234123457","","","",0,0,0,"","",""],["ItemTest4","ItemTest4","ItemTest4","ItemTest4",0,0,0,"2023-08-03T06:22:10.000000Z","2023-08-05 09:01:07",1,"","ItemTest4",0,"2023-08-03T06:22:10.000000Z","simple","",null,"","012345678905","","","",0,0,0,"","",""],["ItemTest3","ItemTest3","ItemTest3","ItemTest3",0,0,0,"2023-08-03T06:22:09.000000Z","2023-08-05 09:01:08",1,"","ItemTest3",0,"2023-08-03T06:22:09.000000Z","simple","",null,"","(01)04043002123458","","","",0,0,0,"","",""],["ItemTest2","ItemTest2","ItemTest2","ItemTest2",0,0,0,"2023-08-03T06:22:09.000000Z","2023-08-05 09:01:08",1,"","ItemTest2",0,"2023-08-03T06:22:09.000000Z","simple","",null,"","01234567","","","",0,0,0,"","",""],["MBTEST-11","MBTEST-11","MBTEST-11","MBTEST-11",0,0,0,"2023-08-03T06:22:11.000000Z","2023-08-05 09:01:08",1,"","MBTEST-11",0,"2023-08-03T06:22:11.000000Z","simple","",null,"","MBTEST-11","","","",0,0,0,"","",""],["MB-1","MB-1","MBTEST-1","MB-1",0,0,0,"2023-08-03T06:22:11.000000Z","2023-08-05 09:01:08",1,"","MBTEST-1",0,"2023-08-03T06:22:11.000000Z","simple","",null,"","236547891","","","",0,0,0,"","",""],["KT-TEST-001","KT-TEST-001","Blank Paper A4","KT-TEST-001",0,0,0,"2023-08-03T06:22:10.000000Z","2023-08-05 09:01:08",1,"","Blank Paper A4",0,"2023-08-03T06:22:10.000000Z","simple","",null,"","542112312","","","",0,0,0,"","",""]];

        if (!is_dir($file)) {
            mkdir($file, 0777, true);
        }

        Excel::store( new InventoryPlannerProductXLS( $excelSetData ), $file.'/products.xlsx', 'public' );
        return 'Excel created successfully: '.$file;
    }

    /**
     * Test
     */
    public function test(){
        $response = json_decode( $this->createWareHouseS3FolderPath( 228, 750, ['586293', '586294'] ), 1 );
        dd( $response );
    }
}
