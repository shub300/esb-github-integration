<?php

namespace App\Http\Controllers\Netsuite\Api;

use DB;
use Auth;
use Mail;
use App\Helper\ConnectionHelper;
use App\Helper\MainModel;
use Exception;
use Illuminate\Database\Eloquent\Model;

class NetsuiteRestApi extends MainModel
{
    public static $myPlatform = "netsuiteerp";
    public static $prototype = "https://";
    public static $basedomain = "suitetalk.api.netsuite.com";
    public static $subQurey = "/services/rest/query/v1/suiteql";
    public static $subRecord = "/services/rest/record/v1/";
    public static $oauth_signature_method = 'HMAC-SHA256';
    public static $oauth_version = "1.0";

    public function __construct()
    {
    }

    /* Headers */
    public function makeHeader($headers)
    {

        return [
            'Prefer' => 'transient',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Accept-Encoding' => 'gzip',
            'User-Agent' => $this->userAgents(),
            'Authorization' => "OAuth ".$headers
        ];
    }
    public function generateOauthHeader($params)
    {
        foreach ($params as $k => $v) {

            $oauthParamArray[] = $k . '="' . rawurlencode($v) . '"';
        }
        $oauthHeader = implode(', ', $oauthParamArray);

        return $oauthHeader;
    }

    public function createSignature($httpRequestMethod, $url, $params, $tokenSecret ,$consumerSecret)
    {

        $strParams = rawurlencode(http_build_query($params));
        $baseString = ucwords($httpRequestMethod) . "&" . rawurlencode($url) . "&" . $strParams;
        $signKey = $this->generateSignatureKey($tokenSecret,$consumerSecret);
        $oauthSignature = base64_encode(hash_hmac('sha256', $baseString, $signKey, true));
       
        return $oauthSignature;
    }

    public function generateSignatureKey($tokenSecret,$consumerSecret)
    {
        $signKey = rawurlencode($consumerSecret) . "&";
        if (!empty($tokenSecret)) {
            $signKey = $signKey . rawurlencode($tokenSecret);
        }
        return $signKey;
    }
    
   
    /* API Call */
    public function CallAPI($account, $method, $url,$arguments, $payload=[],$api_type)
    {
        if($api_type=="query"){
            $subUrl=self::$subQurey;
            
        }else{
            $subUrl=self::$subRecord;
            
        }
        $base = self::$prototype . strtolower($account->account_name) . '.' . self::$basedomain.$subUrl.$url;

     
        if (!empty($arguments)) {
            $query = http_build_query($arguments);
            $baseurl = $base . '?' . $query;
        } else {
            $baseurl = $base;
        }
        $consumerKey = $this->encrypt_decrypt($account->app_id, 'decrypt');
        $token = $this->encrypt_decrypt($account->refresh_token, 'decrypt');
        $consumerSecret = $this->encrypt_decrypt($account->app_secret, 'decrypt');
        $tokenSecret = $this->encrypt_decrypt($account->access_token, 'decrypt');
        $oauthTimestamp = time();
        $oauthNonce = md5(mt_rand());
        $oauth_params = [
            "oauth_consumer_key" =>$consumerKey,
            "oauth_nonce" => $oauthNonce,
            "oauth_signature_method" => self::$oauth_signature_method,
            "oauth_timestamp" => $oauthTimestamp,
            "oauth_token" => $token,
            "oauth_version" => self::$oauth_version
        ];
        if (!empty($arguments)) {
            $params = array_merge($oauth_params, $arguments);
        } else {

            $params = array_merge($oauth_params);
        }
        ksort($params);
        $signature = $this->createSignature($method, $base, $params, $tokenSecret,$consumerSecret);
        $oauth_params['oauth_signature']= $signature;
        $oauth_params['realm']= str_replace("-", "_", $account->account_name);
        $oauthHeader =$this->generateOauthHeader($oauth_params);
        $headers=$this->makeHeader($oauthHeader);
        $response = $this->makeRequest(strtoupper($method), $baseurl, $payload, $headers, "json");
        if(!$response){
           return ['status_code' => 404,
            'body' => null,
            'reason' => "Internal Server Error: Url not found"];
        }
        return $this->getResponse($response);
    }
    /* Verify Account */
   
