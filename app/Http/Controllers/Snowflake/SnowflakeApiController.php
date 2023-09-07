<?php

namespace App\Http\Controllers\Snowflake;

use Exception;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\PlatformOrder;
use App\Models\PlatformAccount;
use App\Models\PlatformProduct;
use App\Helper\WorkflowSnippet;
use App\Http\Controllers\CommonController;
use App\Models\PlatformCustomer;
use App\Models\PlatformOrderLine;
use Illuminate\Support\Facades\DB;
use App\Models\PlatformObjectData;
use App\Models\Enum\PlatformStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Lang;
use App\Models\PlatformOrderAddress;
use Illuminate\Support\Facades\Auth;
use App\Models\PlatformOrderShipment;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Session;
use App\Models\PlatformProductInventory;
use Illuminate\Support\Facades\Validator;
use App\Models\PlatformOrderShipmentLine;
use App\Http\Controllers\Snowflake\Api\SnowflakeApi;
use App\Http\Controllers\Snowflake\SnowflakeService;
use App\Models\PlatformOrderAdditionalInformation;
use App\Models\PlatformPreProcessData;

use function GuzzleHttp\json_decode;

class SnowflakeApiController extends SnowflakeApi
{
    public $client_id = "";
    public $client_secret = "";
    public $app_id = "";
    public $api_domain = "";
    public static $myPlatform = 'snowflake';
    public $snowflakeApi;
    public $wfSnip;
    public $platformId;
    public $mapping;
    public $snowFlakeService;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->snowFlakeService = new SnowflakeService();
        $this->snowflakeApi = new SnowflakeApi();
        $this->wfSnip = new WorkflowSnippet();
        $this->platformId = $this->snowflakeApi->connectionHelper->getPlatformIdByName(self::$myPlatform);
    }

    /**
     *
     */
    public function InitiateSnowflakeAuth(Request $request)
    {
        $platform = self::$myPlatform;
        return view("pages.apiauth.snowflake_auth", compact('platform'));
    }

    public function ConnectSnowflake(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'account_name' => 'required',
                'client_id' => 'required',
                'client_secret' => 'required',
                // 'region' => 'required',
                // 'marketplace_id' => 'required',
                // 'custom_domain' => 'required',
                'api_domain' => 'required',
            ]
        );
        if ($this->snowflakeApi->mainModel->checkHtmlTags($request->all())) {
            return back()->with('error', Lang::get('tags.validate'));
        }

        if ($validator->fails()) {
            return back()->withErrors($validator);
        } else {
            $account_name = trim($request->account_name);
            //to check whether given account is already in use or not.
            $checkExistingAccount = PlatformAccount::where(['platform_id' => $this->platformId, 'account_name' => $account_name])->first();
            if ($checkExistingAccount) {
                return back()->with('error', 'Given details are already in use, Try with other details.');
            }

            $this->client_id = $request->client_id;
            $this->client_secret = $request->client_secret;
            $this->api_domain = $request->api_domain;
            $isReAuth = 0;
            $redirect_uri = $this->snowflakeApi->mainModel->makeUrlHttpsForProd(url('/RedirectHandlerSnowflake'));
            // $state = Auth::user()->id."-|-".$request->user_integration_id."-|-".$account_name."-|-".$this->client_id."-|-".$this->client_secret."-|-".$request->marketplace_id."-|-".$request->custom_domain."-|-".$this->api_domain."-|-".$request->region;
            $state = Auth::user()->id . "-|-" . $request->user_integration_id . "-|-" . $account_name . "-|-" . $this->client_id . "-|-" . $this->client_secret . "-|--|--|-" . $this->api_domain . "-|-".$isReAuth;

            if ($this->client_id && $this->client_secret) {
                $authorizationUrl = "https://" . $this->api_domain . Config::get('apiconfig.SnowflakeEndPointUrl') . '/authorize';
                $authorizationUrl .= "?response_type=code&client_id=" . urlencode($this->client_id) . "&redirect_uri=" . urlencode($redirect_uri) . "&scope=&state=" . urlencode($state);
                return redirect($authorizationUrl);
            } else {
                Session::put('auth_msg', 'App config not found');
                echo '<script>window.close();</script>';
            }
        }
    }

    /**
     * 
     */
    public function ReConnectSnowflake( $user_integration_id )
    {
        $platform_account = $this->snowflakeApi->getAccountDetails( $user_integration_id ); // get the account information for the integration

        if( $platform_account ){

            $account_name = $platform_account->account_name;
            $api_domain = $platform_account->api_domain;
            $clientId = $this->snowflakeApi->mainModel->encrypt_decrypt( $platform_account->app_id, 'decrypt' );
            $clientSecret = $this->snowflakeApi->mainModel->encrypt_decrypt( $platform_account->app_secret, 'decrypt' );
            $isReAuth = 1;
            $redirect_uri = $this->snowflakeApi->mainModel->makeUrlHttpsForProd(url('/RedirectHandlerSnowflake'));
            $state = Auth::user()->id . "-|-" . $user_integration_id . "-|-" . $account_name . "-|-" . $clientId . "-|-" . $clientSecret . "-|--|--|-" . $api_domain . "-|-".$isReAuth;

            if ( $clientId && $clientSecret ) {
                $authorizationUrl = "https://" . $api_domain . Config::get('apiconfig.SnowflakeEndPointUrl') . '/authorize';
                $authorizationUrl .= "?response_type=code&client_id=" . urlencode( $clientId ) . "&redirect_uri=" . urlencode( $redirect_uri ) . "&scope=&state=" . urlencode( $state );
                return redirect( $authorizationUrl );
            } else {
                Session::put('auth_msg', 'App config not found');
                echo '<script>window.close();</script>';
            }
        } else {
            Session::put('auth_msg', 'App connection not found');
            echo '<script>window.close();</script>';
        }
    }

    /**
     * Undocumented function
     *
     * @param Request $request
     * @return void
     */
    public function RedirectHandlerSnowflake(Request $request)
    {
        date_default_timezone_set('UTC');
        if ( isset( $request->code ) ) {
            $platform_api_app = true; //PlatformApiApp::where( [ 'platform_id' => $this->platformId] )->first();//['client_id', 'client_secret']);
            if ( $platform_api_app ) {
                $state = $request->state;
                $state_arr = explode('-|-', $state);
                $user_id = $state_arr[0] ?? null; //user primary id
                $user_integration_id = $state_arr[1] ?? null; //user integration id
                $account_name = $state_arr[2] ?? null; // Account Code
                $client_id = $state_arr[3] ?? null; //IP panel client id
                $client_secret = $state_arr[4] ?? null; // IP panel client secret
                $database = $state_arr[5] ?? null; //IP panel database name
                $schema = $state_arr[6] ?? null; // IP panel database schema name
                $this->api_domain = $state_arr[7] ?? null; // IP panel Hostname
                $isReAuth = $state_arr[8] ?? null; // IP panel Re Authentication available
                if ( isset( $user_id ) && isset( $user_integration_id ) && isset( $client_secret ) && isset( $account_name ) && isset( $client_id ) ) {
                    $code = $request->code;
                    $authorization = base64_encode("$client_id:$client_secret");
                    $url = 'https://' . $this->api_domain . '.aws.snowflakecomputing.com/oauth/token-request';
                    $data = [
                        'code' => $code,
                        'redirect_uri' => $this->snowflakeApi->mainModel->makeUrlHttpsForProd(url('/RedirectHandlerSnowflake')),
                        'grant_type' => 'authorization_code'
                    ];
                    $headers = [
                        "Content-Type: application/x-www-form-urlencoded",
                        "Accept: application/json",
                        "Authorization: Basic {$authorization}"
                    ];

                    $curl = curl_init();
                    curl_setopt($curl, CURLOPT_URL, $url);
                    curl_setopt($curl, CURLOPT_POST, true);
                    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($curl);
                    $info = curl_getinfo($curl);
                    curl_close( $curl );

                    if ($response = json_decode($response, true)) {
                        Storage::append('Snowflake/' . $user_integration_id . '/RedirectHandlerSnowflakeAuthResponse' . date('d-m-Y') . '_' . $user_integration_id . '.txt', json_encode($response));

                        if (isset($response['access_token'])) {
                            $platform_account = PlatformAccount::where([
                                'user_id' => $user_id,
                                'platform_id' => $this->platformId,
                                'account_name' => $account_name
                            ])
                            ->first();

                            if (!$platform_account) {
                                $platform_account = new PlatformAccount();
                            }

                            $platform_account->user_id = $user_id;
                            $platform_account->platform_id = $this->platformId;
                            $platform_account->account_name = $account_name;
                            $platform_account->app_id = $this->snowflakeApi->mainModel->encrypt_decrypt($client_id);
                            $platform_account->app_secret = $this->snowflakeApi->mainModel->encrypt_decrypt($client_secret);
                            $platform_account->refresh_token = $this->snowflakeApi->mainModel->encrypt_decrypt($response['refresh_token']);
                            $platform_account->access_token = $this->snowflakeApi->mainModel->encrypt_decrypt($response['access_token']);
                            $platform_account->marketplace_id = $database;
                            $platform_account->custom_domain = $schema;
                            $platform_account->api_domain = $this->api_domain;
                            $platform_account->token_type = $response['token_type'] ?? '';
                            $platform_account->expires_in = $response['expires_in'];
                            $platform_account->refresh_expires_in = $response['refresh_token_expires_in'] ?? time();
                            $platform_account->role_arn = $this->snowflakeApi->fetchSubStr($response['scope'], "role:", '"');
                            $platform_account->token_refresh_time = time();
                            $platform_account->allow_reauth_refresh = 0;

                            if( $isReAuth ){
                                $platform_account->last_refreshed_at = date( 'Y-m-d H:i:s' );
                            }

                            $platform_account->save();
                        } else {
                            if (isset($response['message'])) {
                                $error = $response['message'];
                            } else {
                                $error = "Something went wrong in your account";
                            }

                            echo '<script>alert("' . $error . '");window.close();</script>';
                        }
                        echo '<script>window.close();</script>';
                    } else {
                        $this->snowflakeApi->mainModel->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
                    }
                }
            }
        } else {
            // When code not received from SnowFlake
            $this->snowflakeApi->mainModel->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
        }
    }

    /*
     * Refresh Token
     */
    public function RefreshToken( $id )
    {
        date_default_timezone_set('UTC');
        $return_response = true;

        try {
            $platform_account = PlatformAccount::select('id', 'app_id', 'refresh_token', 'app_secret', 'api_domain')
                ->where(['id' => $id, 'platform_id' => $this->platformId])
                ->first();

            if ($platform_account) {
                $clientId = $this->snowflakeApi->mainModel->encrypt_decrypt($platform_account->app_id, 'decrypt');
                $clientSecret = $this->snowflakeApi->mainModel->encrypt_decrypt($platform_account->app_secret, 'decrypt');
                $refresh_token = $this->snowflakeApi->mainModel->encrypt_decrypt($platform_account->refresh_token, 'decrypt');

                $service_url = "https://$platform_account->api_domain.aws.snowflakecomputing.com/oauth/token-request";
                $curl = curl_init($service_url);
                $curl_post_data = [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh_token,
                ];
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Authorization: Basic ' . base64_encode($clientId . ":" . $clientSecret)
                ]);
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($curl_post_data));

                $responseObj = curl_exec($curl);

                // if( $id == 1158 ){
                //     dd( $service_url, $curl_post_data, $responseObj );
                // }

                Storage::append('Snowflake/' . $id . '/AuthResponse' . date('d-m-Y') . '.txt', "[".date('H:i:s')."]: ".$responseObj);

                $response = json_decode($responseObj, 1);

                if (isset($response['access_token'])) {
                    $platform_account->access_token = $this->snowflakeApi->mainModel->encrypt_decrypt($response['access_token']);
                    $platform_account->expires_in = $response['expires_in'];
                    $platform_account->token_refresh_time = time();
                    $platform_account->save();
                    $return_response = $response['access_token'];
                } else {
                    if (isset($response['message'])) {
                        $return_response = $response['message'];
                    } else {
                        $return_response = "Something went wrong in your account";
                    }
                }

                Storage::append('Snowflake/' . $id . '/AuthResponse' . date('d-m-Y') . '.txt', "[".date('H:i:s')."]: ".$return_response);
                // if( $id == 1242 ){
                //     dd( $service_url, $curl_post_data, $responseObj );
                // }
            }
        } catch (Exception $e) {
            Log::error($id . ' - SnowflakeApiController - RefreshToken - ' . $e->getMessage());
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
            $platform_account = $this->snowflakeApi->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {

                $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);
                if (!$databaseConfig['database'] || !$databaseConfig['schema']) {
                    return "Database or Schema information is not defined in account credentials.";
                }

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

                $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);
                $post_data = [
                    "timeout" => 1000,
                    "resultSetMetaData" => [
                        "format" => "json"
                    ],
                    "warehouse" => $databaseConfig['warehouse'], //$platform_account->region,
                    "role" => "SYSADMIN",
                ];

                $limit = 100;
                $database = $databaseConfig['database']; //$platform_account->marketplace_id;
                $schema = $databaseConfig['schema']; //$platform_account->custom_domain;
                $table = "VENDORS";
                $statement = "select * from $database.$schema.$table ";
                if ($last_updated_at) {
                    $statement .= "where 'UPDATED_AT' > '$last_updated_at' ";
                }
                $statement .= "order by 'UPDATED_AT' asc limit $limit";
                $post_data["statement"] = $statement;

                $post_data["statement"] = $statement;
                $response =  $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $post_data);

                if (isset($response['api_status']) && $response['api_status'] == 1) {
                    $vendors = $response['api_data'];

                    if (count($vendors)) {
                        foreach ($vendors as $vendor) {
                            $vendor_id = (isset($vendor[0]) && $vendor[0]) ? $vendor[0] : NULL; // VENDOR_ID

                            $vendorObj = PlatformCustomer::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'api_customer_id' => $vendor_id
                            ])
                                ->select('id')
                                ->first();

                            if (!$vendorObj) {
                                $vendorObj = new PlatformCustomer();
                                $vendorObj->api_customer_id = $vendor_id;
                                $vendorObj->user_id = $user_id;
                                $vendorObj->user_integration_id = $user_integration_id;
                                $vendorObj->platform_id = $this->platformId;
                                $vendorObj->type = 'Vendor';
                            }

                            $vendorObj->customer_name = (isset($vendor[1]) && $vendor[1]) ? $vendor[1] : NULL; // DISPLAY_NAME
                            $vendorObj->email = (isset($vendor[5]) && $vendor[5]) ? $vendor[5] : NULL; // EMAIL
                            $vendorObj->postal_addresses = (isset($vendor[6]) && $vendor[6]) ? $vendor[6] : NULL; // VENDOR_ADDRESS
                            $vendorObj->api_updated_at = (isset($vendor[8]) && $vendor[8]) ? date('Y-m-d H:i:s', strtotime($vendor[8])) : NULL; // UPDATED_AT
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
            Log::error($user_integration_id . ' - SnowflakeApiController - GetSupplier - ' . $return_response);
        }
        return $return_response;
    }

    /**
     * Get Transfer orders
     * //update URL name
     */
    public function GetOrders($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $order_type = 'PO')
    {
        set_time_limit(0);
        $return_response = true;
        try {
            $platform_account = $this->snowflakeApi->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {

                $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);
                if (!$databaseConfig['database'] || !$databaseConfig['schema']) {
                    return "Database or Schema information is not defined in account credentials.";
                }

                // Getting most recent transfer order date to fetch further new orders afer this perticular order's date
                $is_url = $this->snowflakeApi->mainModel->getFirstResultByConditions(
                    'platform_urls',
                    [
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                        'url_name' => 'last_trans_' . $order_type . '_date'
                    ],
                    ['url', 'id', 'status']
                );

                $last_updated_at = date('Y-m-d H:i:s', time());
                if ($is_url && $is_url->status == 1) {
                    $last_updated_at = $is_url->url;
                } else {
                    $get_workflow_events = DB::table('user_workflow_rule')->where('id', $user_workflow_rule_id)
                        ->select('sync_start_date')->first();
                    if (isset($get_workflow_events->sync_start_date)) {
                        $last_updated_at = date('Y-m-d H:i:s', strtotime($get_workflow_events->sync_start_date));
                    }
                }

                $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);

                $post_data = [
                    "timeout" => 1000,
                    "resultSetMetaData" => [
                        "format" => "json"
                    ],
                    "warehouse" => $databaseConfig['warehouse'], //$platform_account->region,
                    "role" => "SYSADMIN",
                ];

                if (isset($_GET['order_type'])) {
                    $order_type = $_GET['order_type'];
                }

                $api_order_type = strtolower(($order_type == 'TO') ? 'TRANSFER' : 'PO');

                $limit = 100;
                $database = $databaseConfig['database']; //$platform_account->marketplace_id;
                $schema = $databaseConfig['schema']; //$platform_account->custom_domain;
                $table = "PURCHASE_ORDERS"; // This table contains the both PO and TO records (separated by their specific types)
                $post_data["statement"] = "select * from $database.$schema.$table WHERE TYPE = '$api_order_type' AND UPDATED_AT > '$last_updated_at' ORDER BY 'UPDATED_AT' ASC LIMIT $limit;";
                $response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $post_data);

                if (isset($response['api_status']) && $response['api_status'] == 1) {
                    $orders = $response['api_data']['data'] ?? [];
                    if (count($orders)) {
                        $recentToDate = null;
                        $platformOrderId = null;
                        $warehouse_object_id = $this->snowflakeApi->connectionHelper->getObjectId('warehouse');
                        foreach ($orders as $ord) {

                            $recentToDate = (isset($ord[11]) && $ord[11]) ? date('Y-m-d h:i:s',  $ord[11]) : NULL; // LAST_MODIFIED

                            if ($user_integration_id == 689) {
                                Storage::append('Snowflake/' . $user_integration_id . '/getPoDetails/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: " . json_encode($ord));
                            }

                            if (!isset($ord[1]) || (isset($ord[1]) && strtoupper($ord[1]) != strtoupper($api_order_type))) { // TYPE (order type)
                                continue;
                            }

                            $ord_number = (isset($ord[0]) && $ord[0]) ? $ord[0] : NULL; // ID (order number)

                            /** Section: Order [start] */
                            $orderObj = PlatformOrder::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'api_order_id' => $ord_number,
                                'user_workflow_rule_id' => $user_workflow_rule_id,
                                'order_type' => $order_type,
                            ])
                            ->first();

                            $order_date = explode(".", $ord[9]);
                            if (!$orderObj) {
                                $orderObj = new PlatformOrder();

                                $orderObj->api_order_id = $ord_number;
                                $orderObj->user_id = $user_id;
                                $orderObj->user_integration_id = $user_integration_id;
                                $orderObj->platform_id = $this->platformId;
                                $orderObj->order_type = $order_type;
                                $orderObj->user_workflow_rule_id = $user_workflow_rule_id;
                                $orderObj->order_number = (isset($ord[2]) && trim($ord[2])) ? trim($ord[2]) : NULL; // REFERENCE, $ord_number;
                                $orderObj->api_order_reference = (isset($ord[2]) && trim($ord[2])) ? trim($ord[2]) : NULL; // REFERENCE
                                $orderObj->currency = (isset($ord[8]) && $ord[8]) ? $ord[8] : NULL; // CURRENCY
                                $orderObj->order_date = (isset($ord[9]) && $ord[9]) ? date('Y-m-d h:i:s', $order_date[0]) : NULL; // CREATED_DATE
                                $orderObj->order_updated_at = (isset($ord[11]) && $ord[11]) ? date('Y-m-d h:i:s',  $ord[11]) : NULL; // LAST_MODIFIED
                                $orderObj->sync_status = PlatformStatus::READY;

                                // Cretae/Update vendor information in Database and link its id in Order record
                                if (isset($ord[5]) && $ord[5]) { // VENDOR (vendor id)
                                    $condition = [
                                        'user_id' => $user_id,
                                        'user_integration_id' => $user_integration_id,
                                        'platform_id' => $this->platformId,
                                        'customer_name' => trim($ord[5]),
                                    ];
                                    $vendor_info = PlatformCustomer::where($condition)
                                        ->select('id', 'email', 'postal_addresses')
                                        ->first();

                                    if (!$vendor_info) {
                                        // If vendor not found, create a new instanse
                                        $vendor_info = new PlatformCustomer();
                                        $vendor_info->api_customer_id = trim($ord[5]);
                                        $vendor_info->customer_name = trim($ord[5]);
                                        $vendor_info->user_id = $user_id;
                                        $vendor_info->user_integration_id = $user_integration_id;
                                        $vendor_info->platform_id = $this->platformId;
                                        $vendor_info->email = $ord[13] ? trim($ord[13]) : null;
                                        $vendor_info->postal_addresses = $ord[14] ? trim($ord[14]) : null;
                                        $vendor_info->type = 'Vendor';
                                        $vendor_info->sync_status = PlatformStatus::READY;
                                    }

                                    $vendor_info->email = $ord[13] ? trim($ord[13]) : null;
                                    $vendor_info->postal_addresses = $ord[14] ? trim($ord[14]) : null;
                                    $vendor_info->save();

                                    if (isset($vendor_info->id)) {
                                        $orderObj->platform_customer_id = $vendor_info->id; // VENDOR (platform customer id)
                                    }
                                }
                            } else {
                                $platformOrderId = $orderObj->id;
                            }

                            $dest_warehouse_id = $source_warehouse_id = null;
                            $dest_warehouse = (isset($ord[6]) && $ord[6]) ? $ord[6] : NULL; // WAREHOUSE (destination warehouse)
                            $source_warehouse = (isset($ord[7]) && $ord[7]) ? $ord[7] : NULL; // SOURCE_WAREHOUSE
                            /* get store actual warehouse detail in platform_object_data table and return primary id */
                            if ($dest_warehouse) {
                                $dest_warehouse_id = $this->snowFlakeService->GetOrderWarehouse($dest_warehouse, $user_id, $user_integration_id, $warehouse_object_id);
                            }
                            if ($source_warehouse) {
                                $source_warehouse_id = $this->snowFlakeService->GetOrderWarehouse($source_warehouse, $user_id, $user_integration_id, $warehouse_object_id);
                            }

                            if ($order_type == 'PO') {
                                $orderObj->order_status = (isset($ord[4]) && $ord[4]) ? $ord[4] : NULL; // STATUS (order status)
                                $orderObj->warehouse_id =  $dest_warehouse_id; // WAREHOUSE
                                $orderObj->delivery_date = (isset($ord[10]) && $ord[10]) ? date('Y-m-d h:i:s', $ord[10]) : NULL; // EXPECTED_DATE
                                $orderObj->shipping_method = (isset($ord[12]) && $ord[12]) ? $ord[12] : NULL; // SHIPMENT_METHOD
                            }

                            // if ($orderObj->sync_status != 'Synced') {
                            //     $orderObj->sync_status = PlatformStatus::READY;
                            // }

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
                                /** Section: Order Address [start] */
                                // TO billing address
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

                                $orderBillAddr->address1 = (isset($ord[16]) && $ord[16]) ? $ord[16] : NULL; // BILLING_ADDRESS
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

                                $orderShipAddr->address1 = (isset($ord[17]) && $ord[17]) ? $ord[17] : NULL; // SHIPPING_ADDRESS
                                $orderShipAddr->save();
                                /** Section: Order Address [end] */

                                if ($order_type == 'TO') {
                                    /** Section: Order Shipment [start] */
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
                                        $shipmentObj->shipment_id = (isset($ord[2]) && trim($ord[2])) ? trim($ord[2]) : NULL; // REFERENCE
                                    }

                                    $shipmentObj->order_id = $ord_number; // ID (order number)
                                    $shipmentObj->shipment_status = (isset($ord[4]) && $ord[4]) ? $ord[4] : NULL; // STATUS (shipment status)
                                    $shipmentObj->to_warehouse_id = $dest_warehouse_id; // WAREHOUSE (destination warehouse)
                                    $shipmentObj->warehouse_id = $source_warehouse_id; // SOURCE_WAREHOUSE
                                    $shipmentObj->created_on = (isset($ord[9]) && $ord[9]) ? date('Y-m-d h:i:s', $order_date[0]) : NULL; // CREATED_DATE
                                    $shipmentObj->realease_date = (isset($ord[10]) && $ord[10]) ? date('Y-m-d h:i:s', $ord[10]) : NULL; // EXPECTED_DATE
                                    $shipmentObj->shipping_method = (isset($ord[12]) && $ord[12]) ? $ord[12] : NULL; // SHIPMENT_METHOD

                                    $shipmentObj->save();

                                    /** Section: Order Shipment [end] */

                                    /** Section: Order Shipment lines [start] */
                                    if ($shipmentObj->id) {
                                        $order_line_id = (isset($ord[23]) && $ord[23]) ? $ord[23] : NULL; // LINE_ITEM_ID

                                        // PO line items
                                        $shipmentLineObj = PlatformOrderShipmentLine::where([
                                            'platform_order_shipment_id' => $shipmentObj->id,
                                            'row_id' => $order_line_id
                                        ])
                                            ->select('id')
                                            ->first();

                                        if (!$shipmentLineObj) {
                                            $shipmentLineObj = new PlatformOrderShipmentLine();
                                            $shipmentLineObj->platform_order_shipment_id = $shipmentObj->id;
                                            $shipmentLineObj->row_id = $order_line_id;
                                        }

                                        $shipmentLineObj->product_id = (isset($ord[24]) && $ord[24]) ? $ord[24] : NULL; // VARIANT_ID
                                        $shipmentLineObj->sku = (isset($ord[25]) && $ord[25]) ? $ord[25] : NULL; // SKU
                                        $shipmentLineObj->barcode = (isset($ord[26]) && $ord[26]) ? $ord[26] : NULL; // BARCODE
                                        $shipmentLineObj->currency = (isset($ord[8]) && $ord[8]) ? $ord[8] : NULL; // CURRENCY
                                        $shipmentLineObj->price = (isset($ord[31]) && $ord[31]) ? $ord[31] : 0; // COST_PRICE
                                        $shipmentLineObj->warehouse_id = (isset($ord[7]) && $ord[7]) ? $ord[7] : NULL; // SOURCE_WAREHOUSE
                                        $shipmentLineObj->quantity = (isset($ord[28]) && $ord[28]) ? $ord[28] : 0; // REPLENISHMENT (Ordered Qty)
                                        $shipmentLineObj->sent_quantity = (isset($ord[29]) && $ord[29]) ? $ord[29] : 0; // RECEIVED
                                        $shipmentLineObj->save();
                                    }
                                    /** Section: Order Shipment lines [end] */
                                } else if ($order_type == 'PO' || $order_type == 'SO') {
                                    $order_line_id = (isset($ord[23]) && $ord[23]) ? $ord[23] : NULL; // LINE_ITEM_ID

                                    // PO line items
                                    $orderLineArr = PlatformOrderLine::where([
                                        'platform_order_id' => $orderObj->id,
                                        'api_order_line_id' => $order_line_id
                                    ])
                                        ->select('id')
                                        ->first();

                                    if (!$orderLineArr) {
                                        $orderLineArr = new PlatformOrderLine();
                                        $orderLineArr->platform_order_id = $orderObj->id;
                                        $orderLineArr->api_order_line_id = $order_line_id;
                                    }

                                    $orderLineArr->api_product_id = (isset($ord[24]) && $ord[24]) ? $ord[24] : NULL; // VARIANT_ID
                                    $orderLineArr->sku = (isset($ord[25]) && $ord[25]) ? $ord[25] : NULL; // SKU
                                    $orderLineArr->barcode = (isset($ord[26]) && $ord[26]) ? $ord[26] : NULL; // BARCODE
                                    $orderLineArr->product_name = (isset($ord[27]) && $ord[27]) ? $ord[27] : NULL; // TITLE
                                    $orderLineArr->qty = (isset($ord[28]) && $ord[28]) ? $ord[28] : 0; // REPLENISHMENT
                                    $orderLineArr->price = (isset($ord[31]) && $ord[31]) ? $ord[31] : 0; // COST_PRICE
                                    $orderLineArr->total_tax = (isset($ord[32]) && $ord[32]) ? $ord[32] : 0; // TAX
                                    $orderLineArr->save();
                                }
                            }
                        }

                        // Update the most recent Transfer order date in PlatformUrl table for the next iteration to fetch TO from Snowflake API
                        if ($recentToDate) {
                            if ($is_url) {
                                $this->snowflakeApi->mainModel->makeUpdate('platform_urls', [
                                    'url' => $recentToDate
                                ], [
                                    'id' => $is_url->id
                                ]);
                            } else {
                                $this->snowflakeApi->mainModel->makeInsert('platform_urls', [
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
            Log::error($user_integration_id . ' - SnowflakeApiController - GetSalesOrders - ' . $return_response);
        }
        return $return_response;
    }

    /**
     * Sync product in snowfake
     */
    public function createUpdateProducts($user_id, $user_integration_id, $source_platform_id, $source_platform_name, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id = 0)
    {
        $return_response = true;
        try {
            $platform_account = $this->snowflakeApi->getAccountDetails($user_integration_id); // get the account information for the integration
            if ($platform_account) {

                $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);
                if (!$databaseConfig['database'] || !$databaseConfig['schema']) {
                    return "Database or Schema information is not defined in account credentials.";
                }
                $object_id = $sync_object_id = $this->snowflakeApi->connectionHelper->getObjectId('product');

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
                        // 'platform_product_inventory.quantity', 'platform_product_inventory.api_product_id as variant_id')
                        // ->leftJoin( 'platform_product_inventory', 'platform_product_inventory.platform_product_id', '=', 'platform_product.id' )
                        ->leftJoin('platform_product_detail_attributes', 'platform_product_detail_attributes.platform_product_id', '=', 'platform_product.id')
                        ->orderBy('platform_product.updated_at', 'ASC')
                        ->limit($limit)->get();

                    if ($platform_products) {

                        $product_identifier = $this->snowFlakeService->productIdentityMapping($user_integration_id, $platform_workflow_rule_id);

                        if (
                            (isset($product_identifier['source_identity']) && isset($product_identifier['destination_identity'])
                            ) ||
                            $source_platform_name == "veracore"
                        ) {

                            $default_product_pricelist = $this->snowflakeApi->fieldMapHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "product_pricelist", ['api_id'], "default");
                            $default_product_currency = $this->snowflakeApi->fieldMapHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "default_product_currency", ['api_code'], "default");

                            $database = $databaseConfig['database']; //$platform_account->marketplace_id;
                            $schema = $databaseConfig['schema']; //$platform_account->custom_domain;
                            $table = "VARIANTS";
                            $table = "$database.$schema.$table";

                            $post_data = [
                                "timeout" => 1000,
                                "resultSetMetaData" => [
                                    "format" => "json"
                                ],
                                "warehouse" => $databaseConfig['warehouse'], //$platform_account->region,
                                "role" => "SYSADMIN",
                            ];

                            foreach ($platform_products as $product) {
                                $productLinkingNew = PlatformProduct::find($product->id);
                                $productLinking = $productLinkingNew->replicate();
                                $destinationColumn = $product_identifier['destination_identity'];
                                $sourceColumn = $product_identifier['source_identity'];

                                $productImage = $productPrice = $productSku  = $productBarcode = $productVariant =
                                $productAsin = $productBrand = $productSubCategory = $productCategory = null;
                                $productRegularPrice = $productCBM = 0;

                                $product_primary_id = $product->id;
                                $publish = 0;
                                $remove = 0;
                                if ($product->product_status) {
                                    $publish = 1;
                                }

                                $type = "simple";
                                if ($product->bundle) {
                                    $type = "bundle";
                                }
                                $INVENTORY_MANAGEMENT = 1;

                                if (isset(Config::get('apisettings.AllowSKUInSnowflake')[$source_platform_name])) {
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
                                if (isset($default_product_currency->api_code) && isset($default_product_pricelist->api_id)) {
                                    if ($source_platform_name == "netsuite" || $source_platform_name == "peoplevox") {
                                        $price = $this->snowFlakeService->findPriceList($product_primary_id, $default_product_currency->api_code, $default_product_pricelist->api_id);
                                        if (isset($price['price'])) {
                                            $productPrice = $price['price'] ?? 0;
                                        }
                                    }
                                }
                                $productRegularPrice = $productPrice; // Product Price and Regular price will be the same as defined in IP doc
                                $productCategory = $product->category_id;

                                $productBrand = htmlspecialchars(str_replace("'", "\'", $product->brand_id), ENT_QUOTES);

                                $field_mapping = $this->snowflakeApi->fieldMapHelper->GetMappedFieldRecord($object_id, $user_integration_id, NULL, "source_row_id", NULL, $product_primary_id); //product field mappings | custom fields
                                if ($field_mapping) {
                                    foreach ($field_mapping as $mapping) {
                                        if ($mapping['destination_field_name'] == "IMAGE") {
                                            $productImage = $mapping['source_custom_field_value'];
                                            $productImage = htmlspecialchars(str_replace("'", "\'", $productImage), ENT_QUOTES);
                                        }
                                        if ($mapping['destination_field_name'] == "ASIN") {
                                            $productAsin = $mapping['source_custom_field_value'];
                                            $productAsin = htmlspecialchars(str_replace("'", "\'", $productAsin), ENT_QUOTES);
                                        }
                                        if ($mapping['destination_field_name'] == "BRAND") {
                                            $productBrand = $mapping['source_custom_field_value'];
                                            $productBrand = htmlspecialchars(str_replace("'", "\'", $productBrand), ENT_QUOTES);
                                        }
                                        if ($mapping['destination_field_name'] == "PRICE") {
                                            $productPrice = $mapping['source_custom_field_value'];
                                        }
                                        if ($mapping['destination_field_name'] == "REGULAR_PRICE") {
                                            $productRegularPrice = $mapping['source_custom_field_value'];
                                        }
                                        if ($mapping['destination_field_name'] == "TAGS") {
                                            $productSubCategory = $mapping['source_custom_field_value'];
                                            $productSubCategory = htmlspecialchars(str_replace("'", "\'", $productSubCategory), ENT_QUOTES);
                                        }
                                        if ($mapping['destination_field_name'] == "CATEGORY") {
                                            $productCategory = $mapping['source_custom_field_value'];
                                            $productCategory = htmlspecialchars(str_replace("'", "\'", $productCategory), ENT_QUOTES);
                                        }
                                    }
                                }

                                $postVariantVenderStatement = "";
                                $updatedAt = date( 'Y-m-d h:i:s' );
                                if ($product->linked_id) {
                                    $post_data["statement"] = "UPDATE $table SET
                                        TITLE = '" . $product->product_name . "',
                                        PRODUCT_TITLE = '" . $product->product_name . "',
                                        SKU = '" . $productSku . "',
                                        ASIN = '" . $productAsin . "',
                                        BARCODE = '" . $productBarcode . "',
                                        PRICE = '" . (float)$productPrice . "',
                                        REGULAR_PRICE = '" . (float)$productRegularPrice . "',
                                        NET_WEIGHT = '" . (float)$product->weight . "',
                                        BRAND = '" . $productBrand . "',
                                        CATEGORY = '" . $productCategory . "',
                                        IMAGE = '" . $productImage . "',
                                        INVENTORY_MANAGEMENT = '" . $INVENTORY_MANAGEMENT . "',
                                        PUBLISHED = '" . $publish . "',
                                        TAGS='" . $productSubCategory . "',
                                        TYPE = '" . $type . "',
                                        CBM = '" . $productCBM . "',
                                        REMOVED = '" . $remove . "',
                                        CREATED_AT = '" . $this->dateFormat($source_platform_name, $product->created_at) . "',
                                        UPDATED_AT = '" . $updatedAt . "'
                                    WHERE VARIANT_ID = '" . $productVariant . "' AND PRODUCT_ID = '" . $product->api_product_id . "';";
                                    //$this->dateFormat($source_platform_name, $product->api_updated_at)

                                    //update variant vendor table
                                    $postVariantVenderStatement = "UPDATE VARIANT_VENDOR SET
                                        COST_PRICE = '" . (float)$productPrice . "',
                                        LANDING_COST_PRICE = '" . (float)$productRegularPrice . "',
                                        VENDOR_REFERENCE = '',
                                        VENDOR_NAME = 'unknown',
                                        MOQ = '0',
                                        UOM = '0',
                                        REMOVED = '" . $remove . "',
                                        UPDATED_AT = '" . $updatedAt . "'
                                    WHERE ID = '" . $product->id . "' AND VARIANT_ID = '" . $productVariant . "' AND VENDOR_ID = 'unknown';";
                                } else {
                                    //variant_id
                                    $post_data["statement"]=$sql = "INSERT into $table(
                                        VARIANT_ID, PRODUCT_ID, TITLE, PRODUCT_TITLE, SKU, ASIN, BARCODE, PRICE, REGULAR_PRICE, NET_WEIGHT, BRAND, CATEGORY, IMAGE, INVENTORY_MANAGEMENT, PUBLISHED,PUBLISHED_AT,TAGS, TYPE, CBM,REMOVED, CREATED_AT, UPDATED_AT
                                    ) values (
                                        '" . str_replace("'", "\'", $productVariant) . "',
                                        '" . str_replace("'", "\'", $product->api_product_id) . "',
                                        '" . str_replace("'", "\'", $product->product_name) . "',
                                        '" . str_replace("'", "\'", $product->product_name) . "',
                                        '" . str_replace("'", "\'", $productSku) . "',
                                        '" . $productAsin . "',
                                        '" . str_replace("'", "\'", $productBarcode) . "',
                                        '" . (float)$productPrice . "',
                                        '" . (float)$productRegularPrice . "',
                                        '" . (float)$product->weight . "',
                                        '" . $productBrand . "',
                                        '" . $productCategory . "',
                                        '" . $productImage . "',
                                        '" . $INVENTORY_MANAGEMENT . "',
                                        '" . $publish . "',
                                        '" . $this->dateFormat($source_platform_name, $product->created_at) . "',
                                        '" . $productSubCategory . "',
                                        '" . $type . "',
                                        '" . $productCBM . "',
                                        '" . $remove . "',
                                        '" . $this->dateFormat($source_platform_name, $product->created_at) . "',
                                        '" . $updatedAt . "'
                                    );";
                                    //$this->dateFormat($source_platform_name, $product->api_updated_at)

                                    //insert variant vendor table
                                    $postVariantVenderStatement = "INSERT into VARIANT_VENDOR ( ID, VARIANT_ID, VENDOR_ID, COST_PRICE, LANDING_COST_PRICE, VENDOR_REFERENCE, VENDOR_NAME, MOQ, UOM, REMOVED, UPDATED_AT ) VALUES ( '" . $product->id . "', '" . $productVariant . "', 'unknown', '" . (float)$productPrice . "', '" . (float)$productRegularPrice . "', '', 'unknown', '0', '0', '" . $remove . "', '" . $updatedAt . "');";
                                }

                                $response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $post_data);
                               // Storage::append('Snowflake'.date('d-m-Y').'.txt', "Post Request: ".json_encode($post_data).json_encode($response));
                                if (isset($response['api_status']) && $response['api_status'] == 1) {

                                    $postVariantVender = [
                                        "timeout" => 1000,
                                        "resultSetMetaData" => [
                                            "format" => "json"
                                        ],
                                        "warehouse" => $databaseConfig['warehouse'], //$platform_account->region,
                                        "role" => "SYSADMIN",
                                        "statement" => $postVariantVenderStatement,
                                    ];

                                    $responseVariantVendor = $this->snowflakeApi->makeAPICall( $user_integration_id, $platform_account, $postVariantVender );
                                    if ( isset($response['api_status']) && $response['api_status'] == 0 ) {
                                        Storage::append('Snowflake/' . $user_integration_id . '/CreateProductVariantVendor/' . date('d-m-Y').'.txt', "Post Request: ".json_encode($postVariantVender));
                                        Storage::append('Snowflake/' . $user_integration_id . '/CreateProductVariantVendor/' . date('d-m-Y').'.txt', "Response: ".json_encode($responseVariantVendor));
                                    }

                                    if ($product->linked_id == 0) {
                                        $productLinking = PlatformProduct::where([
                                            "linked_id" => $product->id,
                                            "platform_id" => $this->platformId,
                                        ])
                                            ->first();

                                        if (!$productLinking) {
                                            $productLinkingNew = PlatformProduct::find($product->id);
                                            $productLinking = $productLinkingNew->replicate();

                                            if ($source_platform_name != "veracore" && isset($productLinking->$destinationColumn)) {
                                                $productAtt = $productLinkingNew->toArray();
                                                if (isset($productAtt[$product_identifier['source_identity']])) {
                                                    $productLinking->$destinationColumn = $productAtt[$sourceColumn];
                                                }
                                            }

                                            $productLinking->platform_id = $this->platformId;
                                            $productLinking->linked_id = $product->id;
                                            $productLinking->created_at = Carbon::now();
                                            $productLinking->updated_at = Carbon::now();
                                            $productLinking->product_sync_status = PlatformStatus::SYNCED;
                                            $productLinking->inventory_sync_status = PlatformStatus::PENDING;
                                            $productLinking->save();
                                        }

                                        $product->linked_id = $productLinking->id; // Update the product_sync_status
                                    }

                                    $product->product_sync_status = PlatformStatus::SYNCED;
                                    $product->save();

                                    $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $product->id, null);

                                    if( $source_platform_name == "peoplevox" || $source_platform_name == "logiwa" ){
                                        $post_data2 = $component_post_data=[];
                                        $table2 = "VARIANT_COMPONENT";
                                        $post_data2=$component_post_data = [
                                            "timeout" => 1000,
                                            "resultSetMetaData" => [
                                                "format" => "json"
                                            ],
                                            "warehouse" => $databaseConfig['warehouse'],
                                            "role" => "SYSADMIN",
                                        ];

                                        $child_item_list = PlatformPreProcessData::where([
                                            'user_integration_id' => $user_integration_id,
                                            'platform_id' => $source_platform_id,
                                            'status' => 1,
                                            'module' => 'PRODUCT',
                                            'api_id' => $productVariant
                                        ])
                                        ->select(DB::raw("CONCAT(sub_api_id, ' | ', description) as combined_value"))
                                        ->pluck('combined_value')->toArray();

                                        $extractedData = [];
                                        if( count( $child_item_list ) ){
                                            foreach ( $child_item_list as $result ) {
                                                list( $value, $count ) = explode( " | ", $result );
                                                $extractedData[] = [
                                                    'component_var_id' => $value,
                                                    'quantity' => $count
                                                ];
                                            }
                                        }
                                        $syncStatus = 0;
                                        if( count( $extractedData ) ){
                                            $componentLineFailed = false;
                                            $updatedAt = date( 'Y-m-d h:i:s' );
                                            foreach ( $extractedData as $component ) {
                                                $inv_update_query = "UPDATE $database.$schema.$table2 SET
                                                    QUANTITY = '" . (int)$component['quantity'] . "',
                                                    PRICE = '".(float)$productPrice."',
                                                    REMOVED = 0,
                                                    UPDATED_AT = '" .$updatedAt. "'
                                                WHERE
                                                    COMPONENT_VARIANT_ID = '" . $component['component_var_id'] . "' AND VARIANT_ID = '" . $productVariant . "';";

                                                $component_post_data["statement"] = $inv_update_query;

                                                $response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $component_post_data);

                                                if ($response['api_status'] && isset($response['api_data']['data'][0][0]) && $response['api_data']['data'][0][0] > 0) {
                                                    $syncStatus = 1;
                                                } else if ($response['api_status'] && isset($response['api_data']['data'][0][0]) &&  $response['api_data']['data'][0][0] == 0) {
                                                    $columns2 = [
                                                        'ID', 'VARIANT_ID', 'COMPONENT_VARIANT_ID', 'QUANTITY', 'PRICE', 'REMOVED', 'UPDATED_AT',
                                                    ];

                                                    $values2 = [
                                                        date("YmdHisu").random_int(1000, 9999), $productVariant, $component['component_var_id'], (int)$component['quantity'], (float)$productPrice, 0, $updatedAt
                                                    ];

                                                    // Generate the SQL statement using the prepared columns and values
                                                    $post_data2["statement"] = "INSERT INTO $database.$schema.$table2 (" . implode(', ', $columns2) . ") VALUES ('" . implode("', '", $values2) . "');";
                                                    $response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $post_data2);

                                                    if ($response['api_status'] && isset($response['api_data']['stats']['numRowsInserted']) && $response['api_data']['stats']['numRowsInserted'] == 1) {
                                                        $syncStatus = 1;
                                                    } else {
                                                        if ($response['api_data'] == "390318") {
                                                            $product->updated_at = date('Y-m-d H:i:s');
                                                            $product->save();
                                                            continue; // OAuth access token expired
                                                        }
                                                        $componentLineFailed = true;
                                                        $syncStatus = 0;
                                                        $return_response = $response['api_data'];
                                                    }
                                                } else {
                                                    if ($response['api_data'] == "390318") {
                                                        $product->updated_at = date('Y-m-d H:i:s');
                                                        $product->save();
                                                        continue; // OAuth access token expired
                                                    }
                                                    $syncStatus = 0;
                                                    $componentLineFailed = true;
                                                    $return_response = $response['api_data'];
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    if ($response['api_data'] == "390318") {
                                        Storage::append('Snowflake/'.$user_integration_id.'/390318/PostProduct-' . date('d-m-Y') . '.txt', "[" . date('d-m-Y h:i:s') . "] " . $user_integration_id . " OAuth access token expired: " . $product->variant_id);
                                        $product->updated_at = date('Y-m-d H:i:s');
                                        $product->save();
                                        continue; // OAuth access token expired
                                    }
                                    $return_response = $response['api_data'];
                                    $product->product_sync_status = PlatformStatus::FAILED;
                                    $product->save();

                                    $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $product->id, $return_response);
                                }
                            }
                        }
                    }
                } else {
                    $return_response = self::$myPlatform . " : Integration account detail not found";
                }
            }
        } catch (Exception $e) {
            $return_response = $e->getMessage();
            Log::error('SnowflakeApiController - createUpdateProducts - userIntegration- ' . $user_integration_id . " Error: " . $e->getLine() . " -> " . $return_response);
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
            $limit=50;
            $platform_account = $this->snowflakeApi->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {

                $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);
                if (!$databaseConfig['database'] || !$databaseConfig['schema']) {
                    return "Database or Schema information is not defined in account credentials.";
                }

                $sync_object_id = $this->snowflakeApi->connectionHelper->getObjectId('inventory');
                $identity = $this->snowflakeApi->ProductIdentityMapping($user_integration_id, $platform_workflow_rule_id); //Identify Product Uniqueness
                $source_identity = $identity['source_identity']; //Source Identity
                $destination_identity = $identity['destination_identity']; //Destination Identity
                // Log::info( $user_integration_id." Snowflake Identity: ".json_encode( $identity ) );

                if ($source_identity == "" || $destination_identity == "") {
                    $return_response = "Please complete product unique identifier mapping.";
                    $productsIds=PlatformProduct::where(['user_id'=>$user_id,'user_integration_id'=>$user_integration_id,'platform_id'=>$source_platform_id,'inventory_sync_status'=>PlatformStatus::READY])->pluck('id')->toArray();
                    if($productsIds){
                        PlatformProduct::whereIn('id',$productsIds)->update(['inventory_sync_status' => PlatformStatus::FAILED]);
                        $this->snowflakeApi->logger->syncLogBulk($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $productsIds, $return_response);
                    }
                } else {
                    $query = DB::table('platform_product as source_platform_product')
                        ->join('platform_product as destination_platform_product', 'destination_platform_product.' . $destination_identity, '=', 'source_platform_product.' . $source_identity)
                        ->select(
                            'source_platform_product.id',
                            'source_platform_product.sku',
                            'source_platform_product.barcode',
                            'source_platform_product.updated_at',
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

                    $products = $query->limit($limit)->distinct()->orderBy('source_platform_product.updated_at')->get();
                    // Log::info( $user_integration_id." Total Inventory: ".Count( $products ) );

                    if ($products) {
                        $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);
                        $database = $databaseConfig['database']; //$platform_account->marketplace_id;
                        $schema = $databaseConfig['schema']; //$platform_account->custom_domain;
                        $table = "VARIANT_WAREHOUSE";

                        $post_data=$inventory_post_data = [
                            "timeout" => 1000,
                            "resultSetMetaData" => [
                                "format" => "json"
                            ],
                            "warehouse" => $databaseConfig['warehouse'], //$platform_account->region,
                            "role" => "SYSADMIN",
                        ];

                        foreach ($products as $product) {
                            $productPrimaryId=$product->id;

                             if($source_platform_name=="netsuite"){
                                $inventoryObject="inventory_location";
                                $mappingType='cross';
                                $productVariant = $product->api_product_id;
                            }else{
                                $inventoryObject="inventory_warehouse";
                                $mappingType='regular';
                                $productVariant = $product->api_variant_id;
                            }

                            $invQuery = PlatformProductInventory::where(
                                'platform_product_id', $productPrimaryId);
                            if ($record_id) {
                                $invQuery->whereNotIn('sync_status', [PlatformStatus::SYNCED]);
                            }else{
                                $invQuery->where('sync_status', PlatformStatus::READY);
                            }
                            $productInventories = $invQuery->get();

                            if ($totalInventory=count($productInventories)) {
                                $inventoryLineFailed=false;
                                $noWareHouseMappingFound=0;

                                foreach ($productInventories as $inventory) {
                                    $warehouseName=null;
                                    $warehouseId = 0;
                                      // Find one to one warehouse map if api_warehouse_id is set
                                    $warehouseMapping = $this->snowflakeApi->fieldMapHelper->getMappedDataByName($user_integration_id, null, $inventoryObject, ['api_id','name'], $mappingType, $inventory->api_warehouse_id);

                                    if ($warehouseMapping) {
                                        $warehouseId = $warehouseMapping->api_id;
                                        $warehouseName = $warehouseMapping->name;
                                    }

                                    // Find default warehouse map if api_warehouse_id is not set
                                    if (!$inventory->api_warehouse_id) {

                                        $default_warehouse_mapping = $this->snowflakeApi->fieldMapHelper->getMappedDataByName($user_integration_id, NULL, "inventory_warehouse", ['api_id','name']);
                                        if ($default_warehouse_mapping) {
                                            $warehouseId = $default_warehouse_mapping->api_id;
                                            $warehouseName = $default_warehouse_mapping->name;
                                        }
                                    }

                                    if(!$warehouseId){
                                        $noWareHouseMappingFound++;
                                        continue;//skip inventory sync if no warehouse mapping found
                                    }

                                    $updatedAt = date( 'Y-m-d h:i:s' );
                                    $inv_update_query = "UPDATE $database.$schema.$table SET
                                        IN_STOCK = '" . $inventory->quantity . "',
                                        GROSS_WEIGHT = 0,
                                        MIN_STOCK = 0,
                                        MAX_STOCK = 0,
                                        MAX_ORDER = 0,
                                        TRANSFER_UOM = 0,
                                        REMOVED = 0,
                                        UPDATED_AT = '" .$updatedAt. "'
                                    WHERE
                                        VARIANT_ID = '" . $productVariant . "' AND WAREHOUSE_ID = '" . $warehouseId . "';";

                                    $inventory_post_data["statement"] = $inv_update_query;

                                    $response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $inventory_post_data);
                                    // Storage::append( 'Snowflake/'.$user_integration_id.'/ProductsInventory/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $inventory_post_data )." ".json_encode( $response ) );

                                    $syncStatus = 0;
                                    if ($response['api_status'] && isset($response['api_data']['data'][0][0]) && $response['api_data']['data'][0][0] > 0) {
                                        $syncStatus = 1;
                                    } elseif ($response['api_status'] && isset($response['api_data']['data'][0][0]) &&  $response['api_data']['data'][0][0] == 0) {
                                        $columns = [
                                            'ID', 'VARIANT_ID', 'WAREHOUSE_ID', 'IN_STOCK', 'GROSS_WEIGHT', 'MIN_STOCK', 'MAX_STOCK', 'MAX_ORDER',
                                            'TRANSFER_UOM', 'REMOVED', 'UPDATED_AT',
                                        ];

                                        $values = [
                                            $inventory->id, $productVariant, $warehouseId, $inventory->quantity, 0, 0, 0, 0,
                                            0, 0, $updatedAt
                                        ];//$inventory->updated_at

                                        // Generate the SQL statement using the prepared columns and values
                                        $post_data["statement"] = "INSERT INTO $database.$schema.$table (" . implode(', ', $columns) . ") VALUES ('" . implode("', '", $values) . "');";
                                        $response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $post_data);
                                        // Storage::append( 'Snowflake/'.$user_integration_id.'/ProductsInventory/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $post_data )." ".json_encode( $response ) );

                                        if ($response['api_status'] && isset($response['api_data']['stats']['numRowsInserted']) && $response['api_data']['stats']['numRowsInserted'] == 1) {
                                            $syncStatus = 1;
                                        } else {
                                            if ($response['api_data'] == "390318") {
                                                PlatformProduct::where('id', $product->id)->update(['updated_at'=>date('Y-m-d H:i:s')]);
                                                continue; // OAuth access token expired
                                            }
                                            $inventoryLineFailed=true;
                                            $syncStatus = 0;
                                            if($warehouseName){
                                                $warehouseName=" (".$warehouseName.")";
                                            }
                                            $return_response = $response['api_data'].$warehouseName;
                                        }
                                    } else {
                                        if ($response['api_data'] == "390318") {
                                            PlatformProduct::where('id', $product->id)->update(['updated_at'=>date('Y-m-d H:i:s')]);
                                            continue; // OAuth access token expired
                                        }
                                        $syncStatus = 0;
                                        $inventoryLineFailed=true;

                                        if($warehouseName){
                                            $warehouseName=" (".$warehouseName.")";
                                        }
                                        $return_response = $response['api_data'].$warehouseName;
                                    }

                                    if ($syncStatus) {
                                        $inventory->sync_status =PlatformStatus::SYNCED;
                                    } else {
                                        $inventory->sync_status =PlatformStatus::FAILED;
                                    }
                                    $inventory->save();

                                }
                                if(!$inventoryLineFailed && $totalInventory!=$noWareHouseMappingFound){
                                    $status=PlatformStatus::SYNCED;
                                    $syncLogStatus="success";
                                }else if($totalInventory==$noWareHouseMappingFound){
                                    $status=PlatformStatus::FAILED;
                                    $syncLogStatus="failed";
                                    $return_response="Please check inventory warehouse mapping";
                                }else{
                                    $status=PlatformStatus::FAILED;
                                    $syncLogStatus="failed";
                                    $return_response="Partial inventory synced, Resync again";
                                }
                                PlatformProduct::where("id", $productPrimaryId)->update(['inventory_sync_status'=>$status]);
                                $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, $syncLogStatus, $productPrimaryId, $return_response);
                            } else {
                                $findAnyInventoryCount=PlatformProductInventory::where('platform_product_id', $productPrimaryId)->count();
                                if($findAnyInventoryCount){//if no pending inventory record found for sync Set inventory_sync_status synced as in product table
                                    PlatformProduct::where("id", $productPrimaryId)->update(['inventory_sync_status'=>PlatformStatus::SYNCED]);
                                    $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $productPrimaryId, null);

                                }else{
                                    $return_response = "No inventory found for this product";
                                    PlatformProduct::where("id", $productPrimaryId)->update(['inventory_sync_status'=>PlatformStatus::FAILED]);
                                    $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $productPrimaryId, $return_response);

                                }

                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $return_response = $e->getMessage();
            Log::error($user_integration_id . ' - SnowflakeApiController - updateProductInventory - ' . $return_response);
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
            $platform_account = $this->snowflakeApi->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {

                $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);
                if (!$databaseConfig['database'] || !$databaseConfig['schema']) {
                    return "Database or Schema information is not defined in account credentials.";
                }

                $post_data = [
                    "timeout" => 1000,
                    "resultSetMetaData" => [
                        "format" => "json"
                    ],
                    "warehouse" => $databaseConfig['warehouse'], //$platform_account->region,
                    "role" => "SYSADMIN",
                ];

                $sync_object_id = $this->snowflakeApi->connectionHelper->getObjectId('order');
                $database = $databaseConfig['database']; //$platform_account->marketplace_id;
                $schema = $databaseConfig['schema']; //$platform_account->custom_domain;
                $table = "SALES_ORDERS";
                $limit = 50;
                // $offset = 0;

                // Define the search criteria for the query
                $searchCriteria = [
                    #'user_id' => $user_id,
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
                    //'linked_id' => 0,
                    'is_deleted' => 0
                ]);
                $platformOrders = $query->where($searchCriteria)->orderBy('updated_at', 'ASC')->limit($limit)->get();

                if ($platformOrders->isNotEmpty()) {

                    $identity = $this->snowflakeApi->ProductIdentityMapping($user_integration_id, $platform_workflow_rule_id); //Identify Product Uniqueness
                    $source_identity = $identity['source_identity']; //Source Identity
                    $destination_identity = $identity['destination_identity']; //Destination Identity

                    if (isset(Config::get('apisettings.UniqueIdentityForSnowflakeSoMutate')[$source_platform_name])) {
                        $source_identity = $destination_identity = Config::get('apisettings.UniqueIdentityForSnowflakeSoMutate')[$source_platform_name][0];
                    }

                    Storage::append('Snowflake/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: source_identity: " . $source_identity . ", destination_identity: " . $destination_identity);

                    if ($source_identity == "" || $destination_identity == "") {
                        $return_response = "Please complete product unique identifier mapping.";
                        PlatformOrder::where('sync_status', PlatformStatus::READY)->update(['sync_status' => PlatformStatus::FAILED]);
                    } else {
                        foreach ($platformOrders as $sourceOrder) {
                            $variant_id = "";
                            $result = false;
                            $OrderwarehouseId = null;

                            if ($source_platform_name == "netsuite") { //only for netsuite warehouse mapping
                                $location_object_data = $this->snowflakeApi->mainModel->getFirstResultByConditions('platform_object_data', ['id' => $sourceOrder->warehouse_id, 'status' => 1], ['api_id']);

                                if ($location_object_data) {
                                    $warehouseId = $this->snowflakeApi->fieldMapHelper->getMappedDataByName($user_integration_id, null, "order_warehouse", ['api_id'], 'cross', $location_object_data->api_id);

                                    if ($warehouseId) {
                                        $OrderwarehouseId = $warehouseId->api_id;
                                    }
                                }
                            } else {

                                $salesOrderObject = $this->snowflakeApi->mainModel->getFirstResultByConditions('platform_objects', ['name' => 'warehouse'], ['id']);
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
                                if (isset(Config::get('apisettings.IgnoreWarehouseMapInSoSync')[$source_platform_name])) {
                                    $default_warehouse = $this->snowflakeApi->fieldMapHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "order_warehouse", ['api_id']);
                                    if ($default_warehouse) {
                                        $OrderwarehouseId = $default_warehouse->api_id;
                                    }
                                }
                            }

                            if (is_null($OrderwarehouseId)) {
                                $return_response = "No warehouse mapping found";
                            } else {
                                Storage::append('Snowflake/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]:  OrderwarehouseId: " . $OrderwarehouseId);
                                if (isset($sourceOrder->platformOrderLine)) {

                                    $olines = PlatformOrderLine::where('platform_order_id', $sourceOrder->id)->where('row_type', 'ITEM')->pluck($source_identity)->toArray();

                                    $destination_identityTemp = $destination_identity;
                                    if (isset(Config::get('apisettings.UniqueIdentityForSnowflakeSoMutate')[$source_platform_name])) {
                                        $destination_identityTemp = Config::get('apisettings.UniqueIdentityForSnowflakeSoMutate')[$source_platform_name][1];
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

                                    $totalPlines = array_unique( array_filter( $totalPlines ) );
                                    Storage::append('Snowflake/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: Total OLines: " . count($olines) . ", Total PLines: " . count($totalPlines));

                                    //when order item is bundle and use variant id as product id
                                    if( $source_platform_name == 'tiktok' ){
										if ( count( $olines ) != count( $totalPlines ) ) {

											$diffProducts = array_diff( $olines, $totalPlines );//get only not found product/variant id

											if ( isset( Config::get('apisettings.UniqueIdentityForSnowflakeSoMutate')[$source_platform_name] ) ) {
												$destination_identityTemp = Config::get('apisettings.UniqueIdentityForSnowflakeSoMutate')[$source_platform_name][0];
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

											Storage::append('Snowflake/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: Total OLines: " . count($olines) . ", Total PIDLines: " . count($totalPIDlines).", totalPlines: ".count( $totalPlines ) );
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
                                        ->whereIn( $destination_identity,  $diffProducts )
                                        ->update([
                                            'product_sync_status' => 'Ready'
                                        ]);

                                        Storage::append('Snowflake/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: productMappingArr: " . json_encode( $olines ) . ", productIds: " . json_encode( $totalPlines ) . ' Diff Product-' . json_encode( $diffProducts ) );

                                        $return_response = "Some of order line item not found " . implode(",", $diffProducts);
                                        $sourceOrder->updated_at = date('Y-m-d H:i:s');
                                        $sourceOrder->save();
                                        continue;
                                    }

                                    $assignShippingCost = false;
                                    if ($sourceOrder->shipping_total > 0) { //if shipping cost is greater than 0
                                        $assignShippingCost = true;
                                    }

                                    // Check for cancelled orders and set flag
                                    $removedStatus = 0;
                                    if ($sourceOrder->is_voided) {
                                        $removedStatus = 1;
                                    }

                                    if($sourceOrder->linked_id > 0){
                                        $update_query = "UPDATE $database.$schema.$table SET
                                        REMOVED = $removedStatus,
                                        UPDATED_AT = '" .date( 'Y-m-d h:i:s' ). "'
                                        WHERE
                                            ID = '" . $sourceOrder->api_order_id . "';";

                                        $post_data["statement"] = $update_query;

                                        $response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $post_data);

                                        if ($response['api_status'] && isset($response['api_data']['data'][0][0]) && $response['api_data']['data'][0][0] > 0) {
                                            $result = true;
                                        } else {
                                            $return_response = $response['api_data'];
                                        }
                                    } else{
                                        foreach ($sourceOrder->platformOrderLine as $orderLine) {
                                            if ($orderLine->row_type == "SHIPPING") {
                                                $sourceOrder->updated_at = date('Y-m-d H:i:s');
                                                $sourceOrder->save();
                                                continue; //ignore shipping row_type lines due to aleady added amount in a fist line item
                                            }
                                            $shipCost = 0;
                                            $shipCostTax = 0;

                                            if ($assignShippingCost) {
                                                $shipCost = $sourceOrder->shipping_total;
                                                $shipCostTax = $sourceOrder->shipping_tax;
                                                $assignShippingCost = false;
                                            }

                                            $variant_id = $orderLine->$destination_identity;
                                            Storage::append('Snowflake/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: variant_id - " . $variant_id);

                                            // Prepare the column and value arrays for the SQL statement
                                            $columns = [
                                                'ORDER_ID', 'ID', 'VARIANT_ID', 'REFERENCE', 'REFERENCE2', 'CREATED_AT', 'WAREHOUSE', 'CURRENCY', 'QUANTITY',
                                                'PRICE', 'DISCOUNT', 'TAX', 'TAX_INCLUDED', 'SHIPPING', 'SHIPPING_TAX', 'CANCELLED', 'STATUS', 'REMOVED',
                                                'CUSTOMER_NAME', 'CUSTOMER_EMAIL', 'CUSTOMER_COMPANY', 'UPDATED_AT',
                                            ];
                                            if ($source_platform_name == "netsuite") {
                                                $linePrice = $orderLine->unit_price;
                                            } else {
                                                $linePrice = $orderLine->price;
                                            }

                                            $orderLineId = ($orderLine->api_order_line_id) ? $orderLine->api_order_line_id : $orderLine->id;

                                            $values = [
                                                $orderLineId, //order_id
                                                $sourceOrder->api_order_id, //id
                                                $variant_id, // variant_id
                                                $sourceOrder->api_order_reference, // reference
                                                $sourceOrder->notes, // reference2
                                                $sourceOrder->order_date, //created_at
                                                $OrderwarehouseId, //warehouse
                                                $sourceOrder->currency, //currency
                                                (int)$orderLine->qty, // quantity
                                                (float)$linePrice, //price
                                                (float)$orderLine->discount_amount, //discount
                                                (float)$orderLine->subtotal_tax, // tax
                                                0, // tax_included
                                                (float)$shipCost ?? 0, // shipping
                                                (float)$shipCostTax ?? 0, // shipping_tax
                                                0, // cancelled
                                                0, // status
                                                $removedStatus, // removed
                                                $sourceOrder->platformCustomer->customer_name ?? '', //customer name
                                                $sourceOrder->platformCustomer->email ?? '', //customer email
                                                $sourceOrder->platformCustomer->company_name ?? '', // customer company
                                                date( 'Y-m-d h:i:s' ),//$this->dateFormat($source_platform_name, $sourceOrder->updated_at), // updated_at
                                            ];

                                            if ($source_platform_name == "peoplevox") {
                                                $productInfo = PlatformProduct::where([
                                                    'user_id' => $user_id,
                                                    'user_integration_id' => $user_integration_id,
                                                    'platform_id' => $source_platform_id,
                                                ])
                                                ->where($destination_identity, $variant_id)
                                                ->select('id')->first();

                                                if( $productInfo ){
                                                    $productCostPrice = 0;
                                                    $default_product_currency = $this->snowflakeApi->fieldMapHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "default_product_currency", ['api_code'], "default");
                                                    $product_currency = isset($default_product_currency->api_code) ? $default_product_currency->api_code : 'USD';
                                                    $price = $this->snowFlakeService->findPriceList($productInfo->id, $product_currency, 'cost_price');
                                                    if (isset($price['price'])) {
                                                        $productCostPrice = $price['price'];
                                                    }
                                                    $columns[] = 'COST_PRICE';
                                                    $values[] = number_format($productCostPrice, 4);
                                                }
                                            }

                                            // Generate the SQL statement using the prepared columns and values
                                            $statement = "INSERT INTO $database.$schema.$table (" . implode(', ', $columns) . ") VALUES ('" . implode("', '", $values) . "');";
                                            // Assign the SQL statement to the $post_data array
                                            $post_data["statement"] = $statement;
                                            $response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $post_data);

                                            if ($response['api_status']) {
                                                $result = true;
                                            } else {
                                                $return_response = $response['api_data'];

                                                if ($response['api_data'] == "390318") {
                                                    $sourceOrder->updated_at = date('Y-m-d H:i:s');
                                                    $sourceOrder->save();
                                                    continue; // OAuth access token expired
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            if ($result) {
                                if($sourceOrder->linked_id > 0){
                                    $sourceOrder->sync_status = PlatformStatus::SYNCED;
                                    $sourceOrder->save();
                                } else {
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
                                }
                                $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $sourceOrder->id, null);
                            } else {
                                $sourceOrder->updated_at = date('Y-m-d H:i:s');
                                if ($return_response == "390318") {
                                    $sourceOrder->sync_status = PlatformStatus::READY;
                                    $sourceOrder->save();
                                    continue; // OAuth access token expired
                                }
                                // $return_response = $response['api_data'];
                                $sourceOrder->sync_status = PlatformStatus::FAILED;
                                $sourceOrder->save();
                                $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $sourceOrder->id, $return_response);
                            }
                        }
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (Exception $e) {
            Log::error($user_integration_id . "-- SnowflakeApiController createSalesOrder -->" . $e->getMessage() . '-->' . $e->getLine());
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
            $platform_account = $this->snowflakeApi->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {

                $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);
                if (!$databaseConfig['database'] || !$databaseConfig['schema']) {
                    return "Database or Schema information is not defined in account credentials.";
                }

                $post_data =$item_post_data= [
                    "timeout" => 1000,
                    "resultSetMetaData" => [
                        "format" => "json"
                    ],
                    "warehouse" => $databaseConfig['warehouse'],
                    "role" => "SYSADMIN",

                ];

                $database = $databaseConfig['database']; //$platform_account->marketplace_id;
                $schema = $databaseConfig['schema']; //$platform_account->custom_domain;
                $table = "PURCHASE_ORDERS";
                $limit = 20;
                $where = [];

                if ($record_id) {
                    $where['id'] = $record_id;
                } else {
                    $where['user_id'] = $user_id;
                    $where['platform_id'] = $source_platform_id;
                    $where['user_integration_id'] = $user_integration_id;
                    $where['order_type'] = $type;
                    $where['shipment_status'] = PlatformStatus::READY;
                }

                $orders = PlatformOrder::where($where)
                    ->where($where)
                    // ->whereIn( 'shipment_status', [ PlatformStatus::READY, PlatformStatus::PARTIAL ] )
                    ->limit($limit)
                    ->orderBy('updated_at', 'asc')
                    ->get();



                if (count($orders)) {
                    $productIdentity = $this->snowFlakeService->ProductIdentityMapping($user_integration_id, $platform_workflow_rule_id); //Identify Product Uniqueness



                    if ($productIdentity) {

                        $source_identity = $productIdentity['source_identity'];

                        // If custom identifier is required for PO receipt sync
                        $source_platform_name = $this->snowflakeApi->connectionHelper->getPlatformNameByID($source_platform_id);
                        if (isset(Config::get('apisettings.UniqueIdentityForSnowflakeOrderReceiptMutate')[$source_platform_name])) {
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

                        $sync_object_id = $this->snowflakeApi->connectionHelper->getObjectId($objectName);

                        foreach ($orders as $order) {

                            if (!$order->linked_id)
                                continue; //ignore this order if no linked id found.

                            $destinationOrderID = @$order->linkedOrder->api_order_id;
                            if ($type == "PO") {
                                //Destination Line Item Product sum
                                $count = @$order->linkedOrder->platformOrderLine->sum('qty');
                            } elseif ($type == "TO") {
                                //Destination Line Item Product sum
                                $shipments = PlatformOrderShipment::where('platform_order_id', $order->linked_id)->first();


                                $count = @$shipments->platformShippingLines->sum('quantity');

                            }

                            $query = PlatformOrderShipment::where('platform_order_id', $order->id);
                            if ($query->count()) {
                                /* Find Source Line Item Product Quantity Sum */
                                $orderShipmentIds = $query->get();

                                $sum = 0;
                                foreach ($orderShipmentIds as $shipment) {
                                    $sum = $sum + $shipment->platformShippingLines->sum('quantity');
                                }



                                $orderShipment = $query->whereIn('sync_status', ['Ready', 'Failed'])->get();

                                $error = null;
                                $errorOrderFinalFlag = false;
                                foreach ($orderShipment as $shipment) {

                                    $errorShipmentFlag = false;
                                    if (isset($shipment->platformShippingLines) && count($shipment->platformShippingLines)) {
                                        $shipment->platformShippingLines->sum('quantity');
                                        $totalLines=count($shipment->platformShippingLines);
                                        $totalLineProcess=0;
                                        foreach ($shipment->platformShippingLines as $itemRec) {
                                            $skipItem = false;
                                            $receive = $Old = $itemRec->quantity;
                                            //get old receive qty
                                            $rec_post_data["statement"] = "SELECT REPLENISHMENT,RECEIVED FROM $database.$schema.$table
                                                 WHERE ID = '{$destinationOrderID}'
                                                 AND $matchShipLineBy = '{$itemRec->$source_identity}';";
                                            $recieve_quantity_post_data = array_merge($rec_post_data, $item_post_data);
                                            if ($user_integration_id == 749) {
                                                Storage::append('Snowflake/' . $user_integration_id . '/createOrderReceipt/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: Shipment Order Status: " . json_encode($recieve_quantity_post_data));
                                            }

                                            $rec_response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $recieve_quantity_post_data);

                                            if ($rec_response['api_status'] && isset($rec_response['api_data']['data'][0][1])) {
                                                if (is_array($rec_response['api_data']) && !empty($rec_response['api_data'])) {
                                                    $receive += ($rec_response['api_data']['data'][0][1] != "" && $rec_response['api_data']['data'][0][1] != null) ? (int)$rec_response['api_data']['data'][0][1] : 0;

                                                    if($rec_response['api_data']['data'][0][0] < $receive){//Fallback: do not send more than order qty
                                                        $receive =$rec_response['api_data']['data'][0][0];
                                                    }
                                                } else {
                                                    $skipItem = true;
                                                    continue; // if server response busy error get from SF
                                                }
                                            } else {
                                                if ($rec_response['api_data'] == "390318") {
                                                    continue; // OAuth access token expired
                                                }
                                                $skipItem = true;
                                                $errorShipmentFlag = true;
                                                continue;
                                            }

                                            // if ($user_integration_id == 689) {
                                            //     Storage::append('Snowflake/' . $user_integration_id . '/createOrderReceipt/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: $matchShipLineBy = $itemRec->$source_identity");
                                            // }
                                            if (!$skipItem) {
                                                //update receive qty
                                                $updatedAt = date( 'Y-m-d h:i:s' );
                                                $post_data["statement"] = "UPDATE $database.$schema.$table SET
                                                                            RECEIVED = {$receive},
                                                                            RECEIVED_DATE = '{$itemRec->updated_at}',
											                                UPDATED_AT = '{$updatedAt}'
                                                                            WHERE ID = '{$destinationOrderID}'
                                                                            AND $matchShipLineBy = '{$itemRec->$source_identity}';";

                                                $response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $post_data);

                                                if (isset($response['api_data']['data'][0][0]) && $response['api_status'] && $response['api_data']['data'][0][0] >= 0) {
                                                    $error = null;
                                                    $totalLineProcess++;
                                                } else {
                                                    if ($response['api_data'] == "390318") {
                                                         continue; // OAuth access token expired
                                                    }
                                                    $errorShipmentFlag = true;
                                                    $return_response = $error = $response['api_data'];
                                                    continue;
                                                }

                                                // if ($user_integration_id == 689) {
                                                //     Storage::append('Snowflake/' . $user_integration_id . '/createOrderReceipt/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: return_response: " . $return_response);
                                                // }
                                            }
                                        }

                                        if ($errorShipmentFlag) {
                                            if($totalLines==$totalLineProcess || $totalLineProcess==0){
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
                                        $status = "failed";
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
                                $return_response= $error;
                                $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, $status, $order->id, $error);
                            } else {
                                $order->shipment_status = PlatformStatus::FAILED;
                                $status = "failed";
                                $error =  $return_response="No receipt found for this order.";
                                $order->save();

                                $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, $status, $order->id, $error);
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Log::error($user_integration_id . "-- SnowflakeApiController createOrderReceipt --> " . $e->getMessage() . " Line -->" . $e->getLine());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* get Warehouse */
    public function getWarehouse($user_id, $user_integration_id, $source_platform_id, $source_platform_name)
    {
        $return_response = true;
        try {
            $platform_account = $this->snowflakeApi->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {
                $source_warehouse_object_id = $source_platform_name == "netsuite" ? $this->snowflakeApi->connectionHelper->getObjectId('location') : $this->snowflakeApi->connectionHelper->getObjectId('warehouse');
                $destination_warehouse_object_id = $this->snowflakeApi->connectionHelper->getObjectId('warehouse');

                //revert object data status
                PlatformObjectData::where([
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $destination_warehouse_object_id,
                ])->update(['status' => 0]);

                $sourceWarehouseList = PlatformObjectData::where([
                    'user_id' => $user_id,
                    'platform_id' => $source_platform_id,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $source_warehouse_object_id,
                    'status' => 1,
                ])->get();

                if (count($sourceWarehouseList)) {
                    foreach ($sourceWarehouseList as $wh) {
                        $findWh = PlatformObjectData::where([
                            'user_id' => $user_id,
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'platform_object_id' => $destination_warehouse_object_id,
                            'api_id' => $wh->api_id,
                        ])->first();

                        if ($findWh) {
                            $findWh->status = 1;
                            $findWh->name = $wh->name;
                            $findWh->save();
                        } else {
                            PlatformObjectData::create([
                                'user_id' => $user_id,
                                'platform_id' => $this->platformId,
                                'user_integration_id' => $user_integration_id,
                                'platform_object_id' => $destination_warehouse_object_id,
                                'api_id' => $wh->api_id,
                                'name' => $wh->name,
                                'status' => 1,
                            ]);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Log::error($user_integration_id . "-- SnowflakeApiController getWarehouse -->" . $e->getMessage() . '-->' . $e->getLine());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Sycn/Clone Warehouse to SF database */
    public function syncWarehouse($user_id, $user_integration_id, $source_platform_name, $is_initial_sync)
    {
        $return_response = true;

        try {
            if ($is_initial_sync) {
                $platform_account = $this->snowflakeApi->getAccountDetails($user_integration_id); // get the account information for the integration

                if ($platform_account) {

                    $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);
                    if (!$databaseConfig['database'] || !$databaseConfig['schema']) {
                        return "Database or Schema information is not defined in account credentials.";
                    }

                    $destination_warehouse_object_id = $this->snowflakeApi->connectionHelper->getObjectId('warehouse');
                    $destinationWarehouseList = PlatformObjectData::where([
                        'user_id' => $user_id,
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'platform_object_id' => $destination_warehouse_object_id,
                        'status' => 1,
                    ])->get();
                    if (count($destinationWarehouseList)) {

                        $post_data = [
                            "timeout" => 1000,
                            "resultSetMetaData" => [
                                "format" => "json"
                            ],
                            "warehouse" => $databaseConfig['warehouse'], //$platform_account->region,
                            "role" => "SYSADMIN",
                        ];

                        $database = $databaseConfig['database']; //$platform_account->marketplace_id;
                        $schema = $databaseConfig['schema']; //$platform_account->custom_domain;
                        $table = "WAREHOUSES";

                        foreach ($destinationWarehouseList as $wh) {

                            $currency = $shipping_address = $billing_address = '';
                            $status = $wh->status;
                            $updateDate = date( 'Y-m-d h:i:s' );//$this->dateFormat($source_platform_name, $wh->updated_at); //format date as Y-m-d H:i:s
                            if (!$status) { //basically this part is not working in this case

                                $post_data["statement"] = "UPDATE $database.$schema.$table SET
                                        DISPLAY_NAME = '" . str_replace("'", "\'", $wh->name) . "',
                                        CURRENCY = '" . $currency . "',
                                        SHIPPING_ADDRESS = '" . str_replace("'", "\'", $shipping_address) . "',
                                        BILLING_ADDRESS = '" . str_replace("'", "\'", $billing_address) . "',
                                        REMOVED = '" . $status . "',
                                        UPDATED_AT = '" . $updateDate . "'
                                    WHERE WAREHOUSE_ID = '" . $wh->api_id . "';";
                            } else {
                                $post_data["statement"] = "INSERT into $database.$schema.$table(
                                        WAREHOUSE_ID, DISPLAY_NAME, CURRENCY, SHIPPING_ADDRESS, BILLING_ADDRESS, REMOVED, UPDATED_AT
                                    ) values (
                                        '" . str_replace("'", "\'", $wh->api_id) . "',
                                        '" . str_replace("'", "\'", $wh->name) . "',
                                        '" . str_replace("'", "\'", $currency) . "',
                                        '" . str_replace("'", "\'", $shipping_address) . "',
                                        '" . str_replace("'", "\'", $billing_address) . "',
                                        0,
                                        '" . $updateDate . "'
                                    );";
                            }

                            $response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $post_data);

                            if ($response['api_status']) {

                                //$return_response = true;
                            } else {


                                $return_response = $response['api_data'];
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Log::error($user_integration_id . "-- SnowflakeApiController syncWarehouse -->" . $e->getMessage() . ' --> ' . $e->getLine());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /**
     * get warehouse list
     *
     * Server Response:
     *  0 => "17042023080313062" // WAREHOUSE_ID
     *  1 => "WareHouse: 17042023080313062" // DISPLAY_NAME
     *  2 => "Billing 1" // BILLING_ADDRESS
     *  3 => "Shipping 1" // SHIPPING_ADDRESS
     *  4 => "USD" // CURRENCY
     *  5 => "false" // REMOVED
     *  6 => "1997168400.000000000" // UPDATED_AT
     */
    public function getWareHouseLists($user_id, $user_integration_id)
    {
        $return_response = true;
        try {
            $platform_account = $this->snowflakeApi->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {

                $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);
                if (!$databaseConfig['database'] || !$databaseConfig['schema']) {
                    return "Database or Schema information is not defined in account credentials.";
                }

                $post_data = [
                    "timeout" => 1000,
                    "resultSetMetaData" => [
                        "format" => "json"
                    ],
                    "warehouse" => $databaseConfig['warehouse'], //$platform_account->region,
                    "role" => "SYSADMIN",
                ];

                $database = $databaseConfig['database']; //$platform_account->marketplace_id;
                $schema = $databaseConfig['schema']; //$platform_account->custom_domain;

                $post_data["statement"] = "SELECT * FROM $database.$schema.WAREHOUSES WHERE REMOVED = false";
                $response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $post_data);

                if ($response['api_status']) {
                    $salesOrderObject = $this->snowflakeApi->mainModel->getFirstResultByConditions('platform_objects', ['name' => 'warehouse'], ['id']);
                    $warehouseList = $response['api_data']['data'];

                    //revert object data status
                    PlatformObjectData::where([
                        'platform_id' => $this->platformId,
                        'user_integration_id' => $user_integration_id,
                        'platform_object_id' => $salesOrderObject->id,
                    ])
                        ->update(['status' => 0]);

                    foreach ($warehouseList as $ar) {
                        $platformObjData = PlatformObjectData::where([
                            'platform_id' => $this->platformId,
                            'user_integration_id' => $user_integration_id,
                            'platform_object_id' => $salesOrderObject->id,
                            'api_id' => $ar[0],
                        ])
                            ->first();

                        if (!$platformObjData) {
                            $platformObjData = new PlatformObjectData();
                            $platformObjData->user_id = $user_id;
                            $platformObjData->platform_id = $this->platformId;
                            $platformObjData->user_integration_id = $user_integration_id;
                            $platformObjData->platform_object_id = $salesOrderObject->id;
                            $platformObjData->api_id = $ar[0];
                        }

                        $platformObjData->api_code = $ar[1];
                        $platformObjData->name = ucfirst(strtolower(str_ireplace("_", " ", $ar[1])));
                        $platformObjData->status = 1;
                        $platformObjData->save();
                    }
                } else {
                    $return_response = $response['api_data'];
                }
            }
        } catch (Exception $e) {
            Log::error($user_integration_id . ' - SnowflakeApiController - getWareHouseLists - ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /**
     * vendor as a customers, suppliers
     */
    public function CreateVendor($user_id, $user_integration_id, $source_platform_id, $source_platform_name, $user_workflow_rule_id, $record_id)
    {
        $return_response = true;
        try {
            $platform_account = $this->snowflakeApi->getAccountDetails($user_integration_id); // get the account information for the integration

            if ($platform_account) {

                $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);
                if (!$databaseConfig['database'] || !$databaseConfig['schema']) {
                    return "Database or Schema information is not defined in account credentials.";
                }

                $post_data = [
                    "timeout" => 1000,
                    "resultSetMetaData" => [
                        "format" => "json"
                    ],
                    "warehouse" => $databaseConfig['warehouse'], //$platform_account->region,
                    "role" => "SYSADMIN",
                ];

                $database = $databaseConfig['database']; //$platform_account->marketplace_id;
                $schema = $databaseConfig['schema']; //$platform_account->custom_domain;
                $limit = 25;
                $offset = 0;

                if ($record_id) {
                    $where['id'] = $record_id;
                    // $where['sync_status'] = PlatformStatus::FAILED;
                } else {
                    $where['platform_id'] = $source_platform_id;
                    $where['user_integration_id'] = $user_integration_id;
                    $where['sync_status'] = PlatformStatus::READY;
                    $where['is_deleted'] = 0; //new condition added to only pick active customer/verndors
                }

                $vendorArr = PlatformCustomer::where($where)
                    ->offset($offset)
                    ->limit($limit)
                    ->get();

                if ($vendorArr) {
                    $sync_object_id = $this->snowflakeApi->connectionHelper->getObjectId('supplier');
                    $table = "VENDORS";

                    foreach ($vendorArr as $vendor) {

                        if (!empty($vendor->customer_name)) {

                            $defaultCurrency = NULL;
                            $currencyFind = $this->snowflakeApi->fieldMapHelper->getMappedDataByName($user_integration_id, NULL, "default_currency",  ['custom_data'], "default");
                            if ($currencyFind) {
                                $defaultCurrency = $currencyFind->custom_data;
                            }

                            $currency = isset($vendor->extraInfo->currency) ? $vendor->extraInfo->currency : null;
                            if (!$currency) {
                                $currency = $defaultCurrency; //set default currency if not found
                            }
                            if (!empty($currency)) {
                                if ($vendor->linked_id) {
                                    $updateDate = date( 'Y-m-d h:i:s' );//$this->dateFormat($source_platform_name, $vendor->api_updated_at); //format date as Y-m-d H:i:s
                                    $post_data["statement"] = "UPDATE $database.$schema.$table SET
                                    DISPLAY_NAME = '" . str_replace("'", "\'", $vendor->customer_name) . "',
                                    CURRENCY = '" . $currency . "',
                                    EMAIL = '" . str_replace("'", "\'", $vendor->email) . "',
                                    VENDOR_ADDRESS = '" . str_replace("'", "\'", $vendor->address1) . "',
                                    REMOVED = '" . $vendor->is_deleted . "',
                                    UPDATED_AT = '" . $updateDate . "'
                                WHERE VENDOR_ID = '" . $vendor->api_customer_code . "';";
                                } else {
                                    $updateDate = date( 'Y-m-d h:i:s' );//$this->dateFormat($source_platform_name, $vendor->api_updated_at); //format date as Y-m-d H:i:s

                                    $post_data["statement"] = "INSERT into $database.$schema.$table(
                                    VENDOR_ID, DISPLAY_NAME, EMAIL, VENDOR_ADDRESS, CURRENCY, REMOVED, UPDATED_AT
                                ) values (
                                    '" . str_replace("'", "\'", $vendor->api_customer_code) . "',
                                    '" . str_replace("'", "\'", $vendor->customer_name) . "',
                                    '" . str_replace("'", "\'", $vendor->email) . "',
                                    '" . str_replace("'", "\'", $vendor->address1) . "',
                                    '" . $currency . "',
                                    '0',
                                    '" . $updateDate . "'
                                );";
                                }

                                $response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $post_data);

                                if ($response['api_status']) {

                                    if ($vendor->linked_id == 0) {
                                        $vendorLinking = PlatformCustomer::where([
                                            "linked_id" => $vendor->id,
                                            "platform_id" => $this->platformId,
                                        ])
                                            ->first();

                                        if (!$vendorLinking) {
                                            $vendorLinkingNew = PlatformCustomer::find($vendor->id);
                                            $vendorLinking = $vendorLinkingNew->replicate();
                                            $vendorLinking->platform_id = $this->platformId;
                                            $vendorLinking->linked_id = $vendor->id;
                                            $vendorLinking->created_at = Carbon::now();
                                            $vendorLinking->updated_at = Carbon::now();
                                            $vendorLinking->sync_status = PlatformStatus::SYNCED;
                                            $vendorLinking->save();
                                        }

                                        $vendor->linked_id = $vendorLinking->id; // Update the product_sync_status
                                    }
                                    $vendor->sync_status = PlatformStatus::SYNCED;
                                    $vendor->save();

                                    $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'success', $vendor->id, null);
                                } else {
                                    if ($response['api_data'] == "390318") {
                                        continue; // OAuth access token expired
                                    }
                                    $vendor->sync_status = PlatformStatus::FAILED;
                                    $vendor->save();
                                    $return_response = $response['api_data'];
                                    $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $vendor->id, $return_response);
                                }
                            } else {
                                $vendor->sync_status = PlatformStatus::FAILED;
                                $vendor->save();

                                $return_response = "Currency detail is not available.";
                                $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $vendor->id, $return_response);
                            }
                        } else {
                            $vendor->sync_status = PlatformStatus::FAILED;
                            $vendor->save();

                            $return_response = "Name is not available.";
                            $this->snowflakeApi->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sync_object_id, 'failed', $vendor->id, $return_response);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Log::error($user_integration_id . "-- SnowflakeApiController CreateVendor -->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Date Conversion */
    private function dateFormat($platformName, $updateDate)
    {
        if ($platformName == "netsuite") {
            //if change format as Y-m-d by knowing your platform because snowflake acccept only Y-m-d H:i:s format
            $updateDate = Carbon::parse($updateDate)->format('Y-m-d H:i:s');
            // $date = explode('/', $updateDate);
            // $updateDate = $date[2] . '-' . sprintf("%02d", $date[1]) . '-' . $date[0];
        }
        return $updateDate;
    }

    /**
     * 
     */
    public function validateRefreshTokenSendEmail( $user_id, $user_integration_id ){
        $return_response = true;
        try {
            $platform_account = $this->snowflakeApi->getAccountDetails( $user_integration_id ); // get the account information for the integration

            if( $platform_account ){
                $commonController = new CommonController();
                $return_response = $commonController->sendRefreshTokenReSyncNotification( $platform_account->id, $user_integration_id );
            }
        } catch (Exception $e) {
            Log::error( $user_integration_id . "-- SnowflakeApiController validateRefreshTokenSendEmail -->" . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /*
     * Execute Snowflake Event Methods
     * ExecuteSnowflakeEvents= method: MUTATE - event: TICKET - destination_platform_id: snowflake - user_id: 109 - user_integration_id: 597 - is_initial_sync: 0 - user_workflow_rule_id: 1162 - source_platform_id: whmcs - platform_workflow_rule_id: 176 - record_id:
     */
    public function ExecuteSnowflakeEvents($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform = '', $platform_workflow_rule_id = '', $record_id = '')
    {
        // Log::Info( "ExecuteSnowflakeEvents = method: ".$method." - event: ".$event );//." - destination_platform_id: ".$destination_platform_id." - user_id: ".$user_id." - user_integration_id: ".$user_integration_id." - is_initial_sync: ".$is_initial_sync." - user_workflow_rule_id: ".$user_workflow_rule_id." - source_platform: ".$source_platform." - platform_workflow_rule_id: ".$platform_workflow_rule_id." - record_id: ".$record_id );
        $log = "Method: " . $method . ", event: " . $event . ", source_platform: " . $source_platform . ", is_initial_sync: " . $is_initial_sync;
        Storage::append('Snowflake/' . $user_integration_id . '/ExecuteSnowflakeEvents/' . date('d-m-Y') . '.txt', "[" . date('h:i:s') . "] " . $log);
        $source_platform_id = 0;
        if ($source_platform != "") {
            $source_platform_id = $this->snowflakeApi->connectionHelper->getPlatformIdByName($source_platform);
        }

        $response = true;
        if ($method == 'GET' && $event == 'VENDOR') {
            $response = $this->GetVendors($user_id, $user_integration_id);
        } else if ($method == 'GET' && $event == 'PURCHASEORDER') {
            $response = $this->GetOrders($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'PO');
        } else if ($method == 'MUTATE' && $event == 'SALESORDER') {
            $response = $this->createSalesOrder($user_id, $user_integration_id, $source_platform_id, $source_platform, $destination_platform_id, $user_workflow_rule_id, $record_id, $platform_workflow_rule_id);
        } else if ($method == 'MUTATE' && $event == 'PURCHASEORDERRECEIPT') {
            $response = $this->createOrderReceipt($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id, "SKU", "PO");
        } else if ($method == 'MUTATE' && $event == 'PRODUCT') {
            $response = $this->createUpdateProducts($user_id, $user_integration_id, $source_platform_id, $source_platform, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id);
        } else if ($method == 'MUTATE' && $event == 'INVENTORY') {
            $response = $this->updateProductInventory($user_id, $user_integration_id, $source_platform_id, $source_platform, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id);
        } else if ($method == 'GET' && $event == 'WAREHOUSELOCATION') {
            $response = $this->getWareHouseLists($user_id, $user_integration_id);
        } else if ($method == 'GET' && $event == 'WAREHOUSE') {
            // $response = $this->getWareHouse($user_id,$user_integration_id, $source_platform_id, $source_platform);
        } else if ($method == 'GET' && $event == 'CLONEWAREHOUSE') {
            $response = $this->syncWareHouse($user_id, $user_integration_id, $source_platform, $is_initial_sync);
        } else if ($method == 'GET' && $event == 'TRANSFERORDER') {
            $response = $this->GetOrders($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'TO');
        } else if ($method == 'MUTATE' && $event == 'TRANSFERORDERRECEIPT') {
            $response = $this->createOrderReceipt($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id, "SKU", "TO");
        } else if ($method == 'MUTATE' && ($event == 'VENDOR')) {
            $response = $this->CreateVendor($user_id, $user_integration_id, $source_platform_id, $source_platform, $user_workflow_rule_id, $record_id);
        } else if ($method == 'MUTATE' && $event == 'VALIDATEREFRESHTOKENSENDEMAIL' ) {
            $response = $this->validateRefreshTokenSendEmail( $user_id, $user_integration_id );
        } 

        return $response;
    }

    /**
     * Test function
     */
    public function test()
    {
        $user_id = Auth::user()->id;
        $user_integration_id = 702;
        $platform_workflow_rule_id = 700;
        $identity = $this->snowflakeApi->ProductIdentityMapping($user_integration_id, $platform_workflow_rule_id); //Identify Product Uniqueness
        $source_identity = $identity['source_identity']; //Source Identity
        $destination_identity = $identity['destination_identity']; //Destination Identity

        dd($identity);
        $sourceOrder = PlatformOrder::with('platformOrderLine', 'platformCustomer')->where('id', 592733)->first();

        $olines = PlatformOrderLine::where('platform_order_id', $sourceOrder->id)->where('row_type', 'ITEM')->pluck($destination_identity)->toArray();

        $olines = array_unique(array_filter($olines));
        $totalPlines = PlatformProduct::where([
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'is_deleted' => 0
        ])
            ->whereIn($destination_identity, $olines)
            ->pluck($destination_identity)
            ->toArray();

        $totalPlines = array_unique(array_filter($totalPlines));
        Storage::append('Snowflake/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: Total OLines: " . count($olines) . ", Total PLines: " . count($totalPlines));
        if (count($olines) != count($totalPlines)) {
            $diffProducts = array_diff($olines, $totalPlines);

            PlatformProduct::where([
                'user_id' => $user_id,
                'user_integration_id' => $user_integration_id,
                'platform_id' => 7,
            ])
                ->where('product_sync_status', '!=', 'Ready')
                ->whereIn($destination_identity,  $diffProducts)
                ->update([
                    'product_sync_status' => 'Ready'
                ]);

            Storage::append('Snowflake/' . $user_integration_id . '/SalesOrder/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: productMappingArr: " . json_encode($olines) . ", productIds: " . json_encode($totalPlines) . ' Diff Product-' . json_encode($diffProducts));

            $return_response = "Some of order line item not found " . implode(",", $diffProducts);
            $sourceOrder->updated_at = date('Y-m-d H:i:s');
            $sourceOrder->save();
        }
        dd("HERE");
        // $platform_account = $this->snowflakeApi->getAccountDetails($user_integration_id); // get the account information for the integration
        dd($this->GetOrders($user_id, 702, null, 1543, $order_type = 'TO'));
        // if ($platform_account) {
        //     $databaseConfig = $this->snowflakeApi->getDefaultDatabaseObject($user_integration_id);
        //     $post_data = [
        //         "timeout" => 1000,
        //         "resultSetMetaData" => [
        //             "format" => "json"
        //         ],
        //         "warehouse" => $databaseConfig['warehouse'], //$platform_account->region,
        //         "role" => "SYSADMIN",
        //     ];

        //     $database = $databaseConfig['database']; //$platform_account->marketplace_id;
        //     $schema = $databaseConfig['schema']; //$platform_account->custom_domain;

        //     $receive = 4;
        //     $post_data["statement"] = "SELECT RECEIVED FROM $database.$schema.PURCHASE_ORDERS WHERE ID = '241008';";
        //     $rec_response = $this->snowflakeApi->makeAPICall($user_integration_id, $platform_account, $post_data);
        //     if ($rec_response['api_status'] && isset($rec_response['api_data']['data'][0][0])) {
        //         $receive += $rec_response['api_data']['data'][0][0];
        //     }

        //     return $receive;
    }
}
