<?php
namespace App\Http\Controllers\ExactERP;

use Exception;
use DateTime;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\PlatformOrder;
use App\Models\PlatformAccount;
use App\Helper\WorkflowSnippet;
use App\Models\PlatformCustomer;
use App\Models\PlatformOrderLine;
use App\Models\PlatformObjectData;
use App\Models\Enum\PlatformStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Auth;
use App\Models\PlatformOrderShipment;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Models\PlatformOrderShipmentLine;
use App\Http\Controllers\ExactERP\Api\ExactERPApi;
use Illuminate\Support\Facades\Storage;

use function GuzzleHttp\json_decode;

class ExactERPApiController extends ExactERPApi
{
    public $api_domain = "https://start.exactonline.co.uk/api/";
    public static $myPlatform = 'exacterp';
    public $ExactERPApi;
    public $wfSnip;
    public $platformId;

    /**
    * Create a new controller instance.
    *
    * @return void
    */
    public function __construct()
    {
        $this->wfSnip = new WorkflowSnippet();
        $this->ExactERPApi = new ExactERPApi();
        $this->platformId = $this->ExactERPApi->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
    }

    /**
     *
     */
    public function InitiateExactERPAuth(Request $request)
    {
        $platform = self::$myPlatform;
        return view("pages.apiauth.exact_erp_auth", compact('platform'));
    }

    /**
     * https://apps.exactonline.com/gb/en-GB/V2/Manage
     */
    public function ConnectExactERP(Request $request)
    {
        $validator = Validator::make(
            $request->all(), [
                'account_name' => 'required',
                'client_id' => 'required',
                'client_secret' => 'required',
            ]);
        if($this->ExactERPApi->MainModel->checkHtmlTags($request->all())) {
            return back()->with('error', Lang::get('tags.validate'));
        }

        if($validator->fails()) {
            return back()->withErrors($validator);
        } else {
            $account_name = trim($request->account_name);
            //to check whether given account is already in use or not.
            $checkExistingAccount = PlatformAccount::where( ['platform_id' => $this->platformId, 'account_name' => $account_name] )->first();
            if( $checkExistingAccount ){
                return back()->with('error', 'Given details are already in use, Try with other details.');
            }

            $redirect_uri = $this->ExactERPApi->MainModel->makeUrlHttpsForProd( url('/RedirectHandlerExactERP') );
            $state = Auth::user()->id."-|-".$request->user_integration_id."-|-".$account_name."-|-".$request->client_id."-|-".$request->client_secret;//."-|-".$this->api_domain;

            if( $request->client_id && $request->client_secret ) {
                $authorizationUrl = $this->api_domain."oauth2/auth?client_id=".$request->client_id."&redirect_uri=".$redirect_uri."&response_type=code&force_login=0&state=".urlencode( $state );
                return redirect( $authorizationUrl );
            } else {
                Session::put('auth_msg', 'App config not found');
                echo '<script>window.close();</script>';
            }
        }
    }

