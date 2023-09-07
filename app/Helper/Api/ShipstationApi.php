<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\MainModel;
use Illuminate\Support\Facades\Config;

class ShipstationApi
{
    public function __construct()
    {
        $this->mobj = new MainModel();
    }
	
   

    public function ApiCall($method,$url,$api_key, $secret_key,$post_data=NULL,$is_webhook_process_call=false)
    {
        $BaseUrl  = Config::get('apiconfig.ShipstationBaseUrl');
        $service_url = $BaseUrl.$url;

        //when webhook process call complete url passed
        if($is_webhook_process_call) {
            $service_url = $url;
        }

        $headers = array(
            'Content-Type: application/json',
            'Authorization: basic '.base64_encode($api_key.':'.$secret_key)
        );
        $response = $this->mobj->makeCurlRequest($method, $service_url, $post_data, $headers);
        $response = json_decode($response,true);


        //for testing
        /* CURL POST METHOD */
        // $ch = curl_init();
        // curl_setopt($ch, CURLOPT_URL, $service_url);
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        // curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);

        // $headers = array();
        // $headers[] = "Authorization: basic ".base64_encode($api_key.':'.$secret_key);
        // $headers[] = "Content-Type: application/json";

        // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // if (!$result = curl_exec($ch)) {
        //     $result = curl_error($ch);
        // }
        // $curl_info = curl_getinfo($ch);

        // $response_time = $curl_info['total_time'];
        // $http_code = $curl_info['http_code'];
        // curl_close($ch);

        // $response = json_decode($result, true);
        // $error = null;
        // if (isset($response['Message'])){
        //     $error = $response['Message'];
        // }

    
        

        return $response;

    }

    public function test_ApiCall($method,$url,$api_key, $secret_key,$post_data=NULL,$is_webhook_process_call=false)
    {
        $BaseUrl  = Config::get('apiconfig.ShipstationBaseUrl');
        $service_url = $BaseUrl.$url;

        //when webhook process call complete url passed
        if($is_webhook_process_call) {
            $service_url = $url;
        }


        //for testing
        /* CURL POST METHOD */
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $service_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);

        $headers = array();
        $headers[] = "Authorization: basic ".base64_encode($api_key.':'.$secret_key);
        $headers[] = "Content-Type: application/json";

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (!$result = curl_exec($ch)) {
            $result = curl_error($ch);
        }
        $curl_info = curl_getinfo($ch);

        $response_time = $curl_info['total_time'];
        $http_code = $curl_info['http_code'];
        curl_close($ch);

        dd($result);
        
        $response = json_decode($result, true);
        
        $error = null;
        if (isset($response['Message'])){
            $error = $response['Message'];
        }

    
        

        return $response;

    }
    

}

