<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\MainModel;
use App\Common;

class AhlsellApi
{
    public function __construct()
    {
        $this->mobj = new MainModel();
    }
    public function GetInventory($url, $request_data_json)
    {
        $service_url = $url;
        $headers = [
            'Content-Type: application/xml'
        ];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers); //('POST', $service_url, $request_data_json, $headers);
        return $response;
    }
}
