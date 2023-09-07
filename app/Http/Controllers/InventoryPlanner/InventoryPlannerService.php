<?php

namespace App\Http\Controllers\InventoryPlanner;

use App\Exports\InventoryPlannerProductXLS;
use App\Http\Controllers\InventoryPlanner\Api\InventoryPlannerApi;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformProduct;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\File;

class InventoryPlannerService extends InventoryPlannerApi
{

    public static $myPlatform = 'inventoryplanner';
    public $InventoryPlannerApi;
    public $productFileExtension = '.csv';
    public $orderFileExtension = '.csv';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
    }

    /**
     *
     */
    public function getAccountDetails( $user_integration_id ){
        return $this->MainModel->getPlatformAccountByUserIntegration( $user_integration_id, $this->platformId, [
            'id',
            'account_name',
            'app_id',
            'app_secret',
            'api_domain',
            'access_token',
        ] );
    }

    /* 
     * Find Price List By Product ID 
     */
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
                    $priceName = $this->FieldMappingHelper->getObjectDataByID($value->platform_object_data_id, ['api_id']);
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

    /*
     * Get Warehouse and Update 
     */
    public function GetOrderWarehouse( $orderWh, $user_id, $user_integration_id, $warehouse_object_id )
    {
        $order_warehouse_id = null;
        if ($orderWh) {
            $ord_warehouse = $this->MainModel->getFirstResultByConditions('platform_object_data', [
                'user_integration_id' => $user_integration_id, 
                'platform_id' => $this->platformId, 
                'platform_object_id' => $warehouse_object_id, 
                'api_id' => $orderWh
            ], ['id']);

            if ($ord_warehouse) {
                $order_warehouse_id = $ord_warehouse->id;
            } else {
                $order_warehouse_id = $this->MainModel->makeInsertGetId('platform_object_data', [
                    'user_id' => $user_id,
                    'platform_id' => $this->platformId,
                    'api_id' => $orderWh,
                    'user_integration_id' => $user_integration_id,
                    'platform_object_id' => $warehouse_object_id,

                ]);
            }
        }

        return $order_warehouse_id;
    }
    
    /**
     * 
     */
    public function productIdentityMapping($userIntegrationId, $PlatformWorkFlowRuelID)
    {
        $product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');
        $maping_data =  $this->FieldMappingHelper->getMappedField($userIntegrationId, $PlatformWorkFlowRuelID, $product_identity_obj_id);

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
        return [
            'source_identity' => $source_row_data, 
            'destination_identity' => $destination_row_data
        ];
    }

    /**
     * Generate and append product data into file
     */
    public function createProductRowData( $product, $object_id, $user_id, $user_integration_id, $product_identifier, $source_platform_id, $source_platform_name, $default_product_pricelist, $default_product_currency, $user_workflow_rule_id, $qty=0, $filePath='', $product_id_prefix='' ){

        $productLinkingNew = PlatformProduct::find( $product->id );
        $productLinking = $productLinkingNew->replicate();
        $destinationColumn = $product_identifier['destination_identity'];
        $sourceColumn = $product_identifier['source_identity'];

        $productImage = $productPrice = $productSku  = $productBarcode = $productVariant = $productBrand = $productCategory = $productSubCategory = null;
        $productRegularPrice = 0;

        $product_primary_id = $product->id;
        $publish = 'false';
        $remove = 'false';
        if ($product->product_status) {
            $publish = 'true';
        }

        $inventoryManagement = 'true';

        if ( isset( Config::get( 'apisettings.AllowSKUInSnowflake')[$source_platform_name] ) ) {
            $productVariant = $productSku = $product->api_product_id;
            $productBarcode = $product->upc;
        } else {
            $productSku = $product->sku;
            $productBarcode = $product->barcode;
            $productVariant = $product->api_variant_id;
        }

        if (isset($product->images)) {
            $imageArr = explode(",", $product->images);
            if (COUNT($imageArr) > 0) {
                $productImage = $imageArr[0];
            }
        }

        $productPrice = $product->price ?? 0;
        if ( isset( $default_product_currency->api_code ) && isset( $default_product_pricelist->api_id ) ) {
            if ( $source_platform_name == "netsuite" ) {
                $price = $this->findPriceList( $product_primary_id, $default_product_currency->api_code, $default_product_pricelist->api_id );
                if ( isset( $price['price'] ) ) {
                    $productPrice = $price['price'] ?? 0;
                }
            }
        }
        $productCategory = $product->category_id;

        $productBrand = htmlspecialchars(str_replace("'", "\'", $product->brand_id), ENT_QUOTES);

        $field_mapping = $this->FieldMappingHelper->GetMappedFieldRecord( $object_id, $user_integration_id, NULL, "source_row_id", NULL, $product_primary_id); //product field mappings | custom fields
        if ($field_mapping) {
            foreach ($field_mapping as $mapping) {
                if ($mapping['destination_field_name'] == "IMAGE") {
                    $productImage = $mapping['source_custom_field_value'];
                }
                
                if ($mapping['destination_field_name'] == "BRAND") {
                    $productBrand = $mapping['source_custom_field_value'];
                }

                if ($mapping['destination_field_name'] == "PRICE") {
                    $productPrice = $mapping['source_custom_field_value'];
                }

                if ($mapping['destination_field_name'] == "REGULAR_PRICE") {
                    $productRegularPrice = $mapping['source_custom_field_value'];
                }

                if ($mapping['destination_field_name'] == "TAGS") {
                    $productSubCategory = $mapping['source_custom_field_value'];
                }

                if ($mapping['destination_field_name'] == "CATEGORY") {
                    $productCategory = $mapping['source_custom_field_value'];
                }
            }
        }
        
        $updatedAt = date( 'Y-m-d h:i:s' );
        {
            // Accept Format
            /**
             * A => product_id,
             * B => title,
             * C => SKU,
             * D => regular_price,
             * E => price,
             * F => stock_quantity,
             * G => created_at,
             * H => updated_at,
             * I => managing_stock,
             * J => vendor,
             * K => vendor_product_name,
             * L => visible,
             * M => categories,
             * N => image,
             * O => barcode,
             * P => brand,
             * Q => options,
             * R => tags,
             * S => removed,
             */

            $data = [];
            $data[] = str_replace( "'", "\'", $productVariant );//A
            $data[] = str_replace( "'", "\'", $product->product_name );//B
            $data[] = str_replace("'", "\'", $productSku);//C
            $data[] = (float)$productRegularPrice;//D
            $data[] = (float)$productPrice;//E
            $data[] = $qty;//stock_quantity//F
            $data[] = $this->dateFormat($source_platform_name, $product->created_at);//G
            $data[] = $updatedAt;//H
            $data[] = $inventoryManagement;//I
            $data[] = '';//vendor//J
            $data[] = str_replace("'", "\'", $product->product_name);//K
            $data[] = $publish;//L
            $data[] = $productCategory;//M
            $data[] = $productImage;//N
            $data[] = str_replace("'", "\'", $productBarcode);//O
            $data[] = $productBrand;//P
            $data[] = '';//Q
            $data[] = $productSubCategory;//R
            $data[] = $remove;//S

            
            /**
             * Generate product linking
             */
            $productLinkingNew = PlatformProduct::find($product->id);
            $productLinking = $productLinkingNew->replicate();

            if ( $source_platform_name != "veracore" && isset( $productLinking->$destinationColumn ) ) {
                $productAtt = $productLinkingNew->toArray();
                if ( isset( $productAtt[$product_identifier['source_identity']] ) ) {
                    $productLinking->$destinationColumn = $productAtt[$sourceColumn];
                }
            }

            $productLinking->platform_id = $this->platformId;
            $productLinking->api_product_id = $product_id_prefix.$productLinkingNew->api_product_id;
            $productLinking->linked_id = $product->id;
            $productLinking->created_at = Carbon::now();
            $productLinking->updated_at = Carbon::now();
            $productLinking->product_sync_status = PlatformStatus::SYNCED;
            $productLinking->inventory_sync_status = PlatformStatus::PENDING;
            $productLinking->save();

            $product->linked_id = $productLinking->id; //Update the prodct linking id
            $product->product_sync_status = PlatformStatus::SYNCED; //Update the product_sync_status
            $product->save();
            // $excelSetData[] = $data;

            if( $data ){
                // Check if the CSV file exists, create if not
                if (!File::exists( $filePath ) ) {
                    File::put( $filePath, "product_id,title,SKU,regular_price,price,stock_quantity,created_at,updated_at,managing_stock,vendor,vendor_product_name,visible,categories,image,barcode,brand,options,tags,removed\n");
                }
                
                File::append( $filePath, implode(',', $data ) . "\n");
            }
            
            $this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $product->id, null);
        }
    }

    /* 
     * Date Conversion 
     */
    public function dateFormat($platformName, $updateDate)
    {
        if ($platformName == "netsuite") {
            $updateDate = Carbon::parse( $updateDate )->format('Y-m-d H:i:s');//if change format as Y-m-d by knowing your platform because snowflake acccept only Y-m-d H:i:s format
        }
        return $updateDate;
    }

    /**
     * default Order WareHouse Select
     */
    public function getDefaultOrderWarehouse( $user_integration_id ){

        $result['selectWarehouseId'] = null;
        $result['selectWarehouseName'] = null;
        $result['selectWarehouseCode'] = null;
        $wareHouseNameArr = $this->FieldMappingHelper->getMappedDataByName( $user_integration_id, null, "order_warehouse", ['api_id', 'api_code', 'name'] );
        
        if( $wareHouseNameArr ){
            $result['selectWarehouseId'] = $wareHouseNameArr->api_id;
            $result['selectWarehouseName'] = $wareHouseNameArr->name;
            $result['selectWarehouseCode'] = $wareHouseNameArr->api_code;
        }

        return $result;
    }
}
