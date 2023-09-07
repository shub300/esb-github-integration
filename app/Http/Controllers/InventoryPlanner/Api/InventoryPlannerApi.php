<?php
namespace App\Http\Controllers\InventoryPlanner\Api;

use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\MainModel;
use App\Helper\Logger;
use Illuminate\Support\Facades\Storage;

class InventoryPlannerApi
{
    public $MainModel = '';
    public $InventoryPlannerApi = '';
    public $ConnectionHelper = '';
    public $FieldMappingHelper = '';
    public $Logger = '';
    public $WorkflowSnippet = '';
    public $platform = '';
    public $platformId = '';
    public $ApiVersion = '/v1';

    /**
     *
     */
    public function __construct()
    {
        $this->MainModel = new MainModel();
        $this->Logger = new Logger();
        $this->ConnectionHelper = new ConnectionHelper();
        $this->FieldMappingHelper = new FieldMappingHelper();
        $this->platform = 'inventoryplanner';
        $this->platformId = $this->ConnectionHelper->getPlatformIdByName($this->platform);
    }
    /**
     * 
     */
    public function CheckAPIResponse( $url, $authDetails=[], $method = 'GET', $post=[], $isAuth=false )
    {
        // $curl = curl_init();

        // curl_setopt_array( $curl, array(
        //     CURLOPT_URL => $url,
        //     CURLOPT_RETURNTRANSFER => true,
        //     CURLOPT_ENCODING => '',
        //     CURLOPT_MAXREDIRS => 10,
        //     CURLOPT_TIMEOUT => 0,
        //     CURLOPT_FOLLOWLOCATION => true,
        //     CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        //     CURLOPT_CUSTOMREQUEST => $method,
        //     CURLOPT_HTTPHEADER => [
        //         'Account: '.$authDetails['app_id'],
        //         'Content-Type: application/json',
        //         'Authorization: '.$authDetails['app_secret'],
        //     ]
        // ));

        $headers = [];
        $headers [] = 'Account: '.$authDetails['app_id'];
        $headers [] = 'Content-Type: application/json';
        $headers [] = 'Authorization: '.$authDetails['app_secret'];

        $response = $this->MainModel->makeCurlRequest( $method, $url, $post, $headers);//curl_exec($curl);//
        // dd( $method, $url, $post, $response );
        // curl_close($curl);
        $response = json_decode( $response, true );
        // dd( $url, $response );
        if( $isAuth ){
            Storage::append('InventoryPlanner/' . $authDetails['app_id'] . '/ConnectInventoryPlanner/' . date('d-m-Y') . '.txt', "[" . date('H:i:s') . "]: URL: ".$url.", Result: " . json_encode( $response ) );
            if( isset( $response['meta'] ) ){//['count'] ) && $response['meta']['count'] >0
                $response['api_status'] = 'success';
                $response['api_error'] = '';
            } else {
                $response['api_status'] = 'failed';
                $response['api_data'] = 'Sign-in information is incorrect';
            }
        } else {
            if( isset( $response['result'] ) && $response['result']['status'] == 'success' ){
                $response['api_status'] = 'success';
                $response['api_error'] = '';
            } else if( isset( $response['result'] ) && $response['result']['status'] == 'error' ){
                $response['api_status'] = 'error';
                $response['api_data'] = $response['result']['message'];
            } else if( isset( $response['meta']['count'] ) && $response['meta']['count'] >0 ){
                $response['api_status'] = 'success';
                $response['api_data'] = '';
            } else{
                $response['api_status'] = 'failed';
                $response['api_data'] = 'Sign-in information is incorrect';
            }
        }
        
        return $response;
    }
}
