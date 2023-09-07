<?php

namespace App\Http\Controllers\GunBroker;


use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\MainModel;
use App\Http\Controllers\Controller;
use App\Models\PlatformAccount;
use App\Models\PlatformOrder;
use Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\GunBroker\Api\GunBrokerApi;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformUrl;
use Lang;
class GunBrokerController extends GunBrokerApi
{

    /**
     * Default name of the controller platform name
     */
    private const PLATFORMNAME = 'gunbroker';
    public $helper, $mobj, $logger, $platformId, $map,$source_platform_id;
    public function __construct()
    {

        $this->helper = new ConnectionHelper();
        $this->mobj = new MainModel();
        $this->logger = new Logger();
        $this->map = new FieldMappingHelper();
        // Set the platform ID
        $this->platformId = $this->helper->getPlatformIdByName(self::PLATFORMNAME);
    }

    /**
     * Auth function return the view page of authentication
     *
     * @param $request Request class
     */
    public function InitiateGunBrokerAuth(Request $request)
    {
        $platform = self::PLATFORMNAME;
        return view("pages.apiauth.auth_gunbroker", compact('platform'));
    }

    /**
     * Auth function to connect to the platform with response to the front
     *
     * @param $request Request class
     *
     * @return json_encoded data to be return with 2 parameters as `status_code` and `status_text`
     */
    public function ConnectGunBroker(Request $request)
    {
        $response = ['status_code' => 0]; // array for return response with status_code default to 0 (false)

        if($this->mobj->checkHtmlTags( $request->all() ) ){
            $response['status_text'] = Lang::get('tags.validate');
            return $response;
        }
        
        try {
            $validator = Validator::make($request->all(), [
                'dev_key' => 'required',
                'username' => 'required',
                'password' => 'required',
                'env_type' => 'required',
            ], [
                'dev_key.required' => 'Dev Key is required.',
                'username.required' => 'Username is required.',
                'password.required' => 'Password is required.',
                'env_type.required' => 'Env Type is required.',
            ]);
            if ($validator->fails()) {
                $statustext = array_values(json_decode($validator->messages()->toJson(), true))[0][0];
            } else {
                $validated = array_map(function ($val) {
                    return htmlspecialchars($val);
                }, $validator->validated());
                $validated = (object) $validated;
                // Set and Decrypt the values for security measures
                $account_name = "GunBroker_" . $validated->username;
                $env_type = $validated->env_type;
                $dev_key = $this->mobj->encrypt_decrypt($validated->dev_key);
                $username = $this->mobj->encrypt_decrypt($validated->username);
                $password = $this->mobj->encrypt_decrypt($validated->password);

                // Get Current User Id
                $user_data = Auth::user();
                $user_id = $user_data->id;

                // Check for the account
                $account = PlatformAccount::select('id')->where([
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'access_key' => $dev_key,
                    'app_id' => $username,
                ])->count();
                if ($account === 0) {
                    $isConnected = self::checkAuthCredential($validated);
                    if ($isConnected['status'] === true) {
                        // Add the given data
                        $api_domain = self::setURL($env_type, ''); //get domain name
                        $newAccount = PlatformAccount::create([
                            'user_id' => $user_id,
                            'platform_id' => $this->platformId,
                            'account_name' => $account_name,
                            'app_id' => $username,
                            'app_secret' => $password,
                            'api_domain' => $api_domain,
                            'access_token' => $this->mobj->encrypt_decrypt($isConnected['token']),
                            'access_key' => $dev_key,
                            'env_type' => $env_type,
                        ]);
                        if ($newAccount->id) {
                            $response['status_code'] = true;
                            $statustext = 'Account Connected.';
                        } else {
                            $statustext = 'Account not created! Please try again.';
                        }
                    } else if ($isConnected['status'] === false) {

                        $statustext = 'Please check for the given credential.';
                    }
                } else {
                    $statustext = "Account already connected.";
                }
            }
            $response['status_text'] = $statustext;
        } catch (\Exception $e) {

            $response['status_text'] = $e->getMessage();
        }
        return $response;
    }

