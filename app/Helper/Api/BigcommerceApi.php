<?php
	namespace App\Helper\Api;
	
	use App\Helper\MainModel;
	use Illuminate\Support\Facades\Config;
	
	class BigcommerceApi
	{
		private static function url(string $secret_key = '', string $subUrl = '', string $version = 'v3')
		{
			$mainModel = new MainModel();
			return Config::get('apiconfig.bigcommerce') . (($secret_key) ? $mainModel->encrypt_decrypt($secret_key, 'decrypt') . '/' . $version . '/' : '') . $subUrl;
		}
		
		protected static function checkAuthCredential($accountInfo)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'catalog/products');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return true;
					}
				}
			}
			return false;
		}
		
		protected static function getDataWithUrl($accountInfo, $url, $isFullUrl = true, $urlVersion = 'v3')
		{
			if(!empty($accountInfo) && $url) {
				if (!$isFullUrl) {
					$url = static::url($accountInfo->secret_key, $url, $urlVersion);
				}
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function deleteDataWithUrl($accountInfo, $url, $isFullUrl = true)
		{
			if(!empty($accountInfo) && $url) {
				if (!$isFullUrl) {
					$url = static::url($accountInfo->secret_key, $url, 'v3');
				}
				$response = static::makeAPICall($accountInfo, $url, [], 'DELETE');
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function getAPICustomerGroups($accountInfo)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'customer_groups?limit=250', 'v2');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}

		protected static function getAPIPriceLists($accountInfo)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'pricelists?limit=1000', 'v3');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function getAPIPaymentMethods($accountInfo)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'payments/methods', 'v2');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function getAPICategories($accountInfo, $limit, $page)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'catalog/categories?limit='.$limit.'&page='.$page, 'v3');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function getAPIBrands($accountInfo, $limit, $page)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'catalog/brands?limit='.$limit.'&page='.$page, 'v3');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function getAPISalesOrder($accountInfo, $params)
		{
			if(!empty($accountInfo)) {
				$params = (is_array($params) && count($params)) ? '?' . http_build_query($params) : '';
				$url = static::url($accountInfo->secret_key, 'orders' . $params, 'v2');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function getAPIPaymentFromOrderID($accountInfo, $order_id)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'orders/' . $order_id . '/transactions', 'v3');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function getAPISalesOrderFromId($accountInfo, $id)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'orders/' . $id, 'v2');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function getAPIProducts($accountInfo, $params)
		{
			if(!empty($accountInfo)) {
				$params = (is_array($params) && count($params)) ? '?' . http_build_query($params) : '';
				$url = static::url($accountInfo->secret_key, 'catalog/products' . $params, 'v3');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function setAPIWebhook($accountInfo, $data)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'hooks', 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'POST');
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function deleteAPIWebhook($accountInfo, $webhook_id)
		{
			if($accountInfo)
			{
				$url = static::url($accountInfo->secret_key, 'hooks/'.$webhook_id, 'v3');
				$response = static::makeAPICall($accountInfo, $url, [], 'DELETE');
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function createAPIProduct($accountInfo, $data)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'catalog/products', 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'POST');
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function updateAPIProduct($accountInfo, $data, $id)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'catalog/products/' . $id, 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'PUT');
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}

		protected static function createAPIProductOption($accountInfo, $data, $product_id)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'catalog/products/'.$product_id.'/options', 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'POST');
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}

		protected static function updateAPIProductOption($accountInfo, $data, $product_id, $option_id)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'catalog/products/'.$product_id.'/options/'.$option_id, 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'PUT');
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}

		protected static function deleteAPIProductOption($accountInfo, $product_id, $option_id)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'catalog/products/'.$product_id.'/options/'.$option_id, 'v3');
				$response = static::makeAPICall($accountInfo, $url, [], 'DELETE');
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}

		protected static function createAPIProductOptionValue($accountInfo, $data, $product_id, $option_id)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'catalog/products/'.$product_id.'/options/'.$option_id.'/values', 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'POST');
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
						}
						else
						{
						return $response;
					}
				}
			}
			return false;
		}

		protected static function updateAPIProductOptionValue($accountInfo, $data, $product_id, $option_id, $value_id)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'catalog/products/'.$product_id.'/options/'.$option_id.'/values/'.$value_id, 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'PUT');
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
						}
						else
						{
						return $response;
					}
				}
			}
			return false;
		}

		protected static function deleteAPIProductOptionValue($accountInfo, $product_id, $option_id, $value_id)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'catalog/products/'.$product_id.'/options/'.$option_id.'/values/'.$value_id, 'v3');
				$response = static::makeAPICall($accountInfo, $url, [], 'DELETE');
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function updateAPIProductVariantById($accountInfo, $data, $parent_id, $id)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'catalog/products/' . $parent_id . '/variants/' . $id, 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'PUT');
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function createAPIProductVariant($accountInfo, $data, $parent_id)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'catalog/products/'.$parent_id.'/variants', 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'POST');
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function createAPICategory($accountInfo, $data)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'catalog/categories', 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'POST');
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function createAPIBrand($accountInfo, $data)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'catalog/brands', 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'POST');
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function getAPICustomersWithAddress($accountInfo)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'customers?include=addresses');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return false;
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function getAPICustomerFromIDWithAddress($accountInfo, $id)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'customers?id:in=' . $id . '&include=addresses');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function getAPIOrderStatus($accountInfo)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'order_statuses', 'v2');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}

		protected static function getAPIShippingZones($accountInfo)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'shipping/zones', 'v2');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}

		protected static function getAPIZoneShippingMethods($accountInfo, $zone_id)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'shipping/zones/'.$zone_id.'/methods', 'v2');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function getAPICustomerFromEMAILWithAddress($accountInfo, $email)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'customers?email:in=' . $email . '&include=addresses');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		protected static function createUpdateAPICustomer($accountInfo, $data, $isUpdate = false)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'customers', 'v3');
				$method = (!$isUpdate) ? 'POST' : 'PUT';
				$response = static::makeAPICall($accountInfo, $url, $data, $method);
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		/*
			API Doc.: https://developer.bigcommerce.com/api-reference/store-management/orders/orders/createanorder
		*/
		protected static function createOrder($accountInfo, $data)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'orders', 'v2');
				$response = static::makeAPICall($accountInfo, $url, $data, 'POST');
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		/*
			API Doc.: https://developer.bigcommerce.com/api-reference/store-management/orders/order-shipments/createordershipments
		*/
		protected static function createOrderShipment($accountInfo, $order_id, $data)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'orders/'.$order_id.'/shipments', 'v2');
				$response = static::makeAPICall($accountInfo, $url, $data, 'POST');
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}

		/*
			https://developer.bigcommerce.com/api-reference/9959f9f2a03e4-get-refunds-for-order
		*/
		protected static function getAPIOrderRefunds($accountInfo, $order_id)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'orders/'.$order_id.'/payment_actions/refunds', 'v3');
				$response = static::makeAPICall($accountInfo, $url, []);
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}

		/*
			https://developer.bigcommerce.com/docs/ZG9jOjIyMDYxNQ-order-refunds
		*/
		protected static function createRefundQuote($accountInfo, $order_id, $data)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'orders/'.$order_id.'/payment_actions/refund_quotes', 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'POST');
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}

		/*
			https://developer.bigcommerce.com/docs/ZG9jOjIyMDYxNQ-order-refunds
		*/
		protected static function createRefund($accountInfo, $order_id, $data)
		{
			if(!empty($accountInfo))
			{
				$url = static::url($accountInfo->secret_key, 'orders/'.$order_id.'/payment_actions/refunds', 'v3');
				$response = static::makeAPICall($accountInfo, $url, $data, 'POST');
				if($response = static::isJson($response, true))
				{
					$response = static::errorOrResponse($response);
					if(isset($response['error']))
					{
						return $response['error'];
					}
					else
					{
						return $response;
					}
				}
			}
			return false;
		}
		
		/*
			API Doc.: https://developer.bigcommerce.com/api-reference/store-management/orders/orders/updateanorder
		*/
		protected static function updateOrderStatus($accountInfo, $order_id, $data)
		{
			if(!empty($accountInfo)) {
				$url = static::url($accountInfo->secret_key, 'orders/'.$order_id, 'v2');
				$response = static::makeAPICall($accountInfo, $url, $data, 'PUT');
				if($response = static::isJson($response, true)) {
					$response = static::errorOrResponse($response);
					if(isset($response['error'])) {
						return $response['error'];
						} else {
						return $response;
					}
				}
			}
			return false;
		}
		
		private static function errorOrResponse($response)
		{
			$data = [];
			try{
				if(isset($response['status']) && isset($response['errors']))
				{
					$errors = 'Unknown Error';
					if(isset($response['errors']) && count($response['errors']))
					{
						$errors = '';
						foreach(array_values($response['errors']) as $val)
						{
							if(array_key_last(array_values($response['errors'])))
							{
								$errors .= $val;
							}
							else
							{
								$errors .= $val . ', ';
							}
						}
					}
					
					$errors = rtrim($errors, ', ');
					
					if(isset($response['title']))
					{
						if($response['title'] == 'JSON data is missing or invalid' || $response['title'] == $errors)
						{
							if($response['title'] == 'JSON data is missing or invalid')
							{
								$data['error'] = 'Required data is missing or invalid';
							}
							else
							{
								$data['error'] = $response['title'];
							}
						}
						else
						{
							$data['error'] = $response['title'].' - '.$errors;
						}
					}
					else
					{
						$data['error'] = $errors;
					}
				}
				elseif(isset($response['title']))
				{
					$data['error'] = $response['title'];
				}
				else
				{
					if(is_array($response))
					{
						$data = $response;
					}
					else
					{
						$data['error'] = $response;
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error("BigcommerceApi -> errorOrResponse -> ".$e->getLine()." -> ".$e->getMessage());
				$data = $e->getMessage();
			}
			return $data;
		}
		
		private static function isJson($string, bool $return_data = false)
		{
			$data = @json_decode($string, true);
			return (json_last_error() == JSON_ERROR_NONE) ? ($return_data ? $data : $string) : $string;
		}
		
		private static function setHeadersForAPI(object $accountInfo)
		{
			$headers = [];
			if(!empty($accountInfo))
			{
				$mainModel = new MainModel();
				
				$headers = [
				'Accept: application/json',
				'Content-Type: application/json',
				'x-auth-client: '.(isset($accountInfo->app_id) ? $mainModel->encrypt_decrypt($accountInfo->app_id, 'decrypt') : null),
				'x-auth-token: '.(isset($accountInfo->access_token) ? $mainModel->encrypt_decrypt($accountInfo->access_token, 'decrypt') : null)
				];
			}
			return $headers;
		}
		
		private static function makeAPICall($accountInfo, $url, $postData=[], $method='GET')
		{
			$response = [];
			try{
				if(!empty($accountInfo))
				{
					$headers = static::setHeadersForAPI($accountInfo);
					if(count($headers))
					{
						$mainModel = new MainModel();
						$response = $mainModel->makeCurlRequest($method, $url, json_encode($postData), $headers);
						
						//$result = $mainModel->makeRequest($method, $url, $postData, $headers);
						//if($result)
						//{
						//$response = $result->getBody();
						//}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error("BigcommerceApi -> makeAPICall -> ".$e->getLine()." -> ".$e->getMessage());
				$response = $e->getMessage();
			}
			return $response;
		}
	}