<?php

namespace App\Helper\Api;

use App\Helper\MainModel;
use App\Helper\ConnectionHelper;

class ExtensivBillingManagerApi
{
    public $mobj, $helper;
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->helper = new ConnectionHelper;
    }

    /* Check 3pl token */
    public function CheckCredentials($client_id, $client_secret)
    {
        try {
            $url = "https://secure-wms.com/AuthServer/api/Token";
            $header = [
                "Content-Type: application/json; charset=utf-8",
                "Accept: application/json",
                "Authorization: Basic " . base64_encode($client_id . ':' . $client_secret)
            ];
            $response = $this->mobj->makeCurlRequest("POST", $url, json_encode(["grant_type" => "client_credentials", "user_login_id" => 1]), $header);
            $result = json_decode($response, true);
            if ($result) {
                return $result;
            } else {
                return "API Error";
            }
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /* Check Json */
    public function isJson($string, $return_data = false)
    {
        $data = json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE) ? ($return_data ? $data : true) : false;
    }

    /* Header */
    public function MakeHeader($account, $token = NULL)
    {
        if (!empty($account)) {
            $header = [
                "Content-Type: application/json; charset=utf-8",
                "Authorization: Bearer " . $this->mobj->encrypt_decrypt($account->access_token, 'decrypt'),
            ];
        } else {
            $header = [
                "Content-Type: application/json; charset=utf-8",
                "Authorization: Bearer " . $this->mobj->encrypt_decrypt($token, 'decrypt'),
            ];
        }

        return $header;
    }

    public function MakeHeaderFormat($account, $token = NULL)
    {
        if (!empty($account)) {
            $header = [
                "Content-Type" => "application/json; charset=utf-8",
                "Authorization" => "Bearer " . $this->mobj->encrypt_decrypt($account->access_token, 'decrypt'),
            ];
        } else {
            $header = [
                "Content-Type" => "application/json; charset=utf-8",
                "Authorization" => "Bearer " . $this->mobj->encrypt_decrypt($token, 'decrypt'),
            ];
        }

        return $header;
    }

    /* API Call */
    public function CallAPI($account, $method, $url, $postData = [], $type = NULL)
    {
        if ($type) {
            $header = $this->MakeHeader($account);
            $url = "https://secure-wms.com" . $url;
            $response = $this->mobj->makeCurlRequest(strtoupper($method), $url, $postData, $header);
        } else {
            $header = $this->MakeHeaderFormat($account);
            $url = "https://secure-wms.com" . $url;
            $response = $this->mobj->makeRequest(strtoupper($method), $url, $postData, $header);
        }

        return $response;
    }
}
