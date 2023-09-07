<?php
	namespace App\Helper\Api;
	
	use App\Helper\MainModel;
	use App\Helper\ConnectionHelper;
	class TeapplixApi
	{	
		public function __construct()
		{
			$this->mobj = new MainModel();
			$this->conn = new ConnectionHelper();
		}
		
		/* 
			doc url: "https://www.teapplix.com/help/?page_id=9091"
			api url: "https://api.teapplix.com/api2/Warehouse";
		*/
		public function GetWarehouseList($APIToken)
		{
			$header = array("Content-Type: application/json", "APIToken: ".$APIToken);
			
			$service_url = "https://api.teapplix.com/api2/Warehouse";
			
			$response = $this->mobj->makeCurlRequest('GET', $service_url, [], $header);
			
			return $response;
		}

		/* 
			doc url: "https://www.teapplix.com/help/?page_id=5963"
			api url: "https://api.teapplix.com/api2/Product";
			PageSize: Max records to return per response. Default 100. Limit 1000. Use 'Next' or 'Prev' link to paginate
			PageNumber: Page number. Considering PageNumber, returns the PageNumber-th page, 1-indexed	
		*/
		public function GetProductList($APIToken, $PageSize, $PageNumber)
		{
			$header = array("Content-Type: application/json", "APIToken: ".$APIToken);
			
			$service_url = "https://api.teapplix.com/api2/Product?PageSize=".$PageSize."&PageNumber".$PageNumber;
			
			$response = $this->mobj->makeCurlRequest('GET', $service_url, [], $header);
			
			return $response;
		}

		/* 
			doc url: "https://www.teapplix.com/help/?page_id=6045"
			api url: "https://api.teapplix.com/api2/ProductQuantity";
		*/
		public function GetInventoryList($APIToken)
		{
			$header = array("Content-Type: application/json", "APIToken: ".$APIToken);
			
			$service_url = "https://api.teapplix.com/api2/ProductQuantity";
			
			$response = $this->mobj->makeCurlRequest('GET', $service_url, [], $header);
			
			return $response;
		}
		
		/* 
			doc url: "https://www.teapplix.com/help/?page_id=5681"
			api url: "https://api.teapplix.com/api2/ProductQuantity"
		*/
		public function UpdateProductQuantity($APIToken, $request_data_json)
		{
			$header = array("Content-Type: application/json", "APIToken: ".$APIToken);
			
			$service_url = "https://api.teapplix.com/api2/ProductQuantity";
			
			$response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $header);
			
			return $response;
		}
	}																															