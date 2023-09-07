<?php
	namespace App\Http\Controllers\TaxJar\Api;
	
	use App\Helper\MainModel;
	
	class TaxJarApi
	{
		public $mainModel;
		public function __construct()
		{
			$this->mainModel = new MainModel();
		}

		/**
			* Function to check for the given credential
			*
			* @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validation
			* keys
		*/
		protected function checkAuthCredential($env_type, $access_token)
		{
			$status = false;
			if($env_type && $access_token)
			{
				$url = self::setURL($env_type, '/categories');

				$headers['Authorization'] = 'Bearer '.$access_token;
				$response = $this->mainModel->makeRequest('GET', $url, [], $headers);
				
				if($response = self::convertToData($response, 'status'))
				{
					if($response===200)
					{
						$status = true;
					}
				}
			}
			
			return ['status'=>$status];
		}
		
		/**
			* Set url endpoint with sandbox or production environment
			*
			* @param $env, environment of the GunBroker account
			* @param $endpoint, other endpoint of the GunBroker url
			*
			* @return array
		*/
		protected function setURL(string $env, string $endpoint): string
		{
			return (($env === 'sandbox') ? (string) \Config::get('apiconfig.TaxJarSandboxURL') : (string) \Config::get('apiconfig.TaxJarLiveURL')) . $endpoint;
		}
		
		/**
			* Check for the response body to validate if it's a json and also returns array
			*
			* @param $string, json formate string
			* @param $return_data, even if it's false or true, for true return will be array of the json
			* decoded data
			*
			* @return array or boolean
		*/
		private function isJson(string $return_string, bool $return_data = false)
		{
			$data = json_decode($return_string, true);
			return (json_last_error() == JSON_ERROR_NONE) ? ($return_data ? $data : true) : false;
		}
		
		/**
			* Check for the response body to validate if it's a json and also returns array
			*
			* @param $return, object formate
			* @param $type, to get multiple type of data
			* @return array or string or integer
		*/
		private function convertToData($return, $type = 'body')
		{
			$data = NULL;
			if($return)
			{
				if($type == 'body')
				{
					$data = self::isJson($return->getBody(), true);
				}
				elseif($type == 'status')
				{
					$data = $return->getStatusCode();
				}
				elseif($type == 'phrase')
				{
					$data = $return->getReasonPhrase();
				}
				else
				{
					$status = $return->getStatusCode();
					$phrase = $return->getReasonPhrase();
					$body = self::isJson($return->getBody(), true);
					$data = ['status'=>$status, 'phrase'=>$phrase, 'body'=>$body];
				}
			}
			
			return $data;
		}
		
		/**
			* Set headers for the API call
			*
			* @param $accountInfo, StdClass of the information of account from platformAccount or authenticate validation keys
			*
			* @return array
		*/
		private function setHeadersForAPI($accountInfo)
		{
			$headers = [];
			if($accountInfo)
			{
				$headers['Authorization'] = isset($accountInfo->access_token) ? 'Bearer '.$this->mainModel->encrypt_decrypt($accountInfo->access_token, 'decrypt') : null;
			}
			return $headers;
		}

		protected function getCategories($accountInfo)
		{
			$response = false;
			if($accountInfo)
			{
				$url = self::setURL($accountInfo->env_type, '/categories');
				$headers = self::setHeadersForAPI($accountInfo);
				if(count($headers))
				{
					$response = $this->mainModel->makeRequest('GET', $url, [], $headers);
				}
			}
			
			return $response;
		}

		public function postOrderTaxCalculation($accountInfo, $postData=[])
		{
			$response = false;
			if($accountInfo)
			{
				$url = self::setURL($accountInfo->env_type, '/taxes');
				$headers = self::setHeadersForAPI($accountInfo);
				if(count($headers))
				{
					$result = $this->mainModel->makeRequest('POST', $url, $postData, $headers);

					$response = self::convertToData($result);
				}
			}
			
			return $response;
		}

		protected function postSalesOrderTransaction($accountInfo, $postData=[])
		{
			$response = false;
			if($accountInfo)
			{
				$url = self::setURL($accountInfo->env_type, '/transactions/orders');
				$headers = self::setHeadersForAPI($accountInfo);
				if(count($headers))
				{
					$result = $this->mainModel->makeRequest('POST', $url, $postData, $headers);

					$response = self::convertToData($result);
				}
			}
			
			return $response;
		}

		protected function postRefundOrderTransaction($accountInfo, $postData=[])
		{
			$response = false;
			if($accountInfo)
			{
				$url = self::setURL($accountInfo->env_type, '/transactions/refunds');
				$headers = self::setHeadersForAPI($accountInfo);
				if(count($headers))
				{
					$result = $this->mainModel->makeRequest('POST', $url, $postData, $headers);

					$response = self::convertToData($result);
				}
			}
			
			return $response;
		}
	}