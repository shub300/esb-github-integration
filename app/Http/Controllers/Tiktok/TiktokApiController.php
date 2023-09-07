<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Tiktok\Api\TiktokApi;

use DateTime;
use Exception;
use Illuminate\Http\Request;
use App\Models\PlatformOrder;
use App\Models\PlatformAccount;
use App\Models\PlatformProduct;
use App\Models\PlatformOrderLine;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tiktok\TiktokService;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformCustomer;
use App\Models\PlatformObjectData;
use App\Models\PlatformProductDetailAttribute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Session;
use App\Models\PlatformProductInventory;
use App\Models\PlatformProductPriceList;
use DateTimeZone;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class TiktokApiController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $app_secret = "7e68826e481427e48d981417272c9495889f7c6f";
    public $app_id = "68pf7njbhns29";
    public $api_domain = "";
    public static $myPlatform = 'tiktok';
    public $TiktokApi;
    public $TiktokService;
    public $platformId;

    /**
     *
     */
    public function __construct()
    {
        $this->TiktokApi = new TiktokApi();
        $this->TiktokService = new TiktokService();
        $this->platformId = $this->TiktokApi->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
        $isLiveEnv = false;
        $this->api_domain = ( $isLiveEnv ) ? "open-api-sandbox" : "open-api";

    }

    /**
     *
     */
    public function InitiateTiktokAuth(Request $request)
    {
        $platform = self::$myPlatform;
        return view("pages.apiauth.tiktok_auth", compact('platform'));
    }

    /**
     *
     */
    public function ConnectTiktokAuth(Request $request)
    {
        $validator = Validator::make(
            $request->all(), [
                'account_name' => 'required',
            ]);
        if($this->TiktokApi->MainModel->checkHtmlTags($request->all())) {
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

            $state = Auth::user()->id."-|-".$request->user_integration_id."-|-".$account_name."-|-".$this->app_id."-|-".$this->app_secret;//."-|-".$request->marketplace_id;
            if( $this->app_id && $this->app_secret ) {
                $authorizationUrl = Config::get('apiconfig.TiktokAuthUrl')."oauth/authorize?app_key=".urlencode( $this->app_id )."&state=".urlencode( $state );
                return redirect($authorizationUrl);
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
     * ROW_BZgoxAAAAAAd8kczDWC1TxWkMh_6ENnkHvh0dRXtoLRtF100jXsxUpG0balC2i29jv4LWSG6OGAn1sqFBLAtqjel3hjP4Pv4
     */
    public function RedirectHandlerTiktok(Request $request)
    {
        date_default_timezone_set('UTC');
        if(isset($request->code))
        {
            $platform_api_app = true;//PlatformApiApp::where( [ 'platform_id' => $this->platformId] )->first();//['client_id', 'client_secret']);
            if($platform_api_app)
            {
                $state = $request->state;
                $state_arr = explode('-|-', urldecode( $state ) );

                // Valid request
                $user_id = $state_arr[0] ?? null;//user primary id
                $user_integration_id = $state_arr[1] ?? null;//user integration id
                $account_name = $state_arr[2] ?? null; // Account name
                $app_id = $this->app_id;//$state_arr[3] ?? null;//IP panel app id
                $app_secret = $this->app_secret;//$state_arr[4] ?? null; // IP panel app secret
                if( isset( $user_id ) && isset( $user_integration_id ) && isset($app_secret ) && isset( $account_name ) && isset($app_id ) )
                {
                    $code = $request->code;
                    $url = Config::get('apiconfig.TiktokAuthUrl').'api/v2/token/get?app_key='.$app_id.'&auth_code='.$code.'&app_secret='.$app_secret.'&grant_type=authorized_code';
                    $response = $this->TiktokApi->makeAPICall( $url, 'GET' );
                    Storage::append( 'Tiktok/'.date( 'd-m-Y' ).'/AuthResponse_'.$user_integration_id.'.txt', json_encode($response) );

                    if( $response['api_status'] )
                    {
                        $platform_account = PlatformAccount::where([
                            'user_id' => $user_id,
                            'platform_id' => $this->platformId,
                            'account_name' => $account_name
                        ])
                        ->first();

                        if( !$platform_account ){
                            $platform_account = new PlatformAccount();
                        }

                        $response = $response['api_data'];

                        $platform_account->user_id = $user_id;
                        $platform_account->platform_id = $this->platformId;
                        $platform_account->account_name = $account_name;
                        $platform_account->app_id = $this->TiktokApi->MainModel->encrypt_decrypt( $app_id );
                        $platform_account->app_secret = $this->TiktokApi->MainModel->encrypt_decrypt( $app_secret );
                        $platform_account->refresh_token = $this->TiktokApi->MainModel->encrypt_decrypt( $response['refresh_token'] );
                        $platform_account->access_token = $this->TiktokApi->MainModel->encrypt_decrypt( $response['access_token'] );
                        $platform_account->api_domain = $this->api_domain;
                        $platform_account->token_type = $response['token_type'] ?? '';
                        $platform_account->role_arn = $response['seller_name'];
                        $platform_account->installation_instance_id = $response['open_id'];
                        $platform_account->expires_in = ( round( $response['access_token_expire_in'] - time(), 0 ) - 84000 ); // unix to second convert using -1 day
                        $platform_account->token_refresh_time = time();
                        $platform_account->allow_refresh = 1;
                        $platform_account->save();

                        // $this->getTiktokShopId( $user_id, $user_integration_id );//get connected account shop details
                        // $this->getWareHouseLists( $user_id, $user_integration_id );//get connected account warehouse details
                        // $this->getTiktokSalesOrderStatus( $user_id, $user_integration_id );//get connected account order statuses
                    }
                    else
                    {
                        $error = $response['message'];
                        echo '<script>alert("'.$error.'");window.close();</script>';
                    }
                    echo '<script>window.close();</script>';
                }
            }
        }
        else
        {
            // When code not received from Tiktok
            $this->TiktokApi->MainModel->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
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
            $platform_account = PlatformAccount::select('id', 'app_id', 'refresh_token', 'app_secret', 'api_domain')
                                ->where( ['id' => $id, 'platform_id' => $this->platformId] )
                                ->first();

            if($platform_account)
            {
                $app_key = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
                $app_secret = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
                $refresh_token = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->refresh_token, 'decrypt' );

                $url = Config::get('apiconfig.TiktokAuthUrl')."api/v2/token/refresh?app_key=$app_key&refresh_token=$refresh_token&app_secret=$app_secret&grant_type=refresh_token";
                $headers = [
                    "Accept: application/json",
                ];

                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
                curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $responseObj = curl_exec($curl);
                $response = json_decode( $responseObj, true );
                Storage::append( 'Tiktok/'.date( 'd-m-Y' ).'/AuthRefreshResponse_'.$id.'.txt', json_encode($response) );

                if( $response['code'] == 0 && $response['message'] === "success" )
                {
                    $response = $response['data'];

                    $platform_account->access_token = $this->TiktokApi->MainModel->encrypt_decrypt( $response['access_token'] );
                    $platform_account->refresh_token = $this->TiktokApi->MainModel->encrypt_decrypt( $response['refresh_token'] );
                    $platform_account->api_domain = $this->api_domain;
                    $platform_account->token_type = $response['token_type'] ?? '';
                    $platform_account->role_arn = $response['seller_name'];
                    $platform_account->installation_instance_id = $response['open_id'];
                    $platform_account->expires_in = ( round( $response['access_token_expire_in'] - time(), 0 ) - 84000 ); // unix to second convert using -1 day
                    $platform_account->token_refresh_time = time();
                    $platform_account->allow_refresh = 1;
                    $platform_account->save();
                    $return_response = $response['access_token'];
                }
                else
                {
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
            Log::error($id . ' - TiktokApiController - RefreshToken - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
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
     * Get Tiktok products
     * https://partner.tiktokshop.com/doc/page/262788?external_id=262788
     * search_status: 0-all、1-draft、2-pending、3-failed、4-live、5-seller_deactivated、6-platform_deactivated、7-freeze
     */
    public function getTiktokProduct( $user_id, $user_integration_id, $is_initial_sync=0 ){
        $return_response = true;
        try{
            $platform_account = $this->TiktokService->getAccountDetails( $user_integration_id );

            if( $platform_account )
            {
                $pagesize = 50;
                $page_number = 1;
                $create_time_from = 0;
                $create_time_to = time();
                $limit = [];

                $app_key = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
                $secret = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
                $access_token = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );

                $limit = $this->TiktokApi->MainModel->getFirstResultByConditions('platform_urls', [
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'url_name' => 'products'
                ],
                ['url', 'id']);

                if ($limit) {
                    $create_time_from = $limit->url;
                }

                $now = new DateTime();
                $unix = $now->getTimestamp();
                $string = $secret."/api/products/searchapp_key".$app_key."timestamp".$unix.$secret;
                $sign = hash_hmac('sha256', $string, $secret);

                $url = "https://".$this->api_domain.".tiktokglobalshop.com/api/products/search?app_key=$app_key&timestamp=$unix&sign=$sign&access_token=$access_token";

                $post_data = [
                    "page_size" => $pagesize,//50
                    "page_number" => $page_number,//1
                    "create_time_from" => (int)$create_time_from,
                    "create_time_to" => $create_time_to,
                    "search_status" => 4,//live
                ];

                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getProduct-".$user_integration_id.".txt", "[".date( 'H:i:s' )."] makeCurlRequest: ".json_encode( $post_data )." ".$url );
                $response = $this->TiktokApi->makeAPICall( $url, 'POST', $post_data );
                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getProduct-".$user_integration_id.".txt", "[".date( 'H:i:s' )."] makeCurlResponse: ".json_encode( $response ) );

                if( $response['api_status'] && $response['api_data']['total'] > 0 ){

                    $productArr = $response['api_data']['products'];

                    foreach( $productArr as $k=>$product ){
                        if( COUNT( $product['skus'] ) > 0 ){
                            foreach( $product['skus'] as $variant ){
                                $this->TiktokService->storeProductDetails( $this->api_domain, $user_id, $user_integration_id, $product, $variant, $app_key, $secret, $access_token );
                            }
                        }
                    }
                } else {
                    $return_response = $response['api_data'];
                }

                if ($limit) {
                    $this->TiktokApi->MainModel->makeUpdate('platform_urls', ['url' => $create_time_to ], ['id' => $limit->id]);
                } else {
                    $this->TiktokApi->MainModel->makeInsert('platform_urls', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url' => $create_time_to,
                        'url_name' => 'products'
                    ]);
                }
            }
        }catch( Exception $e )
        {
            Log::error( $user_integration_id . ' - TiktokApiController - getTiktokProduct - '.$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     * update tiktok product inventory
     * https://partner.tiktokshop.com/doc/page/262788?external_id=262788
     * search_status: 0-all、1-draft、2-pending、3-failed、4-live、5-seller_deactivated、6-platform_deactivated、7-freeze
     * for now
     */
    public function getTiktokProductInventory( $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->TiktokService->getAccountDetails( $user_integration_id );

            if( $platform_account )
            {
                $pagesize = 100;
                $page_number = 1;
                $update_time_from = 0;
                $update_time_to = time();
                $limit = [];

                $app_key = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
                $secret = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
                $access_token = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );

                $limit = $this->TiktokApi->MainModel->getFirstResultByConditions('platform_urls', [
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'url_name' => 'inventory_update'
                ],
                ['url', 'id']);

                if ($limit) {
                    $update_time_from = $limit->url;
                }

                $now = new DateTime();
                $unix = $now->getTimestamp();
                $string = $secret."/api/products/searchapp_key".$app_key."timestamp".$unix.$secret;
                $sign = hash_hmac('sha256', $string, $secret);

                $url = "https://".$this->api_domain.".tiktokglobalshop.com/api/products/search?app_key=$app_key&timestamp=$unix&sign=$sign&access_token=$access_token";

                $post_data = [
                    "page_size" => $pagesize,//50
                    "page_number" => $page_number,//1
                    "update_time_from" => 0,//(int)$update_time_from,
                    "update_time_to" => $update_time_to,
                    "search_status" => 4,//live
                ];

                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getProductInventory-".$user_integration_id.".txt", "[".date( 'H:i:s' )."] makeCurlRequest: ".json_encode( $post_data )." ".$url );
                $response = $this->TiktokApi->makeAPICall( $url, 'POST', $post_data );
                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getProductInventory-".$user_integration_id.".txt", "[".date( 'H:i:s' )."] makeCurlResponse: ".json_encode( $response ) );
                if( $response['api_status'] && $response['api_data']['total'] > 0){
                    $productArr = $response['api_data']['products'];

                    foreach( $productArr as $ar ){
                        if( COUNT( $ar['skus'] ) > 0 ){
                            foreach( $ar['skus'] as $sr ){
                                $this->TiktokService->storeProductDetails( $this->api_domain, $user_id, $user_integration_id, $ar, $sr, $app_key, $secret, $access_token, true, false );
                            }
                        }
                    }
                } else if( $response['api_data']['total'] == 0){
                    // $return_response = "No more inventory result found";
                } else {
                    $return_response = $response['api_data'];
                }

                if ($limit) {
                    $this->TiktokApi->MainModel->makeUpdate('platform_urls', [ 'url' => $update_time_to ], ['id' => $limit->id]);
                } else {
                    $this->TiktokApi->MainModel->makeInsert('platform_urls', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url' => $update_time_to,
                        'url_name' => 'inventory_update'
                    ]);
                }
            }
        }catch( Exception $e )
        {
            Log::error( $user_integration_id . ' - TiktokApiController - getTiktokProductInventory - '.$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /*
     * get tiktok seller shop id
     * https://partner.tiktokshop.com/doc/page/262739?external_id=262739
     */
    public function getTiktokShopId( $user_id, $user_integration_id ){
        $return_response = false;
        try{
            $platform_account = $this->TiktokService->getAccountDetails( $user_integration_id );

            if($platform_account)
            {
                $app_key = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
                $app_secret = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
                $access_token = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );

                $now = new DateTime();
                $unix = $now->getTimestamp();
                $string = $app_secret."/api/shop/get_authorized_shopapp_key".$app_key."timestamp".$unix.$app_secret;
                $sign = hash_hmac('sha256', $string, $app_secret);

                $url = "https://".$this->api_domain.".tiktokglobalshop.com/api/shop/get_authorized_shop?app_key=$app_key&timestamp=$unix&sign=$sign&access_token=$access_token";
                $response = $this->TiktokApi->makeAPICall( $url, 'GET' );

                if( $response['api_status'] )
                {
                    $response = $response['api_data']['shop_list'];
                    $platform_account = PlatformAccount::select( 'id', 'region', 'marketplace_id' )
                    ->where( [
                        'user_id' => $user_id,
                        'platform_id' => $this->platformId
                    ] )
                    ->first();

                    $platform_account->region = $response[0]['region'];
                    $platform_account->marketplace_id = $response[0]['shop_id'];
                    $platform_account->save();
                    $return_response = $response[0]['shop_id'];
                }
                else
                {
                    $return_response = $response['api_data'];
                }
            }
        }
        catch( Exception $e )
        {
            Log::error($user_integration_id . ' - TiktokApiController - getTiktokShopId - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * set Manully
     */
    public function getTiktokSalesOrderStatus( $user_id, $user_integration_id ){

        $return_data = true;
        try
        {
            $salesOrderObject = $this->TiktokApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'order_status'], ['id']);

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
                    100 => 'UNPAID',
                    111 => 'AWAITING_SHIPMENT',
                    112 => 'AWAITING_COLLECTION',
                    114 => 'PARTIALLY_SHIPPING',
                    121 => 'IN_TRANSIT',
                    122 => 'DELIVERED',
                    130 => 'COMPLETED',
                    140 => 'CANCELLED',
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

                    $platform_object_data = $this->TiktokApi->MainModel->getFirstResultByConditions('platform_object_data',
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
                        $this->TiktokApi->MainModel->makeUpdate('platform_object_data', $orderStatus, ['id'=>$platform_object_data->id]);
                    } else {
                        $this->TiktokApi->MainModel->makeInsert('platform_object_data', $orderStatus);
                    }
                }
            }
        }
        catch( Exception $e )
        {
            Log::error($user_integration_id.' - TiktokApiController - getTicketStatus - '.$e->getLine().' - '.$e->getMessage());
            $return_data = $e->getMessage();
        }
        return $return_data;

    }

    /**
     * get tiktok sale order
     * https://partner.tiktokshop.com/doc/page/262815?external_id=262815
     *
     * Error like: invalid signature after pass next-cursor or order status
     */
    public function getTiktokSalesOrder( $user_id, $user_integration_id, $is_initial_sync=0, $user_workflow_rule_id=0, $source_platform_id=0, $platform_workflow_rule_id=0 )
    {
        date_default_timezone_set('Europe/London');
        $return_response = true;
        try{
            $platform_account = $this->TiktokService->getAccountDetails( $user_integration_id );

            if($platform_account)
            {
                $sync_object_id = $this->TiktokApi->ConnectionHelper->getObjectId('platform_order');

                $SourceOrDestination = "source";
                $platform_workflow_rule = $this->TiktokApi->ConnectionHelper->getPlatformFlowDetail( $user_workflow_rule_id );
                if( $platform_workflow_rule && $platform_workflow_rule->destination_platform_id == $this->platformId )
                {
                    $SourceOrDestination = "destination";
                }

                $order_status = 0;

                /*----------------Start to find order status----------------*/
                $orderStatusObject = $this->TiktokApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'order_status'], ['id']);
                $order_status_name = $this->TiktokApi->MainModel->getFirstResultByConditions('platform_object_data', [
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'platform_object_id' => $orderStatusObject->id,
                    'status' => 1
                ],
                ['api_id']);

                if( $order_status_name )
                {
                    $order_status_filter = $this->TiktokApi->FieldMappingHelper->getMappedDataByName( $user_integration_id, $platform_workflow_rule_id, "sorder_status", ['api_id']);
                    $order_status = $order_status_filter->api_id;
                }

                $lessDays = $this->TiktokApi->dateTime( date( 'Y-m-d', strtotime( '-7 day', strtotime( date( 'Y-m-d' ) ) ) )." 00:00:00" );
                $plusDays = $this->TiktokApi->dateTime( date( 'Y-m-d', strtotime( '+1 day', strtotime( date( 'Y-m-d' ) ) ) )." 23:59:59" );

                $create_time_from = $lessDays;//$this->TiktokApi->dateTime( date( 'Y-m-d' )." 00:00:00" );
                $pagesize = 50;
                $page_number = 1;
                $limit = [];
                $next_cursor = "";

                $app_key = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
                $secret = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
                $access_token = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );

                if( $is_initial_sync ){
                    $orderStartDateObject = $this->TiktokApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'sorder_sync_start_date'], ['id']);
                    $order_date_filter = $this->TiktokApi->MainModel->getFirstResultByConditions('platform_object_data', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'platform_object_id' => $orderStartDateObject->id,
                        'status' => 1
                    ],
                    ['api_id']);

                    if( $order_date_filter )
                    {
                        $map_order_status = $this->TiktokApi->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "map_order_status", ['api_id'], 'regular', $order_status_name->api_id, "single", $SourceOrDestination);
                        if($map_order_status)
                        {
                            $create_time_from = $this->TiktokApi->dateTime( date( 'Y-m-d', $map_order_status->api_id )." 00:00:00" );;//strtotime( $map_order_status->api_id );
                        }
                    }
                } else {
                    $limit = $this->TiktokApi->MainModel->getFirstResultByConditions('platform_urls', [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'sales_orders'
                    ],
                    ['url', 'id']);
    
                    if ( $limit && $limit->url != "" ) {
                        $explodeURL = explode('|', $limit->url );
                        $create_time_from = ( isset( $explodeURL[0] ) && $explodeURL[0] != '') ? $explodeURL[0] : $lessDays;
                        $next_cursor = ( isset( $explodeURL[1] ) && $explodeURL[1] != '' ) ? $explodeURL[1] : '' ;
                        $create_time_to = ( isset( $explodeURL[2] ) && $explodeURL[2] != '' ) ? $explodeURL[2] : $plusDays;
                    }
                }

                $isContinue = 1;
                $fileName = "";
                
                $now = new DateTime();
                $unix = $now->getTimestamp();
                $string = $secret."/api/orders/searchapp_key".$app_key."timestamp".$unix.$secret;
                $sign = hash_hmac('sha256', $string, $secret);

                $url = "https://".$this->api_domain.".tiktokglobalshop.com/api/orders/search?app_key=$app_key&timestamp=$unix&sign=$sign&access_token=$access_token";

                $post_data = [
                    "page_size" => $pagesize,//50
                    "page_number" => $page_number,//1
                    "cursor" => $next_cursor,
                    "order_status" => (int)$order_status,//param order_status is invalid,detail:type incorrect,expected type:int
                    "create_time_from" => $create_time_from,
                    // "sort_by" => "CREATE_TIME",
                    "create_time_to" => $create_time_to,
                ];

                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getSalesOrder-".$user_integration_id.$fileName.".txt", "[".date( 'H:i:s' )."] ".date( 'd-m-Y', $create_time_from )." To ".date( 'd-m-Y', $create_time_to )." makeCurlRequest: ".json_encode( $post_data ) );
                $response = $this->TiktokApi->makeAPICall( $url, 'POST', $post_data );
                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getSalesOrder-".$user_integration_id.$fileName.".txt", "[".date( 'H:i:s' )."] makeCurlResponse: ".json_encode( $response ) );

                $next_cursor = "";
                $detailResponse = true;
                if( $isContinue && $response['api_status'] && $response['api_data']['total'] > 0 && isset( $response['api_data']['order_list'] ) ){
                    $orderArr = $response['api_data']['order_list'];
                    $orderIds = [];
                    foreach( $orderArr as $k=>$orders ){
                        $orderIds[] = $orders['order_id'];
                    }
                    $responseArr = $this->getTiktokOrderDetails( $user_id, $user_integration_id, $platform_account, $orderIds, $user_workflow_rule_id, $source_platform_id, $sync_object_id );
                    $detailResponse = $responseArr['response'];
                    
                    if( isset( $response['api_data']['more'] ) && $response['api_data']['more'] == "true" ){
                        $next_cursor = $response['api_data']['next_cursor'];
                    }
                    else {
                        // $create_time_from = $responseArr['create_time_from'];
                        // $next_cursor = "";
                        Storage::append( "Tiktok/".date( 'd-m-Y' )."/getSalesOrder-".$user_integration_id.$fileName.".txt", "[".date( 'H:i:s' )."] Complete Between: ".date( 'd-m-Y', $create_time_from )." To ".date( 'd-m-Y', $create_time_to ) );
                    }
                } else {
                    $return_response = $response['api_data'];
                    // $next_cursor = "";
                }

                $create_time_from = $lessDays;
                $create_time_to = $plusDays;
                if( $isContinue && $detailResponse == true ){
                    if ($limit) {
                        $this->TiktokApi->MainModel->makeUpdate('platform_urls', [
                            'url' => $create_time_from."|".$next_cursor."|".$create_time_to
                        ], [
                            'id' => $limit->id
                        ]);
                    } else {
                        $this->TiktokApi->MainModel->makeInsert('platform_urls', [
                            'user_id' => $user_id,
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'url' => $create_time_from."|".$next_cursor."|".$create_time_to,
                            'url_name' => 'sales_orders'
                        ]);
                    }
                }
            }
        }catch( Exception $e )
        {
            Log::error( $user_integration_id . ' - TiktokApiController - getTiktokSalesOrder - '.$e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     * update tiktok order details
     * https://partner.tiktokshop.com/doc/page/262814?external_id=262814
     */
    public function getTiktokOrderDetails( $user_id, $user_integration_id, $platform_account=null, $order_data=[], $user_workflow_rule_id, $source_platform_id, $sync_object_id, $isDirect='' ){
        $return['response'] = true;
        try{

            if( !$platform_account ){
                $platform_account = $this->TiktokService->getAccountDetails( $user_integration_id );
            }

            if($platform_account)
            {
                $sync_object_id = $this->TiktokApi->ConnectionHelper->getObjectId('platform_order');

                $app_key = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
                $secret = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
                $access_token = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );

                $now = new DateTime();
                $unix = $now->getTimestamp();
                $string = $secret."/api/orders/detail/queryapp_key".$app_key."timestamp".$unix.$secret;
                $sign = hash_hmac('sha256', $string, $secret);

                $url = "https://".$this->api_domain.".tiktokglobalshop.com/api/orders/detail/query?app_key=$app_key&timestamp=$unix&sign=$sign&access_token=$access_token";

                $post_data = [
                    "order_id_list" => $order_data
                ];

                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getOrderDetails-".$user_integration_id.$isDirect.".txt", "[".date( 'H:i:s' )."] makeCurlRequest: ".json_encode( $post_data ) );
                $response = $this->TiktokApi->makeAPICall( $url, 'POST', $post_data );
                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getOrderDetails-".$user_integration_id.$isDirect.".txt", "[".date( 'H:i:s' )."] makeCurlRequest: ".json_encode( $response ) );

                // return true;
                if( $response['api_status'] ){
                    $orderStatus = [
                        100 => 'UNPAID',
                        111 => 'AWAITING_SHIPMENT',
                        112 => 'AWAITING_COLLECTION',
                        114 => 'PARTIALLY_SHIPPING',
                        121 => 'IN_TRANSIT',
                        122 => 'DELIVERED',
                        130 => 'COMPLETED',
                        140 => 'CANCELLED',
                    ];

                    $warehouseObject = $this->TiktokService->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);

                    foreach( $response['api_data']['order_list'] as $orderList ){

                        $newOrder = false;//After updating the ready order status, make sure to save the source side order with the line item.
                        $order = PlatformOrder::where([
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'user_workflow_rule_id' => $user_workflow_rule_id,
                            'order_type' => 'SO',
                            'api_order_id' => $orderList['order_id'],
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
                            $order->api_order_id = $orderList['order_id'];
                            // $order->sync_status = PlatformStatus::READY;
                        }

                        $order->order_number = $orderList['order_id'];
                        $order->order_date = date( 'Y-m-d h:i:s', substr( $orderList['create_time'], 0, 10 ) );//Carbon::createFromTimestamp( substr( $orderList['create_time'], 0, 10 ) );//
                        $order->order_status = $orderStatus[$orderList['order_status']];
                        $order->warehouse_id = $this->TiktokService->GetWarehouseLocation( $user_integration_id, $orderList['warehouse_id'], $warehouseObject );

                        $paymentInfo = $orderList['payment_info'];
                        $order->currency = $paymentInfo['currency'];
                        $order->shipping_total = $paymentInfo['shipping_fee'];
                        $order->total_tax = $paymentInfo['taxes'];
                        $order->net_amount =$paymentInfo['sub_total'];
                        $order->total_amount = $paymentInfo['total_amount'];
                        $order->save();

                        //Order line ites
                        foreach( $orderList['item_list'] as $k=>$orderLines ){

                            //check product exist or not
                            $checkIsProductSyncArr = PlatformProduct::select( 'id', 'product_sync_status' )
                            ->where( [
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'api_product_id' => $orderLines['product_id'],
                            ] )
                            ->first();
                            
                            if( !$checkIsProductSyncArr ){
                                $this->getProductDetails( $user_id, $user_integration_id, $orderLines['product_id'] );
                            }

                            $orderline = PlatformOrderLine::where([
                                'platform_order_id' => $order->id,
                                // 'api_order_line_id' => $orderLines['order_line_id'],
                                'api_product_id' => $orderLines['sku_id'],//product_id
                            ])
                            ->first();

                            if( !$orderline ){
                                $orderline = new PlatformOrderLine();
                                $orderline->platform_order_id = $order->id;
                                $orderline->api_product_id = $orderLines['sku_id'];//product_id
                            }

                            if( isset( $orderList['order_line_list'][$k] ) ){
                                $orderline->api_order_line_id = $orderList['order_line_list'][$k]['order_line_id'];
                            }

                            $orderline->product_name = $orderLines['product_name'];
                            $orderline->sku = $orderLines['seller_sku'];
                            $orderline->qty = $orderLines['quantity'];
                            $orderline->subtotal = $orderLines['sku_original_price'];
                            $orderline->total = $orderLines['sku_sale_price'];
                            $orderline->price = $orderLines['sku_sale_price'];
                            $orderline->unit_price = $orderLines['sku_sale_price'];
                            $orderline->discount_amount = $orderLines['sku_seller_discount'];
                            // $orderline->notes = $orderLines['cancel_reason'] ?? '';
                            $orderline->save();
                        }

                        //Order Customer
                        $customer = PlatformCustomer::where([
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                            'api_customer_id' => $orderList['buyer_uid'],
                        ])
                        ->first();

                        if( !$customer ){
                            $customer = new PlatformCustomer();
                            $customer->user_id = $user_id;
                            $customer->user_integration_id = $user_integration_id;
                            $customer->platform_id = $this->platformId;
                            $customer->sync_status = PlatformStatus::PENDING;
                            $customer->api_customer_id = $orderList['buyer_uid'];
                        }

                        $customerArr = $orderList['recipient_address'];
                        if( $customerArr ){
                            $customer->customer_name = $customerArr['name'];
                            $customer->phone = $customerArr['phone'];
                            $customer->address1 = $customerArr['address_detail'].", ".$customerArr['town'];
                            $customer->address2 = $customerArr['city'].", ".$customerArr['district'].", ".$customerArr['state'];
                            $customer->address3 = $customerArr['full_address'];
                            $customer->postal_addresses = $customerArr['full_address'];
                            $customer->country = $customerArr['region'];
                            $customer->type = "Customer";
                        }

                        $customer->save();

                        $order->platform_customer_id = $customer->id;//save customer id in order table

                        if( $newOrder ){
                            $order->sync_status = PlatformStatus::READY;
                        }

                        $order->save();

                        $return['create_time_from'] = ( strtotime( $order->order_date ) - 50 );
                    }
                } else {
                    $this->TiktokApi->Logger->syncLog( $user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', 0, $response['api_data'] );
                    $return['response'] = $response['api_data'];
                }
            }
        }catch( Exception $e )
        {
            Log::error( $user_integration_id . ' - TiktokApiController - getTiktokOrderDetails - '.$e->getMessage());
            $return['response'] = $e->getMessage();
        }

        return $return;
    }

    /**
     * get warehouse list
     * https://partner.tiktokshop.com/doc/page/262859?external_id=262859
     */
    public function getWareHouseLists(  $user_id, $user_integration_id ){
        $return_response = true;
        try{
            $platform_account = $this->TiktokService->getAccountDetails( $user_integration_id );

            if($platform_account)
            {
                $app_key = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
                $app_secret = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
                $access_token = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );

                $now = new DateTime();
                $unix = $now->getTimestamp();
                $string = $app_secret."/api/logistics/get_warehouse_listapp_key".$app_key."timestamp".$unix.$app_secret;
                $sign = hash_hmac('sha256', $string, $app_secret);

                $url = "https://".$this->api_domain.".tiktokglobalshop.com/api/logistics/get_warehouse_list?app_key=$app_key&timestamp=$unix&sign=$sign&access_token=$access_token";
                $response = $this->TiktokApi->makeAPICall( $url, 'GET' );

                if( $response['api_status'] )
                {
                    $salesOrderObject = $this->TiktokApi->MainModel->getFirstResultByConditions('platform_objects', ['name'=>'warehouse'], ['id']);
                    $warehouseList = $response['api_data']['warehouse_list'];

                    //revert object data status
                    PlatformObjectData::where([
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'platform_object_id' => $salesOrderObject->id,
                    ])
                    ->update(['status' => 0]);

                    foreach( $warehouseList as $ar ){
                        $platformObjData = PlatformObjectData::where([
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'platform_object_id' => $salesOrderObject->id,
                            'api_id' => $ar['warehouse_id'],
                        ])
                        ->first();

                        if( !$platformObjData ){
                            $platformObjData = new PlatformObjectData();
                            $platformObjData->user_id = $user_id;
                            $platformObjData->platform_id = $this->platformId;
                            $platformObjData->user_integration_id = $user_integration_id;
                            $platformObjData->platform_object_id = $salesOrderObject->id;
                            $platformObjData->api_id = $ar['warehouse_id'];
                        }

                        $platformObjData->api_code = $ar['warehouse_name'];
                        $platformObjData->name = ucfirst( strtolower( str_ireplace( "_", " ", $ar['warehouse_name'] ) ) );
                        $platformObjData->status = 1;
                        $platformObjData->save();
                    }
                } else {
                    $return_response = $response['api_data'];
                }

                // app('App\Http\Controllers\Snowflake\SnowflakeApiController')->getWareHouseLists( $user_id, $user_integration_id );
            }
        }
        catch( Exception $e )
        {
            Log::error($user_integration_id . ' - TiktokApiController - getTiktokShopId - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * GET functions need to return some response so that it can be used to handle the status of that sub event in initial get data function.
     */
    public function ExecuteEventTiktok($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id='',$platform_workflow_rule_id='', $record_id = '')
    {
        // Log::info("ExecuteEventTiktok- Method: ".$method.", event: ".$event.", is_initial_sync: ".$is_initial_sync);
        $response = true;

        if ($method == 'GET' && $event == 'ORDERSTATUS') {
            $response = $this->getTiktokSalesOrderStatus( $user_id, $user_integration_id );
        } else if($method == 'GET' && $event == 'PRODUCT') {
            $response = $this->getTiktokProduct( $user_id, $user_integration_id, $is_initial_sync );
        } else if($method == 'GET' && $event == 'SALESORDER') {
            // Log::info( "SALESORDER: ".$user_id." - ".$user_integration_id." - ".$is_initial_sync." - ".$user_workflow_rule_id." - ".$source_platform_id." - ".$platform_workflow_rule_id );
            $response = $this->getTiktokSalesOrder( $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id );
        } else if($method == 'GET' && ( $event == 'PRODUCTINVENTORY' || $event == 'INVENTORY' ) ) {
            $response = $this->getTiktokProductInventory( $user_id, $user_integration_id );
        } else if( $method == 'GET' && $event == 'WAREHOUSELOCATION') {
            $response = $this->getWareHouseLists( $user_id, $user_integration_id );
        } else if( $method == 'GET' && $event == 'SHOPLIST') {
            $response = $this->getTiktokShopId( $user_id, $user_integration_id );
        }
        return $response;
    }

    /**
     * Undocumented function
     *
     * @return void
     */
    public function updateProductVariantId(){
        return true;
        $productArr = PlatformProduct::select( 'id', 'api_product_id' )
        ->where(
            [
                'user_integration_id' => 580
            ]
        )->get();

        foreach( $productArr as $ar ){
            $this->TiktokApi->MainModel->makeUpdate('platform_product', [ 'api_variant_id' => $ar->api_product_id ], [ 'id' => $ar->id ] );
        }
    }

    /**
     *
     */
    public function deleteProductDuplicateEntry(){
        return true;
        $productArr = DB::select("SELECT MAX(id) as id, api_product_id, COUNT(*) count FROM platform_product WHERE `user_integration_id` = 580 AND `platform_id` = 50 GROUP BY api_product_id HAVING COUNT(*) > 1;");
        foreach( $productArr as $k=>$pr ){
            //delete inventory record
            PlatformProductInventory::where( 'platform_product_id', $pr->id)->delete();

            //delete attribute record
            PlatformProductDetailAttribute::where( 'platform_product_id', $pr->id)->delete();

            //delete product record
            PlatformProduct::where( 'id',$pr->id)->delete();
            echo $k." : ".$pr->id." - ".$pr->api_product_id."<br>";
        }
    }

    /**
     *
     */
    public function deleteProductEntry( Request $request ){
        return true;
        $integrationId = (int)$request->intid ?? 0;
        $orderArr = DB::select("SELECT id FROM platform_product WHERE `user_integration_id` = $integrationId LIMIT 1500");
        foreach( $orderArr as $k=>$ar ){
            //delete platform_product_detail_attributes record
            PlatformProductDetailAttribute::where( 'platform_product_id', $ar->id)->delete();

            //delete platform_product_inventory record
            PlatformProductInventory::where( 'platform_product_id', $ar->id)->delete();

            //delete platform_porduct_price_list record
            PlatformProductPriceList::where( 'platform_product_id', $ar->id)->delete();

            //delete platform product record
            PlatformProduct::where( 'id', $ar->id )->delete();
            echo $k." : ".$ar->id."<br>";
        }
    }

    /**
     *
     */
    public function deleteOrderEntry( Request $request ){
        return true;
        $integrationId = (int)$request->intid ?? 0;
		if( $integrationId > 0 ){
			$offset = (int)$request->offset ?? 0;
			ob_start();
			$orderArr = PlatformOrder::select('id', 'api_order_id')->where( 'user_integration_id', $integrationId )->offset($offset)->limit(2000)->get();
			echo "Start At: ".date( 'd-m-Y h:i:s' )."<br>";
			//DB::select("SELECT id, api_order_id FROM platform_order WHERE `user_integration_id` = $integrationId LIMIT 1500");
			foreach( $orderArr as $k=>$ar ){

				//delete inventory record
				PlatformOrderLine::where( 'platform_order_id', $ar->id)->delete();

				//delete product record
				PlatformOrder::where( 'id',$ar->id)->delete();
				echo $k." : ".$ar->id." - ".$ar->api_order_id."<br>";
			}
			echo "End At: ".date( 'd-m-Y h:i:s' )."<br>";
			$orderArr = PlatformOrder::select('id')->where( 'user_integration_id', $integrationId )->get();
			echo "Left Record: ".COUNT( $orderArr );

			if( COUNT( $orderArr ) > 0 ){
				//header("Refresh:10; url=https://esb.apiworx.net/remove-tiktok-order?intid=$integrationId");
			}
		}
    }

    /**
     *
     */
    public function updateDeleteOrderLineEntry( Request $request ){
        return true;
        echo "Start At: ".date( 'd-m-Y h:i:s' )."<br>";
        $integrationId = (int)$request->intid ?? 0;
		if( $integrationId > 0 ){
			$offset = (int)$request->offset ?? 0;
			ob_start();

			$productArr = PlatformProduct::where( 'user_integration_id', $integrationId )
                        // ->where( 'sku', '!=', '' )
                        ->where( 'custom_fields', null )
                        ->where( 'platform_id', 50 )
                        // ->offset( $offset )
                        ->limit( 1 )
                        ->get()
                        ->pluck( 'api_variant_id', 'api_product_id' );//See Snap 1 for result

            if( $productArr ){
                foreach( $productArr as $api_product_id => $api_variant_id ){
                
                    // Get order id by inventory record
                    $orderLinkedArr = PlatformOrderLine::where( 'api_product_id', $api_product_id )
                                    ->get()
                                    ->pluck( 'platform_order_id' )
                                    ->toArray();// See Snap 2 for result

                    // Update order line api_product_id as api_variant_id
                    PlatformOrderLine::where( 'api_product_id', $api_product_id )->update( ['api_product_id' => $api_variant_id ] );

                    $orderLinkedArr = array_unique( $orderLinkedArr );
                    
                    //Delete all linked record
                    PlatformOrder::whereIn( 'linked_id', $orderLinkedArr )->delete();

                    // Set Linked id as 0
                    PlatformOrder::whereIn( 'id', $orderLinkedArr )->update( [
                        'sync_status' => PlatformStatus::READY,
                        'linked_id' => 0 
                    ] );

                    // Update product custom data val
                    PlatformProduct::where( [
                        'api_variant_id' => $api_variant_id,
                        'platform_id' => 50,
                    ] )->update( [
                        'custom_fields' => $integrationId
                    ] );

                    echo $api_product_id." => ".$api_variant_id."<br>";
                }

                echo "End At: ".date( 'd-m-Y h:i:s' )."<br>";
                // header("Refresh:15; url=https://esb.apiworx.net/remove-tiktok-linked-order?intid=$integrationId");
            } else {
                echo "All skus completed";
            }
		}
    }

    /**
     *
     */
    public function updateProductInventory( Request $request ){
        return true;
        echo "Start At: ".date( 'd-m-Y h:i:s' )."<br>";
        $integrationId = (int)$request->intid ?? 0;
		if( $integrationId > 0 ){
			$productArr = PlatformProduct::where( 'user_integration_id', $integrationId )
                        ->where( 'sku', '!=', '' )
                        ->select( 'sku', 'api_product_id', 'api_variant_id' )
                        ->get();

            if( $productArr ){
                foreach( $productArr as $ar ){
                    // Update order line api_product_id as api_variant_id
                    PlatformProductInventory::where( 'sku', $ar->sku )->update( ['api_product_id' => $ar->api_variant_id ] );
                }
            }
		}
        echo "End At: ".date( 'd-m-Y h:i:s' )."<br>";
    }

    /**
     *
     */
    public function getUserDetails(){
        $platform_account = $this->TiktokService->getAccountDetails( 580 );
        echo "<pre>";
        print_r($platform_account);
    }

    /**
     *
     */
    public function getProductDetails( $user_id, $user_integration_id, $productId ){
        $platform_account = $this->TiktokService->getAccountDetails( $user_integration_id );

        $app_key = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
        $secret = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
        $access_token = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );

        //get product details
        // foreach( $productIds as $productId )
        {
            $now = new DateTime();
            $unix = $now->getTimestamp();
            $string = $secret."/api/products/detailsapp_key".$app_key."product_id".$productId."timestamp".$unix.$secret;
            $sign = hash_hmac('sha256', $string, $secret);

            $url = "https://".$this->api_domain.".tiktokglobalshop.com/api/products/details?app_key=$app_key&product_id=".$productId."&timestamp=$unix&sign=$sign&access_token=$access_token";
            $response = $this->TiktokApi->makeAPICall( $url, 'GET' );

            Storage::append( "Tiktok/".date( 'd-m-Y' )."/getProductDetails-".$user_integration_id.".txt", "[".date( 'H:i:s' )."] Response: ".json_encode( $response ) );
            $product = $response['api_data'];
            if( COUNT( $product['skus'] ) > 0 ){
                $product['id'] = $product['product_id'];
                $product['name'] = $product['product_name'];
                $product['status'] = $product['product_status'];

                foreach( $product['skus'] as $variant ){
                    $this->TiktokService->storeProductDetails( $this->api_domain, $user_id, $user_integration_id, $product, $variant, $app_key, $secret, $access_token, true, false );
                }
            }

            return true;
        }
    }

    /**
     * get tiktok sale order
     * https://partner.tiktokshop.com/doc/page/262815?external_id=262815
     *
     * Error like: invalid signature after pass next-cursor or order status
     * SALESORDER: 554 - 580 - 0 - 1254 - tiktok - 172
     * https://esb.apiworx.net/get-tiktok-manually-sale-order?startDate=&endDate=&next_cursor=
	 * https://esb.apiworx.net/get-tiktok-manually-sale-order?startDate=1686873600&endDate=1686960000&next_cursor=aDV5MXAyZVlhVWhPVDJaTThTU0lidUw1amt6Ynk5V212bERUWXh4TGhWc0FRUT09
     */
    public function getManuallySalesOrder( Request $request )
    {
        $user_id = 554;
        $user_integration_id = 580;
        $user_workflow_rule_id = 1254;
        $source_platform_id = "tiktok";

        $return_response = true;
        try{
            $platform_account = $this->TiktokService->getAccountDetails( $user_integration_id );

            if($platform_account)
            {
                $sync_object_id = $this->TiktokApi->ConnectionHelper->getObjectId('platform_order');

                echo $app_key = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
                echo "<br>".$secret = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
                echo "<br>".$access_token = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );

                $urlFromTime = $request->create_time_from;
                $create_time_from = strtotime( '-1 day', $request->create_time_from );
                $next_cursor = $request->next_cursor ?? '';
                $create_time_to = strtotime( '+1 day', $request->create_time_from );

                $now = new DateTime();
                $unix = $now->getTimestamp();
                $string = $secret."/api/orders/searchapp_key".$app_key."timestamp".$unix.$secret;
                $sign = hash_hmac('sha256', $string, $secret);

                $url = "https://".$this->api_domain.".tiktokglobalshop.com/api/orders/search?app_key=$app_key&timestamp=$unix&sign=$sign&access_token=$access_token";

                $post_data = [
                    "page_size" => 50,
                    "page_number" => 1,
                    "cursor" => $next_cursor,
                    "order_status" => 122,
                    "create_time_from" => $create_time_from,
                    "create_time_to" => $create_time_to,
                ];

                $type = $request->type ?? 'GK';

                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getSalesOrder-".$user_integration_id."-directTest-".$type.".txt", "[".date( 'H:i:s' )."] ".date( 'd-m-Y', $create_time_from )." To ".date( 'd-m-Y', $create_time_to )." makeCurlRequest: ".json_encode( $post_data ) );
                $response = $this->TiktokApi->makeAPICall( $url, 'POST', $post_data );
                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getSalesOrder-".$user_integration_id."-directTest-".$type.".txt", "[".date( 'H:i:s' )."] makeCurlResponse: ".json_encode( $response ) );
				
                $detailResponse = true;
                $total = 0;
                if( $response['api_status'] && isset( $response['api_data']['order_list'] ) ){
                    $total = $response['api_data']['total'];

                    if( $response['api_data']['more'] ){
                        $next_cursor = $response['api_data']['next_cursor'];
                    } else {
                        $urlFromTime = strtotime( '+1 day', $request->create_time_from );
                        $next_cursor = '';
                    }

                    $orderArr = $response['api_data']['order_list'];
                    $orderIds = [];
                    foreach( $orderArr as $orders ){
                        $orderIds[] = $orders['order_id'];
                    }
                    $detailResponse = $this->getTiktokOrderDetails( $user_id, $user_integration_id, $platform_account, $orderIds, $user_workflow_rule_id, $source_platform_id, $sync_object_id, "-directTest" );
                    
                } else {
                    $return_response = $response['api_data'];
                    $urlFromTime = strtotime( '+1 day', $request->create_time_from );
                }


                // if( $detailResponse == true && $response['api_data']['more'] ){
                    $refresh = $request->refresh ?? 10;
                    
                    echo "<b>Current Date:</b> ".date( 'Y-m-d', $create_time_from )."
                    <br><b>Total: </b>$total
                    <br><a href='https://esb.apiworx.net/get-tiktok-manually-sale-order?create_time_from=".$urlFromTime."&next_cursor=".$next_cursor."&type=".$type."'><b>Click Here</b></a> to continue get new records</b>";

                    // if( $type == 'GK' && $create_time_from >= 1689791400 )//20-07-2023
					// dd( $post_data, $response, $next_cursor, $create_time_from );
                    // header("Refresh:$refresh url=https://esb.apiworx.net/get-tiktok-manually-sale-order?create_time_from=$create_time_from&next_cursor=$next_cursor&refresh=$refresh&type=$type");
                // } else {
                //     dd( $detailResponse, $response );
                // }
            }
        }catch( Exception $e )
        {
            Log::error( $user_integration_id . ' - TiktokApiController - getManuallySalesOrder - '.$e->getMessage());
            $return_response = $e->getMessage();
        }

        // return $return_response;
    }

    /**
     * get tiktok sale order
     * https://partner.tiktokshop.com/doc/page/262815?external_id=262815
     *
     * Error like: invalid signature after pass next-cursor or order status
     * SALESORDER: 554 - 580 - 0 - 1254 - tiktok - 172
     * https://esb.apiworx.net/get-tiktok-manually-sale-order?startDate=&endDate=&next_cursor=
	 * https://esb.apiworx.net/get-tiktok-manually-sale-order?startDate=1686873600&endDate=1686960000&next_cursor=aDV5MXAyZVlhVWhPVDJaTThTU0lidUw1amt6Ynk5V212bERUWXh4TGhWc0FRUT09
     */
    /**
     * get tiktok sale order
     * https://partner.tiktokshop.com/doc/page/262815?external_id=262815
     *
     * Error like: invalid signature after pass next-cursor or order status
     * SALESORDER: 554 - 580 - 0 - 1254 - tiktok - 172
     * https://esb.apiworx.net/get-tiktok-manually-sale-order?startDate=&endDate=&next_cursor=
	 * https://esb.apiworx.net/get-tiktok-manually-sale-order?startDate=1686873600&endDate=1686960000&next_cursor=aDV5MXAyZVlhVWhPVDJaTThTU0lidUw1amt6Ynk5V212bERUWXh4TGhWc0FRUT09
     */
    public function getManuallyCronBaseSalesOrder( $urlSuffix='DELIVERED', $order_status=122 )
    {

        date_default_timezone_set('Europe/London');

        $user_id = 554;
        $user_integration_id = 580;
        $user_workflow_rule_id = 1254;
        $source_platform_id = "tiktok";
        // $create_time_from = 1685574000;
        // $create_time_to = 1693522801;
        $type = $urlSuffix."-Cron";
        $next_cursor = "";

        try{
            $platform_account = $this->TiktokService->getAccountDetails( $user_integration_id );

            if( $platform_account)
            {
                $sync_object_id = $this->TiktokApi->ConnectionHelper->getObjectId('platform_order');

                $app_key = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
                $secret = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
                $access_token = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );

                $limit = $this->TiktokApi->MainModel->getFirstResultByConditions('platform_urls', [
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'url_name' => "tiktok_SO_manual_$urlSuffix",
                ],
                ['url', 'id']);

                // $date = date( 'Y-m-d' );
                if ( $limit ) {
                    $explodeURL = explode( "|", $limit->url );
                    $date = date( 'Y-m-d', $explodeURL[0] );
                    $create_time_from = $this->TiktokApi->dateTime( $date." 00:00:00" );
                    $next_cursor = $explodeURL[1] ?? '';
                    // $create_time_from = $explodeURL[2] ?? time();
                    // $type = $explodeURL[2] ?? 'GK';
                    // $next_cursor = $limit->url;
                }
                // dd($->TiktokApithis->dateTime( "2023-07-01 00:00:00" ));
                // dd(date('d-m-Y H:i:s',$create_time_from));
                
                $urlFromTime = $create_time_from;
                // $create_time_from = strtotime( '-1 day', $create_time_from );
                $create_time_to = $this->TiktokApi->dateTime( $date." 23:59:59" );

                //  dd($create_time_from,$create_time_to);
                $now = new DateTime();
                $unix = $now->getTimestamp();
                $string = $secret."/api/orders/searchapp_key".$app_key."timestamp".$unix.$secret;
                $sign = hash_hmac('sha256', $string, $secret);

                $url = "https://".$this->api_domain.".tiktokglobalshop.com/api/orders/search?app_key=$app_key&timestamp=$unix&sign=$sign&access_token=$access_token";

                $post_data = [
                    "page_size" => 50,
                    "page_number" => 1,
                    "cursor" => $next_cursor,
                    "order_status" => $order_status,
                    "create_time_from" => $create_time_from,
                    "create_time_to" => $create_time_to,
                ];

                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getSalesOrder-".$user_integration_id."-directTest-".$type.".txt", "[".date( 'H:i:s' )."] ".date( 'd-m-Y', $create_time_from )." To ".date( 'd-m-Y', $create_time_to )." makeCurlRequest: ".json_encode( $post_data ) );
                $response = $this->TiktokApi->makeAPICall( $url, 'POST', $post_data );
                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getSalesOrder-".$user_integration_id."-directTest-".$type.".txt", "[".date( 'H:i:s' )."] makeCurlResponse: ".json_encode( $response ) );
				
                if( $response['api_status'] && isset( $response['api_data']['order_list'] ) ){

                    if( $response['api_data']['more'] ){
                        $next_cursor = $response['api_data']['next_cursor'];
                    } else {
                        $date = date( 'Y-m-d', strtotime( '+1 day', $create_time_from ) );
                        $urlFromTime = $this->TiktokApi->dateTime( $date." 00:00:00" );
                        $next_cursor = '';
                    }

                    $orderArr = $response['api_data']['order_list'];
                    $orderIds = [];
                    foreach( $orderArr as $orders ){
                        $orderIds[] = $orders['order_id'];
                    }
                    $this->getTiktokOrderDetails( $user_id, $user_integration_id, $platform_account, $orderIds, $user_workflow_rule_id, $source_platform_id, $sync_object_id, "-directTest-".$type );
                    
                } else {
                    $date = date( 'Y-m-d', strtotime( '+1 day', $create_time_from ) );
                    $urlFromTime = $this->TiktokApi->dateTime( $date." 00:00:00" );
                    $next_cursor = '';
                }

                $url = $urlFromTime."|".$next_cursor;//."|".$type;
                if ($limit) {
                    $this->TiktokApi->MainModel->makeUpdate('platform_urls', ['url' => $url ], ['id' => $limit->id]);
                } else {
                    $this->TiktokApi->MainModel->makeInsert('platform_urls', [
                        'user_integration_id' => $user_integration_id,
						'user_id' => $user_id,
                        'platform_id' => $this->platformId,
                        'url' => $url,
                        'url_name' => "tiktok_SO_manual_$urlSuffix"
                    ]);
                }
            }
        }catch( Exception $e ) {
            Log::error( $user_integration_id . ' - TiktokApiController - getManuallyCronBaseSalesOrder - '.$e->getMessage());
        }
    }

    /**
     * get tiktok sale order
     * https://partner.tiktokshop.com/doc/page/262815?external_id=262815
     *
     * Error like: invalid signature after pass next-cursor or order status
     * SALESORDER: 554 - 580 - 0 - 1254 - tiktok - 172
     * https://esb.apiworx.net/get-tiktok-manually-sale-order?startDate=&endDate=&next_cursor=
	 * https://esb.apiworx.net/get-tiktok-manually-sale-order?startDate=1686873600&endDate=1686960000&next_cursor=aDV5MXAyZVlhVWhPVDJaTThTU0lidUw1amt6Ynk5V212bERUWXh4TGhWc0FRUT09
     */
    public function getManuallyCronBaseCompletedSalesOrder( $urlSuffix='COMPLETED', $order_status=130 )
    {

        date_default_timezone_set('Europe/London');

        $user_id = 554;
        $user_integration_id = 580;
        $user_workflow_rule_id = 1254;
        $source_platform_id = "tiktok";
        $type = $urlSuffix."-Cron";
        $next_cursor = "";

        try{
            $platform_account = $this->TiktokService->getAccountDetails( $user_integration_id );

            if( $platform_account)
            {
                $sync_object_id = $this->TiktokApi->ConnectionHelper->getObjectId('platform_order');

                $app_key = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
                $secret = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
                $access_token = $this->TiktokApi->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );

                $limit = $this->TiktokApi->MainModel->getFirstResultByConditions('platform_urls', [
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'url_name' => "tiktok_SO_manual_$urlSuffix",
                ],
                ['url', 'id']);

                if ( $limit ) {
                    $explodeURL = explode( "|", $limit->url );
                    $date = date( 'Y-m-d', $explodeURL[0] );
                    $create_time_from = $this->TiktokApi->dateTime( $date." 00:00:00" );
                    $next_cursor = $explodeURL[1] ?? '';
                }
                
                $urlFromTime = $create_time_from;

                if( $urlFromTime >= 1690844400 ){//01-Aug-2023
                    Storage::append( "Tiktok/".date( 'd-m-Y' )."/getSalesOrder-".$user_integration_id."-directTest-".$type.".txt", "[".date( 'H:i:s' )."] ".date( 'd-m-Y', $create_time_from )." Is Over" );
                    return true;
                }
                $create_time_to = $this->TiktokApi->dateTime( $date." 23:59:59" );

                $now = new DateTime();
                $unix = $now->getTimestamp();
                $string = $secret."/api/orders/searchapp_key".$app_key."timestamp".$unix.$secret;
                $sign = hash_hmac('sha256', $string, $secret);

                $url = "https://".$this->api_domain.".tiktokglobalshop.com/api/orders/search?app_key=$app_key&timestamp=$unix&sign=$sign&access_token=$access_token";

                $post_data = [
                    "page_size" => 50,
                    "page_number" => 1,
                    "cursor" => $next_cursor,
                    "order_status" => $order_status,
                    "create_time_from" => $create_time_from,
                    "create_time_to" => $create_time_to,
                ];

                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getSalesOrder-".$user_integration_id."-directTest-".$type.".txt", "[".date( 'H:i:s' )."] ".date( 'd-m-Y', $create_time_from )." To ".date( 'd-m-Y', $create_time_to )." makeCurlRequest: ".json_encode( $post_data ) );
                $response = $this->TiktokApi->makeAPICall( $url, 'POST', $post_data );
                Storage::append( "Tiktok/".date( 'd-m-Y' )."/getSalesOrder-".$user_integration_id."-directTest-".$type.".txt", "[".date( 'H:i:s' )."] makeCurlResponse: ".json_encode( $response ) );
				
                if( $response['api_status'] && isset( $response['api_data']['order_list'] ) ){

                    if( $response['api_data']['more'] ){
                        $next_cursor = $response['api_data']['next_cursor'];
                    } else {
                        $date = date( 'Y-m-d', strtotime( '+1 day', $create_time_from ) );
                        $urlFromTime = $this->TiktokApi->dateTime( $date." 00:00:00" );
                        $next_cursor = '';
                    }

                    $orderArr = $response['api_data']['order_list'];
                    $orderIds = [];
                    foreach( $orderArr as $orders ){
                        $orderIds[] = $orders['order_id'];
                    }
                    $this->getTiktokOrderDetails( $user_id, $user_integration_id, $platform_account, $orderIds, $user_workflow_rule_id, $source_platform_id, $sync_object_id, "-directTest-".$type );
                    
                } else {
                    $date = date( 'Y-m-d', strtotime( '+1 day', $create_time_from ) );
                    $urlFromTime = $this->TiktokApi->dateTime( $date." 00:00:00" );
                    $next_cursor = '';
                }

                $url = $urlFromTime."|".$next_cursor;//."|".$type;
                if ($limit) {
                    $this->TiktokApi->MainModel->makeUpdate('platform_urls', ['url' => $url ], ['id' => $limit->id]);
                } else {
                    $this->TiktokApi->MainModel->makeInsert('platform_urls', [
                        'user_integration_id' => $user_integration_id,
						'user_id' => $user_id,
                        'platform_id' => $this->platformId,
                        'url' => $url,
                        'url_name' => "tiktok_SO_manual_$urlSuffix"
                    ]);
                }
            }
        }catch( Exception $e ) {
            Log::error( $user_integration_id . ' - TiktokApiController - getManuallyCronBaseCompletedSalesOrder - '.$e->getMessage());
        }
    }
	
    /**
     *
     */
    public function updateOrderDate( Request $request ){
        return true;
        echo "Start At: ".date( 'd-m-Y h:i:s' )."<br>";
        $integrationId = (int)$request->intid ?? 0;
		if( $integrationId > 0 ){
			$OrderArr = PlatformOrder::where( 'user_integration_id', $integrationId )
                        ->where( [
                            'is_voided' => 1,
                            // 'linked_id' => 0
                        ] )
                        // ->limit( 500 )
                        ->get()
                        ->pluck( 'id' );//See Snap 1 for result

            if( $OrderArr ){
                // Update order line api_product_id as api_variant_id
                PlatformOrder::whereIn( 'id', $OrderArr )->update( ['is_voided' => 0 ] );
                echo "Total: ".COUNT( $OrderArr )."<br>";
                echo "End At: ".date( 'd-m-Y h:i:s' )."<br>";
            } else {
                echo "All skus completed";
            }
		}
    }
}
