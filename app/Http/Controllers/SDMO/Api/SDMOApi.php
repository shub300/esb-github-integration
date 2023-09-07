<?php

namespace App\Http\Controllers\SDMO\Api;

use App\Helper\MainModel;

class SDMOApi
{
	public function __construct()
	{
		$this->MainModel = new MainModel();
	}

	public function Authentication($region, $clientId, $clientSecret, $tenantId)
	{
		$header = array('Content-Type: application/json');

		$service_url = 'https://api.' . $region . '.sageintacctmanufacturing.com/v1/token';

		$data = ['clientId' => $clientId, 'clientSecret' => $clientSecret, 'tenantId' => $tenantId];

		$response = $this->MainModel->makeCurlRequest('POST', $service_url, json_encode($data), $header);

		return $response;
	}

	public function RefreshToken($region, $clientId, $clientSecret, $tenantId, $refreshToken)
	{
		$header = array('Content-Type: application/json', 'Authorization: Bearer ' . $refreshToken);

		$service_url = 'https://api.' . $region . '.sageintacctmanufacturing.com/v1/token/renew';

		$data = ['clientId' => $clientId, 'clientSecret' => $clientSecret, 'tenantId' => $tenantId];

		$response = $this->MainModel->makeCurlRequest('POST', $service_url, json_encode($data), $header);

		return $response;
	}

	public function CallAPI($service_url, $access_token, $request_data_json)
	{
		$header = array('Content-Type: application/json', 'Authorization: Bearer ' . $access_token);

		$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data_json, $header);

		return $response;
	}
}
