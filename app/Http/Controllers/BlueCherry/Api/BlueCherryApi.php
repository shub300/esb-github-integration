<?php
    namespace App\Http\Controllers\BlueCherry\Api;

	use App\Helper\MainModel;

	class BlueCherryApi
	{
		public function __construct()
		{
			$this->MainModel = new MainModel();
		}

		/*
			API Doc.: http://pdev-bcws.azurewebsites.net/#/bcapi/introduction
		*/

		public function Authentication($api_url, $subscription_key)
		{
			$header = array('Ocp-Apim-Trace: true', 'Ocp-Apim-Subscription-Key: '.$subscription_key);

			$service_url = $api_url."/api/pick";

			$response = $this->MainModel->makeCurlRequest('GET', $service_url, [], $header);

			return $response;
		}

		public function CallAPI($method, $subscription_key, $service_url, $request_data_json=NULL)
		{
			$header = array('Ocp-Apim-Trace: true', 'Ocp-Apim-Subscription-Key: '.$subscription_key);

			$response = $this->MainModel->makeCurlRequest($method, $service_url, $request_data_json, $header);

			return $response;
		}

		public function OrderShipment($subscription_key, $service_url, $request_data_json=NULL)
		{
			$header = array('Content-Type: application/json', 'Ocp-Apim-Trace: true', 'Ocp-Apim-Subscription-Key: '.$subscription_key);

			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data_json, $header);

			return $response;
		}

		public function PhysicalInventoryTM($subscription_key, $service_url, $request_data_json=NULL)
		{
			$header = array('Content-Type: application/json', 'Ocp-Apim-Trace: true', 'Ocp-Apim-Subscription-Key: '.$subscription_key);

			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data_json, $header);

			return $response;
		}

		public function CreatePurchaseOrderReceipt($subscription_key, $service_url, $request_data_json=NULL)
		{
			$header = array('Content-Type: application/json', 'Ocp-Apim-Trace: true', 'Ocp-Apim-Subscription-Key: '.$subscription_key);

			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data_json, $header);

			return $response;
		}
	}
