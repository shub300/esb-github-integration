<?php
namespace App\Http\Controllers\Logiwa;

use App\Helper\Logger;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Logiwa\Api\LogiwaApi;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformAccount;
use App\Models\PlatformObjectData;
use App\Models\PlatformPreProcessData;
use App\Models\PlatformProduct;
use App\Models\PlatformProductDetailAttribute;
use App\Models\PlatformProductInventory;
use App\Models\PlatformProductPriceList;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LogiwaService extends Controller
{
    public $Logger;
    public $MainModel;
    public $platformId;
    public $ConnectionHelper;
    public $FieldMappingHelper;
    public static $myPlatform = 'logiwa';
    public $LogiwaApi;

    public function __construct(){
        $this->LogiwaApi = new LogiwaApi();
        $this->Logger = new Logger();
        $this->MainModel = new MainModel();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->FieldMappingHelper = new FieldMappingHelper();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
    }

    public function getAccessToken( $user_id, $user_name, $user_password, $connection_type, $account_name, $isRefreshToken=true ){
        $httpBuildQueryArr = [
            'username' => $user_name,
            'password' => $user_password,
            'grant_type' => $connection_type,
        ];
        $response = $this->LogiwaApi->CheckAuthAPIResponse( "https://hubsystemapi.logiwa.com/token", $httpBuildQueryArr );

        if ( $response['api_status'] == 'success') {
            if ( isset( $response['access_token'] ) ) {
                $account = PlatformAccount::where([
                        'user_id' => $user_id,
                        'platform_id' => $this->platformId,
                        'account_name' => $account_name
                    ])->first();

                if ( !$account ) {
                    $account = new PlatformAccount();
                }

                if( !$isRefreshToken ){
                    $account->app_id = $this->MainModel->encrypt_decrypt( $user_name, 'encrypt' );
                    $account->app_secret = $this->MainModel->encrypt_decrypt( $user_password, 'encrypt' );
                    $account->account_name = $account_name;
                    $account->user_id = $user_id;
                    $account->platform_id = $this->platformId;
                    $account->connection_type = $connection_type;
                }

                $account->access_token = $this->MainModel->encrypt_decrypt( $response['access_token'], 'encrypt' );
                $account->token_type = $response['token_type'];
                $account->expires_in = $response['expires_in'];
                $account->token_refresh_time = time();
                $account->save();

                if( $isRefreshToken ){
                    return $response['access_token'];
                } else {
                    $data['success'] = "Successfully Connected";
                    return response()->json( $data, 200 );
                }
            } else {
                if( $isRefreshToken ){
                    return "Something went wrong in your account";
                } else {
                    $data['error'] = "Sign-in information is incorrect";
                    return response()->json( $data, 200 );
                }
            }
        }else{
            if( $isRefreshToken ){
                return json_encode( $response );
            } else {
                $data['error'] = json_encode( $response );
                return response()->json( $data, 200 );
            }
        }
    }

    /**
     *
     */
    public function getAccountDetails( $user_integration_id ){
        return $this->MainModel->getPlatformAccountByUserIntegration(
            $user_integration_id,
            $this->platformId, [
                'id',
                'user_id',
                'account_name',
                'app_id',
                'app_secret',
                'access_token',
                'expires_in'
            ]
        );
    }

    /**
     *
     */
    public function getDirectAccountDetails( $id ){
        return PlatformAccount::where([
            'id' => $id
        ])
        ->select( 'id',
            'user_id',
            'account_name',
            'app_id',
            'app_secret',
            'access_token',
            'expires_in' )
        ->first();
    }

    /**
     *
     */
    public function storeProductDetails( $user_id, $user_integration_id, $pr, $access_token ){
        $newLastModifiedDateArr = explode( "-", str_ireplace( [".", " "], "-", $pr['LastModifiedDate'] ) );
        $product = PlatformProduct::where([
            'user_integration_id' => $user_integration_id,
            'platform_id' => $this->platformId,
            'api_product_id' => $pr['ID'],
        ])
        ->first();

        if( !$product ){
            $product = new PlatformProduct();

            $product->user_id = $user_id;
            $product->user_integration_id = $user_integration_id;
            $product->platform_id = $this->platformId;
            $product->api_product_id = $pr['ID'];
            $product->product_sync_status = PlatformStatus::READY;
            // $product->inventory_sync_status = PlatformStatus::READY;
        }
        
        $product->api_variant_id = $pr['ID'];
        $product->barcode = $pr['BarcodeSearch'];
        $product->api_product_code = $pr['Code'];
        $product->product_name = $pr['Description'];
        $product->sku = $pr['Code'];
        $product->description = $pr['Description'];
        // $product->api_warehouse_id = $pr['WarehouseCode'];
        $product->api_created_at = $newLastModifiedDateArr[2]."-".$newLastModifiedDateArr[0]."-".$newLastModifiedDateArr[1]." ".$newLastModifiedDateArr[3];
        $product->api_updated_at = $newLastModifiedDateArr[2]."-".$newLastModifiedDateArr[0]."-".$newLastModifiedDateArr[1]." ".$newLastModifiedDateArr[3];
        $product->product_status = 1;
        $product->is_deleted = 0;
        $product->brand_id = $pr['Brand'];
        $product->category_id = $pr['ItemMainCategoryDescripton'];
        $product->save();

        $productDetailAttribute = PlatformProductDetailAttribute::where([
            'platform_product_id' => $product->id,
        ])
        ->first();

        if( !$productDetailAttribute ){
            $productDetailAttribute = new PlatformProductDetailAttribute();
            $productDetailAttribute->platform_product_id = $product->id;
        }

        $productDetailAttribute->width = $pr['Weight'];
        $productDetailAttribute->volume = $pr['Volume'];
        $productDetailAttribute->images = $pr['ImageUrl'];
        $productDetailAttribute->save();

        $this->createPricesList( $user_id, $user_integration_id, $product->id, $pr );
        // $this->CreatePriceList( $product->id, "pricelist", $pr['SalesUnitPrice'] );
        // $this->CreatePriceList( $product->id, "cost_price", $pr['PurchaseUnitPrice'] );
        // $this->CreatePriceList( $product->id, "sale_price", $pr['CreditSalesUnitPrice'] );

        //check product component available or not
        $postData = [
            "InventoryItemID" => $pr['ID']
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer '.$access_token,
        ];

        $url = $this->LogiwaApi->ApiURL."IntegrationApi/InventoryItemComponentSearch";
        $response = $this->LogiwaApi->MainModel->makeCurlRequest( 'POST', $url, json_encode( $postData ), $headers );
        Storage::append( 'Logiwa/'.$user_integration_id.'/ProductComponent/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".json_encode( $postData )." ".$response );
        $response = json_decode( $response, true );

        if( $response && COUNT( $response['Data'] ) > 0 ){
            $productComponentArr = $response['Data'];
            foreach( $productComponentArr as $component ){

                PlatformPreProcessData::where([
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'api_id' => $pr['ID'],
                    'sub_api_id' => $component['ComponentItemID'],
                    'module' => 'PRODUCT',
                ])->update([ 'status' => 0 ]); // Make status 0 for existing, if item found again then it will be update to 1
    
                $bundleItem = PlatformPreProcessData::where([
                    'user_integration_id' => $user_integration_id,
                    'platform_id' => $this->platformId,
                    'api_id' => $pr['ID'],
                    'sub_api_id' => $component['ComponentItemID'],
                    'module' => 'PRODUCT',
                ])->select('id', 'status')->first();

                if( !$bundleItem ){
                    $bundleItem = new PlatformPreProcessData();
                    $bundleItem->user_id = $user_id;
                    $bundleItem->user_integration_id = $user_integration_id;
                    $bundleItem->platform_id = $this->platformId;
                    $bundleItem->api_id = $pr['ID'];
                    $bundleItem->sub_api_id = $component['ComponentItemID'];
                    $bundleItem->module = 'PRODUCT';
                }

                $bundleItem->description = $component['IncludesCUQuantity'];
                $bundleItem->status = 1;
                $bundleItem->save();
            }
            
            $product->bundle = 1;
            $product->save();
        }
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
                // echo $ObjectId." - ".$platformObjData->id." : ".$price."<br>";
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
                        $itemPriceData['price'] = $pr['PurchaseUnitPrice'] ?? 0;
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
                    // PlatformProductPriceList::updateOrCreate([
                    //     'platform_product_id' => $product_id
                    // ], [
                    //     'platform_product_id' => $product_id,
                    //     'platform_object_data_id' => $objData->id,
                    //     'price' => $price
                    // ]);
                }
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
    public function fetchSubStr( $str, $start, $end, &$offsetI=0 )
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

    /**
     * @Description:     <This function is Converting database format date to convienant form >
     * @params :
     * @date : Date which you get from database.
     * @format : Format you want to retrieve.
     * @return :
     *		- Formatted date.
    */
    function formatDate( $format = '', $date = '', $isProperFormat=false )
    {
        if( $isProperFormat ) {
            $dateArr = explode("/", $date);

            if( isset( $dateArr[1] ) ){
                if( strlen( $dateArr[0] ) == 4 )
                    return $date;
                else
                    return Carbon::createFromFormat( 'd/m/Y', $date )->format( 'Y-m-d' );
            }
            return $date;

        } else if($format){
            return date( $format, strtotime( $date ) );
        }

        return date( 'Y-m-d H:i:s' );
    }
}
