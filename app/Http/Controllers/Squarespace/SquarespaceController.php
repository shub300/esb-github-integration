<?php

namespace App\Http\Controllers\Squarespace;
use DB;
use Auth;
use Validator;
use App\Helper\MainModel;
use App\Models\PlatformUrl;
use Illuminate\Http\Request;
use App\Models\PlatformOrder;
use App\Models\PlatformAccount;
use App\Models\PlatformProduct;
use App\Helper\ConnectionHelper;
use App\Models\PlatformObjectData;
use App\Helper\Api\SquarespaceApi;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Session;
use App\Models\PlatformProductOption;
use App\Helper\FieldMappingHelper;
use Lang;
class SquarespaceController extends Controller
{
   /**
    * Create a new controller instance.
    *
    * @return void
    */
   public function __construct()
   {
      $this->mobj = new MainModel();
      $this->helper = new ConnectionHelper;
      $this->squarespace = new SquarespaceApi;
      $this->my_platform = 'squarespace';
      $this->platformId = $this->helper->getPlatformIdByName($this->my_platform);
      $this->map = new FieldMappingHelper();
   }
   public function InitiateSquarespaceAuth(Request $request)
   {
        // $res = app('App\Helper\MainModel')->encrypt_decrypt('9XO+oFE0goyIEERrh8lO4w+cbpn955UCFsriUwm9TO8=');
        $platform =  $this->my_platform;
        return view("pages.apiauth.squarespace_auth", compact('platform'));
   }
   /* Redirect Brodreredahl Auth */
   public function ConnectSquarespaceOauth(Request $request)
   {
    if ($request->isMethod('post')) {
        $validator = Validator::make($request->all(), [
            'account_name' => 'required',
        ]);

        if($this->mobj->checkHtmlTags( $request->all() ) ){
            return back()->with('error',Lang::get('tags.validate'));
         }
         
        if ($validator->fails()) {
            return back()->withErrors($validator);
        } else {
            $account_name = $request->account_name;
            $user_data =  Auth::user();
            $userID =  $user_data->id;
            $isAllowed =  $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' =>  $this->platformId], ['app_ref', 'client_id', 'client_secret']);

            // to check whether given account is already in use or not.
            $checkExistingAc = $this->checkExistingConnectedAc($this->platformId, $account_name);
            if ($checkExistingAc) {
                return back()->with('error', 'Given details are already in use, Try with other details.');
            }
            if ($isAllowed &&  $this->platformId) {
                $client_id = $this->mobj->encrypt_decrypt($isAllowed->client_id, 'decrypt');
                $client_secret = $this->mobj->encrypt_decrypt($isAllowed->client_secret, 'decrypt');
                $redirect_url = $this->mobj->makeUrlHttpsForProd(url('/squarespaceRedirectHandler'));
                $state_i = $userID . "-" . trim($account_name);
                if (!$account_name) {
                    return back()->with('error', 'Account not found.');
                }

                if ($client_id && $client_secret) {
                    $url = \Config::get('apiconfig.SquarespaceUrlAuth')."/authorize?client_id=".$client_id."&redirect_uri=".$redirect_url."&scope=website.inventory,website.orders,website.products,website.transactions&access_type=offline&state=".$state_i;
                    return redirect($url);
                } else {
                    Session::put('auth_msg', 'App config not found');
                    echo '<script>window.close();</script>';
                }
            } else {
                $this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"</a>');
            }
        }
    }
   }
   /* Get Token */
   public function redirectHandler(Request $request)
   {
    //   \Storage::disk('local')->append('squarespace_callback.txt','Redirect Handler Data '.print_r($request->all(),true). ' - '.date('Y-m-d H:i:s') );

      if (isset($request->code)) {

         $record = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' =>  $this->platformId], ['app_ref', 'client_id', 'client_secret']);
         if ($record && $this->platformId) {
             $code = $request->code;
             $client_id = $this->mobj->encrypt_decrypt($record->client_id, 'decrypt');
             $client_secret = $this->mobj->encrypt_decrypt($record->client_secret, 'decrypt');
             $redirect_url = $this->mobj->makeUrlHttpsForProd(url('/squarespaceRedirectHandler'));
             $state = $request->state;
             $state_arr = explode('-', $state);
             if (isset($state_arr[0]) && isset($state_arr[1])) {
                 // Valid request
                 $userId = $state_arr[0];
                 $AccountName = $state_arr[1]; // Account Code
                 if (isset($state_arr[0]) && isset($state_arr[1])) {
                     $curl_post_data = array(
                        'code' => $code,
                        'client_id' => $client_id,
                        'client_secret' => $client_secret,
                        'redirect_uri' => $redirect_url,
                        'grant_type' => 'authorization_code'
                     );
                     
                     $authHash  = base64_encode($client_id.':'.$client_secret);

                     $response = $this->squarespace->AuthApiCall('POST',$curl_post_data,$authHash);
 
                     if (json_decode($response, true)) {
                         if ($decode_val = json_decode($response, true)) {

                            // \Storage::disk('local')->append('squarespace_callback.txt','Squarespace Token Resp '. print_r($decode_val,true));
                
                             if (isset($decode_val['access_token'])) {
                                 $OauthData = [
                                     'access_token' => $this->mobj->encrypt_decrypt($decode_val['access_token']),
                                     'token_type' => $decode_val['token_type'],
                                     'expires_in' => $decode_val['expires_in'],
                                     'access_key' => $this->mobj->encrypt_decrypt($code),
                                     'account_name' => $AccountName,
                                     'user_id' => $userId,
                                     'app_id' => $this->mobj->encrypt_decrypt($client_id), //app_reference
                                     'platform_id' => $this->platformId,
                                     'token_refresh_time' => time(),
                                     'refresh_token' => isset($decode_val['refresh_token']) ? $this->mobj->encrypt_decrypt($decode_val['refresh_token']) : null,
                                 ];

                                 $ufound = DB::table('platform_accounts')->where([
                                     'user_id' => $userId,
                                     'platform_id' => $this->platformId, 'account_name' => $AccountName
                                 ])->first();

                                 if ($ufound) {
                                     $res_n = DB::table('platform_accounts')->where('id', '=', $ufound->id)->update(
                                         $OauthData
                                     );
                                 } else {
                                     $OauthData['user_id'] = $userId;
                                     DB::table('platform_accounts')->insert(
                                         $OauthData
                                     );
                                 }
                             } else { 
                                 if (isset($decode_val['error_description'])) {
                                     $error = $decode_val['error_description'];
                                 } else {
                                     $error = "Something went wrong in your account";
                                 }
                                 echo '<script>alert("' . $error . '");window.close();</script>';
                             }
                         }
                         echo '<script>window.close();</script>';
                     } else {
                         $this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"</a>');
                     }
                     
                 }
             }
         }
     } else { // When code not received from BP
         $this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"</a>');
     }


   }
   /* Refresh token */
   function RefreshTokens($ID, $userId = NULL)
   {
         date_default_timezone_set('UTC');
         try {

            $findApp = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->platformId]);
            if ($findApp && $this->platformId) {

               $accDetail = $this->mobj->getFirstResultByConditions('platform_accounts', ['id' => $ID], ['id', 'user_id', 'platform_id', 'token_refresh_time', 'refresh_token', 'expires_in', 'account_name', 'app_id', 'app_secret', 'env_type', 'access_key','access_token']);
               
               $redirect_url = $this->mobj->makeUrlHttpsForProd(url('/squarespaceRedirectHandler'));

               //call for refresh token  
               if ($accDetail) {
                    $client_id = $this->mobj->encrypt_decrypt($findApp->client_id, 'decrypt');
                    $client_secret = $this->mobj->encrypt_decrypt($findApp->client_secret, 'decrypt');

                    $curl_post_data = [
                        'refresh_token' => $this->mobj->encrypt_decrypt($accDetail->refresh_token, 'decrypt'),
                        'client_id' => $client_id,
                        'client_secret' => $client_secret,
                        'redirect_uri' => $redirect_url,
                        'grant_type' => 'refresh_token'
                    ];

                    
                    // $authHash = $this->mobj->encrypt_decrypt($accDetail->access_token, 'decrypt');

                    $authHash  = base64_encode($client_id.':'.$client_secret);

                    $response = $this->squarespace->AuthApiCall('POST',$curl_post_data,$authHash);

                    // \Storage::disk('local')->append('squarespace_callback.txt','Refresh token Resp '.print_r($response,true));

                     if ($resData = json_decode($response, true)) {
                        $res = $resData;

                        if (!isset($res['errors'])) {
                           $this->mobj->makeUpdate(
                                 'platform_accounts',
                                 [
                                    'access_token' => $this->mobj->encrypt_decrypt($res['access_token']),
                                    'expires_in' => $res['expires_in'], 
                                    'refresh_token' => $this->mobj->encrypt_decrypt($res['refresh_token']), 
                                    'token_refresh_time' => time()
                                 ],
                                 ['id' => $ID]
                           );
                           $return_response = true;
                        } else {
                           $error = $this->squarespace->handleResponseError($res);
                           $return_response = isset($error) ? $error : "API Error";
                        }
                     } else {
                        $return_response =  "API Error";
                
                     }
               }
            }
         } catch (\Exception $e) {
            $return_response = $e->getMessage();
            \Storage::disk('local')->append('testCrone.txt', 'Squarespace refresh token Resp : ' . json_encode($return_response));
         }
   }

   // function to check whether a Brodrenedahl account is already in use or not.
   public function checkExistingConnectedAc($platform_id, $account_name)
   {
         $obj_existing = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $platform_id, 'account_name' => $account_name], ['user_id']);
         if ($obj_existing) {
            return true;
         } else {
            return false;
         }
   } 

    

    /* Receive Order create/update/delete webhook */
    public function ReceiveOrderWebhook(Request $request, $user_integration_id)
    {
            \Storage::disk('local')->append('webhook_log.txt', 'Squarespace Order Webhook Resp - '.date("Y-m-d H:i:s").PHP_EOL .print_r($request->all(), true)); 

            $userIntegData = DB::table('user_integrations')->where('id',$user_integration_id)->select('user_id')->first();
            if($userIntegData)
            {
                $userId = $userIntegData->user_id;
                if($request->all()){
                    $orderRes = $request->all();
                    $webhookTopic = @$orderRes['topic'];
                    $orderId = @$orderRes['data']['orderId'];

                    \Storage::disk('local')->append('webhook_log.txt', 'Squarespace new Webhook OrderId - '.$orderId .PHP_EOL .PHP_EOL); 

                    //get order by id
                    if($orderId){

                        //orders data
                        $arr_order = array();
                        $arr_order['user_id'] = $userId;
                        $arr_order['platform_id'] = $this->platformId;
                        $arr_order['user_integration_id'] = $user_integration_id;
                        $arr_order['order_type'] = "SO";
                        $arr_order['api_order_id'] = $orderId;
                        $arr_order['sync_status'] = 'Pending';
                        
                        //store order with pending status
                        $order_details = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_order_id' => $orderId], ['id','sync_status']);

                        if (!$order_details) {
                            $platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
                            \Storage::disk('local')->append('webhook_log.txt', 'Squarespace new order inserted with primary ID - '.$platform_order_id .' api_orderId-' 
                            .$orderId .PHP_EOL);
                        } 


                    }
                    
                }

            }
            
    } 

    /* Get sales order call for webhook setup & process webhook orders */
    public function GetSalesOrder($userId=NULL, $user_integration_id=NULL, $is_initial_syn = 0)
    {
    
            $return_response = true;
            try {

                $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'account_name', 'app_id']);

        
                if ($ufound && $this->platformId) {
                    if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

                        $account_name = $ufound->account_name;
                        $authHash = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');

                        if ($is_initial_syn == 1) { // To set webhook
                            $webhookStatus = $this->CreateOrDeleteWebhook($userId, $user_integration_id, ['order'], 1);
                        } elseif ($is_initial_syn == 0) {
                            //fetch pending webhook orders detail & make ready for sync
                            $this->processWebhookOrders($userId, $user_integration_id, $ufound);

                        }

                    }
                }
                    

            } catch (\Exception $e) {
                \Log::error($e->getMessage());
                $return_response = $e->getMessage();
            }

            return $return_response;

    }

    public function processWebhookOrders($userId, $user_integration_id, $ufound)
    {

            date_default_timezone_set('UTC');
            $limit = 10;
            $return_response = true;
            try {

                if ($ufound && $this->platformId) {
                    if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

                        $account_name = $ufound->account_name;
                        $authHash = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');

                    
                        $pendingOrders = PlatformOrder::select('id','api_order_id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'sync_status' => 'Pending'])->limit($limit)->orderBy('updated_at','asc')->get();


                        //test log
                        \Storage::disk('local')->append('squarespace_log.txt', 'processWebhookOrders time-'.date('Y-m-d H:i:s').' fetchedPendingOrders-'.json_encode($pendingOrders) .PHP_EOL);

                        if($pendingOrders){

                            foreach($pendingOrders as $order){



                                $platform_order_id = $order['id'];
                                $orderId = $order['api_order_id'];
        
                                // //get order by id
                                $apiEndpoint = "commerce/orders/".$orderId;

                                $value = $this->squarespace->ApiCall($authHash, $apiEndpoint, Null, 'GET');   

                                
                                if (!isset($value['error'])) {
        
                                    if($value &&  array_key_exists('id', $value) ){
                                        $this->insertUpdateWFOrderDetails($userId,$user_integration_id,$this->platformId,$value,NULL);
                                    } else {
                                        $return_response = isset($value['message']) ? $value['message'] : 'Api Erro';
                                    }
        
                                } else {
                                    $this->mobj->makeUpdate('platform_order', ['updated_at'=>date('Y-m-d H:i:s')], ['id' => $platform_order_id]);
                                    $error = $this->squarespace->handleResponseError($value);
                                    $return_response = isset($error) ? $error : "API Error";
                                }
        
        
                            }

                        }

                    }
                }
            } catch (\Exception $e) {
                \Log::error($e->getMessage());
                $return_response = $e->getMessage();
            }
            return $return_response;
    }

    //insert or update orders
    public function insertUpdateWFOrderDetails($user_id, $user_integration_id, $platform_id, $ord, $webhookTopic=null)
    {   
    
        $orderFilter = false;
        $primarySupplierIdFilter = null;
        //get sale order filter values to save that types of orders only 
        $orderFilterObjId = $this->helper->getObjectId('sorder_status_filter');
        $primaryOrderFilter = $this->map->getMappedApiIdByObjectId($user_integration_id, $orderFilterObjId, 'default', 'name');
        if ($primaryOrderFilter) {
            $orderFilter = true;
        }

        //get order createdOn
        $order_createdOn = $ord['createdOn'];
        $acceptNewOrders = true;

        //handle order Filter By sync start date time...filter from mapping
        $findUserWF = $this->mobj->getFirstResultByConditions('user_workflow_rule', ['user_integration_id' => $user_integration_id], ['sync_start_date']);
        if($findUserWF && $findUserWF->sync_start_date ){
            $formate_sync_start_date = date(DATE_ISO8601, strtotime($findUserWF->sync_start_date));
            if( strtotime( $order_createdOn)  < strtotime($formate_sync_start_date) ) {
                $acceptNewOrders = false;
            }  
        }


        /* check order_createdOn > data retention period then store else do not need to store */
        $dataRetentionRow = $this->map->getDataRetentionbyIntegration($user_integration_id);
        if($dataRetentionRow){
            // $dataRetentionPeriod = $dataRetentionRow->pi_drp;
            $dataRetentionPeriod = 1;
            $old_date = date('Y-m-d h:i:s',(strtotime ( '-'.$dataRetentionPeriod.' day' , time()) ));
            $formate_old_date = date(DATE_ISO8601, strtotime($old_date));
            if( strtotime($order_createdOn)  < strtotime($formate_old_date) ) {
                $acceptNewOrders = false;
            }  
        } 

        //if sorder filter is on then that types order only
        if($orderFilter){
            $fullFillmentStatus = @$ord['fulfillmentStatus'];
            //if api fullfillment Status = selected order status on mapping then save
            if($primaryOrderFilter == $fullFillmentStatus){
                $apiOrderId = @$ord['id'];
                // \Storage::disk('local')->append('webhook_log.txt', 'OrderFilter Match with Recieved Order - '.$apiOrderId); 
                //call finalizeStoreUpdate to insert or update orders
                $this->finalizeStoreUpdateOrders($user_id, $user_integration_id, $platform_id, $ord, $acceptNewOrders, $webhookTopic);
            }
        } else {
            //call finalizeStoreUpdate to insert or update orders
            $this->finalizeStoreUpdateOrders($user_id, $user_integration_id, $platform_id, $ord, $acceptNewOrders, $webhookTopic);
        }


    }

    //store or update orders  Topics : order.create, order.update
    public function finalizeStoreUpdateOrders($user_id, $user_integration_id, $platform_id, $ord, $acceptNewOrders, $webhookTopic=null)
    {
        //return execution if order number is empty
        if( isset($ord['orderNumber']) && empty($ord['orderNumber']) ) {
            return true;
        }

        //customer data
        if(isset($ord['shippingAddress'])){
            $cust_address = $ord['shippingAddress'];
        } else if(isset($ord['billingAddress'])){
            $cust_address = $ord['billingAddress'];
        } else {
            $cust_address = "";
        }

        $platform_customer_id = "";

        if($cust_address){
            $arr_customer = array();
            $arr_customer['type'] = 'Customer';
            $arr_customer['user_id'] = $user_id;
            $arr_customer['platform_id'] = $this->platformId;
            $arr_customer['user_integration_id'] = $user_integration_id;
            $arr_customer['email'] = @$ord['customerEmail'];
            $arr_customer['customer_name'] = $cust_address['firstName'].' '.$cust_address['lastName'];
            $arr_customer['first_name'] = $cust_address['firstName'];
            $arr_customer['last_name'] = $cust_address['lastName'];
            $arr_customer['address1'] = $cust_address['address1'];
            $arr_customer['address2'] = $cust_address['address2'];
            $arr_customer['address3'] = $cust_address['city'];
            $arr_customer['country'] = $cust_address['countryCode'];
            $arr_customer['postal_addresses'] = $cust_address['postalCode'];
            $arr_customer['phone'] = $cust_address['phone'];
            $arr_customer['sync_status'] = 'Ready';

            //insert or update in platform customer
            $findCustomer = $this->mobj->getFirstResultByConditions('platform_customer', [
                'platform_id' => $this->platformId, 'email' => $arr_customer['email'], 
                'user_integration_id' => $user_integration_id,
            ], ['id']);

            if (!empty($findCustomer->id)) {
                $platform_customer_id = $findCustomer->id;
                $this->mobj->makeUpdate('platform_customer', $arr_customer, [
                    'id' => $platform_customer_id
                ]);
            } else {
                if($acceptNewOrders){
                    $platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $arr_customer);
                }
            }
        } 


        //orders data
        $arr_order = array();
        $arr_order['user_id'] = $user_id;
        $arr_order['platform_id'] = $this->platformId;

        if($platform_customer_id){
            $arr_order['platform_customer_id'] = $platform_customer_id;
        }
        
        $arr_order['user_integration_id'] = $user_integration_id;
        $arr_order['order_type'] = "SO";
        $arr_order['customer_email'] = $ord['customerEmail'];
        $arr_order['api_order_id'] = @$ord['id'];
        $arr_order['api_order_reference'] = @$ord['externalOrderReference'];
        $arr_order['api_order_payment_status'] = (@$ord['fulfillmentStatus']=='FULFILLED')? 'paid' : 'unpaid';
        $arr_order['order_number'] = @$ord['orderNumber'];
        $arr_order['order_date'] = date('Y-m-d H:i:s', strtotime($ord['createdOn']));
        $arr_order['order_status'] = @$ord['fulfillmentStatus'];
        $arr_order['delivery_date'] = @$ord['fulfilledOn'];
        $arr_order['currency'] = @$ord['grandTotal']['currency'];
        $arr_order['ship_speed'] = @$ord['fulfillments'][0]['service'];
        $arr_order['carrier_code'] = @$ord['fulfillments'][0]['carrierName'];
        

        $order_details = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_order_id' => @$ord['id']], ['id','sync_status']);



        if ($order_details) {
            $platform_order_id = $order_details->id;

            if($order_details->sync_status=='Pending'){

                if( isset($ord['orderNumber']) && $ord['orderNumber'] ) {
                    $arr_order['sync_status'] = 'Ready';
                }
              
            }
            if($order_details->sync_status !='Synced'){
                $this->mobj->makeUpdate('platform_order', $arr_order, ['id' => $platform_order_id]);
            }
           
        } else {
            if($acceptNewOrders){
                $arr_order['sync_status'] = 'Ready';
                $platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
            }
        }

        // $order_shipping_method = NULL;
        //store order items
        foreach (@$ord['lineItems'] as $lineitem) {

            $arr_order_line = array();
            $arr_order_line['platform_order_id'] = $platform_order_id;
            $arr_order_line['row_type'] = 'ITEM';
            $arr_order_line['api_product_id'] = @$lineitem['productId'];
            $arr_order_line['product_name'] = @$lineitem['productName'];
            $arr_order_line['api_order_line_id'] = @$lineitem['id'];
            $arr_order_line['variation_id'] = isset($lineitem['variantId']) ? $lineitem['variantId'] : 0;
            $arr_order_line['sku'] = @$lineitem['sku'];
            $arr_order_line['qty'] = isset($lineitem['quantity']) ? $lineitem['quantity'] : 0;
            $arr_order_line['unit_price'] = isset($lineitem['unitPricePaid']['value']) ? floatval($lineitem['unitPricePaid']['value']) : 0;
            $arr_order_line['subtotal'] = $arr_order_line['unit_price'] * $arr_order_line['qty'];
            $arr_order_line['total'] = $arr_order_line['subtotal'];

            $ct_order_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'sku' => @$arr_order_line['sku'], 'row_type' => 'ITEM']);

            if ($ct_order_line > 0) {
                $this->mobj->makeUpdate('platform_order_line', $arr_order_line, ['platform_order_id' => $platform_order_id, 'sku' => @$arr_order_line['sku'], 'row_type' => 'ITEM']);
            } else {
                if($acceptNewOrders){
                    $this->mobj->makeInsert('platform_order_line', $arr_order_line);
                }
            }
        }

        //store shippingLines 
        foreach (@$ord['shippingLines'] as $shiplineitem) {

            $arr_shipping_line = array();
            $arr_shipping_line['platform_order_id'] = $platform_order_id;
            $arr_shipping_line['row_type'] = 'SHIPPING';
            $arr_shipping_line['product_name'] = 'shipping charge';
            $arr_shipping_line['description'] = 'shipping charge '.@$shiplineitem['method'];
            $arr_shipping_line['price'] = isset($shiplineitem['amount']['value'])? floatval($shiplineitem['amount']['value']) : 0;
            $arr_shipping_line['qty'] = 1;
            $arr_shipping_line['subtotal'] = isset($shiplineitem['amount']['value'])? floatval($shiplineitem['amount']['value']) : 0;
            $arr_shipping_line['total'] = isset($shiplineitem['amount']['value'])? floatval($shiplineitem['amount']['value']) : 0;
            // $order_shipping_method = @$shiplineitem['method'];

            $ct_shipping_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 
            'row_type' => 'SHIPPING']);

            if ($ct_shipping_line > 0) {
                $this->mobj->makeUpdate('platform_order_line', $arr_shipping_line, ['platform_order_id' => $platform_order_id, 
                'row_type' => 'SHIPPING']);
            } else {
                if($acceptNewOrders){
                    $this->mobj->makeInsert('platform_order_line', $arr_shipping_line);
                }
            }
        }

        //store discountLines 
        foreach (@$ord['discountLines'] as $discountLineItem) {
            $arr_discount_line = array();
            $arr_discount_line['platform_order_id'] = $platform_order_id;
            $arr_discount_line['row_type'] = 'DISCOUNT';
            $arr_discount_line['product_name'] = isset($discountLineItem['name'])? $discountLineItem['name'] : 'Discount';
            $arr_discount_line['description'] = isset($discountLineItem['description'])? $discountLineItem['description'] : 'Discount';
            $arr_discount_line['price'] = isset($discountLineItem['amount']['value']) ? floatval('-'.$discountLineItem['amount']['value']) : 0;
            $arr_discount_line['qty'] = (@$discountLineItem['amount']['value'] ) ? 1 : 0;
            $arr_discount_line['subtotal'] = isset($discountLineItem['amount']['value']) ? floatval('-'.$discountLineItem['amount']['value']) : 0;
            $arr_discount_line['total'] = isset($discountLineItem['amount']['value']) ? floatval('-'.$discountLineItem['amount']['value']) : 0;
            //insert promo code for diffrenciate multiple discount
            $arr_discount_line['api_code'] = isset($discountLineItem['promoCode'])? $discountLineItem['promoCode'] : '';
           
            
            $ct_discount_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 
            'row_type' => 'DISCOUNT', 'api_code' =>$discountLineItem['promoCode']]);
            
            if ($ct_discount_line > 0) {
                $this->mobj->makeUpdate('platform_order_line', $arr_discount_line, ['platform_order_id' => $platform_order_id, 
                'row_type' => 'DISCOUNT', 'api_code' => $discountLineItem['promoCode'] ]);
            } else {
               if($acceptNewOrders){
                   $this->mobj->makeInsert('platform_order_line', $arr_discount_line);
               }
            }

        }

        //Store Total tax in platform_order_line if exists
        if(isset($ord['taxTotal']))
        {
            $arr_tax_line = array();
            $arr_tax_line['platform_order_id'] = $platform_order_id;
            $arr_tax_line['row_type'] = 'TAX';
            $arr_tax_line['product_name'] = 'Tax Total';
            $arr_tax_line['description'] = 'Tax Amount';
            $arr_tax_line['qty'] = (@$ord['taxTotal']['value'] > 0)? 1 : 0;
            //store tax as item
            $arr_tax_line['subtotal'] = isset($ord['taxTotal']['value']) ? floatval($ord['taxTotal']['value']) : 0;
            $arr_tax_line['price'] = isset($ord['taxTotal']['value']) ? floatval($ord['taxTotal']['value']) : 0;

            $ct_tax_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 
            'row_type' => 'TAX']);

            if ($ct_tax_line > 0) {
                $this->mobj->makeUpdate('platform_order_line', $arr_tax_line, ['platform_order_id' => $platform_order_id, 
                'row_type' => 'TAX']);
            } else {
                if($arr_tax_line['qty'] > 0)
                {
                    if($acceptNewOrders){
                        $this->mobj->makeInsert('platform_order_line', $arr_tax_line);
                    }
                }
                
            }
        }


        //update total amount into order table
        $ordtotal['total_discount'] =  @$ord['discountTotal']['value'];
        $ordtotal['total_tax'] =  @$ord['taxTotal']['value'];
        $ordtotal['total_amount'] =  @$ord['grandTotal']['value'];
        $ordtotal['shipping_total'] =  @$ord['shippingTotal']['value'];
        $ordtotal['net_amount'] =  @$ord['grandTotal']['value'];
        // $ordtotal['shipping_method'] =  $order_shipping_method;
        $this->mobj->makeUpdate('platform_order', $ordtotal, ["id" => $platform_order_id]);

        
        //if shipping address exist then store this otherwise store billing address
        if(isset($ord['shippingAddress'])){
            $address = $ord['shippingAddress'];
        } else if(isset($ord['billingAddress'])){
            $address = $ord['billingAddress'];
        } else {
            $address = "";
        }
        //if address true
        if($address){
            $arr_order_address = array();
            $arr_order_address['platform_order_id'] = $platform_order_id;
            $arr_order_address['address_type'] = 'Shipping';
            $arr_order_address['firstname'] = @$address['firstName'];
            $arr_order_address['lastname'] = @$address['lastName'];
            $arr_order_address['address_name'] = @$address['firstName'].' '.@$address['lastName'];
            $arr_order_address['address1'] = @$address['address1'];
            $arr_order_address['address2'] = @$address['address2'];
            $arr_order_address['address3'] = @$address['city'];
            $arr_order_address['city'] = @$address['city'];
            $arr_order_address['state'] = @$address['state'];
            $arr_order_address['postal_code'] = @$address['postalCode'];
            $arr_order_address['country'] = @$address['countryCode'];
            $arr_order_address['phone_number'] = @$address['phone'];
            $arr_order_address['ship_speed'] = @$ord['fulfillments'][0]['service'];
            $arr_order_address['carrier_code'] = @$ord['fulfillments'][0]['carrierName'];
            $arr_order_address['email'] = $ord['customerEmail'];

            $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);

            if ($ct_address > 0) {
                $this->mobj->makeUpdate('platform_order_address', $arr_order_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);
            } else {
                if($acceptNewOrders){
                    $this->mobj->makeInsert('platform_order_address', $arr_order_address);
                }
            }
        }
        

        //if billing address exist then store this otherwise store shipping address
        if(isset($ord['billingAddress'])){
            $billaddress = $ord['billingAddress'];
        } else if(isset($ord['shippingAddress'])){
            $billaddress = $ord['shippingAddress'];
        } else {
            $billaddress = "";
        }
        //if billing address true
        if($billaddress){
            $arr_order_bill_address = array();
            $arr_order_bill_address['platform_order_id'] = $platform_order_id;
            $arr_order_bill_address['address_type'] = 'Billing';
            $arr_order_bill_address['firstname'] = @$billaddress['firstName'];
            $arr_order_bill_address['lastname'] = @$billaddress['lastName'];
            $arr_order_bill_address['address_name'] = @$billaddress['firstName'].' '.@$billaddress['lastName'];
            $arr_order_bill_address['address1'] = @$billaddress['address1'];
            $arr_order_bill_address['address2'] = @$billaddress['address2'];
            $arr_order_bill_address['address3'] = @$billaddress['city'];
            $arr_order_bill_address['city'] = @$billaddress['city'];
            $arr_order_bill_address['state'] = @$billaddress['state'];
            $arr_order_bill_address['postal_code'] = @$billaddress['postalCode'];
            $arr_order_bill_address['country'] = @$billaddress['countryCode'];
            $arr_order_bill_address['phone_number'] = @$address['phone'];
            $arr_order_bill_address['ship_speed'] = @$ord['fulfillments'][0]['service'];
            $arr_order_bill_address['carrier_code'] = @$ord['fulfillments'][0]['carrierName'];
            $arr_order_bill_address['email'] = $ord['customerEmail'];


            $bill_ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);

            if ($bill_ct_address > 0) {
                $this->mobj->makeUpdate('platform_order_address', $arr_order_bill_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);
            } else {
                if($acceptNewOrders){
                    $this->mobj->makeInsert('platform_order_address', $arr_order_bill_address);
                }
            }
        }
        

        return true;

    }



   /* Insert Update Product Attributes */
   public function CreateOrUpdateProductAttributes($ProductID = NULL, $PostData = [])
   {

       if ($ProductID && !empty($PostData)) {
           $find = $this->mobj->getFirstResultByConditions('platform_product_detail_attributes', [
               'platform_product_id' => $ProductID
           ], ['id']);
           if ($find) {
               $this->mobj->makeUpdate('platform_product_detail_attributes', $PostData, [
                   'platform_product_id' => $ProductID,
               ]);
           } else {
               $this->mobj->makeInsert('platform_product_detail_attributes', $PostData);
           }
       }
   }
   /* Create or Update Product Prices */
   public function CreateOrUpdateProductPrice($ProductPrimaryID, $ObjectDataPrimaryId, $PostData)
   {
       if ($ProductPrimaryID && !empty($PostData)) {
           $find = $this->mobj->getFirstResultByConditions('platform_porduct_price_list', [
               'platform_product_id' => $ProductPrimaryID,
               'platform_object_data_id' => $ObjectDataPrimaryId,
           ], ['id']);

           if ($find) {
               $this->mobj->makeUpdate('platform_porduct_price_list', $PostData, [
                   'id' => $find->id,
               ]);
           } else {
               $this->mobj->makeInsert('platform_porduct_price_list', $PostData);
           }
       }
   }  
   /* Create Price List */
   public function CreatePriceList($ProductPrimaryID, $ObjectName, $Price = NULL, $SalePrice = NULL, $RegularPrice = NULL)
   {
       if ($ProductPrimaryID) {
           $ObjectId = $this->helper->getObjectId($ObjectName);

           if ($ObjectId) {
               $find = $this->mobj->getResultByConditions('platform_object_data', [
                   'user_id' => 0,
                   'user_integration_id' => 0,
                   'platform_id' => $this->platformId,
                   'platform_object_id' => $ObjectId,
               ], ['id', 'api_id']);

               if (!empty($find)) {
                   $priceArr = [];
                   foreach ($find as $key => $value) {
                       $priceArr[$value->id] = $value->api_id;
                   }

                   if (!empty($priceArr)) {
                       
                       $sale_price_object_data_id = array_search("salePrice", $priceArr);
                       $price_object_data_id = array_search("price", $priceArr);
                       $regular_price_object_data_id = array_search("basePrice", $priceArr);

                       if (!empty($Price) && $Price && $price_object_data_id) {
                           $this->CreateOrUpdateProductPrice($ProductPrimaryID, $price_object_data_id, ['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $price_object_data_id, 'price' => $Price]);
                       }
                       if (!empty($SalePrice) && $SalePrice && $sale_price_object_data_id) {
                           $this->CreateOrUpdateProductPrice($ProductPrimaryID, $sale_price_object_data_id, ['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $sale_price_object_data_id, 'price' => $SalePrice]);
                       }
                       if (!empty($RegularPrice) && $RegularPrice && $regular_price_object_data_id) {
                           $this->CreateOrUpdateProductPrice($ProductPrimaryID, $regular_price_object_data_id, ['platform_product_id' => $ProductPrimaryID, 'platform_object_data_id' => $regular_price_object_data_id, 'price' => $RegularPrice]);
                       }
                   }
               }
           }
       }
   }
   /* Get Options */
   public function GetProductAttributes($arr, $productId)
   {
       if (!empty($arr)) {
           //Set Status 0
           PlatformProductOption::where('platform_product_id', $productId)->update(['status' => 0]);
           $find = PlatformProductOption::where([['platform_product_id', '=', $productId], ['api_option_id', '=', $arr['api_option_id']]])->first();
           if ($find) {
               $find->api_option_id = $arr['api_option_id'];
               $find->option_name = $arr['option_name'];
               $find->option_value = $arr['option_value'];
               $find->status = 1;
               $find->save();
           } else {
               PlatformProductOption::insert($arr);
           }
       }
   } 
   /* Prepare Modal Data to store update product */
   public function PrepareModalData($value, $user_id, $user_integration_id, $platform_id, $attribute = false)
   {    
       $ProductPrimaryID = NULL;

       $categories = NULL;
       if (isset($value['categories'])) {
           foreach ($value['categories'] as $key => $cat) {
               $categories .= $cat['id'] . ",";
           }
           $categories = rtrim($categories, ",");
       }
       $productList = array(
           'user_id' => $user_id,
           'user_integration_id' => $user_integration_id,
           'platform_id' => $platform_id,
           'api_product_id' => $value['id'],
           'api_updated_at' => $value['modifiedOn'],
           'product_name' =>  $value['name'],
           'description' => $value['description'],
           'product_status' => ($value['isVisible']==1)? 'LIVE' : NULL,
           'product_sync_status' => "Ready",
           'is_deleted' => 0,
           'category_id' =>  $categories,
           'sku' => isset($value['variants'][0]['sku'])? $value['variants'][0]['sku'] : NULL,
           'weight' => isset($value['variants'][0]['shippingMeasurements']['weight']['value'])? $value['variants'][0]['shippingMeasurements']['weight'] ['value'] : NULL,
           'weight_unit' => isset($value['variants'][0]['shippingMeasurements']['weight']['unit'])? $value['variants'][0]['shippingMeasurements']['weight']['unit'] : NULL,
       );

       if( isset($value['variants'][0]['shippingMeasurements']['dimensions']) )
       {
           $variantData = $value['variants'][0]['shippingMeasurements']['dimensions'];
           $AttributeData = [
            'lenght' => ($variantData['length'])? $variantData['length'] : NULL,
            'height' => ($variantData['height'])? $variantData['height']: NULL,
            'width' => ($variantData['width'])? $variantData['width'] : NULL
        ];
       } else { $AttributeData = []; }

       $findHook = $this->mobj->getFirstResultByConditions('platform_product', [
           'user_integration_id' => $user_integration_id,
           'platform_id' => $platform_id,
           'api_product_id' => $value['id'],
       ], ['id']);
       if ($findHook) {
           $this->mobj->makeUpdate(
               'platform_product',
               $productList,
               ['id' => $findHook->id]
           );

           $AttributeData['platform_product_id'] = $findHook->id;
           $this->CreateOrUpdateProductAttributes($findHook->id, $AttributeData);

           if( isset($value['variants'][0]['pricing']) )
           {
                $priceArr = $value['variants'][0]['pricing'];
                $respPrice = $this->CreatePriceList($findHook->id, "pricelist", $priceArr['basePrice']['value'], $priceArr['salePrice']['value'], $priceArr['basePrice']['value']);
           }

           //currently not comming
           if ($attribute) {
               if ( isset($value['variants'][0]['attributes']) && $value['variants'][0]['attributes'] ) {
                   foreach ($value['variants'][0]['attributes']  as $attr) {
                       $optionarr[] = isset($attr['option']) ? $attr['option'] : $attr['options']; //assing if option and options available
                       if (!empty($optionarr)) {
                           //If multiple option available
                           foreach ($optionarr as $option) {
                               $attrOption = [
                                   'api_option_id' => $attr['id'],
                                   'platform_product_id' => $findHook->id,
                                   'option_name' => isset($attr['name']) ? isset($attr['name']) : NULL,
                                   'option_value' => $option,
                                   'status' => 1
                               ];
                               $this->GetProductAttributes($attrOption, $findHook->id);
                           }
                       }
                   }
               }
           }

           $ProductPrimaryID = $findHook->id;
       } else {
           $productLinkId = $this->mobj->makeInsertGetId('platform_product', $productList);
           $AttributeData['platform_product_id'] = $productLinkId;
           $this->CreateOrUpdateProductAttributes($productLinkId,  $AttributeData);

           if( isset($value['variants'][0]['pricing']) )
           {
                $priceArr = $value['variants'][0]['pricing'];
                $respPrice = $this->CreatePriceList($productLinkId, "pricelist", $priceArr['basePrice']['value'], $priceArr['salePrice']['value'], $priceArr['basePrice']['value']);
           }

           if ($attribute) {

               if (isset($value['variants'][0]['attributes']) && $value['variants'][0]['attributes']) {
                   foreach ($value['variants'][0]['attributes']  as $attr) {

                       $optionarr[] = isset($attr['option']) ? $attr['option'] : $attr['options']; //assing if option and options available
                       if (!empty($optionarr)) {
                           //If multiple option available
                           foreach ($optionarr as $option) {
                               $attrOption = [
                                   'api_option_id' => $attr['id'],
                                   'platform_product_id' => $productLinkId,
                                   'option_name' => isset($attr['name']) ? isset($attr['name']) : NULL,
                                   'option_value' => $option,
                                   'status' => 1
                               ];
                               $this->GetProductAttributes($attrOption, $productLinkId);
                           }
                       }
                   }
               }
           }

           $ProductPrimaryID = $productLinkId;
       }
       return  $ProductPrimaryID;
   }
    /* GetProducts  */ 
   public function GetProducts($userId = NULL, $user_integration_id = NULL, $is_initial_syn = 0)
   {
       
        date_default_timezone_set('UTC');

        $return_response = false;
        try {

            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'account_name', 'app_id']);

            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

                    $account_name = $ufound->account_name;
                    $authHash = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
                    
                    if ($is_initial_syn == 1) { // get all prducts by one time in loop
                        $x = 1;
                        $nextPageCursor = null;
                        while ($x <= 10) {
                            $allowLoop = true;
                            $pageNo = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $user_integration_id], ['platform_id', '=', $this->platformId], ['url_name', '=', 'products']])->first();
            
                            if (isset($pageNo)) {
                                if ($pageNo->url == NULL && $pageNo->status == 1) {
                                    $allowLoop = false;
                                    $nextPageCursor = NULL;
                                } else {
                                    $nextPageCursor = $pageNo->url;
                                }
                            } else {
                                $nextPageCursor = NULL;
                            }
                            if ($allowLoop) {
                                $breakCounter = 0;

                                if($nextPageCursor) {
                                    $apiEndpoint = "commerce/products?cursor=".$nextPageCursor;
                                } else {
                                    $apiEndpoint = "commerce/products";
                                }
                                
                                $product = $this->squarespace->ApiCall($authHash, $apiEndpoint);

                                //if get
                                if( isset($product['pagination']) && ($product['pagination']['hasNextPage']==true)){
                                    $nextPageCursor = isset( $product['pagination']['nextPageCursor'] )? $product['pagination']['nextPageCursor'] : NULL;
                                } else {
                                    $nextPageCursor = NULL;
                                }

                                //if error occure squarespace throw authorization_error in all kind of invalid api call
                                if (isset($product['type']) && ( $product['type']=="AUTHORIZATION_ERROR" || $product['type']=="INVALID_REQUEST_ERROR")) {
                                    $breakCounter = 1;
                                    break;
                                } 

                                if ( isset ($product['products']) ) {
                                    foreach ($product['products'] as $key => $value) {
                                        $ProductPrimaryID = $this->PrepareModalData($value, $userId, $user_integration_id, $this->platformId);
                                    }
                                    if ($breakCounter == 0) {

                                        if (isset($pageNo)) {   

                                            if($nextPageCursor){
                                                $pageNo->url = $nextPageCursor;
                                                $pageNo->status = 0;
                                                $pageNo->save();
                                            } else {
                                                $pageNo->url = $nextPageCursor;
                                                $pageNo->status = 1;
                                                $pageNo->save();
                                            }
                                            
                                        } else {
                                            //if next page cursor available then insert
                                            if($nextPageCursor)
                                            {
                                                PlatformUrl::insert([
                                                    'user_id' => $userId, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,
                                                    'url' => $nextPageCursor,
                                                    'url_name' => 'products',
                                                    'status' => 0
                                                ]);
                                            } 
                                        }

                                        $return_response = ($nextPageCursor)? "Product chunk data processed" : true;
                        
                                    } else {
                                        $return_response = "API Error to get products from squarespace";
                                    }
                                   
                                } else {
                                    if (isset($pageNo)) {
                                        $pageNo->url = NULL;
                                        $pageNo->status = 1;
                                        $pageNo->save();
                                    }
                                    $return_response = true;
                                }
                            } 
                            else {
                                $return_response = true;
                            }
                            $x++;
                        }

                    }

                    if ($is_initial_syn == 0) { //Get last modofied product after initial sync
                       
                            $allowLoop = true;
                            $modifiedAfter = "";

                            $lastModifiedData = PlatformProduct::select('api_updated_at')->where([['user_integration_id', '=', $user_integration_id], ['platform_id', '=', $this->platformId]])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();

                            $modifiedBefore = date("Y-m-d\TH:i:s.u\Z", strtotime(date("Y-m-d H:i:s")) );
                            if ($lastModifiedData) {
                                //square accept ISO 8601 UTC date in filter
                                $modifiedAfter = $lastModifiedData->api_updated_at;
                            } else {
                                $allowLoop = false;
                            }

                            if ($allowLoop) {
                                $apiEndpoint = "commerce/products?modifiedAfter=".$modifiedAfter."&modifiedBefore=".$modifiedBefore;
                                $product = $this->squarespace->ApiCall($authHash, $apiEndpoint);

                                // \Storage::disk('local')->append('squarespace_callback.txt','New Product After Initial Sync '.json_encode($product,true));

                                if (isset($product['type']) && ( $product['type']=="AUTHORIZATION_ERROR" || $product['type']=="INVALID_REQUEST_ERROR")) 
                                {
                                    //when error occures
                                } 
                                
                                if ( isset ($product['products']) ) {
                                    foreach ($product['products'] as $key => $value) {
                                        $ProductPrimaryID = $this->PrepareModalData($value, $userId, $user_integration_id, $this->platformId);
                                    }
                                } else {
                                    $return_response = true;
                                }
                            } 
                            else {
                                $return_response = true;
                            }
                    }

                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;

   }


   /* Create Webhook */
   public function CreateOrDeleteWebhook($userId = NULL, $user_integration_id = NULL, array $wooksType, $attempt)
   {
        $return_response = false;
        try {

            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id,  $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

            if ($ufound && $this->platformId) {
                if (isset($ufound->platform_id) && $ufound->platform_id ==  $this->platformId) {
                    if ($attempt == 1) { // create webhook
                        /* Please pass last param as 0=for staging mode and 1=for live mode */
                        if (!empty($wooksType)) {
                            $Baseurl = env('APP_WEBHOOK_URL');
        
                            $arraywebhooklist = array();
                            /* Please pass last param as if APP_ENV=stag or local then 0 for staging/local mode and APP_ENV=prod then 1=for live mode */
                            $Mode = env('APP_ENV') == 'prod' ? "1" : "0";

                            $check_already_subscribed = DB::table('platform_webhook_info')->where('user_integration_id', $user_integration_id)->where('platform_id', $ufound->platform_id)->where('status', 1)->pluck('description')->toArray();

                            //create order webhook
                            if (in_array('order', $wooksType) && (!in_array('order.create', $check_already_subscribed))) {

                                $webhookFor = "order";

                                $arraywebhooklist[] = [
                                    'endpointUrl' => $Baseurl."/squarespace/index.php?for=$webhookFor&uid=$user_integration_id&env=$Mode",
                                    'topics' => ['order.create']
                                ];
                                // $arraywebhooklist[] = [
                                //     'topics' => ['order.update'],
                                //     'endpointUrl' => $Baseurl."/squarespace/index.php?for=$webhookFor&uid=$user_integration_id&env=$Mode"
                                // ];

                            }
                          
                    
                            if (!empty($arraywebhooklist)) {
                                $message = [];
                                $error_message = '';
                                foreach ($arraywebhooklist as $row) {

                                    $formatedPostData = '{
                                        "endpointUrl": "'.$row['endpointUrl'].'",
                                        "topics": ["'.$row['topics'][0].'"]
                                    }';

                                    $authHash = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');
                                    $apiEndpoint = "webhook_subscriptions";
       
                                    $webhook = $this->squarespace->ApiCall($authHash,$apiEndpoint, $formatedPostData, "POST");

                                    // \Storage::disk('local')->append('squarespace_callback.txt','Create webhook Resp '.print_r($webhook,true));

                                    if ($webhook) {
                                        //success

                                        if (!empty($webhook)) {

                                            if( isset($webhook['id']) ){
                                                //insert webhook log
                                                $webhookdetails = ['user_id' => $userId, 'user_integration_id' => $user_integration_id, 'platform_id' => $ufound->platform_id,  'api_id' => $webhook['id'],  'description' => $row['topics'][0], 'status' => 1];
                                                $this->mobj->makeInsert('platform_webhook_info', $webhookdetails);
                                            } else {
                                                $message[] = 'There is something went wrong to create webhook';
                                            } 
                                            
                                        } else if (isset($webhook['error']) || (isset($webhook['errors']) && !isset($webhook['errors'][0]['code']))) {
                                            if (isset($webhook['errors'][0]['message'])) {
                                                $message[] = $this->squarespace->handleResponseError($webhook);
                                            } else {
                                                $message[] = $webhook['error'];
                                            }
                                        }
                                    } else {
                                        //Log webhook creation response
                                        $message[] = 'There is something went wrong to create webhook';
                                        // $message[] = (isset($webhook['response'])) ? $webhook['response'] : json_encode($res);
                                    }

                                }

                                if (!empty($message)) {
                                    $error_message = implode(" | ", $message);
                                }

                                if (empty($message)) {
                                    $return_response = true;
                                } else {
                                    $return_response = $error_message;
                                }
                            }
                        } else {
                            $return_response = "error can not create webhook";
                        }
                    } else  if ($attempt == 2) { // delete webhook
                        if (!empty($wooksType)) {

                            if (in_array('all', $wooksType)) {
                                $hookList = $this->mobj->getResultByConditions('platform_webhook_info', [
                                    'user_integration_id' => $user_integration_id,
                                    'platform_id' => $ufound->platform_id
                                ], ['api_id'], ['id' => 'asc']);

                                if ($hookList->count() > 0) {
                                    $hook = $hookList->pluck('api_id')->toArray();

                                    foreach ($hook as $key => $value) {
                                        $response = $this->DeleteWebhook($value, $user_integration_id);
                                        if (!is_bool($response)) {
                                            $this->mobj->makeDelete(
                                                'platform_webhook_info',
                                                ['user_id' => $userId, 'platform_id' => $ufound->platform_id, 'user_integration_id' => $user_integration_id, 'api_id' => $value]
                                            );
                                        }
                                    }
                                }
                                $return_response = true;
                            } else {
                                $hookList = DB::table('platform_webhook_info')->where([
                                    [
                                        'user_integration_id', '=', $user_integration_id
                                    ],
                                    ['platform_id', '=', $ufound->platform_id]
                                ])->whereIn('api_id', $wooksType)->get();

                                if ($hookList->count() > 0) {
                                    $hook = $hookList->pluck('api_id')->toArray();
                                    foreach ($hook as $key => $value) {
                                        $response = $this->DeleteWebhook($value, $user_integration_id);

                                        if (!is_bool($response)) {
                                            $this->mobj->makeDelete(
                                                'platform_webhook_info',
                                                ['user_id' => $userId, 'platform_id' => $ufound->platform_id, 'user_integration_id' => $user_integration_id, 'api_id' => $value]
                                            );
                                        }
                                    }
                                    $return_response = true;
                                }
                            }
                        } else {
                            $return_response = "Error: can not delete webhook";
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $return_response = $e->getMessage();
        }
        return $return_response;
   } 

   
   public function GetTransactionInfo($userId = NULL, $user_integration_id = NULL, $is_initial_syn = 0)
   {    
    date_default_timezone_set('UTC');
    $return_response = false;
    try {

        $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'account_name', 'app_id']);

        if ($ufound && $this->platformId) {
            if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

                $account_name = $ufound->account_name;
                $authHash = $this->mobj->encrypt_decrypt($ufound->access_token, $action = 'decrypt');

                $lastModifiedData = DB::table('platform_order')
                ->where('user_integration_id',$user_integration_id)
                ->where('platform_id',$this->platformId)
                ->where('transaction_sync_status','Pending')
                ->select('id','order_date')
                ->orderByRaw("DATE_FORMAT(order_date, '%Y-%m-%d %H-%i-%s') ASC")->first();     



                //if order with pending 
                if($lastModifiedData){

                    //check last transaction in URL
                    $lastTransactionFromURL = PlatformUrl::select('url', 'id', 'status','updated_at')->where([['user_integration_id', '=', $user_integration_id], ['platform_id', '=', $this->platformId], ['url_name', '=', 'Last_Transaction_Time']])->first();
                    if (isset($lastTransactionFromURL)) {
                        $order_date = $lastTransactionFromURL->url;
                    } else {
                        $order_date = $lastModifiedData->order_date;
                    }

                    
                    $modifiedAfter = date("Y-m-d\TH:i:s.u\Z", strtotime(date($order_date)) );
                    $modifiedBefore = date("Y-m-d\TH:i:s.u\Z", strtotime(date("Y-m-d H:i:s") ) );

                   
                    // \Storage::disk('local')->append('squarespace_callback.txt', 'Called Squarespace payment api call modifiedAfter -'.$modifiedAfter. ' modifiedBefore-'.$modifiedBefore. ' authHash - '.$authHash);


                    //start comment below can when webhook set
                    $last_transaction_time = null;
                    $last_transaction_time_update = false;
                    $x = 1;
                    $nextPageCursor = null;


                    while ($x <= 3) {

                        $allowLoop = true;
                        
                        $pageNo = PlatformUrl::select('url', 'id', 'status','updated_at')->where([['user_integration_id', '=', $user_integration_id], ['platform_id', '=', $this->platformId], ['url_name', '=', 'Transaction_info']])->first();

                        if (isset($pageNo)) {
                            if ($pageNo->url == NULL) {
                                $nextPageCursor = NULL;
                            } else {
                                $nextPageCursor = $pageNo->url;
                            }
                        } else {
                            $nextPageCursor = NULL;
                        }


                        if ($allowLoop) {

                            $breakCounter = 0;

                            if($nextPageCursor) {
                                $apiEndpoint = "commerce/transactions?modifiedAfter=".$modifiedAfter."&modifiedBefore=".$modifiedBefore."&cursor=".$nextPageCursor;
                            } else {
                                $apiEndpoint = "commerce/transactions?modifiedAfter=".$modifiedAfter."&modifiedBefore=".$modifiedBefore;
                            }
                            
                            $transactionDoc = $this->squarespace->ApiCall($authHash, $apiEndpoint);
        

                            //if get
                            if( isset(
                                $transactionDoc['pagination']) && ($transactionDoc['pagination']['hasNextPage']==true)){
                                $nextPageCursor = isset( $transactionDoc['pagination']['nextPageCursor'] )? $transactionDoc['pagination']['nextPageCursor'] : NULL;
                            } else {
                                $nextPageCursor = null;
                            }
                        
                           

                            //handle api error
                            if ( isset($transactionDoc['type']) && ( $transactionDoc['type']=="AUTHORIZATION_ERROR" || $transactionDoc['type']=="INVALID_REQUEST_ERROR")) {

                                \Storage::disk('local')->append('squarespace_callback.txt','Transaction info error '.json_encode($transactionDoc,true));

                                $breakCounter = 1;
                                break;
                            } 

                            if ( isset($transactionDoc['documents']) ) {
                                
                               
                                foreach ($transactionDoc['documents'] as $key => $value) {

                                    //check createdOn if less than modifiedAfter then make nextPageCursor null to avoid cal before given
                                    if( strtotime($value['createdOn']) < strtotime($modifiedAfter) )
                                    {
                                        $nextPageCursor = null;
                                    }

                                    //update last transaction time
                                    if( isset($value['createdOn']) && $last_transaction_time_update==false){

                                        $last_transaction_time = $value['createdOn'];
                                        $last_transaction_time_update==true;

                                    }

                                    // \Storage::disk('local')->append('squarespace_callback.txt','Transaction info insertUpdatePaymentDetails for userInteg-'.$user_integration_id.' data -'.json_encode($value,true));


                                    //insert update transaction data
                                    $this->insertUpdatePaymentDetails($userId,$user_integration_id,$this->platformId,$value);

                                }

                                if ($breakCounter == 0) {

                                    if (isset($pageNo)) {

                                        $pageNo->url = $nextPageCursor;
                                        $pageNo->status = 0;
                                        $pageNo->save();
                                        
                                    } else {
                                        //if next page cursor available then insert
                                        if( $nextPageCursor && empty($pageNo) )
                                        {
                                            PlatformUrl::insert([
                                                'user_id' => $userId, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,
                                                'url' => $nextPageCursor,
                                                'url_name' => 'Transaction_info',
                                                'status' => 0
                                            ]);
                                        } 
                                    }

                                    $return_response = ($nextPageCursor)? "Transaction info chunk data processed - ".$last_transaction_time : true;

                                } else {
                                    $return_response = "API Error to get transaction info from squarespace";
                                }
                            
                            } else {
                                if (isset($pageNo)) {
                                    $pageNo->url = NULL;
                                    $pageNo->save();
                                }
                                $return_response = true;
                            }
                            
                        } 
                        else {
                            $return_response = true;
                        }
                        $x++;
                    }


                    //at the end of loop insert or updated transaction date time for Last_Transaction_Info
                    if($lastTransactionFromURL) {

                        //check updating last transaction time is greater than data from db
                        if(  strtotime($last_transaction_time) > strtotime($order_date) )
                        {
                             //update in url for last transaction
                            if($last_transaction_time){
                                $lastTransactionFromURL->url = $last_transaction_time;
                                $lastTransactionFromURL->save();
                            }

                        } 
                        
                       
                    } else {
                        if($last_transaction_time){
                            PlatformUrl::insert([
                                'user_id' => $userId, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,
                                'url' => $last_transaction_time,
                                'url_name' => 'Last_Transaction_Time',
                                'status' => 0
                            ]);    
                        }
                    }


                    //end 
                }

            }
        }
            

    } catch (\Exception $e) {
        \Log::error($e->getMessage());
        $return_response = $e->getMessage();
    }
    return $return_response;
   }
   
   //insert update transaction info
   public function insertUpdatePaymentDetails($user_id, $user_integration_id, $platform_id, $trans)
   {
        $transactionId = @$trans['id'];
        if(isset($trans['total'])){
            $totalNetPayment = @$trans['total']['value'];
            $paymentCurrencyCode = @$trans['total']['currency'];
        } else if(isset($trans['totalSales'])){
            $totalNetPayment = @$trans['totalSales']['value'];
            $paymentCurrencyCode = @$trans['totalSales']['currency'];
        } else {
            $totalNetPayment = "";
            $paymentCurrencyCode = "";
        }

       
        $arr_order = array();
        $arr_order['api_order_payment_status'] = 'paid';
        $arr_order['transaction_sync_status'] = 'Ready';

        $paidOn = ""; 
        $paymentArr = @$trans['payments'];
        if( count($paymentArr) > 0 ){
            foreach (@$trans['payments'] as $payment){
                $paidOn = $payment['paidOn'];
                $arr_order['payment_date'] = $payment['paidOn'];
            }
        }

        //, 'transaction_sync_status' => 'Pending'
        $order_details = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_order_id' => @$trans['salesOrderId']], ['id','platform_customer_id','sync_status','transaction_sync_status']);

        if ($order_details) {

            $platform_order_id = $order_details->id;
            $platform_customer_id = $order_details->platform_customer_id;
            $order_sync_status = $order_details->sync_status;

        
            //platform order transaction arry
            $arr_pot = array();
            $arr_pot['platform_id'] = $this->platformId;
            $arr_pot['user_integration_id'] = $user_integration_id;
            $arr_pot['platform_order_id'] = $platform_order_id;
            $arr_pot['transaction_id'] = $transactionId;
            $arr_pot['transaction_datetime'] = ($paidOn)? $paidOn : @$trans['createdOn'];
            $arr_pot['transaction_amount'] = $totalNetPayment;
            $arr_pot['sync_status'] = 'Ready';
            $arr_pot['currency_code'] = $paymentCurrencyCode;
            $arr_pot['platform_customer_id'] = $platform_customer_id;
            $arr_pot['row_type'] = 'PAYMENT';


            $order_trans_details = $this->mobj->getFirstResultByConditions('platform_order_transactions', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $platform_order_id ], ['id','sync_status']);
            if($order_trans_details){
                $pot_id = $order_trans_details->id;
                $pot_sync_status = $order_trans_details->sync_status;

                //update order transaction if not synced
                if($pot_sync_status !="Synced"){

                    $this->mobj->makeUpdate('platform_order_transactions', $arr_pot, [
                        'id' => $pot_id
                    ]);

                    // \Storage::disk('local')->append('squarespace_callback.txt', 'Square Payment Update in trans order.id - '.$platform_order_id. ' POT_id '.$pot_id);
                } 

            } else {
                $pot_sync_status = "Ready";
                $pot_id = $this->mobj->makeInsertGetId('platform_order_transactions', $arr_pot);
                // \Storage::disk('local')->append('squarespace_callback.txt', 'Square Payment insert in trans order.id - '.$platform_order_id. ' POT_id '.$pot_id);
            }   

            //update order table
            if($pot_sync_status =="Ready"){

                //check if transaction sync status if ready & order sync status is synced then make it Ready
                // if($order_sync_status=="Synced"){
                //     $arr_order['sync_status'] = 'Ready';
                // }
                $arr_order['sync_status'] = 'Ready';
                
                $this->mobj->makeUpdate('platform_order', $arr_order, ['id' => $platform_order_id]);
                // \Storage::disk('local')->append('squarespace_callback.txt', 'Square Payment update in order  - '.$platform_order_id);
            }

            
        }  
 
   }

   // Execute Squarespacedahl Events
   public function ExecuteSquarespace($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = NULL)
   {
        // \Storage::disk('local')->append('squarespace_callback.txt', 'Called Squarespace Execute Event Function Method-'.$method. ' Event-'.$event);
      try {
        $response = true;

        if ($method == 'GET' && $event == 'PRODUCT') {
            $response = $this->GetProducts($user_id, $user_integration_id, $is_initial_sync);
        } 
        else if ($method == 'GET' && $event == 'SALESORDER') {
            $response = $this->GetSalesOrder($user_id, $user_integration_id, $is_initial_sync);
        }
        else if ($method=='GET' && $event == 'PAYMENT') {
            $response = $this->GetTransactionInfo($user_id, $user_integration_id, $is_initial_sync);
        } 

        return $response;
      } catch (\Exception $e) {
         return $e->getMessage();
      }
   } 

   //test_squarespace
   public function test_squarespace()
   {
        $user_integration_id = 155;
        $account_name = 'wildkidsplay';

        // user_integration_id = 157;
        // $account_name = 'zathletic';

        $accessToken = DB::table('platform_accounts')->where(['account_name'=>$account_name,'platform_id'=>25])->select('access_token')->pluck('access_token')
        ->first();
        $authHash = $this->mobj->encrypt_decrypt($accessToken, $action = 'decrypt');
        $apiEndpoint = "commerce/orders";
        $saleOrders = $this->squarespace->ApiCall($authHash, $apiEndpoint);
        dd($saleOrders);

        // if($saleOrders)
        // {   
        //     $orderPrimaryID = $this->test_insertUpdateWFOrderDetails(175,$user_integration_id,$this->platformId,$saleOrders);
        // }


        // $response = $this->GetTransactionInfo(175, 155);
        // dd($response);


   }


     //insert or update orders
     public function test_insertUpdateWFOrderDetails($user_id, $user_integration_id, $platform_id, $ord, $webhookTopic=null)
     {   
        
         $orderFilter = false;
         $primarySupplierIdFilter = null;
         //get sale order filter values to save that types of orders only 
         $orderFilterObjId = $this->helper->getObjectId('sorder_status_filter');
         $primaryOrderFilter = $this->map->getMappedApiIdByObjectId($user_integration_id, $orderFilterObjId, 'default', 'name');
         if ($primaryOrderFilter) {
             $orderFilter = true;
         }
 
         //get order createdOn
         $order_createdOn = $ord['createdOn'];
         $acceptNewOrders = true;
 
         //handle order Filter By sync start date time...filter from mapping
         $findUserWF = $this->mobj->getFirstResultByConditions('user_workflow_rule', ['user_integration_id' => $user_integration_id], ['sync_start_date']);
         if($findUserWF && $findUserWF->sync_start_date ){
             $formate_sync_start_date = date(DATE_ISO8601, strtotime($findUserWF->sync_start_date));
             if( strtotime( $order_createdOn)  < strtotime($formate_sync_start_date) ) {
                 $acceptNewOrders = false;
             }  
         }
 
 
         /* check order_createdOn > data retention period then store else do not need to store */
         $dataRetentionRow = $this->map->getDataRetentionbyIntegration($user_integration_id);
         if($dataRetentionRow){
             // $dataRetentionPeriod = $dataRetentionRow->pi_drp;
             $dataRetentionPeriod = 1;
             $old_date = date('Y-m-d h:i:s',(strtotime ( '-'.$dataRetentionPeriod.' day' , time()) ));
             $formate_old_date = date(DATE_ISO8601, strtotime($old_date));
             if( strtotime($order_createdOn)  < strtotime($formate_old_date) ) {
                 $acceptNewOrders = false;
             }  
         } 
 
         //if sorder filter is on then that types order only
         if($orderFilter){
             $fullFillmentStatus = @$ord['fulfillmentStatus'];
             //if api fullfillment Status = selected order status on mapping then save
             if($primaryOrderFilter == $fullFillmentStatus){
                 $apiOrderId = @$ord['id'];
                 // \Storage::disk('local')->append('webhook_log.txt', 'OrderFilter Match with Recieved Order - '.$apiOrderId); 
                 //call finalizeStoreUpdate to insert or update orders
                 $this->test_finalizeStoreUpdateOrders($user_id, $user_integration_id, $platform_id, $ord, $acceptNewOrders, $webhookTopic);
             }
         } else {
             //call finalizeStoreUpdate to insert or update orders
             $this->test_finalizeStoreUpdateOrders($user_id, $user_integration_id, $platform_id, $ord, $acceptNewOrders, $webhookTopic);
         }
 
 
     }
 
     //store or update orders  Topics : order.create, order.update
     public function test_finalizeStoreUpdateOrders($user_id, $user_integration_id, $platform_id, $ord, $acceptNewOrders, $webhookTopic=null)
     {
        //return execution if order number is empty
        if( isset($ord['orderNumber']) && empty($ord['orderNumber']) ) {
            return true;
        }
        
         //customer data
         if(isset($ord['shippingAddress'])){
             $cust_address = $ord['shippingAddress'];
         } else if(isset($ord['billingAddress'])){
             $cust_address = $ord['billingAddress'];
         } else {
             $cust_address = "";
         }
 
         $platform_customer_id = "";
 
         if($cust_address){
             $arr_customer = array();
             $arr_customer['type'] = 'Customer';
             $arr_customer['user_id'] = $user_id;
             $arr_customer['platform_id'] = $this->platformId;
             $arr_customer['user_integration_id'] = $user_integration_id;
             $arr_customer['email'] = @$ord['customerEmail'];
             $arr_customer['customer_name'] = $cust_address['firstName'].' '.$cust_address['lastName'];
             $arr_customer['first_name'] = $cust_address['firstName'];
             $arr_customer['last_name'] = $cust_address['lastName'];
             $arr_customer['address1'] = $cust_address['address1'];
             $arr_customer['address2'] = $cust_address['address2'];
             $arr_customer['address3'] = $cust_address['city'];
             $arr_customer['country'] = $cust_address['countryCode'];
             $arr_customer['postal_addresses'] = $cust_address['postalCode'];
             $arr_customer['phone'] = $cust_address['phone'];
             $arr_customer['sync_status'] = 'Ready';
 
             //insert or update in platform customer
             $findCustomer = $this->mobj->getFirstResultByConditions('platform_customer', ['platform_id' => $this->platformId, 'email' => $arr_customer['email'], 
                 'user_integration_id' => $user_integration_id,
             ], ['id']);
 
             if (!empty($findCustomer->id)) {
                 $platform_customer_id = $findCustomer->id;
                 $this->mobj->makeUpdate('platform_customer', $arr_customer, [
                     'id' => $platform_customer_id
                 ]);
             } else {
                 if($acceptNewOrders){
                     $platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $arr_customer);
                 }
             }
         } 
 
 
         //orders data
         $arr_order = array();
         $arr_order['user_id'] = $user_id;
         $arr_order['platform_id'] = $this->platformId;
 
         if($platform_customer_id){
             $arr_order['platform_customer_id'] = $platform_customer_id;
         }
         
         $arr_order['user_integration_id'] = $user_integration_id;
         $arr_order['order_type'] = "SO";
         $arr_order['customer_email'] = $ord['customerEmail'];
         $arr_order['api_order_id'] = @$ord['id'];
         $arr_order['api_order_reference'] = @$ord['externalOrderReference'];
         $arr_order['api_order_payment_status'] = (@$ord['fulfillmentStatus']=='FULFILLED')? 'paid' : 'unpaid';
         $arr_order['order_number'] = @$ord['orderNumber'];
         $arr_order['order_date'] = date('Y-m-d H:i:s', strtotime($ord['createdOn']));
         $arr_order['order_status'] = @$ord['fulfillmentStatus'];
         $arr_order['delivery_date'] = @$ord['fulfilledOn'];
         $arr_order['currency'] = @$ord['grandTotal']['currency'];
         $arr_order['ship_speed'] = @$ord['fulfillments'][0]['service'];
         $arr_order['carrier_code'] = @$ord['fulfillments'][0]['carrierName'];
         
 
         $order_details = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_order_id' => @$ord['id']], ['id','sync_status']);
 


         if ($order_details) {
             $platform_order_id = $order_details->id;
 
             if($order_details->sync_status=='Pending'){
                 $arr_order['sync_status'] = 'Ready';
             }
             if($order_details->sync_status !='Synced'){
                 $this->mobj->makeUpdate('platform_order', $arr_order, ['id' => $platform_order_id]);
             }
            
         } else {
             if($acceptNewOrders){
                 $arr_order['sync_status'] = 'Ready';
                 $platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
             }
         }
 
         // $order_shipping_method = NULL;
         //store order items
         foreach (@$ord['lineItems'] as $lineitem) {
 
             $arr_order_line = array();
             $arr_order_line['platform_order_id'] = $platform_order_id;
             $arr_order_line['row_type'] = 'ITEM';
             $arr_order_line['api_product_id'] = @$lineitem['productId'];
             $arr_order_line['product_name'] = @$lineitem['productName'];
             $arr_order_line['api_order_line_id'] = @$lineitem['id'];
             $arr_order_line['variation_id'] = isset($lineitem['variantId']) ? $lineitem['variantId'] : 0;
             $arr_order_line['sku'] = @$lineitem['sku'];
             $arr_order_line['qty'] = isset($lineitem['quantity']) ? $lineitem['quantity'] : 0;
             $arr_order_line['unit_price'] = isset($lineitem['unitPricePaid']['value']) ? floatval($lineitem['unitPricePaid']['value']) : 0;
             $arr_order_line['subtotal'] = $arr_order_line['unit_price'] * $arr_order_line['qty'];
             $arr_order_line['total'] = $arr_order_line['subtotal'];
 
             $ct_order_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'sku' => @$arr_order_line['sku'], 'row_type' => 'ITEM']);
 
             if ($ct_order_line > 0) {
                 $this->mobj->makeUpdate('platform_order_line', $arr_order_line, ['platform_order_id' => $platform_order_id, 'sku' => @$arr_order_line['sku'], 'row_type' => 'ITEM']);
             } else {
                 if($acceptNewOrders){
                     $this->mobj->makeInsert('platform_order_line', $arr_order_line);
                 }
             }
         }
 
         //store shippingLines 
         foreach (@$ord['shippingLines'] as $shiplineitem) {
 
             $arr_shipping_line = array();
             $arr_shipping_line['platform_order_id'] = $platform_order_id;
             $arr_shipping_line['row_type'] = 'SHIPPING';
             $arr_shipping_line['product_name'] = 'shipping charge';
             $arr_shipping_line['description'] = 'shipping charge '.@$shiplineitem['method'];
             $arr_shipping_line['price'] = isset($shiplineitem['amount']['value'])? floatval($shiplineitem['amount']['value']) : 0;
             $arr_shipping_line['qty'] = 1;
             $arr_shipping_line['subtotal'] = isset($shiplineitem['amount']['value'])? floatval($shiplineitem['amount']['value']) : 0;
             $arr_shipping_line['total'] = isset($shiplineitem['amount']['value'])? floatval($shiplineitem['amount']['value']) : 0;
             // $order_shipping_method = @$shiplineitem['method'];
 
             $ct_shipping_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 
             'row_type' => 'SHIPPING']);
 
             if ($ct_shipping_line > 0) {
                 $this->mobj->makeUpdate('platform_order_line', $arr_shipping_line, ['platform_order_id' => $platform_order_id, 
                 'row_type' => 'SHIPPING']);
             } else {
                 if($acceptNewOrders){
                     $this->mobj->makeInsert('platform_order_line', $arr_shipping_line);
                 }
             }
         }
         


         //store discountLines 
         
         foreach (@$ord['discountLines'] as $discountLineItem) {
             $arr_discount_line = array();
             $arr_discount_line['platform_order_id'] = $platform_order_id;
             $arr_discount_line['row_type'] = 'DISCOUNT';
             $arr_discount_line['product_name'] = isset($discountLineItem['name'])? $discountLineItem['name'] : 'Discount';
             $arr_discount_line['description'] = isset($discountLineItem['description'])? $discountLineItem['description'] : 'Discount';
             $arr_discount_line['price'] = isset($discountLineItem['amount']['value']) ? floatval('-'.$discountLineItem['amount']['value']) : 0;
             $arr_discount_line['qty'] = (@$discountLineItem['amount']['value'] > 0)? 1 : 0;
             $arr_discount_line['subtotal'] = isset($discountLineItem['amount']['value']) ? floatval('-'.$discountLineItem['amount']['value']) : 0;
             $arr_discount_line['total'] = isset($discountLineItem['amount']['value']) ? floatval('-'.$discountLineItem['amount']['value']) : 0;
             //insert promo code for diffrenciate multiple discount
             $arr_discount_line['api_code'] = isset($discountLineItem['promoCode'])? $discountLineItem['promoCode'] : '';
            
             
             $ct_discount_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 
             'row_type' => 'DISCOUNT', 'api_code' =>$discountLineItem['promoCode']]);
             
             if ($ct_discount_line > 0) {
                 $this->mobj->makeUpdate('platform_order_line', $arr_discount_line, ['platform_order_id' => $platform_order_id, 
                 'row_type' => 'DISCOUNT', 'api_code' => $discountLineItem['promoCode'] ]);
             } else {
                if($acceptNewOrders){
                    $this->mobj->makeInsert('platform_order_line', $arr_discount_line);
                }
             }

         }

 
         //Store Total tax in platform_order_line if exists
         if(isset($ord['taxTotal']))
         {
             $arr_tax_line = array();
             $arr_tax_line['platform_order_id'] = $platform_order_id;
             $arr_tax_line['row_type'] = 'TAX';
             $arr_tax_line['product_name'] = 'Tax Total';
             $arr_tax_line['description'] = 'Tax Amount';
             $arr_tax_line['qty'] = (@$ord['taxTotal']['value'] > 0)? 1 : 0;
             //store tax as item
             $arr_tax_line['subtotal'] = isset($ord['taxTotal']['value']) ? floatval($ord['taxTotal']['value']) : 0;
             $arr_tax_line['price'] = isset($ord['taxTotal']['value']) ? floatval($ord['taxTotal']['value']) : 0;
 
             $ct_tax_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 
             'row_type' => 'TAX']);
 
             if ($ct_tax_line > 0) {
                 $this->mobj->makeUpdate('platform_order_line', $arr_tax_line, ['platform_order_id' => $platform_order_id, 
                 'row_type' => 'TAX']);
             } else {
                 if($arr_tax_line['qty'] > 0)
                 {
                    $this->mobj->makeInsert('platform_order_line', $arr_tax_line);
                 }
                 
             }
         }
 
 
         //update total amount into order table
         $ordtotal['total_discount'] =  @$ord['discountTotal']['value'];
         $ordtotal['total_tax'] =  @$ord['taxTotal']['value'];
         $ordtotal['total_amount'] =  @$ord['grandTotal']['value'];
         $ordtotal['shipping_total'] =  @$ord['shippingTotal']['value'];
         $ordtotal['net_amount'] =  @$ord['grandTotal']['value'];
         // $ordtotal['shipping_method'] =  $order_shipping_method;
         $this->mobj->makeUpdate('platform_order', $ordtotal, ["id" => $platform_order_id]);
 
         
         //if shipping address exist then store this otherwise store billing address
         if(isset($ord['shippingAddress'])){
             $address = $ord['shippingAddress'];
         } else if(isset($ord['billingAddress'])){
             $address = $ord['billingAddress'];
         } else {
             $address = "";
         }
         //if address true
         if($address){
             $arr_order_address = array();
             $arr_order_address['platform_order_id'] = $platform_order_id;
             $arr_order_address['address_type'] = 'Shipping';
             $arr_order_address['firstname'] = @$address['firstName'];
             $arr_order_address['lastname'] = @$address['lastName'];
             $arr_order_address['address_name'] = @$address['firstName'].' '.@$address['lastName'];
             $arr_order_address['address1'] = @$address['address1'];
             $arr_order_address['address2'] = @$address['address2'];
             $arr_order_address['address3'] = @$address['city'];
             $arr_order_address['city'] = @$address['city'];
             $arr_order_address['state'] = @$address['state'];
             $arr_order_address['postal_code'] = @$address['postalCode'];
             $arr_order_address['country'] = @$address['countryCode'];
             $arr_order_address['phone_number'] = @$address['phone'];
             $arr_order_address['ship_speed'] = @$ord['fulfillments'][0]['service'];
             $arr_order_address['carrier_code'] = @$ord['fulfillments'][0]['carrierName'];
             $arr_order_address['email'] = $ord['customerEmail'];
 
             $ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);
 
             if ($ct_address > 0) {
                 $this->mobj->makeUpdate('platform_order_address', $arr_order_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);
             } else {
                 if($acceptNewOrders){
                     $this->mobj->makeInsert('platform_order_address', $arr_order_address);
                 }
             }
         }
         
 
         //if billing address exist then store this otherwise store shipping address
         if(isset($ord['billingAddress'])){
             $billaddress = $ord['billingAddress'];
         } else if(isset($ord['shippingAddress'])){
             $billaddress = $ord['shippingAddress'];
         } else {
             $billaddress = "";
         }
         //if billing address true
         if($billaddress){
             $arr_order_bill_address = array();
             $arr_order_bill_address['platform_order_id'] = $platform_order_id;
             $arr_order_bill_address['address_type'] = 'Billing';
             $arr_order_bill_address['firstname'] = @$billaddress['firstName'];
             $arr_order_bill_address['lastname'] = @$billaddress['lastName'];
             $arr_order_bill_address['address_name'] = @$billaddress['firstName'].' '.@$billaddress['lastName'];
             $arr_order_bill_address['address1'] = @$billaddress['address1'];
             $arr_order_bill_address['address2'] = @$billaddress['address2'];
             $arr_order_bill_address['address3'] = @$billaddress['city'];
             $arr_order_bill_address['city'] = @$billaddress['city'];
             $arr_order_bill_address['state'] = @$billaddress['state'];
             $arr_order_bill_address['postal_code'] = @$billaddress['postalCode'];
             $arr_order_bill_address['country'] = @$billaddress['countryCode'];
             $arr_order_bill_address['phone_number'] = @$address['phone'];
             $arr_order_bill_address['ship_speed'] = @$ord['fulfillments'][0]['service'];
             $arr_order_bill_address['carrier_code'] = @$ord['fulfillments'][0]['carrierName'];
             $arr_order_bill_address['email'] = $ord['customerEmail'];
 
 
             $bill_ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);
 
             if ($bill_ct_address > 0) {
                 $this->mobj->makeUpdate('platform_order_address', $arr_order_bill_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);
             } else {
                 if($acceptNewOrders){
                     $this->mobj->makeInsert('platform_order_address', $arr_order_bill_address);
                 }
             }
         }
         
 
         return true;
 
     }




   
}