<?php

namespace App\Http\Controllers\Netsuite;

use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Http\Controllers\Controller;
use DB;
use Illuminate\Http\Request;

use App\Http\Controllers\Netsuite\Api\NetsuiteRestApi;
use App\Models\PlatformAccount;
use App\Models\PlatformCustomer;
use App\Models\PlatformProduct;
use App\Models\PlatformUrl;
use Log;
use Auth;
use Carbon\Carbon;
use Lang;
use Validator;

class NetsuiteRestApiController extends NetsuiteRestApi
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public static $myPlatform = "netsuiteerp";
    public $mobj, $netsuiteApi, $helper, $log, $platformId, $mapping, $service;

    public function __construct()
    {
        //$this = new NetsuiteRestApi();
        $this->service = new NetsuiteRestApiService();
       
        $this->mapping = new FieldMappingHelper;
        $this->log = new Logger;
        $this->helper = new ConnectionHelper;
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }

    public function InitiateNSAuth(Request $request)
    {
        $platform = self::$myPlatform;
        return view("pages.apiauth.auth_netsuite", compact('platform'));
    }
    public function connectNetsuiteAuth(Request $request)
    {
        if ($request->isMethod('post')) {
            try {
                //server validation
                $data = [];
                $flag = true;
                $validator = Validator::make($request->all(), [
                    'account_name' => 'required',
                    'consumer_key' => 'required',
                    'consumer_secret' => 'required',
                    'ns_token' => 'required',
                    'ns_token_secret' => 'required',
                ]);

                if ($validator->fails()) {
                    $flag = false;
                    $data['status_code'] = 0;
                    $data['status_text'] = $validator->getMessageBag()->toArray();
                } else {
                    $user_id =  Auth::user()->id;
                    if ($this->checkHtmlTags($request->all())) {
                        $data['status_code'] = 0;
                        $data['status_text'] = Lang::get('tags.validate');
                        $flag = false;
                    } else {
                        $account = [
                            'consumerKey' => $request->consumer_key,
                            'token' => $request->ns_token,
                            'consumerSecret' => $request->consumer_secret,
                            'tokenSecret' => $request->ns_token_secret,
                            'account_name' => $request->account_name,
                            'method' => "GET",
                            'url' => "salesOrder",
                            'limit' => 1,
                            'offset' => 0,
                            'type' => "record",
                        ];

                        $validate = $this->verifyAccount($account);
                        if (isset($validate['status_code']) && $validate['status_code'] != 200) {
                            $flag = false;
                            $data['status_code'] = 0;
                            $data['status_text'] = $this->handleErrorResponse($validate);
                        } else {

                            $account_name = $request->account_name;
                            $consumer_key = $this->encrypt_decrypt($request->consumer_key);
                            $consumer_secret = $this->encrypt_decrypt($request->consumer_secret);
                            $ns_token = $this->encrypt_decrypt($request->ns_token);
                            $ns_token_secret = $this->encrypt_decrypt($request->ns_token_secret);

                            $obj_existing = PlatformAccount::select('user_id')->where(['account_name' => $account_name, 'platform_id' => $this->platformId])->first();
                            if ($obj_existing) {
                                $flag = false;
                                $data['status_code'] = 0;
                                $data['status_text'] = 'Given details are already in use, Try with other details.';
                                return json_encode($data);
                            } else {
                                $tokens = array(
                                    'user_id' => $user_id,
                                    'platform_id' => $this->platformId,
                                    'account_name' => $account_name,
                                    'api_domain' => null,
                                    'app_id' => $consumer_key,
                                    'app_secret' => $consumer_secret,
                                    'refresh_token' => $ns_token,
                                    'access_token' => $ns_token_secret
                                );
                                PlatformAccount::create($tokens);
                            }
                        }
                    }
                    if ($flag) {
                        $data['status_code'] = 1;
                        $data['status_text'] = 'Netsuite account connected successfully.';
                    }
                }
            } catch (\Exception $e) {
                $data['status_code'] = 0;
                $data['status_text'] = $e->getMessage();
            }
            return response()->json($data);
        }
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
    /* Get Vendors */
    public function getVendors($user_id = null, $user_integration_id = null, $is_initial_sync = 0, $account = null)
    {
        $return_response = true;
        try {
            $account =  $this->getPrimaryAccount($user_integration_id);
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
                                    $offset = $pageNo->url;
                                }
                            } else {
                                $offset = 0;
                            }
                          
                            if ($loopBreaker) {
                                $pageCounter = $offset;
                                $pageLimit = 1000;
                                $arguments = [
                                    "offset" => $offset,
                                    "limit" => $pageLimit,
                                ];
                                $apicall = $this->vendorList($account, null,$arguments,null,"query");
                           
                                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                    $vendors = $apicall['body']['items'];
                                    $vendorAddr=[];
                                    if (count($vendors)) {
                                        foreach ($vendors as $key => $vendor) {

                                            $vendorID=$this->service->prepareVendorData($vendor, $user_id, $user_integration_id, $is_initial_sync);
                                            if(isset($vendor['defaultbillingaddress'])){
                                                $vendorAddr[$vendor['defaultbillingaddress']]=$vendorID;
                                            }
                                           
                                        }
                                        if($vendorAddr){
                                           
                                            //Store Vendor Address
                                            $billingIds=array_keys($vendorAddr);
                                            $billingIds=implode(',',$billingIds);
                                            $apicallAddress=$this->vendorBillingAddressByIds($account, $billingIds,"query");
                                          
                                            if (isset($apicallAddress['status_code']) && $apicallAddress['status_code'] == 200) {
                                                $vendorAddresses = $apicallAddress['body']['items'];
                                                if (count($vendorAddresses)) {
                                                    foreach ($vendorAddresses as $key => $vendorAddrs) {
                                                        if(isset($vendorAddr[$vendorAddrs['nkey']]) && isset($vendorAddrs['addrtext'])){
                                                            $vendorPrimaryID=$vendorAddr[$vendorAddrs['nkey']];
                                                            $this->service->prepareVendorAddress($vendorAddrs['addrtext'],$vendorPrimaryID);
                                                        }
                                                    }
                                                }
                                            }
                                            
                                        }
                                        if (isset($pageNo->url)) {
                                            $pageNo->url = $offset + $pageLimit;
                                            if(!$apicall['body']['hasMore']){
                                                $pageNo->url = 0;
                                                $pageNo->status = 1;
                                            }
                                            $pageNo->save();
                                        } else {
                                            PlatformUrl::insert([
                                                'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,
                                                'url' => $offset + $pageLimit,
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
                                    $error =  $this->handleErrorResponse($apicall);
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
                    $lastDate = PlatformCustomer::select('api_updated_at')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();

                    if (isset($lastDate->api_updated_at)) {
                        $startDate = $lastDate->api_updated_at;
                    } else {
                        $startDate = Carbon::now()->subMinutes(60)->format('Y-m-d'); //minus 60 from current time to get latest data
                    }

                    $offset = 0;
                    $pageLimit = 1000;
                    $arguments = [
                        "offset" => $offset,
                        "limit" => $pageLimit,
                    ];
                    $filters = [
                        "start_date" => $startDate
                    ];
                   
                    $apicall = $this->vendorList($account, null,$arguments,$filters,"query");

                  
                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $vendors = $apicall['body']['items'];
                        if (count($vendors)) {
                            $vendorAddr=[];
                            foreach ($vendors as $key => $vendor) {

                                $vendorID=$this->service->prepareVendorData($vendor, $user_id, $user_integration_id, $is_initial_sync);
                                if(isset($vendor['defaultbillingaddress'])){
                                    $vendorAddr[$vendor['defaultbillingaddress']]=$vendorID;
                                }
                               
                            }
                            if($vendorAddr){
                                           
                                //Store Vendor Address
                                $billingIds=array_keys($vendorAddr);
                                $billingIds=implode(',',$billingIds);
                                $apicallAddress=$this->vendorBillingAddressByIds($account, $billingIds,"query");
                              
                                if (isset($apicallAddress['status_code']) && $apicallAddress['status_code'] == 200) {
                                    $vendorAddresses = $apicallAddress['body']['items'];
                                    if (count($vendorAddresses)) {
                                        foreach ($vendorAddresses as $key => $vendorAddrs) {
                                            if(isset($vendorAddr[$vendorAddrs['nkey']]) && isset($vendorAddrs['addrtext'])){
                                                $vendorPrimaryID=$vendorAddr[$vendorAddrs['nkey']];
                                                $this->service->prepareVendorAddress($vendorAddrs['addrtext'],$vendorPrimaryID);
                                            }
                                        }
                                    }
                                }
                                
                            }
                        }
                    } else {

                        $error =  $this->handleErrorResponse($apicall);
                        $return_response = !empty($error) ? $error : "API Error";
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('NetsuiteRestApiController - getVendors - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }
    /* Get Products */
    public function getProducts($user_id = null, $user_integration_id = null, $is_initial_sync = 0, $account = null)
    {
        $return_response = true;
        try {
            $account =  $this->getPrimaryAccount($user_integration_id);
            if ($account) {

                if ($is_initial_sync) { // get vendors by chunks in loop when initial sync=1
                    $x = 1;
                    $loopBreaker = true;
                    while ($loopBreaker) {
                        if ($x <= 2) {
                            $pageNo = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $user_integration_id], ['platform_id', '=', $this->platformId], ['url_name', '=', 'products']])->first();
                            if (isset($pageNo->url)) {
                                if ($pageNo->url == 0 && $pageNo->status == 1) {
                                    $loopBreaker = false;
                                } else {
                                    $offset = $pageNo->url;
                                }
                            } else {
                                $offset = 0;
                            }
                            if ($loopBreaker) {
                                $pageCounter = $offset;
                                $pageLimit = 1000;
                                $arguments = [
                                    "offset" => $offset,
                                    "limit" => $pageLimit,
                                ];
                                $apicall = $this->productList($account, null,$arguments,null,"query");

                                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                    $products = $apicall['body'];
                                    if (count($products)) {
                                        foreach ($products as $key => $product) {

                                            $this->service->prepareProductData($product, $user_id, $user_integration_id, $is_initial_sync);
                                        }
                                        if (isset($pageNo->url)) {
                                            $pageNo->url = $offset + $pageLimit;
                                            $pageNo->status = 0;
                                            $pageNo->save();
                                        } else {
                                            PlatformUrl::insert([
                                                'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,
                                                'url' => $offset + $pageLimit,
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
                                    $error =  $this->handleErrorResponse($apicall);
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
                    $lastDate = PlatformProduct::select('api_updated_at')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();

                    if (isset($lastDate->api_updated_at)) {
                        $startDate = $lastDate->api_updated_at;
                    } else {
                        $startDate = Carbon::now()->subMinutes(60)->format('Y-m-d'); //minus 60 from current time to get latest data
                    }

                    $offset = 0;
                    $pageLimit = 1000;
                    $arguments = [
                        "page" => $offset,
                        "limit" => $pageLimit
                    ];
                    $filters = [
                        "start_date" => $startDate
                    ];
                  
                  
                    $apicall = $this->productList($account, $filters,$arguments,null,"query");
                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $products = $apicall['body'];
                        if (count($products)) {
                            foreach ($products as $key => $product) {

                                $this->service->prepareProductData($products, $user_id, $user_integration_id, 0);
                            }
                        }
                    } else {

                        $error =  $this->handleErrorResponse($apicall);
                        $return_response = !empty($error) ? $error : "API Error";
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('NetsuiteRestApiController - getProducts - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }
    /* Get Sales Order */
    public function getSalesOrders($user_id , $user_integration_id , $is_initial_sync = 0, $account = null){
        $return_response = true;
        try {
            $account =  $this->getPrimaryAccount($user_integration_id);
            if ($account) {

                if ($is_initial_sync) { // get sales order by chunks in loop when initial sync=1
                    $x = 1;
                    $loopBreaker = true;
                    while ($loopBreaker) {
                        if ($x <= 2) {
                            $pageNo = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $user_integration_id], ['platform_id', '=', $this->platformId], ['url_name', '=', 'sales_order']])->first();
                            if (isset($pageNo->url)) {
                                if ($pageNo->url == 0 && $pageNo->status == 1) {
                                    $loopBreaker = false;
                                } else {
                                    $offset = $pageNo->url;
                                }
                            } else {
                                $offset = 0;
                            }
                            if ($loopBreaker) {
                                $pageCounter = $offset;
                                $pageLimit = 40;
                                $arguments = [
                                    "offset" => $offset,
                                    "limit" => $pageLimit,
                                ];
                                $apicall = $this->salesOrderList($account, null,$arguments,null,"query");

                                if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                                    $orders = $apicall['body']['items'];
                                    if (count($orders)) {
                                        foreach ($orders as $key => $order) {

                                            $this->service->prepareOrderData("SO",$orders, $user_id, $user_integration_id, $is_initial_sync);
                                        }
                                        if (isset($pageNo->url)) {
                                            $pageNo->url = $offset + $pageLimit;
                                            $pageNo->status = 0;
                                            $pageNo->save();
                                        } else {
                                            PlatformUrl::insert([
                                                'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,
                                                'url' => $offset + $pageLimit,
                                                'url_name' => 'sales_order',
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
                                    $error =  $this->handleErrorResponse($apicall);
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
                    $lastDate = PlatformProduct::select('api_updated_at')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();

                    if (isset($lastDate->api_updated_at)) {
                        $startDate = $lastDate->api_updated_at;
                    } else {
                        $startDate = Carbon::now()->subMinutes(60)->format('Y-m-d'); //minus 60 from current time to get latest data
                    }

                    $offset = 0;
                    $pageLimit = 1000;
                    $arguments = [
                        "page" => $offset,
                        "limit" => $pageLimit
                    ];
                    $filters = [
                        "start_date" => $startDate
                    ];
                  
                  
                    $apicall = $this->productList($account, $filters,$arguments,null,"query");
                    if (isset($apicall['status_code']) && $apicall['status_code'] == 200) {
                        $products = $apicall['body'];
                        if (count($products)) {
                            foreach ($products as $key => $product) {

                                $this->service->prepareProductData($products, $user_id, $user_integration_id, 0);
                            }
                        }
                    } else {

                        $error =  $this->handleErrorResponse($apicall);
                        $return_response = !empty($error) ? $error : "API Error";
                    }
                }
            } else {
                $return_response = self::$myPlatform . " : Integration account detail not found";
            }
        } catch (\Exception $e) {
            \Log::error('NetsuiteRestApiController - getSalesOrders - ' . $e->getLine() . " -> " . $e->getMessage());
            $return_response = $e->getMessage();
        }

        return $return_response;
    }
    /* Execute Skubana Method */
    public function ExecuteEventNetsuiteERP($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        $response = true;
        if ($method == 'GET' && $event == 'PRODUCT') {
            //  $response = $this->getProducts($user_id, $user_integration_id, $is_initial_sync);
        } elseif ($method == 'GET' && $event == 'VENDOR') {
            $response = $this->getVendors($user_id, $user_integration_id, $is_initial_sync);
        } elseif ($method == 'GET' && $event == 'PURCHASEORDER') {
            //  $response = $this->getPurchaseOrders($user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync, $destination_platform_id);
        } elseif ($method == 'GET' && $event == 'SALESORDER') {
            // $response = $this->getSalesOrders($user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync);
        } elseif ($method == 'GET' && $event == 'POITEMRECEIPT') {
            // $response = $this->getPurchaseOrdersReceipt($user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync);
        } elseif ($method == 'GET' && $event == 'INVENTORY') {
            //  $response = $this->getInventories($user_id, $user_integration_id, $is_initial_sync);
        }

        return  $response;
    }





    public function test()
    {

        $user_id=$userId=Auth::user()->id;
        $user_integration_id=703;
         $is_initial_sync=1;

       
       dd($this->getVendors($user_id,  $user_integration_id, $is_initial_sync));
     // app('App\Http\Controllers\Snowflake\SnowflakeApiController')->RefreshToken(1181);
        //dd(app('App\Http\Controllers\Snowflake\SnowflakeApiController')->GetVendors( $user_id, $user_integration_id ));

        // $account =  $this->getPrimaryAccount($user_integration_id);
        // // dd($this->cache->get_or_set("awa"));
        // $apicall = $this->APICALL($account, "GET", "orders/114567817", [], [], "v1.1");
        // dd($apicall);
        // //dd($this->getProducts($userId,616,1));
        // //    dd($this->getVendors($userId,616,0));

        // // dd($this->getInventories($user_id, $user_integration_id, 1));
        // // dd($this->getChannels($user_id, $user_integration_id, 1));
        // dd($this->getOrders(109, 616, 1190, 0));
        //dd($this->service->findVendor($vendorId,$user_id,$user_integration_id,$account));
        // dd($this->encrypt_decrypt('d0Y5NFcwbWVmQ05tSHc3QVkrTnZyUld2QzRnbFlMYjRGZExLV3owckNpNXZQdHBwdExFVkM2T21VbnVNblpTU09nbUZhWXY5SkdVaHY3RWl2U3VJWUE9PQ==','decrypt'),$this->encrypt_decrypt('YmhQUkJTeDIrdURJc3RWVGo4ODZXNHhwcXdCT010b0dOSWIrNmd5WjNhRkRRZzNtNFoxYWM0cXpyZlNKUG9aUA==','decrypt'));
        //dd($this->getPurchaseOrders(109, 616, 0));
    }
}
