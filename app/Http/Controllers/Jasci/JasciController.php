<?php

namespace App\Http\Controllers\Jasci;

use App\Http\Controllers\Controller;
use Auth;
use DB, Log;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Api\JasciApi;
use App\Helper\ConnectionHelper;
use Illuminate\Support\Facades\Session;
use App\Models\PlatformProduct;
use App\Models\PlatformProductInventory;
use App\Models\PlatformUrl;
use Lang;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderLine;
use App\Models\PlatformInventoryTrail;
use App\Helper\Logger;
use App\Helper\FieldMappingHelper;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;

class JasciController extends Controller
{
   /**
    * Create a new controller instance.
    *
    * @return void
    */

   public function __construct()
   {
      $this->mobj = new MainModel();
      $this->JasciApi = new JasciApi();
      $this->log = new Logger();
      $this->helper = new ConnectionHelper();
      $this->my_platform = 'jasci';
      $this->my_platform_id = $this->helper->getPlatformIdByName($this->my_platform);
      $this->mapping = new FieldMappingHelper();
   }

   public function InitiateJasciAuth(Request $request)
   {
      $platform = $this->my_platform;
      return view("pages.apiauth.jasci_auth", compact('platform'));
   }

   public function ConnectJasciOauth(Request $request)
   {
      $request->validate([
         'companyId' => 'required',
         'tenantId' => 'required',
         'userId' => 'required',
         'password' => 'required',
      ]);

      $companyId = trim($request->companyId);
      $tenantId = trim($request->tenantId);
      $userId = trim($request->userId);
      $password = trim($request->password);

      $env_type = trim($request->env_type);
      if ($env_type == 'on') { // check account type .
         $env_type = 'production';
      } else {
         $env_type = 'sandbox';
      }

      $user_data =  Session::get('user_data');
      $user_id =  $user_data['id'];
      $data = [];

      if ($this->mobj->checkHtmlTags($request->all())) {
         $data['status_code'] = 0;
         $data['status_text'] = Lang::get('tags.validate');
         return json_encode($data);
      }

      try {
         $existing_jasci = $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => $user_id, 'account_name' => $companyId, 'platform_id' => $this->my_platform_id], ['id']);

