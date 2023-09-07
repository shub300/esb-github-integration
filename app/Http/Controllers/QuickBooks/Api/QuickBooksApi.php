<?php

namespace App\Http\Controllers\QuickBooks\Api;

use App\Helper\MainModel;

class QuickBooksApi extends MainModel
{
    public static $myPlatform = "quickbooks";
    public static $prototype = "https://";
    public static $sandbox_domain = "sandbox-quickbooks.api.intuit.com";
    public static $live_domain = "quickbooks.api.intuit.com";
    public static $version = "v3";
    public function __construct()
    {
    }

    /* Headers */
    public function makeHeader($account, $access_token = NULL)
    {
        if (!empty($account)) {
            $header = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
                'User-Agent' => $this->userAgents(),
                'Authorization' => 'Bearer ' . $this->encrypt_decrypt($account->access_token, 'decrypt')
            ];
        } else {
            $header = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
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

        $domain = ($account->env_type == "production") ? self::$live_domain : self::$sandbox_domain;
        $baseurl = self::$prototype . $domain . "/" . $version . "/company/" . $account->marketplace_id . "/" . $url;
        //dd($baseurl );
        $response = $this->makeRequest(strtoupper($method), $baseurl, $payload, $header, $api_type);
        $this->refreshTokenIfUnauthorized($response, $account, [$method, $baseurl, $payload, $header, $api_type]);
        return $response;
    }

    /* Refresh Token If 401 return server status (unauthorized) */
    private function refreshTokenIfUnauthorized($response, $account, $params = [])
    {
        if (is_object($response)) {
            $server_status = $response->getStatusCode();
            //If status is 401
            if (401 == $server_status && isset($account->id)) {
                $success_response = app('App\Http\Controllers\QuickBooks\QuickBooksApiController')->refreshToken($account->id); //Call refresh token method

                /* Change array index as params to call api */
                if (isset($success_response['response']) && $success_response['response'] && isset($success_response['account']['access_token'])) {
                    $header = $this->MakeHeader(NULL, $success_response['account']['access_token']);
                    $method = isset($params[0]) ? $params[0] : NULL; //Method Name
                    $baseurl = isset($params[1]) ? $params[1] : NULL; //Base url
                    $payload = isset($params[2]) ? $params[2] : []; //Payload data
                    $headers = !empty($header) ? $header : NULL; //Get Header Data
                    $api_type = isset($params[4]) ? $params[4] : "json"; //Type Json or Array | Default json
                    $response = $this->makeRequest($method, $baseurl, $payload, $headers, $api_type);
                }
            }
        } else {
            \Storage::disk('local')->append('api_error.txt', "Account Name: " . isset($account->account_name) ? $account->account_name : "Not Avail" . " Response: " . print_r($response, true));
        }

        return $response;
    }

    public function APICALL($account, $method, $url, $arguments = [], $payload = [], $version = "v3", $api_type = "json")
    {
        try {
            if (!empty($arguments)) {
                $arguments['sparse'] = true;
                if(isset($arguments['minorversion'])){
                    $arguments['minorversion']=$arguments['minorversion'];
                }else{
                    $arguments['minorversion'] = 65;
                }
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

    /* Handle Response Errors */
    public function handleResponseError($serverResponse, $type = NULL)
    {
        $errors_list = null;
        $response = $serverResponse['body'];
        if (is_null($type)) {
            if (isset($response['error_description'])) {
                $errors_list = $response['error_description'];
            } elseif (isset($response['error'])) {
                if (is_string($response['error'])) {
                    $errors_list = $response['error'];
                }
            } elseif (isset($response['Fault']['Error'])) {
                $errors_list = isset($response['Fault']['Error'][0]['Detail']) ? $response['Fault']['Error'][0]['Detail'] : null;
                if (empty($errors_list)) {
                    $errors_list = isset($response['Fault']['Error'][0]['Message']) ? $response['Fault']['Error'][0]['Message'] : null;
                }
            } elseif (isset($response['fault']['error'])) {
                $errors_list = isset($response['fault']['error'][0]['detail']) ? $response['fault']['error'][0]['detail'] : null;
                if (empty($errors_list)) {
                    $errors_list = isset($response['fault']['error'][0]['message']) ? $response['fault']['error'][0]['message'] : null;
                }
            } else {
                $errors_list = $response['reason'];
            }
        } else {
        }

        return rtrim($errors_list, ",");
    }

    /* Handle Batch Response Errors */
    public function handleBatchResponseError($response)
    {
        try {
            $errors_list = null;
            if (isset($response['error_description'])) {
                $errors_list = $response['error_description'];
            } elseif (isset($response['error'])) {
                if (is_string($response['error'])) {
                    $errors_list = $response['error'];
                }
            } elseif (isset($response['Fault']['Error'])) {
                $errors_list = isset($response['Fault']['Error'][0]['Detail']) ? $response['Fault']['Error'][0]['Detail'] : null;
                if (empty($errors_list)) {
                    $errors_list = isset($response['Fault']['Error'][0]['Message']) ? $response['Fault']['Error'][0]['Message'] : null;
                }
            } elseif (isset($response['fault']['error'])) {
                $errors_list = isset($response['fault']['error'][0]['detail']) ? $response['fault']['error'][0]['detail'] : null;
                if (empty($errors_list)) {
                    $errors_list = isset($response['fault']['error'][0]['message']) ? $response['fault']['error'][0]['message'] : null;
                }
            } else {
                $errors_list = $response['reason'];
            }
        } catch (\Exception $e) {
            \Log::error('QuickBooksApiController -> handleBatchResponseError -> ' . json_encode($response));
        }

        return rtrim($errors_list, ",");
    }

    /* Get Products List */
    public function productList($account, $arguments)
    {
        return $this->APICALL($account, "GET", "query", $arguments);
    }

    /* Get Vendor List */
    public function vendorList($account, $arguments)
    {
        return $this->APICALL($account, "GET", "query", $arguments);
    }

    /* Get Customer List */
    public function customerList($account, $arguments)
    {
        return $this->APICALL($account, "GET", "query", $arguments);
    }

    public function getProductById($account, $productId)
    {
        $arguments = [
            "minorversion" => 65,
        ];

        return $this->APICALL($account, "GET", "item/{$productId}", $arguments);
    }
}
