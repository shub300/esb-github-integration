<?php

namespace App\Helper\Api;

use App\Helper\MainModel;
use function GuzzleHttp\json_decode;

class AmazonMcfApi
{
	public $cache, $mainModel;
	public static $my_platform_name = 'amazonmcf';
	public function __construct()
	{
		$this->mainModel = new MainModel();
	}

	/* Refresh Token If 401 return server status (unauthorized) */
	private function RefreshTokenIfUnauthorized($response, $amazonAccount, $amazonAppAccount, $params = [])
	{
		$key = $amazonAccount->user_id . "_amazonmcf_" . $amazonAccount->id;

		if (is_string($response) && $response == 'Access to requested resource is denied.') {
			$amazonAccount = app('App\Http\Controllers\Amazon\AmazonApiController')->refreshTokens($amazonAccount->id, $amazonAccount->user_id, self::$my_platform_name); //Call refresh token method

			$method = isset($params[0]) ? $params[0] : NULL; //Method Name
			$uri = isset($params[1]) ? $params[1] : NULL; //Base url
			$queryString = isset($params[2]) ? $params[2] : []; //Query String
			$postData = !empty($header) ? $header : NULL; //Payload data

			if ($method == 'POST') {
				$response = $this->spApiPostCall($amazonAccount, $amazonAppAccount, $uri, $queryString, $postData);
			} else {
				$response = $this->spApiCall($amazonAccount, $amazonAppAccount, $uri, $queryString);
			}
			$this->cache->clear_cache_by_key($key);
		}

		return $response;
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

		$header = array_merge($canonicalHeaders, ['Authorization' => $authorizationHeader]);
		$header = array_merge($requestOptions['headers'], $header);
		$requestOptions['headers'] = $header;

		$client = new \GuzzleHttp\Client(['base_uri' => 'https://' . $host]);

		try {
			$response = $client->post($uri, $requestOptions);
			$json = json_decode($response->getBody(), true);
			return $json['AssumeRoleResponse']['AssumeRoleResult']['Credentials'] ?? null;
		} catch (\Exception $e) {
			return $e->getMessage();
		}
	}

	public function authorizationHeaderAndSignature($host, $access_token, $AccessKeyId, $SecretAccessKey, $securityToken, $uri, $method, $terminationString, $algorithm, $amzdate, $date, $region, $service, $queryString, $postData = '')
	{
		// Hashed Payload
		$hashedPayload = hash('sha256', $postData);
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
		$kSecret = 'AWS4' . $SecretAccessKey;
		$kDate = hash_hmac('sha256', $date, $kSecret, true);
		$kRegion = hash_hmac('sha256', $region, $kDate, true);
		$kService = hash_hmac('sha256', $service, $kRegion, true);
		$kSigning = hash_hmac('sha256', $terminationString, $kService, true);
		$signature = trim(hash_hmac('sha256', $stringToSign, $kSigning));

		//Finalize the authorization structure
		$authorizationHeader = $algorithm . " Credential={$AccessKeyId}/{$credentialScope}, SignedHeaders={$signedHeadersStr}, Signature={$signature}";

		return $authorizationHeader;
	}

	//formate queryString
	public function formateQueryString($params)
	{
		$url_parts = array();
		foreach (array_keys($params) as $key) {
			$url_parts[] = $key . "=" . str_replace('%7E', '~', rawurlencode($params[$key]));
		}
		sort($url_parts);

		//Construct the string to sign
		return $queryString = implode("&", $url_parts);
	}

	//call spApiPostCall for post method api call with post data
	public function spApiPostCall($amazonAccount, $amazonAppAccount, $uri, $queryString, $postData)
	{
		$method = 'POST';

		$access_key = $this->mainModel->encrypt_decrypt($amazonAppAccount->access_key, 'decrypt');
		$secret_key = $this->mainModel->encrypt_decrypt($amazonAppAccount->secret_key, 'decrypt');
		$role_arn = $this->mainModel->encrypt_decrypt($amazonAppAccount->role_arn, 'decrypt');
		$access_token = $this->mainModel->encrypt_decrypt($amazonAccount->access_token, 'decrypt');

		$host = $amazonAccount->api_domain;
		$region = $amazonAccount->region;

		$AssumeRoleCredentials = $this->getAssumeRole($access_key, $secret_key, $role_arn, $region);
		if (isset($AssumeRoleCredentials['SessionToken'])) {
			$AccessKeyId = $AssumeRoleCredentials['AccessKeyId'];
			$SecretAccessKey = $AssumeRoleCredentials['SecretAccessKey'];
			$securityToken = $AssumeRoleCredentials['SessionToken'];

			$terminationString = 'aws4_request';
			$algorithm = 'AWS4-HMAC-SHA256';
			$amzdate = gmdate('Ymd\THis\Z');
			$date = substr($amzdate, 0, 8);
			$service = 'execute-api';

			$authorizationHeader = $this->authorizationHeaderAndSignature($host, $access_token, $AccessKeyId, $SecretAccessKey, $securityToken, $uri, $method, $terminationString, $algorithm, $amzdate, $date, $region, $service, $queryString, $postData);

			$orderRequestOptions['headers'] = array('x-amz-access-token' => $access_token, 'x-amz-security-token' => $securityToken, 'User-Agent' => 'PostmanRuntime/7.26.10', 'Host' => $host, 'X-Amz-Date' => $amzdate, 'Authorization' => $authorizationHeader);

			$orderRequestOptions['body'] = $postData;

			try {
				$client = new \GuzzleHttp\Client();
				if ($queryString) {
					$api_response = $client->post('https://' . $host . $uri . '?' . $queryString, $orderRequestOptions);
				} else {
					$api_response = $client->post('https://' . $host . $uri, $orderRequestOptions);
				}

				return json_decode($api_response->getBody()->getContents(), true);
			} catch (\GuzzleHttp\Exception\ClientException $e) {
				if ($e->getResponse()->getStatusCode() == 401) {
					return 'Access to requested resource is denied.';
				}

				return \json_decode($e->getResponse()->getBody()->getContents(), true);
			} catch (\Exception $e) {
				return $e->getMessage();
			}
		} else {
			return 'Session Token Generation Error';
		}
	}