         $flag = true;
         if (!$existing_jasci) {
            $responseData = $this->JasciApi->getAccessToken($companyId, $tenantId, $userId, $password, $env_type);
            //{"issuedAt":"2023-07-12 10:10:34","expiresAt":"2023-07-12 11:10:34","token":"eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJJTlRFR1JBVElPTl9TSU1PTiIsInRtaWQiOiJJTlRFR1JBVElPTl9TSU1PTiIsImV4cCI6MTY4OTE2MDIzNCwiaWF0IjoxNjg5MTU2NjM0LCJ0aWQiOiI5OTk5NzB1ZFc2OG81T2lJbVNTU3l1YXZmNTdRYlNlMllJRlIiLCJjaWQiOiJTSU1PTlNBWVNTVEFNUCJ9.HxwDsU_kCHZlWNs5mPMTKRWAoZ7ck1xISJcQ5SVREKsOIIKjqYuhBsbcgB14NhMFvbMmVfCuFPQTNYZYp0j3Ng"}
            $response = json_decode($responseData, true);

            if (isset($response) && isset($response['token'])) {
               $accessToken = $response['token'];

               //insert jasci account details
               $jasci_tokens = array(
                  'user_id' => $user_id,
                  'platform_id' => $this->my_platform_id,
                  'account_name' => $companyId,
                  'access_key' => $tenantId,
                  'app_id' => $this->mobj->encrypt_decrypt($userId, $action = 'encrypt'),
                  'app_secret' => $this->mobj->encrypt_decrypt($password, $action = 'encrypt'),
                  'access_token' => $accessToken,
                  'expires_in' => 3600,
                  'token_refresh_time' => time(),
                  'env_type' => $env_type
               );

               DB::table('platform_accounts')->insert($jasci_tokens);
            } else {
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

   public function RefreshToken($id)
   {
      try {
         $platform_account = $this->mobj->getFirstResultByConditions('platform_accounts', ['id' => $id], ['id', 'account_name', 'access_key', 'app_id', 'app_secret', 'env_type']);
         if ($platform_account) {
            $companyId = $platform_account->account_name;
            $tenantId = $platform_account->access_key;
            $userId = $this->mobj->encrypt_decrypt($platform_account->app_id, 'decrypt');
            $password = $this->mobj->encrypt_decrypt($platform_account->app_secret, 'decrypt');
            $env_type = $platform_account->env_type;

            //test auth hash by api call  
            $responseData = $this->JasciApi->getAccessToken($companyId, $tenantId, $userId, $password, $env_type);
            if ($responseData) {
               //{"issuedAt":"2023-07-12 10:10:34","expiresAt":"2023-07-12 11:10:34","token":"eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJJTlRFR1JBVElPTl9TSU1PTiIsInRtaWQiOiJJTlRFR1JBVElPTl9TSU1PTiIsImV4cCI6MTY4OTE2MDIzNCwiaWF0IjoxNjg5MTU2NjM0LCJ0aWQiOiI5OTk5NzB1ZFc2OG81T2lJbVNTU3l1YXZmNTdRYlNlMllJRlIiLCJjaWQiOiJTSU1PTlNBWVNTVEFNUCJ9.HxwDsU_kCHZlWNs5mPMTKRWAoZ7ck1xISJcQ5SVREKsOIIKjqYuhBsbcgB14NhMFvbMmVfCuFPQTNYZYp0j3Ng"}
               $response = json_decode($responseData, true);
               $accessToken = $response['token'];

               //insert jasci account details
               $jasci_tokens = array(
                  'access_token' => $accessToken,
                  'expires_in' => 3600,
                  'token_refresh_time' => time()
               );

               DB::table('platform_accounts')->where(['id' => $platform_account->id])->update($jasci_tokens);
            }
         }
      } catch (\Exception $e) {
         \Log::error($id . " -> JasciController -> RefreshToken -> " . $e->getLine() . " -> " . $e->getMessage());
         return $e->getMessage();
      }
   }

   //Apply do while loop when initial sync & fetch more product in one call
   public function GetProduct($user_id, $user_integration_id, $is_initial_sync)
   {
      try {
         $return_response = true;
         $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['id', 'access_token', 'env_type']);
         if ($platform_account) {
            $page_number = 1;
            $limit = 500;

            //Find product fetch url
            $findProductUrl = PlatformUrl::select('id', 'url')->where([['user_integration_id', '=', $user_integration_id], ['platform_id', '=', $this->my_platform_id], ['url_name', '=', 'Get_jasci_products']])->first();

            if (!$findProductUrl) {
               PlatformUrl::insert(['url' => $page_number, 'user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'url_name' => 'Get_jasci_products', 'status' => 0]);
            } else {
               $page_number = $findProductUrl->url + 1;
            }

            //Get Product Api call
            $method = 'GET';
            $access_token = $platform_account->access_token;
            $env_type = $platform_account->env_type;

            $fetched_data = true;
            $i = 0;
            do {
               $url = '/product?pageNumber=' . $page_number . '&pageSize=' . $limit . '&status=PROCESSED';
               $responseData = $this->JasciApi->ApiCall($method, $url, $access_token, NULL, $env_type);

               if ($responseData) {
                  $response = json_decode($responseData, true);
                  if (isset($response) && isset($response['data']) && count($response['data']) > 0) {
                     //store fetched products
                     $this->InsertUpdateProducts($user_id, $user_integration_id, $response['data']);

                     //update url
                     if ($findProductUrl) {
                        if (count($response['data']) == $limit) {
                           PlatformUrl::where(['id' => $findProductUrl->id])->update(['url' => $page_number]);
                        }
                     }

                     //set response for chunk process products... i
                     if ($is_initial_sync && count($response['data']) == $limit) {
                        $return_response = $page_number . "- page chunks processed";
                     } else {
                        $return_response = true;
                     }

                     //case 1 if count($responseData['data']) < limit then stop loop 
                     if (count($response['data']) < $limit) {
                        $fetched_data = false;
                     }

                     //stop loop after 10 call
                     if ($i >= 10) {
                        $fetched_data = false;
                     }
                  } else {
                     $fetched_data = false;
                     $return_response = true;
                  }
               } else {
                  $fetched_data = false;
                  $return_response = 'Error check api call';
               }

               $i++;
               $page_number++;
            } while ($fetched_data);
         }

         return $return_response;
      } catch (\Exception $e) {
         \Log::error($user_integration_id . " -> JasciController -> GetProduct -> " . $e->getLine() . " -> " . $e->getMessage());
         return $e->getMessage();
      }
   }

   public function InsertUpdateProducts($user_id, $user_integration_id, $data)
   {
      try {
         if (isset($data) && is_array($data) && count($data)) {
            foreach ($data as $val) {
               if (isset($val['product'])) {
                  $sku = $val['product'];
                  $name = @$val['description20'];
                  $description = @$val['description50'];

                  $fields = array(
                     'user_id' => $user_id,
                     'user_integration_id' => $user_integration_id,
                     'platform_id' => $this->my_platform_id,
                     'product_name' => $name,
                     'api_product_id' => '',
                     'sku' => $sku,
                     'description' => $description,
                     // 'product_sync_status' => 'Ready',
                     'is_deleted' => 0,
                  );

                  //find product
                  $findProduct = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'sku' => $sku])->where('is_deleted', 0)->first();

                  //insert if not found
                  if (!$findProduct) {
                     PlatformProduct::insert($fields);
                  }
               }
            }
         }

         return true;
      } catch (\Exception $e) {
         \Log::error($user_integration_id . " -> JasciController -> InsertUpdateProducts -> " . $e->getLine() . " -> " . $e->getMessage());
         return $e->getMessage();
      }
   }

   //Get Inventory Adjustment
   public function GetInventory($user_id, $user_integration_id, $is_initial_sync)
   {
      try {
         $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['id', 'access_token', 'env_type']);

         if ($platform_account) {
            $logFileName = 'get_inventory_adjustment_call_log_' . date('Y-m-d') . '.txt';

            $method = 'GET';
            $url = '/inventory-adjustments?allTransactions=true&pageSize=500';
            $access_token = $platform_account->access_token;
            $env_type = $platform_account->env_type;

            //test auth hash by api call  
            $responseData = $this->JasciApi->ApiCall($method, $url, $access_token, NULL, $env_type);
            if ($responseData) {
               $response = json_decode($responseData, true);
               if ($response && isset($response['data']) && count($response['data']) > 0) {

                  $multiInsertQuery = [];
                  $multiInsertProductPids = [];
                  foreach ($response['data'] as $inv) {
                     $adjustmentData = $this->handleAdjustmentInventoryData($user_id, $user_integration_id, $inv);
                     //prepare data for multi insert
                     if (isset($adjustmentData) && isset($adjustmentData['product_id']) && isset($adjustmentData['query'])) {
                        array_push($multiInsertProductPids, $adjustmentData['product_id']);
                        array_push($multiInsertQuery, $adjustmentData['query']);
                     }

                     //Log snapshot data count
                     \Storage::disk('local')->append($logFileName, 'get Inventory adjustment success Call time: ' . date('Y-m-d H:i:s') . ' total data : ' . count($response['data']) . ' response : ' . json_encode($response));
                  }

                  //insert adjustment data 
                  if ($multiInsertQuery) {
                     PlatformInventoryTrail::insert($multiInsertQuery);
                     PlatformProduct::whereIn('id', $multiInsertProductPids)->update(['adjustment_sync_status' => 'Ready']);
                  }
               } else {

                  $return_response = 'Api error';
                  if (isset($response['message'])) {
                     $return_response = $response['message'];
                  }
                  //Log snapshot data count
                  \Storage::disk('local')->append($logFileName, 'get Inventory adjustment error Call time: ' . date('Y-m-d H:i:s') . ' total data : ' . json_encode($response) . PHP_EOL);
               }
            }
         }

         return true;
      } catch (\Exception $e) {
         \Log::error($user_integration_id . " -> JasciController -> GetInventory -> " . $e->getLine() . " -> " . $e->getMessage());
         return $e->getMessage();
      }
   }

   //insert update inventory data
   public function handleAdjustmentInventoryData($user_id, $user_integration_id, $inv)
   {
      $response_data = [];
      $quantity = 0;
      if (isset($inv) && isset($inv['product'])) {
         $data = [
            'user_id' => $user_id,
            'platform_id' => $this->my_platform_id,
            'user_integration_id' => $user_integration_id
         ];

         $quantity = $inv['quantity'];
         $product_sku = $inv['product'];
         $dateReceived = $inv['dateReceived'];
         $data['product_name'] = $inv['product'];
         $data['sku'] = $inv['product'];
         $data['product_sync_status'] = "Ready";

         ////store it in audit trail
         $product_count = PlatformProduct::where('user_integration_id', $user_integration_id)
            ->where('platform_id', $this->my_platform_id)->where('sku', $product_sku)->where('is_deleted', 0)->select('id')->first();

         if ($product_count) {
            $product_id = $product_count->id;
         } else {
            // $data['adjustment_sync_status'] = 'Ready';
            $product_id = $this->mobj->makeInsertGetId('platform_product', $data);
         }

         $invt_count = PlatformInventoryTrail::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'platform_product_id' => $product_id, 'api_updated_at' => $dateReceived])->select('id', 'api_quantity')->first();

