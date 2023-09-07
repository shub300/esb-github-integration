<?php
namespace App\Http\Controllers\Logiwa\Api;

use App\Helper\ConnectionHelper;
use App\Helper\MainModel;
use Illuminate\Support\Facades\Storage;

class LogiwaApi
{
    public $MainModel = '';
    public $LogiwaApi = '';
    public $ConnectionHelper = '';
    public $Logger = '';
    public $WorkflowSnippet = '';
    public $platform = '';
    public $platformId = '';
    public $ApiURL = 'https://hubsystemapi.logiwa.com/en/api/';

    /**
     *
     */
    public function __construct()
    {
        $this->MainModel = new MainModel();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->platform = 'logiwa';
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName($this->platform);
    }

    /**
     * See more details
     * https://developer.logiwa.com/?id=5df0da39e6466c2eec992f3f
     */
    public function CheckAuthAPIResponse( $url, $httpBuildQueryArr=[] )
    {
        $post = http_build_query( $httpBuildQueryArr );

        $headers = [];
        $response = $this->MainModel->makeCurlRequest('POST', $url, $post, $headers, 1); // Xml Request
        // Storage::append( "Logiwa/".date( 'd-m-Y' )."/makeCurlRequest.txt", "[".date( 'H:i:s' )."] ".$url." : ".$post );

        if ($response) {
            $res = json_decode( $response, 1 );
            if ( isset( $res['access_token'] ) ) {
                $res['api_status'] = 'success';
            } else{
                $res['api_status'] = 'failed';
            }
            return $res;
        } else {
            return false;
        }
    }

    /**
     * See more details
     * https://developer.logiwa.com/?id=5df0da39e6466c2eec992f3f
     */
    public function CheckAPIResponse( $url, $httpBuildQueryArr=[] )
    {
        $post = http_build_query( $httpBuildQueryArr );

        $headers = [];
        $response = $this->MainModel->makeCurlRequest('POST', $url, $post, $headers, 1); // Xml Request
        // Storage::append( "Logiwa/".date( 'd-m-Y' )."/makeCurlRequest.txt", "[".date( 'H:i:s' )."] ".$url." : ".$post );

        if ($response) {
            $res = json_decode( $response, 1 );
            if ( isset( $res['access_token'] ) ) {
                $res['api_status'] = 'success';
            } else{
                $res['api_status'] = 'failed';
            }
            return $res;
        } else {
            return false;
        }
    }
}
