<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use DateTime;

use function GuzzleHttp\json_decode;

class AmazonApi
{

    public $mobj, $helper, $myPlatform;
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->helper = new ConnectionHelper;
        $this->myPlatform = "amazon";
    }
    public function getAmazonAccessToken($code, $client_id, $client_secret, $redirect_url)
    {
        try{
            $postData = array('grant_type'=>'authorization_code', 'code'=>$code, 'client_id'=>$client_id, 'client_secret'=>$client_secret, 'redirect_url'=>$redirect_url);

            $header = array('Content-Type'=>'application/x-www-form-urlencoded');
            $url = 'https://api.amazon.com/auth/o2/token';

            $response = $this->mobj->makeRequest('POST', $url, $postData, $header, 'http');
            return $response;
        }
        catch(\Exception $e)
        {
            \Log::error('AmazonApi --> getAmazonAccessToken --> '.$e->getMessage());
            return $e->getMessage();
        }
    }
    public function getAmazonRefreshToken($refresh_token, $client_id, $client_secret)
    {
        try {
            $response = false;
            $postData = array('grant_type'=>'refresh_token', 'refresh_token'=>$refresh_token, 'client_id'=>$client_id, 'client_secret'=>$client_secret);
            $header = array('Content-Type'=>'application/x-www-form-urlencoded');
            $url = 'https://api.amazon.com/auth/o2/token';

            $response = $this->mobj->makeRequest('POST', $url, $postData, $header, 'http');
            return $response;
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
        }
    }
    public function getAssumeRole($accessKey, $secretKey, $roleArn, $Region)
    {
        $durationSeconds = 3600;
        $host = 'sts.' . $Region . '.amazonaws.com';
        $uri = '/';
        $method = 'POST';

        $requestOptions = [
            'headers' => ['accept' => 'application/json'],
            'form_params' => ['Action' => 'AssumeRole', 'DurationSeconds' => $durationSeconds, 'RoleArn' => $roleArn, 'RoleSessionName' => 'amazon-sp-api-php', 'Version' => '2011-06-15']
        ];

        $data = http_build_query($requestOptions['form_params']);

        $userAgent = 'cs-php-sp-api-client/2.1';
        $service = 'sts';
        $queryString = '';
        $terminationString = 'aws4_request';
        $algorithm = 'AWS4-HMAC-SHA256';
        $amzdate = gmdate('Ymd\THis\Z');
        $date = substr($amzdate, 0, 8);

        //Prepare payload
        if (is_array($data)) {
            $param = json_encode($data);
            if ('[]' == $param) {
                $requestPayload = '';
            } else {
                $requestPayload = $param;
            }
        } else {
            $requestPayload = $data;
        }

        //Hashed payload
        $hashedPayload = hash('sha256', $requestPayload);

        //Compute Canonical Headers
        $canonicalHeaders = ['host' => $host, 'user-agent' => $userAgent];
        $canonicalHeaders['x-amz-date'] = $amzdate;
        $canonicalHeadersStr = '';
        foreach ($canonicalHeaders as $h => $v) {
            $canonicalHeadersStr .= $h . ':' . $v . "\n";
        }

        $signedHeadersStr = join(';', array_keys($canonicalHeaders));
        //Prepare credentials scope
        $credentialScope = $date . '/' . $Region . '/' . $service . '/' . $terminationString;
        //prepare canonical request
        $canonicalRequest = $method . "\n" . $uri . "\n" . $queryString . "\n" . $canonicalHeadersStr . "\n" . $signedHeadersStr . "\n" . $hashedPayload;
        //Prepare the string to sign
        $stringToSign = $algorithm . "\n" . $amzdate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);

        //Start signing locker process
        $kSecret = 'AWS4' . $secretKey;
        $kDate = hash_hmac('sha256', $date, $kSecret, true);
        $kRegion = hash_hmac('sha256', $Region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', $terminationString, $kService, true);

        //Compute the signature
        $signature = trim(hash_hmac('sha256', $stringToSign, $kSigning));
        //Finalize the authorization structure
        $authorizationHeader = $algorithm . " Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeadersStr}, Signature={$signature}";

        $header =  array_merge($canonicalHeaders, ['Authorization' => $authorizationHeader]);
        $header = array_merge($requestOptions['headers'], $header);
        $requestOptions['headers'] = $header;

        //echo "<pre>";
        //print_r($requestOptions);
        // echo $host;
        // exit;
        $client = new \GuzzleHttp\Client(['base_uri' => 'https://' . $host]);

        try {
            $response = $client->post($uri, $requestOptions);

            $json = json_decode($response->getBody(), true);
            // echo "<pre>";
            // print_r($json);
            return $json['AssumeRoleResponse']['AssumeRoleResult']['Credentials'] ?? null;
        } catch (\Exception $e) {
            return "Error : {$e->getMessage()}";
            //return null;
        }
    }
    public function authrizationHeaderAndSignature($host, $access_token, $accessKey, $secretKey, $securityToken, $uri, $method, $terminationString, $algorithm, $amzdate ,$date, $region, $service, $queryString){

            // $host = 'sellingpartnerapi-na.amazon.com';

            $hashedPayload = hash('sha256', '');
            $canonicalHeaders = ['host' => $host];
            $canonicalHeaders['x-amz-access-token'] = $access_token;
            $canonicalHeaders['x-amz-date'] = $amzdate;
            $canonicalHeaders['x-amz-security-token'] = $securityToken;
            $canonicalHeadersStr = '';
            foreach ($canonicalHeaders as $h => $v) {
                $canonicalHeadersStr .= $h . ':' . $v . "\n";
            }

            $signedHeadersStr = join(';', array_keys($canonicalHeaders));
            //Prepare credentials scope
            $credentialScope = $date . '/' . $region . '/' . $service . '/' . $terminationString;
            //prepare canonical request
            $canonicalRequest = $method . "\n" . $uri . "\n" . $queryString . "\n" . $canonicalHeadersStr . "\n" . $signedHeadersStr . "\n" . $hashedPayload;
            //Prepare the string to sign
            $stringToSign = $algorithm . "\n" . $amzdate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);

            //Start signing locker process
            $kSecret = 'AWS4' . $secretKey;
            $kDate = hash_hmac('sha256', $date, $kSecret, true);
            $kRegion = hash_hmac('sha256', $region, $kDate, true);
            $kService = hash_hmac('sha256', $service, $kRegion, true);
            $kSigning = hash_hmac('sha256', $terminationString, $kService, true);

            //Compute the signature
            $signature = trim(hash_hmac('sha256', $stringToSign, $kSigning));

            //Finalize the authorization structure
           $authorizationHeader = $algorithm . " Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeadersStr}, Signature={$signature}";
           return  $authorizationHeader;
    }
    /* Get Token By User ID */
    public function getTokenByUserID($userId)
    {
        $platformId = $this->helper->getPlatformIdByName($this->myPlatform);
        $findApp = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' =>  $platformId]);
        if ($findApp &&  $platformId) {
            $accDetail = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $platformId, 'user_id' => $userId]);
            if ($accDetail) {

                return ['access_token' => $this->mobj->encrypt_decrypt($accDetail->access_token,'decrypt'), 'dev_ref' => $this->mobj->encrypt_decrypt($findApp->client_id,'decrypt'), 'app_ref' => $this->mobj->encrypt_decrypt($findApp->app_ref,'decrypt'), 'api_domain' => $accDetail->api_domain, 'app_id' => $accDetail->app_id, 'refresh_token' => $accDetail->refresh_token, 'env_type' => $accDetail->env_type];
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
    //formate queryString
    public function formateQueryString($params)
    {
        $url_parts = array();
        foreach(array_keys($params) as $key)
        $url_parts[] = $key . "=" . str_replace('%7E', '~', rawurlencode($params[$key]));
        sort($url_parts);
    
        // Construct the string to sign
        return $queryString = implode("&", $url_parts);

    }


    //call spApiPostCall for post method api call with post data
    public function spApiPostCall($account, $method='POST', $uri, $queryString, $postData)
    {   
        $platform_id = $this->helper->getPlatformIdByName('amazonvendor');
        $platform_api_app = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id'=>$platform_id], ['access_key', 'secret_key','role_arn']);

        // $host = 'sellingpartnerapi-na.amazon.com';
        $host = $account->api_domain;

        $marketplace_id = $this->mobj->encrypt_decrypt($account->marketplace_id,'decrypt');

        //old account
        if($account->app_id && $account->app_secret) {
            $access_key = $this->mobj->encrypt_decrypt($account->access_key,'decrypt');
            $secret_key = $this->mobj->encrypt_decrypt($account->secret_key,'decrypt');
            $role_arn = $this->mobj->encrypt_decrypt($account->role_arn,'decrypt');
        } else {
            $access_key = $this->mobj->encrypt_decrypt($platform_api_app->access_key,'decrypt');
            $secret_key = $this->mobj->encrypt_decrypt($platform_api_app->secret_key,'decrypt');
            $role_arn = $this->mobj->encrypt_decrypt($platform_api_app->role_arn,'decrypt');
        }
            

        $region = $account->region;
        $access_token = $this->mobj->encrypt_decrypt($account->access_token,'decrypt');
        $AssumeRoleCredentials = $this->getAssumeRole($access_key, $secret_key, $role_arn, $region);

        if (isset($AssumeRoleCredentials['SessionToken'])) {

            $accessKey = $AssumeRoleCredentials['AccessKeyId'];
            $secretKey = $AssumeRoleCredentials['SecretAccessKey'];
            $securityToken = $AssumeRoleCredentials['SessionToken'];


            $terminationString = 'aws4_request';
            $algorithm = 'AWS4-HMAC-SHA256';
            $amzdate = gmdate('Ymd\THis\Z');
            $date = substr($amzdate, 0, 8);
            $userAgent = 'PostmanRuntime/7.26.10';
            $service = 'execute-api';


            //Hashed payload
            $hashedPayload = hash('sha256', $postData);
            //Compute Canonical Headers
            $canonicalHeaders = ['host' => $host];
            //Check and attach access token to request header.
            $canonicalHeaders['x-amz-access-token'] = $access_token;
            $canonicalHeaders['x-amz-date'] = $amzdate;
            //Check and attach STS token to request header.
            $canonicalHeaders['x-amz-security-token'] = $securityToken;
            $canonicalHeadersStr = '';
            foreach ($canonicalHeaders as $h => $v) {
                $canonicalHeadersStr .= $h . ':' . $v . "\n";
            }
            $signedHeadersStr = join(';', array_keys($canonicalHeaders));
            //Prepare credentials scope
            $credentialScope = $date . '/' . $region . '/' . $service . '/' . $terminationString;
            //prepare canonical request
            $canonicalRequest = $method . "\n" . $uri . "\n" . $queryString . "\n" . $canonicalHeadersStr . "\n" . $signedHeadersStr . "\n" . $hashedPayload;
            //Prepare the string to sign
            $stringToSign = $algorithm . "\n" . $amzdate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);
            //Start signing locker process
            $kSecret = 'AWS4' . $secretKey;
            $kDate = hash_hmac('sha256', $date, $kSecret, true);
            $kRegion = hash_hmac('sha256', $region, $kDate, true);
            $kService = hash_hmac('sha256', $service, $kRegion, true);
            $kSigning = hash_hmac('sha256', $terminationString, $kService, true);

            //Compute the signature
            $signature = trim(hash_hmac('sha256', $stringToSign, $kSigning));
            //Finalize the authorization structure
            $authorizationHeader = $algorithm . " Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeadersStr}, Signature={$signature}";

            $orderRequestOptions['headers'] = array('x-amz-access-token' => $access_token, 'x-amz-security-token' => $securityToken, 'User-Agent' => 'PostmanRuntime/7.26.10', 'Host' => $host, 'X-Amz-Date' => $amzdate, 'Authorization' => $authorizationHeader);

            $orderRequestOptions['body'] = $postData;

            try {

                $client = new \GuzzleHttp\Client();
                $api_response = $client->post('https://'.$host.$uri.'?MarketplaceId='.$marketplace_id, $orderRequestOptions);
                return $api_results = json_decode($api_response->getBody()->getContents(),true);

            } catch (\Exception $e) {

                $order_response = $e->getMessage();
                
                /* if 401 unauthorized */
                if( $e->getCode() == 401 || $e->getCode() == 403) {
                    $order_response = $this->RefreshTokenIfUnauthorized('POST',$order_response, $account, $host,$uri,$queryString,$orderRequestOptions,$marketplace_id);
                }

                return $order_response;
            }
        
            
        }else{
            return 'Session Token Generation Error';
        }


    }
    //call amazon spi api's
    public function spApiCall($account, $method, $uri, $queryString)
    {
        $platform_id = $this->helper->getPlatformIdByName('amazonvendor');
        $platform_api_app = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id'=>$platform_id], ['access_key', 'secret_key','role_arn']);


        // $host = 'sellingpartnerapi-na.amazon.com';
        $host = $account->api_domain;

        $marketplace_id = $this->mobj->encrypt_decrypt($account->marketplace_id,'decrypt');

         //old account
         if($account->app_id && $account->app_secret) {
            $access_key = $this->mobj->encrypt_decrypt($account->access_key,'decrypt');
            $secret_key = $this->mobj->encrypt_decrypt($account->secret_key,'decrypt');
            $role_arn = $this->mobj->encrypt_decrypt($account->role_arn,'decrypt');
        } else {
            $access_key = $this->mobj->encrypt_decrypt($platform_api_app->access_key,'decrypt');
            $secret_key = $this->mobj->encrypt_decrypt($platform_api_app->secret_key,'decrypt');
            $role_arn = $this->mobj->encrypt_decrypt($platform_api_app->role_arn,'decrypt');
        }

        $region = $account->region;
        $access_token = $this->mobj->encrypt_decrypt($account->access_token,'decrypt');


        $AssumeRoleCredentials = $this->getAssumeRole($access_key, $secret_key, $role_arn, $region);

        if (isset($AssumeRoleCredentials['SessionToken'])) {

            $accessKey = $AssumeRoleCredentials['AccessKeyId'];
            $secretKey = $AssumeRoleCredentials['SecretAccessKey'];
            $securityToken = $AssumeRoleCredentials['SessionToken'];

            $terminationString = 'aws4_request';
            $algorithm = 'AWS4-HMAC-SHA256';
            $amzdate = gmdate('Ymd\THis\Z');
            $date = substr($amzdate, 0, 8);
            $userAgent = 'PostmanRuntime/7.26.10';
            $service = 'execute-api';

            //generate authorized signature
            $authorizationHeader = $this->authrizationHeaderAndSignature($host, $access_token, $accessKey, $secretKey, $securityToken, $uri, $method, $terminationString, $algorithm, $amzdate ,$date, $region, $service, $queryString);

            $client = new \GuzzleHttp\Client();

            //prepare header
            $orderRequestOptions['headers'] = array('x-amz-access-token' => $access_token, 'x-amz-security-token' => $securityToken, 'User-Agent' => $userAgent, 'Host' => $host, 'X-Amz-Date' => $amzdate, 'Authorization' => $authorizationHeader);
            try {
                $order_response = $client->get('https://'.$host.$uri.'?'.$queryString, $orderRequestOptions);
                $order_results = json_decode($order_response->getBody()->getContents(),true);
                return $order_results;

            } catch (\Exception $e) {
                
                $order_response = $e->getMessage();
                
                /* if 401 unauthorized */
                if( $e->getCode() == 401 || $e->getCode() == 403) {
                    $order_response = $this->RefreshTokenIfUnauthorized('GET',$order_response, $account, $host,$uri,$queryString,$orderRequestOptions,$marketplace_id);
                }

                return $order_response;
            }

        }else{
            return 'Session Token Generation Error';
        }

        


    }

    /* Refresh Token If 401 return server status (unauthorized) */
    public function RefreshTokenIfUnauthorized($method,$response, $account, $host,$uri,$queryString,$orderRequestOptions,$marketplace_id)
	{

		if ( isset($account->id) && isset($account->user_id) ) {

			$success_response = app('App\Http\Controllers\Amazon\AmazonApiController')->refreshTokens($account->id,$account->user_id,'amazonvendor');

			if (isset($success_response) && isset($success_response['access_token'])) {

                try {

                    $client = new \GuzzleHttp\Client();
        
                    if($method=="POST") {
                        $order_response = $client->post('https://'.$host.$uri.'?MarketplaceId='.$marketplace_id, $orderRequestOptions);
                    } else {
                        $order_response = $client->get('https://'.$host.$uri.'?'.$queryString, $orderRequestOptions);
                    }
                     
                    $response = json_decode($order_response->getBody()->getContents(),true);
                    return $response;

                } catch (\Exception $e) {

                    $response = $e->getMessage();
                    return $response;

                }   

			}

		}
		
	}



    //Tested common function for get get orders - Retail & Vendor
    public function GetRetailAndVenderOrders($amazon,$next_token,$createdAfter,$filter_by_status,$type,$limit=100,$orderNumber=null)
    {
            $method = 'GET';
            $createdBefore = date(DATE_ISO8601, strtotime(date('Y-m-d H:i:s')));
            $marketplace_id = $this->mobj->encrypt_decrypt($amazon->marketplace_id,'decrypt');
            
            if($type=="PURCHASEORDER"){
                
                //purchaseOrderState - New, Acknowledged, Closed
                $uri = '/vendor/orders/v1/purchaseOrders';

                $params = array(
                    'MarketplaceId' => $marketplace_id,
                    'createdAfter' => $createdAfter,
                    'createdBefore' => $createdBefore,
                    'includeDetails' => 'true',
                    'purchaseOrderState' => $filter_by_status,
                    'limit' => $limit,
                    'sortOrder' => 'ASC'
                );

                //if next token receive
                if($next_token && $next_token !='')
                {
                    $params['nextToken'] = $next_token;
                } 


            } else if($type=="SALESORDER"){

                    $uri = '/orders/v1/Orders';

                    $params = array(
                        'MarketplaceId' => $marketplace_id                        
                    );
                
            } else {
                
                if($orderNumber) {
                    $uri = '/vendor/directFulfillment/orders/v1/purchaseOrders/'.$orderNumber;
                    $params = array(
                        'MarketplaceId' => $marketplace_id
                    );
                } else {
                    //status - NEW, SHIPPED, ACCEPTED, CANCELLED
                    $uri = '/vendor/directFulfillment/orders/v1/purchaseOrders';
                    $params = array(
                        'MarketplaceId' => $marketplace_id,
                        'createdAfter' => $createdAfter,
                        'createdBefore' => $createdBefore,
                        'includeDetails' => 'true',
                        'status' => $filter_by_status,
                        'limit' => $limit,
                        'sortOrder' => 'ASC'
                    );
                }
                
                

                if($next_token && $next_token !='')
                {
                    $params['nextToken'] = $next_token;
                } 

            }

            $queryString = $this->formateQueryString($params);

            return $response = $this->spApiCall($amazon, $method, $uri, $queryString);

    }
    //Send schnowledgement to amazon for directFullfilmentOrders
    public function pushAcknowledgement ($account,$postData,$type)
    {
        $method = 'POST';
        $marketplace_ids = $this->mobj->encrypt_decrypt($account->marketplace_id,'decrypt');

        if($type=="PURCHASEORDERACKNOWLEDGEMENT"){

            $uri = '/vendor/orders/v1/acknowledgements';
            $params = array(
                'MarketplaceId'=> $marketplace_ids
            );
            
        } else {

            $uri = '/vendor/directFulfillment/orders/v1/acknowledgements';
            $params = array(
                'MarketplaceId'=> $marketplace_ids
            );
        }

        $queryString = $this->formateQueryString($params);


		return $data = $this->spApiPostCall($account, $method, $uri, $queryString, $postData);


    }
    //shipment push to amazon for directFullfilmentOrders payment
    public function pushInvoice($account,$postData,$type)
    {
        $method = 'POST';
        $marketplace_ids = $this->mobj->encrypt_decrypt($account->marketplace_id,'decrypt');

        if($type=="INVOICE"){
            $uri = '/vendor/payments/v1/invoices';
            $params = array(
                'MarketplaceId'=> $marketplace_ids
            );
        } else {
            $uri = '/vendor/directFulfillment/payments/v1/invoices';
            $params = array(
                'MarketplaceId'=> $marketplace_ids
            );
        }
		
        $queryString = $this->formateQueryString($params);


		return $data = $this->spApiPostCall($account, $method, $uri, $queryString, $postData);

       

    }
    //shipment push is only for SO (direct fullfillment order) shipping
    public function pushShipment($account,$postData)
    {
        $method = 'POST';
        $uri = '/vendor/directFulfillment/shipping/v1/shipmentConfirmations';
		// $uri = '/vendor/directFulfillment/shipping/v1/shipmentStatusUpdates';
        $marketplace_ids = $this->mobj->encrypt_decrypt($account->marketplace_id,'decrypt');

        $params = array(
            'MarketplaceId'=> $marketplace_ids
        );

        $queryString = $this->formateQueryString($params);
    
		return $data = $this->spApiPostCall($account, $method, $uri, $queryString, $postData);

    }
    //Get Shipment Labels as backup logic
    public function getDirectFullfillmentShipmentLabels($amazon,$next_token,$createdAfter, $createdBefore, $po_number=null)
    {
        $method = 'GET';
        $marketplace_id = $this->mobj->encrypt_decrypt($amazon->marketplace_id,'decrypt');
        
        if($po_number) {
            $uri = '/vendor/directFulfillment/shipping/v1/shippingLabels/'.$po_number;
        } else {
            $uri = '/vendor/directFulfillment/shipping/v1/shippingLabels';
        }
        

        if($next_token && $next_token !='')
        {
            $params = array(
                'MarketplaceId' => $marketplace_id,
                'createdAfter' => $createdAfter,
                'createdBefore' => $createdBefore,
                'limit' => 100,
                'sortOrder' => 'ASC',
                'nextToken' => $next_token
            );
        } else {
            $params = array(
                'MarketplaceId' => $marketplace_id,
                'createdAfter' => $createdAfter,
                'createdBefore' => $createdBefore,
                'limit' => 100,
                'sortOrder' => 'ASC'
            );
        }


        $queryString = $this->formateQueryString($params);
        return $response = $this->spApiCall($amazon, $method, $uri, $queryString);        

    }
    //Get Shipment Label by order number
    public function getDirectFullfillmentShipmentLabelsByOrderNumber($amazon,$po_number)
    {
        $method = 'GET';
        $marketplace_id = $this->mobj->encrypt_decrypt($amazon->marketplace_id,'decrypt');

        $uri = '/vendor/directFulfillment/shipping/v1/shippingLabels/'.$po_number;
        
        $params = array(
            'MarketplaceId' => $marketplace_id
        );

        $queryString = $this->formateQueryString($params);
        return $response = $this->spApiCall($amazon, $method, $uri, $queryString);        

    }
    //create shipment label for direct fullfilment orders
    public function createShipmentLabel ($account,$postData)
    {
        $method = 'POST';
        $marketplace_ids = $this->mobj->encrypt_decrypt($account->marketplace_id,'decrypt');

        $uri = '/vendor/directFulfillment/shipping/v1/shippingLabels';
        $params = array(
            'MarketplaceId'=> $marketplace_ids
        );

        $queryString = $this->formateQueryString($params);


		return $data = $this->spApiPostCall($account, $method, $uri, $queryString, $postData);


    }
    //get transaction status
    public function getTransactionStatus($amazon,$transactionId)
    {
        $method = 'GET';
        $marketplace_id = $this->mobj->encrypt_decrypt($amazon->marketplace_id,'decrypt');

        $uri = '/vendor/directFulfillment/transactions/v1/transactions/'.$transactionId;
        
        $params = array(
            'MarketplaceId' => $marketplace_id
        );

        $queryString = $this->formateQueryString($params);

        return $response = $this->spApiCall($amazon, $method, $uri, $queryString);    


    }


}
