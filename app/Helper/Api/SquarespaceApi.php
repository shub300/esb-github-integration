<?php
namespace App\Helper\Api;
use Auth;
use DB;
use App\Helper\MainModel;

class SquarespaceApi
{
    public function __construct()
    {
        $this->mobj = new MainModel();
    }

    //make api call
    public function ApiCall($authHash,$apiEndpoint, $post_fields=null, $method="GET")
    {
        $service_url = \Config::get('apiconfig.SquarespaceUrl') . '/'.$apiEndpoint;
        $headers = [
            "Authorization:Bearer " . $authHash,
            "Content-Type: application/json",
            "User-Agent: curl/7.54.0",
        ];
        $response = $this->mobj->makeCurlRequest($method, $service_url, $post_fields, $headers); 
        return json_decode($response, true);
    }

    //make api call for Auth
    public function AuthApiCall($method,$curl_post_data, $authHash)
    {
        $post_data = json_encode($curl_post_data,true);
        $service_url = \Config::get('apiconfig.SquarespaceUrlAuth') . '/tokens';
        $headers = [
            'Authorization:Basic ' . $authHash,
            'Content-Type: application/json',
            'User-Agent: curl/7.54.0',
            'Cookie: ANONYMOUS_ID=sentinel-d6559983-f3d3-490c-9718-89b60592a658'
        ];
        $response = $this->mobj->makeCurlRequest($method, $service_url, $post_data, $headers);
        return $response;
    }

    // Handle Squarespace Response Errors 
    public function handleResponseError($response)
    {
        $errors_list = null;
        if (isset($response['errors'])) {
            foreach ($response['errors'] as $key => $error) {
                $errors_list .= $error['message'] . ",";
            }
        } else if (isset($response['response'])) {
            $errors_list = $response['response'];
        }
        return rtrim($errors_list, ",");
    }
    
}