         $inv_arr = [
            'user_id' => $user_id,
            'user_integration_id' => $user_integration_id,
            'platform_product_id' => $product_id,
            'platform_id' => $this->my_platform_id,
            'api_quantity' => $quantity,
            'api_updated_at' => $dateReceived,
            'sync_status' => 'Ready'
         ];

         if (!$invt_count) {
            // PlatformInventoryTrail::insert($inv_arr);
            // PlatformProduct::where('id',$product_id)->update(['adjustment_sync_status' => 'Ready']); 
            $response_data['query'] = $inv_arr;
            $response_data['product_id'] = $product_id;
         }
      }

      return $response_data;
   }

   //get inventory snapshot... daily once to correct inventory...
   public function GetInventorySnapshot($user_id, $user_integration_id, $is_initial_sync)
   {
      $logFileName = 'get_inventory_snapshot_call_log_' . date('Y-m-d') . '.txt';

      $return_response = true;
      try {
         if ($is_initial_sync) {
            return $return_response;
         } else {
            //check time is == EST 10 PM 
            date_default_timezone_set('US/Eastern');

            //run snapshot api call if time == 22 == 10 PM EST 
            if (date('H') == 22 || date('H') == 23) {
               //set default timezone
               date_default_timezone_set(config('app.timezone'));

               DB::table('platform_response_handler')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => 'inventory_snapshot_data'])->whereDate('created_at', '!=', date('Y-m-d'))->limit(100)->delete();

               $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['id', 'access_token', 'env_type']);
               if ($platform_account) {
                  $method = 'GET';
                  //run do while loop & check if count < 1000 then stop
                  //$url = '/inventory-snapshot?limit=1000&markProcessed=true';
                  $url = '/inventory-snapshot?page=0&limit=1000&markProcessed=true';
                  $access_token = $platform_account->access_token;
                  $env_type = $platform_account->env_type;

                  $fetched_data = true;
                  $snapshot_in_one_row_limit = 100;
                  $api_call_limit = 15;
                  $count_api_call = 0;

                  do {
                     //test auth hash by api call  
                     $responseData = $this->JasciApi->ApiCall($method, $url, $access_token, NULL, $env_type);
                     if ($responseData) {
                        $response = json_decode($responseData, true);
                        if (isset($response['data']) && is_array($response['data']) && count($response['data'])) {

                           //store responseData in table in chunks...
                           $snapshot_formatted_chunk_array = [];
                           $snapshotChunkArray = array_chunk($response['data'], $snapshot_in_one_row_limit);

                           foreach ($snapshotChunkArray as $snapshotData) {
                              $keysToKeep = array(
                                 'product' => '',
                                 'quantity' => '',
                                 'totalAvailable' => '',
                                 'quantity01' => '',
                                 'quantity03' => '',
                                 'dateReceived' => '',
                              );

                              // iterate over array and apply the filtering
                              foreach ($snapshotData as $key => $subarray) {
                                 $snapshotData[$key] = array_intersect_key($subarray, $keysToKeep);
                              }

                              //formate data to store with data key
                              $snapshot_formatted_chunk_array['data'] = $snapshotData;

                              DB::table('platform_response_handler')->insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => 'inventory_snapshot_data', 'url' => json_encode($snapshot_formatted_chunk_array), 'url_backup' => json_encode($snapshot_formatted_chunk_array)]);

                              //Log snapshot data count
                              \Storage::disk('local')->append($logFileName, 'get snapshot Call time: ' . date('Y-m-d H:i:s') . ' total data in snapshot : ' . count($response['data']) . ' stored data in row : ' . count($snapshotData));
                           }
                        } else {
                           //Log snapshot data count
                           \Storage::disk('local')->append($logFileName, 'get snapshot Call time: ' . date('Y-m-d H:i:s') . ' Response :' . json_encode($responseData));
                        }

                        //case 2 if count($response['data']) < 1000 then stop loop 
                        if (count($response['data']) < 1000) {
                           $fetched_data = false;
                        }

                        //if total api call
                        if ($count_api_call >= $api_call_limit) {
                           $fetched_data = false;
                        }
                     } else {
                        //Log snapshot data count
                        \Storage::disk('local')->append($logFileName, 'get snapshot Call time: ' . date('Y-m-d H:i:s') . ' Response :' . json_encode($responseData));

                        //case 3 if responseData not get & stop loop 
                        $fetched_data = false;
                     }

                     $count_api_call++;
                  } while ($fetched_data);
               }
            }
         }
      } catch (\Exception $e) {
         Log::error($user_integration_id . " -> JasciController -> GetInventorySnapshot -> " . $e->getLine() . " -> " . $e->getMessage());
         return $e->getMessage();
      }

      return $return_response;
   }

   //get inventory snapshot... daily once to correct inventory...
   public function storeInventorySnapshot($user_id, $user_integration_id, $is_initial_sync)
   {
      $return_response = true;
      $logFileName = 'store_inventory_snapshot_call_log_' . date('Y-m-d') . '.txt';


      try {
         if ($is_initial_sync) {
            return $return_response;
         } else {

            //used for get platform_response_handler url for process
            $process_url_limit = 10;
            //Multi query update call for limit data
            $multi_update_limit = 15;
            //chunk snapshot data for store in inventory tables
            $process_limit = 100;


            //get snapshot json row with 0 status... to process 
            $snapshotDataArray = DB::table('platform_response_handler')->select('id', 'url')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => 'inventory_snapshot_data', 'status' => 0])
            ->whereDate('created_at', date('Y-m-d'))
            ->orderBy('updated_at', 'asc')->limit($process_url_limit)->get();


            if ($snapshotDataArray) {

               //update status = 2 for selected platform_response_handler urls
               $processing_url_ids = [];
               foreach ($snapshotDataArray as $row) {
                  array_push($processing_url_ids, $row->id);
               }
               if ($processing_url_ids) {
                  DB::table('platform_response_handler')->whereIn('id', $processing_url_ids)->update(['status' => 2]);
               }


               \Storage::disk('local')->append($logFileName, '-------0000---------0000------ storeInventorySnapshot Start Call time: ' . date('Y-m-d H:i:s').PHP_EOL);

               //start process 10 picked snapshot data
               foreach ($snapshotDataArray as $snapshotData) {

                  $snapshotJsonArray = json_decode($snapshotData->url, true);
                  

                  if (isset($snapshotJsonArray['data'])) {

                     
                     //chunk url data again...
                     $snapshotChunkArray = array_chunk($snapshotJsonArray['data'], $process_limit);

                     if ($snapshotChunkArray && count($snapshotChunkArray) > 0) {

                        //single row process log
                        \Storage::disk('local')->append($logFileName, '-----------storeInventorySnapshot single row process for 100 data Call time: ' . date('Y-m-d H:i:s').PHP_EOL.PHP_EOL);

                        //process 100 product inventory stored in single row
                        foreach ($snapshotChunkArray as $snapshotChunkDataArray) {

                           $multiInsertQuery = [];
                           $multiInsertUpdateProductPids = [];
                           $multiUpdateRowQuery = [];
                           $remainingData = [];

                           //insert snapshot inventory data
                           foreach ($snapshotChunkDataArray as $inv) {

                              $adjustmentData = $this->handleSnapshotInventoryData($user_id, $user_integration_id, $inv);

                              //prepare data for multi insert
                              if (isset($adjustmentData) && isset($adjustmentData['product_id']) && isset($adjustmentData['query'])) {
                                 if ($adjustmentData['action'] == 'insert') {

                                    //check product ids already exist in array then ignore to avoid multiple insert
                                    if (!in_array($adjustmentData['product_id'], $multiInsertUpdateProductPids)) {
                                       array_push($multiInsertUpdateProductPids, $adjustmentData['product_id']);
                                       array_push($multiInsertQuery, $adjustmentData['query']);
                                    }
                                 } else if ($adjustmentData['action'] == 'update') {

                                    //check product ids already exist in array then ignore to avoid multiple insert
                                    if (!in_array($adjustmentData['product_id'], $multiInsertUpdateProductPids)) {
                                       //add product ids in array for update at END
                                       array_push($multiInsertUpdateProductPids, $adjustmentData['product_id']);
                                       $raw_update_query = "UPDATE platform_product_inventory SET quantity=" . $adjustmentData['query']['quantity'] . ", api_updated_at='" . $adjustmentData['query']['api_updated_at'] . "' ,sync_status='" . $adjustmentData['query']['sync_status'] . "' WHERE id = " . $adjustmentData['product_inv_id'];
                                       //push update query in array
                                       array_push($multiUpdateRowQuery, $raw_update_query);
                                    }


                                    //run update query if count == $multi_update_limit
                                    if (count($multiUpdateRowQuery) >= $multi_update_limit) {

                                       $sql = implode(";", $multiUpdateRowQuery);

                                       //start update query
                                       \Storage::disk('local')->append($logFileName, 'storeInventorySnapshot start update query : ' . date('Y-m-d H:i:s') . ' Total inventory :' . count($multiUpdateRowQuery));
                                       $update_done = DB::unprepared($sql);
                                       //end query
                                       \Storage::disk('local')->append($logFileName, 'storeInventorySnapshot end update query : ' . date('Y-m-d H:i:s').PHP_EOL);

                                       if ($update_done) {
                                          $multiUpdateRowQuery = [];
                                       }
                                    }
                                 }
                              }
                           }


                           //insert adjustment data 
                           if ($multiInsertQuery) {

                              //start insert query
                              \Storage::disk('local')->append($logFileName, 'storeInventorySnapshot start insert  query : ' . date('Y-m-d H:i:s') . ' query :' . json_encode($multiInsertQuery));
                              PlatformProductInventory::insert($multiInsertQuery);
                              //end insert query
                              \Storage::disk('local')->append($logFileName, 'storeInventorySnapshot end insert  query : ' . date('Y-m-d H:i:s').PHP_EOL);
                           }

                           //run update query if any data remain..in case data < 20
                           if (count($multiUpdateRowQuery) > 0) {

                              //start update remaining data
                              \Storage::disk('local')->append($logFileName, '--xx--xx---storeInventorySnapshot update remaining data  : ' . date('Y-m-d H:i:s') . ' count :' . count($multiUpdateRowQuery).PHP_EOL);

                              $sql = implode(";", $multiUpdateRowQuery);
                              $update_done = DB::unprepared($sql);     
                              if ($update_done) {
                                 $multiUpdateRowQuery = [];
                              } else {
                                 return "Multi query update Failed";
                              }
                           }

                           //update Products
                           if ($multiInsertUpdateProductPids) {
                              PlatformProduct::whereIn('id', array_unique($multiInsertUpdateProductPids))->update(['inventory_sync_status' => 'Ready']);
                           }

                           //removed processed chunk data from snapshotChunkArray
                           array_shift($snapshotChunkArray);

                           if (count($snapshotChunkArray) > 0) {
                              $remainingData['data'] = $snapshotChunkArray;
                              DB::table('platform_response_handler')->where('id', $snapshotData->id)->update([
                                 'updated_at' => date('Y-m-d H:i:s'),
                                 'url' => json_encode($remainingData)
                              ]);
                           } else {
                              DB::table('platform_response_handler')->where('id', $snapshotData->id)->update(['status' => 1, 'updated_at' => date('Y-m-d H:i:s'), 'url' => NULL]);
                           }

                        }

                     }
                  }
               }

               \Storage::disk('local')->append($logFileName, '-------xxxx---------xxxx------ storeInventorySnapshot End Call time: ' . date('Y-m-d H:i:s'));
            }
         }
      } catch (\Exception $e) {
         Log::error($user_integration_id . " -> JasciController -> storeInventorySnapshot -> " . $e->getLine() . " -> " . $e->getMessage());
         return $e->getMessage();
      }
   }

   //insert update inventory data
   public function handleSnapshotInventoryData($user_id, $user_integration_id, $inv)
   {
      $response_data = [];

      try {
         if (isset($inv['product'])) {
            $data = [
               'user_id' => $user_id,
               'platform_id' => $this->my_platform_id,
               'user_integration_id' => $user_integration_id
            ];

            // $quantity = $inv['quantity'];
            // $quantity = $inv['totalAvailable'];
            $quantity = $inv['quantity01'] + $inv['quantity03'];
            $product_sku = $inv['product'];
            $dateReceived = $inv['dateReceived'];

            $data['product_name'] = $inv['product'];
            $data['sku'] = $inv['product'];

            $product_count = PlatformProduct::where('user_integration_id', $user_integration_id)
               ->where('platform_id', $this->my_platform_id)->where('sku', $product_sku)->where('is_deleted', 0)->select('id')->first();

            if ($product_count) {
               $product_id = $product_count->id;
               //Ignore existing..Adjustment
               if ($product_count->adjustment_sync_status == "Ready") {

                  $current_received_date = date_create($dateReceived);
                  $api_updated_at = date_format($current_received_date, "Y-m-d H:i:s");

                  $old_adjustment_row_array = PlatformInventoryTrail::where(['user_integration_id' => $user_integration_id, 'platform_product_id' => $product_id])
                     ->where(DB::raw("(DATE_FORMAT(api_updated_at,'%Y-%m-%d %H:%i:%s'))"), '<', $api_updated_at)
                     ->select('id')->pluck('id')->toArray();

                  if (count($old_adjustment_row_array) > 0) {
                     PlatformInventoryTrail::whereIn('id', $old_adjustment_row_array)->update(['sync_status' => 'Ignore']);
                     PlatformProduct::where('id', $product_id)->update(['adjustment_sync_status' => 'Ignore']);
                  }
               }
               //end Ignore adjustment when snapshot received...
            } else {
               // $data['product_sync_status'] = 'Ready';
               $product_id = $this->mobj->makeInsertGetId('platform_product', $data);
            }

            $invt_count = PlatformProductInventory::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'platform_product_id' => $product_id])->select('id', 'quantity')->first();

            $inv_arr = [
               'user_id' => $user_id,
               'user_integration_id' => $user_integration_id,
               'platform_product_id' => $product_id,
               'platform_id' => $this->my_platform_id,
               'sku' => $product_sku,
               'quantity' => $quantity,
               'api_updated_at' => $dateReceived,
               'sync_status' => 'Ready'
            ];

            if ($invt_count) {
               $action = 'update';
               $product_inv_id = $invt_count->id;
               // PlatformProductInventory::where('id', $invt_count->id)->update($inv_arr);
            } else {
               $action = 'insert';
               $product_inv_id = NULL;
               // PlatformProductInventory::insert($inv_arr);
            }

            // PlatformProduct::where('id', $product_id)->update(['inventory_sync_status'=>'Ready']);

            $response_data['action'] = $action;
            $response_data['product_inv_id'] = $product_inv_id;
            $response_data['query'] = $inv_arr;
            $response_data['product_id'] = $product_id;
         }

         return $response_data;
      } catch (\Exception $e) {
         Log::error($user_integration_id . " -> JasciController -> handleSnapshotInventoryData -> " . $e->getLine() . " -> " . $e->getMessage());
         return $e->getMessage();
      }
   }

   public function CreatePurchaseOrder($user_id = null, $user_integration_id = null, $platform_workflow_rule_id = null, $user_workflow_rule_id = null, $SourcePlatformName = null, $sync_status = "Ready", $record_id = null)
   {

      try {

         $return_response = true;

         $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['id', 'access_token', 'account_name', 'access_key', 'env_type']);

         if ($platform_account) {

            $source_platform_id = $this->helper->getPlatformIdByName($SourcePlatformName);

            if ($source_platform_id) {


               $object_id = $this->helper->getObjectId('sales_order');

               $limit = 20;
               $parent_orders = PlatformOrder::where(['platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id]);

               if ($record_id) {
                  $parent_orders = $parent_orders->where('platform_order.id', $record_id);
               } else {
                  $parent_orders = $parent_orders->where('sync_status', $sync_status);
               }

               $parent_orders = $parent_orders->limit($limit)->orderBy('updated_at', 'desc')->get();


               if ($parent_orders) {

                  foreach ($parent_orders as $parent_order) {

                     if ($parent_order->is_deleted == 1) {

                        $message = "Order related data deleted in source platform.";

                        $statusForSync = 'failed';
                        $parent_order->sync_status = 'Failed';
                        $parent_order->order_updated_at = date('Y-m-d H:i:s');
                        $parent_order->save();

                        $return_response = $message;

                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, $statusForSync, $parent_order->id, $message);
                     } else {

                        //order status mapping
                        $order_status = "OPEN";
                        $default_order_mapping = $this->mapping->getMappedDataByName($user_integration_id, null, "get_order_status", ['name']);
                        if ($default_order_mapping) {
                           $order_status = $default_order_mapping->name;
                        }


                        $method = 'POST';
                        $url = '/purchase-order-header-details';
                        $access_token = $platform_account->access_token;
                        $companyId = $platform_account->account_name;
                        $tenantId = $platform_account->access_key;
                        $env_type = $platform_account->env_type;


                        $payload = array();
                        $payload['tenantId'] = $tenantId;
                        $payload['companyId'] = $companyId;

                        //update after confirmation
                        $payload["fulfillmentCenterId"] = 1;
                        // $payload["supplierId"] = '';

                        $payload['purchaseOrderNumber'] = $parent_order->order_number;
                        $payload['status'] = $order_status;
                        $payload['action'] = 'INSERT';

                        $items_posting = $this->getPurchaseOrderLine($tenantId, $companyId, $parent_order, $order_status);

                        $payload['details'] = $items_posting['items_posting'];

                        $post_data = json_encode($payload, true);

                        //call api for create order
                        $responseData = $this->JasciApi->ApiCall($method, $url, $access_token, $post_data, $env_type);
                        $response = json_decode($responseData, true);

                        if ($response && isset($response['status']) && $response['status'] == 'CREATED') {

                           if ($parent_order->linked_id == 0) {

                              //insert order for jasci
                              $orderdata = ['user_id' => $user_id, 'user_workflow_rule_id' => $user_workflow_rule_id, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_customer_id' => $parent_order->platform_customer_id, 'linked_id' => $parent_order->id];

                              $child_order = PlatformOrder::create($orderdata);
                           } else {

                              //update jasci order if already exists
                              $orderdata = ['sync_status' => 'Synced'];
                              PlatformOrder::where('id', $parent_order->linked_id)->update($orderdata);

                              $child_order = PlatformOrder::find($parent_order->linked_id);
                           }


                           //need to update as per apiResponse
                           $child_order->api_order_id = '';
                           $child_order->order_number = $parent_order->order_number;
                           $child_order->order_status = 'Synced';
                           $child_order->order_updated_at = date('Y-m-d H:i:s');
                           $child_order->save();

                           // parent order
                           $parent_order->sync_status = 'Synced';
                           $parent_order->order_updated_at = date('Y-m-d H:i:s');
                           $parent_order->linked_id = $child_order->id;
                           $parent_order->save();
                           $message = "Order synced successfully.";
                           $statusForSync = 'success';


                           $return_response = true;
                        } else {

                           $message = "Order failed to sync";
                              if($response && isset($response['errors']) && isset($response['errors'][0]['message'])) {
                                 $message = $response['errors'][0]['message'];
                              } else if( $response && isset($response['error']) && isset($response['message']) ) {
                                 $message = $response['error'].' | '.$response['message'];
                              }
      


                           $statusForSync = 'failed';
                           $parent_order->sync_status = 'Failed';
                           $parent_order->order_updated_at = date('Y-m-d H:i:s');
                           $parent_order->save();
                           $return_response = $message;
                        }

                        $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, $statusForSync, $parent_order->id, $message);
                     }
                  }
               }
            }
         }
      } catch (\Exception $e) {
         \Log::error($user_integration_id . " -> JasciController -> CreatePurchaseOrder -> " . $e->getLine() . " -> " . $e->getMessage());
         return $e->getMessage();
      }

      return $return_response;
   }

   //get purchaseOrderLine
   public function getPurchaseOrderLine($tenantId, $companyId, $parent_order, $order_status)
   {
      $items_posting = [];

      //get Order line
      $orderLineItems = PlatformOrderLine::where('platform_order_id', $parent_order->id)->get();
      if ($orderLineItems) {

         $i = 1;
         foreach ($orderLineItems as $lineItem) {

            $item_row_sequence = $i;
            if ($lineItem->item_row_sequence) {
               $item_row_sequence = $lineItem->item_row_sequence;
            }

            $line['tenantId'] = $tenantId;
            $line['companyId'] = $companyId;

            //update after confirmation
            $line['fulfillmentCenterId'] = 1;
            // $line['supplierId'] = '';
            // $line['shipmentNumber'] = '';

            $line['purchaseOrderNumber'] = $parent_order->order_number;
            $line['status'] = $order_status;
            $line['lineNumber'] = $item_row_sequence;
            $line['product'] = $lineItem->sku;
            $line['quantityExpected'] = $lineItem->qty;
            $items_posting[] = $line;

            $i++;
         }
      }

      return ['items_posting' => $items_posting];
   }


   //Get goods in note from jasci
   public function getGoodsInNote($user_id, $user_integration_id, $is_initial_sync, $destination_platform_id)
   {
      try {

         $return_response = true;

         if ($is_initial_sync) {
            return $return_response;
         } else {
            
            $logFileName = 'jasci_receipt_confirmation_call_log_' . date('Y-m-d') . '.txt';
            $url_name = "gin_note_data";
            $gin_note_in_one_row_limit = 100;
            
            $platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['id', 'access_token', 'env_type']);
            if ($platform_account) {

               //delete old processed data
               // $cound_processed_ginNote = DB::table('platform_response_handler')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => $url_name, 'status' => 1])->whereDate('created_at', '!=', date('Y-m-d'))->count();
               // if($cound_processed_ginNote > 0) {
               //    DB::table('platform_response_handler')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => $url_name, 'status' => 1])->whereDate('created_at', '!=', date('Y-m-d'))->limit(100)->delete();
               // }
               //end


               $method = 'GET';
               $url = '/receipt-confirmations';
               $access_token = $platform_account->access_token;
               $env_type = $platform_account->env_type;

               //test auth hash by api call  
               $responseData = $this->JasciApi->ApiCall($method, $url, $access_token, NULL, $env_type);
               
               if ($responseData) {

                  $response = json_decode($responseData, true);

                  //Log receipt-confirmations data added on 26-05-2023
                  \Storage::disk('local')->append($logFileName, 'get getGoodsInNote | user_integration_id : ' . $user_integration_id . ' Response Data : ' . json_encode($response));

                  if ($response && isset($response['data']) && count($response['data']) > 0) {

                     //store responseData in table in chunks...
                     $ginNote_formatted_chunk_array = [];
                     $ginNoteChunkArray = array_chunk($response['data'], $gin_note_in_one_row_limit);

                     foreach ($ginNoteChunkArray as $ginNoteData) {

                        $keysToKeep = array(
                           'product' => '',
                           'quantity' => '',
                           'orderId' => '',
                           'salesOrderId' => '',
                           'purchaseOrderNumber' => '',
                           'shipmentId' => '',
                           'status' => '',
                           'dateCreated' => '',
                        );
      
                        // iterate over array and apply the filtering
                        foreach ($ginNoteData as $key => $subarray) {
                           $ginNoteData[$key] = array_intersect_key($subarray, $keysToKeep);
                        }
      
                        //formate data to store with data key
                        $ginNote_formatted_chunk_array['data'] = $ginNoteData;

               
                        DB::table('platform_response_handler')->insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => $url_name, 'url' => json_encode($ginNote_formatted_chunk_array)]);

                        //Log snapshot data count
                        \Storage::disk('local')->append($logFileName, 'get snapshot Call time: ' . date('Y-m-d H:i:s') . ' total data in ginNote : ' . count($response['data']) . ' stored data in row : ' . count($ginNoteData));

                        
                     }

                     
                  } else {
                     //Log GoodsinNote data count
                     \Storage::disk('local')->append($logFileName, 'get getGoodsInNote Call time: ' . date('Y-m-d H:i:s') . ' Response :' . json_encode($responseData));
                  }

               }
            }

            return $return_response;
         }
         
      } catch (\Exception $e) {
         \Log::error($user_integration_id . " -> JasciController -> getGoodsInNote -> " . $e->getLine() . " -> " . $e->getMessage());
         return $e->getMessage();
      }
   }
   //store gon recieved in db
   public function storeGinNoteData($user_id, $user_integration_id, $is_initial_sync)
   {
      $return_response = true;
      $logFileName = 'store_gin_note_data_call_log_' . date('Y-m-d') . '.txt';

      try {
         if ($is_initial_sync) {
            return $return_response;
         } else {

            $url_name = "gin_note_data";
            //used for get platform_response_handler url for process
            $process_url_limit = 5;
            //chunk snapshot data for store in inventory tables
            $process_limit = 100;  //100

            //get snapshot json row with 0 status... to process 
            $ginNoteDataArray = DB::table('platform_response_handler')->select('id', 'url')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => $url_name, 'status' => 0])
            ->whereDate('created_at', date('Y-m-d'))->orderBy('updated_at', 'asc')->limit($process_url_limit)->get();
         
            if ($ginNoteDataArray) {

               //update status = 2 for selected platform_response_handler urls
               $processing_url_ids = [];
               foreach ($ginNoteDataArray as $row) {
                  array_push($processing_url_ids, $row->id);
               }

               if ($processing_url_ids) {
                  DB::table('platform_response_handler')->whereIn('id', $processing_url_ids)->update(['status' => 2]);
               }
               //end

               foreach ($ginNoteDataArray as $ginNoteData) {

                  $ginNoteJsonArray = json_decode($ginNoteData->url, true);

                  if (isset($ginNoteJsonArray['data'])) {

                     //chunk url data again...
                     $ginNoteChunkArray = array_chunk($ginNoteJsonArray['data'], $process_limit);

                    
            
                     if ($ginNoteChunkArray && count($ginNoteChunkArray) > 0) {

                        foreach ($ginNoteChunkArray as $ginNoteChunkDataArray) {

                           //Log snapshot data count
                           \Storage::disk('local')->append($logFileName, 'start of loop process gin_note data Call time: ' . date('Y-m-d H:i:s') . ' total data processed : ' . count($ginNoteChunkDataArray) . PHP_EOL);

                           $remainingData = [];
                           $failed_po_receipt_data_formated = [];

                           //insert snapshot inventory data
                           $failed_po_receipt_data = [];
                           foreach ($ginNoteChunkDataArray as $inv) {
                              $handleInvReceiptStatus = $this->handleInventoryReceiptData($user_id, $user_integration_id, $inv,NULL);
                              //if failed! add in failed_po_receipt_data for furture processing
                              if(!is_bool($handleInvReceiptStatus)) {
                                 array_push($failed_po_receipt_data,$inv);
                              }
                           }

                           //removed processed chunk data from ginNoteChunkArray
                           array_shift($ginNoteChunkArray);

                           if (count($ginNoteChunkArray) > 0) {
                              $remainingData['data'] = $ginNoteChunkArray;
                              DB::table('platform_response_handler')->where('id', $ginNoteData->id)->update([
                                 'updated_at' => date('Y-m-d H:i:s'),
                                 'url' => json_encode($remainingData)
                              ]);
                           } else {
                              // DB::table('platform_response_handler')->where('id', $ginNoteData->id)->update(['status' => 1, 'updated_at' => date('Y-m-d H:i:s'), 'url' => NULL]);
                              DB::table('platform_response_handler')->where('id', $ginNoteData->id)->delete();
                           }

                           //reinsert failed data
                           if($failed_po_receipt_data) {
                              $failed_po_receipt_data_formated['data'] = $failed_po_receipt_data;
                              DB::table('platform_response_handler')->insert(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'url_name' => $url_name, 'url' => json_encode($failed_po_receipt_data_formated)]);
                           }

                           //Log snapshot data count
                           \Storage::disk('local')->append($logFileName, 'End of loop process gin_note data Call time: ' . date('Y-m-d H:i:s') . ' total data processed : ' . count($ginNoteChunkDataArray) . PHP_EOL);



                        }

                     }

                  }

               }


            }

         }
      } catch (\Exception $e) {
         Log::error($user_integration_id . " -> JasciController -> storeGinNoteData -> " . $e->getLine() . " -> " . $e->getMessage());
         return $e->getMessage();
      }

   }
   public function handleInventoryReceiptData($user_id, $user_integration_id, $invReceipt, $destination_platform_id)
   {
      try {

         $return_response = true;

         // $dest_platform_id = $this->helper->getPlatformIdByName($destination_platform_id);

         if (isset($invReceipt) && isset($invReceipt['product']) && isset($invReceipt['quantity']) && $invReceipt['quantity'] > 0) {

            //store data in shipment & shipment line with type POShipment
            $quantity = $invReceipt['quantity'];
            $product_sku = $invReceipt['product'];
            $dateCreated = $invReceipt['dateCreated'];
            $purchaseOrderNumber = $invReceipt['purchaseOrderNumber'];
            $shipmentId = $invReceipt['shipmentId'];

            $orderId = $invReceipt['orderId'];
            $salesOrderId = $invReceipt['salesOrderId'];
            $status = $invReceipt['status'];

            // check order is created by our system ... pull order only if is_fully_synced not 1
            $order = PlatformOrder::select('id', 'linked_id', 'api_order_id', 'platform_order_shipment_id', 'shipment_api_status', 'shipment_status', 'is_fully_synced')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->my_platform_id, 'order_number' => $invReceipt['purchaseOrderNumber'], 'is_fully_synced' => 0])->first();

      
            if ($order) {

               //Insert always as new shipment ..no duplicate get by jasci api
               $shipmentData = ['user_id' => $user_id, 'platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'shipment_id' => $shipmentId, 'platform_order_id' => $order->id, 'order_id' => $purchaseOrderNumber, 'type' => 'POShipment', 'sync_status' => 'Ready', 'created_on' => $dateCreated];

               $platform_order_shipment_id = $this->mobj->makeInsertGetId('platform_order_shipments', $shipmentData);
               

               //find destination order 
               $find_dest_order = PlatformOrder::where(['id' => $order->linked_id])->select('currency')->first();
               $currency = ($find_dest_order) ? $find_dest_order->currency : 'USD';

               //find destination product orderline
               $find_dest_product = PlatformOrderLine::where(['sku' => $product_sku, 'platform_order_id' => $order->linked_id])
                  ->select('api_product_id', 'api_order_line_id', 'total')->first();

               
               $total_shipment_line_qty = 0;

               //Find shipmentIds by order for get total shipment line quantity sum
               $list_shipment_ids = PlatformOrderShipment::where('platform_order_id', $order->id)->where('user_integration_id', $user_integration_id)
               ->where('platform_id', $this->my_platform_id)->select('id')->pluck('id')->toArray();

               $shipment_line_data = DB::table('platform_order_shipment_lines')->select(DB::raw("SUM(quantity) as Quantity"))
                  ->whereIn('platform_order_shipment_id', $list_shipment_ids)->first();
               if ($shipment_line_data) {
                  $total_shipment_line_qty = $shipment_line_data->Quantity;
               }

               //find purchaseOrderRowId for this.. line item
               if ($find_dest_product) {

                  $shipmentLineData = ['platform_order_shipment_id' => $platform_order_shipment_id, 'sku' => $product_sku, 'quantity' => $quantity, 'sync_status' => 'Ready', 'row_id' => $find_dest_product->api_order_line_id, 'product_id' => $find_dest_product->api_product_id, 'price' => ($find_dest_product->total) ? $find_dest_product->total : 0, 'currency' => $currency];

                  //update total received quantity
                  $total_shipment_line_qty = $total_shipment_line_qty + $quantity;

               } else {
                  $shipmentLineData = [
                     'platform_order_shipment_id' => $platform_order_shipment_id, 'sku' => $product_sku, 'quantity' => $quantity, 'sync_status' => 'Ready',
                     'row_id' => 1, 'currency' => $currency
                  ];

                  //update total received quantity
                  $total_shipment_line_qty = $total_shipment_line_qty + $quantity;
               }


               //Always insert...as new line
               $this->mobj->makeInsert('platform_order_shipment_lines', $shipmentLineData);
               //end insert update shipment line


               $order_data = ['shipment_status' => 'Ready'];

               /* get Jasci order Line & shipment Line total quantity if match then update is_fully_synced = true   || handle Open & closed Status $status || purchase order get with closed status then set is_fully_synced = 1 */
               $total_order_line_qty = 0;
               $order_line_data = DB::table('platform_order_line')->select(DB::raw("SUM(qty) as Quantity"))->where('platform_order_id', $order->linked_id)->first();
               if ($order_line_data) {
                  $total_order_line_qty = $order_line_data->Quantity;
               }

      
               // if total shipment line quantity > = total order line quantity
               if ($total_order_line_qty && $total_shipment_line_qty && $total_shipment_line_qty >= $total_order_line_qty) {
                  $order_data['is_fully_synced'] = 1;
               }

               //Log receipt data count
               $logFileName = 'inventory_receipt_count_call_log_' . date('Y-m-d') . '.txt';
               \Storage::disk('local')->append($logFileName, 'handleInventoryReceiptData Call time: ' . date('Y-m-d H:i:s') . ' total_order_line_qty : '.$total_order_line_qty.' total_shipment_line_qty :'.$total_shipment_line_qty. ' order_data : '.json_encode($order_data). ' orderId :'.$order->id. ' list_shipment_ids :'. json_encode($list_shipment_ids) .PHP_EOL);

               $this->mobj->makeUpdate('platform_order', $order_data, ['id' => $order->id]);

            }

         }

         return $return_response;

      } catch (\Exception $e) {
         \Log::error($user_integration_id . " -> JasciController -> handleInventoryReceiptData -> " . $e->getLine() . " -> " . $e->getMessage());
         return $e->getMessage();
      }
   }
   
   //Execute event for calling function by events
   public function ExecuteEventJasci($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
   {
      try {
         $response = true;

         if ($method == 'GET' && $event == 'PRODUCT') {
            $this->GetProduct($user_id, $user_integration_id, $is_initial_sync);
         } else if ($method == 'GET' && $event == 'INVENTORY') {
            if (!$is_initial_sync) {
               $this->GetInventory($user_id, $user_integration_id, $is_initial_sync);
            }
         } else if ($method == 'GET' && $event == 'INVENTORYSNAPSHOT') {
            //pull snapshot inventory data
            $this->GetInventorySnapshot($user_id, $user_integration_id, $is_initial_sync);
         } else if ($method == 'GET' && $event == 'INVENTORYSNAPSHOTSTORE') {
            //store snapshot inventory data
            $this->storeInventorySnapshot($user_id, $user_integration_id, $is_initial_sync);
         } else if ($method == 'MUTATE' && $event == 'PURCHASEORDER') {
            if (!$is_initial_sync) {
               $this->CreatePurchaseOrder($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, 'Ready', $record_id);
            }
         } else if ($method == 'GET' && $event == 'GOODSINNOTE') {
            //Get goods in note from jasci & update brightpearl PO as received
            $this->getGoodsInNote($user_id, $user_integration_id, $is_initial_sync, $destination_platform_id);
         } else if ($method == 'GET' && $event == 'STOREGOODSINNOTE') {
            //Get goods in note from jasci & update brightpearl PO as received
            $this->storeGinNoteData($user_id, $user_integration_id, $is_initial_sync);
         }

         return $response;
      } catch (\Exception $e) {
         \Log::error($user_integration_id . " -> JasciController -> ExecuteEventJasci -> " . $e->getLine() . " -> " . $e->getMessage());
         return $e->getMessage();
      }
   }

   //test_jasci
   public function test()
   {
      $user_id = 523;
      $user_integration_id = 521;
      $is_initial_sync = false;
      $platform_workflow_rule_id = 178;
      $user_workflow_rule_id = 1159;
      $SourcePlatformName = 'brightpearl';
      $record_id = NULL;


      // $this->storeGinNoteData(523, 521, false);
      // die();


      //  $this->RefreshToken(1081);

      // $this->GetProduct($user_id,$user_integration_id,true);

      //  $this->CreatePurchaseOrder($user_id, $user_integration_id, 178, 1206, $SourcePlatformName, "Ready", $record_id);

      // $this->GetInventory($user_id, $user_integration_id, $is_initial_sync);


      //  $this->GetInventorySnapshot($user_id, $user_integration_id, $is_initial_sync);



      //  $this->getGoodsInNote($user_id, $user_integration_id, $is_initial_sync,'brightpearl');


      //  app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->SyncInventoryBulk($user_id, $user_integration_id, 'jasci', 158, 1114, 'Ready', NULL);
      //  die();

      //   app('App\Http\Controllers\Brightpearl\BrightPearlApiSubController')->AdjustInventory($user_id, $user_integration_id, 'jasci', 179, 1207, 'Ready', NULL);
      //   die();

      // $find_pending_products = PlatformProduct::where(['user_integration_id'=>$user_integration_id,'inventory_sync_status'=>'Ignore','platform_id'=>$this->my_platform_id])
      // ->select('id','sku')->get();
      // foreach($find_pending_products as $product) {
      //    $find_dest_prod = PlatformProduct::where(['user_integration_id'=>$user_integration_id,'platform_id'=>$this->my_platform_id])
      //    ->select('id','sku')->get();
      // }

      //SELECT * from platform_product WHERE user_integration_id =521 AND inventory_sync_status ='Ready' AND platform_id =48

      //  app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->SearchOrdersByType($user_id, $user_integration_id, 178, 1206, false, 'purchase_orders');

      //  app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetOrdersByType($user_id, $user_integration_id, 178, 1206, 'jasci', false, 'purchase_orders', 0);


      // $this->test_adjustment_multiInsert();
      // $this->test_snapshot_multiInsert();


      // $product_inv_id = 13105762;
      // $product_inv_id1 = 13105763;
      // $sku_id = 1;

      // echo date('H:i:s')."<br>";
      // $sql = "
      //    UPDATE platform_product_inventory SET sku_id = '$sku_id' WHERE id = '$product_inv_id1';
      //    UPDATE platform_product_inventory SET sku_id = '$sku_id' WHERE id = '$product_inv_id';
      // ";
      // DB::unprepared($sql);
      // echo date('H:i:s')."<br>";

      // echo "--------------";
      // echo date('H:i:s')."<br>";
      // PlatformProductInventory::where('id', $product_inv_id)->update(['sku_id'=>$sku_id]);
      // PlatformProductInventory::where('id', $product_inv_id1)->update(['sku_id'=>$sku_id]);

      // echo date('H:i:s')."<br>";


      // $count_old_adjustment = PlatformInventoryTrail::where(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_product_id' => $product_id, 'sync_status'=>'Ready'])->where('api_updated_at', '<', $dateReceived)->count();


      // $response = app('App\Http\Controllers\Brightpearl\BrightPearlApiSubController')->CreatePOGoodsInNote(523, 521, 160, 1116, 'jasci', "Ready", 2888961);
      // dd($response);

      // $this->storeInventorySnapshot($user_id, $user_integration_id, $is_initial_sync);
      // die();


      $response = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->updateInventorySyncStatus($user_id, $user_integration_id, 'jasci', "Ready");
      die();


   }
}
