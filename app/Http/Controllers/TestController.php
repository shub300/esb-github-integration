<?php

namespace App\Http\Controllers;

use App\Console\Kernel;
use NetSuite\Classes\RecordType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Helper\MainModel;
use App\Helper\Api\AmazonApi;
use App\Helper\Api\ZulilyApi;
use Illuminate\Support\Carbon;
use DB;
use App\Models\PlatformObjectData;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Guzzle\Http\Exception\ClientErrorResponseException;
use Illuminate\Support\Facades\Cache;
use App\Helper\Api\CronHelper;
use App\Models\PlatformObject;
use App\Models\PlatformCustomer;
use App\Models\PlatformProductInventoryCredit;
use DateTime;
use DateTimeZone;
use function GuzzleHttp\json_decode;
use Illuminate\Support\Arr;
use App\Helper\Api\NetsuiteApi;

use App\Helper\FieldMappingHelper;
use App\Helper\ConnectionHelper;
use App\Helper\Cache\CacheDecoder;
use App\CountryCodes;
use App\Helper\Api\WayfairApi;
use App\Helper\WorkflowSnippet;
use App\Models\PlatformAccount;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use App\Http\Controllers\Brightpearl\BrightPearlApiController;
use App\Models\PlatformDataMapping;
use App\Models\PlatformField;
use App\Models\PlatformOrder;
use App\Models\PlatformProductInventory;
use Illuminate\Support\Facades\Config;


class TestController extends Controller
{
  public $az,$mobj,$wayfair,$log,$mapping,$WorkflowSnippet,$ConnectionHelper,$my_platform_id;//,$wfsnip;

  public function _contructor()
  {
    $this->mobj = new MainModel;
    $this->wayfair = new WayfairApi();
    // $this->wfsnip = new WorkflowSnippet();
  }

  public function test()
  { //11072023
    $mobj = new MainModel;
    $az = new AmazonApi;
    $zu = new ZulilyApi;
    $userId = $UserID = $uid = Auth::user()->id;
    $userIntegrationId = 380;
    $UserWorkFlow = 187;
    $WorkFlowID = 18;
    $bc = new \App\Http\Controllers\Brightpearl\BrightPearlApiController;

    $bsc = new \App\Http\Controllers\Brightpearl\BrightPearlApiSubController;
    $bsec = new \App\Http\Controllers\Brightpearl\BrightpearlSearchController;
    $wc = new \App\Http\Controllers\Woocommerce\WoocommerceApiController;
    $helper = new \App\Helper\ConnectionHelper;
    $map = new \App\Helper\Api\AhlsellApi;
    $wf = new \App\Http\Controllers\Wayfair\WayfairApiController;
    $ac = new \App\Http\Controllers\Amazon\AmazonApiController;
    $tpl = new \App\Http\Controllers\ThreePL\ThreePLApiController;
    $cs = new \App\Http\Controllers\CSCart\CSCartApiController;
    $bigc = new \App\Http\Controllers\Bigcommerce\BigcommerceController;
   // dd(app('App\Http\Controllers\Infoplus\InfoplusApiController')->GetInventory($userId, 465, 0));
  }

