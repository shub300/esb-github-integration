<?php
namespace App\Http\Controllers\Veracore\Api;

use App\Helper\Logger;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Http\Controllers\Controller;
use App\Models\PlatformObjectData;
use App\Models\PlatformProductPriceList;

class VeracoreApi extends Controller
{
    public $MainModel;
    public $platformId;
    public $Logger = '';
    public $ConnectionHelper;
    public $FieldMappingHelper = '';
    public static $myPlatform = 'veracore';

    public function __construct(){

        $this->Logger = new Logger();
        $this->MainModel = new MainModel();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->FieldMappingHelper = new FieldMappingHelper();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
    }

    /**
     * Function to process an api call and handle the response
     *
     */
    public function makeAPICall( $api_url, $method="GET", $post_data = [], $headers = [], $isRefreshToken = false ){

        if( $method== "GET" ){
            $headers[] = "Accept: application/json";
        } else {
            $headers[] = "Content-Type: application/json";
        }

        $post_data = json_encode( $post_data );
        $response = $this->MainModel->makeCurlRequest( $method, $api_url, $post_data, $headers );
        $result = json_decode( $response, 1 );

        // $data = [];
        // if( $isRefreshToken ){
        //     if( isset( $result['access_token'] ) ){
        //         $data['api_status'] = 1;
        //         $data['api_data'] = $result['access_token'];
        //     }
        // } else {
        //     if( $result['code'] == 0 ){
        //         $data['api_status'] = 1;
        //         $data['api_data'] = $result['data'] ?? [];
        //     } else if( $result['code'] > 0){
        //         $data['api_status'] = 0;
        //         $data['api_data'] = $result['message'];
        //     } else {
        //         $data['api_status'] = 0;
        //         $data['api_data'] = $result['message'] ?? 'API error or account information is invalid.';
        //     }
        // }

        return $result;

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
                'access_key',
            ]
        );
    }

    /*
     * Insert / Update Product Price
     */
    public function CreatePriceList( $product_id=0, $objectName="", $price = 0 )
    {
        if ( $product_id ) {
            $ObjectId = $this->ConnectionHelper->getObjectId($objectName);
            
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
    public function createPricesList( $user_id, $user_integration_id, $product_id, $pr ){
        $objectID = $this->ConnectionHelper->getObjectId("pricelist");
        
        if ($objectID) {
            $priceListInfo = PlatformObjectData::where([
                // 'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'platform_object_id' => $objectID
            ])->select('id', 'api_id')->get();

            if( $priceListInfo->isNotEmpty() ){
                foreach ($priceListInfo as $objData) {
                    $itemPriceData['platform_object_data_id'] = $objData->id;

                    $itemPriceData['price'] = 0;
                    if($objData->api_id == 'cost_price'){
                        $itemPriceData['price'] = $pr['Product Default Value'] ?? 0;
                    }

                    if($objData->api_id == 'sale_price'){
                        $itemPriceData['price'] = $pr['CreditSalesUnitPrice'] ?? 0;
                    }

                    if($objData->api_id == 'pricelist'){
                        $itemPriceData['price'] = $pr['SalesUnitPrice'] ?? 0;
                    }

                    $existingPrice = PlatformProductPriceList::where([
                        'platform_product_id' => $product_id, 
                        'platform_object_data_id' => $objData->id 
                    ])
                    ->select('id')
                    ->first();

                    if ($existingPrice) {
                        $existingPrice->update($itemPriceData);
                    } else {
                        $itemPriceData['platform_product_id'] = $product_id;
                        PlatformProductPriceList::create($itemPriceData);
                    }
                }
            }
        }
    }
}
