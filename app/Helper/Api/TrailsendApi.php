<?php
namespace App\Helper\Api;
use Auth;
use DB;
use App\Helper\MainModel;

class TrailsendApi
{
    public function __construct()
    {
        $this->mobj = new MainModel();
    }


    //make api call
    public function ApiCall($api_key,$apiEndpoint, $post_fields=null, $method="GET",$env_type, $contentType=null)
    {
        if($env_type=='sandbox'){
            $service_url = \Config::get('apiconfig.Trailsend_sandbox') . '/'.$apiEndpoint;
        } else {
            $service_url = \Config::get('apiconfig.Trailsend') . '/'.$apiEndpoint;
        }

        if($contentType){
            $headers = [
                'x-app:'.$api_key,
                'Content-Type:application/json'
            ];
        } else {
            $headers = [
                'x-app:'.$api_key
            ];
        }
  
        $response = $this->mobj->makeCurlRequest($method, $service_url, $post_fields, $headers); 
        return json_decode($response, true);

    }



}