  //testdemo
  public function testdemo()
  {

    // $tableName = PlatformDataMapping::find(10690); // Assuming you have a record with ID 1
    // if ($tableName) {
    //     //579277 , 579278
    //     $tableName->update(['destination_row_id'=>579278]);
    //     return "Update triggered!";
    // } else {
    //     return "Record not found!";
    // }
    // dd('yes');


    /*$map = new \App\Helper\FieldMappingHelper();
    $helper = new \App\Helper\ConnectionHelper;
    $inventory_warehouse_object_id = $helper->getObjectId('inventory_warehouse');
    $MappedWarehouseArray = $map->getManyToOneWarehouseMapping($inventory_warehouse_object_id, 695);
    dd( $MappedWarehouseArray );*/
    $bc = new \App\Http\Controllers\Brightpearl\BrightPearlApiController;
    $bc->SyncInventoryBulk(97, 695, 'shipbob', 236, 1513, "Pending", 5637961);

    //$snow = new \App\Http\Controllers\Snowflake\SnowflakeApiController;
    //dd( $snow->GetOrders(97, 654, 'snowflake', 1245, 'PO') ); //PO:1245  TO:1314

    $james = new \App\Http\Controllers\JamesAndJames\JamesApiController;
    //dd( $james->createUpdateTransferOrders(97, 654, 'snowflake', 1245, 213, 561122) ); // pf_wf_rule= PO:208, TO:213
    //dd( $james->createUpdatePurchaseOrders(97, 654, 'snowflake', 1245, 208, 561123) );

     $mobj = new MainModel;
    $helper = new \App\Helper\ConnectionHelper;
    $userId = $user_id = $UserID = $uid = Auth::user()->id;

    $SorucePlatformName = "brightpearl";
    $sync_status = "Ready";
    $bc = new \App\Http\Controllers\Brightpearl\BrightPearlApiController;
    $bcs = new \App\Http\Controllers\Brightpearl\BrightpearlApiSubDivController;
    // $bsc = new \App\Http\Controllers\Brightpearl\BrightPearlApiSubController;
    // $bsec = new \App\Http\Controllers\Brightpearl\BrightpearlSearchController;
    $wc = new \App\Http\Controllers\Woocommerce\WoocommerceApiController;
    //dd($wc->GetSalesOrderBackup(97, 582, 3));
    $w = new \App\Helper\Api\WoocommerceApi;
    $b = new \App\Helper\Api\BrightpearlApi;
    $map = new \App\Helper\FieldMappingHelper;
    $i = new \App\Helper\Api\InfoplusApi;
    $info = new \App\Http\Controllers\Infoplus\InfoplusApiController;
    $wf = new \App\Http\Controllers\WorkflowController;

    $wayf = new \App\Http\Controllers\Wayfair\WayfairApiController;
    //dd($wayf->genrateShipmentLable());
    $ns = new \App\Http\Controllers\Netsuite\NetsuiteApiController;
    $bp = new BrightPearlApiController;
    $lobId=1;
    $orderSource = DB::table('platform_object_data')->where([
        'platform_object_data.user_id' => 1,
        'platform_object_data.user_integration_id' => 1,
        'platform_object_data.platform_id' => 36, //destination platform id
        'platform_object_data.platform_object_id' =>9,
        'platform_object_data.name' => "name",
    ])->join('platform_object_data_additional_information', function($join) use ($lobId)
    {
        $join->on('platform_object_data_additional_information.platform_object_data_id', '=', 'platform_object_data.id')->where('lob',$lobId);

    })->select('platform_object_data.id', 'platform_object_data.api_id', 'platform_object_data.name')->first();
    dd( $orderSource);
    $channelListMemo=[];
    $lobId=17948;
    $account=$mobj->getPlatformAccountByUserIntegration(465, 36);
    $order=PlatformOrder::with(['platformOrderAddress', 'getShipmentReadyAndFailed'])->select('id', 'user_id', 'platform_id', 'user_integration_id', 'user_workflow_rule_id',  'platform_customer_id', 'order_type', 'api_order_id', 'order_number', 'sync_status',  'is_voided',  'is_deleted', 'linked_id',  'order_updated_at', 'updated_at', 'warehouse_id', 'order_date', 'api_order_reference', 'allow_check', 'linked_api_order_id')->where('id',560045)->first();
   // dd(app('App\Http\Controllers\Infoplus\InfoplusServiceController')->findOrCreateOrderSource($channelListMemo,$order, $account, $lobId));
    // $account = $mobj->getPlatformAccountByUserIntegration(341, 6, ['app_id', 'app_secret', 'platform_id', 'id', 'user_id', 'api_domain']);

    // $product=app('App\Http\Controllers\Woocommerce\WoocommerceUtilityController')->searchProduct(30,$account,"aa");
    // dd($product['id']);
     //dd($mobj->encrypt_decrypt('Q1dhVEsvZy9DdktrSzJDMlFEWnFPdVB6d3lmeGFBV1VhcmZ0Q3AzckFwQUdrMUkxaW50TDN0b2w4YjNnenVONlNFcEpQRWgya2dUR3RJajMxZDh2enRYVjhXT29oZm1uQVJkMm9pcWZyNXc9','decrypt'));
    //dd($info->SyncProducts(109, 465, 159, 1021,  "brightpearl",  "Ready"));
    // dd($info->GetCommodityCode(109, 465,1, 1));
    // dd($bp->GetProduct(109,465,4,0));
   // dd(\App\Models\KernelUWFLimit::insertGetId(['url' => 0, 'type' => 'WORKFLOW', 'max_limit' => 222]));

    //dd($bp->GetCustomers(97, 429, 3, 0, 0));

  }


  public function encryptText(Request $request)
  {
    $mobj = new MainModel;
    $str = $request->text;
    return $mobj->encryptString($str);
  }

  public function decryptText(Request $request)
  {
    $mobj = new MainModel;
    $str = $request->text;
    return $mobj->decryptString($str);
  }

