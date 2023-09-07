<?php
	namespace App\Helper\Api;
	
	use App\Helper\MainModel;
	use App\Helper\ConnectionHelper;
	
	class CSCartApi
	{
		public $mobj, $helper;
		public static $myPlatform = 'cscart';
		/* This variabe/key is basically used to call custom API call from CS-Cart */
		public static $customApiKey = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9';
		public function __construct()
		{
			$this->mobj = new MainModel();
			$this->helper = new ConnectionHelper;
		}
		
		/* Check CS Cart Token */
		public function CheckCredentials($email, $client_key, $api_domain)
		{
			$return_response = "Error";
			try
			{
				$method = "GET";
				$url = $api_domain . "/api/statuses";
				$header = ["Content-Type" => "application/json", "Authorization" => "Basic " . base64_encode("$email:$client_key")];
				$response = $this->mobj->makeRequest($method, $url,  [], $header, 'json');
				$status = $response->getStatusCode();
				if($status)
				{
					$return_response =  $status;
				}
				else
				{
					$return_response = "Api Error";
				}
			}
			catch(\Exception $e)
			{
				$return_response = $e->getMessage();
			}
			return $return_response;
		}
		
		public function CheckCustomCredentials($api_domain)
		{
			$return_response = "Error";
			try{
				$method = "GET";
				$url = $api_domain . "/api/v1/product";
				$header = ["Content-Type" => "application/json"];
				$response = $this->mobj->makeRequest($method, $url,  [], $header, 'json');
				$status = $response->getStatusCode();
				if($status)
				{
					$return_response =  $status;
				}
				else
				{
					$return_response = "Api Error";
				}
			}
			catch(\Exception $e)
			{
				$return_response = $e->getMessage();
			}
			return $return_response;
		}
		
		/* Check Json */
		public function isJson($string, $return_data = false)
		{
			$data = json_decode($string);
			return (json_last_error() == JSON_ERROR_NONE) ? ($return_data ? $data : true) : false;
		}
		
		/*  Header */
		public function MakeHeader($account, $token = NULL)
		{
			if (!empty($account))
			{
				$email = $this->mobj->encrypt_decrypt($account->app_id, 'decrypt');
				$client_key = $this->mobj->encrypt_decrypt($account->app_secret, 'decrypt');
				$token = base64_encode("$email:$client_key");
				$header = ["Content-Type" => "application/json", "Authorization" => "Basic ".$token];
			} 
			else 
			{
				$header = ["Content-Type" => "application/json", "Authorization" => "Basic ".$token];
			}
			return $header;
		}
		
		/* Custom API Call */
		public function CallCustomAPI($account, $method, $url, $postData = [])
		{
			$header = ["Content-Type" => "application/json",'api-key'=>self::$customApiKey];
			$url = $account->custom_domain . $url;
			$response = $this->mobj->makeRequest(strtoupper($method), $url, $postData, $header);
			return $response;
		}
		
		/* API Call */
		public function CallAPI($account, $method, $url, $postData = [])
		{
			$header = $this->MakeHeader($account);
			$url = $account->api_domain . $url;
			$response = $this->mobj->makeRequest(strtoupper($method), $url, $postData, $header);
			return $response;
		}
	}