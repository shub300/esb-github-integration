<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\MainModel;
use App\Common;

class SitooApi
{

    public function __construct()
    {
        $this->mobj = new MainModel();
    }

    public function makeSitooRequest($method, $base_url, $api_id, $password, $endpoint, $post_data)
    {
        $service_url = rtrim($base_url, '/') . $endpoint;
        $header = [
            "Content-Type: application/json",
            "Accept: application/json",
            "Authorization: Basic " . base64_encode($api_id . ":" . $password)
        ];

        $response = $this->mobj->makeCurlRequest($method, $service_url, $post_data, $header);
        return $response;
    }
}
