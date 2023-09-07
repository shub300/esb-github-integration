<?php

namespace App\Http\Controllers\Snowflake;

use App\Http\Controllers\Snowflake\Api\SnowflakeApi;
use DB;

class SnowflakeService extends SnowflakeApi
{

    public static $myPlatform = 'snowflake';
    public $snowflakeApi;


    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->platformId = $this->connectionHelper->getPlatformIdByName(self::$myPlatform);
    }
    /* Find Price List By Product ID */
    public function findPriceList($productID,$default_product_currency,$default_product_pricelist)
    {
       
     $price =0;
     if(isset($default_product_currency) && isset($default_product_pricelist )){
     $priceListArray = DB::table('platform_porduct_price_list as pp')
     ->join('platform_object_data as data', 'pp.platform_object_data_id', '=', 'data.id')
     ->where('pp.platform_product_id', $productID)
     ->select('pp.platform_product_id', 'pp.price', 'pp.api_currency_code', 'pp.platform_object_data_id')->get();
     if (!empty($priceListArray)) {
        foreach ($priceListArray as $key => $value) {
            $priceName = $this->fieldMapHelper->getObjectDataByID($value->platform_object_data_id, ['api_id']);
              if (isset($priceName->api_id) && $default_product_currency==$value->api_currency_code) {
                
                    if ($default_product_pricelist == $priceName->api_id) {
                        $price = (string) $value->price;
                        break;
                    }
                  
                }
            }
        }
    }

        return ['price' => $price];
    }
    /* Get Warehouse and Update */
    public function GetOrderWarehouse($orderWh, $user_id, $user_integration_id, $warehouse_object_id)
    {
        $return = null;
        if ($orderWh) {

            $ord_warehouse = $this->mainModel->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $warehouse_object_id, 'api_id' => $orderWh], ['id']);
            if ($ord_warehouse) {
                $order_warehouse_id = $ord_warehouse->id;
            } else {

                $order_warehouse_id = $this->mainModel->makeInsertGetId('platform_object_data', [
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'api_id' => $orderWh,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $warehouse_object_id,

                ]);
            }
            $return = $order_warehouse_id;
        }
        return $return;
    }
    
    public function productIdentityMapping($userIntegrationId, $PlatformWorkFlowRuelID)
    {
        $product_identity_obj_id = $this->connectionHelper->getObjectId('product_identity');
        $maping_data =  $this->fieldMapHelper->getMappedField( $userIntegrationId, $PlatformWorkFlowRuelID, $product_identity_obj_id );

        $source_row_data = $destination_row_data = 'sku';
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
}
