<?php

namespace App\Helper\Api;

use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use Illuminate\Support\Facades\Log;

class BrightpearlApi
{
	public $mobj, $helper, $myPlatform;
	public static $prototype = "https://";

	public function __construct()
	{
		$this->mobj = new MainModel();
		$this->helper = new ConnectionHelper;
		$this->myPlatform = "brightpearl";
	}

	/* Refresh Token If 401 return server status (unauthorized) */
	private function RefreshTokenIfUnauthorized($response, $account, $params = [])
	{
		if (is_object($response)) {
			$server_status = $response->getStatusCode();

			//If status is 401
			if (401 == $server_status && isset($account->id)) {
				$success_response = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->RefreshTokens($account->id); //Call refresh token method
				/* Change array index as params to call api */
				if (isset($success_response['response']) && $success_response['response'] && isset($success_response['account']['access_token'])) {
					$header = $this->MakeHeader(NULL,  $success_response['account']['access_token'], $success_response['account']['app_secret'], $success_response['account']['app_id']);
					$passing_params1 = isset($params[0]) ? $params[0] : NULL; //Method Name
					$passing_params2 = isset($params[1]) ? $params[1] : NULL; //Base url
					$passing_params3 = isset($params[2]) ? $params[2] : []; //Payload data
					$passing_params4 = !empty($header) ? $header : NULL; //Get Header Data
					$passing_params5 = isset($params[4]) ? $params[4] : "json"; //Type Json or Array | Default json
					$response = $this->mobj->makeRequest($passing_params1, $passing_params2, $passing_params3, $passing_params4, $passing_params5);
				}
			}
		} else {
			\Storage::disk('local')->append('api_error.txt', "Account Name: " . isset($account->account_name) ? $account->account_name : "Not Avail" . " Response: " .  print_r($response, true));
		}

		return $response;
	}

	/* API Call */
	public function CallAPI($account, $method, $url, $payload = [], $json = "json")
	{
		$header = $this->MakeHeader($account);

		$baseurl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name . "/" . $url;

		$response = $this->mobj->makeRequest(strtoupper($method), $baseurl, $payload, $header, $json);
		return $response;
	}

	/* Get Token By User ID */
	public function GetTokenByUserID($userId)
	{
		$platformId = $this->helper->getPlatformIdByName($this->myPlatform);
		$findApp = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' =>  $platformId]);
		if ($findApp &&  $platformId) {
			$accDetail = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $platformId, 'user_id' => $userId]);
			if ($accDetail) {

				return ['access_token' => $this->mobj->encrypt_decrypt($accDetail->access_token, 'decrypt'), 'dev_ref' => $this->mobj->encrypt_decrypt($findApp->client_id, 'decrypt'), 'app_ref' => $this->mobj->encrypt_decrypt($findApp->app_ref, 'decrypt'), 'api_domain' => $accDetail->api_domain, 'app_id' => $accDetail->app_id, 'refresh_token' => $accDetail->refresh_token, 'env_type' => $accDetail->env_type];
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	/* Refresh Token */
	public function RefreshToken($account, $url = NULL, array $postData, $type = "normal")
	{
		$response = false;
		$header = ["content-type:application/x-www-form-urlencoded"];
		$url = \Config::get('apiconfig.BpOauthUrl') . '/token/' . $account->account_name;

		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, 'http');

		return $response;
	}

	/* Check Json */
	public function isJson($string, $return_data = false)
	{
		$data = json_decode($string);
		return (json_last_error() == JSON_ERROR_NONE) ? ($return_data ? $data : true) : false;
	}

	/* Bp Header */
	public function MakeHeader($account, $token = NULL, $devRef = NULL, $appRef = NULL)
	{
		if (!empty($account)) {
			$header = [
				"Content-Type" => "application/json",
				"Authorization" => "Bearer " . $this->mobj->encrypt_decrypt($account->access_token, 'decrypt'),
				"brightpearl-dev-ref" => $this->mobj->encrypt_decrypt($account->app_secret, 'decrypt'),
				"brightpearl-app-ref" => $this->mobj->encrypt_decrypt($account->app_id, 'decrypt'),
			];
		} else {
			$header = [
				"Content-Type" => "application/json",
				"Authorization" => "Bearer " . $this->mobj->encrypt_decrypt($token, 'decrypt'),
				"brightpearl-dev-ref" => $this->mobj->encrypt_decrypt($devRef, 'decrypt'),
				"brightpearl-app-ref" => $this->mobj->encrypt_decrypt($appRef, 'decrypt'),
			];
		}

		return $header;
	}

