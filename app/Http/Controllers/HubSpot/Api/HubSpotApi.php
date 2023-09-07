<?php
namespace App\Http\Controllers\HubSpot\Api;
use App\Helper\MainModel;
use Illuminate\Support\Facades\Storage;

class HubSpotApi
{
    public $MainModel = '';
    /**
     *
     */
    public function __construct()
    {
        $this->MainModel = new MainModel();
    }

    /**
     *
     */
    public function CheckAPIResponse( $method = 'GET', $account, $endpoint='', $post = [], $isRefreshToken = false, $isUseHAPIKey=false )
    {
        $headers = [];
        $httpBuildQueryArr['client_id'] = $this->MainModel->encrypt_decrypt( $account->app_id, 'decrypt' );
        $httpBuildQueryArr['client_secret'] = $this->MainModel->encrypt_decrypt( $account->app_secret, 'decrypt' );

        $service_url = $account->api_domain.$endpoint;
        if( $isRefreshToken ){
            $httpBuildQueryArr['refresh_token'] = $this->MainModel->encrypt_decrypt( $account->refresh_token, 'decrypt' );
            $httpBuildQueryArr['grant_type'] = 'refresh_token';
        } else {
            if( !$isUseHAPIKey ){
                $headers[] = 'Authorization: Bearer '.$this->MainModel->encrypt_decrypt( $account->access_token, 'decrypt' );
            }
            $headers[] = 'Content-Type: application/json';
        }

        if( is_array( $post ) ){
            $post = http_build_query( $httpBuildQueryArr );
        }

        $response = $this->MainModel->makeCurlRequest( $method, $service_url, $post, $headers, 1); // Xml Request
        if( $endpoint == "oauth/v1/token" ){
            Storage::append( 'HubSpot/RefreshToken-'.$account->id.'/'.date( 'd-m-Y' ).'.txt', "[".date( 'h:i:s' )."] ".$service_url." : ".$response );
        }

        $res = json_decode( $response, 1 );

        if( $isRefreshToken ){
            if( isset( $res['token_type'] ) ){
                //
            }
        } else {
            if( $isUseHAPIKey ){
                if( isset( $res['targetUrl'] ) )
                {
                    $res['api_status'] = 'success';
                    $res['api_error'] = '';
                }
            }

            if( isset( $res['status'] ) && $res['status'] == "error" ){
                $res['api_status'] = 'failed';
                $res['api_error'] = $res['message'];
            } else if ( ( isset( $res['results'] ) && COUNT( $res['results'] ) > 0 ) || isset( $res['id'] )) {
                    $res['api_status'] = 'success';
                    $res['api_error'] = '';
            } else if( isset( $res['vid'] ) && $res['vid'] > 0 ){
                $res['api_status'] = 'success';
                $res['api_error'] = '';
            } else {
                $res['api_status'] = 'failed';
                $res['api_error'] = 'Sign-in information is incorrect';
            }
        }

        return $res;

    }
}
