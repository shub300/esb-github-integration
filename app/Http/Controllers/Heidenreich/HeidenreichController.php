<?php

namespace App\Http\Controllers\Heidenreich;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Api\HeidenreichApi;
use App\Helper\ConnectionHelper;
use Illuminate\Support\Facades\Session;
use App\Models\PlatformProduct;
use App\Models\PlatformProductInventory;
use Lang;
class HeidenreichController extends Controller
{
   /**
    * Create a new controller instance.
    *
    * @return void
    */
   public function __construct()
   {
      $this->mobj = new MainModel();
      $this->HeidenreichApi = new HeidenreichApi();
      // $this->log = new Logger();
      $this->helper = new ConnectionHelper();
      $this->my_platform = 'heidenreich';
      $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
   }
   public function InitiateHeidenreichAuth(Request $request)
   {
        $platform = $this->my_platform;
        return view("pages.apiauth.heidenreich_auth", compact('platform'));
   }
   public function ConnectHeidenreichOauth(Request $request)
   {
      $validated = $request->validate([
         'customer_number' => 'required',
         'user_name' => 'required',
         'secret' => 'required',
      ]);

      $customer_number = trim($request->customer_number);
      $user_name = trim($request->user_name);
      $secret_key = trim($request->secret);
      $authHash= strtoupper((md5($secret_key."".(strtoupper(md5($secret_key."".$user_name. "". $customer_number))))));
      
      $env_type = trim($request->env_type);
      if ($env_type == 'on') { // checke account type .
         $env_type = 'production';
      } else {
            $env_type = 'sandbox';
      }

      $user_data =  Session::get('user_data');
      $user_id =  $user_data['id'];
      $data = [];

      if($this->mobj->checkHtmlTags( $request->all() ) ){
         $data['status_code'] = 0;
         $data['status_text'] = Lang::get('tags.validate');
         return json_encode($data);
     }

      try {
               // $inc_app_id = $this->mobj->encrypt_decrypt($user_name, $action = 'encrypt');
               // $inc_secret_key = $this->mobj->encrypt_decrypt($secret_key, $action = 'encrypt');

               $existing_heidenreich = $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'account_name' => $customer_number,'platform_id' => $this->my_platform_id], ['id']);

               // $existing_heidenreich = $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'account_name' => $customer_number, 'app_id' => $inc_app_id, 'app_secret' => $inc_secret_key,'platform_id' => $this->my_platform_id], ['id']);

               $flag = true;
               if (!$existing_heidenreich) {
                     //test auth hash by api call  
                     $response = $this->ValidateCredential($authHash,$user_name);
                     if($response=="true")
                     {
                        //insert heidenreich account details
                        $heidenreich_tokens = array(
                              'user_id' => $user_id,
                              'platform_id' => $this->my_platform_id,
                              'account_name' => $customer_number,
                              'app_id' => $this->mobj->encrypt_decrypt($user_name, $action = 'encrypt'),
                              'app_secret' => $this->mobj->encrypt_decrypt($secret_key, $action = 'encrypt'),
                              'access_token' => $authHash,
                              'env_type' => $env_type
                        );
                        DB::table('platform_accounts')->insert($heidenreich_tokens);
                     }
                     else
                     {
                        $flag = false;
                        $data['status_code'] = 0;
                        $data['status_text'] = 'Sign-in information is incorrect';
                        return json_encode($data);
                     }
                    
               } else {
                  $flag = false;
                  $data['status_code'] = 0;
                  $data['status_text'] = 'Account name identifier is already exist with the same user, Try with another name.';
               }

