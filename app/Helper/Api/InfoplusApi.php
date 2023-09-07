<?php

namespace App\Helper\Api;

use DB;
use Auth;
use Mail;
use App\Helper\ConnectionHelper;
use App\Helper\MainModel;
use Exception;
use Illuminate\Database\Eloquent\Model;

class InfoplusApi extends Model
{
    public $mobj, $myPlatform;
    public static $prototype = "https://";
    public static $apipart = "infoplus-wms/api";
    public static $version = ["v2.0", "beta"];
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->myPlatform = "infoplus";
    }
    /* Headers */
    public function MakeHeader($account, $access_key = NULL)
    {
        if (!empty($account)) {
            $header = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'API-Key' => $this->mobj->encrypt_decrypt($account->access_key, 'decrypt')
            ];
        } else {
            $header = ['API-Key' => $this->mobj->encrypt_decrypt($access_key, 'decrypt')];
        }
        return $header;
    }
    /* API Call */
    public function CallAPI($account, $method, $url, $payload = [], $version = "v2.0", $json = "json")
    {

        $header = $this->MakeHeader($account);

        $baseurl = self::$prototype . $account->api_domain . "/" . self::$apipart . "/" . $version . "/" . $url;

        $response = $this->mobj->makeRequest(strtoupper($method), $baseurl, $payload, $header, $json);

        return $response;
    }
    /* Check  credentials */
    public function CheckCredentials($access_key, $api_domain)
    {
        try {
            $method = "GET";
            $url = self::$prototype . $api_domain . "/" . self::$version . "/customer/search?page=1&limit=1";
            $header = ['API-Key' => $this->mobj->encrypt_decrypt($access_key, 'decrypt')];
            $response = $this->mobj->makeRequest($method, $url, [], $header, 'json');
            $status = $response->getStatusCode();
            if ($status == 200) {
                return true;
            } else {
                return false;
            }
        } catch (Exception $e) {

            return $e->getMessage();
        }
    }
    public function _API_CALL($account, $method, $url, $arguments = [], $payload = [], $version = "v2.0", $json = "json")
    {

        try {
            if (!empty($arguments)) {
                $query = http_build_query($arguments);
                $baseUrl = $url . '?' . $query;
            } else {
                $baseUrl = $url;
            }
            $server_response = $this->CallAPI($account, strtoupper($method), $baseUrl, $payload, $version, $json);
            return $this->getResponse($server_response);
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }
    private function getResponse($server_response)
    {
        return [
            'status_code' => $server_response->getStatusCode(),
            'body' => json_decode($server_response->getBody(), true),
            'reason' => $server_response->getReasonPhrase()
        ];
    }
    public function checkStringQuotes($string) {
        if (strpos($string, "'") !== false) {
            return "single";
        } elseif (strpos($string, '"') !== false) {
            return "double";
        } else {
            return null;
        }
    }
}
