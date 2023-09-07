<?php

namespace App\Http\Controllers\Brodrene;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\Api\BrodreneApi;
use Validator;
use App\Models\PlatformProduct;
use App\Models\PlatformProductInventory;
use Lang;
class BrodreneController extends Controller
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
      $this->brodrene = new BrodreneApi;
      $this->platformId = 'brodrenedahl';
      $this->platform_pid = $this->helper->getPlatformIdByName($this->platformId);
   }
   public function InitiateBrodreneAuth(Request $request)
   {
      $platform = $this->platformId;
      return view("pages.apiauth.brodrene_auth", compact('platform'));
   }
   /* Redirect Brodreredahl Auth */
   public function ConnectBrodreneOauth(Request $request)
   {
        if($this->mobj->checkHtmlTags( $request->all() ) ){
            return back()->with('error', Lang::get('tags.validate'));
        }
        
       if ($request->isMethod('post')) {
           $validator = Validator::make($request->all(), [
               'account_name' => 'required',
           ]);
           if ($validator->fails()) {
               return back()->withErrors($validator);
           } else {
               $account_name = $request->account_name;
               $user_data =  Auth::user();
               $userID =  $user_data->id;
               $isAllowed =  $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' =>  $this->platform_pid], ['app_ref', 'client_id', 'client_secret']);

               // to check whether given account is already in use or not.
               $checkExistingAc = $this->checkExistingConnectedAc($this->platformId, $account_name);
               if ($checkExistingAc) {
                   return back()->with('error', 'Given details are already in use, Try with other details.');
               }
               if ($isAllowed &&  $this->platformId) {
                   $client_id = $this->mobj->encrypt_decrypt($isAllowed->client_id, 'decrypt');
                   $client_secret = $this->mobj->encrypt_decrypt($isAllowed->client_secret, 'decrypt');
                   $redirect_url = $this->mobj->makeUrlHttpsForProd(url('/brodreneRedirectHandler'));
                   $state_i = $userID . "-" . trim($account_name);
                   if (!$account_name) {
                       return back()->with('error', 'Account not found.');
                   }
                   if ($client_id && $client_secret) {
                       $url = \Config::get('apiconfig.BrodreneOauthUrl')."/authorize/?client_id=".$client_id."&response_type=code&scope=openid%20offline_access&redirect_uri=".$redirect_url."&nonce=UBGW&state=".$state_i;

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
      \Storage::disk('local')->append('brodrene_callback.txt', print_r($request->all(),true));

      if (isset($request->code)) {

         $record = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' =>  $this->platform_pid], ['app_ref', 'client_id', 'client_secret']);
         if ($record && $this->platformId) {
             $code = $request->code;
             $client_id = $this->mobj->encrypt_decrypt($record->client_id, 'decrypt');
             $client_secret = $this->mobj->encrypt_decrypt($record->client_secret, 'decrypt');
             $redirect_url = $this->mobj->makeUrlHttpsForProd(url('/brodreneRedirectHandler'));
             $state = $request->state;
             $state_arr = explode('-', $state);
             if (isset($state_arr[0]) && isset($state_arr[1])) {
                 // Valid request
                 $userId = $state_arr[0];
                 $AccountName = $state_arr[1]; // Account Code
                 if (isset($state_arr[0]) && isset($state_arr[1])) {
                     $curl_post_data = array(
                         'client_id' => $client_id,
                         'client_secret' => $client_secret,
                         'code' => $code,
                         'grant_type' => 'authorization_code',
                         'redirect_uri' => $redirect_url,
                     );
                     $service_url = \Config::get('apiconfig.BrodreneOauthUrl') . '/token';

                     $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

                     $response = $this->mobj->makeRequest('POST', $service_url, $curl_post_data, $headers, 'http');

                     if (json_decode($response->getBody(), true)) {
                         if ($decode_val = json_decode($response->getBody(), true)) {

                            \Storage::disk('local')->append('brodrene_callback.txt', print_r($decode_val,true));
                
                             if (isset($decode_val['access_token'])) {
                                 $OauthData = [
                                     'access_token' => $this->mobj->encrypt_decrypt($decode_val['access_token']),
                                     'refresh_token' => isset($decode_val['refresh_token']) ? $this->mobj->encrypt_decrypt($decode_val['refresh_token']) : null,
                                     'access_key' => $this->mobj->encrypt_decrypt($code),
                                     'token_type' => $decode_val['token_type'],
                                     'expires_in' => $decode_val['expires_in'],
                                     'account_name' => $AccountName,
                                     'user_id' => $userId,
                                     'app_id' => $this->mobj->encrypt_decrypt($client_id), //app_reference
                                     'app_secret' => $record->app_ref, //dev_reference
                                     'platform_id' => $this->platform_pid,
                                     'token_refresh_time' => time()
                                 ];

                                 $ufound = DB::table('platform_accounts')->where([
                                     'user_id' => $userId,
                                     'platform_id' => $this->platform_pid, 'account_name' => $AccountName
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
                             } else { // When Token not found
                                 // $ufound = DB::table('platform_accounts')->where([
                                 //     'user_id' => $userId,
                                 //     'platform_id' => $this->platformId, 'account_name' => $AccountName
                                 // ])->first();
                                 // if ($ufound) {
                                 //     $res_n = DB::table('platform_accounts')->where('id', '=', $ufound->id)->update([
                                 //         'access_token' => null,
                                 //     ]);
                                 // }
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

            $findApp = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->platform_pid]);
            if ($findApp && $this->platform_pid) {

               $accDetail = $this->mobj->getFirstResultByConditions('platform_accounts', ['id' => $ID], ['id', 'user_id', 'platform_id', 'token_refresh_time', 'refresh_token', 'expires_in', 'account_name', 'app_id', 'app_secret', 'env_type', 'access_key','access_token']);
               $redirect_url = $this->mobj->makeUrlHttpsForProd(url('/brodreneRedirectHandler'));

               //call for refresh token  
               if ($accDetail) {
                     $curl_post_data = [
                        'client_id' => $this->mobj->encrypt_decrypt($findApp->client_id, 'decrypt'),
                        'client_secret' => $this->mobj->encrypt_decrypt($findApp->client_secret, 'decrypt'),
                        'redirect_uri' => $redirect_url,
                        'grant_type' => 'refresh_token',
                        'scope' => 'openid offline_access',
                        'refresh_token' => $this->mobj->encrypt_decrypt($accDetail->refresh_token, 'decrypt') 
                     ];
                    
                     $postData = http_build_query($curl_post_data);
    
                     $response = $this->brodrene->RefreshToken($postData);

                     if ($resData = json_decode($response, true)) {
                        $res = $resData;

                        if (!isset($res['errors'])) {
                           $this->mobj->makeUpdate(
                                 'platform_accounts',
                                 [
                                    'access_token' => $this->mobj->encrypt_decrypt($res['access_token']),
                                    'expires_in' => $res['expires_in'], 
                                    // 'app_secret' => $findApp->app_ref, 
                                    'refresh_token' => $this->mobj->encrypt_decrypt($res['refresh_token']), 
                                    'token_refresh_time' => time()
                                 ],
                                 ['id' => $ID]
                           );
                           $return_response = true;
                        } else {
                           $error = $this->brodrene->handleResponseError($res);
                           $return_response = isset($error) ? $error : "API Error";
                        }
                     } else {
                        $return_response =  "API Error";
                
                     }
               }
            }
         } catch (\Exception $e) {
            $return_response = $e->getMessage();
            \Storage::disk('local')->append('testCrone.txt', 'Brodrene refresh token Resp : ' . json_encode($return_response));
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
   // Execute Brodrenedahl Events
   public function ExecuteBrodrene($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = NULL)
   {
      try {
         $response = true;
         if ($method == 'GET' && $event == 'INVENTORY') {
            $response = $this->SaveInventory($user_id, $user_integration_id);
         }
        //  if ($method == 'GET' && $event == 'SUPPLIER') {
        //     $response = $this->GetSupplier($user_id, $user_integration_id);
        //  }

         return $response;
      } catch (\Exception $e) {
         return $e->getMessage();
      }
   } 
   public function SaveInventory($user_id, $user_integration_id)
   {
        date_default_timezone_set('UTC');
        $return_data = true;
        $quantity = 0;
        $product_sku = '';
        $estimatedRestockDate = '';
        
        $limit = 80;
        $skip = 0;
        $platform_urls_id = null;

        //get Product Numbers (sku from brightpearl) & product Ids to update for manage chunk 80
        $ProductNumbersArr = [];
        $fetchedProductIds = [];     
        $acc_detail = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platform_pid, ['access_token', 'platform_id', 'id', 'user_id', 'account_name', 'app_id', 'app_secret']);

        //set source platform Brightpearl to get Products Sku
        $source_platform_id = $this->helper->getPlatformIdByName('Brightpearl');


        $platform_urls = DB::table('platform_urls')->where('user_integration_id',$user_integration_id)
        ->where('platform_id',$this->platform_pid)->where('url_name','product_limit')->select('id','url')->first();

        if ($platform_urls) {
            $platform_urls_id = $platform_urls->id;
            $skip = $platform_urls->url;
            $url = $platform_urls->url + $limit;
            $this->mobj->makeUpdate('platform_urls', ['url' => $url], ['id' => $platform_urls->id]);
        } else {
            $url = $skip + $limit;
            $platform_urls_id = $this->mobj->makeInsertGetId('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platform_pid, 'url_name' => 'product_limit', 'url' => $url]);
        }
        
        
        $listSku = PlatformProduct::where('user_integration_id', $user_integration_id)
        ->where('platform_id',$source_platform_id)->where('product_sync_status','!=','Inactive')->whereNotNull('sku')
        ->select('id','sku')->skip($skip)->take($limit)->get();

        if( count($listSku) > 0 )
        {
            foreach($listSku as $prodNumber)
            {
                array_push($ProductNumbersArr,$prodNumber->sku);
                array_push($fetchedProductIds,$prodNumber->id);
            } 
            
            //get account details
            if ($acc_detail) {
                $account_name = $acc_detail->account_name;
                $app_id = $this->mobj->encrypt_decrypt($acc_detail->app_id, $action = 'decrypt');
                $authHash = $this->mobj->encrypt_decrypt($acc_detail->access_token, $action = 'decrypt');

                $post_fields = [
                    "warehouseId" => "LS",
                    // "itemIds" => ["1010012","1001012"],
                    "itemIds" => $ProductNumbersArr
                ];  
                $post_data = json_encode($post_fields);

                //Call get inventory
                $response = $this->brodrene->GetInventory($post_data, $authHash);

                if(count($response) > 0) {
                    foreach ($response as $inv) {
                        $data = [
                            'user_id' => $user_id,
                            'platform_id' => $this->platform_pid,
                            'user_integration_id' => $user_integration_id
                        ];
                
                        if (isset($inv->itemId)) {
                            $data['sku'] = $inv->itemId;
                            $data['api_product_id'] = $inv->itemId;
                            $data['product_sync_status'] = "Ready";
                            $product_sku = $inv->itemId;
                        }
                        if (isset($inv->quantity)) {
                            $quantity = $inv->quantity;
                        }
                        if (isset($inv->estimatedRestockDate)) {
                            $estimatedRestockDate = $inv->estimatedRestockDate;
                            $data['api_inventory_lastmodified_time'] = $inv->estimatedRestockDate;
                        }
                        
                        $product_count = PlatformProduct::where('user_integration_id',$user_integration_id)
                        ->where('platform_id',$this->platform_pid)->where('sku',$product_sku)->select('id')->first();

                        if ($product_count) {
                            $product_id = $product_count->id;
                        } else {
                            $data['inventory_sync_status'] = 'Ready';
                            $product_id = $this->mobj->makeInsertGetId('platform_product', $data);
                        }
                        
                        // $invt_count = PlatformProductInventory::where('user_id',$user_id)->where('user_integration_id',$user_integration_id)
                        // ->where('platform_id',$this->platform_pid)->where('platform_product_id',$product_id)->select('id', 'quantity')->first();
                        $invt_count = DB::table('platform_product_inventory')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platform_pid, 'platform_product_id' => $product_id])->select('id', 'quantity')->first();
        
                        $inv_arr = [
                            'user_id' => $user_id,
                            'user_integration_id' => $user_integration_id,
                            'platform_product_id' => $product_id,
                            'platform_id' => $this->platform_pid,
                            'sku' => $product_sku,
                            'quantity' => $quantity
                        ];
                        if ($invt_count) {
                            if ($invt_count->quantity != $quantity) {
                                $inv_arr['sync_status'] = 'Ready';
                                // $inv_arr['updated_at'] = date('Y-m-d H:i:s');
                                // PlatformProductInventory::where('id',$invt_count->id)->update([$inv_arr]);
                                $this->mobj->makeUpdate('platform_product_inventory', $inv_arr, ['id' => $invt_count->id]);

                                PlatformProduct::where('id',$product_id)->update(['inventory_sync_status' => 'Ready']);
                            }
                        } else {
                            $inv_arr['sync_status'] = 'Ready';
                            $this->mobj->makeInsertGetId('platform_product_inventory', $inv_arr);
                        }          
        
                    }
                } 
        
                // \Storage::disk('local')->append('chunk_product_for_inventory.txt','Brodrene-'. date('Y-m-d H:i:s') .PHP_EOL .json_encode($fetchedProductIds,true).PHP_EOL);
                
                //update all fetched product updated_at
                // $update_status = PlatformProduct::where('user_integration_id',$user_integration_id)->where('user_id',$user_id)
                // ->whereIn('id',$fetchedProductIds)->update(['updated_at' => date('Y-m-d H:i:s')]);
                
                return $return_data;
            }

        } else {
            $this->mobj->makeUpdate('platform_urls', ['url' => 0], ['id' => $platform_urls_id]);
            return 0;
         }
    
   }

}