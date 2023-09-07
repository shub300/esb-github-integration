<?php
	namespace App\Helper\Api;
	
	use App\Helper\MainModel;
	
	class ThreeDCartApi
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
			doc url: "https://apirest.3dcart.com/v2/orders/index.html#retrieve-a-list-of-orders"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/Orders?invoicenumber=&invoicenumberstart=&invoicenumberend=&invoiceprefix=&orderstatus=&datestart=&dateend=&limit=&offset=&countonly=&lastupdatestart=&lastupdateend=&billingemail=";
			orderstatus: Retrieve a list of orders from a specific status
			limit: Maximum number of items to return
			offset: Starting point for the return data
			datestart: Retrieve a list of orders after this date (mm/dd/yyyy hh:mm:ss)
			lastupdatestart: Retrieve a list of orders last updated after this date (mm/dd/yyyy)
		*/
		public function GetOrderList($access_token, $limit=300, $offset=0, $lastupdatestart=null, $orderstatus=null, $datestart=null)
		{
			$header = array("Content-Type: application/xml", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Orders?limit=".$limit."&offset=".$offset;
			
			if($orderstatus)
			{
				$service_url = $service_url."&orderstatus=".$orderstatus;
			}
			
			if($datestart)
			{
				$service_url = $service_url."&datestart=".$datestart;
			}
			
			if($lastupdatestart)
			{
				$service_url = $service_url."&lastupdatestart=".$lastupdatestart;
			}
			
			$response = $this->MainModel->makeCurlRequest('GET', $service_url, [], $header);
			
			return $response;
		}
		
		/* 
			doc url: "https://apirest.3dcart.com/v2/orders/index.html#retrieve-a-specific-order-by-id"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/Orders/{orderid}"
			orderid: required	
			orderid: The invoice number of the order to retrieve (exact match) 
		*/
		public function GetOrderByID($access_token, $orderid)
		{
			$header = array("Content-Type: application/xml", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Orders/".$orderid;
			
			$response = $this->MainModel->makeCurlRequest('GET', $service_url, [], $header);
			
			return $response;
		}
		
		/* 		
			doc url: "https://apirest.3dcart.com/v2/order-status/index.html#retrieve-a-list-of-order-statuses"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/OrderStatus?limit=&offset=&countonly="
			limit: Maximum number of items to return
			offset: Starting point for the return data
		*/
		public function GetOrderStatusList($access_token, $limit=500, $offset=0)
		{
			$header = array("Content-Type: application/xml", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/OrderStatus?limit=".$limit."&offset=".$offset;
			
			$response = $this->MainModel->makeCurlRequest('GET', $service_url, [], $header);
			
			return $response;
		}
		
		/* 
			doc url: "https://apirest.3dcart.com/v2/order-status/index.html#retrieve-a-specific-order-status-by-id"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/OrderStatus/{id}"
			id: required	
			id: The unique id of the Order Status 
		*/
		public function GetOrderStatusByID($access_token, $id)
		{
			$header = array("Content-Type: application/xml", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/OrderStatus/".$id;
			
			$response = $this->MainModel->makeCurlRequest('GET', $service_url, [], $header);
			
			return $response;
		}
		
		/* 		
			doc url: "https://apirest.3dcart.com/v2/products/index.html#retrieve-a-list-of-products"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/Products?limit=&offset=&countonly=&sku=&name=&costfrom=&costto=&pricefrom=&priceto=&stockfrom=&stockto=&hide=&freeshipping=&onsale=&nontax=&notforsale=&giftcertificate=&homespecial=&categoryspecial=&nonsearchable=&selfship=&rewarddisable=&lastupdatestart=&lastupdateend="
			limit: Maximum number of items to return
			offset: Starting point for the return data
			lastupdatestart: Retrieve a list of orders last updated after this date (mm/dd/yyyy)
		*/
		public function GetProductList($access_token, $limit=200, $offset=0, $lastupdatestart=null)
		{
			$header = array("Content-Type: application/xml", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Products?limit=".$limit."&offset=".$offset;
			
			if($lastupdatestart)
			{
				$service_url = $service_url."&lastupdatestart=".$lastupdatestart;
			}
			
			$response = $this->MainModel->makeCurlRequest('GET', $service_url, [], $header);
			
			return $response;
		}
		
		/* 
			doc url: "https://apirest.3dcart.com/v2/products/index.html#retrieve-a-specific-product-by-id"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/Products/{catalogid}"
			catalogid: required	
			catalogid: Catalogid of the item 
		*/
		public function GetProductByID($access_token, $catalogid)
		{
			$header = array("Content-Type: application/xml", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Products/".$catalogid;
			
			$response = $this->MainModel->makeCurlRequest('GET', $service_url, [], $header);
			
			return $response;
		}
		
		/* 
			doc url: "https://apirest.3dcart.com/v2/products/index.html#retrieve-a-list-of-products"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/Products?limit=&offset=&countonly=&sku=&name=&costfrom=&costto=&pricefrom=&priceto=&stockfrom=&stockto=&hide=&freeshipping=&onsale=&nontax=&notforsale=&giftcertificate=&homespecial=&categoryspecial=&nonsearchable=&selfship=&rewarddisable=&lastupdatestart=&lastupdateend="
			sku: required	
			sku: SKU of the item 
		*/
		public function GetProductBySKU($access_token, $sku)
		{
			$header = array("Content-Type: application/xml", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Products?sku=".$sku;
			
			$response = $this->MainModel->makeCurlRequest('GET', $service_url, [], $header);
			
			return $response;
		}
		
		/* 
			doc url: "https://apirest.3dcart.com/v2/orders/index.html#update-a-list-of-shipments"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/Orders/{orderid}/Shipments"
			orderid: required	
			orderid: The orderid of the order (exact match)
		*/
		public function UpdateOrderShipmentByOrderID($access_token, $orderid, $request_data_json)
		{
			$header = array("Content-Type: application/json", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Orders/".$orderid."/Shipments";
			
			$response = $this->MainModel->makeCurlRequest('PUT', $service_url, $request_data_json, $header);
			
			return $response;
		}
		
		/* 
			doc url: "https://apirest.3dcart.com/v2/orders/index.html#update-a-specific-shipment-by-id"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/Orders/{orderid}/Shipments/{shipmentid}"
			orderid: required	
			orderid: The orderid of the order (exact match)
			shipmentid: required	
			shipmentid: The ShipmentID value
		*/
		public function UpdateOrderShipmentByShipmentID($access_token, $orderid, $shipmentid, $request_data_json)
		{
			$header = array("Content-Type: application/json", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Orders/".$orderid."/Shipments/".$shipmentid;
			
			$response = $this->MainModel->makeCurlRequest('PUT', $service_url, $request_data_json, $header);
			
			return $response;
		}
		
		/* 
			doc url: "https://apirest.3dcart.com/v2/products/index.html#update-a-list-of-products"
			api url: ""https://apirest.3dcart.com/3dCartWebAPI/v2/Products""
			orderid: required	
			orderid: The orderid of the order (exact match)
		*/
		public function UpdateProductList($access_token, $request_data_json)
		{
			$header = array("Content-Type: application/json", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Products";
			
			$response = $this->MainModel->makeCurlRequest('PUT', $service_url, $request_data_json, $header);
			
			return $response;
		}
		
		/* 
			doc url: "https://apirest.3dcart.com/v2/products/index.html#update-a-specific-product-by-id"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/Products/{catalogid}"
			catalogid: required	
			catalogid: Catalogid of the item
		*/
		public function UpdateProductByCatalogID($access_token, $catalogid, $request_data_json)
		{
			$header = array("Content-Type: application/json", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Products/".$catalogid;
			
			$response = $this->MainModel->makeCurlRequest('PUT', $service_url, $request_data_json, $header);
			
			return $response;
		}

		/* 
			doc url: "https://apirest.3dcart.com/v2/products/index.html#update-a-specific-advanced-option-by-id"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/Products/{catalogid}/AdvancedOptions/{advancedoptioncode}"
			catalogid: required	
			catalogid: Catalogid of the item
			advancedoptioncode: required	
			advancedoptioncode: AdvancedOptionCode of the option
		*/
		public function UpdateProductAdvanceOptionByCode($access_token, $catalogid, $advancedoptioncode, $request_data_json)
		{
			$header = array("Content-Type: application/json", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Products/".$catalogid."/AdvancedOptions/".$advancedoptioncode;
			
			$response = $this->MainModel->makeCurlRequest('PUT', $service_url, $request_data_json, $header);
			
			return $response;
		}
		
		/* 
			doc url: "https://apirest.3dcart.com/v2/orders/index.html#update-a-specific-order-by-id"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/Orders/{orderid}"
			orderid: required	
			orderid: The orderid of the order (exact match)
		*/
		public function UpdateOrderByOrderID($access_token, $orderid, $request_data_json)
		{
			$header = array("Content-Type: application/json", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Orders/".$orderid;
			
			$response = $this->MainModel->makeCurlRequest('PUT', $service_url, $request_data_json, $header);
			
			return $response;
		}
		
		/* 
			doc url: "https://apirest.3dcart.com/v2/orders/index.html#create-a-shipment-in-an-order"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/Orders/{orderid}/Shipments"
			orderid: required	
			orderid: The orderid of the order (exact match)
		*/
		public function CreateOrderShipment($access_token, $orderid, $request_data_json)
		{
			$header = array("Content-Type: application/json", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Orders/".$orderid."/Shipments";
			
			$response = $this->MainModel->makeCurlRequest('POST', $service_url, $request_data_json, $header);
			
			return $response;
		}
		
		/* 
			doc url: "https://apirest.3dcart.com/v2/orders/index.html#update-a-list-of-items"
			api url: "https://apirest.3dcart.com/3dCartWebAPI/v2/Orders/{orderid}/Items"
			orderid: required	
			orderid: The orderid of the order (exact match)
		*/
		public function UpdateOrderLineItems($access_token, $orderid, $request_data_json)
		{
			$header = array("Content-Type: application/json", "Accept: application/json", "Authorization: Bearer ".$access_token);
			
			$service_url = \Config::get('apiconfig.3dcartUrl')."/3dCartWebAPI/v2/Orders/".$orderid."/Items";
			
			$response = $this->MainModel->makeCurlRequest('PUT', $service_url, $request_data_json, $header);
			
			return $response;
		}
	}