	//call amazon spi api's
	public function spApiCall($amazonAccount, $amazonAppAccount, $uri, $queryString)
	{
		$host = $amazonAccount->api_domain;

		$access_key = $this->mainModel->encrypt_decrypt($amazonAppAccount->access_key, 'decrypt');
		$secret_key = $this->mainModel->encrypt_decrypt($amazonAppAccount->secret_key, 'decrypt');
		$role_arn = $this->mainModel->encrypt_decrypt($amazonAppAccount->role_arn, 'decrypt');
		$region = $amazonAccount->region;
		$access_token = $this->mainModel->encrypt_decrypt($amazonAccount->access_token, 'decrypt');

		$AssumeRoleCredentials = $this->getAssumeRole($access_key, $secret_key, $role_arn, $region);
		if (isset($AssumeRoleCredentials['SessionToken'])) {
			$AccessKeyId = $AssumeRoleCredentials['AccessKeyId'];
			$SecretAccessKey = $AssumeRoleCredentials['SecretAccessKey'];
			$securityToken = $AssumeRoleCredentials['SessionToken'];

			$terminationString = 'aws4_request';
			$algorithm = 'AWS4-HMAC-SHA256';
			$amzdate = gmdate('Ymd\THis\Z');
			$date = substr($amzdate, 0, 8);
			$userAgent = 'PostmanRuntime/7.26.10';
			$service = 'execute-api';
			$method = 'GET';

			//generate authorized signature
			$authorizationHeader = $this->authorizationHeaderAndSignature($host, $access_token, $AccessKeyId, $SecretAccessKey, $securityToken, $uri, $method, $terminationString, $algorithm, $amzdate, $date, $region, $service, $queryString);

			$client = new \GuzzleHttp\Client();

			//prepare header
			$orderRequestOptions['headers'] = array('x-amz-access-token' => $access_token, 'x-amz-security-token' => $securityToken, 'User-Agent' => $userAgent, 'Host' => $host, 'X-Amz-Date' => $amzdate, 'Authorization' => $authorizationHeader);
			try {
				$order_response = $client->get('https://' . $host . $uri . '?' . $queryString, $orderRequestOptions);
				return json_decode($order_response->getBody()->getContents(), true);
			} catch (\GuzzleHttp\Exception\ClientException $e) {
				if ($e->getResponse()->getStatusCode() == 401) {
					return 'Access to requested resource is denied.';
				}

				return \json_decode($e->getResponse()->getBody()->getContents(), true);
			} catch (\Exception $e) {
				return $e->getMessage();
			}
		} else {
			return 'Session Token Generation Error';
		}
	}

	//Create Fulfillment Order
	public function CreateFulfillmentOrder($amazonAccount, $amazonAppAccount, $CreateFulfillmentOrderRequestData)
	{
		$uri = '/fba/outbound/2020-07-01/fulfillmentOrders';

		$queryString = NULL;

		$postData = json_encode($CreateFulfillmentOrderRequestData, true);

		$response = $this->spApiPostCall($amazonAccount, $amazonAppAccount, $uri, $queryString, $postData);

		$response = $this->RefreshTokenIfUnauthorized($response, $amazonAccount, $amazonAppAccount, ['POST', $uri, $queryString, $postData]);

		return $response;
	}

	//Get All Fulfillment Orders
	public function GetAllFulfillmentOrders($amazonAccount, $amazonAppAccount, $queryStartDate, $nextToken)
	{
		$queryStartDate = date('Y-m-d\TH:i:s\Z', strtotime($queryStartDate));

		//get all fulfillment order and check shipping status
		$uri = '/fba/outbound/2020-07-01/fulfillmentOrders';

		$params = array('queryStartDate' => $queryStartDate);
		if ($nextToken) {
			$params['nextToken'] = $nextToken;
		}

		$queryString = $this->formateQueryString($params);

		$response = $this->spApiCall($amazonAccount, $amazonAppAccount, $uri, $queryString);

		$response = $this->RefreshTokenIfUnauthorized($response, $amazonAccount, $amazonAppAccount, ['GET', $uri, $queryString, []]);

		return $response;
	}

	//Get Fulfillment Order Shipment Details
	public function GetFulfillmentOrderShipmentDetails($amazonAccount, $amazonAppAccount, $sellerFulfillmentOrderId)
	{
		//get fulfillment order and check shipping information
		$uri = '/fba/outbound/2020-07-01/fulfillmentOrders/' . $sellerFulfillmentOrderId;

		$queryString = NULL;

		$response = $this->spApiCall($amazonAccount, $amazonAppAccount, $uri, $queryString);

		$response = $this->RefreshTokenIfUnauthorized($response, $amazonAccount, $amazonAppAccount, ['GET', $uri, $queryString, []]);

		return $response;
	}
}
