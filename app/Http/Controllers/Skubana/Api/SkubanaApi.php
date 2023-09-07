<?php

namespace App\Http\Controllers\Skubana\Api;

use App\Helper\MainModel;

class SkubanaApi extends MainModel
{
    public static $myPlatform = "skubana";
    public static $prototype = "https://";
    public static $domain = "skubana.com";
    public static $version = "v1.1";

    public function __construct()
    {
    }

    /* Headers */
    public function makeHeader($account, $access_token = NULL)
    {
        if (!empty($account)) {
            $header = [
                'Content-Type' => 'application/json',
                'User-Agent' => $this->userAgents(),
                'Authorization' => 'Bearer ' . $this->encrypt_decrypt($account->access_token, 'decrypt')
            ];
        } else {
            $header = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->encrypt_decrypt($access_token, 'decrypt'),
                'User-Agent' => $this->userAgents()
            ];
        }
        return $header;
    }

    /* API Call */
    public function BASEAPI($account, $method, $url, $payload, $version, $api_type)
    {

        $header = $this->makeHeader($account);
        $version = isset($version) ? $version : self::$version;
        $domain = $account->env_type == "sandbox" ? "api.demo" : "api";
        $baseUrl = self::$prototype . $domain . '.' . self::$domain . "/" . $version .  "/" . $url;
        //dd($baseUrl);
        $response = $this->makeRequest(strtoupper($method), $baseUrl, $payload, $header, $api_type);
        return $response;
    }

    public function APICALL($account, $method, $url, $arguments = [], $payload = [], $version = null, $api_type = "json")
    {
        try {
            if (!empty($arguments)) {
                $query = http_build_query($arguments, "", "&");
                $baseUrl = $url . '?' . $query;
            } else {
                $baseUrl = $url;
            }
            $server_response = $this->BASEAPI($account, $method, $baseUrl, $payload, $version, $api_type);

            return $this->getResponse($server_response);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function getResponse($server_response)
    {
        return [
            'status_code' => $server_response->getStatusCode(),
            'body' => json_decode($server_response->getBody(), true),
            'reason' => $server_response->getReasonPhrase()
        ];
    }

    /* Handle Brightpearl Response Errors */
    public function handleResponseError($serverResponse, $type = NULL)
    {
        $errors_list = null;
        $response = $serverResponse['body'];
        if (is_null($type)) {
            if (isset($response['error_description'])) {
                $errors_list = $response['error_description'];
            } elseif (isset($response['error'])) {
                if (is_array($response['error'])) {
                    $errors_list = implode(",", $response['error']);
                } else if ($response['error']) {
                    $errors_list = $response['error'];
                }
            } elseif (isset($response['Fault']['Error'][0])) {
                $errors_list = $response['Fault']['Error'][0]['Message'];
            }
        } else {
        }

        return rtrim($errors_list, ",");
    }

    /* Get Products List  */
    public function productList($account,  $arguments)
    {
        return $this->APICALL($account, "GET", "products", $arguments);
    }
    /* get product by Sku/ProductId */
    public function productByFilter($account, $productIdentiy,$productColomn)
    {
        $arguments = [
            $productColomn=> $productIdentiy,
        ];
        return $this->APICALL($account, "GET", "products", $arguments);
    }

    /* Get Vendor List  */
    public function vendorList($account,  $arguments)
    {
        return $this->APICALL($account, "GET", "vendors", $arguments, [], "v1");
    }
}
