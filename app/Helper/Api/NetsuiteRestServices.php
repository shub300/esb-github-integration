<?php

namespace App\Helper\Api;

use App\Helper\MainModel;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use NetSuite\NetSuiteClient;
use OAuth;

class NetsuiteRestServices
{
//    protected $headers;
    private $mainModel;
    private $accountName;
    private $consumerKey;
    private $consumerSecret;
    private $tokenSecret;
    private $token;
    private $nonce;
    private $baseUrl;
    private $serviceUrl;
//    protected $timestamp;

    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_DELETE = 'DELETE';
    const SIGNATURE_METHOD = 'HMAC-SHA256';
    const VERSION = '1.0';


    public function __construct($platformAccountId) {
       $this->mainModel = new MainModel();
       $nsCreds = DB::table('platform_accounts')->where('id','=',$platformAccountId)->where('platform_id','=', 7)->first();
      
       if($nsCreds) {
           $this->nonce = date('U');
           $this->accountName = $nsCreds->account_name;
           $this->consumerKey = $this->mainModel->encrypt_decrypt($nsCreds->app_id, 'decrypt');
           $this->consumerSecret = $this->mainModel->encrypt_decrypt($nsCreds->app_secret, 'decrypt');
           $this->tokenSecret = $this->mainModel->encrypt_decrypt($nsCreds->access_token, 'decrypt');
           $this->token = $this->mainModel->encrypt_decrypt($nsCreds->refresh_token, 'decrypt');
           $this->baseUrl = "https://".$nsCreds->account_name.".suitetalk.api.netsuite.com";          
       }
    }



    public function getDiscountItems() {
        return $this->getRequestByServiceUrl("/services/rest/record/v1/discountItem");
    }

    public function getDiscountItemById($id) {
        return $this->getRequestByServiceUrl("/services/rest/record/v1/discountItem/".$id);
    }

    public function getShippingItems() {
        return $this->getRequestByServiceUrl("/services/rest/record/v1/shipItem");
    }
    
    public function getShippingItemById($id) {
        return $this->getRequestByServiceUrl("/services/rest/record/v1/shipItem/".$id);
    }

    public function receiveInboundShipment($data) {
        return $this->postRequestByServiceUrl('/services/rest/record/v1/receiveInboundShipment', $data);
    }

    public function receiveInboundShipmentTemp() {
        return $this->postRequestByServiceUrlTemp('/services/rest/record/v1/receiveinboundshipment/33/receiveItems');
    }
   

    private function getRequestByServiceUrl($serviceUrl) {
        try {
            $this->serviceUrl = $serviceUrl;
            return json_decode($this->getRequest());
        } catch (\Exception $ex) {
            Log::info("Error making get request for url ".$serviceUrl." : ".$ex->getMessage()." at ".$ex->getLine());
            return json_decode("{}");
        }
    }

    private function postRequestByServiceUrl($serviceUrl, $postData) {
        try {
            $this->serviceUrl = $serviceUrl;
            return json_decode($this->postRequest($postData));
        } catch (\Exception $ex) {
            Log::info("Error making post request for url ".$serviceUrl." : ".$ex->getMessage()." at ".$ex->getLine());
            return json_decode("{}");
        }
    }

    private function postRequestByServiceUrlTemp ($serviceUrl) {
        try {
            $this->serviceUrl = $serviceUrl;
            return json_decode($this->getRequestTemp());
        } catch (\Exception $ex) {
            Log::info("Error making post request for url ".$serviceUrl." : ".$ex->getMessage()." at ".$ex->getLine());
            return json_decode("{}");
        }
    }

    private function getRequest() {
        $stack = HandlerStack::create();
        $middleware = new Oauth1(['consumer_key' => $this->consumerKey, 'consumer_secret' => $this->consumerSecret,
            'signature_method'  => self::SIGNATURE_METHOD, 'token' => $this->token, 'token_secret' => $this->tokenSecret,
            'realm'=> $this->accountName
        ]);
        $stack->push($middleware);
        $client = new Client([
            'base_uri' => $this->baseUrl,
            'handler' => $stack
        ]);   
       
        $res = $client->get($this->serviceUrl, ['auth' => 'oauth']);
        return $res->getBody()->getContents();
    }

    private function postRequest($postData) {
//        dd("test2");
        $stack = HandlerStack::create();
        $middleware = new Oauth1(['consumer_key' => $this->consumerKey, 'consumer_secret' => $this->consumerSecret,
            'signature_method'  => self::SIGNATURE_METHOD, 'token' => $this->token, 'token_secret' => $this->tokenSecret,
            'realm'=> $this->accountName
        ]);
        $stack->push($middleware);
        $client = new Client([
            'base_uri' => $this->baseUrl,
            'handler' => $stack
        ]);
//        dd("test4");
        try {
            $res = $client->post($this->serviceUrl,['auth' => 'oauth', 'json' => json_encode($postData)]);
            dd($res);
        } catch (\Exception $ex) {
            print_r($ex->getTraceAsString());
            dd($ex->getMessage());
        }


        return $res->getBody()->getContents();
    }

    private function getRequestTemp() {
//        dd("test2");
        $stack = HandlerStack::create();
        $middleware = new Oauth1(['consumer_key' => $this->consumerKey, 'consumer_secret' => $this->consumerSecret,
            'signature_method'  => self::SIGNATURE_METHOD, 'token' => $this->token, 'token_secret' => $this->tokenSecret,
            'realm'=> $this->accountName
        ]);
        $stack->push($middleware);
        $client = new Client([
            'base_uri' => $this->baseUrl,
            'handler' => $stack
        ]);
//        dd("test4");
        try {
            $res = $client->get($this->serviceUrl,['auth' => 'oauth']);
            dd($res->getBody()->getContents());
        } catch (\Exception $ex) {
            print_r($ex->getTraceAsString());
            dd($ex->getMessage());
        }


        return $res->getBody()->getContents();
    }
}
