<?php

namespace App\Http\Controllers\Skubana;

use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\WorkflowSnippet;
use App\Http\Controllers\Skubana\Api\SkubanaApi;
use App\Models\EsRegionalTimeZone;
use App\Models\PlatformAccount;
use App\Models\PlatformApiApp;
use App\Models\PlatformOrder;
use App\Models\PlatformProduct;
use App\Models\PlatformUrl;
use Session, Validator;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use App\Helper\Cache\CacheDecoder;

class SkubanaApiController extends SkubanaApi
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $helper, $mapping, $platformId, $log, $service, $wfsnip, $cache;
    public static $myPlatform = 'skubana';
    public function __construct()
    {
        $this->mapping = new FieldMappingHelper();
        $this->log = new Logger();
        $this->helper = new ConnectionHelper;
        $this->service = new SkubanaServiceController;
        $this->wfsnip = new WorkflowSnippet();
        $this->cache = new CacheDecoder();
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
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

        return $this->getPlatformAccountByUserIntegration($user_integration_id, $platformId);
    }

    /* Display for credentials */
    public function InitiateSkubanaAuth(Request $request)
    {
        if ($request->isMethod('get')) {
            return view("pages.apiauth.auth_skubana");
        }
    }

    /* connect credentials */
    public function ConnectSkubanaAuth(Request $request)
    {
        try {
            if ($request->isMethod('post')) {
                $validator = Validator::make($request->all(), ['account_name' => 'required']);
                if ($this->checkHtmlTags($request->all())) {
                    return back()->with('error', Lang::get('tags.validate'));
                }

                if ($validator->fails()) {
                    return back()->withErrors($validator);
                } else {
                    $account_name = $request->account_name;
                    $user_id = Session::get('user_data')['id'];
                    $env_type = isset($request->env_type) ? $request->env_type : "sandbox";
                    $type = $env_type == "sandbox" ? "s" : "p";
                    // To check whether given account is already in use or not.

                    $checkExistingAc = PlatformAccount::where(['user_id' => $user_id, 'platform_id' => $this->platformId, 'account_name' => $account_name])->count();
                    if ($checkExistingAc) {
                        return back()->with('error', 'Given account name is already in use, Try with other account name.');
                    }

                    if (!$checkExistingAc) {
                        $app = PlatformApiApp::select('client_id', 'client_secret')->where(['platform_id' => $this->platformId, 'env_type' => $env_type])->first();
                        if ($app) {
                            $params = [
                                'redirect_uri' => $this->makeUrlHttpsForProd(url('/RedirectHandlerOM')),
                                'state' => $user_id . "|" . $request->account_name . "|" . $type
                            ];
                            $this->cache->get_or_set($user_id . $this->platformId, $user_id . "|" . $request->account_name . "|" . $env_type);
                            if ($type == "p") {
                                $appLink = \Config::get('apiconfig.SkubanaLiveOauthUrl');
                            } else {
                                $appLink = \Config::get('apiconfig.SkubanaSandboxOauthUrl');
                            }
                            return redirect($appLink . "?" . http_build_query($params));
                        } else {
                            $this->cache->clear_cache_by_key($user_id . $this->platformId);
                            return back()->with('error', 'App configuration has been not found.');
                        }
                    } else {
                        $this->cache->clear_cache_by_key($user_id . $this->platformId);
                        return back()->with('error', 'Authentication Error.');
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('SkubanaApiController - ConnectSkubanaOauth - ' . $e->getLine() . " -> " . $e->getMessage());
        }
    }

    /* Get Token */
    public function RedirectHandlerOM(Request $request)
    {
        try {
            if (isset($request->code)) {
                $user_id = Session::get('user_data')['id'];
                $state = $this->cache->get_or_set($user_id . $this->platformId);
                $state_arr = explode('|', $state);
                if (isset($state_arr[0]) && isset($state_arr[1]) && isset($state_arr[2])) {
                    // Valid request
                    $user_id = $state_arr[0];
                    $accountName = $state_arr[1]; // Account name
                    $env_type = $state_arr[2]; // env
                    // $companyName = $request->realmId;
                    $app = PlatformApiApp::select('client_id', 'client_secret')->where(['platform_id' => $this->platformId, 'env_type' => $env_type])->first();
                    if ($app) {
                        $code = $request->code;
                        $client_id = $this->encrypt_decrypt($app->client_id, 'decrypt');
                        $client_secret = $this->encrypt_decrypt($app->client_secret, 'decrypt');
                        $redirect_url = $this->makeUrlHttpsForProd(url('/RedirectHandlerOM'));
                        if ($client_id && $client_secret) {
                            $curl_post_data = ([
                                'code' => $code,
                                'grant_type' => 'authorization_code',
                                'redirect_uri' => $redirect_url,
                            ]);
                            $authorization = base64_encode("$client_id:$client_secret");
                            $domain = $env_type == "sandbox" ? "demo" : "app";
                            $service_url = self::$prototype . $domain . '.' . self::$domain . "/oauth/token?" . http_build_query($curl_post_data);
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
                                        'account_name' => $accountName,
                                        'user_id' => $user_id,
                                        'platform_id' => $this->platformId,
                                        'allow_refresh' => 0,
                                        'env_type' => $env_type
                                    ];
                                    PlatformAccount::updateOrCreate(['user_id' => $user_id, 'platform_id' => $this->platformId, 'account_name' => $accountName], $OauthData);
                                } else { // When Token not found
                                    if (isset($response['body']['error_description'])) {
                                        $error = $response['body']['error_description'];
                                    } else {
                                        $error = "Something went wrong in your account";
                                    }
                                    echo '<script>alert("' . $error . '");window.close();</script>';
                                }

                                echo '<script>window.close();</script>';
                            } else {
                                if (isset($response['body']['error_description'])) {
                                    $error = $response['body']['error_description'];
                                } else {
                                    $error = "Something went wrong in your account";
                                }
                                echo '<script>alert("' . $error . '");window.close();</script>';
                            }
                        }
                    }
                }
                $this->cache->clear_cache_by_key($user_id . $this->platformId);
            } else {
                echo '<script>alert("Authentication Error");window.close();</script>';
            }
        } catch (\Exception $e) {
            \Log::error(' - QuickBooksApiController - RedirectHandlerQuickBooks - ' . $e->getLine() . " -> " . $e->getMessage());
        }
    }
    /* Get Payment type/ method name */
    public function getPaymentTypes($user_id, $user_integration_id, $is_initial_sync = 0, $account = null)
    {
        $return_response = true;
        try {
            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                $objectId = $this->helper->getObjectId('payment');
                $apicall = $this->APICALL($account, "GET", "paymenttypes", [], [], 'v1');

                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                    $this->service->setStatus($user_id, $user_integration_id, $this->platformId, $objectId);
                    $types = $apicall['body'];
                    if (count($types) > 0) {

                        foreach ($types as $key => $value) {
                            $this->service->preparePaymentTypesData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync);
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
            \Log::error('SkubanaApiController - getPaymentTypes - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
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
                                $arguments = [
                                    "page" => $page,
                                    "limit" => $pageLimit,
                                    "active" => true,
                                ];
                                $apicall = $this->productList($account, $arguments);

                                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                    $products = $apicall['body'];
                                    if (count($products)) {
                                        foreach ($products as $key => $value) {
                                            $this->service->prepareProductData($value, $user_id, $user_integration_id, $is_initial_sync);
                                        }
                                        if (isset($pageNo->url)) {
                                            $pageNo->url = $page;
                                            $pageNo->status = 0;
                                            $pageNo->save();
                                        } else {
                                            PlatformUrl::insert([
                                                'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,
                                                'url' => $page + 1,
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
                                    $return_response = !empty($error) ? $error : "API Error";
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

                    $url_name = "product_modified_range_with_page";
                    //if initial sync is set =0
                    $modifiedDateFrom = Carbon::now()->subMinutes(60)->format('Y-m-d\TH:i:s\Z'); //minus 60 from current time to get latest data
                    $modifiedDateTo = Carbon::now()->format('Y-m-d\TH:i:s\Z');
                    $page = 1;
                    $limit = 100;

                    $url_modified = PlatformUrl::select('url', 'id', 'status')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => $url_name])->first();
                    if (isset($url_modified->url) && $url_modified->url != '') {
                        $arrurl = explode('|', $url_modified->url);
                        $modifiedDateFrom = $arrurl[0];
                        $modifiedDateTo = $arrurl[1];
                        $page = $arrurl[2];
                    } else {



                        $lastDate = PlatformProduct::select('api_updated_at')->where([
                            'user_integration_id' => $user_integration_id,
                            'platform_id' => $this->platformId,
                        ])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();

                        if (isset($lastDate->api_updated_at)) {

                            $modifiedDateFrom = new \DateTime($lastDate->api_updated_at);
                            $modifiedDateFrom->modify('-1 second');
                            $modifiedDateFrom = $modifiedDateFrom->format('Y-m-d\TH:i:s\Z');
                        }
                    }

                    $arguments = [
                        "page" => $page,
                        "limit" => $limit,
                        "modifiedDateFrom" => $modifiedDateFrom,
                        "modifiedDateTo" => $modifiedDateTo,
                    ];
                    $apicall = $this->productList($account, $arguments);

                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $products = $apicall['body'];
                        if (count($products)) {
                            foreach ($products as $key => $value) {
                                $this->service->prepareProductData($value, $user_id, $user_integration_id, $is_initial_sync);
                            }
                            $return_response = true;
                        } else {
                            $return_response = "No products found";
                        }

                        if (count($products) == $limit) {
                            if ($url_modified) {
                                PlatformUrl::where(['id' => $url_modified->id])->update(['url' => $modifiedDateFrom . '|' . $modifiedDateTo . '|' . (intval($page) + 1)]);
                            } else {
                                PlatformUrl::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => $url_name, 'url' => $modifiedDateFrom . '|' . $modifiedDateTo . '|' . (intval($page) + 1)]);
                            }
                        } else {
                            if ($url_modified) {
                                PlatformUrl::where(['id' => $url_modified->id])->update(['url' => null]);
                            }
                        }
                    } else {
                        $error = $this->handleResponseError($apicall);
                        $return_response = !empty($error) ? $error : "API Error";
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('SkubanaApiController - getProducts - ' . $e->getLine() . " -> " . $e->getMessage());
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
                            $pageNo = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $user_integration_id], ['platform_id', '=', $this->platformId], ['url_name', '=', 'vendors']])->first();
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
                                $arguments = [
                                    "page" => $page,
                                    "limit" => $pageLimit,
                                    "activeOnly" => true,
                                ];
                                $apicall = $this->vendorList($account, $arguments);

                                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                    $vendors = $apicall['body'];
                                    if (count($vendors)) {
                                        foreach ($vendors as $key => $vendor) {
                                            $vendor['type'] = "Vendor";
                                            $this->service->prepareVendorData($vendor, $user_id, $user_integration_id, $is_initial_sync);
                                        }
                                        if (isset($pageNo->url)) {
                                            $pageNo->url = $page;
                                            $pageNo->status = 0;
                                            $pageNo->save();
                                        } else {
                                            PlatformUrl::insert([
                                                'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,
                                                'url' => $page + 1,
                                                'url_name' => 'vendors',
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
                                    $return_response = !empty($error) ? $error : "API Error";
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
                    $x = 1;
                    $loopBreaker = true;
                    while ($loopBreaker) {
                        if ($x <= 2) {
                            $pageNo = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $user_integration_id], ['platform_id', '=', $this->platformId], ['url_name', '=', 'vendors']])->first();
                            if (isset($pageNo->url)) {
                                if ($pageNo->url == 0 && $pageNo->status == 1) {
                                    $page = $pageNo->url + 1;
                                    $loopBreaker = true;
                                } else {
                                    $page = $pageNo->url + 1;
                                }
                            } else {
                                $page = 1;
                            }

                            if ($loopBreaker) {
                                $pageCounter = $page;
                                $pageLimit = 100;
                                $arguments = [
                                    "page" => $page,
                                    "limit" => $pageLimit
                                ];
                                $apicall = $this->vendorList($account, $arguments);

                                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                    $vendors = $apicall['body'];
                                    if (count($vendors)) {
                                        foreach ($vendors as $key => $vendor) {
                                            $vendor['type'] = "Vendor";
                                            $this->service->prepareVendorData($vendor, $user_id, $user_integration_id, 0);
                                        }
                                        if (isset($pageNo->url)) {
                                            $pageNo->url = $page;
                                            $pageNo->status = 0;
                                            $pageNo->save();
                                        } else {
                                            PlatformUrl::insert([
                                                'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,
                                                'url' => $page,
                                                'url_name' => 'vendors',
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
                                    $return_response = !empty($error) ? $error : "API Error";
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
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('SkubanaApiController - getVendors - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Get Product Stocks */
    public function getProductStocks($event, $user_id = null, $user_integration_id = null, $is_initial_sync = 0)
    {
        $return_response = true;
        try {
            $DefaultTimezone = $this->mapping->getMappedDataByName($user_integration_id, NULL, 'timezone', ['custom_data'], 'default');
            if ($DefaultTimezone && $DefaultTimezone->custom_data) {
                $regional_timezone = EsRegionalTimeZone::select('time_zone')->where('id', $DefaultTimezone->custom_data)->first();
                if ($regional_timezone) {
                    date_default_timezone_set($regional_timezone->time_zone);
                }
            }

            //Pull product stock after 02 AM
            if (env('APP_ENV') != 'stag' && in_array(date('H'), ['00', '01']) && $event == 'INVENTORY') {
                return $return_response;
            }

            $page = 1;
            $platform_url = PlatformUrl::select('id', 'url')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'total_product_stocks', 'url_filter' => date('Y-m-d')])->first();
            if ($platform_url) {
                $page = $platform_url->url;
            } else {
                $platform_url = PlatformUrl::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => 'total_product_stocks', 'url' => 1, 'allow_retain' => 1, 'url_filter' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s')]);
            }

            if ($page == 0) {
                return $return_response;
            }

            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                $loopCounter = true;

                while ($loopCounter) {
                    $collectionSkus = $namedCollectionSkus = [];
                    //limit cannot exceed 200
                    $arguments = ['page' => $page, 'limit' => 200];
                    $result = $this->APICALL($account, 'GET', 'productstocks/total', $arguments, [], 'v1.1');
                    if (isset($result['status_code']) && $result['status_code'] == 200) {
                        $product_stocks = $result['body'];
                        foreach ($product_stocks as $product_stock) {
                            $collectionSkus[$product_stock['product']['productId']] = $product_stock['product']['masterSku'];
                        }
                        if ($collectionSkus) {
                            $chunk = array_chunk($collectionSkus, 100);

                            if ($chunk) {
                                foreach ($chunk as $arr) {

                                    $splitArr = join(",", $arr);

                                    $productArguments = ['sku' => $splitArr, "limit" => 100];

                                    //\Storage::disk('local')->append(date('d-m-Y') . '_OMINVENTORY.txt', " UserIntegrationID: " . $user_integration_id . " SKU: " .  $splitArr . " RunTime: " . date('d-m-Y H:i:s'));
                                    $splitArr = null;
                                    $productresult = $this->APICALL($account, 'GET', 'products', $productArguments, [], 'v1.1');
                                    if (isset($productresult['status_code']) && $productresult['status_code'] == 200) {
                                        $products = $productresult['body'];
                                        //\Storage::disk('local')->append(date('d-m-Y') . '_OMINVENTORY.txt', "UserIntegrationID: " . $user_integration_id . " Res: " .  json_encode($products) . " RunTime: " . date('d-m-Y H:i:s'));

                                        if (count($products)) {
                                            foreach ($products as $key => $product) {
                                                $product_name = @$product['name'];
                                                $attributes = [];
                                                if ($product['parentId'] != 0 && isset($product['attributeGroups']) && count($product['attributeGroups']) > 0) {
                                                    foreach ($product['attributeGroups'] as $group) {
                                                        if (isset($group['attributes']) && count($group['attributes']) > 0) {
                                                            foreach ($group['attributes'] as $attr) {
                                                                if (isset($attr['name']) && $attr['name'] != '') {
                                                                    $attributes[] = $attr['name'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                    $product_name = trim(@$product['name'] . ' - ' . implode(', ', $attributes));
                                                }
                                                $namedCollectionSkus[$product['productId']] = $product_name;
                                                //  \Storage::disk('local')->append(date('d-m-Y') . '_OMINVENTORY.txt', "UserIntegrationID: " . $user_integration_id . " PRODUCT PID: " . $product['productId'] . " Name: " .  $product_name . " RunTime: " . date('d-m-Y H:i:s'));

                                            }
                                        } else {
                                            $return_response = "No products found";
                                        }
                                    }
                                }
                            }
                        }
                        // \Storage::disk('local')->append(date('d-m-Y') . '_OMINVENTORY.txt', " UserIntegrationID: " . $user_integration_id . " Names: " .  json_encode($namedCollectionSkus) . " RunTime: " . date('d-m-Y H:i:s'));
                        foreach ($product_stocks as $product_stock) {
                            // \Storage::disk('local')->append(date('d-m-Y') . '_OMINVENTORY.txt', "UserIntegrationID: " . $user_integration_id . " INV1 PID: " . $product['productId'] . " Name: " .  $product_stock['product']['name'] . " RunTime: " . date('d-m-Y H:i:s'));

                            if (isset($namedCollectionSkus[$product_stock['product']['productId']])) {
                                // \Storage::disk('local')->append(date('d-m-Y') . '_OMINVENTORY.txt', "UserIntegrationID: " . $user_integration_id . " INV2 PID: " . $product['productId'] . " Name: " .  $namedCollectionSkus[$product_stock['product']['productId']] . " RunTime: " . date('d-m-Y H:i:s'));

                                $product_stock['product']['name'] = $namedCollectionSkus[$product_stock['product']['productId']];
                            }
                            $this->service->prepareProductStockData($user_id, $user_integration_id, $product_stock);
                            // $platform_product_id = $this->service->prepareProductStockData($user_id, $user_integration_id, $product_stock);
                            // if (isset($product['product']['productId']) && $product['product']['productId'] && $platform_product_id) {
                            //     $collectionSkus[$product_stock['product']['masterSku']] = $platform_product_id;
                            // }
                        }
                        /* This below code is reserve to handle product name and all types of product detail to store in inventory flow */
                        // if ($collectionSkus) {
                        //     $skuList = array_keys($collectionSkus);
                        //     $skuList = implode(",", $skuList);
                        //     $productArguments = ['sku' => $skuList];
                        //     $productresult = $this->APICALL($account, 'GET', 'products', $productArguments, [], 'v1.1');
                        //     if (isset($productresult['status_code']) && $productresult['status_code'] == 200) {
                        //         $products = $productresult['body'];
                        //         if (count($products)) {
                        //             foreach ($products as $key => $prod) {
                        //                 $this->service->prepareProductData($prod, $user_id, $user_integration_id, $is_initial_sync);
                        //             }
                        //         } else {
                        //             $return_response = "No products found";
                        //         }
                        //     }
                        // }

                        if (count($product_stocks) == 200) {
                            if ($is_initial_sync) {
                                $return_response = "Page-{$page} data processed";
                            }
                            $page++;
                        } else {
                            $page = 0;
                            $loopCounter = false;
                        }

                        $platform_url->url = $page;
                        $platform_url->updated_at = date('Y-m-d H:i:s');
                        $platform_url->save();
                    } else {
                        $loopCounter = false;
                    }

                    if ($page % 2 == 0) {
                        $loopCounter = false;
                    }
                }
            } else {
                $return_response = self::$myPlatform . ' : Integration account detail not found';
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> SkubanaApiController -> getProductStocks -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* get purchase orders */
    public function getPurchaseOrders($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $is_initial_sync = 0, $destination_platform_name = null, $account = null)
    {
        $return_response = true;
        try {
            if ($is_initial_sync) {
                return $return_response;
            }

            $account = $this->getPrimaryAccount($user_integration_id);
            $destinationPlatformId = $this->helper->getPlatformIdByName($destination_platform_name);
            if ($account && $destinationPlatformId) {
                $lastDate = PlatformOrder::select('order_date')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'order_type' => 'PO'])->orderByRaw("DATE_FORMAT(order_date, '%Y-%m-%d %H-%i-%s') DESC")->first();
                if (isset($lastDate->order_date)) {
                    $createdDateFrom = $lastDate->order_date;
                } else {
                    $events = $this->wfsnip->getWorkflowEvents($user_workflow_rule_id);
                    if ($events && $events->sync_start_date) {
                        $createdDateFrom = Carbon::parse($events->sync_start_date)->format('Y-m-d\TH:i:s\Z');
                    } else {
                        $createdDateFrom = Carbon::now()->subDays(60)->format('Y-m-d\TH:i:s\Z'); //minus 60 from current time to get latest data
                    }
                }

                //$orderStatusFilter = $this->mapping->getMappedDataByName($user_integration_id, NULL, "porder_status_filter", ['api_id'],"regular", NULL, "multiple");

                $arguments = [
                    "page" => 1,
                    "limit" => 50,
                    "createdDateFrom" => $createdDateFrom,
                    "status" => "PENDING_DELIVERY" //implode(",",$orderStatusFilter)
                ];

                $apicall = $this->APICALL($account, "GET", "purchaseorders", $arguments);
                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                    $orders = $apicall['body'];

                    $purchase_object_id = $this->helper->getObjectId('purchase_order');
                    foreach ($orders as $key => $order) {
                        $this->service->prepareOrderData("PO", $order, $user_id, $user_integration_id, $user_workflow_rule_id, $purchase_object_id, $destinationPlatformId, $account);
                    }
                    $return_response = true;
                } else {
                    $error = $this->handleResponseError($apicall);
                    $return_response = !empty($error) ? $error : "API Error";
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> SkubanaApiController -> getPurchaseOrders -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* get purchase orders receipt */
    public function getPurchaseOrdersReceipt($user_id = null, $user_integration_id = null, $user_workflow_rule_id = null, $is_initial_sync = 0, $account = null)
    {
        $return_response = true;
        try {
            if ($is_initial_sync) {
                return $return_response;
            }

            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                $urlname = 'receipt_last_modified_time';

                $modifiedDateFrom = Carbon::now()->subMinutes(60)->format('Y-m-d\TH:i:s\Z'); //minus 60 from current time to get latest data
                $modifiedDateTo = Carbon::now()->format('Y-m-d\TH:i:s\Z');
                $page = 1;
                $limit = 30;

                $url_modified = PlatformUrl::select('url', 'id', 'status')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => $urlname])->first();
                if (isset($url_modified->url) && $url_modified->url != '') {
                    $arrurl = explode('|', $url_modified->url);
                    $modifiedDateFrom = $arrurl[0];
                    $modifiedDateTo = $arrurl[1];
                    $page = $arrurl[2];
                } else {
                    $lastDate = PlatformOrder::select('api_updated_at')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'order_type' => 'PO'])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();
                    if (isset($lastDate->api_updated_at)) {
                        $modifiedDateFrom = new \DateTime($lastDate->api_updated_at);
                        $modifiedDateFrom->modify('-1 second');
                        $modifiedDateFrom = $modifiedDateFrom->format('Y-m-d\TH:i:s\Z');
                    }
                }

                $arguments = [
                    "page" => $page,
                    "limit" => $limit,
                    "modifiedDateFrom" => $modifiedDateFrom,
                    "modifiedDateTo" => $modifiedDateTo,
                    "status" => "PARTIALLY_DELIVERED,FULFILLED"
                ];

                $apicall = $this->APICALL($account, "GET", "purchaseorders", $arguments);
                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                    $orders = $apicall['body'];

                    $purchase_object_id = $this->helper->getObjectId('goods_in_note');
                    foreach ($orders as $key => $order) {
                        $this->service->prepareOrderReceiptData("PO", "POShipment", $order, $user_id, $user_integration_id, $user_workflow_rule_id, $purchase_object_id, $account);
                    }

                    if (count($orders) == $limit) {
                        if ($url_modified) {
                            PlatformUrl::where(['id' => $url_modified->id])->update(['url' => $modifiedDateFrom . '|' . $modifiedDateTo . '|' . (intval($page) + 1)]);
                        } else {
                            PlatformUrl::insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => $urlname, 'url' => $modifiedDateFrom . '|' . $modifiedDateTo . '|' . (intval($page) + 1)]);
                        }
                    } else {
                        if ($url_modified) {
                            PlatformUrl::where(['id' => $url_modified->id])->update(['url' => null]);
                        }
                    }
                } else {
                    $error = $this->handleResponseError($apicall);
                    $return_response = !empty($error) ? $error : "API Error";
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error($user_integration_id . ' -> SkubanaApiController -> getPurchaseOrdersReceipt -> ' . $e->getLine() . ' -> ' . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Get Order as Shipment From SKUBANA */
    public function getOrders($user_id = null, $user_integration_id = null, $user_workflow_rule_id = 0, $is_initial_sync = 0)
    {
        $return_response = true;
        try {
            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                if (!$is_initial_sync) {
                    $urlname = 'order_shipment_last_datetime';
                    $platformUrls = PlatformUrl::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => $urlname])->select('url', 'id')->first();
                    if ($platformUrls) {

                        $shipmentCreatedFromDate = Carbon::parse(trim($platformUrls->url))->subSeconds(2)->format('Y-m-d\TH:i:s\Z');
                    } else {
                        $events = $this->wfsnip->getWorkflowEvents($user_workflow_rule_id);
                        if ($events && $events->sync_start_date) {
                            $shipmentCreatedFromDate = Carbon::parse($events->sync_start_date)->format('Y-m-d\TH:i:s\Z');
                        } else {
                            $shipmentCreatedFromDate = Carbon::now()->subMinutes(60)->format('Y-m-d\TH:i:s\Z'); //minus 60 from current time to get latest data
                        }
                    }
                    $shipmentCreatedToDate = Carbon::now()->format('Y-m-d\TH:i:s\Z');
                    $arguments = [
                        "page" => 1,
                        "limit" => 50,
                        "shipmentCreatedFromDate" => $shipmentCreatedFromDate,
                        "shipmentCreatedToDate" => $shipmentCreatedToDate,
                        //"deliveryStatus" => "DELIVERED"
                    ];
                    $apicall = $this->APICALL($account, "GET", "shipments", $arguments, [], 'v1');

                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $shipments = $apicall['body'];

                        if (count($shipments)) {
                            $shipDate = null;
                            foreach ($shipments as $key => $shipment) {
                                $shipDate = $shipment['created'];
                                $this->service->prepareOrderShipmentData("Shipment", $shipment, $user_id, $user_integration_id, $account);
                            }
                            if ($shipDate) {
                                if ($platformUrls) {
                                    $platformUrls->url = $shipDate;
                                    $platformUrls->save();
                                } else {
                                    PlatformUrl::insert(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => $urlname, 'url' => $shipDate]);
                                }
                            }
                        } else {
                            $return_response = "No shipments are found";
                        }
                    } else {
                        $error = $this->handleResponseError($apicall);
                        $return_response = !empty($error) ? $error : "API Error";
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('SkubanaApiController - getShipments - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }


        return $return_response;
    }

    /* Get Channels*/
    public function getChannels($user_id = null, $user_integration_id = null, $is_initial_sync = 0, $account = null)
    {
        $return_response = true;
        try {
            $account = $this->getPrimaryAccount($user_integration_id);
            if ($account) {
                $objectId = $this->helper->getObjectId('channel');
                $apicall = $this->APICALL($account, "GET", "saleschannels", [], [], 'v1');

                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                    $this->service->setStatus($user_id, $user_integration_id, $this->platformId, $objectId);
                    $channels = $apicall['body'];
                    if (count($channels) > 0) {

                        foreach ($channels as $key => $value) {
                            $this->service->prepareChannelData($value, $objectId, $user_id, $user_integration_id, $is_initial_sync);
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
            \Log::error('SkubanaApiController - getChannels - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }

    /* Execute Skubana Method */
    public function ExecuteSkubanaEvents($method = null, $event = null, $destination_platform_id = null, $user_id = null, $user_integration_id = null, $is_initial_sync = 0, $user_workflow_rule_id = null, $source_platform_id = null, $platform_workflow_rule_id = null, $record_id = NULL)
    {
        $response = true;
        \Storage::disk('local')->append(date('d-m-Y') . '_OMEventCall.txt', "UserIntegrationID: " . $user_integration_id . " Method: " . $method . " Event: " . $event . " RunTime: " . date('d-m-Y H:i:s'));

        if ($method == 'GET' && $event == 'PRODUCT') {
            $response = $this->getProducts($user_id, $user_integration_id, $is_initial_sync);
        } elseif ($method == 'GET' && $event == 'VENDOR') {
            $response = $this->getVendors($user_id, $user_integration_id, $is_initial_sync);
        } elseif ($method == 'GET' && $event == 'CHANNEL') {
            $response = $this->getChannels($user_id, $user_integration_id, $is_initial_sync);
        } elseif ($method == 'GET' && $event == 'PURCHASEORDER') {
            $response = $this->getPurchaseOrders($user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync, $destination_platform_id);
        } elseif ($method == 'GET' && $event == 'SALESORDER') {
            $response = $this->getOrders($user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync);
        } elseif ($method == 'GET' && $event == 'POITEMRECEIPT') {
            $response = $this->getPurchaseOrdersReceipt($user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync);
        } elseif ($method == 'GET' && $event == 'INVENTORY') {
            $response = $this->getProductStocks($event, $user_id, $user_integration_id, $is_initial_sync);
        } elseif ($method == 'GET' && $event == 'PAYMENTMETHOD') {
            $response = $this->getPaymentTypes($user_id, $user_integration_id, $is_initial_sync);
        }

        return $response;
    }

    public function test()
    {
        //$account = $this->getPrimaryAccount(548);
        //echo $this->encrypt_decrypt($account->access_token, 'decrypt');
        //die;

        $account = $this->getPrimaryAccount(604);
        $arguments = ['sku' => 'BOMBL'];
        $apicall = $this->APICALL($account, 'GET', 'product', $arguments, [], 'v1.1');
        dd($apicall);


        // dd($this->getChannels($user_id, $user_integration_id, 1));
        dd($this->getOrders(109, 616, 1190, 0));
        //dd($this->service->findVendor($vendorId,$user_id,$user_integration_id,$account));
        // dd($this->encrypt_decrypt('d0Y5NFcwbWVmQ05tSHc3QVkrTnZyUld2QzRnbFlMYjRGZExLV3owckNpNXZQdHBwdExFVkM2T21VbnVNblpTU09nbUZhWXY5SkdVaHY3RWl2U3VJWUE9PQ==','decrypt'),$this->encrypt_decrypt('YmhQUkJTeDIrdURJc3RWVGo4ODZXNHhwcXdCT010b0dOSWIrNmd5WjNhRkRRZzNtNFoxYWM0cXpyZlNKUG9aUA==','decrypt'));
        //dd($this->getPurchaseOrders(109, 616, 0));
    }
}
