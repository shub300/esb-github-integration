<?php
namespace App\Http\Controllers\ExactERP\Api;

use App\Helper\Logger;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Http\Controllers\Controller;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformProduct;
use App\Models\PlatformProductInventory;
use Carbon\Carbon;

class ExactERPApi extends Controller
{
    public $MainModel, $ConnectionHelper, $platformId, $Logger, $FieldMapHelper;
    public static $myPlatform = 'exacterp';

    public function __construct(){
        $this->Logger = new Logger();
        $this->MainModel = new MainModel();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->FieldMapHelper = new FieldMappingHelper();
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
    }

    /**
     * Function to process an api call and handle the response
     *
     */
    public function makeAPICall( $user_id, $postfix_url, $platform_account, $method="GET", $post_data = '', $isRefreshToken = false ){
        $headers = [];

        $api_url = $platform_account->api_domain.$postfix_url;
        $accessToken = $this->MainModel->encrypt_decrypt( $platform_account->access_token, 'decrypt' );
        if( !$isRefreshToken ){
            $headers[] = 'Authorization: Bearer '.$accessToken;
            $headers[] = 'Content-Type: application/json';
        }

        $response = $this->MainModel->makeCurlRequest( $method, $api_url, $post_data, $headers );
        $result = json_decode( $response, 1 );
        // dd($headers, $api_url, $result);
        $data = [];
        if(
            ( isset( $result['error'] ) && $result['error'] == "access_denied" )
            &&
            ( isset( $result['error_description'] ) && $result['error_description'] == "Rate limit exceeded: access_token not expired" )
        ){

            $data['api_status'] = 2;
            $data['api_data'] = $accessToken;

        } else if( isset( $result['error'] ) && $result['error'] === "invalid_grant" ){

            $data['api_status'] = 2;
            $data['api_data'] = $result['error_description'];

        } else {
            if( $isRefreshToken ){
                if( isset( $result['access_token'] ) ){
                    $data['api_status'] = 1;
                    $data['api_data'] = $result;
                }
            } else {
                if( isset($result['code']) && isset($result['message']) ){
                    $data['api_status'] = 0;
                    $data['api_data'] = $result['message'];
                } else {
                    $data['api_status'] = 0;
                    $data['api_data'] = isset($result['message']) ? $result['message'] : 'API error or account information is invalid.';
                }
            }
        }
        return $data;

    }

    /**
     * Function to process an api call and handle the response
     *
     */
    public function makeCurlCall( $url, $access_token, $method='GET', $post_data='' ){
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url );
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_ENCODING, '');
        curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 0);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method );

        if( $method == "POST" ){
            curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data );
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer '.$this->MainModel->encrypt_decrypt( $access_token, 'decrypt' ),
            'Accept: application/json',
            'Cookie: ASP.NET_SessionId=xblldzzady5kxc5howqjmtwx; ExactOnlineClient=NI46dUmCBPIp7xj/c+DwLGR8tn/O6ffFk2C9mg6Z5sftj2+EgKSVl5fAkxflQKG1WSMI90udK9ZcbPw9yHFCKx2UDKy7QKc0yQXpvF3lUtDOXlfACU/p71yIwYdzaFmBHX7kNv9SBpHUXTe8r4OiuJ7z+uftAJ6fyFYSlnsWiL4=; ExactServer{a9f6eb49-f339-44a1-8138-f7e192694bfc}=Division=113480'
        ]);

        $response = curl_exec($curl);
        $info = curl_getinfo($curl);
        curl_close($curl);

        $result = [];
        $status = 200;
        if( $info['http_code'] == 200 ){
            $response = json_decode( $response );
            // Log::info( "makeCurlCall: ".json_encode( $response ) );
            if( isset( $response->error ) ){
                $result = $response->error;
                $status = 500;
            } else {
                if( isset( $response->d->results ) ){
                    $result = $response->d->results ?? [];
                } else {
                    $result = $response->d ?? [];
                }
            }
        }


        return [ 'result' => $result, 'info' => $info, 'status' => $status ];
    }

    /**
     *
     */
    public function getAccountDetails( $user_integration_id ){
        return $this->MainModel->getPlatformAccountByUserIntegration( $user_integration_id, $this->platformId, [
            'id',
            'account_name',
            'api_domain',
            'app_id',
            'app_secret',
            'access_token',
            'token_type',
            'region'
        ] );
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

    /**
     * get Exact ERP product details
     * https://start.exactonline.nl/docs/HlpRestAPIResourcesDetails.aspx?name=SyncInventoryItemWarehouses
     */
    public function storeProducts( $result, $user_id, $user_integration_id, $isUseInventory=0 ){
        foreach( $result as $products ){
            $product = PlatformProduct::where([
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'api_product_id' => $products->Item,
            ])
            ->first();

            if( !$product ){
                $product = new PlatformProduct();

                $product->user_id = $user_id;
                $product->user_integration_id = $user_integration_id;
                $product->platform_id = $this->platformId;
                $product->api_product_id = $products->Item;
                $product->api_variant_id = $products->Item;
                $product->product_sync_status = PlatformStatus::READY;
                $product->inventory_sync_status = PlatformStatus::READY;
            }

            if( $isUseInventory ){
                $product->inventory_sync_status = PlatformStatus::READY;
            }

            $product->barcode = $products->ItemBarcode;
            $product->api_product_code = $products->ItemCode;
            $product->product_name = $products->ItemDescription;
            $product->sku = $products->ItemCode;
            $product->description = $products->ItemDescription;
            $product->api_warehouse_id = $products->WarehouseCode;
            // $product->uom = $products->WarehouseDescription;
            // $product->custom_fields = $products->__metadata->uri;
            $product->api_created_at = $this->convertTimeStampToDate( $products->Created );
            $product->api_updated_at = $this->convertTimeStampToDate( $products->Modified );
            $product->product_status = 1;
            $product->save();

            $productInventory = PlatformProductInventory::where([
                'user_integration_id' => $user_integration_id,
                'platform_id' => $this->platformId,
                'platform_product_id' => $product->id,
                'api_product_id' => $products->Item,
                // 'sku' => $products->ItemCode,
            ])
            ->first();

            if( !$productInventory ){
                $productInventory = new PlatformProductInventory();

                $productInventory->user_id = $user_id;
                $productInventory->user_integration_id = $user_integration_id;
                $productInventory->platform_id = $this->platformId;
                $productInventory->platform_product_id = $product->id;
                $productInventory->api_product_id = $products->Item;
                $productInventory->sync_status = PlatformStatus::READY;
            }

            $productInventory->sku = $products->ItemCode;
            $productInventory->api_warehouse_id = $products->WarehouseCode;
            $productInventory->quantity = 0;
            $productInventory->save();
        }
    }
}
