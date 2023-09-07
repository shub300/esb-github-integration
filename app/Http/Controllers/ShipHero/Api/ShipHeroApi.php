<?php
	namespace App\Http\Controllers\ShipHero\Api;

	use App\Helper\MainModel;

	class ShipHeroApi
	{
		public function __construct()
		{
			$this->MainModel = new MainModel();
		}

		/*
			Batch size This is the maximum number of records that can be retrieved in a single request.

			GET Orders: 300
			GET Products: 200
			All Others: 500

			Maximum requests 2 per second.
		*/

		/*
			doc url: "https://developer.shiphero.com/getting-started/#authentication"
			api url: "https://public-api.shiphero.com/auth/token";
			username: YOUR EMAIL
			password: YOUR PASSWORD
		*/
		public function Authentication($request_data_json)
		{
			$header = array("Content-Type: application/json");

			$service_url = "https://public-api.shiphero.com/auth/token";

			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data_json, $header);

			return $response;
		}

		/*
			doc url: "https://developer.shiphero.com/getting-started/#authentication"
			api url: "https://public-api.shiphero.com/auth/refresh";
			refresh_token: YOUR REFRESH TOKEN
		*/
		public function RefreshToken($request_data_json)
		{
			$header = array("Content-Type: application/json");

			$service_url = "https://public-api.shiphero.com/auth/refresh";

			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data_json, $header);

			return $response;
		}

		public function CreateOrder($access_token, $request_data)
		{
			$header = array("Authorization: Bearer ".$access_token);

			$service_url = "https://public-api.shiphero.com/graphql";

			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data, $header);

			return $response;
		}

		public function CreateOrUpdateProduct($access_token, $request_data)
		{
			$header = array("Authorization: Bearer ".$access_token);

			$service_url = "https://public-api.shiphero.com/graphql";

			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data, $header);

			return $response;
		}

		public function CreateVendor($access_token, $request_data)
		{
			$header = array("Authorization: Bearer ".$access_token);

			$service_url = "https://public-api.shiphero.com/graphql";

			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data, $header);

			return $response;
		}

		public function CallAPI($access_token, $request_data_json)
		{
			$header = array("Content-Type: application/json", "Authorization: Bearer ".$access_token);

			$service_url = "https://public-api.shiphero.com/graphql";

			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data_json, $header);

			return $response;
		}
	}
