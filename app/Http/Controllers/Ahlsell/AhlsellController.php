<?php

namespace App\Http\Controllers\Ahlsell;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Api\AhlsellApi;
use App\Helper\ConnectionHelper;
use App\Helper\Logger;
use Illuminate\Support\Facades\Session;
use phpseclib3\Net\SFTP;
use File;
use App\Models\PlatformProduct;
use App\Models\PlatformProductInventory;
use Lang;

class AhlsellController extends Controller
{
   /**
    * Create a new controller instance.
    *
    * @return void
    */
   public function __construct()
   {
      $this->mobj = new MainModel();
      $this->AhlsellApi = new AhlsellApi();
      $this->log = new Logger();
      $this->helper = new ConnectionHelper();
      $this->my_platform = 'ahlsell';
      $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
   }

   public function InitiateAhlsellAuth(Request $request)
   {
      $platform = $this->my_platform;
      return view("pages.apiauth.ahlsell_auth", compact('platform'));
   }

   public function ConnectAhlsellOauth(Request $request)
   {
      try {
         $account_name = trim($request->account_name);
         $token = trim($request->token);
         $username = trim($request->user_name);
         $userpass = trim($request->password);

         if($this->mobj->checkHtmlTags( $request->all() ) ){
            Session::put('auth_msg', Lang::get('tags.validate'));
            return redirect()->back();
         }
         
         $user_data =  Session::get('user_data');
         $user_id =  $user_data['id'];
         $response = $this->AhlsellOauth($username, $userpass, $token);

         if (isset($response) && isset($response->Body->Fault)) {
            Session::put('auth_msg', $response->Body->Fault->faultstring);
         } else {
            $OauthData = [
               'app_id' => $this->mobj->encrypt_decrypt($username, $action = 'encrypt'),
               'app_secret' => $this->mobj->encrypt_decrypt($userpass, $action = 'encrypt'),
               'access_token' => $this->mobj->encrypt_decrypt($token, $action = 'encrypt'),
               'account_name' => $account_name,
               'user_id' => $user_id,
               'platform_id' => $this->my_platform_id,
               'expires_in' => 3600,
               'token_refresh_time' => time()
            ];
            $ufound =  $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'app_id' => $username, 'app_secret' => $userpass], ['id']);

            if ($ufound) {
               $this->mobj->makeUpdate('platform_accounts', $OauthData, ['id' => $ufound->id]);
            } else {
               $this->mobj->makeInsert('platform_accounts', $OauthData);
            }
         }
         echo '<script>window.close();</script>';
      } catch (\Exception $e) {
         Session::put('auth_msg', $e->getMessage());
      }
   }

   public function AhlsellOauth($username, $userpass, $token)
   {
      $request_data_json = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:stoc="http://webservices.im.se/ocp/StockQuery">
      <soapenv:Header>
         <wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd" xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
            <wsse:UsernameToken wsu:Id="UsernameToken-' . $token . '">
               <wsse:Username>' . $username . '</wsse:Username>
               <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">' . $userpass . '</wsse:Password>
               <wsse:Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">p3FaBiVn9ya7u5Od4op03Q==</wsse:Nonce>
               <wsu:Created>2021-10-14T10:08:28.040Z</wsu:Created>
            </wsse:UsernameToken>
         </wsse:Security>
      </soapenv:Header>
     <soapenv:Body>
         <stoc:StockQuery>
            <stoc:ArticleList>
               <stoc:AccountNr>3523195</stoc:AccountNr>
               <stoc:WarehouseNr>8</stoc:WarehouseNr>
               <!--1 or more repetitions:-->
               <stoc:Article>
                  <stoc:ArticleNr>607912</stoc:ArticleNr>
                  <stoc:Quantity>10</stoc:Quantity>
                  <!--Optional:-->
                  <stoc:ReturnAlternate>1</stoc:ReturnAlternate>
               </stoc:Article>
            </stoc:ArticleList>
         </stoc:StockQuery>
      </soapenv:Body>
      </soapenv:Envelope>';
      $url = 'http://webshop.ahlsell.com/csfomswsprod/CSFOMSWebService';
      $response = $this->AhlsellApi->GetInventory($url, $request_data_json);
      $clean_xml = str_ireplace(['SOAP-ENV:', 'SOAP:'], '', $response);
      // $xml = simplexml_load_string($clean_xml);
      $xml = simplexml_load_string( utf8_encode($clean_xml) );
      $inv_arr = json_decode(json_encode($xml, true));
      return $inv_arr;
   }

   public function GetInventory($username, $userpass, $token, $user_id, $user_integration_id)
   {
      //set source platform Brightpearl to get Products Sku
      $source_platform_id = $this->helper->getPlatformIdByName('Brightpearl');

      $limit = 100;
      $skip = 0;
      $platform_urls_id = null;
      $fetchedProductIds = [];  
      $AccountNr = 3523195;
      $warehouseId = 8;
      $warehouseName = 'Gardermoen Sentralla';
      $defQty = '9999999';


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

      $product_arr = PlatformProduct::where('user_integration_id', $user_integration_id)->where('platform_id',$source_platform_id)->where('product_sync_status','!=','Inactive')->whereNotNull('sku')->select('id','sku')
      ->skip($skip)->take($limit)->get();


      $Article = '';
      if (count($product_arr)) {
         foreach ($product_arr as $product) {
            array_push($fetchedProductIds,$product->id);
            $Article = $Article . '
            <stoc:Article>
               <stoc:ArticleNr>' . $product->sku . '</stoc:ArticleNr>
               <stoc:Quantity>'.$defQty.'</stoc:Quantity>
               <stoc:ReturnAlternate>1</stoc:ReturnAlternate>
            </stoc:Article>';
         }

         $request_data_json = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:stoc="http://webservices.im.se/ocp/StockQuery">
         <soapenv:Header>
            <wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd" xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
               <wsse:UsernameToken wsu:Id="UsernameToken-' . $token . '">
                  <wsse:Username>' . $username . '</wsse:Username>
                  <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">' . $userpass . '</wsse:Password>
                  <wsse:Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">p3FaBiVn9ya7u5Od4op03Q==</wsse:Nonce>
                  <wsu:Created>2021-10-14T10:08:28.040Z</wsu:Created>
               </wsse:UsernameToken>
            </wsse:Security>
         </soapenv:Header>
         <soapenv:Body>
               <stoc:StockQuery>
                  <stoc:ArticleList>
                  <stoc:AccountNr>'.$AccountNr.'</stoc:AccountNr>
                  <stoc:WarehouseNr>'.$warehouseId.'</stoc:WarehouseNr>
                  <stoc:WarehouseName>'.$warehouseName.'</stoc:WarehouseName>
                  <!--1 or more repetitions:-->
                  ' . $Article . '
                  </stoc:ArticleList>
               </stoc:StockQuery>
            </soapenv:Body>
         </soapenv:Envelope>';

         $url = 'http://webshop.ahlsell.com/csfomswsprod/CSFOMSWebService';
         $response = $this->AhlsellApi->GetInventory($url, $request_data_json);
         $clean_xml = str_ireplace(['SOAP-ENV:', 'SOAP:'], '', $response);
         $xml = simplexml_load_string( utf8_encode($clean_xml) );

         // $inv_arr = json_decode(json_encode($xml, true));
         $inv_arr = json_decode(json_encode($xml),true);

         return $inv_arr;

      } else {
         $this->mobj->makeUpdate('platform_urls', ['url' => 0], ['id' => $platform_urls_id]);
         return 0;
      }
   }

   public function SaveInventory($user_id, $user_integration_id)
   {
      $return_data = true;
      $quantity = 0;
      $product_sku = '';

      $acc_detail = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['id','app_id', 'app_secret', 'access_token']);
      if ($acc_detail) {
         $username = $this->mobj->encrypt_decrypt($acc_detail->app_id, $action = 'decrypt');
         $userpass = $this->mobj->encrypt_decrypt($acc_detail->app_secret, $action = 'decrypt');
         $token = $this->mobj->encrypt_decrypt($acc_detail->access_token, $action = 'decrypt');

         $response = $this->GetInventory($username, $userpass, $token, $user_id, $user_integration_id);

   
         if ( isset($response) && isset($response['Body']['StockQueryResponse']['Article'])) {

            //if directly receive data
            if (isset($response['Body']['StockQueryResponse']['Article']['UnknownArticleNumber'])) { 

               $inv = $response['Body']['StockQueryResponse']['Article'];
               $this->handleInventoryData($user_id, $user_integration_id, $inv);

            } else {

               //if artical has array of inventory values
               foreach ($response['Body']['StockQueryResponse']['Article'] as $inv) {
                  $this->handleInventoryData($user_id, $user_integration_id, $inv);
               }

            }

         } else if (isset($response) && isset($response['Body']['Fault'])) {
            //log account level error msg
            $error_msg = $response['Body']['Fault']['faultstring'];
            $this->mobj->apiErrorLogForNotify($acc_detail->id,$error_msg);
         }
         
         return $return_data;

      }
   }

   //insert update inventory data
   public function handleInventoryData($user_id, $user_integration_id, $inv)
   {
      $quantity = 0; 

      //if recieved UnknownArticleNumber=="no" then save them
      if (isset($inv) && isset($inv['UnknownArticleNumber']) && $inv['UnknownArticleNumber']=="no") {
         
         $data = [
            'user_id' => $user_id,
            'platform_id' => $this->my_platform_id,
            'user_integration_id' => $user_integration_id
         ];
         if (isset($inv['WarehouseNr'])) { }
         if (isset($inv['WarehouseName'])) { }
         if (isset($inv['CurrentStock'])) {
            $quantity = $inv['CurrentStock'];
         } 
       
         if (isset($inv['ArticleNr'])) {
            
            //check N5 in artical number if found then remove
            if (substr($inv['ArticleNr'], -2)=="N5" || substr($inv['ArticleNr'], -2)=="N4")
            {
               $articalNumber = substr_replace($inv['ArticleNr'] ,"",-2); 
            } else {
               $articalNumber = $inv['ArticleNr'];
            }

            $data['sku'] = $articalNumber;
            $data['product_sync_status'] = "Ready";
            $product_sku = $articalNumber;
         }
         if (isset($inv['ArticleName'])) {
            $data['product_name'] = $inv['ArticleName'];
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
               $this->mobj->makeUpdate('platform_product_inventory', $inv_arr, ['id' => $invt_count->id]);
               PlatformProduct::where('id',$product_id)->update(['inventory_sync_status' => 'Ready']);

               \Storage::disk('local')->append('ahlsell_inventory_pull_log.txt', 'Ahlsell Update Case '.' time: ' . date('Y-m-d H:i:s') .PHP_EOL 
               .json_encode($inv_arr,true));
            } else {

               \Storage::disk('local')->append('ahlsell_inventory_pull_log.txt', 'Ahlsell Ignore Case '.' time: ' . date('Y-m-d H:i:s') .PHP_EOL 
               .json_encode($inv_arr,true));
            }


         } else {
            $inv_arr['sync_status'] = 'Ready';
            $this->mobj->makeInsertGetId('platform_product_inventory', $inv_arr);
         }

      }
      
      return true;

   }


   public function ExecuteAhlsell($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = NULL)
   {
      try {
         $response = true;
         if (!$is_initial_sync) {
            if ($method == 'GET' && $event == 'INVENTORY') {
               $response = $this->SaveInventory($user_id, $user_integration_id);
            }
         }
         return $response;
      } catch (\Exception $e) {
         return $e->getMessage();
      }
   }



   public function SaveInventory_Test($user_id=172, $user_integration_id=208)
   {
   
      $return_data = true;
      $quantity = 0;
      $product_sku = '';


      $acc_detail = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['app_id', 'app_secret', 'access_token']);

      if ($acc_detail) {
         $username = $this->mobj->encrypt_decrypt($acc_detail->app_id, $action = 'decrypt');
         $userpass = $this->mobj->encrypt_decrypt($acc_detail->app_secret, $action = 'decrypt');
         $token = $this->mobj->encrypt_decrypt($acc_detail->access_token, $action = 'decrypt');

         $response = $this->GetInventory_Test($username, $userpass, $token, $user_id, $user_integration_id);

         if (isset($response['Body']['StockQueryResponse']['Article'])) {
       
            //if directly receive data
            if (isset($response['Body']['StockQueryResponse']['Article']['UnknownArticleNumber'])) { 

               $inv = $response['Body']['StockQueryResponse']['Article'];

               $this->handleInventoryData($user_id, $user_integration_id, $inv);

            } else {

               //if artical has array of inventory values
               foreach ($response['Body']['StockQueryResponse']['Article'] as $inv) {
                  $this->handleInventoryData($user_id, $user_integration_id, $inv);
               }

            }

         }


         return $return_data;

      }
   }
   public function GetInventory_Test($username, $userpass, $token, $user_id, $user_integration_id)
   {
      //set source platform Brightpearl to get Products Sku  2010491
      $source_platform_id = $this->helper->getPlatformIdByName('Brightpearl'); 
      $AccountNr = 3523195;
      $warehouseId = 8;
      $warehouseName = 'Gardermoen Sentralla';
      $defQty = '9999999';
      $product_sku = '6157269';
     

         $Article = '';
         $Article = $Article . '
            <stoc:Article>
               <stoc:ArticleNr>' . $product_sku . '</stoc:ArticleNr>
               <stoc:Quantity>'.$defQty.'</stoc:Quantity>
               <stoc:ReturnAlternate>1</stoc:ReturnAlternate>
            </stoc:Article>';
      

         $request_data_json = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:stoc="http://webservices.im.se/ocp/StockQuery">
         <soapenv:Header>
            <wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd" xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
               <wsse:UsernameToken wsu:Id="UsernameToken-' . $token . '">
                  <wsse:Username>' . $username . '</wsse:Username>
                  <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">' . $userpass . '</wsse:Password>
                  <wsse:Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">p3FaBiVn9ya7u5Od4op03Q==</wsse:Nonce>
                  <wsu:Created>2021-10-14T10:08:28.040Z</wsu:Created>
               </wsse:UsernameToken>
            </wsse:Security>
         </soapenv:Header>
         <soapenv:Body>
               <stoc:StockQuery>
                  <stoc:ArticleList>
                  <stoc:AccountNr>'.$AccountNr.'</stoc:AccountNr>
                  <stoc:WarehouseNr>'.$warehouseId.'</stoc:WarehouseNr>
                  <stoc:WarehouseName>'.$warehouseName.'</stoc:WarehouseName>
                  <!--1 or more repetitions:-->
                  ' . $Article . '
                  </stoc:ArticleList>
               </stoc:StockQuery>
            </soapenv:Body>
         </soapenv:Envelope>';

         $url = 'http://webshop.ahlsell.com/csfomswsprod/CSFOMSWebService';
         $response = $this->AhlsellApi->GetInventory($url, $request_data_json);
         $clean_xml = str_ireplace(['SOAP-ENV:', 'SOAP:'], '', $response);
         $xml = simplexml_load_string( utf8_encode($clean_xml) );

         $inv_arr = json_decode(json_encode($xml),true);

         // $json = json_encode($xml);
         // $inv_arr = json_decode($json,TRUE);
         
         return $inv_arr;

   
   }

   

}