<?php
namespace App\Http\Controllers\Logiwa;

use Exception;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\WorkflowSnippet;
use App\Helper\Logger;
use App\Http\Controllers\Logiwa\Api\LogiwaApi;
use App\Models\PlatformAccount;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Logiwa\LogiwaService;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformCustomer;
use App\Models\PlatformProduct;
use App\Models\PlatformProductInventory;
use Carbon\Carbon;
use App\Models\PlatformProductDetailAttribute;
use App\Models\PlatformProductPriceList;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderLine;
use Illuminate\Support\Facades\DB;

class LogiwaApiController extends Controller
{
    public $mobj = '';
    public $LogiwaApi = '';
    public $ConnectionHelper = '';
    public $FieldMappingHelper = '';
    public $Logger = '';
    public $WorkflowSnippet = '';
    public $platformId = '';
    public $LogiwaService;
    
    public static $myPlatform = 'Logiwa';

    /*
     * @Function:        <__contruct>
     * @Author:          Gautam Kakadiya
     * @Created On:      <06-07-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Create a new controller instance>
     * @Returns:         <  >
     * https://developer.logiwa.com
    */
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->LogiwaApi = new LogiwaApi();
        $this->LogiwaService = new LogiwaService();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->FieldMappingHelper = new FieldMappingHelper();
        $this->Logger = new Logger();
        $this->WorkflowSnippet = new WorkflowSnippet();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
    }

    /*
     * @Function:        <Initiate Logiwa Authentication>
     * @Author:          Gautam Kakadiya
     * @Created On:      <06-07-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Initiate Logiwa Authentication>
     * @Returns:         < Logiwa UI Authentication>
    */
    public function InitiateLogiwaAuth(Request $request)
    {
        $platform = self::$myPlatform;
        return view("pages.apiauth.logiwa_auth", compact('platform'));
    }

    /*
     * @Function:        <Connect Logiwa>
     * @Author:          Gautam Kakadiya
     * @Created On:      <06-07-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <Made Logiwa connection>
     * @Returns:         <Logiwa Authentication tocket code>
    */
    public function ConnectLogiwa(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account_name' => 'required',
            'user_name' => 'required',
            'user_password' => 'required',
            'grant_type' => 'required'
        ]);

        if($this->mobj->checkHtmlTags( $request->all()) ) {
            $data['error'] = Lang::get('tags.validate');
            return response()->json( $data, 200 );
        }

        if($validator->fails()) {
            return response()->json( $validator->messages(), 200 );
        } else {
            $user_data =  Session::get('user_data');
            return $this->LogiwaService->getAccessToken( $user_data['id'], trim( $request->user_name ), trim($request->user_password), trim($request->grant_type), trim( $request->account_name ), false );
        }
    }

    /*
     * Refresh Token
     */
    public function RefreshToken( $id )
    {
        date_default_timezone_set('UTC');
        $return_response = true;
        try{
            $platform_account = $this->LogiwaService->getDirectAccountDetails( 1224 );

            if($platform_account)
            {
                $user_id = $platform_account->user_id;
                $user_name = $this->LogiwaService->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
                $user_password = $this->LogiwaService->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
                $grant_type = $platform_account->connection_type;
                $account_name = $platform_account->account_name;
                return $this->LogiwaService->getAccessToken( $user_id, $user_name, $user_password, $grant_type, $account_name, true );
            }
        }
        catch( Exception $e )
        {
            Log::error($id . ' - LogiwaApiController - RefreshToken - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * @Function:        <get Products>
     * @Author:          Gautam Kakadiya
     * @Created On:      <06-07-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <get logiwa product listing>
     *
     * https://developer.logiwa.com/?id=5df0daa0e6466c2eec992f43
     * @return void
     */
    public function getProducts( $user_id, $user_integration_id, $user_workflow_rule_id=0, $is_initial_sync=false ){
        $return_response = true;
        try{
            $platform_account = $this->LogiwaService->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if( $platform_account )
            {
                $access_token = $this->LogiwaApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );
                $pagesize = 50;
                $LastModifiedDate_Start = "01.01.1970 00:00:00";//date( 'm.d.Y h:i:s' );
                $selectedPageIndex = 1;
                $limit = [];
                $filter = "";

                if( !$is_initial_sync ){//init sync start date
                    $limit = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_urls', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'product_sync_date'
                    ],
                    ['url', 'id']);

                    if ( $limit && $limit->url != '' ) {
                        $filter = $limit->url;
                    }
                }

                if( $filter == "" ){
                    $get_workflow_rule = $this->LogiwaApi->MainModel->getFirstResultByConditions('user_workflow_rule', [
                        'user_integration_id' => $user_integration_id,
                        'status' => 1,
                        'platform_workflow_rule_id' => $user_workflow_rule_id
                    ], [
                        'sync_start_date'
                    ]);

                    if( $get_workflow_rule ){
                        $filter = $get_workflow_rule->sync_start_date."|1";
                    }
                }

                $getFilterData = explode( "|", $filter );
                if( COUNT( $getFilterData ) > 1 ){
                    $LastModifiedDate_Start = $getFilterData[0];
                    $selectedPageIndex = $getFilterData[1];
                }

                /*----------------Start to find Client Account ----------------*/
                $DepositorID = 0;
                $clientAccountOrderObject = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'customer_account'], ['id']);
                $clientAccount = PlatformObjectData::select('api_id')
                ->where([
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $clientAccountOrderObject->id,
                ])
                ->first();
                
                if( $clientAccount )
                {
                    $DepositorID = $clientAccount->api_id;
                }

                $postData = [
                    'LastModifiedDate_Start' => $LastModifiedDate_Start,//
                    'LastModifiedDate_End' => date( 'm.d.Y h:i:s' ),//"06.01.2023 00:00:00",
                    "PageSize" => $pagesize,
                    "SelectedPageIndex" => $selectedPageIndex,
                    "DepositorID" => 11674,//$DepositorID,
                ];

                $headers = [
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$access_token,
                ];

                $url = $this->LogiwaApi->ApiURL."IntegrationApi/InventoryItemSearch";
                $response = $this->LogiwaApi->MainModel->makeCurlRequest( 'POST', $url, json_encode( $postData ), $headers );
                Storage::append( 'Logiwa/'.$user_integration_id.'/Products/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $postData )." ".$response );

                $response = json_decode( $response, true );
                if( $response && COUNT( $response['Data'] ) > 0 ){
                    $productArr = $response['Data'];

                    if( $productArr[0]['SelectedPageIndex'] == $productArr[0]['PageCount'] ){
                        $LastModifiedDate_Start = $productArr[ ( COUNT( $productArr ) - 1 ) ]['LastModifiedDate'];
                        $selectedPageIndex = 0;
                    }

                    foreach( $productArr as $pr ){
                        $this->LogiwaService->storeProductDetails( $user_id, $user_integration_id, $pr, $access_token );
                    }
                }
                
                $url = $LastModifiedDate_Start."|".( $selectedPageIndex + 1 );
                if ( $limit ) {
                    $this->LogiwaApi->MainModel->makeUpdate('platform_urls', ['url' => $url ], ['id' => $limit->id]);
                } else {
                    $this->LogiwaApi->MainModel->makeInsert('platform_urls', [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url' => $url,
                        'url_name' => 'product_sync_date'
                    ]);
                }
            }
        }catch( Exception $e )
        {
            Log::error( $user_integration_id . ' - LogiwaApiController - getProducts - '.$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     * @Function:        <get Products Inventory>
     * @Author:          Gautam Kakadiya
     * @Created On:      <07-07-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     <get logiwa product inventory listing>
     *
     * https://developer.logiwa.com/?id=5e20a095e6466c2b285d6dc6
     * @return void
     */
    public function getProductInventories( $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->LogiwaService->getAccountDetails( $user_integration_id ); // get the account information for the integration
            
            if( $platform_account )
            {
                $pagesize = 20;
                $selectedPageIndex = 1;
                $limit = [];

                $access_token = $this->LogiwaApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );

                $limit = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_urls', [
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'url_name' => 'product_inventory'
                ],
                ['url', 'id']);

                if ( $limit && $limit->url ) {
                    $selectedPageIndex = $limit->url;
                }

                /*----------------Start to find wareHoose ID ----------------*/
                $whareHouseId = 0;
                $warehouseOrderObject = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);
                $warehouse = PlatformObjectData::select('api_id')
                ->where([
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $warehouseOrderObject->id,
                ])
                ->first();
                
                if( $warehouse )
                {
                    $whareHouseId = $warehouse->api_id;
                }

                /*----------------Start to find Client Account ----------------*/
                $DepositorID = 0;
                $clientAccountOrderObject = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'customer_account'], ['id']);
                $clientAccount = PlatformObjectData::select('api_id')
                ->where([
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $clientAccountOrderObject->id,
                ])
                ->first();
                
                if( $clientAccount )
                {
                    $DepositorID = $clientAccount->api_id;
                }

                $postData = [
                    // 'LastQuantitySyncedDate' => "07.01.2023 00:00:00",
                    // 'LastModifiedDate_End' => "06.01.2023 00:00:00",
                    'WarehouseID' => $whareHouseId,//898,	
                    'DepositorID' => 11674,//$DepositorID,//11674,//11433,
                    "PageSize" => $pagesize,
                    "SelectedPageIndex" => $selectedPageIndex
                ];

                $headers = [
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$access_token,
                ];

                $url = $this->LogiwaApi->ApiURL."IntegrationApi/StockDamagedUndamagedReportSearch";
                $response = $this->LogiwaApi->MainModel->makeCurlRequest( 'POST', $url, json_encode( $postData ), $headers );
                Storage::append( 'Logiwa/'.$user_integration_id.'/ProductInventories/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $postData )." ".$response );
                $response = json_decode( $response, true );
                
                if( $response && COUNT( $response['Data'] ) > 0 ){
                    $productInventoryArr = $response['Data'];

                    if( $productInventoryArr[0]['SelectedPageIndex'] == $productInventoryArr[0]['PageCount'] ){
                        $selectedPageIndex = 0;
                    }

                    foreach( $productInventoryArr as $pr ){
                        $productObj = PlatformProduct::select('id', 'inventory_sync_status')
                        ->where([
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'api_product_id' => $pr['InventoryItemID']
                        ])
                        ->first();

                        if( $productObj ){
                            $productInventoryObj = PlatformProductInventory::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'platform_product_id' => $productObj->id,
                                'api_product_id' => $pr['ID']
                            ])
                            ->first();

                            if( !$productInventoryObj ){
                                $productInventoryObj = new PlatformProductInventory();
                                $productInventoryObj->user_id = $user_id;
                                $productInventoryObj->user_integration_id = $user_integration_id;
                                $productInventoryObj->platform_id = $this->platformId;
                                $productInventoryObj->platform_product_id = $productObj->id;
                                $productInventoryObj->api_product_id = $pr['ID'];
                            }

                            $productInventoryObj->api_warehouse_id = $pr['WarehouseID'];
                            $productInventoryObj->quantity = $pr['StockQty'];
                            $productInventoryObj->sku = $pr['InventoryItemDescription'];
                            $productInventoryObj->sync_status = PlatformStatus::READY;
                            $productInventoryObj->save();
                            
                            $productObj->inventory_sync_status = PlatformStatus::READY;
                            $productObj->save();
                        }
                    }
                }
                
                $url = $selectedPageIndex + 1;
                if ( $limit ) {
                    $this->LogiwaApi->MainModel->makeUpdate('platform_urls', ['url' => $url ], ['id' => $limit->id]);
                } else {
                    $this->LogiwaApi->MainModel->makeInsert('platform_urls', [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url' => $url,
                        'url_name' => 'product_inventory'
                    ]);
                }
            }
        }catch( Exception $e )
        {
            Log::error( $user_integration_id . ' - LogiwaApiController - getProductInventories - '.$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     *
     * @return void
     */
    public function getWareHouseLists( $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->LogiwaService->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if($platform_account)
            {
                $access_token = $this->LogiwaApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );
                $url = $this->LogiwaApi->ApiURL."IntegrationApi/LookUp";
                $postData = [
                    'LookupList' => [
                        2
                    ]
                ];

                $headers = [
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$access_token,
                ];

                $response = $this->LogiwaApi->MainModel->makeCurlRequest( 'POST', $url, json_encode( $postData ), $headers );
                Storage::append( 'Logiwa/'.$user_integration_id.'/Warehouse-'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".$response );
                $response = json_decode( $response, true );
                if( isset( $response['Lookup'] ) )
                {
                    $wareHouseObject = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);

                    //revert object data status
                    PlatformObjectData::where([
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'platform_object_id' => $wareHouseObject->id,
                    ])
                    ->update(['status' => 0]);

                    foreach( $response['Lookup']['WarehouseList'] as $ar ){
                        $platformObjData = PlatformObjectData::where([
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'platform_object_id' => $wareHouseObject->id,
                            'api_id' => $ar['Id'],
                        ])
                        ->first();

                        if( !$platformObjData ){
                            $platformObjData = new PlatformObjectData();
                            $platformObjData->user_id = $user_id;
                            $platformObjData->platform_id = $this->platformId;
                            $platformObjData->user_integration_id = $user_integration_id;
                            $platformObjData->platform_object_id = $wareHouseObject->id;
                            $platformObjData->api_id = $ar['Id'];
                        }

                        $platformObjData->api_code = $ar['Id'];
                        $platformObjData->name = $ar['Description'];
                        $platformObjData->description = $ar['Description'];
                        $platformObjData->status = 1;
                        $platformObjData->save();
                    }
                }
            }
        } catch( Exception $e ) {
            Log::error( $user_integration_id . ' - LogiwaApiController - getWareHouseLists - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * set Manully sales order status
     */
    public function getSalesOrderStatuses( $user_id, $user_integration_id ){

        $return_data = true;
        try
        {
            $salesOrderObject = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'order_status'], ['id']);

            if($salesOrderObject)
            {
                //revert object data status
                PlatformObjectData::where([
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $salesOrderObject->id,
                ])
                ->update(['status' => 0]);

                $orderStatus = [
                    0 => 'ALL',
                    1 => 'Entered',
                    2 => 'Approved',
                    4 => 'Started',
                    6 => 'Shipped',
                ];

                foreach( $orderStatus as $key=>$status )
                {
                    $name = ucfirst( strtolower( str_ireplace( "_", " ", $status ) ) );
                    $orderStatus = [
                        'user_id' => $user_id,
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'platform_object_id' => $salesOrderObject->id,
                        'api_id' => $key,
                        'name' => $name,
                        'api_code' => Strtoupper( $status ),
                        'description' => $status,
                        'status' => 1
                    ];

                    $platform_object_data = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_object_data',
                        [
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'platform_object_id' => $salesOrderObject->id,
                            'api_id' => $key,
                            'api_code' => Strtoupper( $status ),
                        ],
                        ['id']
                    );

                    if($platform_object_data) {
                        $this->LogiwaApi->MainModel->makeUpdate('platform_object_data', $orderStatus, ['id'=>$platform_object_data->id]);
                    } else {
                        $this->LogiwaApi->MainModel->makeInsert('platform_object_data', $orderStatus);
                    }
                }
            }
        }
        catch( Exception $e )
        {
            Log::error($user_integration_id.' - LogiwaApiController - getOrderStatus - '.$e->getLine().' - '.$e->getMessage());
            $return_data = $e->getMessage();
        }
        return $return_data;
    }

    /**
     *
     * @return void
     */
    public function getSalesOrders( $user_id, $user_integration_id, $is_initial_sync=0, $user_workflow_rule_id=0, $platform_workflow_rule_id=0 ){
        $return_response = true;
        try{
            $platform_account = $this->LogiwaService->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if( $platform_account )
            {
                $access_token = $this->LogiwaApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );
                $pagesize = 50;
                $OrderDate = "";//date( 'm.d.Y h:i:s' );
                $selectedPageIndex = 1;
                $limit = [];
                $filter = "";

                if( !$is_initial_sync ){//init sync start date
                    $limit = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_urls', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'order_sync_date'
                    ],
                    ['url', 'id']);

                    if ( $limit && $limit->url != '' ) {
                        $filter = $limit->url;
                    }
                }

                if( $filter == "" ){
                    $get_workflow_rule = $this->LogiwaApi->MainModel->getFirstResultByConditions('user_workflow_rule', [
                        'user_integration_id' => $user_integration_id,
                        // 'status' => 1,
                        'platform_workflow_rule_id' => $platform_workflow_rule_id
                    ], [
                        'sync_start_date'
                    ]);

                    if( $get_workflow_rule ){
                        $sync_start_date = date( 'm.d.Y h:i:s', strtotime( $get_workflow_rule->sync_start_date ) );
                        $filter = $sync_start_date."|1";
                    }
                }

                $getFilterData = explode( "|", $filter );
                if( COUNT( $getFilterData ) > 1 ){
                    $OrderDate = $getFilterData[0];
                    $selectedPageIndex = $getFilterData[1];
                }

                $order_status = 0;
                $whareHouseId = 0;

                /*----------------Start to find order status----------------*/
                $orderStatusObject = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'order_status'], ['id']);
                $order_status_name = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_object_data', [
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'platform_object_id' => $orderStatusObject->id,
                    'status' => 1
                ],
                ['api_id']);

                if( $order_status_name )
                {
                    $order_status_filter = $this->LogiwaService->FieldMappingHelper->getMappedDataByName( $user_integration_id, $platform_workflow_rule_id, "sorder_status", ['api_id']);
                    $order_status = $order_status_filter->api_id;
                }

                /*----------------Start to find wareHoose ID ----------------*/
                $warehouseOrderObject = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);
                $warehouse = PlatformObjectData::select('api_id')
                ->where([
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $warehouseOrderObject->id,
                ])
                ->first();
                
                if( $warehouse )
                {
                    $whareHouseId = $warehouse->api_id;
                }

                /*----------------Start to find Client Account ----------------*/
                $DepositorID = 0;
                $clientAccountOrderObject = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'customer_account'], ['id']);
                $clientAccount = PlatformObjectData::select('api_id')
                ->where([
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $clientAccountOrderObject->id,
                ])
                ->first();
                
                if( $clientAccount )
                {
                    $DepositorID = $clientAccount->api_id;
                }


                $postData = [
                    'WarehouseID' => $whareHouseId,
                    'OrderDate' => $OrderDate,//
                    "PageSize" => $pagesize,
                    "SelectedPageIndex" => $selectedPageIndex,
                    "DepositorID" => 11674,//$DepositorID,//11674,//Divi-Test, 11433//Divi
                    "IsGetOrderDetails" => true,
                    "WarehouseOrderStatusID" => [
                        $order_status
                    ]
                ];

                $headers = [
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$access_token,
                ];

                $url = $this->LogiwaApi->ApiURL."IntegrationApi/WarehouseOrderSearch";
                Storage::append( 'Logiwa/'.$user_integration_id.'/SalesOrder/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] Request Param".json_encode( $postData ) );
                $response = $this->LogiwaApi->MainModel->makeCurlRequest( 'POST', $url, json_encode( $postData ), $headers );
                Storage::append( 'Logiwa/'.$user_integration_id.'/SalesOrder/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] Response: ".$response );
                
                $response = json_decode( $response, true );
                
                $lastOrderDateStart = $OrderDate;
                if( $response && COUNT( $response['Data'] ) > 0 ){
                    // $sync_object_id = $this->LogiwaApi->ConnectionHelper->getObjectId('platform_order');
                    $warehouseObject = $this->LogiwaService->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);
                    $orderArr = $response['Data'];

                    if( $orderArr[0]['SelectedPageIndex'] == $orderArr[0]['PageCount'] ){
                        $selectedPageIndex = 0;
                        // $lastOrderDateStart = $orderArr[ ( COUNT( $orderArr ) - 1 ) ]['OrderDate'];
                    }

                    foreach( $orderArr as $orderList ){
                        
                        if( $selectedPageIndex == 0 ){
                            $lastOrderDateStart = $orderList['OrderDate'];
                        }

                        $newLastModifiedDateArr = explode( "-", str_ireplace( [".", " "], "-", $orderList['OrderDate'] ) );
                        $PlannedDeliveryDateArr = explode( "-", str_ireplace( [".", " "], "-", $orderList['PlannedDeliveryDate'] ) );
                        $PlannedShipDateArr = explode( "-", str_ireplace( [".", " "], "-", $orderList['PlannedShipDate'] ) );

                        $newOrder = false;//After updating the ready order status, make sure to save the source side order with the line item.

                        $order = PlatformOrder::where([
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'user_workflow_rule_id' => $user_workflow_rule_id,
                            'order_type' => 'SO',
                            'api_order_id' => $orderList['ID'],
                        ])
                        ->first();

                        if( !$order ){
                            $newOrder = true;

                            $order = new PlatformOrder();
                            $order->user_id = $user_id;
                            $order->user_integration_id = $user_integration_id;
                            $order->platform_id = $this->platformId;
                            $order->user_workflow_rule_id = $user_workflow_rule_id;
                            $order->order_type = 'SO';
                            $order->api_order_id = $orderList['ID'];
                            // $order->sync_status = PlatformStatus::READY;
                        }

                        $order->order_number = $orderList['Code'];
                        $order->api_order_reference = $orderList['DepositorCode'];
                        
                        $order->order_date = $newLastModifiedDateArr[2]."-".$newLastModifiedDateArr[0]."-".$newLastModifiedDateArr[1]." ".$newLastModifiedDateArr[3];
                        $order->order_status = $orderList['WarehouseOrderStatusCode'];
                        $order->warehouse_id = $this->LogiwaService->GetWarehouseLocation( $user_integration_id, $orderList['WarehouseID'], $warehouseObject );
                        // $order->currency = $orderList['currency'];
                        // $order->shipping_total = $orderList['shipping_fee'];
                        // $order->total_tax = $orderList['taxes'];
                        $order->net_amount =$orderList['TotalSalesGrossPrice'];
                        $order->total_amount = $orderList['TotalSalesGrossPrice'];
                        $order->discount_tax = $orderList['TotalSalesDiscount'];
                        
                        if( COUNT( $PlannedDeliveryDateArr ) > 2 )
                            $order->delivery_date = $PlannedDeliveryDateArr[2]."-".$PlannedDeliveryDateArr[0]."-".$PlannedDeliveryDateArr[1]." ".$PlannedDeliveryDateArr[3];
                        
                        if( COUNT( $PlannedShipDateArr ) > 2 )
                            $order->ship_date = $PlannedShipDateArr[2]."-".$PlannedShipDateArr[0]."-".$PlannedShipDateArr[1]." ".$PlannedShipDateArr[3];

                        $order->carrier_code = $orderList['CarrierTrackingNumber'];
                        $order->save();

                        //Order line ites
                        foreach( $orderList['DetailInfo'] as $k=>$orderLines ){

                            //check product exist or not
                            $checkIsProductSyncArr = PlatformProduct::select( 'id', 'product_sync_status' )
                            ->where( [
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'api_product_id' => $orderLines['ID'],
                            ] )
                            ->first();
                            
                            if( !$checkIsProductSyncArr ){
                                $this->getProductDetails( $user_id, $user_integration_id, $orderLines['ID'] );
                            }

                            $orderline = PlatformOrderLine::where([
                                'platform_order_id' => $order->id,
                                'api_order_line_id' => $orderLines['ID'],
                                'api_product_id' => $orderLines['InventoryItemID'],// as a variant id, InventoryItemPackTypeID
                            ])
                            ->first();

                            if( !$orderline ){
                                $orderline = new PlatformOrderLine();
                                $orderline->platform_order_id = $order->id;
                                $orderline->api_order_line_id = $orderLines['ID'];
                                $orderline->api_product_id = $orderLines['InventoryItemID'];// as a variant id
                            }

                            $orderline->product_name = $orderLines['InventoryItemDescription'];
                            $orderline->sku = $orderLines['Barcode'];
                            $orderline->barcode = $orderLines['Barcode'];
                            $orderline->qty = $orderLines['PackQuantity'];
                            $orderline->subtotal = $orderLines['NetCurrencyPrice'];
                            $orderline->total = $orderLines['SalesUnitPrice'];
                            $orderline->price = $orderLines['SalesUnitPrice'];
                            $orderline->unit_price = $orderLines['SalesUnitPrice'];
                            // $orderline->discount_amount = $orderLines['sku_seller_discount'];
                            $orderline->description = $orderLines['DisplayMember'];
                            $orderline->api_code = $orderLines['Code'];
                            $orderline->notes = $orderLines['Notes1'] ?? '';
                            $orderline->save();
                        }

                        //Order Customer
                        $customer = PlatformCustomer::where([
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'api_customer_id' => $orderList['CustomerID'],
                        ])
                        ->first();

                        if( !$customer ){
                            $customer = new PlatformCustomer();
                            $customer->user_id = $user_id;
                            $customer->user_integration_id = $user_integration_id;
                            $customer->platform_id = $this->platformId;
                            $customer->sync_status = PlatformStatus::PENDING;
                            $customer->api_customer_id = $orderList['CustomerID'];
                        }

                        $customer->api_customer_code = $orderList['CustomerCode'];
                        $customer->customer_name = $orderList['CustomerCode'];
                        $customerNameArr = explode( " ", $orderList['CustomerCode'] );
                        $customer->first_name = $customerNameArr[0];
                        $customer->last_name = $customerNameArr[1] ?? '';
                        $customer->email = $orderList['CustomerEmail'];
                        $customer->address1 = $orderList['CustomerAddressDescription'];
                        $customer->type = "Customer";
                        $customer->save();

                        $order->platform_customer_id = $customer->id;//save customer id in order table

                        if( $newOrder ){
                            $order->sync_status = PlatformStatus::READY;
                        }

                        $order->save();
                    }
                }
                
                $url = $lastOrderDateStart."|".( $selectedPageIndex + 1 );
                if ( $limit ) {
                    $this->LogiwaApi->MainModel->makeUpdate('platform_urls', ['url' => $url ], ['id' => $limit->id]);
                } else {
                    $this->LogiwaApi->MainModel->makeInsert('platform_urls', [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url' => $url,
                        'url_name' => 'order_sync_date'
                    ]);
                }
            }
        }catch( Exception $e )
        {
            Log::error( $user_integration_id . ' - LogiwaApiController - getSalesOrder - '.$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     *
     * @return void
     */
    public function getCancelSalesOrders( $user_id, $user_integration_id, $is_initial_sync=0, $user_workflow_rule_id=0, $platform_workflow_rule_id=0 ){
        $return_response = true;
        try{
            $platform_account = $this->LogiwaService->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if( $platform_account )
            {
                $access_token = $this->LogiwaApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );
                $pagesize = 50;
                $LastModifiedDate = "";//date( 'm.d.Y h:i:s' );
                $selectedPageIndex = 1;
                $limit = [];
                $filter = "";

                if( !$is_initial_sync ){//init sync start date
                    $limit = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_urls', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'cancel_order_sync_date'
                    ],
                    ['url', 'id']);

                    if ( $limit && $limit->url != '' ) {
                        $filter = $limit->url;
                    }
                }

                if( $filter == "" ){
                    $get_workflow_rule = $this->LogiwaApi->MainModel->getFirstResultByConditions('user_workflow_rule', [
                        'user_integration_id' => $user_integration_id,
                        // 'status' => 1,
                        'platform_workflow_rule_id' => $platform_workflow_rule_id
                    ], [
                        'sync_start_date'
                    ]);

                    if( $get_workflow_rule ){
                        $sync_start_date = date( 'm.d.Y h:i:s', strtotime( $get_workflow_rule->sync_start_date ) );
                        $filter = $sync_start_date."|1";
                    }
                }

                $getFilterData = explode( "|", $filter );
                if( COUNT( $getFilterData ) > 1 ){
                    $LastModifiedDate = $getFilterData[0];
                    $selectedPageIndex = $getFilterData[1];
                }

                $whareHouseId = 0;

                $folderSuffix = "CancelledOrder";
                $order_status = 99;
                
                /*----------------Start to find wareHoose ID ----------------*/
                $warehouseOrderObject = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);
                $warehouse = PlatformObjectData::select('api_id')
                ->where([
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $warehouseOrderObject->id,
                ])
                ->first();
                
                if( $warehouse )
                {
                    $whareHouseId = $warehouse->api_id;
                }

                $postData = [
                    'WarehouseID' => $whareHouseId,
                    'LastModifiedDate' => $LastModifiedDate,//
                    "PageSize" => $pagesize,
                    "SelectedPageIndex" => $selectedPageIndex,
                    // "IsGetOrderDetails" => true,
                    "WarehouseOrderStatusID" => [
                        $order_status
                    ]
                ];

                $headers = [
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$access_token,
                ];

                
                $url = $this->LogiwaApi->ApiURL."IntegrationApi/WarehouseOrderSearch";
                $response = $this->LogiwaApi->MainModel->makeCurlRequest( 'POST', $url, json_encode( $postData ), $headers );
                Storage::append( 'Logiwa/'.$user_integration_id.'/'.$folderSuffix.'/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $postData )." ".$response );
                
                $response = json_decode( $response, true );
                
                if( $response && COUNT( $response['Data'] ) > 0 ){
                    $orderArr = $response['Data'];

                    if( $orderArr[0]['SelectedPageIndex'] == $orderArr[0]['PageCount'] ){
                        $LastModifiedDate = $orderArr[ ( COUNT( $orderArr ) - 1 ) ]['LastModifiedDate'];
                        $selectedPageIndex = 0;
                    }

                    foreach( $orderArr as $orderList ){
                        $newLastModifiedDateArr = explode( "-", str_ireplace( [".", " "], "-", $orderList['LastModifiedDate'] ) );
                        
                        $order = PlatformOrder::where([
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'user_workflow_rule_id' => $user_workflow_rule_id,
                            'order_type' => 'SO',
                            'api_order_id' => $orderList['ID'],
                        ])
                        ->first();

                        if( $order ){
                            $order->order_status = 'Cancelled';
                            $order->sync_status = PlatformStatus::READY;
                            $order->api_updated_at = $newLastModifiedDateArr[2]."-".$newLastModifiedDateArr[0]."-".$newLastModifiedDateArr[1]." ".$newLastModifiedDateArr[3];
                            $order->save();
                        }
                    }
                }
                
                $url = $LastModifiedDate."|".( $selectedPageIndex + 1 );
                if ( $limit ) {
                    $this->LogiwaApi->MainModel->makeUpdate('platform_urls', ['url' => $url ], ['id' => $limit->id]);
                } else {
                    $this->LogiwaApi->MainModel->makeInsert('platform_urls', [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url' => $url,
                        'url_name' => 'cancel_order_sync_date'
                    ]);
                }
            }
        }catch( Exception $e )
        {
            Log::error( $user_integration_id . ' - LogiwaApiController - getCancelSalesOrders - '.$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     * 
     */
    public function getProductDetails( $user_id, $user_integration_id, $product_id ){
        $platform_account = $this->LogiwaService->getAccountDetails( $user_integration_id ); // get the account information for the integration

        if( $platform_account )
        {
            $access_token = $this->LogiwaApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );
            $postData = [
                "PageSize" => 50,
                "ID" => $product_id
            ];

            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer '.$access_token,
            ];

            $url = $this->LogiwaApi->ApiURL."IntegrationApi/InventoryItemSearch";
            $response = $this->LogiwaApi->MainModel->makeCurlRequest( 'POST', $url, json_encode( $postData ), $headers );
            Storage::append( 'Logiwa/'.$user_integration_id.'/Products-Details/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $postData )." ".$response );

            $response = json_decode( $response, true );
            if( $response && COUNT( $response['Data'] ) > 0 ){
                $this->LogiwaService->storeProductDetails( $user_id, $user_integration_id, $response['Data'][0], $access_token );
            }
        }
    }

    /**
     * 
     */
    public function getClientAccounts( $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->LogiwaService->getAccountDetails( $user_integration_id ); // get the account information for the integration
            if($platform_account)
            {
                $access_token = $this->LogiwaApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );
                $url = $this->LogiwaApi->ApiURL."IntegrationApi/LookUp";
                $postData = [
                    'LookupList' => [
                        1
                    ]
                ];

                $headers = [
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$access_token,
                ];

                $response = $this->LogiwaApi->MainModel->makeCurlRequest( 'POST', $url, json_encode( $postData ), $headers );
                Storage::append( 'Logiwa/'.$user_integration_id.'/ClientAccount-'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".$response );
                $response = json_decode( $response, true );
                if( isset( $response['Lookup'] ) )
                {
                    $customerAccountObject = $this->LogiwaApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'customer_account'], ['id']);

                    //revert object data status
                    PlatformObjectData::where([
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'platform_object_id' => $customerAccountObject->id,
                    ])
                    ->update(['status' => 0]);

                    foreach( $response['Lookup']['ClientList'] as $ar ){
                        $platformObjData = PlatformObjectData::where([
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'platform_object_id' => $customerAccountObject->id,
                            'api_id' => $ar['Id'],
                        ])
                        ->first();

                        if( !$platformObjData ){
                            $platformObjData = new PlatformObjectData();
                            $platformObjData->user_id = $user_id;
                            $platformObjData->platform_id = $this->platformId;
                            $platformObjData->user_integration_id = $user_integration_id;
                            $platformObjData->platform_object_id = $customerAccountObject->id;
                            $platformObjData->api_id = $ar['Id'];
                        }

                        $platformObjData->api_code = $ar['Id'];
                        $platformObjData->name = $ar['Description'];
                        $platformObjData->description = $ar['Description'];
                        $platformObjData->status = 1;
                        $platformObjData->save();
                    }
                }
            }
        } catch( Exception $e ) {
            Log::error( $user_integration_id . ' - LogiwaApiController - getClientAccounts - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /*
     * @Function:        <ExecuteLogiwaEvents>
     * @Author:          Gautam Kakadiya
     * @Created On:      <06-02-2023>
     * @Last Modified By: Gautam Kakadiya
     * @Last Modified:
     * @Description:     < Execute Logiwa Event Methods >
     * ExecuteLogiwaEvents= method: MUTATE - event: TICKET - destination_platform_id: Logiwa - user_id: 109 - user_integration_id: 597 - is_initial_sync: 0 - user_workflow_rule_id: 1163 - source_platform_id: hubspot - platform_workflow_rule_id: 181 - record_id:
     * @Returns:         <   >
     *  https://webhooks.apiworx.net/esb/Logiwa/index.php?for=ticket&uid=278&env=prod
        https://webhooks.apiworx.net/esb/Logiwa/index.php?for=ticket_reply&uid=278&env=prod
    */
    public function ExecuteLogiwaEvents( $method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform = '', $platform_workflow_rule_id = '', $record_id = '' )
    {
        $log = "Method: " . $method . ", event: " . $event . ", source_platform: " . $source_platform . ", is_initial_sync: " . $is_initial_sync.", user_workflow_rule_id: ".$user_workflow_rule_id.", platform_workflow_rule_id: ".$platform_workflow_rule_id;
        Storage::append('Logiwa/' . $user_integration_id . '/ExecuteEvents/' . date('d-m-Y') . '.txt', "[" . date('h:i:s') . "] " . $log);

        $response = true;

        $source_platform_id = 0;
        if ($source_platform != "") {
            $source_platform_id = $this->LogiwaApi->ConnectionHelper->getPlatformIdByName( $source_platform );
        }

        if($method == 'GET' && $event == 'PRODUCT') {
            $response = $this->getProducts( $user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync );
        } else if($method == 'GET' && $event == 'ORDERSTATUS') {
            $response = $this->getSalesOrderStatuses( $user_id, $user_integration_id );
        } else if($method == 'GET' && $event == 'PRODUCTINVENTORY' ) {
            $response = $this->getProductInventories( $user_id, $user_integration_id );
        } else if($method == 'GET' && $event == 'SALESORDER') {
            $response = $this->getSalesOrders( $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $platform_workflow_rule_id );
            // $this->getCancelSalesOrders( $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $platform_workflow_rule_id );
        } else if( $method == 'GET' && $event == 'WAREHOUSELOCATION') {
            $response = $this->getWareHouseLists( $user_id, $user_integration_id );
        }  else if( $method == 'GET' && $event == 'CUSTOMERACCOUNT') {
            $response = $this->getClientAccounts( $user_id, $user_integration_id );
        } 
        return $response;
    }

    /**
     * 
     */
    public function updateOrderLineProductId(){
        $productArr = PlatformProduct::select( 'api_product_id', 'product_name')
        ->where( [
            'user_integration_id' => 740,
            'platform_id' => 60
        ] )
        ->get();

        if( $productArr ){
            foreach( $productArr as $k=>$pr ){
                PlatformOrderLine::where( 'product_name', $pr->product_name )
                ->where( 'platform_order_id', '>=', 596952  )
                ->update( ['api_product_id' => $pr->api_product_id] );

                echo ($k+1).": ".$pr->api_product_id." - ".$pr->product_name;
            }
        }
    }
}