	public function MakeHeaderCurl($account, $token = NULL, $appRef = NULL, $devRef = NULL)
	{
		if (!empty($account)) {
			$header = [
				"Content-Type:application/json",
				"Authorization:Bearer " . $this->mobj->encrypt_decrypt($account->access_token, 'decrypt'),
				"brightpearl-dev-ref:" . $this->mobj->encrypt_decrypt($account->app_secret, 'decrypt'),
				"brightpearl-app-ref:" . $this->mobj->encrypt_decrypt($account->app_id, 'decrypt'),
			];
		} else {
			$header = [
				"Content-Type:application/json",
				"Authorization:Bearer " . $this->mobj->encrypt_decrypt($token, 'decrypt'),
				"brightpearl-dev-ref:" . $this->mobj->encrypt_decrypt($devRef, 'decrypt'),
				"brightpearl-app-ref:" . $this->mobj->encrypt_decrypt($appRef, 'decrypt'),
			];
		}

		return $header;
	}

	/* Get Warehouse/ Update/ Delete */
	public function GetWarehouse($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/warehouse-service/warehouse/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Brightpearl Inventory Trail */
	public function GetInventoryTrail($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		if ($url) {
			$url =  $baseUrl . $url;
		} else {
			$url =  $baseUrl . "/warehouse-service/goods-movement-search";
		}
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Channels/Update/Delete */
	public function GetChannels($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/product-service/channel/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Projects/Update/Delete */
	public function GetProjects($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/contact-service/project/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Tax Code/Update/Delete */
	public function GetTaxCodes($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/accounting-service/tax-code/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get LeadSources /Update/Delete */
	public function GetLeadSources($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/contact-service/lead-source/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);

		return $response;
	}

	/* Get Shipping Method /Update/Delete */
	public function GetShippingMethods($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/warehouse-service/shipping-method/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Payment Method /Update/Delete */
	public function GetPaymentMethods($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/accounting-service/payment-method/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get GetPriceList /Update/Delete */
	public function GetPriceList($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/product-service/price-list/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Custom Meta Fields New/Update/Delete */
	public function GetCustomMetaFields($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/order-service/sale/custom-field-meta-data/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	public function GetCustomerPaymentsForOrder($account, $orderApiId)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/accounting-service/customer-payment-search?orderId=" . $orderApiId;
		$response =  $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Order Status New/Update/Delete */
	public function GetOrderStatus($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/order-service/order-status/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Categories New/Update/Delete */
	public function GetCategories($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/product-service/brightpearl-category";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Products */
	public function GetProducts($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		if ($type == "search") {
			if (isset($url)) {
				$url =  $baseUrl . "/product-service/{$url}&includeOptional=customFields";
			}
		} elseif ($type == "normal") {

			if (isset($url)) {
				$url =  $baseUrl . "/product-service/{$url}?includeOptional=customFields";
			} else {
				$url =  $baseUrl . "/product-service/product?includeOptional=customFields";
			}
		}
		if (isset($url)) {

			$response = $this->mobj->makeRequest('GET', $url, [], $header);
			/* if 401 unauthorized */
			$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		}
		return $response;
	}

	/* Get Product Urls */
	public function GetProductsUrls($account)
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/product-service/product/";
		$response = $this->mobj->makeRequest('OPTIONS', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Product Options Urls */
	public function GetProductOptions($account, $url)
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/product-service/{$url}/option-value/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Search Option Name */
	public function searchOptionName($account, $optionname)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/product-service/option-search?name=" . $optionname;
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Create Option Name */
	public function createOptionName($account, $data)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/product-service/option/";
		$response = $this->mobj->makeRequest('POST', $url, $data, $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $data, $header]);
		return $response;
	}

	/* Search Option Value Name */
	public function searchOptionValueName($account, $optionValName, $optionId = null)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/product-service/option-value-search?optionId=" . $optionId . "&optionValueName=" . $optionValName;
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Create Option Value */
	public function createOptionValueName($account, $optionid, $data)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/product-service/option/{$optionid}/value";
		$response = $this->mobj->makeRequest('POST', $url, $data, $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $data, $header]);
		return $response;
	}

	/* Get Products */
	public function GetInventory($account, $url = NULL, $is_bundle_item)
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		if ($is_bundle_item == 0) {
			$url = $baseUrl . "/warehouse-service/product-availability/{$url}";
		} else {
			$url = $baseUrl . "/warehouse-service/bundle-availability/{$url}";
		}

		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);

		return $response;
	}

	/* Get bundle info */
	public function GetBundleInfo($account, $url = NULL)
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		$url = $baseUrl . "/product-service/product/{$url}/bundle";

		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);

		return $response;
	}

	/* Get search bundle inventory update */
	public function SearchBundleInventoryUpdate($account, $url = NULL, $from_date, $to_date)
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		$url = $baseUrl . "/product-service/product-search?columns=productId,updatedOn&updatedOn={$from_date}/{$to_date}&stockTracked=false&sort=productId.ASC";

		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);

		return $response;
	}

	/* Get Customers */
	public function GetCustomers($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		if ($type == "search") {
			if (isset($url)) {
				$url = $baseUrl . "/contact-service/{$url}";
			}
		} elseif ($type == "normal") {

			if (isset($url)) {
				$url =  $baseUrl . "/contact-service/{$url}?includeOptional=customFields";
			} else {
				$url =  $baseUrl . "/contact-service/contact?includeOptional=customFields";
			}
		}

		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);

		return $response;
	}