    /**
     * Undocumented function
     *
     * @param Request $request
     * @return void
     */
    public function RedirectHandlerExactERP(Request $request)
    {
        date_default_timezone_set('UTC');
        if(isset($request->code)) {
            $platform_api_app = true;//PlatformApiApp::where( [ 'platform_id' => $this->platformId] )->first();//['client_id', 'client_secret']);
            if($platform_api_app) {
                $state = $request->state;
                $state_arr = explode('-|-', $state );
                $user_id = $state_arr[0] ?? null;//user primary id
                $user_integration_id = $state_arr[1] ?? null;//user integration id
                $account_name = $state_arr[2] ?? null; // Account Name
                $client_id = $state_arr[3] ?? null;//IP panel client id
                $client_secret = $state_arr[4] ?? null; // IP panel client secret
                if( isset( $user_id ) && isset( $user_integration_id ) && isset( $client_secret ) && isset( $account_name ) && isset( $client_id ) ) {
                    $code = $request->code;
                    $url = $this->api_domain.'oauth2/token';
                    $data = [
                        'redirect_uri' => $this->ExactERPApi->MainModel->makeUrlHttpsForProd( url('/RedirectHandlerExactERP') ),
                        'code' => $code,
                        'grant_type' => 'authorization_code',
                        'client_id' => $client_id,
                        'client_secret' => $client_secret,
                    ];
                    $headers = [
                        "Content-Type: application/x-www-form-urlencoded",
                        "Cookie: ExactOnlineClient=dzlcyxe04GUTRa2FZ1n1wlAGmwJcfAyi7QRX+D6+4IDU7RDEne5OPsyKTpiMgRsjAp837UKzHgcN4PXE1KV4LWDtaOg7BFmJ9o+YVHmG//oIsafMy4T0M3QtcCZ62z7BJdK1Kvn6VpyetWM+Y7AGsneMkV8w1puxfP/20tyrBlo=",
                    ];

                    $curl = curl_init();
                    curl_setopt($curl, CURLOPT_URL, $url);
                    curl_setopt($curl, CURLOPT_POST, true);
                    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query( $data ) );
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($curl);
                    $info = curl_getinfo($curl);
                    curl_close($curl);

                    if($response = json_decode( $response, true) ) {
                        if( isset( $response['access_token'] ) ) {
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
                            $platform_account->app_id = $this->ExactERPApi->MainModel->encrypt_decrypt( $client_id );
                            $platform_account->app_secret = $this->ExactERPApi->MainModel->encrypt_decrypt( $client_secret );
                            $platform_account->refresh_token = $this->ExactERPApi->MainModel->encrypt_decrypt( $response['refresh_token'] );
                            $platform_account->access_token = $this->ExactERPApi->MainModel->encrypt_decrypt( $response['access_token'] );
                            $platform_account->api_domain = $this->api_domain;
                            $platform_account->token_type = $response['token_type'] ?? '';
                            $platform_account->expires_in = $response['expires_in'];
                            $platform_account->token_refresh_time = time();
                            $platform_account->save();

                            // $this->getWareHouseLists( $user_id, $user_integration_id );
                            // $this->getLoginDivision( $user_id, $user_integration_id );
                        } else {
                            if( isset( $response['message'] ) ){
                                $error = $response['message'];
                            }else{
                                $error = "Something went wrong in your account";
                            }
                            echo '<script>alert("'.$error.'");window.close();</script>';
                        }
                        echo '<script>window.close();</script>';
                    } else {
                        $this->ExactERPApi->MainModel->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
                    }
                }
            }
        } else {
            $this->ExactERPApi->MainModel->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');// When code not received from ExactERP
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
            $platform_account = PlatformAccount::select('id', 'app_id', 'token_type', 'access_token', 'refresh_token', 'app_secret', 'api_domain')
            ->where( ['id' => $id, 'platform_id' => $this->platformId] )
            ->first();

            if($platform_account) {
                $client_id = $this->ExactERPApi->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
                $client_secret = $this->ExactERPApi->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
                $refresh_token = $this->ExactERPApi->MainModel->encrypt_decrypt( $platform_account->refresh_token, 'decrypt' );
                $post_data = 'grant_type=refresh_token&client_id='.$client_id.'&client_secret='.$client_secret.'&refresh_token='.urlencode($refresh_token);
                $response = $this->ExactERPApi->makeAPICall( 0, 'oauth2/token', $platform_account, "POST", $post_data, 1 );
                Storage::append( 'ExactERPApi/AuthResponse'.date( 'd-m-Y' ).'_'.$id.'.txt', "[".date( 'H:i:s' )."] ".json_encode($response) );

                if( isset( $response['api_status'] ) && $response['api_status'] == 1 ) {
                    $response = $response['api_data'];
                    $platform_account->expires_in = $response['expires_in'] ?? time();
                    $platform_account->access_token = $this->ExactERPApi->MainModel->encrypt_decrypt( $response['access_token'] );
                    $platform_account->refresh_token = $this->ExactERPApi->MainModel->encrypt_decrypt( $response['refresh_token'] );
                    $platform_account->save();
                    $return_response = $response['access_token'];
                } else if( isset( $response['api_status'] ) && $response['api_status'] == 2 ){
                    $return_response = $response['api_data'];
                } else {
                    if(isset($response['message'])){
                        $return_response = $response['message'];
                    } else {
                        $return_response = "Something went wrong in your account";
                    }
                }
            }
        }
        catch( Exception $e )
        {
            Log::error($id . ' - ExactERPApiController - RefreshToken - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * API Doc URL: https://start.exactonline.nl/docs/HlpRestAPIResourcesDetails.aspx?name=SystemSystemMe
     */
    public function getLoginDivision( $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->ExactERPApi->getAccountDetails( $user_integration_id );
            if( $platform_account ){
                $curl = curl_init();
                curl_setopt_array( $curl, [
                    CURLOPT_URL => $platform_account->api_domain.'v1/current/Me',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer '.$this->ExactERPApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' ),
                        'Accept: application/json'
                    ],
                ]);

                $response = curl_exec($curl);
                curl_close($curl);

                // Storage::append( 'ExactERPApi/API-Calls-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": vl/current/Me" );
                $platformAccount = PlatformAccount::select( 'id', 'region' )->where( 'id', $platform_account->id )->first();
                $platformAccount->region = json_decode($response)->d->results[0]->CurrentDivision;
                $platformAccount->save();
            }
        } catch( Exception $e ) {
            Log::error($user_integration_id . ' - ExactERPApiController - getLoginDivision - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * get warehouse list
     * https://start.exactonline.nl/docs/HlpRestAPIResourcesDetails.aspx?name=InventoryWarehouses
     */
    public function getWareHouseLists( $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->ExactERPApi->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if($platform_account)
            {
                $url = $platform_account->api_domain.'v1/'.$platform_account->region.'/inventory/Warehouses';
                // Storage::append( 'ExactERPApi/API-Calls-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": ".$url );
                $responses = $this->ExactERPApi->makeCurlCall( $url, $platform_account->access_token );
                // dd($responses);
                if( count( $responses['result'] ) > 0 )
                {
                    $salesOrderObject = $this->ExactERPApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);

                    //revert object data status
                    PlatformObjectData::where([
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'platform_object_id' => $salesOrderObject->id,
                    ])
                    ->update(['status' => 0]);

                    foreach( $responses['result'] as $ar ){
                        $platformObjData = PlatformObjectData::where([
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'platform_object_id' => $salesOrderObject->id,
                            'api_id' => $ar->Code,
                        ])
                        ->first();

                        if( !$platformObjData ){
                            $platformObjData = new PlatformObjectData();
                            $platformObjData->user_id = $user_id;
                            $platformObjData->platform_id = $this->platformId;
                            $platformObjData->user_integration_id = $user_integration_id;
                            $platformObjData->platform_object_id = $salesOrderObject->id;
                            $platformObjData->api_id = $ar->Code;
                        }

                        $platformObjData->api_code = $ar->ID;
                        $platformObjData->name = $ar->Description;
                        $platformObjData->description = $ar->Description;
                        $platformObjData->status = 1;
                        $platformObjData->save();
                    }
                } else {
                    $return_response = $responses['info']['http_code'];
                }
            }
        } catch( Exception $e ) {
            Log::error($user_integration_id . ' - ExactERPApiController - getWareHouseLists - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * get Exact ERP Product history
     * https://start.exactonline.nl/docs/HlpRestAPIResourcesDetails.aspx?name=SyncInventoryItemWarehouses
     */
    public function getProducts( $user_id, $user_integration_id, $user_workflow_rule_id=0, $is_initial_sync=false ){
        $return_response = true;

        try{
            $platform_account = $this->ExactERPApi->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if($platform_account)
            {
                $filter = "";//strtotime('-20 minutes');
                $limit = [];

                if( !$is_initial_sync ){//init sync start date
                    $limit = $this->ExactERPApi->MainModel->getFirstResultByConditions('platform_urls', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'product_sync_date'
                    ],
                    ['url', 'id']);

                    if ( $limit && $limit->url != '' ) {
                        $filter = '$filter=Created ge datetime\''.$limit->url."'&";
                    }
                }

                if( $filter == "" ){
                    $get_workflow_rule = $this->ExactERPApi->MainModel->getFirstResultByConditions('user_workflow_rule', [
                        'user_integration_id' => $user_integration_id,
                        'status' => 1,
                        'platform_workflow_rule_id' => $user_workflow_rule_id
                    ], [
                        'sync_start_date'
                    ]);

                    if( $get_workflow_rule ){
                        if( false ){
                            // $filter = "Warehouse eq '".$warehouse."' and Modified ge datetime'".$date_filter."'&$select=Item,Quantity";
                            // $filter = '$filter=Modified eq datetime '.date( 'Y-m-d', strtotime( $get_workflow_rule->sync_start_date ) ).'&';
                        }
                        $filter = '$filter=Created ge datetime\''.date( 'Y-m-d', strtotime( $get_workflow_rule->sync_start_date ) )."T00:00:00Z&'";
                    }
                }

                $select = "Created,Modified,ItemBarcode,ID,Item,ItemCode,ItemDescription,MaximumStock,Warehouse,WarehouseCode,WarehouseDescription";
                $url = $platform_account->api_domain.'v1/'.$platform_account->region.'/inventory/ItemWarehouses?'.$filter.'$select='.$select;
                Storage::append( 'ExactERPApi/'.$user_integration_id.'/Products/'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": ".$url );
                $response = $this->ExactERPApi->makeCurlCall( $url, $platform_account->access_token );
                Storage::append( 'ExactERPApi/'.$user_integration_id.'/Products/'.date( 'd-m-Y' ).'.txt', " Response: ".json_encode($response) );

                if( count( $response['result'] ) > 0 && $response['info']['http_code'] == 200 ) {
                    $this->ExactERPApi->storeProducts( $response['result'], $user_id, $user_integration_id );

                    if ($limit) {
                        $this->ExactERPApi->MainModel->makeUpdate('platform_urls',
                            ['url' => date("Y-m-d").'T'.date("h:i:s").'Z' ],//Y-m-dT00:00:00Z
                            ['id' => $limit->id]
                        );
                    } else {
                        $this->ExactERPApi->MainModel->makeInsert('platform_urls', [
                            'user_integration_id' => $user_integration_id,
                            'user_id' => $user_id,
                            'platform_id' => $this->platformId,
                            'url' => date("Y-m-d").'T'.date("h:i:s").'Z',//Y-m-dT00:00:00Z
                            'url_name' => 'product_sync_date'
                        ]);
                    }
                }

            }
        } catch( Exception $e ) {
            Log::error($user_integration_id . ' - ExactERPApiController - getWareHouseLists - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * get Exact ERP Supplier history
     * https://start.exactonline.nl/docs/HlpRestAPIResourcesDetails.aspx?name=BulkCRMAccounts
     */
    public function getSuppliers( $user_id, $user_integration_id, $user_workflow_rule_id=0, $is_initial_sync=false ){
        $return_response = true;

        try{
            $platform_account = $this->ExactERPApi->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if($platform_account)
            {
                $skiptoken = "";
                if( !$is_initial_sync ){//End sync start token
                    $limit = $this->ExactERPApi->MainModel->getFirstResultByConditions('platform_urls', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'supplier_skiptoken'
                    ],
                    ['url', 'id']);

                    if ( $limit && $limit->url != '' ) {
                        $skiptoken = '$skiptoken=guid\''.$limit->url."'&";
                    }
                }

                if( $skiptoken == "" ){
                    $skiptoken = '$skiptoken=guid\'d39f4f4b-9d63-495f-a60d-08245414cbb0\'';
                }

                $select = '$select=ID,Code,Name,SalesCurrency,Email,AddressLine1,AddressLine2,AddressLine3,Created,Modified,SearchCode,Type,Status,CountryName';
                // $select = '$top=1';
                $url = $platform_account->api_domain.'v1/'.$platform_account->region.'/bulk/CRM/Accounts?$filter=IsSupplier eq true&'.$skiptoken.$select;
                Storage::append( 'ExactERPApi/'.$user_integration_id.'/Suppliers/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".$user_integration_id.": ".$url );
                $response = $this->ExactERPApi->makeCurlCall( $url, $platform_account->access_token );
                Storage::append( 'ExactERPApi/'.$user_integration_id.'/Suppliers/'.date( "d-m-Y" ).'.txt', "[".date( 'h:i:s' )."] Response: ".json_encode($response) );

                if( count( $response['result'] ) > 0 && $response['info']['http_code'] == 200 ) {
                    foreach( $response['result'] as $supplier ){
                        $skiptoken = $supplier->ID;

                        $code = trim( $supplier->Code );
                        $vendor = PlatformCustomer::Where([
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'api_customer_code' => $code,
                            'type' => 'Vendor',
                        ])
                        ->first();

                        if( !$vendor ){
                            $vendor = new PlatformCustomer();
                            $vendor->user_id = $user_id;
                            $vendor->platform_id = $this->platformId;
                            $vendor->user_integration_id = $user_integration_id;
                            $vendor->api_customer_code = $code;
                            $vendor->type = 'Vendor';
                            $vendor->sync_status = PlatformStatus::READY;
                        }

                        $vendor->api_customer_id = $skiptoken;
                        $vendor->customer_name = $supplier->Name;
                        $vendor->email = $supplier->Email;
                        $vendor->address1 = $supplier->AddressLine1;
                        $vendor->address2 = $supplier->AddressLine2;
                        $vendor->address3 = $supplier->AddressLine3;
                        $vendor->email3 = $supplier->SalesCurrency;
                        $vendor->country = $supplier->CountryName;
                        $vendor->api_created_at = $this->ExactERPApi->convertTimeStampToDate( $supplier->Created );
                        $vendor->api_updated_at = $this->ExactERPApi->convertTimeStampToDate( $supplier->Modified );
                        $vendor->account_status = $supplier->Status;
                        $vendor->save();
                    }

                    if ($limit) {
                        $this->ExactERPApi->MainModel->makeUpdate('platform_urls',
                            ['url' => $skiptoken],//d39f4f4b-9d63-495f-a60d-08245414cbb0
                            ['id' => $limit->id]
                        );
                    } else {
                        $this->ExactERPApi->MainModel->makeInsert('platform_urls', [
                            'user_id' => $user_id,
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'url' => $skiptoken,//d39f4f4b-9d63-495f-a60d-08245414cbb0
                            'url_name' => 'supplier_skiptoken'
                        ]);
                    }
                }
            }
        } catch( Exception $e ) {
            Log::error($user_integration_id . ' - ExactERPApiController - getWareHouseLists - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * set Manully
     */
    public function getSalesOrderStatus( $user_id, $user_integration_id ){

        $return_data = true;
        try
        {
            $salesOrderObject = $this->ExactERPApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'order_status'], ['id']);

            if($salesOrderObject)
            {
                //revert object data status
                PlatformObjectData::where([
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $salesOrderObject->id,
                ])
                ->update(['status' => 0]);

                $orderStatuses = [
                    12 => 'Open',
                    20 => 'Partial',
                    21 => 'Complete',
                    45 => 'Cancelled',
                ];

                foreach( $orderStatuses as $key=>$status )
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

                    $platform_object_data = $this->ExactERPApi->MainModel->getFirstResultByConditions('platform_object_data',
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
                        $this->ExactERPApi->MainModel->makeUpdate('platform_object_data', $orderStatus, ['id'=>$platform_object_data->id]);
                    } else {
                        $this->ExactERPApi->MainModel->makeInsert('platform_object_data', $orderStatus);
                    }
                }
            }
        }
        catch( Exception $e )
        {
            Log::error($user_integration_id.' - ExactERPApiController - getOrderStatus - '.$e->getLine().' - '.$e->getMessage());
            $return_data = $e->getMessage();
        }
        return $return_data;

    }

    /**
     * get Sales Order history
     * https://start.exactonline.nl/docs/HlpRestAPIResourcesDetails.aspx?name=BulkSalesOrderSalesOrders
     */
    public function gatSalesOrder( $user_id, $user_integration_id, $user_workflow_rule_id=0, $is_initial_sync=false ){
        $return_response = true;

        try{
            $platform_account = $this->ExactERPApi->getAccountDetails( $user_integration_id ); //get the account information for the integration

            if($platform_account)
            {
                $filter = "";
                $limit = [];

                if( $is_initial_sync ){//init sync start date
                    $get_workflow_rule = $this->ExactERPApi->MainModel->getFirstResultByConditions('user_workflow_rule', [
                        'user_integration_id' => $user_integration_id,
                        'status' => 1,
                        'platform_workflow_rule_id' => $user_workflow_rule_id
                    ], [
                        'sync_start_date'
                    ]);

                    if( $get_workflow_rule ){
                        $filter = '$filter=Created ge datetime\''.date( 'Y-m-d', strtotime( $get_workflow_rule->sync_start_date ) )."T00:00:00Z'&";
                        // $filter = '$filter=Created ge datetime\''.$limit->url."'&";
                    }
                } else {
                    $limit = $this->ExactERPApi->MainModel->getFirstResultByConditions('platform_urls', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'order_sync_date'
                    ],
                    ['url', 'id']);

                    if ( $limit && $limit->url != "" ) {
                        $filter = '$filter=Created ge datetime\''.$limit->url."'&";
                    }
                }

                $select = '$select=OrderNumber,OrderID,YourRef,Created,WarehouseCode,Currency,Status,Modified,DeliverToName,SalesOrderLines';
                $url = $platform_account->api_domain.'v1/'.$platform_account->region.'/salesorder/SalesOrders?'.$filter.$select;
                Storage::append( 'ExactERPApi/'.$user_integration_id.'/SalesOrder/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".$user_integration_id.": ".$url );
                $responseOrders = $this->ExactERPApi->makeCurlCall( $url, $platform_account->access_token );
                Storage::append( 'ExactERPApi/'.$user_integration_id.'/SalesOrder/'.date( "d-m-Y" ).'.txt', "[".date( 'h:i:s' )."] Response: ".json_encode($responseOrders) );

                if( count( $responseOrders['result'] ) > 0 && $responseOrders['info']['http_code'] == 200 ) {
                    $orderStatus = [
                        12 => 'Open',
                        20 => 'Partial',
                        21 => 'Complete',
                        45 => 'Cancelled',
                    ];

                    $warehouseObject = $this->ExactERPApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);

                    foreach( $responseOrders['result'] as $orderList ){
                        $newOrder = false;//After updating the ready order status, make sure to save the source side order with the line item.
                        $order = PlatformOrder::where([
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'user_workflow_rule_id' => $user_workflow_rule_id,
                            'order_type' => 'SO',
                            'order_number' => $orderList->OrderID,
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
                            $order->order_number = $orderList->OrderID;
                            // $order->sync_status = PlatformStatus::READY;
                        }

                        $order->api_order_id = $orderList->OrderNumber;
                        $order->order_date = $this->ExactERPApi->convertTimeStampToDate( $orderList->Created );
                        $order->order_status = $orderStatus[$orderList->Status];
                        $order->warehouse_id = $this->GetWarehouseLocation( $user_integration_id, $orderList->WarehouseCode, $warehouseObject );
                        $order->currency = $orderList->Currency;
                        $order->save();

                        $select = '$select=Quantity,QuantityDelivered,QuantityInvoiced,OrderStatus,UnitPrice,Discount,NetPrice,UnitCode,VATAmount,VATCode,Notes,Margin,LineNumber,Item,ItemCode,ItemDescription,DeliveryStatus,CostPriceFC,InvoiceStatus';
                        $url = $orderList->SalesOrderLines->__deferred->uri.'?'.$select;
                        // Storage::append( 'ExactERPApi/SalesOrder/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] SalesOrderLines: ".$user_integration_id.": ".$url );
                        $responseOrderLines = $this->ExactERPApi->makeCurlCall( $url, $platform_account->access_token );
                        Storage::append( 'ExactERPApi/'.$user_integration_id.'/SalesOrder/'.date( "d-m-Y" ).'.txt', "[".date( 'h:i:s' )."] SalesOrderLines Response: ".json_encode($responseOrderLines) );

                        $net_amount = $total_amount = 0;
                        foreach( $responseOrderLines['result'] as $orderLines ){
                            $orderline = PlatformOrderLine::where([
                                'platform_order_id' => $order->id,
                                'api_order_line_id' => $orderLines->LineNumber,
                                // 'api_product_id' => $orderLines->ItemCode,
                                'sku' => $orderLines->ItemCode,
                            ])
                            ->first();

                            if( !$orderline ){
                                $orderline = new PlatformOrderLine();
                                $orderline->platform_order_id = $order->id;
                                $orderline->api_order_line_id = $orderLines->LineNumber;
                                $orderline->sku = $orderLines->ItemCode;
                            }

                            $orderline->api_product_id = $orderLines->Item;
                            $orderline->product_name = $orderLines->ItemDescription;
                            $orderline->qty = $orderLines->Quantity;
                            $net_amount += $orderLines->NetPrice;
                            $orderline->subtotal = $orderLines->NetPrice;
                            $total_amount += $orderLines->UnitPrice;
                            $orderline->total = $orderLines->UnitPrice;
                            $orderline->price = $orderLines->NetPrice;
                            $orderline->unit_price = $orderLines->UnitPrice;
                            $orderline->discount_amount = $orderLines->Discount;
                            $orderline->notes = $orderLines->Notes;
                            $orderline->save();
                        }

                        $order->net_amount =$net_amount;
                        $order->total_amount = $total_amount;

                        if( $newOrder ){
                            $order->sync_status = PlatformStatus::READY;
                        }
                        $order->save();
                    }
                }

                if ($limit) {
                    $this->ExactERPApi->MainModel->makeUpdate('platform_urls',
                        ['url' => date("Y-m-d").'T'.date("h:i:s").'Z' ],//Y-m-dT00:00:00Z
                        ['id' => $limit->id]
                    );
                } else {
                    $this->ExactERPApi->MainModel->makeInsert('platform_urls', [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url' => date("Y-m-d").'T'.date("h:i:s").'Z',//Y-m-dT00:00:00Z
                        'url_name' => 'order_sync_date'
                    ]);
                }
            }
        } catch( Exception $e ) {
            Log::error($user_integration_id . ' - ExactERPApiController - getWareHouseLists - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * get product inventory
     */
    public function getProductInventory( $user_id, $user_integration_id, $is_initial_sync = false ){
        $return_response = true;

        try{
            $platform_account = $this->ExactERPApi->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if($platform_account)
            {
                $filter = "";//strtotime('-20 minutes');
                $limit = [];

                if( !$is_initial_sync ){//init sync start date
                    $limit = $this->ExactERPApi->MainModel->getFirstResultByConditions('platform_urls', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'product_inventory_sync_date'
                    ],
                    ['url', 'id']);

                    if ( $limit ) {
                        $filter = '$filter=Modified ge datetime\''.$limit->url."'&";
                    }
                }

                $select = "Created,Modified,ItemBarcode,ID,Item,ItemCode,ItemDescription,MaximumStock,Warehouse,WarehouseCode,WarehouseDescription";
                $url = $platform_account->api_domain.'v1/'.$platform_account->region.'/inventory/ItemWarehouses?'.$filter.'$select='.$select;
                // Storage::append( 'ExactERPApi/API-Calls-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": ".$url );
                $response = $this->ExactERPApi->makeCurlCall( $url, $platform_account->access_token );
                // Storage::append( 'ExactERPApi/GetProductInventory'.date("d-m-Y").'.txt', $url." ".json_encode($response) );

                if( count( $response['result'] ) > 0 && $response['info']['http_code'] == 200 ) {
                    $this->ExactERPApi->storeProducts( $response['result'], $user_id, $user_integration_id, 1 );
                }

                if ($limit) {
                    $this->ExactERPApi->MainModel->makeUpdate('platform_urls',
                        ['url' => date("Y-m-d").'T'.date("h:i:s").'Z' ],//Y-m-dT00:00:00Z
                        ['id' => $limit->id]
                    );
                } else {
                    $this->ExactERPApi->MainModel->makeInsert('platform_urls', [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url' => date("Y-m-d").'T'.date("h:i:s").'Z',//Y-m-dT00:00:00Z
                        'url_name' => 'product_inventory_sync_date'
                    ]);
                }
            }
        } catch( Exception $e ) {
            Log::error($user_integration_id . ' - ExactERPApiController - getProductInventory - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * Create purchse order
     * https://start.exactonline.nl/docs/HlpRestAPIResourcesDetails.aspx?name=PurchaseOrderPurchaseOrders
     */
    public function postPurchaseOrder( $user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $record_id=0 ){
        $return_response = true;

        try{
            $platform_account = $this->ExactERPApi->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if($platform_account)
            {
                $limit = 1;
                $offset = 0;

                $where['platform_id'] = $source_platform_id;
                $where['user_integration_id'] = $user_integration_id;
                $where['order_type'] = 'PO';

                if( $record_id ){
                    $where['id'] = $record_id;
                    //$where['sync_status'] = PlatformStatus::FAILED;
                } else {
					$where['sync_status'] = PlatformStatus::READY;
				}

                $platformOrderArr = PlatformOrder::
                    with( 'platformOrderLine' )
                    ->where($where)
                    ->offset($offset)
                    ->limit($limit)
                    ->get();

                $sourcePlatformAccount = $this->ExactERPApi->MainModel->getPlatformAccountByUserIntegration( $user_integration_id, $source_platform_id );

                if( COUNT( $platformOrderArr ) > 0 && $sourcePlatformAccount ){
                    $sync_object_id = $this->ExactERPApi->ConnectionHelper->getObjectId('purchase_order');

                    foreach( $platformOrderArr as $order ){
                        //get supplier details
                        $supplier = PlatformCustomer::select('api_customer_id', 'country')
                        ->where( 'id', $order->platform_customer_id )
                        ->first();

                        if( isset( $supplier->api_customer_id ) ){
                            //get warehouse details
                            $warehouse = PlatformObjectData::select('api_code')
                            ->where( [
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'api_id' => $order->warehouse_id
                            ] )
                            ->first();

                            /**
                             * (United Kingdom)
                             * Non UK suppliers 0% VAT is code 15
                             * for UK purchases it’s code 11
                             */
                            $vatCode = 11;
                            // $default_vatcode = $this->ExactERPApi->FieldMapHelper->getMappedDataByName( $user_integration_id, NULL, "default_vatcode_for_other_country", ['api_id'], "default" );
                            // if ($default_vatcode) {
                            //     $vatCode = $default_vatcode->api_id;
                            // }

                            if( $supplier->country == "United Kingdom" ){
                                // $default_vatcode_uk = $this->ExactERPApi->FieldMapHelper->getMappedDataByName( $user_integration_id, NULL, "default_vatcode_for_uk_country", ['api_id'], "default" );
                                // if ( $default_vatcode_uk ) {
                                    $vatCode = 15;//$default_vatcode_uk->api_id;
                                // }
                            }

                            $purchaseOrderLines = [];
                            foreach( $order->platformOrderLine as $orderLine ){
                                $purchaseOrderLine["Item"] = $orderLine['api_product_id'];
                                $purchaseOrderLine["QuantityInPurchaseUnits"] = $orderLine['qty'];
                                $purchaseOrderLine["NetPrice"] = $orderLine['price'];
                                $purchaseOrderLine["ReceiptDate"] = date( 'Y-m-d', strtotime( $order['order_date'] ) );
                                $purchaseOrderLine["VATCode"] = $vatCode;
                                $purchaseOrderLines[] = $purchaseOrderLine;
                            }

                            $post_data = [
                                'Supplier' => $supplier->api_customer_id,
                                'Warehouse' => $warehouse->api_code,
                                'PurchaseOrderLines' => $purchaseOrderLines,
                                'OrderNumber' => $order->api_order_reference,//order_number,
                                "OrderDate" => date( 'Y-m-d', strtotime( $order->order_date ) ),
                                "Description" => "",
                                "YourRef" => "my ref",
                                "ReceiptDate" => date( 'Y-m-d', strtotime( $order->order_date ) ),
                                "PaymentCondition" => "30",
                                "ExchangeRate" => "5.0",
                            ];

                            Storage::append( 'ExactERPApi/'.$user_integration_id.'/PurchaseOrder/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] Request: ".json_encode( $post_data ) );
                            $url = $platform_account->api_domain.'v1/'.$platform_account->region.'/purchaseorder/PurchaseOrders';
                            $curl = curl_init();

                            curl_setopt_array($curl, [
                                CURLOPT_URL => $url,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_ENCODING => '',
                                CURLOPT_MAXREDIRS => 10,
                                CURLOPT_TIMEOUT => 0,
                                CURLOPT_FOLLOWLOCATION => true,
                                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                                CURLOPT_CUSTOMREQUEST => 'POST',
                                CURLOPT_POSTFIELDS => json_encode( $post_data ),
                                CURLOPT_HTTPHEADER => [
                                    'Authorization: Bearer '.$this->ExactERPApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' ),
                                    'Accept: application/json',
                                    'Content-Type: application/json',
                                    'Cookie: ExactOnlineClient=NI46dUmCBPIp7xj/c+DwLGR8tn/O6ffFk2C9mg6Z5sftj2+EgKSVl5fAkxflQKG1WSMI90udK9ZcbPw9yHFCKx2UDKy7QKc0yQXpvF3lUtDOXlfACU/p71yIwYdzaFmBHX7kNv9SBpHUXTe8r4OiuJ7z+uftAJ6fyFYSlnsWiL4='
                                ],
                            ] );

                            $response = curl_exec($curl);
                            $info = curl_getinfo($curl);
                            curl_close($curl);
                            // Storage::append( 'ExactERPApi/'.$user_integration_id.'/PurchaseOrder/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] Response:  ".json_encode( $post_data ) );
                            Storage::append( 'ExactERPApi/'.$user_integration_id.'/PurchaseOrder/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] Response: ".json_encode( $info ). "\n\n".$response );

                            $response = json_decode( $response );
                            if( $info['http_code'] != 200 ){
                                $return_response = "Get server response code: ".$info['http_code'];
                                PlatformOrder::where( 'id', $order->id )->update( [ 'sync_status' => PlatformStatus::FAILED ] );// Update the sync status of order to FAILED
                                $this->ExactERPApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $order->id, $return_response );
                            }
                            if( isset( $response->error ) ){
                                $return_response = $response->error->message->value;
                                PlatformOrder::where( 'id', $order->id )->update( [ 'sync_status' => PlatformStatus::FAILED ] );// Update the sync status of order to FAILED
                                $this->ExactERPApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $order->id, $return_response );
                            } else {
                                PlatformOrder::where( 'id', $order->id )->update( [ 'sync_status' => PlatformStatus::SYNCED ] );// Update the sync status of order to SYNCED
                                $this->ExactERPApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $order->id, null );
                            }
                        } else {
                            $return_response = "Supplier details not found";
                            PlatformOrder::where( 'id', $order->id )->update( [ 'sync_status' => PlatformStatus::FAILED ] );// Update the sync status of order to FAILED
                            $this->ExactERPApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $order->id, $return_response );
                        }
                    }
                }
            }
        } catch( Exception $e ) {
            Log::error($user_integration_id . ' - ExactERPApiController - postPurchaseOrder - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * get Purchase order Receipt
     * https://start.exactonline.nl/docs/HlpRestAPIResourcesDetails.aspx?name=PurchaseOrderGoodsReceipts
     *
     * https://start.exactonline.co.uk/api/v1/113480/purchaseorder/GoodsReceipts?$top=1&$filter=Created ge datetime'2022-04-03T00:00:00Z'
     */
    public function getPurchaseOrderReceipt( $user_id, $user_integration_id, $user_workflow_rule_id=0, $is_initial_sync=0 ){
        $return_response = true;

        try{
            $platform_account = $this->ExactERPApi->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if($platform_account)
            {
                $date = new DateTime();
                $startDate = $date->modify( '-1 day' )->format('m/d/Y');
                $limit = [];

                if( $is_initial_sync ){
                    $get_workflow_rule = $this->ExactERPApi->MainModel->getFirstResultByConditions('user_workflow_rule', [
                        'user_integration_id' => $user_integration_id,
                        'status' => 1,
                        'platform_workflow_rule_id' => $user_workflow_rule_id
                    ], [
                        'sync_start_date'
                    ]);

                    if( $get_workflow_rule ){
                        $startDate =  date('m/d/Y', strtotime($get_workflow_rule->sync_start_date));
                    }
                } else {
                    $limit = $this->ExactERPApi->MainModel->getFirstResultByConditions('platform_urls', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'por_sync_date'
                    ],
                    ['url', 'id']);

                    if ( $limit ) {
                        $startDate = $limit->url;
                    }
                }

                $startDate = date( "Y-m-d", strtotime( $startDate ) ).'T'.date( "h:i:s", strtotime( $startDate ) ).'Z';
                $select = '$select=ID,Created,Creator,CreatorFullName,Description,Document,DocumentSubject,EntryNumber,GoodsReceiptLineCount,GoodsReceiptLines,GoodsReceiptLines,Modifier,ModifierFullName,ReceiptDate,ReceiptNumber,Remarks,Supplier,SupplierCode,SupplierContact,SupplierContactFullName,SupplierName,Warehouse,WarehouseCode,WarehouseDescription,YourRef';
                $filter = '$filter=Created ge datetime\''.$startDate.'\' and Status eq 50';
                $url = $platform_account->api_domain.'v1/'.$platform_account->region.'/purchaseorder/GoodsReceipts?'.$filter.'&'.$select;
                Storage::append( 'ExactERPApi/'.$user_integration_id.'/API-Calls-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": ".$url );
                $response = $this->ExactERPApi->makeCurlCall( $url, $platform_account->access_token );
                Storage::append( 'ExactERPApi/'.$user_integration_id.'/GetPurchaseOrderReceipt'.date("d-m-Y").'.txt', $url." ".json_encode($response) );

                if( count( $response['result'] ) > 0 && $response['info']['http_code'] == 200 ) {
                    foreach( $response['result'] as $por ){
                        $url = $por->GoodsReceiptLines->__deferred->uri;
                        $responseLines = $this->ExactERPApi->makeCurlCall( $url, $platform_account->access_token );
                        $orderShipment = PlatformOrderShipment::where([
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'shipment_id' => $por->ReceiptNumber,
                            'order_id' => $responseLines['result'][0]->PurchaseOrderNumber,
                            'type' => "Shipment",
                        ])
                        ->first();

                        if( !$orderShipment ){
                            $orderShipment = new PlatformOrderShipment();

                            $orderShipment->user_id = $user_id;
                            $orderShipment->user_integration_id = $user_integration_id;
                            $orderShipment->platform_id = $this->platformId;
                            $orderShipment->shipment_id = $por->ReceiptNumber;
                            $orderShipment->order_id = $responseLines['result'][0]->PurchaseOrderNumber;
                            $orderShipment->type = "Shipment";
                            $orderShipment->sync_status = PlatformStatus::READY;
                        }

                        $orderShipment->shipment_sequence_number = $por->EntryNumber;
                        $orderShipment->warehouse_id = $por->WarehouseCode;
                        $orderShipment->tracking_url = $por->__metadata->uri;
                        $orderShipment->event_owner_id = trim( $por->SupplierCode );
                        $orderShipment->realease_date = $this->ExactERPApi->convertTimeStampToDate( $por->ReceiptDate );
                        $orderShipment->save();

                        $orderShipmentLine = PlatformOrderShipmentLine::where([
                            'platform_order_shipment_id' => $orderShipment->id,
                            'row_id' => $responseLines['result'][0]->LineNumber,
                            'product_id' => $responseLines['result'][0]->ItemCode,
                        ])
                        ->first();

                        if( !$orderShipmentLine ){
                            $orderShipmentLine = new PlatformOrderShipmentLine();
                            $orderShipmentLine->platform_order_shipment_id = $orderShipment->id;
                            $orderShipmentLine->row_id = $responseLines['result'][0]->LineNumber;
                            $orderShipmentLine->product_id = $responseLines['result'][0]->ItemCode;
                            $orderShipmentLine->sync_status = PlatformStatus::READY;
                        }

                        $orderShipmentLine->warehouse_id = $por->WarehouseCode;
                        $orderShipmentLine->location_id = $responseLines['result'][0]->LocationCode;
                        $orderShipmentLine->quantity = $responseLines['result'][0]->QuantityOrdered;
                        $orderShipmentLine->sent_quantity = (int)$responseLines['result'][0]->QuantityReceived;
                        $orderShipmentLine->save();
                    }
                }

                if ($limit) {
                    $this->ExactERPApi->MainModel->makeUpdate('platform_urls',
                        ['url' => date("Y-m-d h:i:s") ],
                        ['id' => $limit->id]
                    );
                } else {
                    $this->ExactERPApi->MainModel->makeInsert('platform_urls', [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url' => date("Y-m-d h:i:s"),
                        'url_name' => 'por_sync_date'
                    ]);
                }
            }
        } catch( Exception $e ) {
            Log::error($user_integration_id . ' - ExactERPApiController - getPurchaseOrderReceipt - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * Create purchse order
     * https://start.exactonline.nl/docs/HlpRestAPIResourcesDetails.aspx?name=PurchaseOrderPurchaseOrders
     */
    public function postTransferOrder( $user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $record_id=0 ){
        $return_response = true;

        try{
            $platform_account = $this->ExactERPApi->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if($platform_account)
            {
                $limit = 10;
                $offset = 0;

                $where['platform_id'] = $source_platform_id;
                $where['user_integration_id'] = $user_integration_id;
                $where['order_type'] = 'TO';
                $where['sync_status'] = PlatformStatus::READY;

                if( $record_id ){
                    $where['id'] = $record_id;
                    $where['sync_status'] = PlatformStatus::FAILED;
                }

                $platformOrderArr = PlatformOrder::
                    with( 'shipments' )
                    ->where($where)
                    ->offset($offset)
                    ->limit($limit)
                    ->get();

                $sourcePlatformAccount = $this->ExactERPApi->MainModel->getPlatformAccountByUserIntegration( $user_integration_id, $source_platform_id );

                if( COUNT( $platformOrderArr ) > 0 && $sourcePlatformAccount ){
                    $sync_object_id = $this->ExactERPApi->ConnectionHelper->getObjectId('transfer_order');

                    foreach( $platformOrderArr as $order ){

                        $warehouseTransferLines = [];
                        foreach( $order->shipments as $orderLine ){
                            $purchaseOrderLine["Item"] = $orderLine->platformShippingLines[0]->product_id;
                            $purchaseOrderLine["Quantity"] = $orderLine->platformShippingLines[0]->sent_quantity;
                            $warehouseTransferLines[] = $purchaseOrderLine;
                        }

                        //get warehouse details
                        $warehouseTo = PlatformObjectData::select('api_code')
                        ->where( [
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'api_id' => $order->shipments[0]->to_warehouse_id
                        ] )
                        ->first();

                        $warehouseFrom = PlatformObjectData::select('api_code')
                        ->where( [
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'api_id' => $order->shipments[0]->warehouse_id
                        ] )
                        ->first();

                        $post_data = [
                            'WarehouseFrom' => $warehouseFrom->api_code,
                            'WarehouseTo' => $warehouseTo->api_code,
                            'WarehouseTransferLines' => $warehouseTransferLines,
                            "EntryDate" => date( 'Y-m-d', strtotime( $order->order_date ) ),
                        ];

                        $url = $platform_account->api_domain.'v1/'.$platform_account->region.'/inventory/WarehouseTransfers';
                        Storage::append( 'ExactERPApi/'.$user_integration_id.'/API-Calls-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": ".$url );
                        $curl = curl_init();

                        curl_setopt_array($curl, [
                            CURLOPT_URL => $url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING => '',
                            CURLOPT_MAXREDIRS => 10,
                            CURLOPT_TIMEOUT => 0,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST => 'POST',
                            CURLOPT_POSTFIELDS => json_encode( $post_data ),
                            CURLOPT_HTTPHEADER => [
                                'Authorization: Bearer '.$this->ExactERPApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' ),
                                'Accept: application/json',
                                'Content-Type: application/json',
                                'Cookie: ExactOnlineClient=NI46dUmCBPIp7xj/c+DwLGR8tn/O6ffFk2C9mg6Z5sftj2+EgKSVl5fAkxflQKG1WSMI90udK9ZcbPw9yHFCKx2UDKy7QKc0yQXpvF3lUtDOXlfACU/p71yIwYdzaFmBHX7kNv9SBpHUXTe8r4OiuJ7z+uftAJ6fyFYSlnsWiL4='
                            ],
                        ] );

                        $response = curl_exec($curl);
                        $info = curl_getinfo($curl);
                        curl_close($curl);
                        Storage::append( 'ExactERPApi/'.$user_integration_id.'/postTransferOrder'.date("d-m-Y").'.txt', $url." ".json_encode($response) );
                        $updateOrderStatus = PlatformOrder::find( $order->id );//select( 'id', 'sync_status' )->where( 'id', $order->id )->first();
                        if( $info['http_code'] == 500 ){
                            $response = json_decode( $response );
                            $return_response = $response->error->message->value;
                            $updateOrderStatus->sync_status = PlatformStatus::FAILED;
                            $updateOrderStatus->save();
                            $this->ExactERPApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $order->id, $return_response );
                        } else if( $info['http_code'] == 200 ){
                            $response = json_decode( $response );
                            $updateOrderShipment = PlatformOrderShipment::select( 'id', 'sync_status', 'shipment_id' )->where( 'platform_order_id', $order->id )->first();
                            $updateOrderShipment->shipment_id = $response->d->TransferNumber;
                            $updateOrderShipment->sync_status = PlatformStatus::SYNCED;
                            $updateOrderShipment->save();

                            //set TOR order details
                            $orderNew = $updateOrderStatus->replicate();
                            $orderNew->platform_id = $this->platformId;
                            $orderNew->shipment_status = PlatformStatus::PENDING;
                            $orderNew->created_at = Carbon::now();
                            $orderNew->updated_at = Carbon::now();
                            $orderNew->notes = "Replicate Order ID: ".$order->id." And Platform is: ".$source_platform_id;
                            $orderNew->linked_id = $order->id;
                            $orderNew->sync_status = PlatformStatus::PENDING;
                            $orderNew->save();

                            $updateOrderStatus->linked_id = $orderNew->id;
                            $updateOrderStatus->sync_status = PlatformStatus::SYNCED;
                            $updateOrderStatus->save();
                            $this->ExactERPApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $order->id, null );
                        }
                    }
                }
            }
        } catch( Exception $e ) {
            Log::error($user_integration_id . ' - ExactERPApiController - postTransferOrder - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * get Transfer order Receipt
     *
     * @example location
     * https://start.exactonline.nl/docs/HlpRestAPIResourcesDetails.aspx?name=InventoryWarehouseTransfers?$top=1&$filter=Created ge datetime'2022-04-03T00:00:00Z'
     * https://docs.google.com/document/d/1E_6995Dyjt1f-CdoNUNxTbEu1rid7oHTAAJc9zaEs8Y/edit?usp=sharing
     * 109, 686, 53, 57, 1342, 0
     */
    public function getTransferOrderReceipt( $user_id, $user_integration_id, $destination_platform_id, $source_platform_id, $user_workflow_rule_id, $is_initial_sync=0 ){
        $return_response = true;
        try{
            $platform_account = $this->ExactERPApi->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if($platform_account)
            {
                $date = new DateTime();
                $startDate = $date->modify( '-1 day' )->format('m/d/Y');
                $limit = [];

                if( $is_initial_sync ){
                    $get_workflow_rule = $this->ExactERPApi->MainModel->getFirstResultByConditions('user_workflow_rule', [
                        'user_integration_id' => $user_integration_id,
                        'status' => 1,
                        'platform_workflow_rule_id' => $user_workflow_rule_id
                    ], [
                        'sync_start_date'
                    ]);

                    if( $get_workflow_rule ){
                        $startDate =  date('m/d/Y', strtotime($get_workflow_rule->sync_start_date));
                    }
                } else {
                    $limit = $this->ExactERPApi->MainModel->getFirstResultByConditions('platform_urls', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'pot_sync_date'
                    ],
                    ['url', 'id']);

                    if ( $limit ) {
                        $startDate = $limit->url;
                    }
                }

                $startDate = date( "Y-m-d", strtotime( $startDate ) ).'T'.date( "h:i:s", strtotime( $startDate ) ).'Z';
                $select = '$select=TransferID,Created,Creator,CreatorFullName,Description,Division,EntryDate,Modified,Modifier,ModifierFullName,PlannedDeliveryDate,PlannedReceiptDate,Remarks,Source,Status,TransferDate,TransferNumber,WarehouseFrom,WarehouseFromCode,WarehouseFromDescription,WarehouseTo,WarehouseToCode,WarehouseToDescription,WarehouseTransferLines';
                $filter = '$filter=Modified ge datetime\''.$startDate.'\'';
                $url = $platform_account->api_domain.'v1/'.$platform_account->region.'/inventory/WarehouseTransfers?'.$filter.'&'.$select;
                Storage::append( 'ExactERPApi/'.$user_integration_id.'/API-Calls-'.date( 'd-m-Y' ).'.txt', "[".date( 'd-m-Y h:i:s' )."] ".$user_integration_id.": ".$url );
                $response = $this->ExactERPApi->makeCurlCall( $url, $platform_account->access_token );
                Storage::append( 'ExactERPApi/'.$user_integration_id.'/GetTransferOrderReceipt'.date("d-m-Y").'.txt', $url." ".json_encode($response) );

                if( count( $response['result'] ) > 0 && $response['info']['http_code'] == 200 ) {
                    foreach( $response['result'] as $tor ){
                        $url = $tor->WarehouseTransferLines->__deferred->uri;
                        $responseLines = $this->ExactERPApi->makeCurlCall( $url, $platform_account->access_token );

                        $where = [
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $destination_platform_id,
                            'shipment_id' => $tor->TransferNumber,
                            'type' => "Transfer",
                        ];

                        // get order id base by shipment/transfer number
                        $getShipTrans = PlatformOrderShipment::select('order_id', 'platform_order_id')
                        ->where( $where )
                        ->first();

                        if( isset( $getShipTrans ) )
                        {
                            $orderShipment = PlatformOrderShipment::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'shipment_id' => $tor->TransferNumber,
                                'order_id' => $getShipTrans->order_id,
                                'type' => "Transfer",
                            ])
                            ->first();

                            if( !$orderShipment ){
                                $orderShipment = new PlatformOrderShipment();

                                $orderShipment->user_id = $user_id;
                                $orderShipment->user_integration_id = $user_integration_id;
                                $orderShipment->platform_id = $this->platformId;
                                $orderShipment->shipment_id = $tor->TransferNumber;
                                $orderShipment->order_id = $getShipTrans->order_id;
                                $orderShipment->type = "Transfer";
                                $orderShipment->sync_status = PlatformStatus::READY;
                            }

                            $orderShipment->platform_order_id = $getShipTrans->platform_order_id;
                            $orderShipment->shipment_sequence_number = $responseLines['result'][0]->LineNumber;
                            $orderShipment->warehouse_id = $tor->WarehouseFromCode;
                            $orderShipment->to_warehouse_id = $tor->WarehouseToCode;
                            $orderShipment->tracking_url = $tor->__metadata->uri;
                            $orderShipment->realease_date = $this->ExactERPApi->convertTimeStampToDate( $tor->PlannedReceiptDate );
                            $orderShipment->save();

                            $orderShipmentLine = PlatformOrderShipmentLine::where([
                                'platform_order_shipment_id' => $orderShipment->id,
                                'row_id' => $getShipTrans->order_id,
                                'product_id' => $responseLines['result'][0]->ItemCode,
                            ])
                            ->first();

                            if( !$orderShipmentLine ){
                                $orderShipmentLine = new PlatformOrderShipmentLine();
                                $orderShipmentLine->platform_order_shipment_id = $orderShipment->id;
                                $orderShipmentLine->row_id = $getShipTrans->order_id;
                                $orderShipmentLine->product_id = $responseLines['result'][0]->ItemCode;
                                $orderShipmentLine->sync_status = PlatformStatus::READY;
                            }

                            $orderShipmentLine->warehouse_id = $tor->WarehouseFromCode;
                            $orderShipmentLine->quantity = $responseLines['result'][0]->Quantity;
                            $orderShipmentLine->sent_quantity = (int)$responseLines['result'][0]->Quantity;
                            $orderShipmentLine->save();

                            $updateOrderStatus = PlatformOrder::select( 'id', 'sync_status' )->where( 'id', $getShipTrans->platform_order_id )->first();
                            $updateOrderStatus->sync_status = PlatformStatus::READY;
                            $updateOrderStatus->save();
                        }
                    }
                }

                if ($limit) {
                    $this->ExactERPApi->MainModel->makeUpdate('platform_urls',
                        ['url' => date("Y-m-d h:i:s") ],
                        ['id' => $limit->id]
                    );
                } else {
                    $this->ExactERPApi->MainModel->makeInsert('platform_urls', [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url' => date("Y-m-d h:i:s"),
                        'url_name' => 'pot_sync_date'
                    ]);
                }
            }
        } catch( Exception $e ) {
            Log::error($user_integration_id . ' - ExactERPApiController - getPurchaseOrderReceipt - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /*
     * Execute ExactERP Event Methods
     * ExecuteExactERPEvents= method: MUTATE - event: TICKET - destination_platform_id: ExactERP - user_id: 109 - user_integration_id: 597 - is_initial_sync: 0 - user_workflow_rule_id: 1162 - source_platform_id: whmcs - platform_workflow_rule_id: 176 - record_id:
     */
    public function ExecuteExactERPEvents( $method='', $event='', $destination_platform='', $user_id='', $user_integration_id='', $is_initial_sync=0, $user_workflow_rule_id='', $source_platform='', $platform_workflow_rule_id='', $record_id='' )
    {
        // Log::info("ExecuteExactERPEvents- Method: ".$method.", event: ".$event.", is_initial_sync: ".$is_initial_sync);
        $source_platform_id = 0;
        if( $source_platform != "" ){
            $source_platform_id = $this->ExactERPApi->ConnectionHelper->getPlatformIdByName($source_platform);
        }

        $destination_platform_id = 0;
        if( $source_platform != "" ){
            $destination_platform_id = $this->ExactERPApi->ConnectionHelper->getPlatformIdByName($destination_platform);
        }

        $response = true;
        if($method == 'GET' && $event == 'LOGINDIVISION') {
            $response = $this->getLoginDivision( $user_id, $user_integration_id );
        } else if($method == 'GET' && $event == 'ORDERSTATUS') {
            $response = $this->getSalesOrderStatus( $user_id, $user_integration_id );
        } else if($method == 'GET' && $event == 'WAREHOUSELOCATION') {
            $response = $this->getWareHouseLists( $user_id, $user_integration_id );
        } else if($method == 'GET' && $event == 'PRODUCT') {
            $response = $this->getProducts( $user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync );
        } else if($method == 'GET' && $event == 'VENDOR') {
            $response = $this->getSuppliers( $user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync );
        } else if($method == 'GET' && $event == 'SALESORDER') {
            $response = $this->gatSalesOrder( $user_id, $user_integration_id, $is_initial_sync );
        } else if($method == 'GET' && $event == 'PRODUCTINVENTORY') {
            $response = $this->getProductInventory( $user_id, $user_integration_id );
        } else if($method == 'MUTATE' && $event == 'PURCHASEORDER') {
            $response = $this->postPurchaseOrder( $user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $record_id );
        } else if($method == 'GET' && $event == 'GETPURCHASERECEIPT') {
            $response = $this->getPurchaseOrderReceipt( $user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync );
        } else if($method == 'MUTATE' && $event == 'TRANSFERORDER') {
            $response = $this->postTransferOrder( $user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $record_id );
        } else if($method == 'GET' && $event == 'GETTRANSFERRECEIPT') {
            $response = $this->getTransferOrderReceipt( $user_id, $user_integration_id, $destination_platform_id, $source_platform_id, $user_workflow_rule_id, $is_initial_sync );
        }
        return $response;
    }

    /*
     * Get Order Location and Update
     */
    public function GetWarehouseLocation( $user_integration_id, $warehouse_id, $warehouseObject )
    {
        $platformObjData = PlatformObjectData::select( 'api_id' )
        ->where([
            'platform_id' => $this->platformId,
            'user_integration_id' => $user_integration_id,
            'platform_object_id' => $warehouseObject->id,
            'api_id' => $warehouse_id,
        ])
        ->first();

        return $platformObjData->api_id;
    }
}
