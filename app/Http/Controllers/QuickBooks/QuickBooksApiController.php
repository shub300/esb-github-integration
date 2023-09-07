<?php

namespace App\Http\Controllers\QuickBooks;

use App\Http\Controllers\QuickBooks\Helper\QuickBooksHelper;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\WorkflowSnippet;
use App\Http\Controllers\QuickBooks\Api\QuickBooksApi;
use App\Models\Enum\PlatformStatus;
use App\Models\EsRegionalTimeZone;
use App\Models\PlatformAccount;
use App\Models\PlatformApiApp;
use App\Models\PlatformCustomer;
use App\Models\PlatformInvoice;
use App\Models\PlatformInvoiceTransaction;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderTransaction;
use App\Models\PlatformProduct;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformProductInventory;
use App\Models\PlatformUrl;
use App\User;
use Carbon\Carbon;
use DB, Session, Validator;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;

class QuickBooksApiController extends QuickBooksApi
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $helper, $mapping, $platformId, $log, $service, $wfsnip;
    public static $myPlatform = 'quickbooks';
    public function __construct()
    {
        $this->mapping = new FieldMappingHelper();
        $this->log = new Logger();
        $this->helper = new ConnectionHelper;
        $this->service = new QuickBooksServiceController;
        $this->wfsnip = new WorkflowSnippet;
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }

    /* Get Account Details */
    private function getPrimaryAccount($user_integration_id)
    {
        $platformId = $this->platformId;

        return $this->getPlatformAccountByUserIntegration($user_integration_id, $platformId);
    }

    /* Display for credentials */
    public function InitiateQboAuth(Request $request)
    {
        if ($request->isMethod('get')) {
            return view("pages.apiauth.auth_quickbooks");
        }
    }

    /* Save credentials */
    public function ConnectQuickBooksOauth(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), ['account_name' => 'required']);

            if ($this->checkHtmlTags($request->all())) {
                return back()->with('error', Lang::get('tags.validate'));
            }

            if ($validator->fails()) {
                return back()->withErrors($validator);
            } else {
                $user_id = Session::get('user_data')['id'];
                // To check whether given account is already in use or not.
                $checkExistingAc = PlatformAccount::where(['user_id' => $user_id, 'platform_id' => $this->platformId, 'account_name' => $request->account_name])->count();
                if ($checkExistingAc) {
                    return back()->with('error', 'Given account nick name is already in use, Try with other account nick name.');
                }

                if (!$checkExistingAc) {
                    $env_type = isset($request->env_type) ? "p" : "s";
                    $type = ($env_type == "p") ? "production" : "sandbox";
                    $app = PlatformApiApp::select('client_id', 'client_secret')->where(['platform_id' => $this->platformId, 'env_type' => $type])->first();
                    if ($app) {
                        $params = [
                            'client_id' => $this->encrypt_decrypt($app->client_id, 'decrypt'),
                            'response_type' => 'code',
                            'redirect_uri' => $this->makeUrlHttpsForProd(url('/RedirectHandlerQuickBooks')),
                            'scope' => "com.intuit.quickbooks.accounting com.intuit.quickbooks.payment openid profile email phone address",
                            'state' => $user_id . '|' . $request->account_name . '|' . $env_type,
                            'realmId' => uniqid()
                        ];
                        return redirect(\Config::get('apiconfig.QuickBooksOauthUrl') . "?" . http_build_query($params));
                    } else {
                        return back()->with('error', 'App configuration has been not found.');
                    }
                } else {
                    return back()->with('error', 'Authentication Error.');
                }
            }
        } catch (\Exception $e) {
            \Log::error(' - QuickBooksApiController - ConnectQuickBooksOauth - ' . $e->getLine() . " -> " . $e->getMessage());
        }
    }

    /* Get Token */
    public function RedirectHandlerQuickBooks(Request $request)
    {
        try {
            if (isset($request->code) && isset($request->realmId)) {
                $state = $request->state;
                $state_arr = explode('|', $state);
                if (isset($state_arr[0]) && isset($state_arr[1])) {
                    // Valid request
                    $user_id = $state_arr[0];
                    $accountName = $state_arr[1]; // Account name
                    $env_type = $state_arr[2]; // environment type
                    $companyName = $request->realmId;
                    $type = ($env_type == "p") ? "production" : "sandbox";
                    $app = PlatformApiApp::select('client_id', 'client_secret')->where(['platform_id' => $this->platformId, 'env_type' => $type])->first();
                    if ($app) {
                        $code = $request->code;
                        $client_id = $this->encrypt_decrypt($app->client_id, 'decrypt');
                        $client_secret = $this->encrypt_decrypt($app->client_secret, 'decrypt');
                        $redirect_url = $this->makeUrlHttpsForProd(url('/RedirectHandlerQuickBooks'));
                        if ($client_id && $client_secret) {
                            $curl_post_data = ([
                                'code' => $code,
                                'grant_type' => 'authorization_code',
                                'redirect_uri' => $redirect_url,
                            ]);
                            $authorization = base64_encode($client_id . ':' . $client_secret);
                            $service_url = \Config::get('apiconfig.QuickBooksOauthTokenUrl');
                            $headers = [
                                'Content-Type' => 'application/x-www-form-urlencoded',
                                'Accept-Encoding' => 'gzip',
                                "Authorization" => "Basic {$authorization}",
                                "Accept" => "application/json"
                            ];
                            $server_response = $this->makeRequest('post', $service_url, $curl_post_data, $headers, "http");
                            $response = $this->getResponse($server_response);
                            if ($response['status_code'] == 200) {
                                if (isset($response['body']['access_token'])) {
                                    $OauthData = [
                                        'access_token' => $this->encrypt_decrypt($response['body']['access_token']),
                                        'refresh_token' => $this->encrypt_decrypt($response['body']['refresh_token']),
                                        'token_type' => $response['body']['token_type'],
                                        'expires_in' => $response['body']['expires_in'],
                                        'account_name' => $accountName,
                                        'user_id' => $user_id,
                                        'marketplace_id' => $companyName,
                                        'app_id' => $this->encrypt_decrypt($client_id),
                                        'app_secret' => $this->encrypt_decrypt($client_secret),
                                        'platform_id' => $this->platformId,
                                        'token_refresh_time' => time(),
                                        'env_type' => $type,
                                    ];
                                    PlatformAccount::updateOrCreate(['user_id' => $user_id, 'platform_id' => $this->platformId, 'account_name' => $accountName], $OauthData);
                                } else { // When Token not found
                                    if (isset($decode_val['error_description'])) {
                                        $error = $decode_val['error_description'];
                                    } else {
                                        $error = "Something went wrong in your account";
                                    }
                                    echo '<script>alert("' . $error . '");window.close();</script>';
                                }

                                echo '<script>window.close();</script>';
                            } else {
                                echo '<script>alert("Authentication Error");window.close();</script>';
                            }
                        }
                    }
                } else {
                    echo '<script>alert("Authentication Error");window.close();</script>';
                }
            } else {
                echo '<script>alert("Authentication Error");window.close();</script>';
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksApiController - RedirectHandlerQuickBooks - ' . $e->getLine() . " -> " . $e->getMessage());
        }
    }

    /* get new access token */
    public function refreshToken($ID)
    {
        date_default_timezone_set('UTC');
        $return_response = true;
        $accountReturn = [];
        try {
            $account = PlatformAccount::find($ID);
            if ($account) {
                $app = PlatformApiApp::select('client_id', 'client_secret')->where(['platform_id' => $this->platformId, 'env_type' => $account->env_type])->first();
                if ($app) {
                    $client_id = $this->encrypt_decrypt($app->client_id, 'decrypt');
                    $client_secret = $this->encrypt_decrypt($app->client_secret, 'decrypt');
                    if ($client_id && $client_secret) {
                        $authorization = base64_encode($client_id . ':' . $client_secret);
                        $service_url = \Config::get('apiconfig.QuickBooksOauthTokenUrl'); // token service url
                        $headers = [
                            'Content-Type' => 'application/x-www-form-urlencoded',
                            'Accept-Encoding' => 'gzip',
                            "Authorization" => "Basic {$authorization}",
                            "Accept" => "application/json"
                        ];
                        $payload = [
                            'refresh_token' => $this->encrypt_decrypt($account->refresh_token, 'decrypt'),
                            'grant_type' => 'refresh_token',
                        ]; //prepare refresh token payload
                        $server_response = $this->makeRequest('POST', $service_url, $payload, $headers, 'http');
                        $response = $this->getResponse($server_response);
                        if ($response['status_code'] == 200) { // if we get token
                            if (isset($response['body']['access_token'])) {
                                $accountReturn = [
                                    'access_token' => $this->encrypt_decrypt($response['body']['access_token']),
                                    'refresh_token' => $this->encrypt_decrypt($response['body']['refresh_token']),
                                    'refresh_token_new' => $response['body']['refresh_token'],
                                    'access_token_new' => $response['body']['access_token'],
                                    'refresh_token_old' => $this->encrypt_decrypt($account->refresh_token, 'decrypt'),
                                    'app_secret' => $app->client_secret,
                                    'app_id' => $app->client_id,
                                ]; // send data to re call api when token expired
                                $account->access_token = $this->encrypt_decrypt($response['body']['access_token']);
                                $account->refresh_token = $this->encrypt_decrypt($response['body']['refresh_token']);
                                $account->token_type = $response['body']['token_type'];
                                $account->expires_in = $response['body']['expires_in'];
                                $account->token_refresh_time = time();
                                $account->save();
                                $return_response = true;
                            }
                        } else {
                            $error = $this->handleResponseError($response);
                            $return_response = $error ? $error : "Authentication error from API";
                        }
                    } else {
                        $return_response = "App configuration has been not found: client id and secret missing";
                    }
                } else {
                    $return_response = "App configuration has been not found";
                }
            }
        } catch (\Exception $e) {
            \Log::error($ID . ' - QuickBooksApiController - refreshTokens - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return ['response' => $return_response, 'account' => $accountReturn];
    }

    /* Get Products */
    public function getProducts($user_id = null, $user_integration_id = null, $is_initial_sync = 0, $account = null)
    {
        $return_response = true;
        try {
            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {

                if ($is_initial_sync) { // get products by chunks in loop when initial sync=1
                    $x = 1;
                    $loopBreaker = true;
                    while ($loopBreaker) {
                        if ($x <= 2) {
                            $pageNo = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $user_integration_id], ['platform_id', '=', $this->platformId], ['url_name', '=', 'products']])->first();
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
                                //
                                $arguments = [
                                    "query" => "select * from Item orderBy Id startPosition {$page} maxResults {$pageLimit}",
                                ];


                                $apicall = $this->productList($account, $arguments);

                                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                    $products = isset($apicall['body']['QueryResponse']['Item']) ? $apicall['body']['QueryResponse']['Item'] : [];

                                    if (count($products) > 0) {
                                        foreach ($products as $key => $value) {
                                            $this->service->prepareProductData($value, $user_id, $user_integration_id, $is_initial_sync);
                                        }

                                        if (isset($pageNo->url)) {
                                            $pageNo->url = $page + $pageLimit;
                                            $pageNo->status = 0;
                                            $pageNo->save();
                                        } else {
                                            PlatformUrl::insert([
                                                'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,
                                                'url' => $page + $pageLimit,
                                                'url_name' => 'products',
                                                'status' => 0
                                            ]);
                                        }
                                        $return_response = "Page-{$pageCounter} data processed";
                                    } else {
                                        if (isset($pageNo->url)) {
                                            $pageNo->url = 0;
                                            $pageNo->status = 1;
                                            $pageNo->save();
                                        }
                                        $return_response = true;
                                    }
                                } else {
                                    $loopBreaker = false;
                                    $error = $this->handleResponseError($apicall);
                                    $return_response = $error ? $error : "API Error";
                                }
                            } else {
                                $loopBreaker = false;
                                $return_response = true;
                            }

                            $x++;
                        } else {
                            $loopBreaker = false;
                        }
                    }
                } else {
                    //if initial sync is set =0
                    $page = 1;
                    $pageLimit = 100;

                    $lastDate = PlatformProduct::select('api_updated_at')->where([
                        'user_integration_id' => $user_integration_id,
                        'platform_id' => $this->platformId,
                    ])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();

                    if (isset($lastDate->api_updated_at)) {
                        $modifiedDateFrom = $lastDate->api_updated_at;
                    } else {
                        $modifiedDateFrom = Carbon::now()->subMinutes(60)->format('Y-m-d\TH:i:s\Z'); //minus 60 from current time to get latest data
                    }
                    $arguments = [
                        "query" => "select * from Item where MetaData.LastUpdatedTime>'{$modifiedDateFrom}' orderby Id startPosition {$page} maxResults {$pageLimit}",

                    ];

                    $apicall = $this->productList($account, $arguments);

                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $products = isset($apicall['body']['QueryResponse']['Item']) ? $apicall['body']['QueryResponse']['Item'] : [];
                        if (count($products) > 0) {
                            foreach ($products as $key => $value) {
                                $this->service->prepareProductData($value, $user_id, $user_integration_id, $is_initial_sync);
                            }
                            $return_response = true;
                        } else {
                            $return_response = true;
                        }
                    } else {
                        $error = $this->handleResponseError($apicall);
                        $return_response = $error ? $error : "API Error";
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksApiController - getProducts - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Get Vendors */
    public function getVendors($user_id = null, $user_integration_id = null, $is_initial_sync = 0, $account = null)
    {
        $return_response = true;
        try {
            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                if ($is_initial_sync) { // get vendors by chunks in loop when initial sync=1
                    $x = 1;
                    $loopBreaker = true;
                    while ($loopBreaker) {
                        if ($x <= 2) {
                            $pageNo = PlatformUrl::select('url', 'id', 'status')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'vendors'])->first();
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
                                $arguments = ["query" => "select * from Vendor orderBy Id startPosition {$page} maxResults {$pageLimit}"];

                                $apicall = $this->vendorList($account, $arguments);

                                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                    $vendors = isset($apicall['body']['QueryResponse']['Vendor']) ? $apicall['body']['QueryResponse']['Vendor'] : [];

                                    if (count($vendors) > 0) {
                                        foreach ($vendors as $key => $value) {
                                            $this->service->prepareVendorData($value, $user_id, $user_integration_id, $is_initial_sync);
                                        }

                                        if (isset($pageNo->url)) {
                                            $pageNo->url = $page + $pageLimit;
                                            $pageNo->status = 0;
                                            $pageNo->save();
                                        } else {
                                            PlatformUrl::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url' => $page + $pageLimit, 'url_name' => 'vendors', 'status' => 0]);
                                        }
                                        $return_response = "Page-{$pageCounter} data processed";
                                    } else {
                                        if (isset($pageNo->url)) {
                                            $pageNo->url = 0;
                                            $pageNo->status = 1;
                                            $pageNo->save();
                                        }
                                        $return_response = true;
                                    }
                                } else {
                                    $loopBreaker = false;
                                    $error = $this->handleResponseError($apicall);
                                    $return_response = $error ? $error : "API Error";
                                }
                            } else {
                                $loopBreaker = false;
                                $return_response = true;
                            }

                            $x++;
                        } else {
                            $loopBreaker = false;
                        }
                    }
                } else {
                    //if initial sync is set =0
                    $page = 1;
                    $pageLimit = 100;

                    $lastDate = PlatformCustomer::select('api_updated_at')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'type' => "Vendor"])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();
                    if (isset($lastDate->api_updated_at)) {
                        $modifiedDateFrom = $lastDate->api_updated_at;
                    } else {
                        $modifiedDateFrom = Carbon::now()->subMinutes(60)->format('Y-m-d\TH:i:s\Z'); //minus 60 from current time to get latest data
                    }

                    $arguments = ["query" => "select * from Vendor where MetaData.LastUpdatedTime>'{$modifiedDateFrom}' startPosition {$page} maxResults {$pageLimit}"];

                    $apicall = $this->vendorList($account, $arguments);
                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $vendors = isset($apicall['body']['QueryResponse']['Vendor']) ? $apicall['body']['QueryResponse']['Vendor'] : [];

                        if (count($vendors) > 0) {
                            foreach ($vendors as $key => $value) {
                                $this->service->prepareVendorData($value, $user_id, $user_integration_id, $is_initial_sync);
                            }
                            $return_response = true;
                        } else {
                            $return_response = true;
                        }
                    } else {
                        $error = $this->handleResponseError($apicall);
                        $return_response = $error ? $error : "API Error";
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> QuickBooksApiController -> getVendors -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Get Customers */
    public function getCustomers($user_id, $user_integration_id, $is_initial_sync)
    {
        $return_response = true;
        try {
            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                if ($is_initial_sync) { // get customers by chunks in loop when initial sync=1
                    $x = 1;
                    $loopBreaker = true;
                    while ($loopBreaker) {
                        if ($x <= 2) {
                            $platform_url = PlatformUrl::select('id', 'url', 'status')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'customers'])->first();
                            if (isset($platform_url->url)) {
                                if ($platform_url->url == 0 && $platform_url->status == 1) {
                                    $loopBreaker = false;
                                } else {
                                    $page = $platform_url->url + 1;
                                }
                            } else {
                                $page = 1;
                            }

                            if ($loopBreaker) {
                                $pageCounter = $page;
                                $pageLimit = 100;
                                $arguments = ["query" => "select * from Customer orderBy Id startPosition {$page} maxResults {$pageLimit}"];

                                $result = $this->customerList($account, $arguments);
                                if (isset($result['status_code']) && $result['status_code'] == 200) {
                                    $customers = isset($result['body']['QueryResponse']['Customer']) ? $result['body']['QueryResponse']['Customer'] : [];
                                    if (count($customers) > 0) {
                                        foreach ($customers as $customer) {
                                            $this->service->prepareCustomerData($customer, $user_id, $user_integration_id, $is_initial_sync);
                                        }

                                        if (isset($platform_url->url)) {
                                            $platform_url->url = $page + $pageLimit;
                                            $platform_url->status = 0;
                                            $platform_url->save();
                                        } else {
                                            PlatformUrl::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url' => $page + $pageLimit, 'url_name' => 'customers', 'status' => 0]);
                                        }
                                        $return_response = "Page-{$pageCounter} data processed";
                                    } else {
                                        if (isset($platform_url->url)) {
                                            $platform_url->url = 0;
                                            $platform_url->status = 1;
                                            $platform_url->save();
                                        }
                                        $return_response = true;
                                    }
                                } else {
                                    $loopBreaker = false;
                                    $error = $this->handleResponseError($result);
                                    $return_response = $error ? $error : "API Error";
                                }
                            } else {
                                $loopBreaker = false;
                                $return_response = true;
                            }

                            $x++;
                        } else {
                            $loopBreaker = false;
                        }
                    }
                } else {
                    //if initial sync is set = 0
                    $page = 1;
                    $pageLimit = 100;

                    $lastDate = PlatformCustomer::select('api_updated_at')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'type' => "Customer"])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();
                    if (isset($lastDate->api_updated_at)) {
                        $modifiedDateFrom = $lastDate->api_updated_at;
                    } else {
                        $modifiedDateFrom = Carbon::now()->subMinutes(60)->format('Y-m-d\TH:i:s\Z'); //minus 60 from current time to get latest data
                    }

                    $arguments = ["query" => "select * from Customer where MetaData.LastUpdatedTime>'{$modifiedDateFrom}' startPosition {$page} maxResults {$pageLimit}"];

                    $result = $this->customerList($account, $arguments);
                    if (isset($result['status_code']) && $result['status_code'] == 200) {
                        $customers = isset($result['body']['QueryResponse']['Customer']) ? $result['body']['QueryResponse']['Customer'] : [];
                        if (count($customers) > 0) {
                            foreach ($customers as $customer) {
                                $this->service->prepareCustomerData($customer, $user_id, $user_integration_id, $is_initial_sync);
                            }
                            $return_response = true;
                        } else {
                            $return_response = true;
                        }
                    } else {
                        $error = $this->handleResponseError($result);
                        $return_response = $error ? $error : "API Error";
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> QuickBooksApiController -> getCustomers -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Mutate purchase orders from source platform */
    public function syncPurchaseOrder($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $platform_workflow_rule_id = null, $source_platform_name = null, $sync_status = "Ready", $record_id = NULL, $account = null)
    {
        $return_response = true;
        try {
            $recordExist = 0;
            $limit = 20;
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($user_integration_id);
            }
            $SourcePlatformId = $this->helper->getPlatformIdByName($source_platform_name);
            $sourceAccount = $this->getPlatformAccountByUserIntegration($user_integration_id, $SourcePlatformId, ['id']);

            if ($account && $sourceAccount) {

                $query = PlatformOrder::with(['platformOrderLine'])->select('id', 'user_id', 'platform_id', 'user_integration_id', 'user_workflow_rule_id', 'platform_customer_id', 'order_type', 'api_order_id', 'order_number', 'sync_status', 'is_voided', 'is_deleted', 'linked_id', 'order_updated_at', 'updated_at', 'warehouse_id', 'order_date', 'api_order_reference', 'allow_check', 'linked_api_order_id', 'shipping_total', 'notes', 'file_name');
                if ($record_id) {
                    $query->where('id', $record_id);
                } else {
                    $query->where([
                        'platform_id' => $SourcePlatformId,
                        'user_integration_id' => $user_integration_id,
                        'sync_status' => $sync_status,
                        'order_type' => "PO",
                    ]);
                }
                $list = $query->orderBy('updated_at', 'ASC')->take($limit)->get();

                if (!empty($list) && count($list) > 0) {
                    //$shipping_method_object_id = $this->helper->getObjectId('shipping_method');
                    $purchase_object_id = $this->helper->getObjectId('purchase_order');
                    $recordExist = 1;
                    $account_number_query = NULL;
                    $account_number_query = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_account_number", ['api_id'], "default");
                    if ($account_number_query) {
                        $account_number = $account_number_query->api_id;
                    }

                    $productIdentity = $this->service->productIdentityMapping($user_integration_id, $platform_workflow_rule_id);
                    $source_identity = $productIdentity['source_identity'];
                    $destination_identity = $productIdentity['destination_identity'];
                    $shippingAccountId = $otherCostAccountId = $taxCode = $discountAccountId = NULL;
                    /* Shipping Cost Account ID */
                    $shippingAccount = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_shippingcost_account", ['api_id'], "default");
                    if ($shippingAccount) {
                        $shippingAccountId = $shippingAccount->api_id;
                    }
                    /* Other Cost Account ID */
                    $otherCostAccount = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_othercost_account", ['api_id'], "default");
                    if ($otherCostAccount) {
                        $otherCostAccountId = $otherCostAccount->api_id;
                    }

                    /* discountAccount Cost Account ID */
                    $discountAccount = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_discountcost_account", ['api_id'], "default");
                    if ($discountAccount) {
                        $discountAccountId = $discountAccount->api_id;
                    }


                    foreach ($list as $value) {
                        /* Find Order Primary Key */
                        if ($account_number && !$value->linked_id) {
                            $order_primary_id = isset($value->id) ? $value->id : NULL;
                            $orderLines = isset($value->platformOrderLine) ? $value->platformOrderLine : null;
                            if ($orderLines) {
                                $platform_customer_id = $value->platform_customer_id ? $value->platform_customer_id : null;
                                $warehouse_id = $value->warehouse_id ? $value->warehouse_id : null;
                                $shippingAddress = $this->service->findShippingAddressByWarehouseID($warehouse_id); //find shipping address

                                $vendorId = $this->service->findVendor($platform_customer_id, $account); //find or create vendor and vendor address

                                if (is_numeric($vendorId['vendorId'])) {
                                    $prepareOrderLine = $this->service->prepareOrderLine($value, $user_id, $user_integration_id, $this->platformId, $source_identity, $shippingAccountId, $otherCostAccountId, $discountAccountId, $taxCode, $account);
                                    if (!$prepareOrderLine['productNotFound']) {
                                        $payload = [
                                            "TotalAmt" => $prepareOrderLine['total_amount'],
                                            "APAccountRef" => [
                                                "value" => $account_number
                                            ],
                                            "VendorRef" => [
                                                "value" => $vendorId['vendorId']
                                            ],
                                            "Line" => $prepareOrderLine['items'],


                                        ];
                                        if ($vendorId['vendorAddress']) { // if vendor address available
                                            $payload["VendorAddr"] = $vendorId['vendorAddress'];
                                        }
                                        if ($vendorId['email']) { // if vendor email available
                                            $payload["POEmail"]["Address"] = $vendorId['email'];
                                        }

                                        if (is_array($shippingAddress) && !empty($shippingAddress)) { // if warehouse address available
                                            $payload["ShipAddr"] = $shippingAddress;
                                        }
                                        if ($value['notes']) { // if notes available send to private notes
                                            $payload["PrivateNote"] = strlen($value['notes']) > 4000 ? substr($value['notes'], 0, 4000) : $value['notes'];
                                        }
                                        if ($value['file_name']) { // if message to vendor available send to Memo
                                            $payload["Memo"] = strlen($value['file_name']) > 4000 ? substr($value['file_name'], 0, 4000) : $value['file_name'];
                                        }
                                        /* Field Mapping */
                                        $field_mapping = $this->mapping->GetMappedFieldRecord($purchase_object_id, $user_integration_id, NULL, "source_row_id", NULL, $value->id);
                                        if ($field_mapping) {
                                            $casting = $value->toArray();
                                            $payterms = isset($value->order_extra_information) ? $value->order_extra_information->toArray() : null;

                                            foreach ($field_mapping as $mapping) {

                                                if (isset($mapping['destination_field_name'])) { //This will add all mapping values to api

                                                    if ($mapping['source_db_field_name'] == "api_order_reference") {

                                                        $payload["CustomField"][] = [
                                                            "DefinitionId" => $mapping['destination_db_field_name'],
                                                            "Type" => "StringType",
                                                            "StringValue" => isset($casting[$mapping['source_db_field_name']]) ? $casting[$mapping['source_db_field_name']] : null
                                                        ];
                                                    }


                                                    if ($mapping['source_db_field_name'] == "pay_terms") {
                                                        if ($payterms) {
                                                            $payload["CustomField"][] = [
                                                                "DefinitionId" => $mapping['destination_db_field_name'],
                                                                "Type" => "StringType",
                                                                "StringValue" => isset($payterms[$mapping['source_db_field_name']]) ? $payterms[$mapping['source_db_field_name']] : null
                                                            ];
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        \Storage::disk('local')->append(date('d-m-Y') . 'qb.txt', json_encode($payload));
                                        $apicall = $this->APICALL($account, "POST", "purchaseorder", ['minorversion' => 65], $payload, 'v3');

                                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                            $order = $apicall['body']['PurchaseOrder'];
                                            if (isset($order['Id'])) {
                                                /* Insert order details */
                                                $orderLinkedId = $this->service->saveOrderDetails([
                                                    'user_id' => $user_id,
                                                    'platform_id' => $this->platformId,
                                                    'user_integration_id' => $user_integration_id,
                                                    'order_type' => "PO",
                                                    'api_order_id' => $order['Id'],
                                                    'order_date' => date("Y-m-d H:i:s", strtotime($order['MetaData']['CreateTime'])),
                                                    'order_number' => $order['DocNumber'],
                                                    'sync_status' => 'Pending',
                                                    'linked_id' => $order_primary_id,
                                                    'shipment_status' => "Pending",
                                                    'order_updated_at' => date("Y-m-d H:i:s"),
                                                ]);

                                                $value->sync_status = 'Synced';
                                                $value->order_updated_at = date("Y-m-d H:i:s");
                                                $value->linked_id = $orderLinkedId;
                                                $value->save();
                                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $purchase_object_id, 'success', $order_primary_id, null);
                                            }
                                        } else {
                                            $error = $this->handleResponseError($apicall);
                                            $return_response = $error ? $error : "API Error";
                                            $value->sync_status = 'Failed';
                                            $value->order_updated_at = date("Y-m-d H:i:s");
                                            $value->save();

                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $purchase_object_id, 'failed', $order_primary_id, $return_response);
                                        }
                                    } else {
                                        $value->sync_status = 'Failed';
                                        $value->order_updated_at = date("Y-m-d H:i:s");
                                        $value->save();
                                        $return_response = "Some of line items are not found";
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $purchase_object_id, 'failed', $order_primary_id, $return_response);
                                    }
                                } else {
                                    $value->sync_status = 'Failed';
                                    $value->order_updated_at = date("Y-m-d H:i:s");
                                    $value->save();
                                    $return_response = $vendorId['vendorId'];
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $purchase_object_id, 'failed', $order_primary_id, $return_response);
                                }
                            } else {
                                $value->sync_status = 'Failed';
                                $value->order_updated_at = date("Y-m-d H:i:s");
                                $value->save();
                                $return_response = "No line items found for order";
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $purchase_object_id, 'failed', $order_primary_id, $return_response);
                            }
                        }
                    }
                }
                if ($recordExist == 0) {
                    $return_response = "Record not exist";
                }
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksApiController - syncPurchaseOrder - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    public function syncPurchaseOrderTest($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $platform_workflow_rule_id = null, $source_platform_name = null, $sync_status = "Ready", $record_id = NULL, $account = null)
    {
        $return_response = true;
        try {
            $recordExist = 0;
            $limit = 20;
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($user_integration_id);
            }
            $SourcePlatformId = $this->helper->getPlatformIdByName($source_platform_name);

            if ($account && $SourcePlatformId) {

                $query = PlatformOrder::with(['platformOrderLine'])->select('id', 'user_id', 'platform_id', 'user_integration_id', 'user_workflow_rule_id', 'platform_customer_id', 'order_type', 'api_order_id', 'order_number', 'sync_status', 'is_voided', 'is_deleted', 'linked_id', 'order_updated_at', 'updated_at', 'warehouse_id', 'order_date', 'api_order_reference', 'allow_check', 'linked_api_order_id', 'shipping_total', 'notes', 'file_name');
                if ($record_id) {
                    $query->where('id', $record_id);
                } else {
                    $query->where([
                        'platform_id' => $SourcePlatformId,
                        'user_integration_id' => $user_integration_id,
                        'sync_status' => $sync_status,
                        'order_type' => "PO",
                    ]);
                }
                $list = $query->orderBy('updated_at', 'ASC')->take($limit)->get();

                if (!empty($list) && count($list) > 0) {
                    //$shipping_method_object_id = $this->helper->getObjectId('shipping_method');
                    $purchase_object_id = $this->helper->getObjectId('purchase_order');
                    $recordExist = 1;
                    $account_number_query = NULL;
                    $account_number_query = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_account_number", ['api_id'], "default");
                    if ($account_number_query) {
                        $account_number = $account_number_query->api_id;
                    }

                    $productIdentity = $this->service->productIdentityMapping($user_integration_id, $platform_workflow_rule_id);
                    $source_identity = $productIdentity['source_identity'];
                    $destination_identity = $productIdentity['destination_identity'];
                    $shippingAccountId = $otherCostAccountId = $taxCode = $discountAccountId = NULL;
                    /* Shipping Cost Account ID */
                    $shippingAccount = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_shippingcost_account", ['api_id'], "default");
                    if ($shippingAccount) {
                        $shippingAccountId = $shippingAccount->api_id;
                    }
                    /* Other Cost Account ID */
                    $otherCostAccount = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_othercost_account", ['api_id'], "default");
                    if ($otherCostAccount) {
                        $otherCostAccountId = $otherCostAccount->api_id;
                    }

                    /* discountAccount Cost Account ID */
                    $discountAccount = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_discountcost_account", ['api_id'], "default");
                    if ($discountAccount) {
                        $discountAccountId = $discountAccount->api_id;
                    }


                    foreach ($list as $value) {
                        /* Find Order Primary Key */
                        if ($account_number && !$value->linked_id) {
                            $order_primary_id = isset($value->id) ? $value->id : NULL;
                            $orderLines = isset($value->platformOrderLine) ? $value->platformOrderLine : null;
                            if ($orderLines) {
                                $platform_customer_id = $value->platform_customer_id ? $value->platform_customer_id : null;
                                $warehouse_id = $value->warehouse_id ? $value->warehouse_id : null;
                                $shippingAddress = $this->service->findShippingAddressByWarehouseID($warehouse_id); //find shipping address

                                $vendorId = $this->service->findVendor($platform_customer_id, $account); //find or create vendor and vendor address

                                if (is_numeric($vendorId['vendorId'])) {
                                    $prepareOrderLine = $this->service->prepareOrderLineTest($value, $user_id, $user_integration_id, $SourcePlatformId, $source_platform_name, $source_identity, $destination_identity, $shippingAccountId, $otherCostAccountId, $discountAccountId, $taxCode, $account);

                                    if (!$prepareOrderLine['productNotFound']) {
                                        $payload = [
                                            "TotalAmt" => $prepareOrderLine['total_amount'],
                                            "APAccountRef" => [
                                                "value" => $account_number
                                            ],
                                            "VendorRef" => [
                                                "value" => $vendorId['vendorId']
                                            ],
                                            "Line" => $prepareOrderLine['items'],
                                        ];
                                        if ($vendorId['vendorAddress']) { // if vendor address available
                                            $payload["VendorAddr"] = $vendorId['vendorAddress'];
                                        }
                                        if ($vendorId['email']) { // if vendor email available
                                            $payload["POEmail"]["Address"] = $vendorId['email'];
                                        }

                                        if (is_array($shippingAddress) && !empty($shippingAddress)) { // if warehouse address available
                                            $payload["ShipAddr"] = $shippingAddress;
                                        }
                                        if ($value['notes']) { // if notes available send to private notes
                                            $payload["PrivateNote"] = strlen($value['notes']) > 4000 ? substr($value['notes'], 0, 4000) : $value['notes'];
                                        }
                                        if ($value['file_name']) { // if message to vendor available send to Memo
                                            $payload["Memo"] = strlen($value['file_name']) > 4000 ? substr($value['file_name'], 0, 4000) : $value['file_name'];
                                        }
                                        /* Field Mapping */
                                        $field_mapping = $this->mapping->GetMappedFieldRecord($purchase_object_id, $user_integration_id, NULL, "source_row_id", NULL, $value->id);
                                        if ($field_mapping) {
                                            $casting = $value->toArray();
                                            $payterms = isset($value->order_extra_information) ? $value->order_extra_information->toArray() : null;

                                            foreach ($field_mapping as $mapping) {

                                                if (isset($mapping['destination_field_name'])) { //This will add all mapping values to api

                                                    if ($mapping['source_db_field_name'] == "api_order_reference") {

                                                        $payload["CustomField"][] = [
                                                            "DefinitionId" => $mapping['destination_db_field_name'],
                                                            "Type" => "StringType",
                                                            "StringValue" => isset($casting[$mapping['source_db_field_name']]) ? $casting[$mapping['source_db_field_name']] : null
                                                        ];
                                                    }


                                                    if ($mapping['source_db_field_name'] == "pay_terms") {
                                                        if ($payterms) {
                                                            $payload["CustomField"][] = [
                                                                "DefinitionId" => $mapping['destination_db_field_name'],
                                                                "Type" => "StringType",
                                                                "StringValue" => isset($payterms[$mapping['source_db_field_name']]) ? $payterms[$mapping['source_db_field_name']] : null
                                                            ];
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        \Storage::disk('local')->append(date('d-m-Y') . 'qb.txt', json_encode($payload));
                                        $apicall = $this->APICALL($account, "POST", "purchaseorder", ['minorversion' => 65], $payload, 'v3');

                                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                            $order = $apicall['body']['PurchaseOrder'];
                                            if (isset($order['Id'])) {
                                                /* Insert order details */
                                                $orderLinkedId = $this->service->saveOrderDetails([
                                                    'user_id' => $user_id,
                                                    'platform_id' => $this->platformId,
                                                    'user_integration_id' => $user_integration_id,
                                                    'order_type' => "PO",
                                                    'api_order_id' => $order['Id'],
                                                    'order_date' => date("Y-m-d H:i:s", strtotime($order['MetaData']['CreateTime'])),
                                                    'order_number' => $order['DocNumber'],
                                                    'sync_status' => 'Pending',
                                                    'linked_id' => $order_primary_id,
                                                    'shipment_status' => "Pending",
                                                    'order_updated_at' => date("Y-m-d H:i:s"),
                                                ]);

                                                $value->sync_status = 'Synced';
                                                $value->order_updated_at = date("Y-m-d H:i:s");
                                                $value->linked_id = $orderLinkedId;
                                                $value->save();
                                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $purchase_object_id, 'success', $order_primary_id, null);
                                            }
                                        } else {
                                            $error = $this->handleResponseError($apicall);
                                            $return_response = $error ? $error : "API Error";
                                            $value->sync_status = 'Failed';
                                            $value->order_updated_at = date("Y-m-d H:i:s");
                                            $value->save();

                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $purchase_object_id, 'failed', $order_primary_id, $return_response);
                                        }
                                    } else {
                                        $value->sync_status = 'Failed';
                                        $value->order_updated_at = date("Y-m-d H:i:s");
                                        $value->save();
                                        $return_response = "Some of line items are not found";
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $purchase_object_id, 'failed', $order_primary_id, $return_response);
                                    }
                                } else {
                                    $value->sync_status = 'Failed';
                                    $value->order_updated_at = date("Y-m-d H:i:s");
                                    $value->save();
                                    $return_response = $vendorId['vendorId'];
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $purchase_object_id, 'failed', $order_primary_id, $return_response);
                                }
                            } else {
                                $value->sync_status = 'Failed';
                                $value->order_updated_at = date("Y-m-d H:i:s");
                                $value->save();
                                $return_response = "No line items found for order";
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $purchase_object_id, 'failed', $order_primary_id, $return_response);
                            }
                        }
                    }
                }
                if ($recordExist == 0) {
                    $return_response = "Record not exist";
                }
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksApiController - syncPurchaseOrder - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Mutate sales orders shipment from source platform */
    public function syncOrderShipment($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $platform_workflow_rule_id = null, $source_platform_name = null, $sync_status = "Ready", $record_id = NULL, $account = null)
    {
        $return_response = true;
        try {
            $recordExist = 0;
            $limit = 20;
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($user_integration_id);
            }
            $SourcePlatformId = $this->helper->getPlatformIdByName($source_platform_name);
            $sourceAccount = $this->getPlatformAccountByUserIntegration($user_integration_id, $SourcePlatformId, ['id']);
            $sales_order_object_id = $this->helper->getObjectId('sales_order');

            if ($account && $sourceAccount && $sales_order_object_id) {
                $journal_channel_ids = $this->mapping->getMappedDataByName($user_integration_id, NULL, "journal_channel_filter", ['api_id'], 'regular', null, 'multiple');

                $query = PlatformOrder::leftJoin('platform_order_additional_information', 'platform_order.id', '=', 'platform_order_additional_information.platform_order_id')
                    ->select('platform_order.id', 'platform_order.user_id', 'platform_order.platform_id', 'platform_order.user_integration_id', 'platform_order.user_workflow_rule_id', 'platform_order.platform_customer_id', 'platform_order.order_type', 'platform_order.api_order_id', 'platform_order.order_number', 'platform_order.sync_status', 'platform_order.is_voided', 'platform_order.is_deleted', 'platform_order.linked_id', 'platform_order.order_updated_at', 'platform_order.updated_at', 'platform_order.warehouse_id', 'platform_order.order_date', 'platform_order.api_order_reference', 'platform_order.allow_check', 'platform_order.linked_api_order_id', 'platform_order.shipping_total', 'platform_order.notes', 'platform_order.file_name', 'platform_order.order_number', 'platform_order.customer_email', 'platform_order.shipping_method', 'platform_order.total_discount', 'platform_order_additional_information.api_channel_id')
                    ->where(function ($query) use ($journal_channel_ids) {
                        if (is_array($journal_channel_ids) && count($journal_channel_ids)) {
                            $query->whereNotIn('platform_order_additional_information.api_channel_id', $journal_channel_ids);
                        }
                    });
                if ($record_id) {
                    $query->where('platform_order.id', $record_id);
                } else {
                    $query->where([
                        'platform_order.user_integration_id' => $user_integration_id,
                        'platform_order.platform_id' => $SourcePlatformId,
                        'platform_order.sync_status' => $sync_status,
                        'platform_order.order_type' => "SO",
                    ]);
                }
                $list = $query->orderBy('platform_order.updated_at', 'ASC')->take($limit)->get();

                if (!empty($list) && count($list) > 0) {
                    //$shipping_method_object_id = $this->helper->getObjectId('shipping_method');

                    $recordExist = 1;

                    $default_other_country_taxcode = $default_us_based_taxcode = NULL;
                    $other_country_taxcode_query = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_taxcode_for_other_country", ['api_id'], "default");
                    if ($other_country_taxcode_query) {
                        $default_other_country_taxcode = $other_country_taxcode_query->api_id;
                    }

                    $us_based_taxcode_query = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_taxcode_for_us_country", ['api_id'], "default");
                    if ($us_based_taxcode_query) {
                        $default_us_based_taxcode = $us_based_taxcode_query->api_id;
                    }

                    $productIdentity = $this->service->productIdentityMapping($user_integration_id, $platform_workflow_rule_id);
                    $source_identity = $productIdentity['source_identity'];
                    $discountAccountId = $taxCode = NULL;
                    // /* Shipping Cost Account ID */
                    // $shippingAccount = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_shippingcost_account", ['api_id'], "default");
                    // if ($shippingAccount) {
                    //$shippingAccountId = $shippingAccount->api_id;
                    // }
                    // /* Other Cost Account ID */
                    // $otherCostAccount = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_othercost_account", ['api_id'], "default");
                    // if ($otherCostAccount) {
                    //$otherCostAccountId = $otherCostAccount->api_id;
                    // }
                    // // /* Landed Cost Account ID */
                    // // $landedUnitAccount= $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_othercost_sku", ['api_id'], "default");
                    // // if ($landedUnitAccount) {
                    // //$$landedUnitAccountId = $landedUnitAccount->api_id;
                    // // }
                    // /* discountAccount Cost Account ID */
                    $discountAccount = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_discountcost_account", ['api_id'], "default");
                    if ($discountAccount) {
                        $discountAccountId = $discountAccount->api_id;
                    }


                    foreach ($list as $value) {
                        /* Find Order Primary Key */
                        if (!$value->linked_id) {
                            $payload = [];
                            $order_primary_id = isset($value->id) ? $value->id : NULL;
                            $orderLines = isset($value->platformOrderLine) ? $value->platformOrderLine : null;

                            if ($orderLines) {
                                $platform_customer_id = $value->platform_customer_id ? $value->platform_customer_id : null;

                                $paymentMethod = $this->service->findPaymentMethodAndSave($value, $account); //find or payment method

                                //if (!is_numeric($paymentMethod) && !is_null($paymentMethod)) { // if payment method detail not found in order
                                $billAddress = $this->service->getShippingAddress($value);
                                $customerRef = $this->service->findCustomer($platform_customer_id, $account); //find or create customer
                                if (isset($billAddress['Country']) && in_array($billAddress['Country'], ["US", "United States", "United States of America", "USA", "U.S.", "U.S.A."])) {
                                    $state = isset($billAddress['State']) && !empty($billAddress['State']) ? $billAddress['State'] : null;
                                    if ($state) {
                                        $taxCode = app('App\Http\Controllers\QuickBooks\Helper\QuickBooksHelper')->getCustomMappingForState($state, $user_integration_id, null, "state");
                                        if ($taxCode == false) {
                                            $taxCode = $default_us_based_taxcode;
                                        }
                                    } else {
                                        $taxCode = $default_us_based_taxcode;
                                    }
                                } else {
                                    $taxCode = $default_other_country_taxcode;
                                }
                                /* payment type mapping with account deposit to account */
                                if (isset($value->order_transaction->transaction_method)) { //if payment type name is found in DB
                                    $deposit_to_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "sorder_payment", ['name'], 'cross', $value->order_transaction->transaction_method, 'single', 'source', ['api_id']);
                                    if (isset($deposit_to_account->api_id)) {
                                        $payload["DepositToAccountRef"]["value"] = $deposit_to_account->api_id;
                                    }
                                }

                                if (is_numeric($customerRef['customerId'])) {

                                    $prepareOrderLine = $this->service->prepareSOOrderLine($value, $user_id, $user_integration_id, $this->platformId, $source_identity, $discountAccountId, $account);

                                    if (!$prepareOrderLine['productNotFound']) {
                                        $payload["Line"] = $prepareOrderLine['items'];

                                        if (isset($value->getShipmentReadyAndFailed->transaction_id)) { // if available
                                            $payload["PaymentRefNum"] = $value->getShipmentReadyAndFailed->transaction_id;
                                        }
                                        if (isset($billAddress['Email'])) { // if order email available
                                            $payload["BillEmail"]["Address"] = @$billAddress['Email'];
                                        }
                                        if ($value->notes) { // if notes available send to private notes
                                            $payload["PrivateNote"] = strlen($value['notes']) > 4000 ? substr($value['notes'], 0, 4000) : $value['notes'];
                                        }
                                        if ($value->file_name) { // if message to customer memo available send to Memo
                                            $payload["CustomerMemo"]['value'] = strlen($value->file_name) > 4000 ? substr($value->file_name, 0, 4000) : $value->file_name;
                                        }
                                        if (isset($billAddress['Line1']) && !empty($billAddress['Line1'])) { // if bill address found in order
                                            $payload["BillAddr"] = [
                                                "Line1" => $billAddress['Line1']
                                            ];

                                            $payload["ShipFromAddr"] = [
                                                "Line1" => $billAddress['Line1'],
                                                "Line2" => $billAddress['Line2']
                                            ];
                                        }

                                        $payload["DocNumber"] = $value->order_number;
                                        $payload["CustomerRef"]['value'] = $customerRef['customerId'];

                                        if (is_numeric($paymentMethod)) { // if payment method detail found in order
                                            $payload["PaymentMethodRef"]['value'] = $paymentMethod;
                                        }
                                        if (isset($value->getShipmentReadyAndFailed->realease_date)) { // if ShipDate detail found in order
                                            $payload["ShipDate"] = $value->getShipmentReadyAndFailed->realease_date;
                                        }
                                        if (isset($value->getShipmentReadyAndFailed->tracking_info)) { // if tracking detail found in order
                                            $payload["TrackingNum"] = $value->getShipmentReadyAndFailed->tracking_info;
                                        }
                                        if (isset($value->shipping_method)) { // if shipping method detail found in order
                                            $payload["ShipMethodRef"]['value'] = $value->shipping_method;
                                        }

                                        if ($prepareOrderLine['taxTotal'] > 0) {
                                            $payload["TxnTaxDetail"] = [
                                                'TxnTaxCodeRef' => [
                                                    'value' => $taxCode
                                                ],
                                                'TotalTax' => $prepareOrderLine['taxTotal']
                                            ];
                                        }

                                        //
                                        \Storage::disk('local')->append(date('d-m-Y') . 'sales_receipt.txt', json_encode($payload));
                                        $apicall = $this->APICALL($account, "POST", "salesreceipt", ['minorversion' => 65], $payload, 'v3');
                                        //dd($payload,$apicall);
                                        //dd($apicall);

                                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                            $order = $apicall['body']['SalesReceipt'];
                                            if (isset($order['Id'])) {
                                                /* Insert order details */
                                                $orderLinkedId = $this->service->saveOrderDetails([
                                                    'user_id' => $user_id,
                                                    'platform_id' => $this->platformId,
                                                    'user_integration_id' => $user_integration_id,
                                                    'order_type' => "SO",
                                                    'api_order_id' => $order['Id'],
                                                    'order_date' => date("Y-m-d H:i:s", strtotime($order['MetaData']['CreateTime'])),
                                                    'order_number' => $order['DocNumber'],
                                                    'sync_status' => 'Pending',
                                                    'linked_id' => $order_primary_id,
                                                    'shipment_status' => "Pending",
                                                    'order_updated_at' => date("Y-m-d H:i:s"),
                                                ]);
                                                $value->getShipmentReadyAndFailed->sync_status = "Synced";
                                                $value->getShipmentReadyAndFailed->save(); //save shipment table entry as synced
                                                $value->sync_status = 'Synced';
                                                $value->order_updated_at = date("Y-m-d H:i:s");
                                                $value->linked_id = $orderLinkedId;
                                                $value->save();
                                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'success', $order_primary_id, null);
                                            }
                                        } else {
                                            $error = $this->handleResponseError($apicall);
                                            $return_response = $error ? $error : "API Error";
                                            $value->sync_status = 'Failed';
                                            $value->order_updated_at = date("Y-m-d H:i:s");
                                            $value->save();

                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $order_primary_id, $return_response);
                                        }
                                    } else {
                                        $value->sync_status = 'Failed';
                                        $value->order_updated_at = date("Y-m-d H:i:s");
                                        $value->save();
                                        $return_response = "Some of line items are not found";
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $order_primary_id, $return_response);
                                    }
                                } else {
                                    $value->sync_status = 'Failed';
                                    $value->order_updated_at = date("Y-m-d H:i:s");
                                    $value->save();
                                    $return_response = $customerRef['customerId'];
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $order_primary_id, $return_response);
                                }
                                /*
                                    } else {
                                        $value->sync_status = 'Failed';
                                        $value->order_updated_at = date("Y-m-d H:i:s");
                                        $value->save();
                                        $return_response = is_null($paymentMethod)?"Payment method detail not found":$paymentMethod;
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $order_primary_id, $return_response);
                                    }
                                */
                            } else {
                                $value->sync_status = 'Failed';
                                $value->order_updated_at = date("Y-m-d H:i:s");
                                $value->save();
                                $return_response = "No line items found for order";
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $order_primary_id, $return_response);
                            }
                        }
                    }
                }

                if ($recordExist == 0) {
                    $return_response = "Record not exist";
                }

                if (is_array($journal_channel_ids) && count($journal_channel_ids)) {
                    //create journal entry for selected channel.
                    $sync_response = $this->syncJournalEntry($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $SourcePlatformId, $journal_channel_ids, $account, $sales_order_object_id);
                    if ($sync_response) {
                        $return_response = $sync_response;
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> QuickBooksApiController -> syncOrderShipment -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
    public function syncOrderShipmentTest($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $platform_workflow_rule_id = null, $source_platform_name = null, $sync_status = "Ready", $record_id = NULL, $account = null)
    {
        $return_response = true;
        try {
            $failedOrders = [];
            $recordExist = 0;
            $limit = 20;
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($user_integration_id);
            }
            $SourcePlatformId = $this->helper->getPlatformIdByName($source_platform_name);
            $sales_order_object_id = $this->helper->getObjectId('sales_order');

            if ($account && $sales_order_object_id) {
                $journal_channel_ids = $this->mapping->getMappedDataByName($user_integration_id, NULL, "journal_channel_filter", ['api_id'], 'regular', null, 'multiple');
                $query = PlatformOrder::leftJoin('platform_order_additional_information', 'platform_order.id', '=', 'platform_order_additional_information.platform_order_id')
                    ->select('platform_order.id', 'platform_order.user_id', 'platform_order.platform_id', 'platform_order.user_integration_id', 'platform_order.user_workflow_rule_id', 'platform_order.platform_customer_id', 'platform_order.order_type', 'platform_order.api_order_id', 'platform_order.order_number', 'platform_order.sync_status', 'platform_order.is_voided', 'platform_order.notes', 'platform_order.is_deleted', 'platform_order.linked_id', 'platform_order.order_updated_at', 'platform_order.updated_at', 'platform_order.warehouse_id', 'platform_order.order_date', 'platform_order.api_order_reference', 'platform_order.allow_check', 'platform_order.linked_api_order_id', 'platform_order.shipping_total', 'platform_order.notes', 'platform_order.file_name', 'platform_order.order_number', 'platform_order.customer_email', 'platform_order.shipping_method', 'platform_order.total_discount', 'platform_order.is_fully_synced', 'platform_order_additional_information.api_channel_id');

                if ($record_id) {
                    $query->where('platform_order.id', $record_id);
                } else {
                    $query->where([
                        'platform_order.user_integration_id' => $user_integration_id,
                        'platform_order.platform_id' => $SourcePlatformId,
                        'platform_order.sync_status' => $sync_status,
                        'platform_order.order_type' => "SO",
                    ])->where(function ($query) use ($journal_channel_ids) {
                        if (is_array($journal_channel_ids) && count($journal_channel_ids)) {
                            $query->whereNotIn('platform_order_additional_information.api_channel_id', $journal_channel_ids);
                        }
                    });
                }
                $list = $query->orderBy('platform_order.updated_at', 'ASC')->take($limit)->get();

                $syncOrder = $syncJournalOrder = true;
                $order = null;
                $paymentTypes = [];
                if (!empty($list) && count($list) > 0) {
                    if ($record_id) {
                        $is_fully_synced = isset($list[0]->is_fully_synced) ? $list[0]->is_fully_synced : 0;
                        if ($is_fully_synced) {
                            $syncOrder = true;
                            $syncJournalOrder = false;
                        } else {
                            $arr = explode('_', $list[0]->api_order_id);
                            if (isset($arr[1])) {
                                $journal_channel_ids = [$arr[1]];
                                $order = $list[0];
                            } else {
                                $syncJournalOrder = false;
                            }
                            $syncOrder = false;
                        }
                    }
                    if ($syncOrder) {
                        $recordExist = 1;


                        $default_other_country_taxcode = $default_us_based_taxcode = $default_journal_debit_account = NULL;
                        $other_country_taxcode_query = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_taxcode_for_other_country", ['api_id'], "default");
                        if ($other_country_taxcode_query) {
                            $default_other_country_taxcode = $other_country_taxcode_query->api_id;
                        }

                        $us_based_taxcode_query = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_taxcode_for_us_country", ['api_id'], "default");
                        if ($us_based_taxcode_query) {
                            $default_us_based_taxcode = $us_based_taxcode_query->api_id;
                        }
                        $journal_debit_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_journal_debit_account", ['api_id', 'name'], "default");
                        if ($journal_debit_account) {
                            $default_journal_debit_account = $journal_debit_account->api_id;
                        }

                        $productIdentity = $this->service->productIdentityMapping($user_integration_id, $platform_workflow_rule_id);
                        $source_identity = $productIdentity['source_identity'];
                        $destination_identity = $productIdentity['destination_identity'];
                        $discountAccountId = $taxCode = $shippingItemSKU = NULL;
                        // /* Shipping Cost Account ID */
                        // $shippingAccount = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_shippingcost_account", ['api_id'], "default");
                        // if ($shippingAccount) {
                        //$shippingAccountId = $shippingAccount->api_id;
                        // }
                        // /* Other Cost Account ID */
                        // $otherCostAccount = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_othercost_account", ['api_id'], "default");
                        // if ($otherCostAccount) {
                        //$otherCostAccountId = $otherCostAccount->api_id;
                        // }
                        // // /* Landed Cost Account ID */
                        // // $landedUnitAccount= $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_othercost_sku", ['api_id'], "default");
                        // // if ($landedUnitAccount) {
                        // //$$landedUnitAccountId = $landedUnitAccount->api_id;
                        // // }
                        // /* discountAccount Cost Account ID */
                        $discountAccount = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_discountcost_account", ['api_id'], "default");
                        if ($discountAccount) {
                            $discountAccountId = $discountAccount->api_id;
                        }
                        $shippingSku = $this->mapping->getMappedDataByName($user_integration_id, null, "default_shipping_sku", ['custom_data'], "default");
                        if ($shippingSku) {
                            $shippingItemSKU = $shippingSku->custom_data;
                        }


                        foreach ($list as $value) {
                            /* Find Order Primary Key */
                            if (!$value->linked_id) {
                                $payload = [];
                                $order_primary_id = isset($value->id) ? $value->id : NULL;
                                $orderLines = isset($value->platformOrderLine) ? $value->platformOrderLine : null;

                                if ($orderLines) {
                                    $createInvoice=false;
                                    $platform_customer_id = $value->platform_customer_id ? $value->platform_customer_id : null;
                                    /* payment type mapping with account deposit to account */
                                    $paymentTypeFound = true;
                                    if (isset($value->order_transaction->transaction_method)) { //if payment type name is found in DB
                                        $deposit_to_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "sorder_payment", ['name'], 'cross', $value->order_transaction->transaction_method, 'single', 'source', ['api_id']);
                                        if (isset($deposit_to_account->api_id)) {
                                            if($deposit_to_account->api_id!="unpaid"){
                                                $payload["DepositToAccountRef"]["value"] = $deposit_to_account->api_id;
                                            }else{
                                                $createInvoice=true;
                                            }

                                        } else {
                                            $paymentTypeFound = false;
                                            $paymentTypes[] = $value->order_transaction->transaction_method;
                                        }
                                    } else {
                                        if ($default_journal_debit_account) {
                                            $payload["DepositToAccountRef"]["value"] = $default_journal_debit_account;
                                        } else {
                                            $paymentTypeFound = false;
                                        }
                                    }
                                    if ($paymentTypeFound) {

                                        $paymentMethod = $this->service->findPaymentMethodAndSave($value, $account); //find or payment method

                                        //if (!is_numeric($paymentMethod) && !is_null($paymentMethod)) { // if payment method detail not found in order
                                        $billAddress = $this->service->getShippingAddress($value);
                                        $customerRef = $this->service->findCustomer($platform_customer_id, $account); //find or create customer
                                        if (isset($billAddress['Country']) && in_array($billAddress['Country'], ["US", "United States", "United States of America", "USA", "U.S.", "U.S.A."])) {
                                            $state = isset($billAddress['State']) && !empty($billAddress['State']) ? $billAddress['State'] : null;
                                            if ($state) {
                                                $taxCode = app('App\Http\Controllers\QuickBooks\Helper\QuickBooksHelper')->getCustomMappingForState($state, $user_integration_id, null, "state");
                                                if ($taxCode == false) {
                                                    $taxCode = $default_us_based_taxcode;
                                                }
                                            } else {
                                                $taxCode = $default_us_based_taxcode;
                                            }
                                        } else {
                                            $taxCode = $default_other_country_taxcode;
                                        }

                                        if (is_numeric($customerRef['customerId'])) {

                                                $prepareOrderLine = $this->service->prepareSOOrderLineTest($value, $user_id, $user_integration_id, $SourcePlatformId, $source_platform_name, $source_identity, $destination_identity, $discountAccountId, $shippingItemSKU, $account);
                                                if (empty($prepareOrderLine['shippingError']) && empty($prepareOrderLine['discountError'])) {
                                                    if (!$prepareOrderLine['productNotFound']) {
                                                        $payload["Line"] = $prepareOrderLine['items'];
                                                        if(!$createInvoice){
                                                            if (isset($value->getShipmentReadyAndFailed->transaction_id)) { // if available
                                                                $payload["PaymentRefNum"] = $value->getShipmentReadyAndFailed->transaction_id;
                                                            }
                                                        }
                                                        if (isset($billAddress['Email'])) { // if order email available
                                                            $payload["BillEmail"]["Address"] = @$billAddress['Email'];
                                                        }
                                                        if ($value->notes) { // if notes available send to private notes
                                                            $payload["PrivateNote"] = strlen($value['notes']) > 4000 ? substr($value['notes'], 0, 4000) : $value['notes'];
                                                        }
                                                        if ($value->file_name) { // if message to customer memo available send to Memo
                                                            $payload["CustomerMemo"]['value'] = strlen($value->file_name) > 4000 ? substr($value->file_name, 0, 4000) : $value->file_name;
                                                        }
                                                        if (isset($billAddress['Line1']) && !empty($billAddress['Line1'])) { // if bill address found in order
                                                            $payload["BillAddr"] = [
                                                                "Line1" => $billAddress['Line1']
                                                            ];
                                                            if(!$createInvoice){
                                                            $payload["ShipFromAddr"] = [
                                                                "Line1" => $billAddress['Line1'],
                                                                "Line2" => $billAddress['Line2']
                                                            ];
                                                        }else{
                                                            $payload["ShipAddr"]=[
                                                                "Line1" => $billAddress['Line1'],
                                                                "City" => $billAddress['City'],
                                                                "PostalCode"=> $billAddress['PostalCode'],
                                                                "CountrySubDivisionCode"=> $billAddress['Country'],

                                                            ];
                                                        }
                                                        }

                                                        $payload["DocNumber"] = $value->order_number;
                                                        $payload["CustomerRef"]['value'] = $customerRef['customerId'];

                                                        if (is_numeric($paymentMethod)) { // if payment method detail found in order
                                                            $payload["PaymentMethodRef"]['value'] = $paymentMethod;
                                                        }
                                                        if(!$createInvoice){
                                                            if (isset($value->getShipmentReadyAndFailed->realease_date)) { // if ShipDate detail found in order
                                                                $payload["ShipDate"] = $value->getShipmentReadyAndFailed->realease_date;
                                                            }
                                                        }
                                                        if (isset($value->getShipmentReadyAndFailed->tracking_info)) { // if tracking detail found in order
                                                            $payload["TrackingNum"] = $value->getShipmentReadyAndFailed->tracking_info;
                                                        }
                                                        if (isset($value->shipping_method)) { // if shipping method detail found in order
                                                            $payload["ShipMethodRef"]['value'] = $value->shipping_method;
                                                        }
                                                        if($createInvoice){
                                                            if(isset($value->order_transaction->transaction_datetime)){
                                                                $payload['TxnDate'] = date('Y-m-d', strtotime($value->order_transaction->transaction_datetime));
                                                            }
                                                            if(isset($value->order_transaction->transaction_datetime)){
                                                                $payload['DueDate'] = date('Y-m-d', strtotime($value->getShipmentReadyAndFailed->realease_date));
                                                            }

                                                        }


                                                        if ($prepareOrderLine['taxTotal'] > 0) {
                                                            $payload["TxnTaxDetail"] = [
                                                                'TxnTaxCodeRef' => [
                                                                    'value' => $taxCode
                                                                ],
                                                                'TotalTax' => $prepareOrderLine['taxTotal']
                                                            ];
                                                        }
                                                        if($createInvoice){
                                                            $baseurl="invoice";
                                                        }else{
                                                            $baseurl="salesreceipt";
                                                        }
                                                        //
                                                        \Storage::disk('local')->append(date('d-m-Y') . 'sales_receipt.txt', json_encode($payload));
                                                        $apicall = $this->APICALL($account, "POST", $baseurl, ['minorversion' => 65], $payload, 'v3');
                                                        //dd($payload,$apicall);
                                                        //dd($apicall);

                                                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                                            if ($createInvoice) {
                                                                $order = $apicall['body']['Invoice'];
                                                            } else {
                                                                $order = $apicall['body']['SalesReceipt'];
                                                            }
                                                            if (isset($order['Id'])) {
                                                                /* Insert order details */
                                                                $orderLinkedId = $this->service->saveOrderDetails([
                                                                    'user_id' => $user_id,
                                                                    'platform_id' => $this->platformId,
                                                                    'user_integration_id' => $user_integration_id,
                                                                    'order_type' => "SO",
                                                                    'api_order_id' => $order['Id'],
                                                                    'order_date' => date("Y-m-d H:i:s", strtotime($order['MetaData']['CreateTime'])),
                                                                    'order_number' => @$order['DocNumber'],
                                                                    'api_order_reference' => @$order['DocNumber'],
                                                                    'sync_status' => 'Pending',
                                                                    'linked_id' => $order_primary_id,
                                                                    'shipment_status' => "Pending",
                                                                    'order_updated_at' => date("Y-m-d H:i:s"),
                                                                ]);
                                                                $value->getShipmentReadyAndFailed->sync_status = PlatformStatus::SYNCED;
                                                                $value->getShipmentReadyAndFailed->save(); //save shipment table entry as synced
                                                                $value->sync_status =  PlatformStatus::SYNCED;
                                                                $value->order_updated_at = date("Y-m-d H:i:s");
                                                                $value->linked_id = $orderLinkedId;
                                                                $value->save();
                                                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'success', $order_primary_id, null);
                                                            }
                                                        } else {
                                                            $error = $this->handleResponseError($apicall);
                                                            $return_response = $error ? $error : "API Error";
                                                            $value->sync_status = 'Failed';
                                                            $value->is_fully_synced = 1;
                                                            $value->order_updated_at = date("Y-m-d H:i:s");
                                                            $value->save();

                                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $order_primary_id, $return_response);
                                                        }
                                                    } else {
                                                        $value->sync_status = 'Failed';
                                                        $value->is_fully_synced = 1;
                                                        $value->order_updated_at = date("Y-m-d H:i:s");
                                                        $value->save();
                                                        $return_response = "Some of line items are not found";
                                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $order_primary_id, $return_response);
                                                    }
                                                } else {
                                                    $value->sync_status = 'Failed';
                                                    $value->is_fully_synced = 1;
                                                    $value->order_updated_at = date("Y-m-d H:i:s");
                                                    $value->save();
                                                    $error = null;
                                                    if ($prepareOrderLine['shippingError']) {
                                                        $error = $prepareOrderLine['shippingError'] . ', ';
                                                    }
                                                    if ($prepareOrderLine['discountError']) {
                                                        $error .= $prepareOrderLine['discountError'] . ', ';
                                                    }

                                                    $return_response =  rtrim($error, ",");
                                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $order_primary_id, $return_response);
                                                }

                                        } else {
                                            $value->sync_status = 'Failed';
                                            $value->is_fully_synced = 1;
                                            $value->order_updated_at = date("Y-m-d H:i:s");
                                            $value->save();
                                            $return_response = $customerRef['customerId'];
                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $order_primary_id, $return_response);
                                        }
                                        /*
                                        } else {
                                            $value->sync_status = 'Failed';
                                            $value->order_updated_at = date("Y-m-d H:i:s");
                                            $value->save();
                                            $return_response = is_null($paymentMethod)?"Payment method detail not found":$paymentMethod;
                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $order_primary_id, $return_response);
                                        }
                                    */
                                    } else {
                                        $value->sync_status = 'Failed';
                                        $value->is_fully_synced = 1;
                                        $value->order_updated_at = date("Y-m-d H:i:s");
                                        $value->save();
                                        $return_response = "Payment type or mapping is not found";
                                        $failedOrders[] = $value->api_order_id;
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $order_primary_id, $return_response);
                                    }
                                } else {
                                    $value->sync_status = 'Failed';
                                    $value->is_fully_synced = 1;
                                    $value->order_updated_at = date("Y-m-d H:i:s");
                                    $value->save();
                                    $return_response = "No line items found for order";
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $order_primary_id, $return_response);
                                }
                            }
                        }
                    }
                }

                if ($recordExist == 0) {
                    $return_response = "Record not exist";
                }
                if (count($failedOrders)) {
                    $user = User::find($account->user_id);
                    if ($user) {
                        $failedOrders = implode(",", $failedOrders);
                        $paymentTypes = implode(",", array_unique($paymentTypes));
                        /* Send Email Notification for payment type not found records */
                        $this->service->notifyCustomerByEmail([
                            'paymenttypes' =>  $paymentTypes,
                            'orders' => $failedOrders,
                            'email' => $user->email,
                            'to_name' => $user->name,
                            'subject' => "Payment type or mapping is not found",
                        ], $user->organization_id);
                    }
                }
                if ($syncJournalOrder) {

                    if (is_array($journal_channel_ids) && count($journal_channel_ids)) {
                        //create journal entry for selected channel.

                        $sync_response = $this->syncJournalEntryTest($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $SourcePlatformId, $journal_channel_ids, $account, $sales_order_object_id, $order);

                        if ($sync_response) {
                            $return_response = $sync_response;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> QuickBooksApiController -> syncOrderShipmentTest -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    public function syncJournalEntry($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $platform_workflow_rule_id = null, $SourcePlatformId = null, $journal_channel_ids = [], $account = null, $sales_order_object_id = null)
    {
        $sync_response = NULL;
        try {
            date_default_timezone_set("US/Eastern");

            $JournalEntryDate = date('Y-m-d', strtotime('-1 day'));
            $platformUrl = PlatformUrl::select('id')->where(['url' => $JournalEntryDate, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'JournalEntry'])->first();
            if (is_null($platformUrl)) {
                $platform_order_ids = PlatformOrderTransaction::join('platform_order', function ($join) use ($user_integration_id, $SourcePlatformId) {
                    $join->on('platform_order_transactions.platform_order_id', '=', 'platform_order.id')
                        ->where('platform_order.user_integration_id', $user_integration_id)->where('platform_order.platform_id', $SourcePlatformId)->where('platform_order.order_type', 'SO')->where('platform_order.linked_id', 0);
                })->join('platform_order_additional_information', 'platform_order.id', '=', 'platform_order_additional_information.platform_order_id')
                    ->select('platform_order_transactions.platform_order_id')

                    ->whereDate('platform_order_transactions.created_at', '=', $JournalEntryDate)
                    ->where(function ($query) use ($journal_channel_ids) {
                        $query->whereIn('platform_order_additional_information.api_channel_id', $journal_channel_ids);
                    })
                    ->groupBy('platform_order_transactions.platform_order_id')
                    ->pluck('platform_order_transactions.platform_order_id')->toArray();
                if (count($platform_order_ids)) {
                    $default_journal_credit_sales_subtotal_account = $default_journal_credit_sales_tax_account = $default_journal_credit_sales_shipping_account = $default_journal_debit_account = $default_journal_debit_discount_account = NULL;

                    $journal_credit_sales_subtotal_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_journal_credit_sales_subtotal_account", ['api_id'], "default");
                    if ($journal_credit_sales_subtotal_account) {
                        $default_journal_credit_sales_subtotal_account = $journal_credit_sales_subtotal_account->api_id;
                    }

                    $journal_credit_sales_tax_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_journal_credit_sales_tax_account", ['api_id'], "default");
                    if ($journal_credit_sales_tax_account) {
                        $default_journal_credit_sales_tax_account = $journal_credit_sales_tax_account->api_id;
                    }

                    $journal_credit_sales_shipping_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_journal_credit_sales_shipping_account", ['api_id'], "default");
                    if ($journal_credit_sales_shipping_account) {
                        $default_journal_credit_sales_shipping_account = $journal_credit_sales_shipping_account->api_id;
                    }

                    $journal_debit_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_journal_debit_account", ['api_id', 'name'], "default");
                    if ($journal_debit_account) {
                        $default_journal_debit_account = $journal_debit_account->api_id;
                    }

                    $journal_debit_discount_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_journal_debit_discount_account", ['api_id'], "default");
                    if ($journal_debit_discount_account) {
                        $default_journal_debit_discount_account = $journal_debit_discount_account->api_id;
                    }

                    $Line = [];
                    $default_journal_debit_account_total_amount = 0;

                    $order_transactions = PlatformOrderTransaction::select('transaction_method', DB::raw('SUM(transaction_amount) as total_amount'))->whereIn('platform_order_id', $platform_order_ids)->groupBy('transaction_method')->get();
                    foreach ($order_transactions as $order_transaction) {
                        $sales_order_payment = $this->mapping->getMappedDataByName($user_integration_id, NULL, "sales_order_payment", ['name'], 'cross', $order_transaction->transaction_method, 'single', 'source', ['api_id']);
                        if (isset($sales_order_payment->api_id)) {
                            $Line[] = array(
                                "DetailType" => "JournalEntryLineDetail",
                                "JournalEntryLineDetail" => array(
                                    "PostingType" => "Debit",
                                    "AccountRef" => array("value" => $sales_order_payment->api_id)
                                ),
                                "Amount" => $order_transaction->total_amount,
                                "Description" => $order_transaction->transaction_method
                            );
                        } else {
                            $default_journal_debit_account_total_amount = $default_journal_debit_account_total_amount + $order_transaction->total_amount;
                        }
                    }

                    if ($default_journal_debit_account_total_amount) {
                        $Line[] = array(
                            "DetailType" => "JournalEntryLineDetail",
                            "JournalEntryLineDetail" => array(
                                "PostingType" => "Debit",
                                "AccountRef" => array("value" => $default_journal_debit_account)
                            ),
                            "Amount" => $default_journal_debit_account_total_amount,
                            "Description" => @$journal_debit_account->name
                        );
                    }

                    $total_debit_discount_amount = 0;
                    if ($default_journal_debit_discount_account || $default_journal_credit_sales_shipping_account) {
                        $order_debit_amount = PlatformOrder::select(DB::raw('SUM(total_discount) as total_discount_amount'), DB::raw('SUM(shipping_total) as total_shipping_amount'))->whereIn('id', $platform_order_ids)->first();
                        if ($order_debit_amount) {
                            if ($order_debit_amount->total_discount_amount) {
                                $total_debit_discount_amount = $total_debit_discount_amount + $order_debit_amount->total_discount_amount;
                            }

                            if ($order_debit_amount->total_shipping_amount) {
                                $Line[] = array(
                                    "DetailType" => "JournalEntryLineDetail",
                                    "JournalEntryLineDetail" => array(
                                        "PostingType" => "Credit",
                                        "AccountRef" => array("value" => $default_journal_credit_sales_shipping_account)
                                    ),
                                    "Amount" => $order_debit_amount->total_shipping_amount,
                                    "Description" => "Shipping cost of the orders"
                                );
                            }
                        }
                    }

                    if ($default_journal_credit_sales_subtotal_account || $default_journal_credit_sales_tax_account || $default_journal_debit_discount_account) {
                        $journal_credit_amount = PlatformOrderLine::select(DB::raw('SUM(subtotal) as subtotal_amount'), DB::raw('SUM(discount_amount) as total_line_discount_amount'), DB::raw('SUM(subtotal_tax) as total_tax_amount'))->whereIn('platform_order_id', $platform_order_ids)->where('row_type', 'ITEM')->first();
                        if ($journal_credit_amount) {
                            if ($journal_credit_amount->subtotal_amount) {
                                $Line[] = array(
                                    "DetailType" => "JournalEntryLineDetail",
                                    "JournalEntryLineDetail" => array(
                                        "PostingType" => "Credit",
                                        "AccountRef" => array("value" => $default_journal_credit_sales_subtotal_account)
                                    ),
                                    "Amount" => $journal_credit_amount->subtotal_amount,
                                    "Description" => "Sub total cost of the order"
                                );
                            }

                            if ($journal_credit_amount->total_tax_amount) {
                                $Line[] = array(
                                    "DetailType" => "JournalEntryLineDetail",
                                    "JournalEntryLineDetail" => array(
                                        "PostingType" => "Credit",
                                        "AccountRef" => array("value" => $default_journal_credit_sales_tax_account)
                                    ),
                                    "Amount" => $journal_credit_amount->total_tax_amount,
                                    "Description" => "Sales tax of the orders"
                                );
                            }

                            if ($journal_credit_amount->total_line_discount_amount) {
                                $total_debit_discount_amount = $total_debit_discount_amount + $journal_credit_amount->total_line_discount_amount;
                            }
                        }
                    }

                    if ($default_journal_debit_discount_account && $total_debit_discount_amount) {
                        $Line[] = array(
                            "DetailType" => "JournalEntryLineDetail",
                            "JournalEntryLineDetail" => array(
                                "PostingType" => "Debit",
                                "AccountRef" => array("value" => $default_journal_debit_discount_account)
                            ),
                            "Amount" => $total_debit_discount_amount,
                            "Description" => "Discount amount of the orders"
                        );
                    }

                    if (count($Line)) {
                        $payload = array('Line' => $Line);
                        $result = $this->APICALL($account, "POST", "journalentry", ['minorversion' => 65], $payload, 'v3');
                        if (isset($result['status_code']) && $result['status_code'] == 200) {
                            $journal_entry = $result['body']['JournalEntry'];
                            if (isset($journal_entry['Id'])) {
                                $jn_platform_order = PlatformOrder::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_type' => 'JN', 'api_order_id' => $journal_entry['Id'], 'order_number' => $journal_entry['Id'], 'order_date' => $journal_entry['MetaData']['CreateTime'], 'linked_id' => $platform_order_ids[0]]);

                                PlatformOrder::whereIn('id', $platform_order_ids)
                                    ->update(['sync_status' => 'Synced', 'linked_id' => $jn_platform_order->id]);

                                $response = 'Payload: ' . json_encode($payload) . ', Response: ' . $journal_entry['Id'];
                                PlatformUrl::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url' => $JournalEntryDate, 'url_name' => 'JournalEntry', 'status' => 1, 'response' => $response, 'allow_retain' => 1]);

                                $this->log->syncLogBulk($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'success', $platform_order_ids, null);
                            }
                        } else {
                            $error = $this->handleResponseError($result);
                            $sync_response = $error ? $error : "API Error";

                            $response = 'Payload: ' . json_encode($payload) . ', Response: ' . $sync_response;

                            PlatformOrder::whereIn('id', $platform_order_ids)
                                ->update(['sync_status' => 'Failed']);

                            PlatformUrl::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url' => $JournalEntryDate, 'url_name' => 'JournalEntry', 'status' => 0, 'response' => $response, 'allow_retain' => 1]);

                            $this->log->syncLogBulk($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', $platform_order_ids, $sync_response);
                        }
                    } else {
                        PlatformUrl::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url' => $JournalEntryDate, 'url_name' => 'JournalEntry', 'status' => 0, 'response' => 'Journal detail not available.', 'allow_retain' => 1]);
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> QuickBooksApiController -> syncJournalEntry -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $sync_response = $e->getMessage();
        }

        return $sync_response;
    }
    /* Mutate journal entry for selected channels from source platform */
    public function syncJournalEntryTest($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $platform_workflow_rule_id = null, $SourcePlatformId = null, $journal_channel_ids = [], $account = null, $sales_order_object_id = null, $order = null)
    {
        $sync_response = NULL;
        try {
            date_default_timezone_set("US/Eastern");
            $channel_object_id = $this->helper->getObjectId('channel');
            /* -------Default mappings run  only once------- */
            $default_journal_credit_sales_subtotal_account = $default_journal_credit_sales_tax_account = $default_journal_credit_sales_shipping_account = $default_journal_debit_account = $default_journal_debit_discount_account = NULL;

            $journal_credit_sales_subtotal_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_journal_credit_sales_subtotal_account", ['api_id'], "default");
            if ($journal_credit_sales_subtotal_account) {
                $default_journal_credit_sales_subtotal_account = $journal_credit_sales_subtotal_account->api_id;
            }

            $journal_credit_sales_tax_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_journal_credit_sales_tax_account", ['api_id'], "default");
            if ($journal_credit_sales_tax_account) {
                $default_journal_credit_sales_tax_account = $journal_credit_sales_tax_account->api_id;
            }

            $journal_credit_sales_shipping_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_journal_credit_sales_shipping_account", ['api_id'], "default");
            if ($journal_credit_sales_shipping_account) {
                $default_journal_credit_sales_shipping_account = $journal_credit_sales_shipping_account->api_id;
            }

            $journal_debit_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_journal_debit_account", ['api_id', 'name'], "default");
            if ($journal_debit_account) {
                $default_journal_debit_account = $journal_debit_account->api_id;
            }

            $journal_debit_discount_account = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_journal_debit_discount_account", ['api_id'], "default");
            if ($journal_debit_discount_account) {
                $default_journal_debit_discount_account = $journal_debit_discount_account->api_id;
            }

            /* ------- End default mappings------- */
            foreach ($journal_channel_ids as $journal_channel_id) {
                /* payment type mapping with account deposit to account */

                $paymentTypeMappingNotFound = false;

                $channel = PlatformObjectData::select('name')->where(['user_integration_id' => $user_integration_id, 'api_id' => $journal_channel_id, 'platform_object_id' => $channel_object_id, 'platform_id' => $SourcePlatformId, 'status' => 1])->first();
                if ($user_integration_id == 548) {
                    $JournalEntryDate = date('Y-m-d');
                } else {
                    $JournalEntryDate = date('Y-m-d', strtotime('-1 day'));
                }

                //  \Storage::append("QBtext.txt", " Loop000 : " . $order);
                if ($order) {
                    //  \Storage::append("QBtext.txt", " Loop000 : " . $order);
                    $platformUrl = null;
                } else {
                    $platformUrl = PlatformUrl::select('id')->where(['url' => $JournalEntryDate, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_filter' => $journal_channel_id, 'url_name' => 'JournalEntry'])->first();
                }
                //  \Storage::append("QBtext.txt", " Loop0 : " . $platformUrl);

                if (is_null($platformUrl)) {

                    $orderPrimaryID = null;
                    if ($order) {
                        $platform_order_ids = [];
                        if (isset($order->notes)) {
                            if (!empty($order->notes)) {
                                $platform_order_ids = array_map('intval', explode(',', $order->notes));
                            }
                            $JournalEntryDate = $order->order_date;
                            $orderPrimaryID = $order->id;
                        }

                        //  \Storage::append("QBtext.txt", " Loop1 : " . json_encode($platform_order_ids) . $sync_response);
                    } else {
                        $platform_order_ids = PlatformOrderTransaction::join('platform_order', function ($join) use ($user_integration_id, $SourcePlatformId) {
                            $join->on('platform_order_transactions.platform_order_id', '=', 'platform_order.id')
                                ->where(['platform_order.user_integration_id' => $user_integration_id, 'platform_order.platform_id' => $SourcePlatformId, 'platform_order.order_type' => 'SO', 'platform_order.linked_id' => 0, 'platform_order.is_fully_synced' => 0]);
                        })->join('platform_order_additional_information', 'platform_order.id', '=', 'platform_order_additional_information.platform_order_id')
                            ->select('platform_order_transactions.platform_order_id')
                            ->whereDate('platform_order_transactions.created_at', '=', $JournalEntryDate)
                            ->where(function ($query) use ($journal_channel_id) {
                                $query->where('platform_order_additional_information.api_channel_id', $journal_channel_id);
                            })
                            ->groupBy('platform_order_transactions.platform_order_id')
                            ->pluck('platform_order_transactions.platform_order_id')->toArray();
                    }

                    // \Storage::append("QBtext.txt", " Loop2 : " . json_encode($platform_order_ids) . $sync_response);
                    if (count($platform_order_ids)) {
                        if (is_null($default_journal_debit_discount_account)) {
                            $sync = false;
                            $sync_response = "No mapping found for default journal debit discount account";
                        } else if (is_null($default_journal_credit_sales_shipping_account)) {
                            $sync = false;
                            $sync_response = "No mapping found for default journal credit sales shipping account";
                        } else if (is_null($default_journal_credit_sales_subtotal_account)) {
                            $sync = false;
                            $sync_response = "No mapping found for default journal credit sales subtotal account";
                        } else if (is_null($default_journal_credit_sales_tax_account)) {
                            $sync = false;
                            $sync_response = "No mapping found for default journal credit sales tax account";
                        } else if (is_null($default_journal_debit_account)) {
                            $sync = false;
                            $sync_response = "No mapping found for Default Account for Payment type";
                        } else {
                            $sync = true;
                        }

                        // \Storage::append("QBtext.txt", " Loop3 : " . json_encode($platform_order_ids) . $sync_response);
                        if ($sync) {

                            $Line = [];
                            $default_journal_debit_account_total_amount = 0;
                            $unpaidPaymentType=false;
                            $order_transactions = PlatformOrderTransaction::select('transaction_method', DB::raw('SUM(transaction_amount) as total_amount'))->whereIn('platform_order_id', $platform_order_ids)->groupBy('transaction_method')->get();
                            if (count($order_transactions)) {

                                foreach ($order_transactions as $order_transaction) {
                                    if ($order_transaction->transaction_method) {
                                        $sales_order_payment = $this->mapping->getMappedDataByName($user_integration_id, NULL, "sorder_payment", ['name'], 'cross', $order_transaction->transaction_method, 'single', 'source', ['api_id']);
                                        if (isset($sales_order_payment->api_id)) {
                                            if($sales_order_payment->api_id!="unpaid"){//if payment type is not mapped with unpaid payment type
                                                $Line[] = array(
                                                    "DetailType" => "JournalEntryLineDetail",
                                                    "JournalEntryLineDetail" => array(
                                                        "PostingType" => "Debit",
                                                        "AccountRef" => array("value" => $sales_order_payment->api_id)
                                                    ),
                                                    "Amount" => $order_transaction->total_amount,
                                                    "Description" => $order_transaction->transaction_method
                                                );
                                            }else{
                                                $unpaidPaymentType=true;

                                                $paymentTypeMappingNotFound = true;
                                            }
                                        } else {
                                            $paymentTypeMappingNotFound = true;
                                        }
                                    } else {
                                        $default_journal_debit_account_total_amount = $default_journal_debit_account_total_amount + $order_transaction->total_amount;
                                    }
                                }

                                if ($default_journal_debit_account_total_amount) {
                                    $Line[] = array(
                                        "DetailType" => "JournalEntryLineDetail",
                                        "JournalEntryLineDetail" => array(
                                            "PostingType" => "Debit",
                                            "AccountRef" => array("value" => $default_journal_debit_account)
                                        ),
                                        "Amount" => $default_journal_debit_account_total_amount,
                                        "Description" => @$journal_debit_account->name
                                    );
                                }
                            }

                            $total_debit_discount_amount = 0;
                            if ($default_journal_debit_discount_account || $default_journal_credit_sales_shipping_account) {
                                $order_debit_amount = PlatformOrder::select(DB::raw('SUM(total_discount) as total_discount_amount'), DB::raw('SUM(shipping_total) as total_shipping_amount'))->whereIn('id', $platform_order_ids)->first();
                                if ($order_debit_amount) {
                                    if ($order_debit_amount->total_discount_amount) {
                                        $total_debit_discount_amount = $total_debit_discount_amount + $order_debit_amount->total_discount_amount;
                                    }

                                    if ($order_debit_amount->total_shipping_amount) {
                                        $Line[] = array(
                                            "DetailType" => "JournalEntryLineDetail",
                                            "JournalEntryLineDetail" => array(
                                                "PostingType" => "Credit",
                                                "AccountRef" => array("value" => $default_journal_credit_sales_shipping_account)
                                            ),
                                            "Amount" => $order_debit_amount->total_shipping_amount,
                                            "Description" => "Shipping cost of the orders"
                                        );
                                    }
                                }
                            }

                            if ($default_journal_credit_sales_subtotal_account || $default_journal_credit_sales_tax_account || $default_journal_debit_discount_account) {
                                $journal_credit_amount = PlatformOrderLine::select(DB::raw('SUM(subtotal) as subtotal_amount'), DB::raw('SUM(discount_amount) as total_line_discount_amount'), DB::raw('SUM(subtotal_tax) as total_tax_amount'))->whereIn('platform_order_id', $platform_order_ids)->where('row_type', 'ITEM')->first();
                                if ($journal_credit_amount) {
                                    if ($journal_credit_amount->subtotal_amount) {
                                        $Line[] = array(
                                            "DetailType" => "JournalEntryLineDetail",
                                            "JournalEntryLineDetail" => array(
                                                "PostingType" => "Credit",
                                                "AccountRef" => array("value" => $default_journal_credit_sales_subtotal_account)
                                            ),
                                            "Amount" => $journal_credit_amount->subtotal_amount,
                                            "Description" => "Sub total cost of the order"
                                        );
                                    }

                                    if ($journal_credit_amount->total_tax_amount) {
                                        $Line[] = array(
                                            "DetailType" => "JournalEntryLineDetail",
                                            "JournalEntryLineDetail" => array(
                                                "PostingType" => "Credit",
                                                "AccountRef" => array("value" => $default_journal_credit_sales_tax_account)
                                            ),
                                            "Amount" => $journal_credit_amount->total_tax_amount,
                                            "Description" => "Sales tax of the orders"
                                        );
                                    }

                                    if ($journal_credit_amount->total_line_discount_amount) {
                                        $total_debit_discount_amount = $total_debit_discount_amount + $journal_credit_amount->total_line_discount_amount;
                                    }
                                }
                            }

                            if ($default_journal_debit_discount_account && $total_debit_discount_amount) {
                                $Line[] = array(
                                    "DetailType" => "JournalEntryLineDetail",
                                    "JournalEntryLineDetail" => array(
                                        "PostingType" => "Debit",
                                        "AccountRef" => array("value" => $default_journal_debit_discount_account)
                                    ),
                                    "Amount" => $total_debit_discount_amount,
                                    "Description" => "Discount amount of the orders"
                                );
                            }

                            if (!$paymentTypeMappingNotFound) {
                                if (count($Line)) {
                                    $payload = array('Line' => $Line, "PrivateNote" => "Sales Order from " . @$channel->name);
                                    $result = $this->APICALL($account, "POST", "journalentry", ['minorversion' => 40], $payload, 'v3');
                                    \Storage::append("QBtext.txt", " Loop123 : " . json_encode($payload), json_encode($result));

                                    if (isset($result['status_code']) && $result['status_code'] == 200) {
                                        $journal_entry = $result['body']['JournalEntry'];
                                        if (isset($journal_entry['Id'])) {
                                            if ($orderPrimaryID) {
                                                $where = [
                                                    'id' => $orderPrimaryID
                                                ];
                                            } else {
                                                $where = [
                                                    'user_id' => $user_id,
                                                    'platform_id' => $SourcePlatformId,
                                                    'user_integration_id' => $user_integration_id,
                                                    'order_type' => 'SO',
                                                    'api_order_id' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                                ];
                                            }
                                            $save = PlatformOrder::updateOrCreate($where, [
                                                'user_id' => $user_id,
                                                'platform_id' => $SourcePlatformId,
                                                'user_integration_id' => $user_integration_id,
                                                'order_type' => 'SO',
                                                'api_order_id' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                                'order_number' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                                'order_date' => $JournalEntryDate,
                                                'sync_status' => PlatformStatus::SYNCED,
                                                'notes' => implode(",", $platform_order_ids)
                                            ]);
                                            if ($save) {
                                                $jn_platform_order = PlatformOrder::create([
                                                    'user_id' => $user_id,
                                                    'platform_id' => $this->platformId,
                                                    'user_integration_id' => $user_integration_id, 'order_type' => 'JN',
                                                    'api_order_id' => @$journal_entry['Id'],
                                                    'order_number' => @$journal_entry['DocNumber'],
                                                    'order_date' => $journal_entry['MetaData']['CreateTime'],
                                                    'linked_id' => @$save->id
                                                ]);
                                                $save->linked_id = @$jn_platform_order->id;
                                                $save->save();
                                                if (is_null($order)) {
                                                    PlatformOrder::whereIn('id', $platform_order_ids)
                                                        ->update(['sync_status' => PlatformStatus::INACTIVE, 'linked_id' => $jn_platform_order->id]);

                                                    $response = 'Payload: ' . json_encode($payload) . ', Response: ' . $journal_entry['Id'];

                                                    PlatformUrl::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url' => $JournalEntryDate, 'url_filter' => $journal_channel_id, 'url_name' => 'JournalEntry', 'status' => 1, 'response' => $response, 'allow_retain' => 1]);
                                                }
                                                $sync_response = true;
                                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'success', @$save->id, null);
                                            }
                                        }
                                    } else {
                                        $error = $this->handleResponseError($result);
                                        $sync_response = $error ? $error : "API Error";
                                        if ($orderPrimaryID) {
                                            $where = [
                                                'id' => $orderPrimaryID
                                            ];
                                        } else {
                                            $where = [
                                                'user_id' => $user_id,
                                                'platform_id' => $SourcePlatformId,
                                                'user_integration_id' => $user_integration_id,
                                                'order_type' => 'SO',
                                                'api_order_id' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                            ];
                                        }
                                        $save = PlatformOrder::updateOrCreate($where, [
                                            'user_id' => $user_id,
                                            'platform_id' => $SourcePlatformId,
                                            'user_integration_id' => $user_integration_id,
                                            'order_type' => 'SO',
                                            'api_order_id' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                            'order_number' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                            'order_date' => $JournalEntryDate,
                                            'sync_status' => PlatformStatus::FAILED,
                                            'notes' => implode(",", $platform_order_ids)
                                        ]);
                                        if ($save) {
                                            if (is_null($order)) {
                                                PlatformOrder::whereIn('id', $platform_order_ids)
                                                    ->update(['sync_status' => PlatformStatus::INACTIVE]);

                                                $response = 'Payload: ' . json_encode($payload) . ', Response: ' . $sync_response;

                                                PlatformUrl::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url' => $JournalEntryDate, 'url_filter' => $journal_channel_id, 'url_name' => 'JournalEntry', 'status' => 0, 'response' => $response, 'allow_retain' => 1]);
                                            }

                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', @$save->id, $sync_response);
                                        }
                                    }
                                } else {
                                    if ($orderPrimaryID) {
                                        $where = [
                                            'id' => $orderPrimaryID
                                        ];
                                    } else {
                                        $where = [
                                            'user_id' => $user_id,
                                            'platform_id' => $SourcePlatformId,
                                            'user_integration_id' => $user_integration_id,
                                            'order_type' => 'SO',
                                            'api_order_id' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                        ];
                                    }
                                    $save = PlatformOrder::updateOrCreate($where, [
                                        'user_id' => $user_id,
                                        'platform_id' => $SourcePlatformId,
                                        'user_integration_id' => $user_integration_id,
                                        'order_type' => 'SO',
                                        'api_order_id' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                        'order_number' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                        'order_date' => $JournalEntryDate,
                                        'sync_status' => PlatformStatus::FAILED,
                                        'notes' => implode(",", $platform_order_ids)
                                    ]);
                                    if ($save) {
                                        $sync_response = "Journal detail not available";
                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', @$save->id, $sync_response);
                                    }
                                    if (is_null($order)) {
                                        PlatformUrl::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url' => $JournalEntryDate, 'url_filter' => $journal_channel_id, 'url_name' => 'JournalEntry', 'status' => 0, 'response' => 'Journal detail not available.', 'allow_retain' => 1]);
                                    }
                                }
                            } else {
                                if ($orderPrimaryID) {
                                    $where = [
                                        'id' => $orderPrimaryID
                                    ];
                                } else {
                                    $where = [
                                        'user_id' => $user_id,
                                        'platform_id' => $SourcePlatformId,
                                        'user_integration_id' => $user_integration_id,
                                        'order_type' => 'SO',
                                        'api_order_id' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                    ];
                                }
                                $save = PlatformOrder::updateOrCreate($where, [
                                    'user_id' => $user_id,
                                    'platform_id' => $SourcePlatformId,
                                    'user_integration_id' => $user_integration_id,
                                    'order_type' => 'SO',
                                    'api_order_id' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                    'order_number' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                    'order_date' => $JournalEntryDate,
                                    'sync_status' => PlatformStatus::FAILED,
                                    'notes' => implode(",", $platform_order_ids)
                                ]);
                                if ($save) {
                                    if($unpaidPaymentType){
                                        $sync_response = "Mapping with UNPAID payment type will failed to sync";
                                    }else{
                                        $sync_response = "No mapping found for payment type";
                                    }


                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', @$save->id, $sync_response);
                                }
                            }
                        } else {
                            if ($orderPrimaryID) {
                                $where = [
                                    'id' => $orderPrimaryID
                                ];
                            } else {
                                $where = [
                                    'user_id' => $user_id,
                                    'platform_id' => $SourcePlatformId,
                                    'user_integration_id' => $user_integration_id,
                                    'order_type' => 'SO',
                                    'api_order_id' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                ];
                            }
                            $save = PlatformOrder::updateOrCreate($where, [
                                'user_id' => $user_id,
                                'platform_id' => $SourcePlatformId,
                                'user_integration_id' => $user_integration_id,
                                'order_type' => 'SO',
                                'api_order_id' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                'order_number' => $JournalEntryDate . "_" . $journal_channel_id . "_" . @$channel->name,
                                'order_date' => $JournalEntryDate,
                                'sync_status' => PlatformStatus::FAILED,
                                'notes' => implode(",", $platform_order_ids)
                            ]);
                            if ($save) {
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $sales_order_object_id, 'failed', @$save->id, $sync_response);
                            }
                        }
                    } else {
                        $sync_response = "No valid order to sync the journal";
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> QuickBooksApiController -> syncJournalEntryTest -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $sync_response = $e->getMessage();
        }

        return $sync_response;
    }

    /* Get Accounts*/
    public function getAccounts($user_id = null, $user_integration_id = null, $is_initial_sync = 0, $account = null)
    {
        $return_response = true;
        try {
            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                $objectId = $this->helper->getObjectId('account_number');
                if ($is_initial_sync) { // get products by chunks in loop when initial sync=1
                    //if initial sync is set =0
                    $this->service->setStatus($user_id, $user_integration_id, $this->platformId, $objectId);
                    $x = 1;
                    $page = 1;
                    $pageLimit = 100;
                    do {
                        $arguments = [
                            "query" => "select * from Account orderBy Id startPosition {$page} maxResults {$pageLimit}",
                        ];
                        $apicall = $this->APICALL($account, "GET", "query", $arguments);
                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                            $accounts = isset($apicall['body']['QueryResponse']['Account']) ? $apicall['body']['QueryResponse']['Account'] : [];
                            if (count($accounts) > 0) {

                                foreach ($accounts as $key => $value) {
                                    $this->service->prepareAccountData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync);
                                }
                                $return_response = true;
                            } else {
                                $return_response = true;
                            }
                            $x++;
                            $page = $page + $pageLimit;
                        } else {
                            $error = $this->handleResponseError($apicall);
                            $return_response = $error ? $error : "API Error";
                            $x = 3;
                        }
                    } while ($x <= 2);
                } else {
                    //if initial sync is set =0
                    $this->service->setStatus($user_id, $user_integration_id, $this->platformId, $objectId);
                    $x = 1;
                    $page = 1;
                    $pageLimit = 100;
                    do {
                        $arguments = [
                            "query" => "select * from Account orderBy Id startPosition {$page} maxResults {$pageLimit}",
                        ];
                        $apicall = $this->APICALL($account, "GET", "query", $arguments);
                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                            $accounts = isset($apicall['body']['QueryResponse']['Account']) ? $apicall['body']['QueryResponse']['Account'] : [];
                            if (count($accounts) > 0) {

                                foreach ($accounts as $key => $value) {
                                    $this->service->prepareAccountData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync);
                                }
                                $return_response = true;
                            } else {
                                $return_response = true;
                            }
                            $x++;
                            $page = $page + $pageLimit;
                        } else {
                            $error = $this->handleResponseError($apicall);
                            $return_response = $error ? $error : "API Error";
                            $x = 3;
                        }
                    } while ($x <= 2);
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksApiController - getAccounts - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Get TaxRates*/
    public function getTaxRates($user_id = null, $user_integration_id = null, $is_initial_sync = 0, $account = null)
    {
        $return_response = true;
        try {
            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                $objectId = $this->helper->getObjectId('taxrate');

                $page = 1;
                $pageLimit = 100;
                $arguments = [
                    "query" => "select * from TaxRate orderBy Id startPosition {$page} maxResults {$pageLimit}",
                ];
                $apicall = $this->APICALL($account, "GET", "query", $arguments);

                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {

                    $rates = isset($apicall['body']['QueryResponse']['TaxRate']) ? $apicall['body']['QueryResponse']['TaxRate'] : [];
                    if (count($rates) > 0) {
                        $this->service->setStatus($user_id, $user_integration_id, $this->platformId, $objectId);
                        foreach ($rates as $key => $value) {
                            $this->service->prepareTaxRateData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync);
                        }
                        $return_response = true;
                    } else {
                        $return_response = true;
                    }
                } else {
                    $error = $this->handleResponseError($apicall);
                    $return_response = $error ? $error : "API Error";
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksApiController - getTaxRate - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Get TaxRates*/
    public function getTaxCodes($user_id = null, $user_integration_id = null, $is_initial_sync = 0, $account = null)
    {
        $return_response = true;
        try {
            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                $objectId = $this->helper->getObjectId('taxcode');

                $page = 1;
                $pageLimit = 100;
                $arguments = [
                    "query" => "select * from TaxCode orderBy Id startPosition {$page} maxResults {$pageLimit}",
                ];
                $apicall = $this->APICALL($account, "GET", "query", $arguments);

                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {

                    $taxCodes = isset($apicall['body']['QueryResponse']['TaxCode']) ? $apicall['body']['QueryResponse']['TaxCode'] : [];
                    if (count($taxCodes) > 0) {
                        $this->service->setStatus($user_id, $user_integration_id, $this->platformId, $objectId);
                        foreach ($taxCodes as $key => $value) {
                            $this->service->prepareTaxCodeData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync);
                        }
                        $return_response = true;
                    } else {
                        $return_response = true;
                    }
                } else {
                    $error = $this->handleResponseError($apicall);
                    $return_response = $error ? $error : "API Error";
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksApiController - getTaxCodes - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Get Service Items */
    public function getServiceItems($user_id = null, $user_integration_id = null)
    {
        $return_response = true;
        try {
            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                $objectId = $this->helper->getObjectId('service_item');
                $this->service->setStatus($user_id, $user_integration_id, $this->platformId, $objectId);

                $arguments = ["query" => "select * from Item where Type= 'Service'"];
                $result = $this->APICALL($account, "GET", "query", $arguments);
                if (isset($result['body']['QueryResponse']['Item'])) {
                    $Items = isset($result['body']['QueryResponse']['Item']) ? $result['body']['QueryResponse']['Item'] : [];
                    if (count($Items)) {
                        foreach ($Items as $Item) {
                            $this->service->prepareServiceItemData($Item, $objectId, $user_id, $user_integration_id);
                        }
                        $return_response = true;
                    } else {
                        $return_response = true;
                    }
                } else {
                    $error = $this->handleResponseError($result);
                    $return_response = $error ? $error : "API Error";
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> QuickBooksApiController -> getServiceItems -> ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Mutate purchase orders from source platform */
    public function syncPurchaseOrderBill($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $platform_workflow_rule_id = null, $source_platform_name = null, $sync_status = "Ready", $record_id = NULL, $account = null)
    {
        $return_response = true;
        $allow_bill_process = false;
        date_default_timezone_set("US/Eastern");
        if (env('APP_ENV') == "stag") { //always run
            $allow_bill_process = true;
        } else if (env('APP_ENV') != "stag" && date('H') >= 00 && date('H') <= 02) { //for 3 hrs
            $allow_bill_process = true;
        }

        if ($allow_bill_process) {
            try {

                $limit = 20;
                if (!isset($account)) {
                    $account = $this->getPrimaryAccount($user_integration_id);
                }
                $SourcePlatformId = $this->helper->getPlatformIdByName($source_platform_name);
                $sourceAccount = $this->getPlatformAccountByUserIntegration($user_integration_id, $SourcePlatformId, ['id']);

                if ($account && $sourceAccount) {
                    /*
                        $account_number_query = NULL;
                        $account_number_query = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_account_number", ['api_id'], "default");
                        if ($account_number_query) {
                        $account_number = $account_number_query->api_id;
                        }
                    */

                    $productIdentity = $this->service->productIdentityMapping($user_integration_id, $platform_workflow_rule_id);
                    $source_identity = $productIdentity['source_identity'];
                    $destination_identity = $productIdentity['destination_identity'];


                    $bill_object_id = $this->helper->getObjectId('goods_in_note');


                    // remaining date condition

                    $query = PlatformOrder::whereHas('shipments', function ($query) use ($record_id) {
                        $query->whereDate('created_on', '=', date('Y-m-d', strtotime('now - 1day')));
                        if ($record_id) {
                            $query->whereIn('sync_status', ['Ready', 'Failed']);
                        } else {
                            $query->whereIn('sync_status', ['Ready']);
                        }
                    })->with(['order_extra_information', 'shipments' => function ($query) use ($record_id) {
                        $query->whereDate('created_on', '=', date('Y-m-d', strtotime('now - 1day')));
                        if ($record_id) {
                            $query->whereIn('sync_status', ['Ready', 'Failed']);
                        } else {
                            $query->whereIn('sync_status', ['Ready']);
                        }
                    }]);

                    if ($record_id) {
                        $query->where('id', $record_id);
                    } else {
                        $query->where([
                            'platform_id' => $SourcePlatformId,
                            'user_integration_id' => $user_integration_id,
                            'sync_status' => 'Synced',
                            'shipment_status' => $sync_status,
                            'order_type' => "PO",
                        ]);
                    }
                    $query->select('id', 'user_id', 'platform_id', 'user_integration_id', 'user_workflow_rule_id', 'platform_customer_id', 'order_type', 'api_order_id', 'order_number', 'sync_status', 'is_voided', 'is_deleted', 'linked_id', 'order_updated_at', 'updated_at');

                    $list_bill = $query->orderBy('updated_at', 'ASC')->take($limit)->get();

                    $vendorId = "";
                    foreach ($list_bill as $bills) {
                        $platform_order_id = @$bills->id;
                        $order_number = @$bills->order_number;
                        $platform_customer_id = $bills->platform_customer_id ? $bills->platform_customer_id : null;
                        $shipments = isset($bills->shipments) ? $bills->shipments : [];
                        $extra_information = isset($bills->order_extra_information) ? $bills->order_extra_information : [];

                        //PlatformOrderShipment::where('platform_order_id', $platform_order_id)->where('sync_status','<>', 'Synced')->update(['sync_status' => 'Processing']);
                        //PlatformOrder::where('id', $platform_order_id)->update(['shipment_status' => 'Processing']);

                        if ($vendorId == '') {
                            $vendorId = $this->service->findVendor($platform_customer_id, $account); //find or create vendor and vendor address

                            if (is_numeric($vendorId['vendorId'])) {
                                $vendorId = $vendorId['vendorId'];
                            } else {
                                $return_response = $vendorId['vendorId'];
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $bill_object_id, 'failed', $platform_order_id, $return_response);

                                PlatformOrderShipment::where('platform_order_id', $platform_order_id)->where('sync_status', '<>', 'Synced')->update(['sync_status' => 'Failed']);

                                PlatformOrder::where('id', $platform_order_id)->update(['shipment_status' => 'Failed']);
                            }
                        }

                        if ($vendorId != '') {
                            $shipment_primary_ids = [];
                            $ref_number = $txn_date = $failed_error = "";

                            foreach ($shipments as $bill_details) {
                                $shipment_primary_ids[] = $bill_details->id;
                                if ($ref_number == '') {
                                    $ref_number = @$bill_details->transaction_id;
                                }
                                if ($txn_date == '') {
                                    $txn_date = @$bill_details->realease_date ? date('Y-m-d', strtotime($bill_details->realease_date)) : date('Y-m-d', strtotime('now - 1day'));
                                }
                            }

                            $dest_order = PlatformOrder::where('linked_id', $platform_order_id)->select('api_order_id')->first();

                            if ($dest_order) {
                                $link_order_id = $dest_order->api_order_id;

                                $apicall = $this->APICALL($account, "GET", "purchaseorder/" . $link_order_id, ['minorversion' => 65], [], 'v3');

                                $link_order_line_detail = [];
                                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                    $purchaseorder = $apicall['body']['PurchaseOrder'];
                                    if (isset($purchaseorder['Id']) && isset($purchaseorder['Line'])) {
                                        foreach ($purchaseorder['Line'] as $linkline) {
                                            if (isset($linkline['ItemBasedExpenseLineDetail'])) {
                                                $link_order_line_detail[$linkline['ItemBasedExpenseLineDetail']['ItemRef']['value']] = $linkline['Id'];
                                            }
                                        }
                                    }
                                }

                                $shipment_lines = PlatformOrderShipmentLine::select('id', 'row_id', 'product_id', 'sku', 'price', 'quantity', 'currency', 'user_batch_reference')->whereIn('platform_order_shipment_id', $shipment_primary_ids)->get();

                                $prepareBillLine = $this->service->prepareBillLine($shipment_lines, $link_order_line_detail, $link_order_id, $user_id, $user_integration_id, $SourcePlatformId, $this->platformId, $source_identity, $destination_identity, $account);

                                if (!$prepareBillLine['productNotFound']) {
                                    $payload = [
                                        "VendorRef" => [
                                            "value" => $vendorId
                                        ],
                                        "Line" => $prepareBillLine['items'],
                                        "LinkedTxn" => [
                                            [
                                                "TxnId" => $link_order_id,
                                                "TxnType" => 'PurchaseOrder'
                                            ]
                                        ],
                                    ];
                                    $memo = "PO Number - " . $order_number;
                                    if ($prepareBillLine['memo']) { // if notes available send to private notes
                                        $memo .= "\nActual memo detail -" . strlen($prepareBillLine['memo']) > 4000 ? substr($prepareBillLine['memo'], 0, 4000) : $prepareBillLine['memo'];
                                    }
                                    $payload["PrivateNote"] = $memo;

                                    if ($ref_number) {
                                        $payload["DocNumber"] = $ref_number;
                                    }

                                    $payload["TxnDate"] = $txn_date;
                                    //$payload["DueDate"] = "2023-04-09";

                                    if (isset($extra_information->pay_terms)) {
                                        $termRef = $this->service->searchTerms($extra_information->pay_terms, $user_id, $user_integration_id, $account);
                                        if (is_numeric($termRef)) {
                                            $payload['SalesTermRef']['value'] = $termRef;
                                        }
                                    }

                                    $apicall = $this->APICALL($account, "POST", "bill", ['minorversion' => 65], $payload, 'v3');
                                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                        $bill = $apicall['body']['Bill'];
                                        if (isset($bill['Id'])) {
                                            $insertdata = [
                                                'user_id' => $user_id,
                                                'platform_id' => $this->platformId,
                                                'user_integration_id' => $user_integration_id,
                                                'type' => 'POShipment',
                                                'platform_order_id' => $platform_order_id,
                                                'shipment_id' => $bill['Id'],
                                                'created_on' => date("Y-m-d H:i:s", strtotime($bill['MetaData']['CreateTime'])),
                                                'sync_status' => 'Pending',
                                                'linked_id' => $shipment_primary_ids[0] //$shipment_primary_id,
                                            ];

                                            $billLinkedId = PlatformOrderShipment::insertGetId($insertdata);

                                            PlatformOrderShipment::whereIn('id', $shipment_primary_ids)->update(['sync_status' => 'Synced', 'linked_id' => $billLinkedId]);
                                            PlatformOrder::where('id', $platform_order_id)->update(['shipment_status' => 'Synced']);

                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $bill_object_id, 'success', $platform_order_id, null);

                                            // updating to link with po
                                            $payload['Id'] = $bill['Id'];
                                            $payload['SyncToken'] = "0";
                                            $apicalllink = $this->APICALL($account, "POST", "bill", ['minorversion' => 65], $payload, 'v3');
                                            if (isset($apicalllink['status_code']) && $apicalllink['status_code'] == 200) {
                                            } else {
                                                $error = "Link Error : " . $this->handleResponseError($apicall);
                                                $return_response = $failed_error = $error ? $error : "API Error";
                                            }
                                        }
                                    } else {
                                        $error = $this->handleResponseError($apicall);
                                        $return_response = $failed_error = $error ? $error : "API Error";
                                    }
                                } else {
                                    $return_response = $failed_error = "Some of line items are not found";
                                }

                                if ($failed_error != '') {
                                    PlatformOrderShipment::where('platform_order_id', $platform_order_id)->where('sync_status', '<>', 'Synced')->update(['sync_status' => 'Failed']);

                                    PlatformOrder::where('id', $platform_order_id)->update(['shipment_status' => 'Failed']);

                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $bill_object_id, 'failed', $platform_order_id, $failed_error);
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::error('QuickBooksApiController - syncPurchaseOrderBill - ' . $e->getLine() . " -> " . $e->getMessage());
                $return_response = $e->getMessage();
            }
        }
        return $return_response;
    }
    public function syncPurchaseOrderBillTest($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $platform_workflow_rule_id = null, $source_platform_name = null, $sync_status = "Ready", $record_id = NULL, $account = null)
    {
        $return_response = true;
        $allow_bill_process = true;
        date_default_timezone_set("US/Eastern");
        if (env('APP_ENV') == "stag") { //always run
            $allow_bill_process = true;
        } else if (env('APP_ENV') != "stag" && date('H') >= 00 && date('H') <= 02) { //for 3 hrs
            $allow_bill_process = true;
        }

        if ($allow_bill_process) {
            try {

                $limit = 20;
                if (!isset($account)) {
                    $account = $this->getPrimaryAccount($user_integration_id);
                }
                $SourcePlatformId = $this->helper->getPlatformIdByName($source_platform_name);
                $sourceAccount = $this->getPlatformAccountByUserIntegration($user_integration_id, $SourcePlatformId, ['id']);

                if ($account && $sourceAccount) {
                    /*
                        $account_number_query = NULL;
                        $account_number_query = $this->mapping->getMappedDataByName($user_integration_id, NULL, "default_account_number", ['api_id'], "default");
                        if ($account_number_query) {
                        $account_number = $account_number_query->api_id;
                        }
                    */

                    $productIdentity = $this->service->productIdentityMapping($user_integration_id, $platform_workflow_rule_id);
                    $source_identity = $productIdentity['source_identity'];
                    $destination_identity = $productIdentity['destination_identity'];


                    $bill_object_id = $this->helper->getObjectId('goods_in_note');


                    // remaining date condition

                    $query = PlatformOrder::whereHas('shipments', function ($query) use ($record_id) {
                        // $query->whereDate('created_on', '=', date('Y-m-d', strtotime('now - 1day')));
                        if ($record_id) {
                            $query->whereIn('sync_status', ['Ready', 'Failed']);
                        } else {
                            $query->whereIn('sync_status', ['Ready']);
                        }
                    })->with(['order_extra_information', 'shipments' => function ($query) use ($record_id) {
                        //  $query->whereDate('created_on', '=', date('Y-m-d', strtotime('now - 1day')));
                        if ($record_id) {
                            $query->whereIn('sync_status', ['Ready', 'Failed']);
                        } else {
                            $query->whereIn('sync_status', ['Ready']);
                        }
                    }]);

                    if ($record_id) {
                        $query->where('id', $record_id);
                    } else {
                        $query->where([
                            'platform_id' => $SourcePlatformId,
                            'user_integration_id' => $user_integration_id,
                            'sync_status' => 'Synced',
                            'shipment_status' => $sync_status,
                            'order_type' => "PO",
                        ]);
                    }
                    $query->select('id', 'user_id', 'platform_id', 'user_integration_id', 'user_workflow_rule_id', 'platform_customer_id', 'order_type', 'api_order_id', 'order_number', 'sync_status', 'is_voided', 'is_deleted', 'linked_id', 'order_updated_at', 'updated_at');

                    $list_bill = $query->orderBy('updated_at', 'ASC')->take($limit)->get();
                    //dd( $list_bill);
                    $vendorId = "";
                    foreach ($list_bill as $bills) {
                        $platform_order_id = @$bills->id;
                        $order_number = @$bills->order_number;
                        $platform_customer_id = $bills->platform_customer_id ? $bills->platform_customer_id : null;
                        $shipments = isset($bills->shipments) ? $bills->shipments : [];
                        $extra_information = isset($bills->order_extra_information) ? $bills->order_extra_information : [];

                        //PlatformOrderShipment::where('platform_order_id', $platform_order_id)->where('sync_status','<>', 'Synced')->update(['sync_status' => 'Processing']);
                        //PlatformOrder::where('id', $platform_order_id)->update(['shipment_status' => 'Processing']);

                        if ($vendorId == '') {
                            $vendorId = $this->service->findVendor($platform_customer_id, $account); //find or create vendor and vendor address

                            if (is_numeric($vendorId['vendorId'])) {
                                $vendorId = $vendorId['vendorId'];
                            } else {
                                $return_response = $vendorId['vendorId'];
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $bill_object_id, 'failed', $platform_order_id, $return_response);

                                PlatformOrderShipment::where('platform_order_id', $platform_order_id)->where('sync_status', '<>', 'Synced')->update(['sync_status' => 'Failed']);

                                PlatformOrder::where('id', $platform_order_id)->update(['shipment_status' => 'Failed']);
                            }
                        }

                        if ($vendorId != '') {
                            $shipment_primary_ids = [];
                            $ref_number = $txn_date = $failed_error = "";

                            foreach ($shipments as $bill_details) {
                                $shipment_primary_ids[] = $bill_details->id;
                                if ($ref_number == '') {
                                    $ref_number = @$bill_details->transaction_id;
                                }
                                if ($txn_date == '') {
                                    $txn_date = @$bill_details->realease_date ? date('Y-m-d', strtotime($bill_details->realease_date)) : date('Y-m-d', strtotime('now - 1day'));
                                }
                            }

                            $dest_order = PlatformOrder::where('linked_id', $platform_order_id)->select('api_order_id')->first();

                            if ($dest_order) {
                                $link_order_id = $dest_order->api_order_id;

                                $apicall = $this->APICALL($account, "GET", "purchaseorder/" . $link_order_id, ['minorversion' => 65], [], 'v3');

                                $link_order_line_detail = [];
                                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                    $purchaseorder = $apicall['body']['PurchaseOrder'];
                                    if (isset($purchaseorder['Id']) && isset($purchaseorder['Line'])) {
                                        foreach ($purchaseorder['Line'] as $linkline) {
                                            if (isset($linkline['ItemBasedExpenseLineDetail'])) {
                                                $link_order_line_detail[$linkline['ItemBasedExpenseLineDetail']['ItemRef']['value']] = $linkline['Id'];
                                            }
                                        }
                                    }
                                }

                                $shipment_lines = PlatformOrderShipmentLine::select('id', 'row_id', 'product_id', 'sku', 'price', 'quantity', 'currency', 'user_batch_reference')->whereIn('platform_order_shipment_id', $shipment_primary_ids)->get();

                                $prepareBillLine = $this->service->prepareBillLineTest($shipment_lines, $link_order_line_detail, $link_order_id, $user_id, $user_integration_id, $source_platform_name, $SourcePlatformId, $source_identity, $destination_identity, $account);
                                // dd($prepareBillLine);
                                if (!$prepareBillLine['productNotFound']) {
                                    $payload = [
                                        "VendorRef" => [
                                            "value" => $vendorId
                                        ],
                                        "Line" => $prepareBillLine['items'],
                                        "LinkedTxn" => [
                                            [
                                                "TxnId" => $link_order_id,
                                                "TxnType" => 'PurchaseOrder'
                                            ]
                                        ],
                                    ];
                                    $memo = "PO Number - " . $order_number;
                                    if ($prepareBillLine['memo']) { // if notes available send to private notes
                                        $memo .= "\nActual memo detail -" . strlen($prepareBillLine['memo']) > 4000 ? substr($prepareBillLine['memo'], 0, 4000) : $prepareBillLine['memo'];
                                    }
                                    $payload["PrivateNote"] = $memo;

                                    if ($ref_number) {
                                        $payload["DocNumber"] = $ref_number;
                                    }

                                    $payload["TxnDate"] = $txn_date;
                                    //$payload["DueDate"] = "2023-04-09";

                                    if (isset($extra_information->pay_terms)) {
                                        $termRef = $this->service->searchTerms($extra_information->pay_terms, $user_id, $user_integration_id, $account);
                                        if (is_numeric($termRef)) {
                                            $payload['SalesTermRef']['value'] = $termRef;
                                        }
                                    }

                                    $apicall = $this->APICALL($account, "POST", "bill", ['minorversion' => 65], $payload, 'v3');
                                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                        $bill = $apicall['body']['Bill'];
                                        if (isset($bill['Id'])) {
                                            $insertdata = [
                                                'user_id' => $user_id,
                                                'platform_id' => $this->platformId,
                                                'user_integration_id' => $user_integration_id,
                                                'type' => 'POShipment',
                                                'platform_order_id' => $platform_order_id,
                                                'shipment_id' => $bill['Id'],
                                                'created_on' => date("Y-m-d H:i:s", strtotime($bill['MetaData']['CreateTime'])),
                                                'sync_status' => 'Pending',
                                                'linked_id' => $shipment_primary_ids[0] //$shipment_primary_id,
                                            ];

                                            $billLinkedId = PlatformOrderShipment::insertGetId($insertdata);

                                            PlatformOrderShipment::whereIn('id', $shipment_primary_ids)->update(['sync_status' => 'Synced', 'linked_id' => $billLinkedId]);
                                            PlatformOrder::where('id', $platform_order_id)->update(['shipment_status' => 'Synced']);

                                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $bill_object_id, 'success', $platform_order_id, null);

                                            // updating to link with po
                                            $payload['Id'] = $bill['Id'];
                                            $payload['SyncToken'] = "0";
                                            $apicalllink = $this->APICALL($account, "POST", "bill", ['minorversion' => 65], $payload, 'v3');
                                            if (isset($apicalllink['status_code']) && $apicalllink['status_code'] == 200) {
                                            } else {
                                                $error = "Link Error : " . $this->handleResponseError($apicall);
                                                $return_response = $failed_error = $error ? $error : "API Error";
                                            }
                                        }
                                    } else {
                                        $error = $this->handleResponseError($apicall);
                                        $return_response = $failed_error = $error ? $error : "API Error";
                                    }
                                } else {
                                    $return_response = $failed_error = "Some of line items are not found";
                                }

                                if ($failed_error != '') {
                                    PlatformOrderShipment::where('platform_order_id', $platform_order_id)->where('sync_status', '<>', 'Synced')->update(['sync_status' => 'Failed']);

                                    PlatformOrder::where('id', $platform_order_id)->update(['shipment_status' => 'Failed']);

                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $bill_object_id, 'failed', $platform_order_id, $failed_error);
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::error('QuickBooksApiController - syncPurchaseOrderBill - ' . $e->getLine() . " -> " . $e->getMessage());
                $return_response = $e->getMessage();
            }
        }
        return $return_response;
    }

    /* Get custom fields for PO */
    public function getCustomFields($user_id = null, $user_integration_id = null, $is_initial_sync = 0, $fieldType = "purchase_order", $account = null)
    {
        $return_response = true;
        try {
            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                if ($fieldType == "purchase_order") {
                    $objectId = $this->helper->getObjectId('purchase_order');
                    if ($is_initial_sync) { // get products by chunks in loop when initial sync=1
                        //if initial sync is set =0
                        $page = 1;
                        $pageLimit = 100;
                        $arguments = [
                            "query" => "select * from Preferences startPosition {$page} maxResults {$pageLimit}",
                        ];

                        $apicall = $this->APICALL($account, "GET", "query", $arguments);

                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                            $this->service->setStatus($user_id, $user_integration_id, $this->platformId, $objectId);
                            $Preferences = isset($apicall['body']['QueryResponse']['Preferences']) ? $apicall['body']['QueryResponse']['Preferences'] : [];

                            if (count($Preferences) > 0) {

                                foreach ($Preferences as $key => $vendorCustomFields) {

                                    if (isset($vendorCustomFields['VendorAndPurchasesPrefs']['POCustomField'])) {
                                        $vendorCustomField = $vendorCustomFields['VendorAndPurchasesPrefs']['POCustomField'];

                                        foreach ($vendorCustomField as $key => $CustomField) {

                                            foreach ($CustomField as $cffields) {
                                                foreach ($cffields as $value) {
                                                    $this->service->prepareCustomFieldData($value, $objectId, 'purchase_order', $user_id, $user_integration_id, $is_initial_sync);
                                                }
                                            }
                                        }
                                    }
                                }
                                $return_response = true;
                            } else {
                                $return_response = true;
                            }
                        } else {
                            $error = $this->handleResponseError($apicall);
                            $return_response = $error ? $error : "API Error";
                        }
                    } else {
                        //if initial sync is set =0
                        $page = 1;
                        $pageLimit = 100;
                        $arguments = [
                            "query" => "select * from Preferences startPosition {$page} maxResults {$pageLimit}",
                        ];

                        $apicall = $this->APICALL($account, "GET", "query", $arguments);

                        if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                            $this->service->setStatus($user_id, $user_integration_id, $this->platformId, $objectId);
                            $Preferences = isset($apicall['body']['QueryResponse']['Preferences']) ? $apicall['body']['QueryResponse']['Preferences'] : [];

                            if (count($Preferences) > 0) {

                                foreach ($Preferences as $key => $vendorCustomFields) {

                                    if (isset($vendorCustomFields['VendorAndPurchasesPrefs']['POCustomField'])) {
                                        $vendorCustomField = $vendorCustomFields['VendorAndPurchasesPrefs']['POCustomField'];

                                        foreach ($vendorCustomField as $key => $CustomField) {

                                            foreach ($CustomField as $cfkey => $cffields) {
                                                foreach ($cffields as $cfkkey => $value) {
                                                    $this->service->prepareCustomFieldData($value, $objectId, 'purchase_order', $user_id, $user_integration_id, $is_initial_sync);
                                                }
                                            }
                                        }
                                    }
                                }
                                $return_response = true;
                            } else {
                                $return_response = true;
                            }
                        } else {
                            $error = $this->handleResponseError($apicall);
                            $return_response = $error ? $error : "API Error";
                        }
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksApiController - getCustomFields - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Sync Vendors */
    public function syncVendors($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $platform_workflow_rule_id = null, $source_platform_name = null, $sync_status = "Ready", $record_id = NULL, $account = null)
    {
        $return_response = true;
        try {
            $recordExist = 0;
            $limit = 20;
            if (!isset($account)) {
                $account = $this->getPrimaryAccount($user_integration_id);
            }
            $sourcePlatformId = $this->helper->getPlatformIdByName($source_platform_name);
            $sourceAccount = $this->getPlatformAccountByUserIntegration($user_integration_id, $sourcePlatformId, ['id']);
            if ($account && $sourceAccount) {

                $query = PlatformCustomer::with(['linkedCustomer', 'extraInfo'])->select('id', 'user_id', 'user_integration_id', 'api_customer_id', 'api_customer_code', 'customer_name', 'first_name', 'last_name', 'company_name', 'phone', 'fax', 'email', 'address1', 'address2', 'address3', 'postal_addresses', 'country', 'sync_status', 'type', 'linked_id', 'is_deleted');
                if ($record_id) {
                    $query->where('id', $record_id);
                } else {
                    $query->where([
                        'platform_id' => $sourcePlatformId,
                        'user_integration_id' => $user_integration_id,
                        'sync_status' => $sync_status,
                        'type' => "Vendor",
                    ]);
                }
                $list = $query->orderBy('updated_at', 'ASC')->take($limit)->get();

                if (!empty($list) && count($list) > 0) {
                    $vendor_object_id = $this->helper->getObjectId('vendor');
                    $recordExist = 1;
                    foreach ($list as $value) {
                        $syncVendor = true;
                        $logStatus = "success";
                        $syncStatus = "Synced";
                        $response = null;
                        /* Find Primary Key */
                        $vendor_primary_id = isset($value->id) ? $value->id : NULL;
                        $payload = [
                            //"Title" => $value->customer_name,
                            "GivenName" => $value->customer_name,
                            "BillAddr" => [
                                "City" => $value->address2,
                                "Country" => $value->country,
                                "Line1" => $value->address1,
                                "PostalCode" => $value->postal_addresses,
                                "CountrySubDivisionCode" => $value->address3
                            ],
                        ];
                        if ($value->phone) {
                            $payload['PrimaryPhone']['FreeFormNumber'] = $value->phone;
                        }
                        if ($value->email) {
                            $payload['PrimaryEmailAddr']['Address'] = $value->email;
                        }
                        if ($value->company_name) {
                            $payload['CompanyName'] = $value->company_name;
                        }
                        if ($value->fax) {
                            $payload['Fax']['FreeFormNumber'] = $value->fax;
                        }

                        if (isset($value->extraInfo->pay_terms)) {
                            $termRef = $this->service->searchTerms($value->extraInfo->pay_terms, $user_id, $user_integration_id, $account);
                            if (is_numeric($termRef)) {
                                $payload['TermRef']['value'] = $termRef;
                            } else {
                                $syncVendor = false;
                                $return_response = $response = "No valid payment term found";
                                $logStatus = "failed";
                                $syncStatus = "Failed";
                            }
                        }

                        $vendorData = [];
                        if ($syncVendor) {
                            if (isset($value->linkedCustomer->id)) { //If linked_id vendor found
                                $vendorData['vendorId'] = $value->linkedCustomer->api_customer_id;
                                $vendorData['vendorPrimaryId'] = $value->linkedCustomer->id;
                                $findVendorData = $this->service->findVendorByID($value->linkedCustomer->api_customer_id, $user_id, $user_integration_id, $account);
                                $syncToken = !empty($findVendorData['syncToken']) ? $findVendorData['syncToken'] : $value->linkedCustomer->api_customer_code;
                                $vendorData['syncToken'] = $syncToken;
                            } else {
                                $vendorData = $this->service->searchVendorOrCreateOrUpdateAndStore($value, [], "search", $account); //find or search by Api
                            }

                            if (is_numeric($vendorData['vendorId']) && is_numeric($vendorData['vendorPrimaryId'])) { //update QB Vendor
                                $payload["Id"] = $vendorData['vendorId'];
                                $syncToken = 0;
                                if (isset($value->linkedCustomer->api_customer_code)) {
                                    $syncToken = $value->linkedCustomer->api_customer_code;
                                }
                                $payload['SyncToken'] = !empty($vendorData['syncToken']) ? $vendorData['syncToken'] : $syncToken;
                                $vendorData = $this->service->searchVendorOrCreateOrUpdateAndStore($value, $payload, "update", $account);

                                if (is_numeric($vendorData['vendorId']) && is_numeric($vendorData['vendorPrimaryId'])) {
                                    $response = null;
                                    $logStatus = "success";
                                    $syncStatus = "Synced";
                                } else {
                                    $return_response = $response = $vendorData['vendorId'];
                                    $logStatus = "failed";
                                    $syncStatus = "Failed";
                                }
                            } else {
                                /* Create vendor */
                                $vendorData = $this->service->searchVendorOrCreateOrUpdateAndStore($value, $payload, "create", $account);
                                if (is_numeric($vendorData['vendorId']) && is_numeric($vendorData['vendorPrimaryId'])) {
                                    $response = null;
                                    $logStatus = "success";
                                    $syncStatus = "Synced";
                                } else {
                                    $return_response = $response = $vendorData['vendorId'];
                                    $logStatus = "failed";
                                    $syncStatus = "Failed";
                                }
                            }
                        }

                        $value->sync_status = $syncStatus;
                        $value->linked_id = !empty($vendorData['vendorPrimaryId']) ? $vendorData['vendorPrimaryId'] : 0;
                        $value->save();
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $sourcePlatformId, $this->platformId, $vendor_object_id, $logStatus, $vendor_primary_id, $response);
                    }
                }
                if ($recordExist == 0) {
                    $return_response = "Record not exist";
                }
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksApiController - syncVendors - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Sync Customers */
    public function syncCustomers($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $source_platform_name = null, $record_id = NULL)
    {
        $return_response = true;
        try {
            $recordExist = 0;
            $limit = 25;
            $account = $this->getPrimaryAccount($user_integration_id);
            $sourcePlatformId = $this->helper->getPlatformIdByName($source_platform_name);
            $sourceAccount = $this->getPlatformAccountByUserIntegration($user_integration_id, $sourcePlatformId, ['id']);
            if ($account && $sourceAccount) {
                $query = PlatformCustomer::with(['linkedCustomer', 'extraInfo'])->select('id', 'user_id', 'user_integration_id', 'api_customer_id', 'api_customer_code', 'customer_name', 'first_name', 'last_name', 'company_name', 'phone', 'fax', 'email', 'address1', 'address2', 'address3', 'postal_addresses', 'country', 'sync_status', 'type', 'linked_id', 'is_deleted')
                    //->where(['user_integration_id' => $user_integration_id, 'platform_id' => $sourcePlatformId, 'type' => 'Customer', 'linked_id' => 0, 'is_deleted' => 0]);
                    ->where(['user_integration_id' => $user_integration_id, 'platform_id' => $sourcePlatformId, 'type' => 'Customer', 'is_deleted' => 0]);
                if ($record_id) {
                    $query->where('id', $record_id);
                } else {
                    $query->where('sync_status', 'Ready');
                }
                $list = $query->orderBy('updated_at', 'ASC')->take($limit)->get();

                if (!empty($list) && count($list) > 0) {
                    $customer_object_id = $this->helper->getObjectId('customer');
                    $recordExist = 1;
                    foreach ($list as $value) {
                        $syncCustomer = true;
                        $logStatus = "success";
                        $syncStatus = "Synced";
                        $response = null;
                        /* Find Primary Key */
                        $customer_primary_id = isset($value->id) ? $value->id : NULL;
                        $payload = [
                            "GivenName" => $value->customer_name,
                            "BillAddr" => [
                                "City" => $value->address2,
                                "Country" => $value->country,
                                "Line1" => $value->address1,
                                "PostalCode" => $value->postal_addresses,
                                "CountrySubDivisionCode" => $value->address3
                            ],
                        ];
                        if ($value->phone) {
                            $payload['PrimaryPhone']['FreeFormNumber'] = $value->phone;
                        }

                        if ($value->email) {
                            $payload['PrimaryEmailAddr']['Address'] = $value->email;
                        }

                        if ($value->company_name) {
                            $payload['CompanyName'] = $value->company_name;
                        }

                        if ($value->fax) {
                            $payload['Fax']['FreeFormNumber'] = $value->fax;
                        }

                        if (isset($value->extraInfo->pay_terms)) {
                            $termRef = $this->service->searchTerms($value->extraInfo->pay_terms, $user_id, $user_integration_id, $account);
                            if (is_numeric($termRef)) {
                                $payload['TermRef']['value'] = $termRef;
                            } else {
                                $syncCustomer = false;
                                $return_response = $response = "No valid payment term found";
                                $logStatus = "failed";
                                $syncStatus = "Failed";
                            }
                        }

                        $customerData = [];
                        if ($syncCustomer) {
                            if (isset($value->linkedCustomer->id)) { //If linked_id customer found
                                $customerData['customerId'] = $value->linkedCustomer->api_customer_id;
                                $customerData['customerPrimaryId'] = $value->linkedCustomer->id;
                                $findCustomerData = $this->service->findCustomerByID($value->linkedCustomer->api_customer_id, $user_id, $user_integration_id, $account);
                                $syncToken = !empty($findCustomerData['syncToken']) ? $findCustomerData['syncToken'] : $value->linkedCustomer->api_customer_code;
                                $customerData['syncToken'] = $syncToken;
                            } else {
                                $customerData = $this->service->searchCustomerOrCreateOrUpdateAndStore($value, [], "search", $account); //find or search by Api
                            }

                            if (is_numeric($customerData['customerId']) && is_numeric($customerData['customerPrimaryId'])) { //update QB Customer
                                $payload["Id"] = $customerData['customerId'];
                                $syncToken = 0;
                                if (isset($value->linkedCustomer->api_customer_code)) {
                                    $syncToken = $value->linkedCustomer->api_customer_code;
                                }
                                $payload['SyncToken'] = !empty($customerData['syncToken']) ? $customerData['syncToken'] : $syncToken;
                                $customerData = $this->service->searchCustomerOrCreateOrUpdateAndStore($value, $payload, "update", $account);

                                if (is_numeric($customerData['customerId']) && is_numeric($customerData['customerPrimaryId'])) {
                                    $response = null;
                                    $logStatus = "success";
                                    $syncStatus = "Synced";
                                } else {
                                    $return_response = $response = $customerData['customerId'];
                                    $logStatus = "failed";
                                    $syncStatus = "Failed";
                                }
                            } else {
                                /* Create Customer */
                                $customerData = $this->service->searchCustomerOrCreateOrUpdateAndStore($value, $payload, "create", $account);
                                if (is_numeric($customerData['customerId']) && is_numeric($customerData['customerPrimaryId'])) {
                                    $response = null;
                                    $logStatus = "success";
                                    $syncStatus = "Synced";
                                } else {
                                    $return_response = $response = $customerData['customerId'];
                                    $logStatus = "failed";
                                    $syncStatus = "Failed";
                                }
                            }
                        }

                        $value->sync_status = $syncStatus;
                        $value->linked_id = !empty($customerData['customerPrimaryId']) ? $customerData['customerPrimaryId'] : 0;
                        $value->save();
                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $sourcePlatformId, $this->platformId, $customer_object_id, $logStatus, $customer_primary_id, $response);
                    }
                }
                if ($recordExist == 0) {
                    $return_response = "Record not exist";
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> QuickBooksApiController -> syncCustomers -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Get getTerms*/
    public function getTerms($user_id = null, $user_integration_id = null, $is_initial_sync = 0, $account = null)
    {
        $return_response = false;
        try {
            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                $objectId = $this->helper->getObjectId('pay_terms');
                if ($is_initial_sync) { // get products by chunks in loop when initial sync=1
                    //if initial sync is set =0
                    $page = 1;
                    $pageLimit = 100;
                    $arguments = [
                        "query" => "select * from Term orderBy Id startPosition {$page} maxResults {$pageLimit}",
                    ];
                    $apicall = $this->APICALL($account, "GET", "query", $arguments);

                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {

                        $rates = isset($apicall['body']['QueryResponse']['Term']) ? $apicall['body']['QueryResponse']['Term'] : [];
                        if (count($rates) > 0) {
                            $this->service->setStatus($user_id, $user_integration_id, $this->platformId, $objectId);
                            foreach ($rates as $key => $value) {
                                $this->service->prepareTermData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync);
                            }
                            $return_response = true;
                        } else {
                            $return_response = true;
                        }
                    } else {
                        $error = $this->handleResponseError($apicall);
                        $return_response = $error ? $error : "API Error";
                    }
                } else {
                    //if initial sync is set =0
                    $page = 1;
                    $pageLimit = 100;
                    $arguments = [
                        "query" => "select * from Term orderBy Id startPosition {$page} maxResults {$pageLimit}",
                    ];
                    $apicall = $this->APICALL($account, "GET", "query", $arguments);

                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $rates = isset($apicall['body']['QueryResponse']['Term']) ? $apicall['body']['QueryResponse']['Term'] : [];
                        if (count($rates) > 0) {
                            $this->service->setStatus($user_id, $user_integration_id, $this->platformId, $objectId);
                            foreach ($rates as $key => $value) {
                                $this->service->prepareTermData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync);
                            }
                            $return_response = true;
                        } else {
                            $return_response = true;
                        }
                    } else {
                        $error = $this->handleResponseError($apicall);
                        $return_response = $error ? $error : "API Error";
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksApiController - getTerms - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Sync products & Inventory*/
    public function syncProductsAndInventory($type = 'PRODUCT', $user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $platform_workflow_rule_id = null, $source_platform_name = null, $sync_status = 'Ready', $record_id = NULL)
    {
        $return_response = true;
        try {
            $DefaultTimezone = $this->mapping->getMappedDataByName($user_integration_id, NULL, 'timezone', ['custom_data'], 'default');
            if ($DefaultTimezone && $DefaultTimezone->custom_data) {
                $regional_timezone =  EsRegionalTimeZone::select('time_zone')->where('id', $DefaultTimezone->custom_data)->first();
                if ($regional_timezone) {
                    date_default_timezone_set($regional_timezone->time_zone);
                }
            }

            //Pull product stock after 02 AM
            if (in_array(date('H'), ['00', '01']) && $type == 'INVENTORY' && !$record_id) {
                return $return_response;
            }

            $recordExist = 0;
            $limit = 150;

            $account = $this->getPrimaryAccount($user_integration_id);
            $sourcePlatformId = $this->helper->getPlatformIdByName($source_platform_name);

            if ($account && $sourcePlatformId) {
                $query = PlatformProduct::with('linkedProduct')->select('id', 'product_name', 'sku', 'price', 'description', 'inventory_sync_status', 'linked_id', 'api_inventory_lastmodified_time');

                if ($record_id) {
                    $query->where('id', $record_id);
                } else {
                    $condition = ['user_integration_id' => $user_integration_id, 'platform_id' => $sourcePlatformId];
                    if ($type == 'PRODUCT') {
                        $condition['product_sync_status'] = $sync_status;
                    } else {
                        $query->whereNotNull('api_inventory_lastmodified_time')->whereDate('api_inventory_lastmodified_time', '=', date('Y-m-d'));

                        $condition['inventory_sync_status'] = $sync_status;
                    }

                    $query->where($condition);
                }

                $platform_products = $query->orderBy('updated_at', 'ASC')->take($limit)->get()->toArray();
                if (count($platform_products)) {
                    $recordExist = 1;

                    $income_account_ref = $asset_account_ref = $expense_account_ref = '';
                    $income_account_ref_query = $this->mapping->getMappedDataByName($user_integration_id, NULL, 'default_income_account_ref', ['api_id'], 'default');
                    if ($income_account_ref_query) {
                        $income_account_ref = $income_account_ref_query->api_id;
                    }

                    $asset_account_ref_query = $this->mapping->getMappedDataByName($user_integration_id, NULL, 'default_asset_account_ref', ['api_id'], 'default');
                    if ($asset_account_ref_query) {
                        $asset_account_ref = $asset_account_ref_query->api_id;
                    }

                    $expense_account_ref_query = $this->mapping->getMappedDataByName($user_integration_id, NULL, 'default_expense_account_ref', ['api_id'], 'default');
                    if ($expense_account_ref_query) {
                        $expense_account_ref = $expense_account_ref_query->api_id;
                    }

                    if ($type == 'PRODUCT') {
                        $mutate_object_id = $this->helper->getObjectId('product');
                    } else {
                        $mutate_object_id = $this->helper->getObjectId('inventory');
                    }

                    $chunk_platform_products = array_chunk($platform_products, 30);
                    foreach ($chunk_platform_products as $list) {
                        $PlatformProductIds = [];

                        $source_identity_data = [];
                        foreach ($list as $value) {
                            $PlatformProductIds[] = $value['id'];

                            if ($value['sku']) {
                                $source_identity_data[] = $value['sku'];
                            }
                        }

                        $Existing_QB_Item = [];
                        if (count($source_identity_data)) {
                            $source_identity_data = array_unique($source_identity_data);
                            sort($source_identity_data);

                            $implode_sku = implode(',', array_map(function ($value) {
                                return "'" . $value . "'";
                            }, $source_identity_data));

                            $arguments = ["query" => "select * from Item where Type = 'Inventory' and Sku in (" . $implode_sku . ")"];
                            $sku_collection = $this->APICALL($account, "GET", "query", $arguments);
                            if (isset($sku_collection['status_code']) && $sku_collection['status_code'] == 200) {
                                $Items = isset($sku_collection['body']['QueryResponse']['Item']) ? $sku_collection['body']['QueryResponse']['Item'] : [];
                                foreach ($Items as $Item) {
                                    $Existing_QB_Item[$Item['Sku']] = $Item;
                                }
                            }
                        }

                        $sync_status_field = ($type == 'PRODUCT') ? 'product_sync_status' : 'inventory_sync_status';
                        if (count($PlatformProductIds)) {
                            PlatformProduct::whereIn('id', $PlatformProductIds)
                                ->update([$sync_status_field => 'Processing']);
                        }

                        $BatchMutateRequest = [];
                        foreach ($list as $value) {
                            $mutate_item = [];
                            /* Find Primary Key */
                            $product_primary_id = $value['id'];
                            $mutate_item = ['TrackQtyOnHand' => true, 'Name' => $value['product_name'], 'Type' => 'Inventory'];

                            if ($value['sku']) {
                                $mutate_item['Sku'] = $value['sku'];
                            }

                            if ($value['description']) {
                                $mutate_item['Description'] = $value['description'];
                            }

                            if ($value['price']) {
                                $mutate_item['PurchaseCost'] = $value['price'];
                            }

                            if ($type == 'PRODUCT' && $value['linked_id'] == 0) {
                                //create new product
                                $mutate_item['InvStartDate'] = date('Y-m-d');
                                $mutate_item['QtyOnHand'] = 0;
                            } else {
                                //When Product ON In create/update case it will go
                                $inventory = PlatformProductInventory::select(DB::raw("SUM(quantity) as total_quantity"))->where('platform_product_id', $product_primary_id)->first();
                                if ($inventory) {
                                    $mutate_item['QtyOnHand'] = $inventory->total_quantity;
                                    $mutate_item['InvStartDate'] = $value['api_inventory_lastmodified_time'] ? date('Y-m-d', strtotime($value['api_inventory_lastmodified_time'])) : date('Y-m-d');
                                }
                            }

                            if (isset($Existing_QB_Item[$value['sku']])) {
                                $mutate_item['Id'] = $Existing_QB_Item[$value['sku']]['Id'];
                                $mutate_item['SyncToken'] = $Existing_QB_Item[$value['sku']]['SyncToken'];

                                if (isset($Existing_QB_Item[$value['sku']]['AssetAccountRef']['value']) && $Existing_QB_Item[$value['sku']]['AssetAccountRef']['value']) {
                                    $mutate_item['AssetAccountRef']['value'] = $Existing_QB_Item[$value['sku']]['AssetAccountRef']['value'];
                                }

                                if (isset($Existing_QB_Item[$value['sku']]['IncomeAccountRef']['value']) && $Existing_QB_Item[$value['sku']]['IncomeAccountRef']['value']) {
                                    $mutate_item['IncomeAccountRef']['value'] = $Existing_QB_Item[$value['sku']]['IncomeAccountRef']['value'];
                                }

                                if (isset($Existing_QB_Item[$value['sku']]['ExpenseAccountRef']['value']) && $Existing_QB_Item[$value['sku']]['ExpenseAccountRef']['value']) {
                                    $mutate_item['ExpenseAccountRef']['value'] = $Existing_QB_Item[$value['sku']]['ExpenseAccountRef']['value'];
                                }

                                $BatchMutateRequest[] = array("Item" => $mutate_item, "bId" => $product_primary_id, "operation" => "update");
                            } elseif ($type == 'PRODUCT') {
                                /* Create Product */
                                if ($asset_account_ref) {
                                    $mutate_item['AssetAccountRef']['value'] = $asset_account_ref;
                                }

                                if ($income_account_ref) {
                                    $mutate_item['IncomeAccountRef']['value'] = $income_account_ref;
                                }

                                if ($expense_account_ref) {
                                    $mutate_item['ExpenseAccountRef']['value'] = $expense_account_ref;
                                }

                                $BatchMutateRequest[] = array("Item" => $mutate_item, "bId" => $product_primary_id, "operation" => "create");
                            }
                        }

                        if (count($BatchMutateRequest)) {
                            $mutate_payload = array('BatchItemRequest' => $BatchMutateRequest);

                            $mutate_result = $this->APICALL($account, "POST", "batch", ['minorversion' => 65], $mutate_payload, 'v3');
                            if (isset($mutate_result['status_code']) && $mutate_result['status_code'] == 200) {
                                $BatchItemResponses = isset($mutate_result['body']['BatchItemResponse']) ? $mutate_result['body']['BatchItemResponse'] : [];
                                foreach ($BatchItemResponses as $BatchItemResponse) {
                                    $product_primary_id = $BatchItemResponse['bId'];
                                    $QbProductPrimaryId = NULL;
                                    if (isset($BatchItemResponse['Item'])) {
                                        $response = null;
                                        $logStatus = 'success';
                                        $syncStatus = 'Synced';

                                        $Item = $BatchItemResponse['Item'];
                                        $Item['linked_id'] = $product_primary_id;
                                        $QbProductPrimaryId = $this->service->prepareProductData($Item, $user_id, $user_integration_id, 0);
                                    } else {
                                        $logStatus = 'failed';
                                        $syncStatus = 'Failed';

                                        $error = $this->handleBatchResponseError($BatchItemResponse);
                                        $return_response = $response = $error ? $error : 'API Error';
                                    }

                                    $item_sync_info = [];
                                    if ($type == 'PRODUCT') {
                                        $item_sync_info['product_sync_status'] = $syncStatus;
                                    } else {
                                        PlatformProductInventory::where('platform_product_id', $product_primary_id)
                                            ->update(['sync_status' => $syncStatus]);
                                        $item_sync_info['inventory_sync_status'] = $syncStatus;
                                    }

                                    if ($QbProductPrimaryId) {
                                        $item_sync_info['linked_id'] = $QbProductPrimaryId;
                                    }

                                    PlatformProduct::where('id', $product_primary_id)
                                        ->update($item_sync_info);

                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $sourcePlatformId, $this->platformId, $mutate_object_id, $logStatus, $product_primary_id, $response);
                                }
                            }

                            //\Storage::disk('local')->append('QuickBooks/' . date('Y-m-d') . '_product_inventory.txt', 'DateTime: ' . date('Y-m-d H:i:s') . ', Request: ' . json_encode($mutate_payload) . ', Response: ' . json_encode($mutate_result));
                        }

                        if (count($PlatformProductIds)) {
                            PlatformProduct::whereIn('id', $PlatformProductIds)->where([$sync_status_field => 'Processing'])
                                ->update([$sync_status_field => 'Ignore']);
                        }
                    }
                }

                if ($recordExist == 0) {
                    $return_response = 'Record not exist.';
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> QuickBooksApiController -> syncProductsAndInventory -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Dummy Url */
    public function disconnectQboOnline(Request $request)
    {
        $return_response = true;
        try {
            \Storage::disk('local')->append(date('d-m-Y') . '_disconnectQboOnline.txt', json_encode($request->all()));
        } catch (\Exception $e) {
            \Log::error('QuickBooksApiController - disconnectQboOnline - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Sync Invoice */
    public function syncInvoice($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $platform_workflow_rule_id = null, $source_platform_name = null, $record_id = NULL)
    {
        $return_response = true;
        try {
            $recordExist = 0;
            $limit = 20;
            $account = $this->getPrimaryAccount($user_integration_id);
            $SourcePlatformId = $this->helper->getPlatformIdByName($source_platform_name);
            $sourceAccount = $this->getPlatformAccountByUserIntegration($user_integration_id, $SourcePlatformId, ['id']);
            if ($account && $sourceAccount) {
                $query = PlatformInvoice::with(['platformInvoiceLine', 'platformCustomer'])->select('id', 'platform_customer_id', 'order_doc_number', 'api_invoice_id', 'invoice_date', 'order_doc_number', 'tracking_number', 'due_date', 'is_dropship', 'linked_id', 'is_deleted');
                if ($record_id) {
                    $query->where('id', $record_id);
                } else {
                    $query->where(['user_integration_id' => $user_integration_id, 'platform_id' => $SourcePlatformId, 'sync_status' => 'Ready']);
                }
                $platform_invoices = $query->orderBy('updated_at', 'ASC')->take($limit)->get();

                if (count($platform_invoices)) {
                    $invoice_object_id = $this->helper->getObjectId('invoice');
                    $recordExist = 1;

                    $default_invoice_service_item_id = NULL;
                    $default_invoice_service_item = $this->mapping->getMappedDataByName($user_integration_id, NULL, 'default_invoice_service_item', ['api_id'], 'default');
                    if ($default_invoice_service_item) {
                        $default_invoice_service_item_id = $default_invoice_service_item->api_id;
                    }

                    $default_invoice_taxcode_id = NULL;
                    $default_invoice_taxcode = $this->mapping->getMappedDataByName($user_integration_id, NULL, 'default_invoice_taxcode', ['api_id'], 'default');
                    if ($default_invoice_taxcode) {
                        $default_invoice_taxcode_id = $default_invoice_taxcode->api_id;
                    } else {
                        $default_invoice_taxcode_id = 'NON';
                    }

                    foreach ($platform_invoices as $platform_invoice) {
                        $error_message = NULL;
                        if (!$platform_invoice->linked_id) {
                            $invoiceLines = isset($platform_invoice->platformInvoiceLine) ? $platform_invoice->platformInvoiceLine : null;
                            if ($invoiceLines) {
                                $customer = $this->service->findCustomer($platform_invoice->platform_customer_id, $account); //find or create customer
                                if (is_numeric($customer['customerId'])) {
                                    $items = $this->service->prepareInvoiceLine($platform_invoice, $user_integration_id, $platform_workflow_rule_id, $default_invoice_service_item_id);
                                    if (count($items)) {
                                        $payload = [
                                            'Line' => $items,
                                            'TxnDate' => date('Y-m-d', strtotime($platform_invoice->invoice_date)),
                                            'CustomerRef' => [
                                                'value' => $customer['customerId']
                                            ],
                                            'DocNumber' => $platform_invoice->order_doc_number,
                                            'TxnTaxDetail' => [
                                                'TxnTaxCodeRef' => ['value' => $default_invoice_taxcode_id]
                                            ]
                                        ];

                                        if ($platform_invoice->tracking_number) {
                                            $payload['TrackingNum'] = $platform_invoice->tracking_number;
                                        }

                                        if ($platform_invoice->due_date) {
                                            $payload['DueDate'] = date('Y-m-d', strtotime($platform_invoice->due_date));
                                        }

                                        if (isset($customer['email']) && $customer['email']) { // if customer email available
                                            $payload["BillEmail"]["Address"] = $customer['email'];
                                        }

                                        if (isset($customer['customerAddress']) && count($customer['customerAddress'])) { // if customer address available
                                            $payload["ShipAddr"] = $customer['customerAddress'];
                                            $payload["BillAddr"] = $customer['customerAddress'];
                                        }

                                        \Storage::disk('local')->append(date("Y-m-d") . '_syncInvoiceQB.txt', 'Date: ' . date("Y-m-d H:i:s") . ', Payload: ' . json_encode($payload) . PHP_EOL);

                                        $result = $this->APICALL($account, 'POST', 'invoice', ['minorversion' => 65], $payload, 'v3');
                                        if (isset($result['status_code']) && $result['status_code'] == 200) {
                                            $invoice = $result['body']['Invoice'];
                                            if (isset($invoice['Id'])) {
                                                /* Insert invoice details */
                                                $linkPlatformInvoice = PlatformInvoice::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_invoice_id' => $invoice['Id'], 'invoice_code' => $invoice['SyncToken'], 'invoice_date' => date('Y-m-d H:i:s', strtotime($invoice['MetaData']['CreateTime'])), 'order_doc_number' => $invoice['DocNumber'], 'ref_number' => $invoice['DocNumber'], 'linked_id' => $platform_invoice->id, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

                                                $platform_invoice->sync_status = 'Synced';
                                                $platform_invoice->updated_at = date('Y-m-d H:i:s');
                                                $platform_invoice->linked_id = $linkPlatformInvoice->id;
                                                $platform_invoice->save();

                                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $invoice_object_id, 'success', $platform_invoice->id, null);

                                                $platform_invoice_transactions = PlatformInvoiceTransaction::select('id', 'transaction_amount')->where('platform_invoice_id', $platform_invoice->id)->where('sync_status', 'Ready')->get();
                                                if (count($platform_invoice_transactions)) {
                                                    foreach ($platform_invoice_transactions as $platform_invoice_transaction) {
                                                        $payload = [
                                                            'TotalAmt' => $platform_invoice_transaction->transaction_amount,
                                                            'CustomerRef' => ['value' => $customer['customerId']],
                                                            'Line' => [
                                                                [
                                                                    'Amount' => $platform_invoice_transaction->transaction_amount,
                                                                    'LinkedTxn' => [
                                                                        ['TxnId' => $invoice['Id'], 'TxnType' => 'Invoice']
                                                                    ]
                                                                ]
                                                            ]
                                                        ];

                                                        $result = $this->APICALL($account, 'POST', 'payment', ['minorversion' => 65], $payload, 'v3');
                                                        if (isset($result['status_code']) && $result['status_code'] == 200) {
                                                            $payment = $result['body']['Payment'];
                                                            if (isset($payment['Id'])) {
                                                                /* Insert invoice payment details */
                                                                $linkPlatformInvoiceTransaction = PlatformInvoiceTransaction::create(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_invoice_id' => $platform_invoice->linked_id, 'transaction_amount' => $platform_invoice_transaction->transaction_amount, 'api_transaction_index_id' => $payment['Id'], 'transaction_id' => $payment['Id'], 'linked_id' => $platform_invoice_transaction->id, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

                                                                $platform_invoice_transaction->sync_status = 'Synced';
                                                                $platform_invoice_transaction->updated_at = date('Y-m-d H:i:s');
                                                                $platform_invoice_transaction->linked_id = $linkPlatformInvoiceTransaction->id;
                                                                $platform_invoice_transaction->save();
                                                            }
                                                        } else {
                                                            $error = $this->handleResponseError($result);
                                                            $error_message = $error ? $error : 'API Error';
                                                        }
                                                    }
                                                }
                                            }
                                        } else {
                                            $error = $this->handleResponseError($result);
                                            $error_message = $error ? $error : 'API Error';
                                        }
                                    } else {
                                        $error_message = 'Some of line items are not found.';
                                    }
                                } else {
                                    $error_message = $customer['customerId'];
                                }
                            } else {
                                $error_message = 'No line items found for order.';
                            }
                        } else {
                            if (!$platform_invoice->is_dropship) {
                                $platform_invoice_transactions = PlatformInvoiceTransaction::select('id', 'transaction_amount')->where('platform_invoice_id', $platform_invoice->id)->where('sync_status', 'Ready')->get();
                                if (count($platform_invoice_transactions)) {
                                    $destination_platform_invoice = PlatformInvoice::select('api_invoice_id')->where('id', $platform_invoice->linked_id)->first();
                                    $destination_platform_customer = PlatformCustomer::select('api_customer_id')->where('id', $platform_invoice->platformCustomer->linked_id)->first();
                                    if ($destination_platform_invoice && $destination_platform_customer) {
                                        foreach ($platform_invoice_transactions as $platform_invoice_transaction) {
                                            $payload = [
                                                'TotalAmt' => $platform_invoice_transaction->transaction_amount,
                                                'CustomerRef' => ['value' => $destination_platform_customer->api_customer_id],
                                                'Line' => [
                                                    [
                                                        'Amount' => $platform_invoice_transaction->transaction_amount,
                                                        'LinkedTxn' => [
                                                            ['TxnId' => $destination_platform_invoice->api_invoice_id, 'TxnType' => 'Invoice']
                                                        ]
                                                    ]
                                                ]
                                            ];

                                            $result = $this->APICALL($account, 'POST', 'payment', ['minorversion' => 65], $payload, 'v3');
                                            if (isset($result['status_code']) && $result['status_code'] == 200) {
                                                $payment = $result['body']['Payment'];
                                                if (isset($payment['Id'])) {
                                                    /* Insert invoice payment details */
                                                    $linkPlatformInvoiceTransaction = PlatformInvoiceTransaction::create(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_invoice_id' => $platform_invoice->linked_id, 'transaction_amount' => $platform_invoice_transaction->transaction_amount, 'api_transaction_index_id' => $payment['Id'], 'transaction_id' => $payment['Id'], 'linked_id' => $platform_invoice_transaction->id, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

                                                    $platform_invoice_transaction->sync_status = 'Synced';
                                                    $platform_invoice_transaction->updated_at = date('Y-m-d H:i:s');
                                                    $platform_invoice_transaction->linked_id = $linkPlatformInvoiceTransaction->id;
                                                    $platform_invoice_transaction->save();
                                                }
                                            } else {
                                                $error = $this->handleResponseError($result);
                                                $error_message = $error ? $error : 'API Error';
                                            }
                                        }
                                    } else {
                                        $error_message = 'Linked customer or invoice record not available.';
                                    }
                                }

                                if ($error_message == NULL) {
                                    $platform_invoice->sync_status = 'Synced';
                                    $platform_invoice->updated_at = date('Y-m-d H:i:s');
                                    $platform_invoice->save();

                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $invoice_object_id, 'success', $platform_invoice->id, null);
                                }
                            } else {
                                $platform_invoice_transaction = PlatformInvoiceTransaction::select('id', 'transaction_amount')->where('platform_invoice_id', $platform_invoice->id)->where('sync_status', 'Synced')->first();
                                if (is_null($platform_invoice_transaction)) {
                                    $destination_platform_invoice = PlatformInvoice::select('api_invoice_id')->where('id', $platform_invoice->linked_id)->first();
                                    if ($destination_platform_invoice) {
                                        $result = $this->APICALL($account, 'GET', 'invoice/' . $destination_platform_invoice->api_invoice_id, ['minorversion' => 65], [], 'v3');
                                        if (isset($result['status_code']) && $result['status_code'] == 200) {
                                            $invoice = $result['body']['Invoice'];
                                            if (isset($invoice['Id'])) {
                                                /* Delete Invoice Payload */
                                                $payload = ['SyncToken' => $invoice['SyncToken'], 'Id' => $invoice['Id']];
                                                $result1 = $this->APICALL($account, 'POST', 'invoice', ['minorversion' => 65, 'operation' => 'delete'], $payload, 'v3');
                                                if (isset($result1['status_code']) && $result1['status_code'] == 200) {
                                                    $invoice1 = $result1['body']['Invoice'];
                                                    if (isset($invoice1['status']) && $invoice1['status'] == 'Deleted') {
                                                        $platform_invoice->sync_status = 'Ignore';
                                                        $platform_invoice->is_deleted = 1;
                                                        $platform_invoice->updated_at = date('Y-m-d H:i:s');
                                                        $platform_invoice->save();
                                                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $invoice_object_id, 'success', $platform_invoice->id, null);
                                                    }
                                                } else {
                                                    $error = $this->handleResponseError($result1);
                                                    $error_message = $error ? $error : 'API Error';
                                                }
                                            }
                                        } else {
                                            $error = $this->handleResponseError($result);
                                            $error_message = $error ? $error : 'API Error';
                                        }
                                    }
                                } else {
                                    $platform_invoice->sync_status = 'Synced';
                                    $platform_invoice->updated_at = date('Y-m-d H:i:s');
                                    $platform_invoice->save();

                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $invoice_object_id, 'success', $platform_invoice->id, null);
                                }
                            }
                        }

                        if ($error_message) {
                            $platform_invoice->sync_status = 'Failed';
                            $platform_invoice->updated_at = date('Y-m-d H:i:s');
                            $platform_invoice->save();

                            $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $invoice_object_id, 'failed', $platform_invoice->id, $error_message);

                            $return_response = $error_message;
                        }
                    }
                }

                if ($recordExist == 0) {
                    $return_response = 'Record not exist';
                }
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> QuickBooksApiController -> syncInvoice -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
    }

    /* Execute QuickBook Method */
    public function ExecuteQuickBooksEvents($method = null, $event = null, $destination_platform_id = null, $user_id = null, $user_integration_id = null, $is_initial_sync = 0, $user_workflow_rule_id = null, $source_platform_id = null, $platform_workflow_rule_id = null, $record_id = NULL)
    {
        $response = true;
        \Storage::disk('local')->append(date('d-m-Y') . '_QBEventCall.txt', "UserIntegrationID: " . $user_integration_id . " Method: " . $method . " Event: " . $event . " RunTime: " . date('d-m-Y H:i:s'));

        if ($method == 'GET' && $event == 'PRODUCT') {
            $response = $this->getProducts($user_id, $user_integration_id, $is_initial_sync);
        } else if ($method == 'GET' && $event == 'SERVICEITEM') {
            $response = $this->getServiceItems($user_id, $user_integration_id);
        } else if ($method == 'GET' && $event == 'VENDOR') {
            $response = $this->getVendors($user_id, $user_integration_id, $is_initial_sync);
        } else if ($method == 'GET' && $event == 'CUSTOMER') {
            $response = $this->getCustomers($user_id, $user_integration_id, $is_initial_sync);
        } else if ($method == 'GET' && $event == 'TAXCODE') {
            $response = $this->getTaxCodes($user_id, $user_integration_id, $is_initial_sync);
        } else if ($method == 'GET' && $event == 'ACCOUNT') {
            $response = $this->getAccounts($user_id, $user_integration_id, $is_initial_sync);
        } else if ($method == 'GET' && $event == 'PURCHASEORDERCUSTOMFIELDS') {
            $response = $this->getCustomFields($user_id, $user_integration_id, $is_initial_sync, 'purchase_order');
        } else if ($method == 'MUTATE' && $event == 'PURCHASEORDER') {
            $sync_status = 'Ready';
            if ($user_integration_id == 548) {
                $response = $this->syncPurchaseOrderTest($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
            } else {
                $response = $this->syncPurchaseOrder($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
            }
        } else if ($method == 'MUTATE' && $event == 'VENDOR') {
            $sync_status = 'Ready';
            $response = $this->syncVendors($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
        } else if ($method == 'MUTATE' && $event == 'CUSTOMER') {
            $response = $this->syncCustomers($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id);
        } else if ($method == 'MUTATE' && $event == 'SALESORDER') {
            $sync_status = 'Ready';

            if ($user_integration_id == 548) {
                $response = $this->syncOrderShipmentTest($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
            } else {
                $response = $this->syncOrderShipment($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
            }
        } else if ($method == 'MUTATE' && $event == 'BILL') {
            $sync_status = 'Ready';
            if ($user_integration_id == 548) {
                $response = $this->syncPurchaseOrderBillTest($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
            } else {
                $response = $this->syncPurchaseOrderBill($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
            }
        } else if ($method == 'GET' && $event == 'TERMS') {
            $response = $this->getTerms($user_id, $user_integration_id, $is_initial_sync);
        } else if ($method == 'MUTATE' && $event == 'PRODUCT') {
            $sync_status = 'Ready';
            $response = $this->syncProductsAndInventory('PRODUCT', $user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
        } else if ($method == 'MUTATE' && $event == 'INVENTORY') {
            $sync_status = 'Ready';
            $response = $this->syncProductsAndInventory('INVENTORY', $user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
        } else if ($method == 'MUTATE' && $event == 'INVOICE') {
            $response = $this->syncInvoice($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $record_id);
        }
        return $response;
    }

    public function test(Request $request)
    {
        $account = $this->getPrimaryAccount(548);
        $user_integration_id = 548;
        $userId = $user_id = Session::get('user_data')['id'];
        $pageLimit = 100;
        //
        // $arguments = [
        //     "query" => "select * from Item where Type='Group'",
        // ];
        // $journal_channel_id=19144;
        // $JournalEntryDate=date('Y-m-d');
        // $SourcePlatformId=47;
        // $platform_order_ids = PlatformOrderTransaction::join('platform_order', function ($join) use ($user_integration_id, $SourcePlatformId) {
        //     $join->on('platform_order_transactions.platform_order_id', '=', 'platform_order.id')
        //         ->where(['platform_order.user_integration_id' => $user_integration_id, 'platform_order.platform_id' => $SourcePlatformId, 'platform_order.order_type' => 'SO', 'platform_order.linked_id' => 0, 'platform_order.is_fully_synced' => 0]);
        // })->join('platform_order_additional_information', 'platform_order.id', '=', 'platform_order_additional_information.platform_order_id')
        //     ->select('platform_order_transactions.platform_order_id')
        //     ->whereDate('platform_order_transactions.created_at', '=', $JournalEntryDate)
        //     ->where(function ($query) use ($journal_channel_id) {
        //         $query->where('platform_order_additional_information.api_channel_id', $journal_channel_id);
        //     })
        //     ->groupBy('platform_order_transactions.platform_order_id')
        //     ->pluck('platform_order_transactions.platform_order_id')->toArray();
        //     dd($platform_order_ids);
        $dateTime = Carbon::createFromTimestamp(1690954834998)->toDateTimeString();


        dd($dateTime);
        dd($this->syncOrderShipmentTest($user_id, $user_integration_id, 1170, 164, "skubana", "Ready", NULL, $account));
        // $apicall = $this->productList($account, $arguments);
        // dd(  $apicall );
        dd($this->getProducts($user_id, $user_integration_id, 1));

        // $account = $this->getPrimaryAccount($user_integration_id);
        // $apicall = $this->productList($account, $arguments);
        // dd($apicall);
        // $journal_channel_ids = $this->mapping->getMappedDataByName(548, NULL, "journal_channel_filter", ['api_id'], 'regular', null, 'multiple');
        //dd($journal_channel_ids);
        // dd($this->syncPurchaseOrderTest($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $source_platform_id, $sync_status, $record_id));
        //    dd($this->syncProductsAndInventory('PRODUCT', $user_id, $user_integration_id, 1171, 165, "skubana", "Ready", null));
        // dd($this->syncOrderShipmentTest(549, 548 , 1170, 164,  "skubana",  "Ready", 3244285));
        //   dd($this->syncPurchaseOrderBillTest($user_id, $user_integration_id , 1172, 166, "skubana", "Ready", null));
        $record_id = 3704891;
        $query = PlatformOrder::leftJoin('platform_order_additional_information', 'platform_order.id', '=', 'platform_order_additional_information.platform_order_id')
            ->select('platform_order.id', 'platform_order.user_id', 'platform_order.platform_id', 'platform_order.user_integration_id', 'platform_order.user_workflow_rule_id', 'platform_order.platform_customer_id', 'platform_order.order_type', 'platform_order.api_order_id', 'platform_order.order_number', 'platform_order.sync_status', 'platform_order.is_voided', 'platform_order.is_deleted', 'platform_order.linked_id', 'platform_order.order_updated_at', 'platform_order.updated_at', 'platform_order.warehouse_id', 'platform_order.order_date', 'platform_order.api_order_reference', 'platform_order.allow_check', 'platform_order.linked_api_order_id', 'platform_order.shipping_total', 'platform_order.notes', 'platform_order.file_name', 'platform_order.order_number', 'platform_order.customer_email', 'platform_order.shipping_method', 'platform_order.total_discount', 'platform_order_additional_information.api_channel_id');

        if ($record_id) {
            $query->where('platform_order.id', $record_id);
        } else {
            $query->where([
                'platform_order.user_integration_id' => 565,
                'platform_order.platform_id' => 47,

                'platform_order.order_type' => "SO",
            ]);
        }
        $list = $query->orderBy('platform_order.updated_at', 'ASC')->take(1)->get();

        if (!empty($list) && count($list) > 0) {
            foreach ($list as $value) {
                if (isset($value->order_transaction->transaction_method)) {
                    dd($value->order_transaction);
                }
            }
        }
        dd("STOP");
        $deposit_to_account = $this->mapping->getMappedDataByName(565, NULL, "sorder_payment", ['name'], 'cross', 'B2B', 'single', 'source', ['api_id']);

        dd($this->syncOrderShipmentTest($user_id, $user_integration_id, 1170, 164, "skubana", "Ready"));
        dd($this->syncPurchaseOrderTest($user_id, $user_integration_id, 1167, 161, "skubana", "Ready"));


        $channel_object_id = $this->helper->getObjectId('channel');
        $channel = PlatformObjectData::where(['user_integration_id' => 548, 'api_id' => 19003, 'platform_object_id' => $channel_object_id, 'platform_id' => 47])->first();
        dd($channel);



        $response = $this->syncOrderShipment(556, 565, 1214, 164, "skubana", "Ready");
        dd($response);

        $account = $this->getPrimaryAccount(548);
        echo $this->encrypt_decrypt($account->access_token, 'decrypt');
        dd($account);
        //die;

        if ($request->sku) {
            $account = $this->getPrimaryAccount(604);
            $arguments = ["query" => "select * from Item where Sku = '{$request->sku}'"];

            $apicall = $this->productList($account, $arguments);
            echo '<pre>';
            print_r($apicall);
        }
        die;

        $hr = new QuickBooksHelper;
        // $this->syncProducts(109, 617, 1205, 195, "skubana", "Ready");
        // die;

        // $offset=$this->getUTCOffset("America/Los_Angeles");

        // $order=PlatformOrder::select('*',DB::raw("CONCAT(DATE_FORMAT(convert_tz(order_date,'+00:00','".$offset."'),'%Y-%m-%dT%T'),'".$offset."') as date"))->where('id',559706)->take(1)->get();
        // dd($order,$offset);
        // dd($this->getUTCOffset("America/Los_Angeles"));
        //dd($this->refreshToken($account->id));
        $user_integration_id = 668;
        $source_platform_id = 50;
        $destination_platform_id = 51;
        $source_identity = $destination_identity = "sku";
        $user_workflow_rule_id = 1187;
        $platform_workflow_rule_id = 189;
        // dd($this->service->searchTerms($extra_information->pay_terms, $user_id, $user_integration_id, $account));
        dd("STOP");
        dd($this->syncOrderShipment($user_id, $user_integration_id, 1170, 164, "skubana", "Ready"));
        // dd(app('App\Http\Controllers\QuickBooks\Helper\QuickBooksHelper')->getCustomMappingForState("AK",$user_integration_id, null, "state"));
        // $product = DB::table('platform_product as source')
        //->select('destination.id', 'destination.api_product_id', 'destination.sku', 'destination.product_name')
        //->join('platform_product as destination', function ($join) use ($source_identity, $destination_identity, $user_id, $user_integration_id, $destination_platform_id) {
        // $join->on('source.' . $source_identity,'=','destination.' . $destination_identity)->where(['destination.user_id' => $user_id, 'destination.user_integration_id'=> $user_integration_id, 'destination.platform_id' => $destination_platform_id]);
        //})->where(['source.user_id' => $user_id, 'source.user_integration_id'=> $user_integration_id, 'source.platform_id' => $source_platform_id, 'source.api_product_id'=> 14, 'source.is_deleted' => 0])->first();

        // dd($product);
        //dd($this->getAccounts($user_id, $user_integration_id, 1));

        // dd($this->refreshToken($account->id));
        // $query = PlatformCustomer::with('linkedCustomer','extraInfo')->select('id', 'user_id', 'user_integration_id', 'api_customer_id', 'api_customer_code', 'customer_name', 'first_name', 'last_name', 'company_name', 'phone', 'fax', 'email', 'address1', 'address2', 'address3', 'postal_addresses', 'country', 'sync_status', 'type', 'linked_id', 'is_deleted')->where('id', 2559640)->first();
        // dd($query);
        // dd($this->service->searchTerms("Due on receipt", $user_id, $user_integration_id, $account));
        // dd($this->getTerms($user_id , $user_integration_id, 1));
        // dd($this->syncVendors($user_id, $user_integration_id, 1188, 190, "skubana", "Ready"));
        // dd($this->getTaxCodes($user_id, $user_integration_id, 1));
        //dd($this->getTaxRates($user_id, $user_integration_id, 0));
        // dd($this->syncPurchaseOrder($user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, "skubana", "Ready"));
        //dd($this->getProducts($user_id, 616, 1));
        //dd($this->getVendors($user_id, 616, 1));
        // $purchase_object_id = $this->helper->getObjectId('purchase_order');
        // dd($field_mapping = $this->mapping->GetMappedFieldRecord($purchase_object_id, $user_integration_id, NULL, "source_row_id"));
        //dd($this->getCustomFields($user_id, 616, 0));

        dd(
            $this->encrypt_decrypt('Tzc4b056T3pXb2dSYTdIYmg2TEk3ajlTTHQyOWhtRGRaaVRYd3hYSThjOHhXdHBUYlZENHAwYU1ld3N3bDc1ZFh0d3lNTzJyZGUzakFmQzg3RXlsbVF2cVZhSDBmTW1FWU1uWGo2eGgwK0lMUzJaT3VodWxiRGRqanJXb3Z0dFREczltaFBmblBwMjk5QzN4cE9wSjIvc1hMZ3MrczUwVno4bUVIMFR1ak91bUNrQW9Sb2FlczdlcGhYOHczVCt6SG1ZSjJrcTNZUW9WWlJJd0dwNDRjZ3cxZE9Ud28vNFVub0t6b3dkSFFxUit0bmVkZy9YZVVkTW9tOXVMdTRDUzU1L05NejY0K2dJczZ4OXg2TVhETittVGNNaUhFNERkUWo5ZGUzUVdXZUhHa0w1NjB5bzRWN2srbzBvTW1SZisxOXF2Ri9iUlVNcGFIa0N3TDB2SGlNbjVXREZEY3BRNUxCVGlZc002T2hmR2h5eU93V20yTXFDellmci9FenZuUDR1L3VSZWZsbktrN1htYW42ZkFKZ2RNdnE4cWY2UlBPVE1DaGN4cFZhTlNJdEpvUUxnZkpob09PZ0VGSzBLMXNxTTRPUzJETWRxVEliUFZzdUpRZGQ1eDEwcnBLODZqenhDVEk0RGUvclZLdE1RVzMrWDhtNlcvVUpHTjg5Z2YrZGJKdGkwOXlsV3BFSUZHanQ3Y0Nqak9aMGFvcWdGQ3htSStoZkQxT0lhUDlheDVsYmpZZmM2SGpSaGc0V1JXQjZXR1ZrR0x2RmszZVZoSXRWMUQxOHFhRjRUUjlPRHF0WGtGNVVKRVJ5cVZPL25OL3VsRThha0R2cU1laDYvaEdwZVB2SlBaR3NhNG1sVWkwemcxYmdCQXQ1Z2ZVOUV6RlNkVVZFQjJqUGpEUFpXUUxMaE9ZN0lHRnU4bzRzWVEveUM0R2VhUGdJL0t1dGhuR0k2LzJKTlo1MmwzbWFiUktZMms4S0tqSWwrZjNwbmlzd0Y1N3lzb1R3dXR5Q1VKVFhxc0MxTVVaMzh4MXBQMU14WHJUM2FXdWt0bDd0UmhreWtqL0lIdDQ4c2w3K1A0em9EMDExUVFBVzFtVGZVL1JIWVBLNWVpRktFWXRSbDdWYmFyT3NFNHJnVDVzVTN0a0tmNzI2UmhKbUd0SklSeW14Qmh4ZXFYMXZqZk1GNXNIUHFaN253UWtLaWJBSjUyc0F5SGF0YlZFMU9WbFJUM0xNVHh2YU4wUEpyUmxidXVqeEV4UnpsS1lPUCtTcHgxclZwbG4xY1hSdTA1aExrMko0R0thc09rbitHSzlOeWpkVEtxc0hsV1VXQmFwSXcvSG1aVUF0ekpHcGhublNKWmV0cEtuN0xlY3VHN3k3WXVzVG9iUzRZaUJvKzRoVTNCVm5DTnppNk1CQi90NVZaV1RhN3N4N2lycTk5NnFWZDJqMFNKRFRKUVJhdEtWUGJ5dzZobnp2VzljZGZZOStmWHFSWDdFNkdJUzdDYWdtWks5SjJ6TDBkc0s4V0RBTzJucVJWd3ZFSUdXamdiSjR5TUliMWJZdXoya3NwckdSa0gzNENVbEEvWXBwQWh1bk83MTNMY0oyd0xoK1FCVHJMOVQzWU41ZjVpcEJMcDBSdE0yeXhXSGRxN3B6UVE2N3JpYlpXZE42WXBEZFBFZUtIaTArbE4rUjFiSXl3SjZZMUZWaE9QSU54dXJXM3lMNDhtZGkvTkcxdERrbEFYYklyVjBlY1ROWEU0TXRic3Z5MEV2T05CMmJKdmJETHZZQk8yTFZibm9TcWdzUGtqRmdQV2RmSHlsclk2TjVVMWJnMk5ndkpReFdqc0wvekFvMjVFU3RKcmxtTGY0cFNSSnB2dlJ0Q3VJVXZBTkF3YnZlRkdaa1RJU0V4M1hzR0hnYy85U3NoeG9objYrd0JLTmRRRDkvZk5oL244TXJ2KzYzS1BDdCtKUzA2NVd1Wml1RzRGempMMTk4UlJibVlNMTZYZ3ZRSWlNd1czMmNYWUZETnp2M20zUlZJNTdDQVlXS0daRXlNOUVickxtc0F2UUVXbTJjK2gxNFY0aEdBUVpvTlBDR2Y1aXRaWjI3NytoM2hLT1h3NzkxUTZZRzdxMDR5VEZBc2puS1J3cjJ4aisvZ2x0Vnp5Z2s4VGVzaW52emRBbWpMa2ZKQm15WkdTM3RDbHhMWUlycmJLd3BuVGxFTDBCVFZIRCtIak8rd1IwWFFQV3Yzc1MzTE9XNzd6RGRmU3ZXZi92WWk4bForQXFacE1COFg1eGgzcStwVWRYY2FGbmVzYk5jMUxzRnppa3d5UjBOdHB6N1Y4QkpNQTJrV3JacFNCNDhpYlpGc3FVRkNXS1Q0QzZ2eW9CbytvQUozell1UVFzbmpHSDY0alM3ZkR1WjVzRTd5elU4b1o4dVlXZzcwS2FNb2kwK3U4M0F4endBODVBTXZFWjA1bTZlc3YvV1pjaC9pemYwN0Z1VW1VN1ZUSitYVVo0L1F2Sm9jT1UwcnhMZ3VXaTVvWVE5ZEkyd3hMUC8wbk5SbzhVZ3B1VmR0Vm41ZW1ZcWlsODcyOTBxUzNDTWRCZGNYa29heG9BTGJUellmZm5xT3B1YURtaGlmWVdhUmlkUThFWVNxNXNZcEpGdU9IZWIrZis1eWtQNS9TTVRQc2xCclp6WC96bUtoQ1YyR0NMeFNSUElKUTVUWW1ac1lLaE9lSXRMM1lJc0lzQkMrNlhqcHhoTVgvTkZRVGpHS1l2Rjg5dVU3RWJ6UERaaVp2RXExS2doM29xanB1aFZ3c1FvampmVzA4bGVWVW9LV0VFSTVaaUJyVmpFcTVUS28xVU9FZWNFMDhCbDdNNFRMQjc5MDc0WVVVWVUwTS9ldW9zeldBbWtweUQzQ3pCSUNkV1ZmMXg4ei9vK2lpcXdYN2RsS2VhdERwcnR0dlI3aUhiZm1GRGZ5N0l6S2dsUDM4MFFwU2FCSnJGMTFadmhxUjBmYnErZnEzMWJOdVJTalpwOXFhNHM0cFRnWWlRN0s1Z3o4SlFLZXU2M3BIVnA1N3IwUC9lTkp2M05zR3pRTkh2SlpVZnc2bUhCcGtWdnFyZXAxZWsrNTZ0M1hyMUQ4RytEUysxckhJRTVCeWp2Y0U3Q2dUVFlaRk0xUW12TDNrOVE2VTg3bFc5UEZFekllUWNNNE4xZ0dzMTJtUXJYM0F6NHF4NFoxZW1pZ0VodlIzb3dkK3BJLzB1TXBIMEU5QzJFZU9XdE5qZ2Q1VkVSaDVXd3RiS0Y4Tlh0R2ltU25odDY0d0lya2Q1Mlh6V29XQzl0bzZuYmt5ODZyYU5ueFd1bndEOG53RmdyeGZ3TDZkZUEzdHhKUW1hZ0swTkNiSUE0MnNOUEc5TmFQbW01Slh4aVJCQTMzNVlLdlYzTnlQdktDR1VlOENQdVpVa3RrTHRPSVdFNUNoejBOSEl1V1ZTSlBtdEpBNmgrUytNQVRIRFljbG9MTHBhbXV1Zk4xS0hLb3dVVjFPeGpIazBwK1hYeHZVS0tQand4TEh6R3pNazBVM0RuVlNvZ0I2M3EySUFDMWFmOFJVaXpkQndBSjk4OUw5TG1Bd1ZlT2RUQ0pDc1ZQQlpxQVFEOWUveTZWZndlS2Vhd2xYRW4ycEt5dUdja1k5Uno5REpRdG0yc2hkQVFwYzdFOXIzN2ZCUDNSSzVrR3drM0NPaHM1b281c0p5V0lxSmRXODR6dFNLWEtnV2ZNOEtJd2pBRVZndk4rMmdWTTgwSXJKblVTdGlxcVE1dXhVWFZrdDdsZ1pEellwbThpeW15RithVnE0TG5xbFRoSzNUVDJ5Y2tuMHVmNXhoRVNObktTaFY5T3g3NzdFd25tNm9teXN6NUtxdVRNSy9iRUl6aHg4SlVRMXpTb1J5ejc2ZkFrNTRxVENzZ0Z2U1N3ekRmVnNWcjBicnQ2c0dvUE5RL2xGRUgyQnR4YlpzTlhEdWl3MDEyRGN6MU9XSXVFRURKanl0UEtZMmY2WFd4Ulo2dm9tbnZZTk1xNTZDZTZaTjJOb2xJWWppaVpKNHJONjFGNTdSK1pMWGd1ZzdjSmpIb3pwNGgrN3krcGNwVnlRSHVNaGRWTlZoVWpPbmlGcnFKbnYwb3ZtdVZsem9KTk55Y00wRlpXMnNsWExuYU5adDhSS2F5TGxmUWpDdEY1RGtwQ05YVXJ6VHI2aTJuTk9GQ0xNMmltajY1Z05nVHZRR1c0VzFxTTZSejFlUDF2NWhqSHpOdlFGUGxlbVlkaXNnNTRyUVIxcnZQWFNtemZLQWIrYWpnaU94Y3JMZUFaek13bnNIaFZIc2grazVOeU5RUTcvTHpJWUVvcHdlTUdUYzZ3akZrM0hPMER5NTZ5RFhvOU5iVlU2R1pQaU9GYXd4LzhZQm9QM2Q5T0NBeDd3UHk0ME43NCtrS2ZqdUZBYVJkc3VuRUpsZkRhMTM4OC85ZDc3L1MydzdpcUxVbkQrNC83UU9tb3h3bjd0RzRuSlZsd1NhTUk2MEJaQWIrNHhLS2s1dGZaU0lHUE1BRk5JdTJRc0ptTHBhZGtaTzNHeGh4c3FKSU54UEVLamZIRE0xa2hPWXhIN0UvVEZ1TUxtdnV2U0RtcXkvSm5iREFoNmJHMncvODg2dm9qcTd2VW14QjBxb0hXZVlYKzRCSTAxL005NmNibVhQaWNsZVV0bFg4NEtVVXBSeUE9PQ==', 'decrypt'),
            $this->encrypt_decrypt('F23F15BC96DB297E901E810B5C2FEC91E7071C811E123C8A3330656FE86CE6B7-1')
        );
    }
}
