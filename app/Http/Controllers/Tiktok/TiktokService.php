<?php
namespace App\Http\Controllers\Tiktok;

use App\Helper\Logger;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tiktok\Api\TiktokApi;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformObjectData;
use App\Models\PlatformProduct;
use App\Models\PlatformProductDetailAttribute;
use App\Models\PlatformProductInventory;
use App\Models\PlatformProductPriceList;
use DateTime;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TiktokService extends Controller
{
    public $Logger;
    public $MainModel;
    public $platformId;
    public $ConnectionHelper;
    public $FieldMappingHelper;
    public static $myPlatform = 'tiktok';
    public $TiktokApi;

    public function __construct(){
        $this->TiktokApi = new TiktokApi();
        $this->Logger = new Logger();
        $this->MainModel = new MainModel();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->FieldMappingHelper = new FieldMappingHelper();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
    }

    /**
     *
     */
    public function getAccountDetails( $user_integration_id ){
        return $this->MainModel->getPlatformAccountByUserIntegration(
            $user_integration_id,
            $this->platformId, [
                'id',
                'account_name',
                'app_id',
                'app_secret',
                'api_domain',
                'access_token',
                'marketplace_id',
                'custom_domain',
                'expires_in'
            ]
        );
    }

    /**
     *
     */
    public function storeProductDetails( $api_domain, $user_id, $user_integration_id, $productResultArr, $variant, $app_key, $secret, $access_token, $isSyncInventory=false, $isGetProductDetails=true ){
        $product = PlatformProduct::where([
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_product_id' => $productResultArr['id'],
            'api_variant_id' => $variant['id'],
            'sku' => $variant['seller_sku'],
        ])
        ->first();

        if( !$product ){
            $product = new PlatformProduct();

            $product->user_id = $user_id;
            $product->user_integration_id = $user_integration_id;
            $product->platform_id = $this->platformId;
            $product->api_product_id = $productResultArr['id'];
            $product->api_variant_id = $variant['id'];
            $product->sku = $variant['seller_sku'];
            $product->product_sync_status = PlatformStatus::READY;
            $product->inventory_sync_status = PlatformStatus::READY;
        }

        $product->product_name = $productResultArr['name'];
        $product->api_warehouse_id = ( $variant['stock_infos'] ) ? $variant['stock_infos'][0]['warehouse_id'] : null;
        $product->price = $variant['price']['original_price'];
        $product->product_status = ( $productResultArr['status'] == 4 ) ? 1 : 0;
        $product->bundle = 0;//( COUNT( $productResultArr['skus'] ) == 1 ) ? 0 : 1;

        $product->created_at = date( "Y-m-d h:i:s", substr( $productResultArr['create_time'], 0, 10 ) );
        $product->api_updated_at = date( "Y-m-d h:i:s", substr( $productResultArr['update_time'], 0, 10 ) );
        $product->save();

        //get product details
        if( $isGetProductDetails ){
            $now = new DateTime();
            $unix = $now->getTimestamp();
            $string = $secret."/api/products/detailsapp_key".$app_key."product_id".$productResultArr['id']."timestamp".$unix.$secret;
            $sign = hash_hmac('sha256', $string, $secret);

            $url = "https://".$api_domain.".tiktokglobalshop.com/api/products/details?app_key=$app_key&product_id=".$productResultArr['id']."&timestamp=$unix&sign=$sign&access_token=$access_token";
            Storage::append( "Tiktok/".date( 'd-m-Y' )."/getProductDetails-".$user_integration_id.".txt", "[".date( 'H:i:s' )."] makeCurlRequest: ".$url );
            $response = $this->TiktokApi->makeAPICall( $url, 'GET' );
            Storage::append( "Tiktok/".date( 'd-m-Y' )."/getProductDetails-".$user_integration_id.".txt", "[".date( 'H:i:s' )."] makeCurlResponse: ".json_encode( $response ) );
        } else {
            $response = $productResultArr;
        }

        if( isset( $response['api_data'] ) && $response['api_data']['brand'] ){
            $productDetails = $response['api_data'];
            $product->brand_id = $productDetails['brand']['name'];

            $category = "";
            if( COUNT( $productDetails['category_list'] ) > 0 ){
                foreach( $productDetails['category_list'] as $cat ){
                    $category = $category.$cat['local_display_name'].", ";
                }
            }
            $product->category_id = rtrim( $category, ", " );
            $product->weight = $productDetails['package_weight'];

            $productDetailAttribute = PlatformProductDetailAttribute::where([
                'platform_product_id' => $product->id,
            ])
            ->first();

            if( !$productDetailAttribute ){
                $productDetailAttribute = new PlatformProductDetailAttribute();
                $productDetailAttribute->platform_product_id = $product->id;
            }

            $productDetailAttribute->lenght = $productDetails['package_length'];
            $productDetailAttribute->height = $productDetails['package_height'];
            $productDetailAttribute->width = $productDetails['package_width'];
            $productDetailAttribute->volume = $productDetails['package_weight'];

            $imageTxt = "";
            if( COUNT( $productDetails['images'] ) > 0 ){
                foreach( $productDetails['images'] as $images ){
                    foreach( $images['url_list'] as $img ){
                        $imageTxt = $imageTxt.$img.", ";
                    }
                }
            }
            $productDetailAttribute->images = rtrim( $imageTxt, ", " );
            $productDetailAttribute->save();
        }

        if( $isSyncInventory ){
            $product->inventory_sync_status = PlatformStatus::READY;
        }
        $product->save();

        if( COUNT( $variant['stock_infos'] ) > 0 ){
            foreach( $variant['stock_infos'] as $var ){
                $productInventory = PlatformProductInventory::where([
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'api_product_id' => $variant['id'],//$productResultArr['id'],
                    'platform_product_id' => $product->id,
                    // 'sku' => $variant['seller_sku'],
                    'api_warehouse_id' => $var['warehouse_id'],
                ])
                ->first();

                if( !$productInventory ){
                    $productInventory = new PlatformProductInventory();
                    $productInventory->user_id = $user_id;
                    $productInventory->user_integration_id = $user_integration_id;
                    $productInventory->platform_id = $this->platformId;
                    $productInventory->api_product_id = $variant['id'];//$productResultArr['id'];
                    $productInventory->platform_product_id = $product->id;
                    $productInventory->sku = $variant['seller_sku'];
                    $productInventory->api_warehouse_id = $var['warehouse_id'];
                    $productInventory->sync_status = PlatformStatus::READY;
                }

                if( $isSyncInventory ){
                    $productInventory->sync_status = PlatformStatus::READY;
                }

                $productInventory->quantity = $var['available_stock'];
                $productInventory->location_code = $productResultArr['sale_regions'][0] ?? '';
                $productInventory->save();
            }
        }

        //set product price
        // Log::Info( "Tiktok Product ID: ".$product->id." - ".$variant['price']['original_price'] );
        $this->CreatePriceList( $product->id, "pricelist", $variant['price']['original_price'] );
    }

    /*
     * Insert / Update Product Price
     */
    public function CreatePriceList( $product_id=0, $objectName="", $price = 0 )
    {
        if ( $product_id ) {
            $ObjectId = $this->ConnectionHelper->getObjectId($objectName);
            // Log::Info( "Tiktok Product Object Id: ".$ObjectId );
            if ($ObjectId) {
                $platformObjData = PlatformObjectData::where([
                    'platform_id' => $this->platformId,
                    'user_integration_id' => 0,
                    'platform_object_id' => $ObjectId,
                    'api_id' => $objectName,
                ])
                ->first();

                if( !$platformObjData ){
                    $platformObjData = new PlatformObjectData();
                    $platformObjData->user_id = 0;
                    $platformObjData->platform_id = $this->platformId;
                    $platformObjData->user_integration_id = 0;
                    $platformObjData->platform_object_id = $ObjectId;
                    $platformObjData->api_id = $objectName;
                }

                $platformObjData->api_code = $objectName;
                $platformObjData->name = ucfirst( strtolower( str_ireplace( "_", " ", $objectName ) ) );
                $platformObjData->status = 1;
                $platformObjData->save();

                // Log::Info( "Tiktok Product Object Data: ".$platformObjData->id );

                $price = isset($price) ? $price : 0;
                PlatformProductPriceList::updateOrCreate([
                    'platform_product_id' => $product_id
                ], [
                    'platform_product_id' => $product_id,
                    'platform_object_data_id' => $platformObjData->id,
                    'price' => $price
                ]);
            }
        }
    }

    /**
     *
     */
    public function convertTimeStampToDate( $timeStamp, $format = "Y-m-d H:i:s" ){
        return date( $format, substr( $this->fetchSubStr( $timeStamp, "Date(", ")" ), 0, 10 ) );
    }

    /**
     * @Description:     <This function for @abstract fetch string within specified start and end>
    */
    function fetchSubStr( $str, $start, $end, &$offsetI=0 )
    {
        $pos1 = strpos( $str, $start );
        if( $pos1 !== FALSE )
        {
            $pos1 = $pos1 + strlen( $start );

            $pos2 = FALSE;
            if( !empty( $end ) )
                $pos2 = strpos( $str, $end, $pos1 );

            if( $pos2 !== FALSE )
            {
                $offsetI = $pos2;
                return substr( $str, $pos1, ( $pos2 - $pos1 ) );
            }
            else
            {
                $offsetI = $pos1;
                return substr( $str, $pos1 );
            }
        }
    }

    /*
     * Get Order Location and Update
     */
    public function GetWarehouseLocation( $user_integration_id, $warehouse_id, $warehouseObject )
    {
        $platformObjData = PlatformObjectData::select( 'api_id' )
        ->where([
            'platform_id' => $this->platformId,
            'user_integration_id' => $user_integration_id,
            'platform_object_id' => $warehouseObject->id,
            'api_id' => $warehouse_id,
        ])
        ->first();

        return $platformObjData->api_id;
    }
}
