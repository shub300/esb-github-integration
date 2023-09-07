<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Common;

class ShipBobApi
{
	public function __construct()
	{
		$this->mobj = new MainModel();
		$this->conn = new ConnectionHelper();
	}

	/*
			API calls will be rate-limited to 150 requests per-minute using a sliding window, and will be totalled per user, per application across calls to any of the Shipbob APIs.

			Max Limit: 250 records per API call
		*/

	/*
			doc url: "https://developer.shipbob.com/api-docs#tag/Orders/paths/~1order/get"
			api url: "https://api.shipbob.com/1.0/order"
			Limit: Amount of orders per page to request
			Page: Page of orders to get
			StartDate: Start date to filter orders inserted later than
			shipbob_channel_id: Channel Id for Operation
		*/
	public function GetOrderList($access_token, $env_type, $Limit = 200, $Page = 1, $channel_id, $StartDate = null)
	{
		$header = array("Authorization: Bearer " . $access_token, "shipbob_channel_id: " . $channel_id);

		if ($env_type == 'production') {
			$service_url = \Config::get('apiconfig.ShipBobLiveURL') . "/order?Limit=" . $Limit . "&Page=" . $Page;
		} else {
			$service_url = \Config::get('apiconfig.ShipBobSandboxURL') . "/order?Limit=" . $Limit . "&Page=" . $Page;
		}

		if ($StartDate) {
			$service_url = $service_url . "&StartDate=" . $StartDate;
		}

		$response = $this->mobj->makeCurlRequest('GET', $service_url, [], $header);

		return $response;
	}

	/*
			doc url: "https://developer.shipbob.com/api-docs/#tag/Orders/paths/~1order~1{orderId}/get"
			api url: "https://api.shipbob.com/1.0/order/{orderId}"
			orderId: required integer <int32>
		*/
	public function GetOrderByID($access_token, $env_type, $orderId)
	{
		$header = array("Authorization: Bearer " . $access_token);

		if ($env_type == 'production') {
			$service_url = \Config::get('apiconfig.ShipBobLiveURL') . "/order/" . $orderId;
		} else {
			$service_url = \Config::get('apiconfig.ShipBobSandboxURL') . "/order/" . $orderId;
		}

		$response = $this->mobj->makeCurlRequest('GET', $service_url, [], $header);

		return $response;
	}

	/*
			doc url: "https://developer.shipbob.com/api-docs/#tag/Products/paths/~1product/get"
			api url: "https://api.shipbob.com/1.0/productr"
			Limit: Amount of orders per page to request
			Page: Page of orders to get
			ActiveStatus: Enum: "Any" "Active" "Inactive"
			BundleStatus: Enum: "Any" "Bundle" "NotBundle"
		*/
	public function GetInventoryList($access_token, $env_type, $Limit = 250, $Page = 1)
	{
		$header = array("Authorization: Bearer " . $access_token);

		if ($env_type == 'production') {
			//$service_url = \Config::get('apiconfig.ShipBobLiveURL')."/product?ActiveStatus=Active&BundleStatus=NotBundle&Limit=".$Limit."&Page=".$Page;
			$service_url = \Config::get('apiconfig.ShipBobLiveURL') . "/product?ActiveStatus=Active&Limit=" . $Limit . "&Page=" . $Page;
		} else {
			$service_url = \Config::get('apiconfig.ShipBobSandboxURL') . "/product?ActiveStatus=Active&Limit=" . $Limit . "&Page=" . $Page;
		}

		$response = $this->mobj->makeCurlRequest('GET', $service_url, [], $header);

		return $response;
	}

	/*
			doc url: "https://developer.shipbob.com/api-docs/#tag/Channels/paths/~1channel/get"
			api url: "https://api.shipbob.com/1.0/channel"
		*/
	public function GetChannelList($access_token, $env_type)
	{
		$header = array("Authorization: Bearer " . $access_token);

		if ($env_type == 'production') {
			$service_url = \Config::get('apiconfig.ShipBobLiveURL') . "/channel";
		} else {
			$service_url = \Config::get('apiconfig.ShipBobSandboxURL') . "/channel";
		}

		$response = $this->mobj->makeCurlRequest('GET', $service_url, [], $header);

		return $response;
	}

	/*
			doc url: "https://developer.shipbob.com/api-docs/#tag/Orders/paths/~1shippingmethod/get"
			api url: "https://api.shipbob.com/1.0/shippingmethod"
		*/
	public function GetShippingMethodList($access_token, $env_type, $Limit = 250, $Page = 1)
	{
		$header = array("Authorization: Bearer " . $access_token);

		if ($env_type == 'production') {
			$service_url = \Config::get('apiconfig.ShipBobLiveURL') . "/shippingmethod?Limit=" . $Limit . "&Page=" . $Page;
		} else {
			$service_url = \Config::get('apiconfig.ShipBobSandboxURL') . "/shippingmethod?Limit=" . $Limit . "&Page=" . $Page;
		}

		$response = $this->mobj->makeCurlRequest('GET', $service_url, [], $header);

		return $response;
	}