  public function BP_GoodsId()
  {


    $mobj = new MainModel;
    $baseurl = "https://ws-use.brightpearl.com/public-api/sandboxnrsshop/warehouse-service/order/*/goods-note/goods-out/2244412,2244560,2244591,2244614,2244677,2244926,2245198,2245234,2245235,2245251,2245318,2245389,2245399,2245421,2245451,2245454,2245457,2245473,2245474,2245476,2245477,2245478,2245479,2245480,2245481,2245482,2245483,2245484,2245490,2245492,2245495,2245499,2245501,2245504,2245505,2245506,2245507,2245509,2245510,2245512,2245589,2245591,2245592,2245593,2245594,2245595,2245596,2245597,2245598,2245599,2245601,2245602,2245603,2245604,2245606,2245607,2245609,2245610,2245611,2245612,2245613,2245614,2245615,2245616,2245617,2245823,2245824,2245825,2245826,2245827,2245851,2245852,2245853,2245854,2245857,2245858,2245859,2245860,2245864,2245866,2245913,2245915,2245916,2245917,2245918,2245919,2245920,2245921,2245927,2245928,2245929,2246017,2246075,2246076,2246128,2246129,2246173,2246174,2246176,2246177,2246178,2246179,2246180,2246183,2246185,2246187,2246188,2246259,2246261,2246262,2246263,2246267,2246269,2246271,2246272,2246273,2246275,2246277,2246326,2246366,2246375,2246376,2246558,2246560,2246562,2246563,2246564,2246565,2246567,2246569,2246620,2246663,2246666,2246667,2246669,2246670,2246671,2246673,2246674,2246695,2246754,2246767,2246772,2246776,2246778,2246779,2246823,2246913,2246993,2246994,2247083,2247192,2247196,2247386,2247422,2247424,2247425,2247483,2247490,2247567,2247568,2247586,2247754,2247756,2247799,2247960,2248086,2248087,2248089,2248091,2248092,2248093,2248096,2248097,2248099,2248101,2248104,2248105,2248110,2248111,2248114,2248410,2249197,2249205,2249207,2249209,2249568,2249699,2249717,2249740,2249741,2249786,2249913,2250107,2250140,2250207,2250228,2250230,2250234,2250236,2250237,2250238,2250239,2250240,2250241,2250242,2250243,2250244,2250245,2250247,2250248,2250249,2250251,2250252,2250253,2250255,2250256,2250260,2250262,2250263,2250265,2250390,2250393,2250394,2250415,2250417,2250432,2250495,2250511,2250534,2250592,2250593,2250599,2250600,2250769,2250772,2250773,2250774,2250775,2250776,2250777,2250819,2250820,2250821,2250822,2250846,2250847,2250848,2250849,2250850,2250853,2250855,2250856,2250881,2250882";
    $header = [
      "Content-Type" => "application/json",
      'brightpearl-app-ref' => 'nrsshop_2222',
      'brightpearl-account-token' => 'Z7HQkHLLabqcalCYep4+ONyl/Th0vWgs68yZCmdVPRw=',
    ];

    $server_response = $mobj->makeRequest("GET", $baseurl, [], $header, 'json');

    $res = json_decode($server_response->getBody(), true);

    if(isset($res['response'])){
      foreach($res['response'] as $key=>$value){

        // // Open the file to get existing content
        // $current = file_get_contents($file);
        // // Append a new person to the file
        // $current .= $value['orderId'];
        // // Write the contents back to the file
        $val=$value['orderId'].",".$value['createdOn'].",";
        file_put_contents('sheet_new.csv',$val.PHP_EOL , FILE_APPEND | LOCK_EX);
      }

    }
  }

  /**
     * user_id: 146 - user_integration_id: 610 - source_platform_id: brightpearl - platform_workflow_rule_id: 185 - user_workflow_rule_id: 1177 - record_id:  - destination_platform_id: wayfair
     */
    public function createShipmentLabel($user_id = 146, $user_integration_id = 610, $source_platform_name = 'brightpearl', $platform_workflow_rule_id = 185, $user_workflow_rule_id = 1177, $record_id = 0, $destination_platform_name = 'wayfair')
    {
        try {
            $source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);

            $object_id = $this->ConnectionHelper->getObjectId('purchase_order');
            $return = true;

            $estimatedShipDate = date( "Y-m-d h:s:i" );
            $ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->my_platform_id, ['refresh_token', 'access_token', 'env_type']);

