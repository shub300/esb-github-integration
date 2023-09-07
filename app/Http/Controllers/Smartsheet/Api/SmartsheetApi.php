<?php
	namespace App\Http\Controllers\Smartsheet\Api;

	use App\Helper\MainModel;

	class SmartsheetApi
	{
		public function __construct()
		{
			$this->MainModel = new MainModel();
		}

		public function getSheetList($access_token, $queryString='')
		{
			$header = array('Authorization: Bearer '.$access_token);

			if($queryString)
			{
				$service_url = 'https://api.smartsheet.com/2.0/sheets?'.$queryString;
			}
			else
			{
				$service_url = 'https://api.smartsheet.com/2.0/sheets';
			}
			

			$response = $this->MainModel->makeCurlRequest('GET', $service_url, [], $header);

			return $response;
		}

		public function readSheet($access_token, $sheetId, $queryString='')
		{
			$header = array('Authorization: Bearer '.$access_token);

			if($queryString)
			{
				$service_url = 'https://api.smartsheet.com/2.0/sheets/'.$sheetId.'?'.$queryString;
			}
			else
			{
				$service_url = 'https://api.smartsheet.com/2.0/sheets/'.$sheetId;
			}
			
			$response = $this->MainModel->makeCurlRequest('GET', $service_url, [], $header);

			return $response;
		}

		public function updateSalesOrderStatus($access_token, $sheetId, $postData)
		{
			$header = array('Authorization: Bearer '.$access_token, 'Content-Type: application/json');

			$service_url = 'https://api.smartsheet.com/2.0/sheets/'.$sheetId.'/rows';
			
			$response = $this->MainModel->makeCurlRequest('PUT', $service_url, $postData, $header);

			return $response;
		}
	}