	/*
			doc url: "https://developer.shipbob.com/api-docs/#tag/Locations/paths/~1location/get"
			api url: "https://api.shipbob.com/1.0/location"
		*/
	public function GetLocationList($access_token, $env_type)
	{
		$header = array("Authorization: Bearer " . $access_token);

		if ($env_type == 'production') {
			$service_url = \Config::get('apiconfig.ShipBobLiveURL') . "/location";
		} else {
			$service_url = \Config::get('apiconfig.ShipBobSandboxURL') . "/location";
		}

		$response = $this->mobj->makeCurlRequest('GET', $service_url, [], $header);

		return $response;
	}

	/*
			doc url: "https://developer.shipbob.com/api-docs#tag/Webhooks/paths/~1webhook/post"
			api url: "https://api.shipbob.com/1.0/webhook"
		*/
	public function CreateWebhook($access_token, $request_data_json, $env_type, $channel_id = NULL)
	{
		$header = array("Authorization: Bearer " . $access_token, "Content-Type: application/json");

		if ($env_type == 'production') {
			$service_url = \Config::get('apiconfig.ShipBobLiveURL') . "/webhook";
		} else {
			$service_url = \Config::get('apiconfig.ShipBobSandboxURL') . "/webhook";
		}

		$response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $header);

		return $response;
	}

	/*
			doc url: "https://developer.shipbob.com/api-docs#tag/Webhooks/paths/~1webhook~1{id}/delete"
			api url: "https://api.shipbob.com/1.0/webhook/{id}"
			id: required
			id: integer
		*/
	public function DeleteWebhook($access_token, $env_type, $id)
	{
		$header = array("Authorization" => "Bearer " . $access_token, "Content-Type" => "application/json");

		if ($env_type == 'production') {
			$service_url = \Config::get('apiconfig.ShipBobLiveURL') . "/webhook/" . $id;
		} else {
			$service_url = \Config::get('apiconfig.ShipBobSandboxURL') . "/webhook/" . $id;
		}

		$response = $this->mobj->makeRequest("DELETE", $service_url, NULL, $header);

		return $response;
	}

	//call api to create sales order
	public function createSalesOrder($access_token, $request_data_json, $env_type, $channel_id)
	{
		$header = array("Authorization: Bearer " . $access_token, "Content-Type: application/json", "shipbob_channel_id:" . $channel_id);

		if ($env_type == 'production') {
			$service_url = \Config::get('apiconfig.ShipBobLiveURL') . "/order";
		} else {
			$service_url = \Config::get('apiconfig.ShipBobSandboxURL') . "/order";
		}


		$response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $header);

		return $response;
	}


	// call api to create product
	public function createProduct($access_token, $request_data_json, $env_type, $channel_id)
	{
		$header = array("Authorization: Bearer " . $access_token, "Content-Type: application/json", "shipbob_channel_id:" . $channel_id);

		if ($env_type == 'production') {
			$service_url = \Config::get('apiconfig.ShipBobLiveURL') . "/product/batch";
		} else {
			$service_url = \Config::get('apiconfig.ShipBobSandboxURL') . "/product/batch";
		}


		$response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $header);

		return $response;
	}

	//get shipment by order as backup call
	public function GetShipmentByOrder($access_token, $env_type, $orderId)
	{
		$header = array("Authorization: Bearer " . $access_token);

		if ($env_type == 'production') {
			$service_url = \Config::get('apiconfig.ShipBobLiveURL') . "/order/" . $orderId . '/shipment';
		} else {
			$service_url = \Config::get('apiconfig.ShipBobSandboxURL') . "/order/" . $orderId . '/shipment';
		}

		$response = $this->mobj->makeCurlRequest('GET', $service_url, [], $header);

		return $response;
	}

	//get shipment by order as backup call
	public function GetProductByReferenceId($access_token, $env_type, $refrenceId)
	{
		$header = array("Authorization: Bearer " . $access_token);

		if ($env_type == 'production') {
			$service_url = \Config::get('apiconfig.ShipBobLiveURL') . "/product?ReferenceIds=" . $refrenceId;
		} else {
			$service_url = \Config::get('apiconfig.ShipBobSandboxURL') . "/product?ReferenceIds=" . $refrenceId;
		}

		$response = $this->mobj->makeCurlRequest('GET', $service_url, [], $header);

		return $response;
	}
}