            if ($ufound) {
                    $result_order = '';
                    $dryRun = 'true';
                    if ($ufound->env_type == 'production') { // checke account type .
                        $url = Config::get('apiconfig.WayfairAudience');
                    } else {
                        $url = Config::get('apiconfig.WayfairUrlSandbox');
                    }

                    if ($ufound->env_type == 'production') { // set  dryRun
                        $dryRun =  'false';
                    }

                    $limit = 1;
                    if ($record_id) {
                        $result_order = $this->mobj->getResultByConditions('platform_order', ['id' => $record_id], ['id', 'linked_id', 'order_number']);
                    } else {
                        $result_order = $this->mobj->getResultByConditions('platform_order', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'sync_status' => 'Ready'], ['id', 'linked_id', 'order_number'], ['id' => 'asc'], $limit);
                        if (!count($result_order)) {
                            $result_order = $this->mobj->getResultByConditions('platform_order', ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'sync_status' => 'Failed'], ['id', 'linked_id', 'order_number'], ['id' => 'asc'], $limit);
                        }
                    }

                    foreach ($result_order as $row) {
                        $variables = [];
                        $parent_order = $this->mobj->getFirstResultByConditions('platform_order', ['id' => $row->linked_id], ['id', 'order_number', 'ship_speed', 'order_date']);

                        if ($parent_order) {
                            $purchase_order_object_id = $this->ConnectionHelper->getObjectId('estimate_ship_in_days');
                            $warehouse_mapp = $this->mapping->getMappedWarehouse($user_integration_id, $platform_workflow_rule_id, $purchase_order_object_id, ['custom_data']);
                            $lineItem = [];
                            if ($warehouse_mapp && $parent_order->order_date) {
                                $estimatedShipDate = date(DATE_ISO8601, strtotime('+' . $warehouse_mapp->custom_data . ' days ', strtotime($parent_order->order_date)));
                            }

                            $OrderWarehouseId = null;
                            $defaultSelectedWarehouse = $this->mapping->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "order_warehouse", ['api_id']);

                            if (isset($defaultSelectedWarehouse->api_id)) {
                                $OrderWarehouseId = $defaultSelectedWarehouse->api_id;
                            }
                            $param_data = [
                                'poNumber' => $parent_order->order_number,
                                'warehouseId' => $OrderWarehouseId,
                                'requestForPickupDate' => $estimatedShipDate
                            ];

                            $params = '$params';
                            $curl_post_data = [
                                "query" => "mutation register($params: RegistrationInput!) {
                                    purchaseOrders {
                                        register(registrationInput: $params) {
                                            eventDate,
                                            pickupDate,
                                            consolidatedShippingLabel {
                                                url,
                                            },
                                            shippingLabelInfo {
                                                carrier,
                                                carrierCode,
                                                trackingNumber,
                                            },
                                            purchaseOrder {
                                                poNumber,
                                                shippingInfo {
                                                    carrierCode
                                                }
                                            }
                                        }
                                    }
                                } ", "variables" => [
                                    "params" => $param_data
                                ]
                            ];

                            $request_data_json = json_encode($curl_post_data);
                            $response = $this->wayfair->createShipmentLabel($ufound->access_token, $url, $request_data_json, $source_platform_name, $destination_platform_name);
                            $order_data = json_decode($response, true);
                            if( isset( $_GET['istest'] ) && $_GET['istest'] == 1 ){
                                dd( $parent_order, $request_data_json, $order_data );
                            }

                            if (isset($order_data['errors']) && count($order_data['errors'])) {
                                $return = $order_data['errors'][0]['message'];
                                $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $row->id]);
                                $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'failed', $row->id, $return);
                            } else {
                                if (isset($order_data['data']['purchaseOrders']['register'])) {
                                    $lableCreationResponse = $order_data['data']['purchaseOrders']['register'];
                                    $shipment = PlatformOrderShipment::where(['platform_id' => $this->my_platform_id, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $row->id])->first();
                                    if($shipment){ //udapte shipping label info after create shipment label
                                        $shipment->tracking_url = $lableCreationResponse['consolidatedShippingLabel']['url'];
                                        $shipment->carrier_code = $lableCreationResponse['shippingLabelInfo'][0]['carrierCode'];
                                        $shipment->tracking_info = $lableCreationResponse['shippingLabelInfo'][0]['trackingNumber'];
                                        $shipment->is_shipped = 1;
                                        $shipment->save();
                                    }
                                    $nmsg = 'Label Created successfully!';
                                    $this->mobj->makeUpdate('platform_order', ['sync_status' => 'Synced'], ['id' => $row->id]);
                                    $this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->my_platform_id, $object_id, 'success', $row->id, $nmsg);
                                }
                            }
                        }
                    }
            }
        } catch (\Exception $e) {
            $return = $e->getMessage();
        }
        return $return;
    }

    /**
     * S:\Server\www\laravel\esb\integration\app\Helper\WorkflowSnippet.php
     *
     * @return void
     */
    public function getWorkflowEvents(){
        $getflowEvents = app('App\Helper\WorkflowSnippet')->getWorkflowEvents(1243);//$this->WorkflowSnippet->getWorkflowEvents(203);
        dd($getflowEvents);
    }


    
}
