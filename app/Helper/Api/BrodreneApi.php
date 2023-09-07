<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\MainModel;

class BrodreneApi
{
    public function __construct()
    {
        $this->mobj = new MainModel();
    }

    /* Refresh Token */
    public function RefreshToken($postData)
    {
        try {
            $response = false;
            $header = [
                "Content-type:application/x-www-form-urlencoded",
            ];
            $url = \Config::get('apiconfig.BrodreneOauthUrl') . '/token';
            $response = $this->mobj->makeCurlRequest('POST', $url, $postData, $header);
            return $response;
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
        }
    }
    //getInventory
    public function GetInventory($post_fields,$authHash)
    {
        $service_url="https://api-prd.dahl.no/inventory/1/inventory/warehouse-items";
        $headers = [
            "Content-Type:application/json",
            "Authorization:Bearer " . $authHash,
        ];
        
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $post_fields, $headers); 
        return json_decode($response);
    }

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