    public function verifyAccount($account = [])
    { 
        $method = $account['method'];
        if($account['type']=="query"){
            $subUrl=self::$subQurey;
        }else{
            $subUrl=self::$subRecord;
        }
        $url = self::$prototype .strtolower($account['account_name']).'.' . self::$basedomain.$subUrl.$account['url'];
        $arguments=["limit"=>$account['limit'],"offset"=>$account['offset']];
        if (!empty($arguments)) {
            $query = http_build_query($arguments);
            $baseurl = $url . '?' . $query;
        } else {
            $baseurl = $url;
        }
      
        $consumerKey = $account['consumerKey'];
        $token =  $account['token'];
        $consumerSecret = $account['consumerSecret'];
        $tokenSecret = $account['tokenSecret'];
        $oauthTimestamp = time();
        $oauthNonce = md5(mt_rand());
        $oauth_params = [
            "oauth_consumer_key" =>$consumerKey,
            "oauth_nonce" => $oauthNonce,
            "oauth_signature_method" => self::$oauth_signature_method,
            "oauth_timestamp" => $oauthTimestamp,
            "oauth_token" => $token,
            "oauth_version" => self::$oauth_version
        ];
        if (!empty($arguments)) {
            $params = array_merge($oauth_params, $arguments);
        } else {

            $params = array_merge($oauth_params);
        }
        ksort($params);
        $signature = $this->createSignature($method, $url, $params, $tokenSecret,$consumerSecret);
        $oauth_params['oauth_signature']= $signature;
        $oauth_params['realm']= str_replace("-", "_", $account['account_name']);
        $oauthHeader =$this->generateOauthHeader($oauth_params);
        $headers=$this->makeHeader($oauthHeader);
        $response = $this->makeRequest(strtoupper($method), $baseurl, [], $headers, "json");
        if(!$response){
           return ['status_code' => 404,
            'body' => null,
            'reason' => "Internal Server Error: Url not found"];
        }
        return $this->getResponse($response);
    }
    /* Get Vendor List  */
    public function vendorList($account, $url,$arguments, $filters = [],$api_type="record")
    {
        
       
        $filter = null;
        if ($filters) {
            $startDate = $filters['start_date'];
            $filter = "and vdr.datecreated >= '{$startDate}'";
        }

        $payload = [
            "q" => "SELECT vdr.id,vdr.externalid,vdr.legalname,vdr.email,vdr.companyname,vdr.defaultbillingaddress,cur.symbol as currency,vdr.isinactive,vdr.datecreated,vdr.lastmodifieddate FROM Vendor vdr left join Currency cur ON cur.id=vdr.currency WHERE vdr.isinactive='F' {$filter} order by vdr.id ASC"
        ];
       
        return $this->CallAPI($account, "POST", $url,$arguments, $payload,$api_type);
    }
    /* Get SO/PO Line Items */
    public function lineItemsList($account, $url,$arguments, $filters = [],$api_type="record")
    {
        
       
        $filter = null;
        if ($filters) {
            $transactionId = $filters['transactionId'];
            $filter = "transaction = '{$transactionId}'";
        }

        $payload = [
            "q" => "SELECT * FROM TransactionLine {$filter} order by id ASC"
        ];
       
        return $this->CallAPI($account, "POST", $url,$arguments, $payload,$api_type);
    }
     /* Get Vendor Billing Address By Id  */
     public function vendorBillingAddressByIds($account, $ids=null,$api_type="record")
     {    $payload = [
             "q" => "SELECT nkey,addrtext FROM EntityAddress WHERE nkey IN ({$ids}) ORDER BY nkey ASC"
         ];
         return $this->CallAPI($account, "POST", NULL,[], $payload,$api_type);
     }
    /* Get Product List  */
    public function productList($account, $url,$arguments, $filters = [],$api_type="record")
    {
        $filter = null;
        if ($filters) {
            $startDate = $filters['start_date'];
            $filter = "and vdr.datecreated >= '{$startDate}'";
        }

        $payload = [
            "q" => "SELECT * FROM Item WHERE isinactive='F' {$filter} ORDER BY id ASC"
        ];
       
        return $this->CallAPI($account, "POST", $url,$arguments, $payload,$api_type);
    }
     /* Get Sales Order List  */
     public function salesOrderList($account, $url,$arguments, $filters = [],$api_type="record")
     {
         $filter = null;
         if ($filters) {
             $startDate = $filters['start_date'];
             $filter = "and vdr.datecreated >= '{$startDate}'";
         }
 
         $payload = [
             "q" => "SELECT * FROM Item WHERE isinactive='F' {$filter} ORDER BY id ASC"
         ];
        
         return $this->CallAPI($account, "POST", $url,$arguments, $payload,$api_type);
     }
    private function getResponse($server_response)
    {
        return [
            'status_code' => $server_response->getStatusCode(),
            'body' => json_decode($server_response->getBody(), true),
            'reason' => $server_response->getReasonPhrase()
        ];
    }
    /* Handle Erros */
    public function handleErrorResponse($response)
    {
        $errors_list = null;

        if (isset($response['body']['o:errorDetails'])) {
            if (is_array($response['body']['o:errorDetails'])) {
                foreach ($response['body']['o:errorDetails'] as $arr) {
                    $errors_list = $arr['detail'] . ", ";
                }
            }
        } else if (isset($response['reason'])) {
            $errors_list =  $response['reason'];
        } else {
            $errors_list = "Internal API Error";
        }
        return $errors_list;
    }
}
