<?php
namespace App\Http\Controllers\Tiktok\Api;

use App\Helper\Logger;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Http\Controllers\Controller;
use App\Models\Enum\PlatformStatus;
use App\Models\PlatformObjectData;
use App\Models\PlatformProduct;
use App\Models\PlatformProductDetailAttribute;
use App\Models\PlatformProductInventory;
use App\Models\PlatformProductPriceList;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

class TiktokApi extends Controller
{
    public $Logger;
    public $MainModel;
    public $platformId;
    public $ConnectionHelper;
    public $FieldMappingHelper;
    public static $myPlatform = 'tiktok';

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

        $data = [];
        if( $isRefreshToken ){
            if( isset( $result['access_token'] ) ){
                $data['api_status'] = 1;
                $data['api_data'] = $result['access_token'];
            }
        } else {
            if( $result['code'] == 0 ){
                $data['api_status'] = 1;
                $data['api_data'] = $result['data'] ?? [];
            } else if( $result['code'] > 0){
                $data['api_status'] = 0;
                $data['api_data'] = $result['message'];
            } else {
                $data['api_status'] = 0;
                $data['api_data'] = $result['message'] ?? 'API error or account information is invalid.';
            }
        }

        return $data;

    }

    /**
     * 
     */
    function dateTime( $date )
    {
        $originalDatetime = new DateTime( $date, new DateTimeZone('Europe/London'));
        // Get the Unix timestamp
        $timestamp = $originalDatetime->getTimestamp();
        return $timestamp; // Output: 1690671600
    }
}