	/* Get Customers Urls */
	public function GetCustomersUrls($account)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/contact-service/contact/";
		$response = $this->mobj->makeRequest('OPTIONS', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['OPTIONS', $url, [], $header]);
		return $response;
	}

	/* Get Account Default Configuration */
	public function GetAccountAdditionInformation($account)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/integration-service/account-configuration/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);

		return $response;
	}

	/* Create Order In BP */
	public function CreateOrder($account, $url = NULL, $postData = [], $type = "normal")
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/order-service/sales-order/";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);

		return $response;
	}

	/* Create Order Sales Credit In BP */
	public function CreateSalesCredit($account, $url = NULL, $postData = [], $type = "normal")
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/order-service/sales-credit/";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);
		return $response;
	}

	/* Get Order Sales Credit By ID In BP */
	public function GetSalesCreditByID($account, $orderId = NULL)
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/order-service/sales-credit/{$orderId}";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Create Order Sales Credit Invoice In BP */
	public function CreateSalesCreditInvoice($account, $postData = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/accounting-service/invoice/sales-credit/";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");

		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);
		return $response;
	}

	/* Close Order Sales Credit By ID In BP */
	public function CloseSalesCreditByID($account, $orderId = NULL, $postData = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/order-service/sales-credit/{$orderId}/close";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");

		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);
		return $response;
	}

	/* Move Sales Credit Inventory */
	public function MoveInventoryOfSalesCreditByID($account, $orderId = NULL, $payload = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/warehouse-service/order/{$orderId}/goods-note/goods-in/";
		$response = $this->mobj->makeRequest('POST', $url, $payload, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $payload, $header, "json"]);
		return $response;
	}

	/* Create Order Payment In BP */
	public function CreateCustomerPayment($account, $url = NULL, $postData = [], $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/accounting-service/customer-payment/";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);

		return $response;
	}

	/* Get Order In BP */
	public function GetOrder($account, $url = NULL, $OrderID = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		if ($type == "search") {

			$url =  $baseUrl . "/order-service/{$url}";
		} elseif ($type == "normal" || $type == "") {

			$url =  $baseUrl . "/order-service/order/$OrderID?includeOptional=customFields";
		}

		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);

		return $response;
	}

	/* Get Goods Out Notes In BP */
	public function GetGoodsOutNotes($account, $url = NULL, $GoodsOutNoteID = NULL)
	{
		$response = false;
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		if ($url) {
			$url =  $baseUrl . $url;
		} else {
			$url =  $baseUrl . "/warehouse-service/order/*/goods-note/goods-out/{$GoodsOutNoteID}";
		}

		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Drop-Ship Notes In BP */
	public function GetDropShipNotes($account, $url = NULL, $DropShipNoteIds = NULL)
	{
		$response = false;
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		if ($url) {
			$url =  $baseUrl . $url;
		} else {
			$url =  $baseUrl . "/warehouse-service/order/*/goods-note/drop-ship/" . $DropShipNoteIds;
		}

		$response = $this->mobj->makeRequest('GET', $url, [], $header);

		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	//https://api-docs.brightpearl.com/product/primary-supplier/put.html
	/* Put Product Primary Supplier In BP */
	public function PutProductPrimarySupplier($account, $supplierId = NULL, $postData = [])
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/product-service/primary-supplier/{$supplierId}/product";
		$response = $this->mobj->makeRequest('PUT', $url, $postData, $header);

		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['PUT', $url, $postData, $header]);
		return $response;
	}

	//https://api-docs.brightpearl.com/product/product-supplier/post.html
	/* Put Product Supplier In BP */
	public function PostProductSupplier($account, $productId = NULL, $postData = [])
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/product-service/product/{$productId}/supplier";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header);

		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header]);
		return $response;
	}

	/* Put Goods Out Notes In BP */
	public function PutGoodsOutNotes($account, $url = NULL, array $postData, $GoodsOutNoteID = NULL)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/warehouse-service/goods-note/goods-out/{$GoodsOutNoteID}";
		$response = $this->mobj->makeRequest('PUT', $url, $postData, $header);

		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['PUT', $url, $postData, $header]);
		return $response;
	}

	/* Post Goods Out Note Event In BP */
	public function PostGoodsOutNoteEvent($account, $url = NULL, array $postData, $GoodsOutNoteID = NULL)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/warehouse-service/goods-note/goods-out/{$GoodsOutNoteID}/event";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header]);
		return $response;
	}

	/* Create Customer In BP */
	public function CreateCustomer($account, $url = NULL, array $postData, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/contact-service/contact/";

		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, 'json');
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, 'json']);
		return $response;
	}

	/* Update Customer In BP */
	public function updateCustomer($account, $url = NULL, array $postData, $customerId)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/contact-service/contact/{$customerId}";

		$response = $this->mobj->makeRequest('PATCH', $url, $postData, $header, 'json');
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['PATCH', $url, $postData, $header, 'json']);
		return $response;
	}

	/* Create Postal Address */
	public function CreatePostalAddress($account, $url = NULL, array $postData, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/contact-service/postal-address/";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, 'json');
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, 'json']);
		return $response;
	}

	/* Get Postal Address */
	public function GetPostalAddress($account, $postAddressIds = null)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		$url =  $baseUrl . "/contact-service/postal-address/" . $postAddressIds;
		if (isset($postAddressIds)) {
			$response = $this->mobj->makeRequest('GET', $url, [], $header);
			/* if 401 unauthorized */
			$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		}
		return $response;
	}

	/* Create Multi Message Post */
	public function MultiMessage($account, array $postData)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/multi-message";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, 'json');
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, 'json']);
		return $response;
	}
	/* get bp webhook list */

	public function GetWebhookList($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/integration-service/webhook";
		$response = $this->mobj->makeRequest('GET', $url, [], $header, 'json');
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* delete bp webhook list */
	public function DeleteWebhook($account, $url = NULL, $ID, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/integration-service/webhook/{$ID}";

		$response = $this->mobj->makeRequest("DELETE", $url, [], $header, 'json');
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ["DELETE", $url, [], $header, 'json']);

		return $response;
	}

	/* Handle Brightpearl Response Errors */
	public function handleResponseError($response, $type = NULL)
	{
		$errors_list = null;
		if (is_null($type)) {
			if (isset($response['errors'])) {
				foreach ($response['errors'] as $key => $error) {
					$errors_list .= $error['message'] . ",";
				}
			} elseif (isset($response['response'])) {
				if (is_string($response['response'])) {
					$errors_list = $response['response'];
				}
			}
		} else {
			if (isset($response['response']['processedMessages'])) {
				foreach ($response['response']['processedMessages'] as $key => $res) {
					$contentbody = json_decode($res['body']['content'], true);
					/* Error Handling 1 */
					if (isset($contentbody['error']) || (isset($contentbody['errors']) && is_array($contentbody['errors']))) {
						$errors = isset($contentbody['errors']) ? $contentbody['errors'] : $contentbody['error'];
						foreach ($errors as $key => $error) {
							$errors_list .= $error['message'] . ",";
						}
					}
				}
			}
		}

		return rtrim($errors_list, ",");
	}

	/* Allocate Items */
	public function AllocateItems($account, $url = NULL, $OrderID, $WarehouseID, array $postData, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/warehouse-service/order/{$OrderID}/reservation/warehouse/{$WarehouseID}";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, 'json');
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, 'json']);
		return $response;
	}

	/* Order fulfilment status GET */
	public function GetOrderFullmentStatus($account, $url = NULL, $OrderID)
	{
		// try {
		//     $response = false;
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/warehouse-service/order/{$OrderID}/fulfilment-status";
		$response = $this->mobj->makeRequest('GET', $url, [], $header, 'json');
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header, 'json']);
		return $response;
		// } catch (\Exception $e) {
		//     \Log::error($e->getMessage());
		// }
	}

	/* Get Purchase Orders */
	public function GetPurchaseOrders($account, $url)
	{
		//$response = false;
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . $url;
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Stock Transactions */
	public function GetStockTransactions($account, $url)
	{
		//$response = false;
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . $url;
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Product Price List */
	public function GetProductPriceList($account, $url = NULL, $type = "normal")
	{
		// try {
		//     $response = false;
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		if (isset($url)) {
			$url =  $baseUrl . "/product-service/{$url}";
		} else {
			$url =  $baseUrl . "/product-service/product-price/*/price-list";
		}

		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
		// } catch (\Exception $e) {

		//     \Log::error($e->getMessage());
		//     return $e->getMessage();
		// }
	}

	/* Get Goods In Notes */
	public function GetGoodsInNotes($account, $url = NULL, $GoodsInNoteID = NULL)
	{
		// $response = false;
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		if ($url != null) {
			$url =  $baseUrl . $url;
		} else {
			$url =  $baseUrl . "/warehouse-service/order/*/goods-note/goods-in/{$GoodsInNoteID}";
		}
        // Log::info("GetGoodsInNotes URL: ".$url);
        // Log::info("GetGoodsInNotes Header: ".json_encode( $header ) );
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Custom Fields */
	public function GetCustomFieldsList($account, $type = 'purchase_order')
	{
		// try {
		//     $response = false;
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		if ($type == 'purchase_order') {
			$url = '/order-service/purchase/custom-field-meta-data';
		} else if ($type == 'sales_order') {
			$url = '/order-service/sale/custom-field-meta-data';
		} elseif ($type == 'customer') {
			$url = '/contact-service/customer/custom-field-meta-data';
		} elseif ($type == 'product') {
			$url = '/product-service/product/custom-field-meta-data';
		}
		$url =  $baseUrl . $url;
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
		// } catch (\Exception $e) {
		//     \Log::error($e->getMessage());
		// }
	}

	/* Update Order Status */
	public function UpdateOrderStatus($account, $url = NULL, $postData = [], $OrderID = NULL)
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		if ($url) {
			$url =  $baseUrl . "/order-service/{$url}";
		} else {
			$url =  $baseUrl . "/order-service/order/{$OrderID}/status";
		}

		$response = $this->mobj->makeRequest('PUT', $url, $postData, $header, 'json');

		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['PUT', $url, $postData, $header, 'json']);
		return $response;
	}

	/* Update Order Row Tax */
	public function UpdateOrderRowTax($account, $OrderID = NULL, $RoWID = NULL, $postData = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/order-service/order/{$OrderID}/row/{$RoWID}";

		$response = $this->mobj->makeRequest('PATCH', $url, $postData, $header, 'json');

		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['PATCH', $url, $postData, $header, 'json']);
		return $response;
	}

	/* Update Order Status */
	public function AddOrderNote($account, $url = NULL, $postData = [], $OrderID = NULL)
	{
		// $response = false;
		// try {

		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		if ($url) {
			$url =  $baseUrl . "/order-service/{$url}";
		} else {
			$url =  $baseUrl . "/order-service/order/{$OrderID}/note";
		}
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, 'json');
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, 'json']);
		return $response;
		// } catch (\Exception $e) {
		//     \Log::error($e->getMessage());
		//     return $e->getMessage();
		// }
		// return $response;
	}

	/* Search Payments */
	public function SearchCustomerPayments($account, $url = NULL)
	{
		// $response = false;
		// try {
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		$url =  $baseUrl . "/accounting-service/customer-payment-search/{$url}";

		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
		// } catch (\Exception $e) {
		//     \Log::error($e->getMessage());
		//     return $e->getMessage();
		// }
		// return $response;
	}

	/* Search Customer */
	public function SearchCustomer($account, $url = NULL)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/contact-service/contact-search/{$url}";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}
	/* Search Customer */
	public function SearchOrder($account, $url = NULL)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/order-service/order-search/{$url}";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Search Products */
	public function SearchProduct($account, $url = NULL)
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/product-service/product-search/{$url}";

		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Delete Customer Order Payment By ID */
	public function DeleteCustomerPaymentByID($account, $url = NULL, $paymentID)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		$url =  $baseUrl . "/accounting-service/customer-payment/{$paymentID}";

		$response = $this->mobj->makeRequest('DELETE', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['DELETE', $url, [], $header]);
		return $response;
	}

	public function GetWarehouseDefaultLocation($account, $warehouseId)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/warehouse-service/warehouse/{$warehouseId}/location/default";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	public function UpdateInventory($account, $warehouseId, $postData = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/warehouse-service/warehouse/{$warehouseId}/stock-correction";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);
		return $response;
	}

	/* Create Order In BP */
	public function CreatePOInvoice($account, $url = NULL, $postData = [], $type = "normal")
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/accounting-service/invoice/purchase-invoice/";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);
		return $response;
	}

	// Get currency id
	public function GetCurrency($account, $currency_code)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/accounting-service/currency-search?code=" . $currency_code;
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Product type By ID or All Product Type at once */
	public function GetProductType($account, $product_type_id = NULL)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		if ($product_type_id) {
			$url =  $baseUrl . '/product-service/product-type/' . $product_type_id;
		} else {
			$url =  $baseUrl . '/product-service/product-type/';
		}
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Set Product Type Option Association */
	public function SetProductTypeOptionAssociation($account, $productTypeId, $optionId)
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		$url =  $baseUrl . "/product-service/product-type/" . $productTypeId . "/option-association/" . $optionId;

		$response = $this->mobj->makeRequest('POST', $url, [], $header);

		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, [], $header]);

		return $response;
	}

	/* Create Order Goods Out Note In BP */
	public function CreateOrderGoodsOutNote($account, $OrderID, $postData = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/warehouse-service/order/" . $OrderID . "/goods-note/goods-out";

		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);
		return $response;
	}

	/*Update Goods Out Note In BP */
	public function UpdateGoodsOutNote($account, $GoodsOutNoteID, $postData = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/warehouse-service/goods-note/goods-out/" . $GoodsOutNoteID;

		$response = $this->mobj->makeRequest('PUT', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['PUT', $url, $postData, $header, "json"]);
		return $response;
	}

	/*Goods Out Note Mark As Shipped In BP */
	public function GoodsOutNoteMarkAsShipped($account, $GoodsOutNoteID, $postData)
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/warehouse-service/goods-note/goods-out/" . $GoodsOutNoteID . "/event";

		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);
		return $response;
	}

	/* Create Purchase Order Invoice Payment In BP */
	public function CreatePOInvoicePayment($account, $url = NULL, $postData = [], $type = "normal", $invoice_payment_type = "NonPOInvoice")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		if ($invoice_payment_type == "NonPOInvoice") {
			$url =  $baseUrl . "/accounting-service/invoice/purchase-payment/";
		} else {
			$url =  $baseUrl . "/accounting-service/supplier-payment/";
		}

		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);

		return $response;
	}

	/* Create Order In BP */
	public function CreateGoodInNote($account, $url = NULL, $postData = [], $type = "normal")
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . $url;
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);
		return $response;
	}

	/* Create Order In BP */
	public function UpdateCustomField($account, $OrderID, $postData = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/order-service/order/" . $OrderID . "/custom-field";

		$response = $this->mobj->makeRequest('PATCH', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['PATCH', $url, $postData, $header, "json"]);
		return $response;
	}

	/* Get Suppliers/ Update/ Delete */
	public function GetSupplier($account, $url = NULL, $type = "normal")
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/contact-service/contact-search?isSupplier=true&columns=contactId,companyName";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Get Brands */
	public function GetBrands($account)
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/product-service/brand/";

		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	/* Create Product In BP */
	public function CreateProduct($account, $postData = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/product-service/product/";

		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);
		return $response;
	}

	/* Update Product In BP */
	public function UpdateProduct($account, $productId, $postData = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/product-service/product/" . $productId;

		$response = $this->mobj->makeRequest('PUT', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['PUT', $url, $postData, $header, "json"]);
		return $response;
	}

	/* Update Product Price In BP */
	public function UpdateProductPrice($account, $productId, $postData = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/product-service/product-price/" . $productId . "/price-list";

		$response = $this->mobj->makeRequest('PUT', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['PUT', $url, $postData, $header, "json"]);
		return $response;
	}

	public function GetTags($account)
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/contact-service/tag/";

		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);
		return $response;
	}

	public function searchBrand($account, $value)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/product-service/brand-search?brandName=" . $value;
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);

		return $response;
	}

	public function createBrand($account, $data)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/product-service/brand";
		$response = $this->mobj->makeRequest('POST', $url, $data, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $data, $header, "json"]);

		return $response;
	}

	public function searchCategory($account, $value)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/product-service/brightpearl-category-search?name=" . $value;
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);

		return $response;
	}

	public function createCategory($account, $data)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/product-service/brightpearl-category/";
		$response = $this->mobj->makeRequest('POST', $url, $data, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $data, $header, "json"]);

		return $response;
	}

	public function getWarehouseLocation($account, $warehouseId)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/warehouse-service/warehouse/$warehouseId/location/";
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);

		return $response;
	}

	/* External Transfer */
	public function ExternalTransfer($account, $url, $payload)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . $url;
		$response = $this->mobj->makeRequest('POST', $url, $payload, $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $payload, $header]);
		return $response;
	}

	/* Quarantine Release */
	public function QuarantineRelease($account, $url, $payload)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . $url;
		$response = $this->mobj->makeRequest('POST', $url, $payload, $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $payload, $header]);
		return $response;
	}

	/* Create Purchase Order Close In BP */
	public function CreatePurchaseOrderClose($account, $url = NULL, $postData = [], $type = "normal")
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/order-service/purchase-order/close";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);
		return $response;
	}

	/* Create journal In BP */
	public function CreateJournalEntry($account, $postData = [])
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		$url =  $baseUrl . "/accounting-service/journal-entry";

		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);

		return $response;
	}

	public function searchCurrency($account, $code)
	{
		$header = $this->MakeHeader($account);
		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url = $baseUrl . "/accounting-service/currency-search?code=" . $code;
		$response = $this->mobj->makeRequest('GET', $url, [], $header);
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['GET', $url, [], $header]);

		return $response;
	}
	public function getResponse($server_response)
    {
        return [
            'status_code' => $server_response->getStatusCode(),
            'body' => json_decode($server_response->getBody(), true),
            'reason' => $server_response->getReasonPhrase()
        ];
    }

	public function addOrderLine($account, $api_order_id, $postData = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;

		$url =  $baseUrl . "/order-service/order/{$api_order_id}/row";

		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);
		return $response;
	}

	public function CloseSalesOrderByID($account, $orderId = NULL, $postData = [])
	{
		$header = $this->MakeHeader($account);

		$baseUrl = self::$prototype . $account->api_domain . "/public-api/" . $account->account_name;
		$url =  $baseUrl . "/order-service/sales-order/{$orderId}/close";
		$response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");

		/* if 401 unauthorized */
		$response = $this->RefreshTokenIfUnauthorized($response, $account, ['POST', $url, $postData, $header, "json"]);
		return $response;
	}

}
