<?php
	namespace App\Helper\Api;
	
	use App\Helper\MainModel;
	
	class MFTGatewayApi
	{	
		public function __construct()
		{
			$this->MainModel = new MainModel();
		}
		
		/* 
			api url: "https://api.mftgateway.com/authorize";
			username: YOUR EMAIL
			password: YOUR PASSWORD
		*/
		public function Authentication($request_data_json)
		{
			$header = array("Content-Type: application/json");
			
			$service_url = "https://api.mftgateway.com/authorize";
			
			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data_json, $header);
			
			return $response;
		}

		/* 
			api url: "https://api.mftgateway.com/refresh-session";
			username: YOUR EMAIL
			refreshToken: YOUR REFRESH TOKEN
		*/
		public function RefreshToken($request_data_json)
		{
			$header = array("Content-Type: application/json");
			
			$service_url = "https://api.mftgateway.com/refresh-session";
			
			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data_json, $header);
			
			return $response;
		}
		
		/* 
			api url: "https://api.mftgateway.com/message/submit";
		*/
		public function SendMessage($header, $request_data)
		{
			$service_url = "https://api.mftgateway.com/message/submit";
			
			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data, $header);
			
			return $response;
		}

		/* 
			normal api url: "https://api.mftgateway.com/partner";
			as2 api url: "https://api.mftgateway.com/partner?service=as2";
			sftp api url: "https://api.mftgateway.com/partner?service=sftp";
		*/
		public function CreatePartner($access_token, $request_data, $service=NULL)
		{
			$header = array('Authorization: '.$access_token, 'Content-Type: application/json');

			$service_url = "https://api.mftgateway.com/partner";
			if($service == 'as2')
			{
				$service_url = "https://api.mftgateway.com/partner?service=as2";
			}
			elseif($service == 'sftp')
			{
				$service_url = "https://api.mftgateway.com/partner?service=sftp";
			}
			
			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data, $header);
			
			return $response;
		}
	}