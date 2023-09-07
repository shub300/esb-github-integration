<?php
namespace App\Http\Controllers\Whmcs\Api;

use App\Helper\ConnectionHelper;
use App\Helper\MainModel;
use Illuminate\Support\Facades\Storage;

class WhmcsApi
{
    public $mobj = '';
    public $WhmcsApi = '';
    public $ConnectionHelper = '';
    public $FieldMappingHelper = '';
    public $Logger = '';
    public $WorkflowSnippet = '';
    public $platform = '';
    public $platformId = '';

    /**
     *
     */
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->platform = 'whmcs';
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName($this->platform);
    }
    /**
     * https://helptest.apiworx.com/includes/api.php
     * See more details
     * https://developers.whmcs.com/api/authentication,
     * https://developers.whmcs.com/api-reference/createoauthcredential/
     */
    public function CheckAPIResponse( $authDetails, $httpBuildQueryArr=[] )
    {
        $post = http_build_query( $httpBuildQueryArr );

        $service_url = $authDetails['end_point'];
        $headers = [];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $post, $headers, 1); // Xml Request
        Storage::append( "WHMCS/".date( 'd-m-Y' )."/makeCurlRequest.txt", "[".date( 'H:i:s' )."] ".$service_url." : ".$post );

        if ($response) {
            $res = json_decode( $response, 1 );
            if ($res['result'] == 'success' ) {
                $res['api_status'] = 'success';
                $res['api_error'] = '';
            }else if ($res['result'] == "error") {
                $res['api_status'] = 'failed';
                $res['api_error'] = $res['message'];
            }else{
                $res['api_status'] = 'failed';
                $res['api_error'] = 'Sign-in information is incorrect';
            }
            return $res;
        } else {
            return false;
        }
    }
    /**
     *
     */
    public function GetWhmCsAccInfo($user_id)
    {
        $app_detail = $this->mobj->getFirstResultByConditions(
                        'platform_api_app',
                        ['platform_id' => $this->platformId]
                    );

        if($app_detail){
            $intacct_cred = array();
            $intacct_cred['app_ref'] = $app_detail->app_ref;
            $intacct_cred['client_id'] = $this->mobj->encrypt_decrypt( $app_detail->client_id, 'decrypt' );
            $intacct_cred['client_secret'] = $this->mobj->encrypt_decrypt( $app_detail->client_secret, 'decrypt' );
            return $intacct_cred;
        }else{
            return false;
        }
    }

    /**
     *
     */
    protected static function setAPIWebhook($accountInfo, $data)
    {
        if(!empty($accountInfo)) {
            $url = static::url($accountInfo->secret_key, 'hooks', 'v3');
            $response = static::makeAPICall($accountInfo, $url, $data, 'POST');
            if($response = static::isJson($response, true)) {
                $response = static::errorOrResponse($response);
                if(isset($response['error'])) {
                    return $response['error'];
                    } else {
                    return $response;
                }
            }
        }
        return false;
    }
}