    /**
     * Function is used to regenerate the token
     *
     * @param $ID platform account id
     *
     * @return bool value
     */
    public function RefreshToken($ID)
    {
        $return_response = false;
        date_default_timezone_set('UTC');
        try {

            if ($this->platformId) {
                $accDetail = PlatformAccount::select('id', 'app_id', 'app_secret', 'api_domain','access_key')->find($ID);
                if ($accDetail) {
                    $checkCredentials = self::RegenerateAccessToken($accDetail,false,true);
                   
                    $return_response = $checkCredentials['status'];
                }
            }
        } catch (\Exception $e) {
            $return_response = $e->getMessage();
        }
        return $return_response;
    }
   
    /**
     * Get Products from  GunBroker
     *
     * @param $user_id, the user's id with the current integration
     * @param $user_integration_id, the user_integration id
     * @param $is_initial_sync, for set intial value or not
     *
     * @return return_response be return  as bolean or error value
     */
    public function GetProducts($user_id = null, $user_integration_id = null, $is_initial_sync = 0)
    {
        $this->mobj->AddMemory(); //Add extra memory to execute
        $return_response = false;
        try {
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId); // get the account information for the integration

            if ($account) {
                if ($is_initial_sync) {
                    $x = 1;
                    while ($x <= 2) {
                        $loopBreaker = true;
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
                            $pageLimit = 250;
                            $breakCounter = 0;
                            $arguments = [
                                'PageIndex' => $page,
                                'PageSize' => $pageLimit,
                            ];
                            $product =  self::GetProduct($account, $arguments);
                    

                            if (!empty($product) && is_array($product)) {
                                foreach ($product as $key => $value) {
                                    if (!empty($value['sku'])) {
                                        $value['user_id'] = $user_id;
                                        $value['user_integration_id'] = $user_integration_id;
                                        app('App\Http\Controllers\GunBroker\GunBrokerServiceController')->PrepareProductModal($value);
                                    }
                                }
                                if ($breakCounter == 0) {

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
                                    $return_response = "API Error to get products from " . self::PLATFORMNAME;
                                }
                            } else if (empty($product) && !is_array($product)) {

                                //if we get error in api
                                $return_response = $product;
                                continue;
                            } else if (empty($product) && is_array($product)) {

                                //if we have last record as empty array
                                if (isset($pageNo->url)) {
                                    $pageNo->url = 0;
                                    $pageNo->status = 1;
                                    $pageNo->save();
                                }
                                $return_response = true;
                            }
                        } else {
                            $return_response = true;
                        }
                        $x++;
                    }
                } else {
                    $return_response = true;
                    // $arguments = [
                    //     'PageIndex' => 1,
                    //     'PageSize' => 300,
                    //     "Sort"=>1,
                    //     "Keyword"=>"new"
                    // ];
                    // $product =  self::GetProduct($account, $arguments);

                    // if (!empty($product) && is_array($product)) {
                    //     foreach ($product as $key => $value) {
                    //         if (!empty($value['sku'])) {
                    //             $value['user_id'] = $user_id;
                    //             $value['user_integration_id'] = $user_integration_id;
                    //             app('App\Http\Controllers\GunBroker\GunBrokerServiceController')->PrepareProductModal($value);
                    //         }
                    //     }
                    // } else if (empty($product) && !is_array($product)) {
                    //     //if we get error in api
                    //     $return_response = $product;
                    // }else if (empty($product) && is_array($product)) {
                    //     $return_response = true;
                    // }
                }
            }
        } catch (\Exception $e) {

            $return_response = $e->getMessage();
        }
        return $return_response;
    }


    /**
     * Syncing of the order's shipment from Brightpearl to GunBroker platform
     *
     * @param $user_id, the user's id with the current integration
     * @param $user_integration_id, the user_integration id
     * @param $platform_workflow_rule_id
     * @param $user_workflow_rule_id, the user_workflow_rule id
     * @param $source_platform_name, the source platform name eg. brightpearl
     * @param $sync_status, the platform_workflow_rule id
     * @param $record_id, for resyncing the failed data
     *
     * @return json_encoded data to be return with 2 parameters as `status_code` and `status_text`
     */
    public function SyncShipment($user_id = null, $user_integration_id = null,$platform_workflow_rule_id = null, $user_workflow_rule_id = null, $source_platform_name = null,  $sync_status = "Ready", $record_id=null)
    {
        $returnstatus = true;
        try {

            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId); // get the account information for the integration
            if ($account) {
                $this->source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
                if ($this->source_platform_id) {
                    $limit = 20;
                    $query = PlatformOrder::with('linkedOrder')->select('id', 'api_order_id', 'linked_id');
                    if ($record_id) {
                        $query->where('id', $record_id);
                    } else {
                        $query->where([
                            'platform_id' => $this->source_platform_id,
                            'user_integration_id' => $user_integration_id,
                            'shipment_status' => $sync_status,
                        ]);
                    }
                    $list = $query->orderBy('id','asc')->take($limit)->get();

                    if (count($list) && !empty($list)) {
                        $default_shipping_method=NULL;
                        $object_id = $this->helper->getObjectId('shipping_method');
                        $default_sales_order_shipping_method = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "sorder_shipping_method", ['api_id']);
                        if ($default_sales_order_shipping_method) {
                            $default_shipping_method = $default_sales_order_shipping_method->api_id;
                        }

                        foreach ($list as $key => $value) {
                            if (isset($value->linkedOrder->linked_id)) {
                                $find = PlatformOrderShipment::where([
                                    ['platform_order_id', '=', $value->id],
                                    ['tracking_info', '!=', NULL],
                                    ['sync_status', '=', "Ready"]
                                ])->orderBy('shipment_id','asc')->first();

                                if ($find) {
                                    /* Here passing user_id=user_integration_id=0 because GB doesn't provide api to get shipping method */
                                    $shipping_method = $this->map->getMappedDataByName($user_integration_id, NULL, "sorder_shipping_method", ['api_id'], 'regular', $find->shipping_method,'single','destination');
                                    if (isset($shipping_method->api_id)) {
                                        $shippingMethod = $shipping_method->api_id;
                                    } else {
                                        $shippingMethod = $default_shipping_method;
                                    }
                                    $payload = [
                                        'TrackingNumber' => $find->tracking_info,
                                        'Carrier' => $shippingMethod
                                    ];
                                    $response = self::UpdateOrder($account, $value->linkedOrder->api_order_id, $payload);
                                    if ($response == 200) {
                                        $syncLog='success'; $error=null;
                                        $flagPayload = [
                                            'OrderShipped' => true
                                        ];
                                        $responseFlag = self::UpdateOrderFlag($account, $value->linkedOrder->api_order_id, $flagPayload);
                                        if ($responseFlag == 200) {                                          
                                            $value->shipment_status = "Synced";
                                            $value->linkedOrder->shipment_status == "Synced";                                           
                                        }else{
                                            $syncLog='failed';
                                            $value->shipment_status = "Failed";
                                            $value->linkedOrder->shipment_status == "Failed";
                                            if(empty($responseFlag)){
                                                $error="Tracking no updated but final shipment flag set to failed";
                                            }else{
                                                $error="Tracking no updated but ". $responseFlag;
                                            }
                                           
                                        }                                      
                                        $value->save();
                                        $this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $this->source_platform_id, $this->platformId, $object_id, $syncLog, $value->id, $error);
                                    } else {
                                        $value->shipment_status = "Failed";
                                        $value->linkedOrder->shipment_status == "Failed";
                                        $value->save();
                                        $this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $this->source_platform_id, $this->platformId, $object_id, 'failed', $value->id, $response);
                                        $returnstatus=$response;
                                    }
                                }
                            }else{
                                $error="Order shipment detail not found";
                                $value->shipment_status = "Failed";
                                $value->save();
                                $this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $this->source_platform_id, $this->platformId, $object_id, 'failed', $value->id, $error);
                                $returnstatus=$error;
                            }
                        }
                    }
                } else {
                    $returnstatus = 'Account error occured.';
                }
            } else {
                $returnstatus = 'No account found for integration.';
            }
        } catch (\Exception $e) {
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }

   /**
     * Syncing of the inventory detail from Brightpearl to GunBroker platform
     *
     * @param $user_id, the user's id with the current integration
     * @param $user_integration_id, the user_integration id
     * @param $platform_workflow_rule_id, the platform_workflow_rule id
     * @param $user_workflow_rule_id, the user_workflow_rule id
     * @param $source_platform_name, the source platform name eg. brightpearl

     * @param $sync_status, the default sync status to pick record for sync process
     * @param $record_id, for resyncing the failed data
     *
     * @return json_encoded data to be return with 2 parameters as `status_code` and `status_text`
     */
    public function SyncInventoryAndPrice($user_id = null, $user_integration_id = null, $platform_workflow_rule_id = null,$user_workflow_rule_id = null, $source_platform_name = null,  $sync_status = "Ready", $record_id = 0)
    {
        $this->mobj->AddMemory();
        $returnstatus = true;
        try {

            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId); // get the account information for the integration
           // \Storage::append('GunBroker_SyncInventory.txt', 'account found: ' . print_r($account, true));

            if ($account) {
                $this->source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);

                if ($this->source_platform_id) {
                    $limit = 100;
                    $identity = app('App\Http\Controllers\GunBroker\GunBrokerServiceController')->ProductIdentityMapping($user_integration_id, $platform_workflow_rule_id);

                    if (!empty($identity['source_identity']) && !empty($identity['destination_identity'])) {
                        $q = DB::table('platform_product as source_platform_product')->join('platform_product as destination_platform_product', 'destination_platform_product.' . $identity['source_identity'], '=', 'source_platform_product.' . $identity['destination_identity']);

                        if ($record_id) {
                            $q->where([
                                ['source_platform_product.id','=',  $record_id],
                                ['source_platform_product.user_integration_id', '=', $user_integration_id],
                                ['source_platform_product.platform_id', '=', $this->source_platform_id],
                                ['destination_platform_product.user_integration_id', '=', $user_integration_id],
                                ['destination_platform_product.platform_id', '=', $this->platformId],
                                ['destination_platform_product.price_type', '=', 'Fixed'],
                                ['destination_platform_product.is_deleted', '=', 0],
                                ['source_platform_product.is_deleted', '=', 0],
                            ]);
                            $singleExecute=true;
                        } else {
                            $q->where([
                                ['source_platform_product.user_integration_id', '=', $user_integration_id],
                                ['source_platform_product.platform_id', '=', $this->source_platform_id],
                                ['destination_platform_product.user_integration_id', '=', $user_integration_id],
                                ['destination_platform_product.platform_id', '=', $this->platformId],
                                ['destination_platform_product.price_type', '=', 'Fixed'],
                                ['destination_platform_product.is_deleted', '=', 0],
                                ['source_platform_product.is_deleted', '=', 0],
                            ])->where(function ($subQuery) use ($sync_status) {
                                $subQuery->where('source_platform_product.inventory_sync_status', '=', $sync_status)->Orwhere('source_platform_product.product_sync_status', '=', $sync_status);
                            });
                            $singleExecute=false;
                        }
                        $inventory_and_price_arr = $q->select('source_platform_product.id', 'destination_platform_product.sku as gun_sku',
                        'destination_platform_product.price_type as gun_price_type', 'destination_platform_product.id as gun_row_id', 'destination_platform_product.api_product_id as gun_api_product_id', 'source_platform_product.api_product_id as bp_api_product_id', 'destination_platform_product.parent_product_id as parent_product_id','source_platform_product.product_sync_status','source_platform_product.inventory_sync_status')->orderBy('source_platform_product.updated_at', 'asc')
                            ->limit($limit)->get();

                        if (count($inventory_and_price_arr) > 0) {

                            $returnstatus =app('App\Http\Controllers\GunBroker\GunBrokerServiceController')->UpdateProduct($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $this->source_platform_id, $inventory_and_price_arr, $account,$singleExecute); //Update Inventory & Price Based on condition;

                        }
                    }
                } else {
                    $returnstatus = 'Account error occured.';
                }
            } else {
                $returnstatus = 'No account found for integration.';
            }
        } catch (\Exception $e) {
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }
    /**
     * Get orders from source platform (GunBroker)
     *
     * @param $user_id, the user's id with the current integration
     * @param $user_integration_id, the user_integration id
     * @param $user_workflow_rule_id, the user_workflow_rule id
     * @param $source_platform_name, the source platform name eg. brightpearl
     * @param $platform_workflow_rule_id, the platform_workflow_rule id
     * @param $record_id, for resyncing the failed data
     *
     * @return json_encoded data to be return with 2 parameters as `status_code` and `status_text`
     */
    public function GetSalesOrder($user_id = null, $user_integration_id = null)
    {
        $returnstatus = true;
        try {
            $account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId); // get the account information for the integration

            if ($account) {              
                $record = PlatformOrder::where([
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                ])->select('api_updated_at')->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();
                if ($record) {
                    $filterDate = Carbon::parse($record->api_updated_at)->format('c');
                } else {
                    $filterDate = Carbon::now()->subMinutes(30)->format('c');
                }
                $arguments = [
                    'PageIndex' => 1,
                    'PageSize' => 300,
                    'OrdersModifiedSinceDate' => $filterDate,
                    'Sort' => 0,  /*   0 - Order ID
                    1 - Buyer Name                    
                    2 - Item ID                    
                    3 - Order Date                    
                    4 - Total Price                    
                    5 - Seller Reviewed                    
                    6 - Buyer Confirmed                    
                    7 - Payment Received                    
                    8 - FFL Received                    
                    9 - Item Shipped                    
                    10 - Order Complete                    
                    11 - On Layaway                    
                    12 - Payment In Process */                
                    'SortOrder'=>0//0 - Ascending

                ];
                $list = self::GetOrders($account, $arguments);
                if (is_array($list) && !empty($list)) {
                    foreach ($list as $key => $value) {
                        if( isset($value['paymentReceived']) && $value['paymentReceived']==true){//if payment received is true
                            $count = PlatformOrder::where([
                                'user_integration_id' => $user_integration_id,
                                'platform_id' => $this->platformId,
                                'api_order_id'=> $value['orderID']
                            ])->count();
                            if ($count==0) {
                                $value['user_integration_id'] = $user_integration_id; //assign user_integration_id as array
                                $value['user_id'] = $user_id; //assign user_id as array

                                $value['platform_customer_id'] = app('App\Http\Controllers\GunBroker\GunBrokerServiceController')->StoreCustomerDetail($account, $value);
                                app('App\Http\Controllers\GunBroker\GunBrokerServiceController')->PrepareOrderModal($value);
                            }
                        }
                    }
                } else if (!is_array($list)) {
                    $returnstatus = $list;
                }
            } else {
                $returnstatus = 'No account found for integration.';
            }
        } catch (\Exception $e) {
            $returnstatus = $e->getMessage();
        }
        return $returnstatus;
    }


    /**
     * Syncing of the Order from GunBroker to Brightpearl
     * Syncing of the Product Price from Brightpearl to GunBroker
     * Syncing of the Product Inventory from Brightpearl to GunBroker
     * Syncing of the Shipment from Brightpearl to GunBroker
     *
     * @param $method, for 'MUTATE' it's for creation of new data and for 'GET' to get any data from the platform
     * @param $event, the event for the function is initiated
     * @param $is_initial_sync, at first it's 1 and then it's always 0
     * @param $user_id, the user's id with the current integration
     * @param $user_integration_id, the user_integration id
     * @param $source_platform_name, the source platform name eg. brightpearl
     * @param $platform_workflow_rule_id, the platform_workflow_rule id
     * @param $user_workflow_rule_id, the user_workflow_rule id
     * @param $record_id, for resyncing the failed data
     *
     * @return json_encoded data to be return with 2 parameters as `status_code` and `status_text`
     */
    public function ExecuteGunBroker($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
    {
        $response = true;
        // \Storage::append('ExecuteGunBroker.txt', 'ExecuteGunBroker Called @ ' . now());
        // \Storage::append('ExecuteGunBroker.txt', 'method : ' . $method . ' | event : ' . $event . ' | is_initial_sync : ' . $is_initial_sync . ' | user_integration_id : ' . $user_integration_id);
        try {
            if ($method == 'GET' && $event == 'SALESORDER') {
                $response = $this->GetSalesOrder($user_id, $user_integration_id);
            } else if ($method == 'GET' && $event == 'PRODUCT') {
                $response = $this->GetProducts($user_id, $user_integration_id, $is_initial_sync);
            } else if ($method == 'MUTATE' && $event == 'SHIPMENT') {
                $sync_status = 'Ready';
                $response = $this->SyncShipment($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $sync_status,$record_id);
            } else if ($method == 'MUTATE' && $event == 'INVENTORY') {
                $sync_status = 'Ready';
                $response = $this->SyncInventoryAndPrice($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $sync_status, $record_id);
            }
            return $response;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}