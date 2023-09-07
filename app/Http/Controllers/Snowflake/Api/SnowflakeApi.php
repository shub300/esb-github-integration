<?php
namespace App\Http\Controllers\Snowflake\Api;

use App\Helper\Logger;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class SnowflakeApi extends Controller
{
    public $mainModel, $connectionHelper, $platformId, $logger, $fieldMapHelper;
    public static $myPlatform = 'snowflake';

    public function __construct(){
        $this->logger = new Logger();
        $this->mainModel = new MainModel();
        $this->connectionHelper = new ConnectionHelper();
        $this->fieldMapHelper = new FieldMappingHelper();
        $this->platformId = $this->connectionHelper->getPlatformIdByName(self::$myPlatform);
    }
    /**
     * Function to process an api call and handle the response
     *
     */
    public function makeAPICall( $user_integration_id, $account, $post_data = [], $isRefreshToken = false ){
        $headers = [];

        $api_url = "https://".$account->api_domain.'.aws.snowflakecomputing.com/api/v2/statements';//config('SnowflakeEndPointUrl');
        if( $isRefreshToken ){
            $httpBuildQueryArr['refresh_token'] = $this->mainModel->encrypt_decrypt( $account->refresh_token, 'decrypt' );
            $httpBuildQueryArr['grant_type'] = 'refresh_token';

            $clientId = $this->mainModel->encrypt_decrypt( $account->app_id, 'decrypt' );
            $clientSecret = $this->mainModel->encrypt_decrypt( $account->app_secret, 'decrypt' );
            $secret = base64_encode($clientId.":".$clientSecret);
            $headers[] = 'Authorization: Basic '.$secret;
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';

            $api_url = 'https://'.$account->api_domain.config('SnowflakeEndPointUrl').'token-request';

            $post_data = $httpBuildQueryArr;
        } else {
            $headers[] = 'Authorization: Bearer '.$this->mainModel->encrypt_decrypt( $account->access_token, 'decrypt' );
            $headers[] = 'X-Snowflake-Authorization-Token-Type: OAUTH';
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'User-Agent: Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1)';
        }

        $post_data = json_encode( $post_data );
        Storage::append( 'Snowflake/'.$user_integration_id.'/'.date('d-m-Y').'.txt', "[".date( 'H:i:s' )."]: makeCurlRequest - ".$post_data );
        $response = $this->mainModel->makeCurlRequest( 'POST', $api_url, $post_data, $headers );
        Storage::append( 'Snowflake/'.$user_integration_id.'/'.date('d-m-Y').'.txt', "[".date( 'H:i:s' )."]: makeCurlResponse - ".$response );
        $result = json_decode( $response, 1 );

        $data = [];
        if( $isRefreshToken ){
            if( isset( $result['access_token'] ) ){
                $data['api_status'] = 1;
                $data['api_data'] = $result['access_token'];
            }
        } else {
            if( isset($result['code']) && $result['code'] == "090001" ){
                $data['api_status'] = 1;
                $data['api_data'] = $result ?? [];
            } else if( isset( $result['code'] ) && ( isset( $result['message'] ) && trim( $result['message'] ) ) ){
                if( $result['code'] == "390318" ){
                    app('App\Http\Controllers\Snowflake\SnowflakeApiController')->RefreshToken($account->id);
                    $data['api_status'] = 0;
                    $data['api_data'] = "390318"; // OAuth access token expired
                } else{
                    $data['api_status'] = 0;
                    $data['api_data'] = $result['message'];
                }
            } else {
                $data['api_status'] = 0;
                $data['api_data'] = 'API problem or account token modification.';
            }
        }

        $data['responseResult'] = $result;
        return $data;

    }

    /**
     *
     */
    public function getAccountDetails( $user_integration_id ){
        return $this->mainModel->getPlatformAccountByUserIntegration( $user_integration_id, $this->platformId, [
            'id',
            'account_name',
            'app_id',
            'app_secret',
            'api_domain',
            'access_token',
            'marketplace_id',
            'custom_domain',
            'region',
        ] );
    }

    /**
     * @Function:        <login>
     * @Author:          Gautam Kakadiya
     * @Created On:      <29-03-2023>
     * @Last Modified By:Gautam Kakadiya
     * @Last Modified:   Gautam Kakadiya
     * @Description:     <This function for @abstract fetch string within specified start and end>
     */
    public function fetchSubStr($str, $start, $end, &$offsetI = 0)
    {
        $pos1 = strpos($str, $start);
        if ($pos1 !== FALSE) {
            $pos1 = $pos1 + strlen($start);

            $pos2 = FALSE;
            if (!empty($end))
                $pos2 = strpos($str, $end, $pos1);

            if ($pos2 !== FALSE) {
                $offsetI = $pos2;
                return substr($str, $pos1, ($pos2 - $pos1));
            } else {
                $offsetI = $pos1;
                return substr($str, $pos1);
            }
        }
    }

    /**
     * Product Identity Mapping
     */
    public function ProductIdentityMapping( $user_integration_id, $PlatformWorkFlowRuleID)
    {
        $product_identity_obj_id = $this->connectionHelper->getObjectId('product_identity');
        // Log::info( "product_identity_obj_id: ".$product_identity_obj_id );
        $mapping_data = $this->fieldMapHelper->getMappedField( $user_integration_id, $PlatformWorkFlowRuleID, $product_identity_obj_id);
        // Log::info( "mapping_data: ".json_encode( $mapping_data ) );

        $source_row_data = $destination_row_data = '';
        if ($mapping_data) {
            if ($mapping_data['destination_platform_id'] == self::$myPlatform) {
                $destination_row_data = $mapping_data['destination_row_data'];
                $source_row_data = $mapping_data['source_row_data'];
            } else {
                $destination_row_data = $mapping_data['source_row_data'];
                $source_row_data = $mapping_data['destination_row_data'];
            }
        }

        return ['source_identity' => $source_row_data, 'destination_identity' => $destination_row_data];
    }

    /**
     *
     */
    public function getDefaultDatabaseObject( $user_integration_id ){

        $result = [
            'database',
            'schema',
            'warehouse',
        ];

        $dbName = $this->fieldMapHelper->getMappedDataByName( $user_integration_id, NULL, "default_database_name",  ['custom_data'], "default");
        if ($dbName) {
            $result['database'] = $dbName->custom_data;
        }

        $dbSchema = $this->fieldMapHelper->getMappedDataByName( $user_integration_id, NULL, "default_database_schema",  ['custom_data'], "default");
        if ($dbSchema) {
            $result['schema'] = $dbSchema->custom_data;
        }

        $dbWarehouse = $this->fieldMapHelper->getMappedDataByName( $user_integration_id, NULL, "default_database_warehouse",  ['custom_data'], "default");
        if ($dbWarehouse) {
            $result['warehouse'] = $dbWarehouse->custom_data;
        }

        return $result;
    }
}
