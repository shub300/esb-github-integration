<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\MainModel;
use Illuminate\Support\Facades\Config;

class JasciApi
{
    public function __construct()
    {
        $this->mobj = new MainModel();
    }
	
    public function getAccessToken($companyId,$tenantId,$userId,$password,$env_type)
	{
        if($env_type=='sandbox'){
            $BaseUrl  = Config::get('apiconfig.JasciBaseUrl');
        } else {
            $BaseUrl  = Config::get('apiconfig.JasciBaseUrlProduction');
        }
		

        $service_url = $BaseUrl.'/authenticate';

        $headers = array(
            'Content-Type: application/json'
        );

        $post_data = [];
        $post_data['tenantId'] = $tenantId;
        $post_data['companyId'] = $companyId;
        $post_data['userId'] = $userId;   
        $post_data['password'] = $password;   
        $encoded_post_data = json_encode($post_data);

        $response = $this->mobj->makeCurlRequest('POST', $service_url, $encoded_post_data, $headers); 

        return $response;
        
	}

    public function ApiCall($method,$url,$access_token,$post_data=NULL,$env_type)
    {
        if($env_type=='sandbox'){
            $BaseUrl  = Config::get('apiconfig.JasciBaseUrl');
        } else {
            $BaseUrl  = Config::get('apiconfig.JasciBaseUrlProduction');
        }
        
        $service_url = $BaseUrl.$url;

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer '.$access_token
        );

        $response = $this->mobj->makeCurlRequest($method, $service_url, $post_data, $headers); 

        return $response;

    }


}

