<?php

namespace App\Http\Controllers\Woocommerce;

use App\Http\Controllers\Controller;
use App\Helper\Api\WoocommerceApi;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\Logger;
use App\Helper\MainModel;
use App\Models\PlatformProduct;
use DB;

class WoocommerceUtilityController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public $mobj, $wc, $helper, $map, $platformId, $log;
    public static $myPlatform = 'woocommerce';
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->wc = new WoocommerceApi();
        $this->map = new FieldMappingHelper();
        $this->log = new Logger();
        $this->helper = new ConnectionHelper;
        $this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
    }
    /* Get Store Product */
    public function StoreProduct($sku, $account, $userId, $userIntegrationId)
    {
        $url = "/wp-json/wc/v3/products?sku={$sku}";
        $response = $this->wc->GetProducts($account, $url);
        if( isset($response['status_code']) && ($response['status_code']==200 || $response['status_code']==201) ){
            $product = $response['body'];
            if (!empty($product) && is_array($product) ) {
                foreach ($product as $key => $value) {
                    if (!isset($value['error'])) {
                        //Set parent product id
                        $ProductPrimaryID = app('App\Http\Controllers\Woocommerce\WoocommerceApiController')->PrepareModalData($value, $userId, $userIntegrationId, $this->platformId);

                        if (!empty($value['variations']) && isset($value['variations'])) {
                            //if we've parent product
                            //PlatformProduct::where('parent_product_id', $ProductPrimaryID)->update(['is_deleted' => 1]);

                            /* If we have variants */
                            foreach ($value['variations'] as $variants) {
                                $url = "/wp-json/wc/v3/products/{$variants}?page=1&per_page=1";
                                $response = $this->wc->GetProducts($account, $url);
                                if( isset($response['status_code']) && ($response['status_code']==200 || $response['status_code']==201) ){
                                    $productV = $response['body'];
                                    if (!empty($productV) || is_array($productV) ) {
                                        if (!isset($productV['error'])) {
                                            //Set parent product id
                                            $productV['parent_product_id'] = $ProductPrimaryID;
                                            app('App\Http\Controllers\Woocommerce\WoocommerceApiController')->PrepareModalData($productV, $userId, $userIntegrationId, $this->platformId, true);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                return true;
            } else {
                return false;
            }
         }
    }
    /* find dulplicate product name to set varibale product type in woo */
    public function FindDuplicateProductName($productName, $apiProductId, $userId, $userIntegrationId, $SourcePlatformId, $DestinationPlatformId)
    {

        $count = PlatformProduct::where([
            ['platform_id', '=', $SourcePlatformId],
            ['user_integration_id', '=', $userIntegrationId],
            ['product_name', '=', $productName],
            ['api_product_id', '!=', $apiProductId],
        ])->count();
        $get = DB::table('platform_product as s')
            ->join('platform_product as at', 'at.product_name', '=', 's.product_name')->where([
                ['s.user_id', '=', $userId],
                ['s.platform_id', '=', $SourcePlatformId],
                ['s.user_integration_id', '=', $userIntegrationId],
                ['s.product_name', '=', $productName],
                ['s.is_deleted', '=', 0],
                ['at.user_id', '=', $userId],
                ['at.platform_id', '=', $DestinationPlatformId],
                ['at.user_integration_id', '=', $userIntegrationId],
                ['at.is_deleted', '=', 0],
            ])->where(function ($query) {

                $query->where('at.parent_product_id', '=', 0)
                    ->orWhereNull('at.parent_product_id');
            })->select('at.id', 'at.api_product_id', 'at.product_name',  'at.parent_product_id')->first();
        return ['count' => $count, 'woo_product' => $get];
    }
    /* Validate date */
    public function isOldDate($orderDate)
    {
        $olddate = date(DATE_ISO8601, strtotime('- 12 day'));
        $order_created_date = date(DATE_ISO8601, strtotime($orderDate));
        if ($olddate < $order_created_date) {
            return true;
        }
        return false;
    }
    /* Check Sync Start Date And Order date */
    public function isValidOrder($order_sync_start_date, $date_created)
    {
        if (isset($order_sync_start_date) && !empty($order_sync_start_date)) {
            $FromDate =  date(DATE_ISO8601, strtotime($order_sync_start_date));
            $ToDate = date(DATE_ISO8601, strtotime($date_created));
            if ($FromDate < $ToDate) {
                $byPass = true;
            } else {
                $byPass = false;
            }
        } else {
            $byPass = true;
        }
        return $byPass;
    }
    /* Update Normal Product Inventory */
    public function UpdateNormalProductInventory($userId, $userIntegrationId, $PlatformWorkFlowID, $UserWorkFlowID, $SourcePlatformId, $UpdateInventoryData = [], $Products = [], $type = "NORMAL", $account = NULL)
    {
        if (!empty($UpdateInventoryData)) {
            if ($type == "NORMAL") { //If no multi warehouse
                $postData = [
                    'create' => [],
                    'update' =>
                    $UpdateInventoryData,
                    'delete' => []
                ];
                $response = app('App\Http\Controllers\Woocommerce\WoocommerceApiController')->ProductBulkUpdate($userIntegrationId, $postData);
                \Log::channel('webhook')->info("wooawadhes -Response: " . print_r($response,true) . " Created Date : " . date('Y-m-d H:i:s'));
                if (isset($response['update']) && !empty($response['update'])) {
                    $object_id = $this->helper->getObjectId('inventory');
                    foreach ($response['update'] as $key => $value) {
                        if (!isset($value['error'])) {
                            if (isset($Products[$value['id']])) {
                                $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => "Synced"], ['id' => $Products[$value['id']]]);
                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowID,  $SourcePlatformId, $this->platformId, $object_id, 'success', $Products[$value['id']], NULL);
                            }
                        } else if (isset($value['error']) && isset($value['error']['message'])) {
                            $ifErrorVariation=false;
                            if($value['error']['message']=="Invalid ID."){
                                $error =  "Product does not exist";
                            }else{
                                $error =  $value['error']['message'];

                                $needle   = 'to manipulate';//if variantions related error found
                                if (strpos(strtolower($error), strtolower($needle)) !== false) {
                                   $ifErrorVariation=true;
                                }

                            }
                            if($ifErrorVariation){
                                //if variantions related error found call woo product and set source product inventory_sync_status=ready

                                if($product=$this->searchProduct($value['id'],$account,null)){

                                    app('App\Http\Controllers\Woocommerce\WoocommerceApiController')->PrepareModalData($product, $userId, $userIntegrationId, $this->platformId);

                                    $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => "Ready"], ['id' => $Products[$value['id']]]);
                                }

                            }else{
                                $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => "Failed"], ['id' => $Products[$value['id']]]);
                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $Products[$value['id']], $error);
                            }

                        }
                    }
                }
            } else if ($type == "MULTIWAREHOUSE") {
                if (count($UpdateInventoryData) > 0) {
                    $object_id = $this->helper->getObjectId('inventory');

                    foreach ($UpdateInventoryData as $key => $Inventory) {
                        $url = "products/{$key}";

                        $response = $this->wc->CreateOrUpdateOrDeleteProduct($account, $url, $Inventory, 'update');
                        $update = json_decode($response->getBody(), true);

                        if (isset($update['id'])) {

                            if (isset($Products[$update['id']])) {
                                $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => "Synced"], ['id' => $Products[$update['id']]]);
                                $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowID,  $SourcePlatformId, $this->platformId, $object_id, 'success', $Products[$update['id']], NULL);
                            }
                        }
                    }
                }
            }
        }
    }
    /* Update Variant Product Inventory */
    public function UpdateVariantProductInventory($userId, $userIntegrationId, $PlatformWorkFlowID, $UserWorkFlowID, $SourcePlatformId, $UpdateInventoryData = [], $Products = [], $type = "NORMAL", $account = NULL)
    {
        if (!empty($UpdateInventoryData)) {
            if ($type == "NORMAL") { //If no multiwarehouse
                foreach ($UpdateInventoryData as $key => $val) {
                    $postData = [
                        'create' => [],
                        'update' => $val,
                        'delete' => []
                    ];
                    $url = "/wp-json/wc/v3/products/{$key}/variations/batch";
                    $response =  app('App\Http\Controllers\Woocommerce\WoocommerceApiController')->ProductVariantBulkUpdate($userIntegrationId, $url, $postData);
                    if (isset($response['update']) && !empty($response['update'])) {
                        $object_id = $this->helper->getObjectId('inventory');
                        foreach ($response['update'] as $key => $value) {
                            if (!isset($value['error'])) {
                                if (isset($Products[$value['id']])) {
                                    $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => "Synced"], ['id' => $Products[$value['id']]]);
                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowID,  $SourcePlatformId, $this->platformId, $object_id, 'success', $Products[$value['id']], NULL);
                                }
                            } else if (isset($value['error']) && isset($value['error']['message'])) {
                                $ifErrorVariation=false;
                                if($value['error']['message']=="Invalid ID."){
                                    $error =  "Product does not exist";
                                }else{
                                    $error =  $value['error']['message'];
                                    $needle   = 'to manipulate';//if variantions related error found
                                    if (strpos(strtolower($error), strtolower($needle)) !== false) {
                                       $ifErrorVariation=true;
                                    }

                                }
                                if($ifErrorVariation){
                                    //if variantions related error found call woo product and set source product inventory_sync_status=ready

                                    if($product=$this->searchProduct($value['id'],$account,null)){
                                        app('App\Http\Controllers\Woocommerce\WoocommerceApiController')->PrepareModalData($product, $userId, $userIntegrationId, $this->platformId);
                                        $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => "Ready"], ['id' => $Products[$value['id']]]);
                                    }

                                }else{
                                    $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => "Failed"], ['id' => $Products[$value['id']]]);
                                    $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowID,  $SourcePlatformId, $this->platformId, $object_id, 'failed', $Products[$value['id']], $error);
                                }

                            }
                        }
                    }
                }
            } else if ($type == "MULTIWAREHOUSE") {
                if (count($UpdateInventoryData) > 0) {
                    // dd($UpdateInventoryData );
                    $object_id = $this->helper->getObjectId('inventory');
                    foreach ($UpdateInventoryData as $key => $Inventory) {
                        if (isset($Products[$key])) {

                            $first_key = array_key_first($Products[$key]);
                            $first_value = isset($Products[$key][$first_key]) ? $Products[$key][$first_key] : NULL;
                            if ($first_key && $first_key) {
                                $url = "products/{$first_key}/variations/{$key}";
                                $response = $this->wc->CreateOrUpdateOrDeleteProduct($account, $url, $Inventory, 'update');
                                $update = json_decode($response->getBody(), true);

                                if (isset($update['id'])) {
                                    if (isset($Products[$update['id']])) {
                                        $this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => "Synced"], ['id' => $first_value]);
                                        $this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowID,  $SourcePlatformId, $this->platformId, $object_id, 'success', $first_value, NULL);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    /* Prepare Inventory Data using Modal */
    public function PrepareInventoryData($userId, $userIntegrationId, $PlatformWorkFlowID, $PluginType, $Inventory_arr)
    {
        $update_inventory_data = $productsIndex = $VariantProductsIndex = $VariantProducts = [];
        if ($PluginType == "METADATA") {
            //If multi warehouse
            foreach ($Inventory_arr as $Inventory) {
                //Update Inventory
                $this->mobj->makeUpdate('platform_product', ['updated_at' => date('Y-m-d H:i:s')], ['id' => $Inventory->id]);
                $product_inventory_arr = $this->mobj->getResultByConditions('platform_product_inventory', ['user_integration_id' => $userIntegrationId, 'platform_product_id' => $Inventory->id], ['id', 'api_warehouse_id', 'quantity', 'platform_product_id']);

                if (count($product_inventory_arr) > 0) {
                    if ($PluginType == "METADATA") {
                        foreach ($product_inventory_arr as $product_inventory) {
                            $warehouseResponse = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowID, "inventory_warehouse", ['api_id'], "regular", $product_inventory->api_warehouse_id);

                            $warehouseId = isset($warehouseResponse->api_id) ? $warehouseResponse->api_id : NULL;
                            if ($warehouseId) { //If warehouse mapping of BP and woo
                                // if (isset($VariantProducts[$Inventory->woo_api_product_id])) {
                                //dd($Inventory);
                                if ($Inventory->parent_product_id) {
                                    $find = PlatformProduct::select('api_product_id')->where('id', $Inventory->parent_product_id)->where('is_deleted', 0)->first();
                                    // dd($find->api_product_id);
                                    if ($find) {
                                        $VariantProducts[$Inventory->woo_api_product_id][$find->api_product_id] = $Inventory->id;
                                        if (isset($VariantProductsIndex[$Inventory->woo_api_product_id])) {


                                            $VariantProductsIndex[$Inventory->woo_api_product_id]['meta_data'][] = [

                                                "key" => "_stocks_location_" . $warehouseId,

                                                "value" => $product_inventory->quantity > 0 ? $product_inventory->quantity : "o"
                                            ];
                                        } else {

                                            $VariantProductsIndex[$Inventory->woo_api_product_id]['meta_data'][] = [

                                                "key" => "_stocks_location_" . $warehouseId,

                                                "value" => $product_inventory->quantity > 0 ? $product_inventory->quantity : "o"
                                            ];
                                        }
                                    }
                                } else {
                                    $productsIndex[$Inventory->woo_api_product_id] = $Inventory->id;
                                    $update_inventory_data[$Inventory->woo_api_product_id]['meta_data'][] = [

                                        "key" => "_stocks_location_" . $warehouseId,

                                        "value" => $product_inventory->quantity > 0 ? $product_inventory->quantity : "o"
                                    ];
                                }
                                // }
                                // else{
                                //     $VariantProducts[$Inventory->woo_api_product_id] = $Inventory->id;
                                //     if ($Inventory->parent_product_id) {
                                //         $find = PlatformProduct::select('api_product_id')->where('id', $Inventory->parent_product_id)->where('is_deleted',0)->first();
                                //         if ($find) {
                                //             $VariantProducts[$Inventory->woo_api_product_id] = $Inventory->id;
                                //             if (isset($VariantProductsIndex[$find->api_product_id])) {


                                //                 $VariantProductsIndex[$find->api_product_id]['meta_data'][]=[

                                //                     "key"=> "_stocks_location_".$warehouseId,

                                //                     "value"=> $product_inventory->quantity
                                //                 ];
                                //             } else {

                                //                 $VariantProductsIndex[$Inventory->woo_api_product_id]['meta_data'][]=[

                                //                     "key"=> "_stocks_location_".$warehouseId,

                                //                     "value"=> $product_inventory->quantity
                                //                 ];
                                //             }
                                //         }
                                //     } else {
                                //         $update_inventory_data[$Inventory->woo_api_product_id]['meta_data'][]=[

                                //             "key"=> "_stocks_location_".$warehouseId,

                                //             "value"=> $product_inventory->quantity
                                //         ];

                                //     }
                                // }


                            }
                        }
                    }
                }
            }
        } else {
            //If no multi warehouse
            $warehouseArray = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowID, "inventory_warehouse", ['api_id'], "regular", NULL, "multi", "source");
            if (is_array($warehouseArray) && !empty($warehouseArray)) {
                foreach ($Inventory_arr as $Inventory) {

                    $productsIndex[$Inventory->woo_api_product_id] = $Inventory->id;
                    $product_inventory_arr = $this->mobj->getResultByConditions('platform_product_inventory', ['user_integration_id' => $userIntegrationId, 'platform_product_id' => $Inventory->id], ['id', 'api_warehouse_id', 'quantity']);

                    if (count($product_inventory_arr) > 0) {

                        $sum = 0;
                        foreach ($product_inventory_arr as $product_inventory) {
                            if (in_array($product_inventory->api_warehouse_id, $warehouseArray)) {
                                $sum += $product_inventory->quantity;
                            }
                        }
                        /* Add total sum  as stock for Woo */
                        if ($Inventory->parent_product_id) {
                            $find = PlatformProduct::select('api_product_id')->where('id', $Inventory->parent_product_id)->first();
                            if ($find) {
                                $VariantProducts[$Inventory->woo_api_product_id] = $Inventory->id;
                                if (isset($VariantProductsIndex[$find->api_product_id])) {

                                    $VariantProductsIndex[$find->api_product_id][] = [
                                        'id' => $Inventory->woo_api_product_id,
                                        'stock_quantity' => $sum
                                    ];
                                } else {
                                    $VariantProductsIndex[$find->api_product_id][] = [
                                        'id' => $Inventory->woo_api_product_id,
                                        'stock_quantity' => $sum
                                    ];
                                }
                            }
                        } else {
                            array_push($update_inventory_data, [
                                'id' => $Inventory->woo_api_product_id,
                                'stock_quantity' => $sum
                            ]);
                        }
                    } else {
                        if ($Inventory->parent_product_id) {
                            $find = PlatformProduct::select('api_product_id')->where('id', $Inventory->parent_product_id)->first();
                            if ($find) {
                                $VariantProducts[$Inventory->woo_api_product_id] = $Inventory->id;
                                if (isset($VariantProductsIndex[$find->api_product_id])) {
                                    $VariantProductsIndex[$find->api_product_id][] = [
                                        'id' => $Inventory->woo_api_product_id,
                                        'stock_quantity' => 0
                                    ];
                                } else {
                                    $VariantProductsIndex[$find->api_product_id][] = [
                                        'id' => $Inventory->woo_api_product_id,
                                        'stock_quantity' => 0
                                    ];
                                }
                            }
                        } else {
                            array_push($update_inventory_data, [
                                'id' => $Inventory->woo_api_product_id,
                                'stock_quantity' => 0
                            ]);
                        }
                    }
                }
            }
        }
        return ['update_inventory_data' => $update_inventory_data, 'update_variant_inventory_data' => $VariantProductsIndex, 'normal_product' => $productsIndex, 'variant_product' => $VariantProducts];
    }
    public function ProductIdentityMapping($userIntegrationId, $PlatformWorkFlowRuelID)
    {
        $product_identity_obj_id = $this->helper->getObjectId('product_identity');
        $maping_data =  $this->map->getMappedField($userIntegrationId, $PlatformWorkFlowRuelID, $product_identity_obj_id);
        $source_row_data = $destination_row_data = '';
        if ($maping_data) {

            if ($maping_data['destination_platform_id'] == self::$myPlatform) {
                $destination_row_data = $maping_data['destination_row_data'];
                $source_row_data = $maping_data['source_row_data'];
            } else {
                $destination_row_data = $maping_data['source_row_data'];
                $source_row_data = $maping_data['destination_row_data'];
            }
        }
        return ['source_identity' => $source_row_data, 'destination_identity' => $destination_row_data];
    }
    /* Search Product by SKU and Product Id */
    public function searchProduct($product,$account,$type){
        if($type=="sku"){
            $url="sku={$product}";
            $response = $this->wc->searchProductBySKU($account, $url);
            if ($product = json_decode($response->getBody(), true)) {
                return $product;
            }

        }else{
            $response = $this->wc->searchProductByID($account, $product);
            if ($product = json_decode($response->getBody(), true)) {
                return $product;
            }
        }
        return false;

    }
}