               if ($flag) {
                  $data['status_code'] = 1;
                  $data['status_text'] = 'Account connected successfully.';
               }
               return json_encode($data);
      } catch (\Exception $e) {
               $data['status_code'] = 0;
               $data['status_text'] = $e->getMessage();
               return json_encode($data);
      }
   }
   //Test Api call  to check credentials are valid
   public function ValidateCredential($authHash,$user_name)
   {
      $request_data_json = '';
      $ProductNumbersArr = "&ProductNumbers=0000000";
      $response = $this->HeidenreichApi->GetInventory($request_data_json, $authHash, $user_name,$ProductNumbersArr);
      $responseXml = simplexml_load_string($response);
      $apiStatus = $responseXml->Success;
      return $apiStatus;
   }
   public function GetInventory($authHash,$user_name,$ProductNumbersArr)
   {
      $request_data_json = '';
      $response = $this->HeidenreichApi->GetInventory($request_data_json, $authHash, $user_name,$ProductNumbersArr);
      $responseXml = simplexml_load_string($response);
      return $responseXml;
   }

   public function SaveInventory($user_id, $user_integration_id)
   {
      date_default_timezone_set('UTC');
      $return_data = true;
      $quantity = 0;
      $product_sku = '';

      //get Product Numbers (sku from brightpearl) & product Ids to update for manage chunk 80
      $ProductNumbersArr="";
      $fetchedProductIds = [];     
 
      $acc_detail = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['access_token', 'platform_id', 'id', 'user_id', 'account_name', 'app_id', 'app_secret']);

      //set source platform Brightpearl to get Products Sku
      $source_platform_id = $this->helper->getPlatformIdByName('Brightpearl');

      $limit = 80;
      $skip = 0;
      $platform_urls_id = null;
      
      $platform_urls = DB::table('platform_urls')->where('user_integration_id',$user_integration_id)
      ->where('platform_id',$this->my_platform_id)->where('url_name','product_limit')->select('id','url')->first();

      if ($platform_urls) {
         $platform_urls_id = $platform_urls->id;
         $skip = $platform_urls->url;
         $url = $platform_urls->url + $limit;
         $this->mobj->makeUpdate('platform_urls', ['url' => $url], ['id' => $platform_urls->id]);
      } else {
         $url = $skip + $limit;
         $platform_urls_id = $this->mobj->makeInsertGetId('platform_urls', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => 'product_limit', 'url' => $url]);
      }

      $listSku = PlatformProduct::where('user_integration_id', $user_integration_id)->where('platform_id',$source_platform_id)
      ->where('product_sync_status','!=','Inactive')->whereNotNull('sku')
      ->select('id','sku')->skip($skip)->take($limit)->get();

      if( count($listSku) > 0 )
      {
         foreach($listSku as $prodNumber)
         {
            $prodFormatedSku = str_replace("VIL-","",$prodNumber->sku);
            // $prodFormatedSku = ltrim(stristr($prodNumber->sku, '-'), '-');
            $ProductNumbersArr .="&ProductNumbers=".$prodFormatedSku;
            array_push($fetchedProductIds,$prodNumber->id);
         }
         
         //get account details
         if ($acc_detail) {
            $customer_number = $acc_detail->account_name;
            $user_name = $this->mobj->encrypt_decrypt($acc_detail->app_id, $action = 'decrypt');
            $secret_key = $this->mobj->encrypt_decrypt($acc_detail->app_secret, $action = 'decrypt');
            $authHash = $acc_detail->access_token;

            $response = $this->GetInventory($authHash, $user_name,$ProductNumbersArr);
            $apiStatus = $response->Success;

            $articalFetchStatus = false;
            if ( isset($response->Stocks->StocksExternal) && ($apiStatus=="true") ) {
               foreach ($response->Stocks->StocksExternal as $inv) {
                  $articalFetchStatus = $inv->Success;

                  if($articalFetchStatus=="true")
                  {
                     $data = [
                        'user_id' => $user_id,
                        'platform_id' => $this->my_platform_id,
                        'user_integration_id' => $user_integration_id
                     ];
               
                     if (isset($inv->Articlenumber)) {
                        $data['sku'] = $inv->Articlenumber;
                        $data['product_sync_status'] = "Ready";
                        $product_sku = $inv->Articlenumber;
                     }
                  
                     if (isset($inv->Amount)) {
                        $quantity = $inv->Amount;
                     }
      
                     $product_count = PlatformProduct::where('user_integration_id',$user_integration_id)
                     ->where('platform_id',$this->my_platform_id)->where('sku',$product_sku)->select('id')->first();

                     if ($product_count) {
                        $product_id = $product_count->id;
                     } else {
                        $data['inventory_sync_status'] = 'Ready';
                        $product_id = $this->mobj->makeInsertGetId('platform_product', $data);
                     }


                     $invt_count = DB::table('platform_product_inventory')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'platform_product_id' => $product_id])->select('id', 'quantity')->first();

                     
                     $inv_arr = [
                        'user_id' => $user_id,
                        'user_integration_id' => $user_integration_id,
                        'platform_product_id' => $product_id,
                        'platform_id' => $this->my_platform_id,
                        'sku' => $product_sku,
                        'quantity' => $quantity
                     ];
                     if ($invt_count) {
                        if ($invt_count->quantity != $quantity) {
                              $inv_arr['sync_status'] = 'Ready';
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

            } else if($apiStatus=="false") {
               $this->handleErrorMsg($acc_detail->id,$response);
            }
            
            // \Storage::disk('local')->append('chunk_product_for_inventory.txt', 'Heidenreich - '.' time: ' . date('Y-m-d H:i:s') .PHP_EOL .json_encode($fetchedProductIds,true).PHP_EOL);

            return $return_data;
         }
         
         
      } else {
         $this->mobj->makeUpdate('platform_urls', ['url' => 0], ['id' => $platform_urls_id]);
         return 0;
      }

   

   }

   //check error msg & update account info for email notification
   public function handleErrorMsg($accountId,$response)
   {
      $response_data = (array)$response;
      if( isset($response_data['Message']) ) {
         $error_msg = $response_data['Message'];
      } else {
         $error_msg = 'Api error';
      }  
            
      $this->mobj->apiErrorLogForNotify($accountId,$error_msg);

      return true;
   }



   public function ExecuteHeidenreich($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = NULL)
   {
      try {
         $response = true;
         if ($method == 'GET' && $event == 'INVENTORY') {
            $response = $this->SaveInventory($user_id, $user_integration_id);
         }
         return $response;
      } catch (\Exception $e) {
         return $e->getMessage();
      }
   }

}