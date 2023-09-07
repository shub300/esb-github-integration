<?php

namespace App\Http\Controllers\Bigcommerce;

use DateTime;
use App\Helper\Logger;
use App\Helper\MainModel;
use Illuminate\Http\Request;
use App\Models\PlatformOrder;
use App\Models\PlatformStates;
use App\Helper\WorkflowSnippet;
use App\Models\PlatformAccount;
use App\Models\PlatformProduct;
use App\Helper\ConnectionHelper;
use App\Models\PlatformCustomer;
use App\Models\PlatformOrderLine;
use App\Helper\Api\BigcommerceApi;
use App\Helper\FieldMappingHelper;
use App\Models\PlatformCustomFieldValue;
use App\Models\PlatformField;
use App\Models\PlatformObjectData;
use Illuminate\Support\Facades\DB;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformProductOption;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use App\Models\PlatformProductInventory;
use App\Models\PlatformProductPriceList;
use Illuminate\Support\Facades\Validator;
use App\Models\PlatformWebhookInformation;
use App\Models\PlatformProductDetailAttribute;
use App\Models\PlatformOrderAdditionalInformation;
use App\Models\PlatformOrderRefund;
use App\Models\PlatformOrderRefundLine;
use App\Models\PlatformOrderTransaction;
use App\Models\PlatformUrl;
use App\Models\SyncLog;
use App\Models\UserIntegration;
use Lang;
// TODO
// Product Image
// add the consent to add the custom payment or not
// Variant update and create
// sales order and product create and update webhook only put id and sync_status to pending
// get pending sales order and product for run the api and get the data of the order / product to sync_status change to ready
// get webhook of the payment and put the transaction to pending
// run the cron for sub event of payment information to get the data of transaction and set payment and sync_status to ready

class BigcommerceController extends BigcommerceApi
{
	private const PLATFORMNAME = 'bigcommerce';

	private $workflowSnippet, $connectionHelper, $mainModel, $logger, $fieldMapHelper;

	public function __construct()
	{
		$this->workflowSnippet = new WorkflowSnippet();
		$this->connectionHelper = new ConnectionHelper();
		$this->mainModel = new MainModel();
		$this->logger = new Logger();
		$this->fieldMapHelper = new FieldMappingHelper();
		// Set the platform ID
		$this->platformId = $this->connectionHelper->getPlatformIdByName(self::PLATFORMNAME);
	}

	public function InitiateBigCommerceAuth(Request $request)
	{
		$platform = self::PLATFORMNAME;
		return view("pages.apiauth.bigcommerce_auth", compact('platform'));
	}

	public function ConnectBigCommerceAuth(Request $request)
	{
		$response = ['status_code' => 0]; // array for return response with status_code default to 0 (false)

		if ($this->mainModel->checkHtmlTags($request->all())) {
			$response['status_text'] = Lang::get('tags.validate');
			return $response;
		}

		try {
			$validator = Validator::make($request->all(), ['account_name' => 'required', 'access_token' => 'required', 'client_id' => 'required', 'store_hash' => 'required'], ['access_token.required' => 'Access token is required.', 'account_name.required' => 'Account Username is required.', 'client_id.required' => 'Client ID is required.', 'store_hash.required' => 'Store Hash is required.']);
			if ($validator->fails()) {
				$statusText = array_values(json_decode($validator->messages()->toJson(), true))[0][0];
			} else {
				$validated = array_map(function ($val) {
					return htmlspecialchars($val);
				}, $validator->validated());
				$validated = (object) $validated;

				// Set and Decrypt the values for security measures
				$env = 'production';
				$account_name = $validated->account_name;
				$store_hash = $this->mainModel->encrypt_decrypt($validated->store_hash);
				$access_token = $this->mainModel->encrypt_decrypt($validated->access_token);
				$client_id = $this->mainModel->encrypt_decrypt($validated->client_id);

				// Get Current User Id
				$user_data = Session::get('user_data');
				$user_id = $user_data['id'];

				// Check for the account
				$account = PlatformAccount::select('id')->where(['user_id' => $user_id, 'platform_id' => $this->platformId, 'account_name' => $account_name, 'env_type' => $env])->count();
				if ($account === 0) {
					$accountInfo = new \StdClass();
					$accountInfo->access_token = $access_token;
					$accountInfo->secret_key = $store_hash;
					$accountInfo->account_name = $account_name;
					$accountInfo->app_id = $client_id;

					$isConnected = static::checkAuthCredential($accountInfo);
					if ($isConnected === true) {
						// Add the given data
						$newAccount = new PlatformAccount();
						$newAccount->user_id = $user_id;
						$newAccount->platform_id = $this->platformId;
						$newAccount->app_id = $client_id;
						$newAccount->account_name = $account_name;
						$newAccount->access_token = $access_token;
						$newAccount->secret_key = $store_hash;
						$newAccount->allow_refresh = 0;
						$newAccount->save();

						if ($newAccount->id) {
							$response['status_code'] = true;
							$statusText = 'Account Connected.';
						} else {
							$statusText = 'Account not created! Please try again.';
						}
					} else {
						if ($isConnected === false) {
							$statusText = 'Please check for the given credential.';
						} else {
							$statusText = $isConnected;
						}
					}
				} else {
					$statusText = "Account with $account_name already connected.";
				}
			}
			$response['status_text'] = $statusText;
		} catch (\Exception $e) {
			$response['status_text'] = $e->getMessage();
		}
		return $response;
	}

	// ** GET PAYMENT METHODS **
	private function getPaymentMethods($user_id, $user_integration_id)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$payment_object_id = $this->connectionHelper->getObjectId('payment');
			if ($account && $payment_object_id) {
				$paymentMethods = static::getAPIPaymentMethods($account);
				if (isset($paymentMethods[0]['code'])) {
					// Update the object data status to 0
					$payMethodData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $payment_object_id];
					$payMethodInsData = [];
					PlatformObjectData::where($payMethodData)->update(['status' => 0]); // put all the customer group to 0
					foreach ($paymentMethods as $paymentMethod) {
						$payMethodData['api_id'] = $paymentMethod['code'];
						$payMethodData['api_code'] = $paymentMethod['code'];
						$grpObject = PlatformObjectData::where($payMethodData)->first();
						if ($grpObject) {
							$grpObject->name = $paymentMethod['name'];
							$grpObject->status = 1;
							$grpObject->updated_at = date('Y-m-d H:i:s');
							$grpObject->save();
						} else {
							$payMethodData['name'] = $paymentMethod['name'];
							$payMethodInsData[] = $payMethodData;
						}
					}

					if (count($payMethodInsData)) {
						PlatformObjectData::insert($payMethodInsData);
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> getPaymentMethods -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** GET CUSTOMER GROUP **
	private function getCustomerGroup($is_initial_sync, $user_id, $user_integration_id)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$price_list_object_id = $this->connectionHelper->getObjectId('pricelist_group');
			if ($account && $price_list_object_id) {
				$customerGrpData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $price_list_object_id];
				if ($is_initial_sync) {
					// Update the object data status to 0
					PlatformObjectData::where($customerGrpData)->update(['status' => 0]); // put all the customer group to 0
				}

				$customerGroups = static::getAPICustomerGroups($account);
				if (isset($customerGroups[0]['id'])) {
					$customerGrpInsData = [];
					foreach ($customerGroups as $customerGroup) {
						$customerGrpData['api_id'] = $customerGroup['id'];
						$grpObject = PlatformObjectData::where($customerGrpData)->first();
						if ($grpObject) {
							$grpObject->name = $customerGroup['name'];
							$grpObject->status = 1;
							$grpObject->updated_at = date('Y-m-d H:i:s');
							$grpObject->save();
						} else {
							$customerGrpData['name'] = $customerGroup['name'];
							$customerGrpInsData[] = $customerGrpData;
						}
					}

					if (count($customerGrpInsData)) {
						PlatformObjectData::insert($customerGrpInsData);
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> getCustomerGroup -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** GET Price List **
	private function getPriceList($is_initial_sync, $user_id, $user_integration_id)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$price_list_object_id = $this->connectionHelper->getObjectId('pricelist');
			if ($account && $price_list_object_id) {
				$priceListData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $price_list_object_id];
				if ($is_initial_sync) {
					// Update the object data status to 0
					PlatformObjectData::where($priceListData)->update(['status' => 0]); // put all the price list to 0
				}

				$PriceLists = static::getAPIPriceLists($account);
				if (isset($PriceLists['data'])) {
					$priceListInsData = [];
					foreach ($PriceLists['data'] as $PriceList) {
						$priceListData['api_id'] = $PriceList['id'];
						$priceListObject = PlatformObjectData::where($priceListData)->first();
						if ($priceListObject) {
							$priceListObject->name = $PriceList['name'];
							$priceListObject->status = $PriceList['active'];
							$priceListObject->save();
						} else {
							$priceListData['name'] = $PriceList['name'];
							$priceListData['status'] = $PriceList['active'];
							$priceListInsData[] = $priceListData;
						}
					}

					if (count($priceListInsData)) {
						PlatformObjectData::insert($priceListInsData);
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> getPriceList -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** GET CATEGORY **
	private function getCategories($is_initial_sync, $user_id, $user_integration_id)
	{
		$response = true;
		try {
			$platform_account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$category_object_id = $this->connectionHelper->getObjectId('category');
			if ($platform_account && $category_object_id) {
				//Update the object data status to 0
				$categoryData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $category_object_id];

				$limit = 200;
				$page = 1;

				$platform_url = $this->mainModel->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'categories', 'status' => 1], ['id', 'url']);
				if ($platform_url) {
					$platform_url_id = $platform_url->id;
					$page = $platform_url->url;
				} else {
					$url_data = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'categories', 'url' => 1, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
					$platform_url_id = $this->mainModel->makeInsertGetId('platform_urls', $url_data);
				}

				if ($is_initial_sync == 1 && $page == 1) {
					PlatformObjectData::where($categoryData)->update(['status' => 0]);
				}

				do {
					$allow_next_cal = false;

					$categories = static::getAPICategories($platform_account, $limit, $page);
					if (isset($categories['data'][0]['id'])) {
						$allow_next_cal = true;
						$categoryInsData = [];
						foreach ($categories['data'] as $category) {
							$categoryData['api_id'] = $category['id'];
							$grpObject = PlatformObjectData::where($categoryData)->first();
							if ($grpObject) {
								$grpObject->name = $category['name'];
								$grpObject->parent_id = $category['parent_id'];
								$grpObject->description = $category['description'];
								$grpObject->status = 1;
								$grpObject->updated_at = date('Y-m-d H:i:s');
								$grpObject->save();
							} else {
								$categoryData['name'] = $category['name'];
								$categoryData['parent_id'] = $category['parent_id'];
								$categoryData['description'] = $category['description'];
								$categoryInsData[] = $categoryData;
							}
						}

						if (count($categoryInsData)) {
							PlatformObjectData::insert($categoryInsData);
						}

						//max 4 time run this script in single call
						if (($page % 4) == 0) {
							$allow_next_cal = false;
						}

						$page++;

						if (count($categories['data']) == $limit) {
							$this->mainModel->makeUpdate('platform_urls', ['url' => $page, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_url_id]);

							$response = "Next get page " . $page . " data";
						} else {
							$allow_next_cal = false;
							$this->mainModel->makeUpdate('platform_urls', ['url' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_url_id]);

							$response = true;
						}
					} else {
						$this->mainModel->makeUpdate('platform_urls', ['url' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_url_id]);
						$response = true;
					}
				} while ($allow_next_cal);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> getCategories -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** GET BRAND **
	private function getBrands($is_initial_sync, $user_id, $user_integration_id)
	{
		$response = true;
		try {
			$platform_account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$brand_object_id = $this->connectionHelper->getObjectId('brand');
			if ($platform_account && $brand_object_id) {
				//Update the object data status to 0
				$brandData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $brand_object_id];

				$limit = 200;
				$page = 1;

				$platform_url = $this->mainModel->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'brands', 'status' => 1], ['id', 'url']);
				if ($platform_url) {
					$platform_url_id = $platform_url->id;
					$page = $platform_url->url;
				} else {
					$url_data = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'brands', 'url' => 1, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
					$platform_url_id = $this->mainModel->makeInsertGetId('platform_urls', $url_data);
				}

				if ($is_initial_sync == 1 && $page == 1) {
					PlatformObjectData::where($brandData)->update(['status' => 0]);
				}

				do {
					$allow_next_cal = false;
					$brands = static::getAPIBrands($platform_account, $limit, $page);
					if (isset($brands['data'][0]['id'])) {
						$allow_next_cal = true;
						$brandInsData = [];
						foreach ($brands['data'] as $brand) {
							$brandData['api_id'] = $brand['id'];
							$grpObject = PlatformObjectData::where($brandData)->first();
							if ($grpObject) {
								$grpObject->name = $brand['name'];
								$grpObject->status = 1;
								$grpObject->updated_at = date('Y-m-d H:i:s');
								$grpObject->save();
							} else {
								$brandData['name'] = $brand['name'];
								$brandInsData[] = $brandData;
							}
						}

						if (count($brandInsData)) {
							PlatformObjectData::insert($brandInsData);
						}

						//max 4 time run this script in single call
						if (($page % 4) == 0) {
							$allow_next_cal = false;
						}

						$page++;

						if (count($brands['data']) == $limit) {
							$this->mainModel->makeUpdate('platform_urls', ['url' => $page, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_url_id]);

							$response = "Next get page " . $page . " data";
						} else {
							$allow_next_cal = false;
							$this->mainModel->makeUpdate('platform_urls', ['url' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_url_id]);

							$response = true;
						}
					} else {
						$this->mainModel->makeUpdate('platform_urls', ['url' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_url_id]);
						$response = true;
					}
				} while ($allow_next_cal);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> getBrands -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** GET ORDER STATUS **
	private function getOrderStatus($is_initial_sync, $user_id, $user_integration_id)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$orderstatus_object_id = $this->connectionHelper->getObjectId('order_status');
			if ($account && $orderstatus_object_id) {
				// Update the object data status to 0
				$orderStatusData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $orderstatus_object_id];
				if ($is_initial_sync) {
					PlatformObjectData::where($orderStatusData)->update(['status' => 0]); // put all the customer group to 0
				}

				$orderstatus = static::getAPIOrderStatus($account);
				if (isset($orderstatus[0]['id'])) {
					$orderStatusInsData = [];
					foreach ($orderstatus as $status) {
						$orderStatusData['api_id'] = $status['id'];
						$grpObject = PlatformObjectData::where($orderStatusData)->first();
						if ($grpObject) {
							$grpObject->name = $status['custom_label'];
							$grpObject->description = $status['name'];
							$grpObject->status = 1;
							$grpObject->updated_at = date('Y-m-d H:i:s');
							$grpObject->save();
						} else {
							$orderStatusData['name'] = $status['custom_label'];
							$orderStatusData['description'] = $status['name'];
							$orderStatusInsData[] = $orderStatusData;
						}
					}

					if (count($orderStatusInsData)) {
						PlatformObjectData::insert($orderStatusInsData);
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> getOrderStatus -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	/* Get Shipping Zones */
	public function getShippingZones($user_id, $user_integration_id)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$zone_object_id = $this->connectionHelper->getObjectId("zone");
			$location_object_id = $this->connectionHelper->getObjectId("location");
			if ($account && $zone_object_id) {
				$zoneData = ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $zone_object_id, 'user_id' => $user_id];

				$zones = static::getAPIShippingZones($account);
				if (isset($zones[0]['id'])) {
					foreach ($zones as $zone) {
						$zoneData['api_id'] = $zone['id'];
						$objectData = PlatformObjectData::where($zoneData)->first();
						if ($objectData) {
							$objectData->name = $zone['name'];
							$objectData->description = $zone['name'];
							$objectData->status = $zone['enabled'] ? 1 : 0;
							$objectData->updated_at = date('Y-m-d H:i:s');
							$objectData->save();
						} else {
							$zoneData['name'] = $zone['name'];
							$zoneData['description'] = $zone['name'];
							$zoneData['status'] = $zone['enabled'] ? 1 : 0;
							$objectData = PlatformObjectData::create($zoneData);
						}

						if ($location_object_id && $objectData && isset($zone['locations'][0]['id'])) {
							$locationData = ['parent_id' => $objectData->id, 'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $location_object_id];
							foreach ($zone['locations'] as $location) {
								$locationData['api_id'] = $location['id'];
								$locationObjectData = PlatformObjectData::where($locationData)->first();
								if ($locationObjectData) {
									$locationObjectData->name = $location['country_iso2'];
									$locationObjectData->description = @$location['state_iso2'];
									$locationObjectData->status = 1;
									$locationObjectData->updated_at = date('Y-m-d H:i:s');
									$locationObjectData->save();
								} else {
									$locationData['name'] = $location['country_iso2'];
									$locationData['description'] = @$location['state_iso2'];
									$locationData['status'] = 1;
									PlatformObjectData::create($locationData);
								}
							}
						}
					}
				} else {
					$response = "Shipping zone data not found";
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> getShippingZones -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	/* Get Zone Shipping Methods */
	public function getZoneShippingMethods($user_id, $user_integration_id, $zonePrimaryID = NULL)
	{
		$response = true;
		try {
			if ($zonePrimaryID) {
				$zone = PlatformObjectData::find($zonePrimaryID);
				$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
				$shipping_method_object_id = $this->connectionHelper->getObjectId("shipping_method");
				if ($zone && $account && $shipping_method_object_id) {
					$shippingMethodData = ['parent_id' => $zonePrimaryID, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $shipping_method_object_id, 'user_id' => $user_id];

					$shipping_methods = static::getAPIZoneShippingMethods($account, $zone->api_id);
					if (isset($shipping_methods[0]['id'])) {
						$shippingMethodDataList = [];
						foreach ($shipping_methods as $shipping_method) {
							$shippingMethodData['api_id'] = $shipping_method['id'];
							$objectData = PlatformObjectData::where($shippingMethodData)->first();
							if ($objectData) {
								$objectData->name = $shipping_method['name'];
								$objectData->description = $shipping_method['type'];
								$objectData->status = $shipping_method['enabled'] ? 1 : 0;
								$objectData->updated_at = date('Y-m-d H:i:s');
								$objectData->save();
							} else {
								$shippingMethodData['name'] = $shipping_method['name'];
								$shippingMethodData['description'] = $shipping_method['type'];
								$shippingMethodData['status'] = $shipping_method['enabled'] ? 1 : 0;
								$shippingMethodData['parent_id'] = $zonePrimaryID;
								$shippingMethodDataList[] = $shippingMethodData;
							}
						}

						if (count($shippingMethodDataList)) {
							PlatformObjectData::insert($shippingMethodDataList);
						}
					} else {
						$response = "Shipping method data not found";
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> getZoneShippingMethods -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** Set Non Tracked Product SKU **
	public function setNonTrackedProductSKU($sku)
	{
		$listSKU = ['ROUTEINS'];
		if (in_array($sku, $listSKU)) {
			return true;
		} else {
			return false;
		}
	}

	// ** GET PRODUCTS **
	private function getProducts($is_initial_sync, $user_id, $user_integration_id, $user_workflow_rule_id)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			if ($is_initial_sync && $account) {
				$checkForUrlAsPageNo = PlatformUrl::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'products', 'status' => 0])->first();
				if ($checkForUrlAsPageNo) {
					$pageNo = $checkForUrlAsPageNo->url;
				} else {
					$env = (env('APP_ENV') == 'prod') ? 'prod' : 'stag';
					$webhook_data = ['scope' => 'store/product/*', 'destination' => env('APP_WEBHOOK_URL') . "/bigcommerce/index.php?for=product&uid=$user_integration_id&env=$env", 'is_active' => true];
					$this->setWebhook($account, $webhook_data, $user_id, $user_integration_id);
					$pageNo = 1;
				}

				$VariantCount = 0;
				do {
					$allow_next_cal = false;

					$params = ['page' => $pageNo];
					$params['include'] = 'variants,custom_fields,bulk_pricing_rules,modifiers,options';
					$products = static::getAPIProducts($account, $params);
					if (isset($products['data'][0]['id'])) {
						foreach ($products['data'] as $vCount) {
							$VariantCount = $VariantCount + count($vCount['variants']);
						}

						$allow_next_cal = true;
						$this->addProductsToDatabase($user_id, $user_integration_id, $user_workflow_rule_id, $products);
						if (isset($products['meta']) && isset($products['meta']['pagination'])) {
							if ($products['meta']['pagination']['total_pages'] > $params['page']) {
								$newPageNo = $params['page'] + 1;
								$productsInserted = $products['meta']['pagination']['per_page'] * $params['page'];
								$response = "$productsInserted products are inserted";
								if ($checkForUrlAsPageNo) {
									$checkForUrlAsPageNo->url = $newPageNo;
									$checkForUrlAsPageNo->save();
								} else {
									PlatformUrl::create(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'products', 'url' => $newPageNo, 'status' => 0]);
								}
							} else {
								if ($checkForUrlAsPageNo) {
									$checkForUrlAsPageNo->status = 1;
									$checkForUrlAsPageNo->save();
								}
								$response = true;
								$allow_next_cal = false;
							}
						} else {
							if ($checkForUrlAsPageNo) {
								$checkForUrlAsPageNo->status = 1;
								$checkForUrlAsPageNo->save();
							}
							$response = true;
							$allow_next_cal = false;
						}
					} else {
						if ($checkForUrlAsPageNo) {
							$checkForUrlAsPageNo->status = 1;
							$checkForUrlAsPageNo->save();
						}
						$response = true;
						$allow_next_cal = false;
					}
					//DB::commit();

					//max 10 time run this script in single call
					if (($pageNo % 10) == 0 || $VariantCount > 200) {
						$allow_next_cal = false;
					}

					$pageNo++;
				} while ($allow_next_cal);
			} elseif ($account) {
				//DB::beginTransaction();
				// CHECK FOR THE PENDING PRODUCTS
				$pendingProducts = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'product_sync_status' => 'Pending', 'is_deleted' => 0])->select('api_product_id')->groupBy('api_product_id')->inRandomOrder()->limit(50)->pluck('api_product_id')->toArray();
				if (is_array($pendingProducts) && count($pendingProducts)) {
					$dbProductIds = implode(',', $pendingProducts);
					$params = ['id:in' => $dbProductIds, 'include' => 'variants,custom_fields,bulk_pricing_rules,modifiers,options'];
					$canRun = true;
					$newPageNo = 1;
					do {
						$params['page'] = $newPageNo;
						$products = static::getAPIProducts($account, $params);
						if (isset($products['data'][0]['id'])) {
							$response = $this->addProductsToDatabase($user_id, $user_integration_id, $user_workflow_rule_id, $products);
							if (isset($products['meta']) && isset($products['meta']['pagination'])) {
								if ($products['meta']['pagination']['total_pages'] > $params['page']) {
									$newPageNo = $params['page'] + 1;
								} else {
									$canRun = false;
								}
							}
						} else {
							$canRun = false;
							$response = true;
						}
					} while ($canRun);
				}
				//DB::commit();
			}
		} catch (\Exception $e) {
			//DB::rollBack();
			\Log::error($user_integration_id . " -> BigcommerceController -> getProducts -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}
		return $response;
	}

	// ** GET PRODUCTS Backup **
	private function getProductBackup($is_initial_sync, $user_id, $user_integration_id, $user_workflow_rule_id)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			if ($account && $is_initial_sync == 0) {
				$params = ['sort' => 'date_modified', 'direction' => 'asc', 'limit' => 250];

				$platform_url = PlatformUrl::select('id', 'url')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'product_backup_time'])->first();
				if ($platform_url) {
					$min_date_modified = new DateTime($platform_url->url);
					$min_date_modified->modify('-1 second');
					$min_date_modified = $min_date_modified->format(DateTime::ATOM);

					$params['date_modified:min'] = $min_date_modified;
				} else {
					$platform_product_latest = PlatformProduct::select('id', 'api_updated_at')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'inventory_tracking' => 'PRODUCT'])->whereNotNull('api_updated_at')->orderBy('api_updated_at', 'DESC')->first();
					if ($platform_product_latest) {
						$min_date_modified = new DateTime($platform_product_latest->api_updated_at);
						$min_date_modified->modify('-1 second');
						$min_date_modified = $min_date_modified->format(DateTime::ATOM);

						$params['date_modified:min'] = $min_date_modified;
					} else {
						$min_date_modified = date("Y-m-d H:i:s", strtotime('-2 hours'));
						$min_date_modified = new DateTime($min_date_modified);
						$min_date_modified = $min_date_modified->format(DateTime::ATOM);

						$params['date_modified:min'] = $min_date_modified;
					}
				}

				$products = static::getAPIProducts($account, $params);
				if (isset($products['data'][0]['id'])) {
					$new_date_modified = NULL;
					foreach ($products['data'] as $product) {
						$new_date_modified = $product['date_modified'];
						$platform_product = PlatformProduct::select('id', 'api_updated_at')->where(['api_product_id' => $product['id'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,  'inventory_tracking' => 'PRODUCT'])->first();
						if ($platform_product) {
							if ($platform_product->api_updated_at != $product['date_modified']) {
								PlatformProduct::where('id', $platform_product->id)
									->update(['product_sync_status' => 'Pending']);
							}
						} else {
							PlatformProduct::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_product_id' => $product['id'], 'product_sync_status' => 'Pending']);
						}
					}

					if ($new_date_modified) {
						if ($platform_url) {
							PlatformUrl::where('id', $platform_url->id)
								->update(['url' => $new_date_modified, 'allow_retain' => 1]);
						} else {
							PlatformUrl::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => 'product_backup_time', 'url' => $new_date_modified, 'allow_retain' => 1]);
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> getProductBackup -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** WEBHOOK PRODUCT CREATE / UPDATE **
	public function webhookBigcommerceProduct(Request $request, $user_integration_id)
	{
		$response = true;
		$data = [];
		try {
			if ($user_integration_id) {
				$user_integration = $this->fieldMapHelper->getUserIntegrationDetailsById($user_integration_id, self::PLATFORMNAME);
				$data = $request->getContent();
				$data = json_decode($data, true);
				if ($user_integration && isset($data['data']['id']) && isset($data['scope'])) {
					$api_id = $data['data']['id'];
					$api_type = isset($data['data']['type']) ? $data['data']['type'] : null;
					if ($api_type == 'product') {
						//check if the GET_PRODUCT workflow is ON
						if (($data['scope'] == 'store/product/created' || $data['scope'] == 'store/product/updated')) {
							if (!$this->fieldMapHelper->getIntegProductById($user_integration_id, $api_id, $data['scope'], self::PLATFORMNAME)) {
								$productCheck = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_product_id' => $api_id])->first();
								if ($productCheck) {
									if ($productCheck->linked_id) {
										$product_object_id = $this->connectionHelper->getObjectId('product');
										//loop back issue solve
										$sync_log = SyncLog::select('updated_at')->where(['record_id' => $productCheck->linked_id, 'sync_status' => 'success', 'platform_object_id' => $product_object_id])->first();
										if ($sync_log && $product_object_id && (strtotime(date('Y-m-d H:i:s')) - strtotime($sync_log->updated_at)) > 10) {
											$productCheck->product_sync_status = 'Pending';
											$productCheck->save();
										} elseif (is_null($sync_log)) {
											$productCheck->product_sync_status = 'Pending';
											$productCheck->save();
										}
									} else {
										$productCheck->product_sync_status = 'Pending';
										$productCheck->save();
									}
								} else {
									PlatformProduct::create(['user_id' => $user_integration->user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_product_id' => $api_id, 'product_sync_status' => 'Pending']);
								}
							}
						} elseif ($data['scope'] == 'store/product/deleted') {
							$response = $this->deleteProductsToDatabase($user_integration->user_id, $user_integration_id, $api_id);
						}
					} else {
						$response = 'Webhook type must be product';
					}
				}
			} else {
				$response = "No integration for this ID";
			}
		} catch (\Exception $e) {
			//DB::rollBack();
			\Log::error($user_integration_id . " -> BigcommerceController -> webhookBigcommerceProduct -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** GET PAYMENT INFORMATION **
	private function getTransactionInformation($is_initial_sync, $user_id, $user_integration_id, $user_workflow_rule_id)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			if (!$is_initial_sync && $account) {
				//DB::beginTransaction();
				$consent_of_user = $this->fieldMapHelper->getMappedDataByName($user_integration_id, null, "custom_payment_consent", ['api_id']);
				$dbPendingOrder = PlatformOrder::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'transaction_sync_status' => 'Pending', 'is_deleted' => 0])->where('linked_id', '<>', 0)->limit(25)->get();
				if ($dbPendingOrder) {
					foreach ($dbPendingOrder as $dborder) {
						$canFill = false;
						$order = static::getAPISalesOrderFromId($account, $dborder->api_order_id);
						$paymentData = static::getAPIPaymentFromOrderID($account, $dborder->api_order_id);
						if ($order['payment_provider_id'] && isset($paymentData['data']) && count($paymentData['data'])) {
							$row_type = 'PAYMENT';
							$total_amount = 0;
							foreach ($paymentData['data'] as $paymentDetail) {
								if ((($paymentDetail['status'] == 'capture_success' || $paymentDetail['status'] == 'refund_success') || ($paymentDetail['status'] == 'ok' && $paymentDetail['test'] == 1)) && $paymentDetail['event'] != 'pending') {
									$amount = $paymentDetail['amount'];
									if ($paymentDetail['status'] == 'capture_success') {
										//2023-05-23 comment below line, because API send actual amount now
										//$amount = $paymentDetail['amount'] / 100;
									}

									$manual_transaction = PlatformOrderTransaction::where(['platform_order_id' => $dborder->id, 'transaction_type' => 'payment', 'transaction_approval' => 'ok', 'row_type' => 'PAYMENT'])->where(function ($query) {
										$query->where('linked_id', '<>', 0)->orWhere('sync_status', 'Synced');
									})->first();
									if ($row_type == 'PAYMENT' && is_null($manual_transaction)) {
										PlatformOrderTransaction::where(['platform_order_id' => $dborder->id, 'transaction_type' => 'payment', 'transaction_approval' => 'ok', 'row_type' => 'PAYMENT', 'linked_id' => 0])->delete();

										$platform_order_transaction = PlatformOrderTransaction::select('id')->where('platform_order_id', $dborder->id)->where(function ($query) use ($paymentDetail) {
											$query->where('transaction_gateway_id', $paymentDetail['gateway_transaction_id'])->orWhere('transaction_id', $paymentDetail['id']);
										})->first();
										if (is_null($platform_order_transaction)) {
											PlatformOrderTransaction::create(['platform_order_id' => $dborder->id, 'transaction_id' => $paymentDetail['id'], 'transaction_datetime' => $paymentDetail['date_created'], 'transaction_type' => $paymentDetail['event'], 'transaction_method' => $paymentDetail['gateway'], 'transaction_amount' => $amount, 'transaction_approval' => $paymentDetail['status'], 'transaction_reference' => $paymentDetail['reference_transaction_id'], 'transaction_gateway_id' => $paymentDetail['gateway_transaction_id'], 'row_type' => $row_type, 'platform_customer_id' => $dborder->platform_customer_id, 'currency_code' => $paymentDetail['currency']]);
										}
									} elseif ($row_type == 'REFUND') {
										$platform_order_transaction = PlatformOrderTransaction::select('id')->where('platform_order_id', $dborder->id)->where(function ($query) use ($paymentDetail) {
											$query->where('transaction_gateway_id', $paymentDetail['gateway_transaction_id'])->orWhere('transaction_id', $paymentDetail['id']);
										})->first();
										if (is_null($platform_order_transaction)) {
											PlatformOrderTransaction::create(['platform_order_id' => $dborder->id, 'transaction_id' => $paymentDetail['id'], 'transaction_datetime' => $paymentDetail['date_created'], 'transaction_type' => $paymentDetail['event'], 'transaction_method' => $paymentDetail['gateway'], 'transaction_amount' => $amount, 'transaction_approval' => $paymentDetail['status'], 'transaction_reference' => $paymentDetail['reference_transaction_id'], 'transaction_gateway_id' => $paymentDetail['gateway_transaction_id'], 'row_type' => $row_type, 'platform_customer_id' => $dborder->platform_customer_id, 'currency_code' => $paymentDetail['currency']]);
										}
									}

									$total_amount += $amount;
								}

								if ($paymentDetail['event'] == 'refund') {
									$row_type = 'REFUND';
								} else {
									$row_type = 'PAYMENT';
								}
							}

							if ($total_amount == $dborder->total_amount) {
								$dborder->api_order_payment_status = 'paid';
								$dborder->transaction_sync_status = 'Ready';
							} elseif ($total_amount > 0) {
								$dborder->api_order_payment_status = 'partial_paid';
								$dborder->transaction_sync_status = 'Ready';
							} else {
								$dborder->transaction_sync_status = 'Inactive';
								$dborder->api_order_payment_status = 'unpaid';
							}
						} else {
							if ((isset($order['payment_method']) && !empty($order['payment_method'])) || (isset($order['payment_status']) && $order['payment_status'] == 'paid')) {
								if ($consent_of_user) {
									if (isset($consent_of_user->api_id) && $consent_of_user->api_id == 'Yes') {
										$canFill = true;
									}
								} else {
									$canFill = true;
								}

								if ($canFill) {
									$payment_method = $order['payment_method'] ? $order['payment_method'] : 'Manual Payment';
									$platform_order_transaction = PlatformOrderTransaction::where(['platform_order_id' => $dborder->id])->first();
									$amount = bcdiv($order['total_inc_tax'], 1, 2);
									if (is_null($platform_order_transaction)) {
										PlatformOrderTransaction::create(['platform_order_id' => $dborder->id, 'transaction_id' => random_int(999999999, 9999999999), 'transaction_datetime' => date('Y-m-d H:i:s'), 'transaction_type' => 'payment', 'transaction_method' => $payment_method, 'transaction_amount' => $amount, 'transaction_approval' => 'ok', 'transaction_gateway_id' => random_int(999999999, 9999999999), 'row_type' => 'PAYMENT', 'platform_customer_id' => $dborder->platform_customer_id, 'currency_code' => ($order['currency_code']) ? $order['currency_code'] : $order['default_currency_code']]);

										$dborder->api_order_payment_status = 'paid';
										$dborder->transaction_sync_status = 'Ready';
									} else {
										$dborder->api_order_payment_status = 'paid';

										$platform_order_transaction->update(['transaction_method' => $payment_method, 'transaction_amount' => $amount, 'currency_code' => ($order['currency_code']) ? $order['currency_code'] : $order['default_currency_code']]);
									}
								} else {
									$dborder->transaction_sync_status = 'Inactive';
								}
							} else {
								$dborder->transaction_sync_status = 'Inactive';
								$dborder->api_order_payment_status = 'unpaid';
							}
						}
						$dborder->sync_status = 'Ready';
						$dborder->save();
					}
				}
				//DB::commit();
			}
		} catch (\Exception $e) {
			//DB::rollBack();
			\Log::error($user_integration_id . " -> BigcommerceController -> getTransactionInformation -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** GET PAYMENT INFORMATION **
	private function getRefundOrders($is_initial_sync, $user_id, $user_integration_id, $user_workflow_rule_id)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			if ($account && $is_initial_sync == 0) {
				$platform_orders = PlatformOrder::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId,  'refund_sync_status' => 'Pending', 'is_deleted' => 0])->where('linked_id', '<>', 0)->limit(25)->get();
				foreach ($platform_orders as $platform_order) {
					$refunds = static::getAPIOrderRefunds($account, $platform_order->api_order_id);
					if (isset($refunds['data'][0]['id'])) {
						foreach ($refunds['data'] as $refund) {
							$refundId = null;
							$NewRefundOrder = 0;
							$platform_order_refund = PlatformOrderRefund::where(['platform_order_id' => $platform_order->id, 'api_id' => $refund['id']])->first();
							if (is_null($platform_order_refund)) {
								$platform_order_refund = PlatformOrderRefund::create(['user_workflow_rule_id' => $user_workflow_rule_id, 'platform_order_id' => $platform_order->id, 'api_id' => $refund['id'], 'refund_order_number' => $platform_order->order_number, 'date_created' => $refund['created'], 'amount' => $refund['total_amount'], 'sync_status' => 'Ready']);

								$refundId = $platform_order_refund->id;
								$NewRefundOrder = 1;
							} else {
								$refundId = $platform_order_refund->id;
								$platform_order_refund->update(['refund_order_number' => $platform_order->order_number, 'date_created' => $refund['created'], 'amount' => $refund['total_amount']]);
							}

							$RefundOrderLineItemAvailable = 0;
							if ($refundId && isset($refund['items'][0]['item_id'])) {
								// FOR REFUND LINES - MODIFY
								foreach ($refund['items'] as $item) {
									if ($item['item_type'] == 'PRODUCT') {
										$platform_order_line = PlatformOrderLine::where(['platform_order_id' => $platform_order->id, 'api_order_line_id' => $item['item_id']])->first();
										if ($platform_order_line) {
											$platform_order_refund_line = PlatformOrderRefundLine::where(['platform_order_refund_id' => $refundId, 'api_order_line_id' => $item['item_id'], 'row_type' => 'ITEM'])->first();
											if (is_null($platform_order_refund_line)) {
												PlatformOrderRefundLine::create(['platform_order_refund_id' => $refundId, 'api_order_line_id' => $item['item_id'], 'api_product_id' => @$platform_order_line->api_product_id, 'variation_id' => @$platform_order_line->variation_id, 'product_name' => @$platform_order_line->product_name, 'sku' => @$platform_order_line->sku, 'qty' => $item['quantity'], 'price' => @$platform_order_line->price, 'subtotal' => ($item['quantity'] * @$platform_order_line->price), 'total' => ($item['quantity'] * @$platform_order_line->price), 'row_type' => 'ITEM']);

												$platform_order->refund_sync_status = 'Ready';
											} else {
												$platform_order_refund_line->update(['api_product_id' => @$platform_order_line->api_product_id, 'variation_id' => @$platform_order_line->variation_id, 'product_name' => @$platform_order_line->product_name, 'sku' => @$platform_order_line->sku, 'qty' => $item['quantity'], 'price' => @$platform_order_line->price, 'subtotal' => ($item['quantity'] * @$platform_order_line->price), 'total' => ($item['quantity'] * @$platform_order_line->price)]);
											}

											$RefundOrderLineItemAvailable = 1;
										}
									} elseif ($item['item_type'] == 'SHIPPING') {
										$platform_order_refund_line = PlatformOrderRefundLine::where(['platform_order_refund_id' => $refundId, 'api_order_line_id' => $item['item_id'], 'row_type' => 'SHIPPING'])->first();
										if (is_null($platform_order_refund_line)) {
											PlatformOrderRefundLine::create(['platform_order_refund_id' => $refundId, 'api_order_line_id' => $item['item_id'], 'qty' => 1, 'price' => $item['requested_amount'], 'subtotal' => $item['requested_amount'], 'total' => $item['requested_amount'], 'row_type' => 'SHIPPING']);

											$RefundOrderLineItemAvailable = 1;
										} else {
											$platform_order_refund_line->update(['price' => $item['requested_amount'], 'subtotal' => $item['requested_amount'], 'total' => $item['requested_amount']]);
										}
									} elseif ($item['item_type'] == 'GIFT_WRAPPING') {
										$platform_order_refund_line = PlatformOrderRefundLine::where(['platform_order_refund_id' => $refundId, 'api_order_line_id' => $item['item_id'], 'row_type' => 'GIFTWRAPPING'])->first();
										if (is_null($platform_order_refund_line)) {
											PlatformOrderRefundLine::create(['platform_order_refund_id' => $refundId, 'api_order_line_id' => $item['item_id'], 'qty' => 1, 'price' => $item['requested_amount'], 'subtotal' => $item['requested_amount'], 'total' => $item['requested_amount'], 'row_type' => 'GIFTWRAPPING']);

											$RefundOrderLineItemAvailable = 1;
										} else {
											$platform_order_refund_line->update(['price' => $item['requested_amount'], 'subtotal' => $item['requested_amount'], 'total' => $item['requested_amount']]);
										}
									} elseif ($item['item_type'] == 'HANDLING') {
										$platform_order_refund_line = PlatformOrderRefundLine::where(['platform_order_refund_id' => $refundId, 'api_order_line_id' => $item['item_id'], 'row_type' => 'HANDLING'])->first();
										if (is_null($platform_order_refund_line)) {
											PlatformOrderRefundLine::create(['platform_order_refund_id' => $refundId, 'api_order_line_id' => $item['item_id'], 'qty' => 1, 'price' => $item['requested_amount'], 'subtotal' => $item['requested_amount'], 'total' => $item['requested_amount'], 'row_type' => 'HANDLING']);

											$RefundOrderLineItemAvailable = 1;
										} else {
											$platform_order_refund_line->update(['price' => $item['requested_amount'], 'subtotal' => $item['requested_amount'], 'total' => $item['requested_amount']]);
										}
									}
								}
							}

							if ($refundId && $refund['total_tax']) {
								$platform_order_refund_line = PlatformOrderRefundLine::where(['platform_order_refund_id' => $refundId, 'row_type' => 'TAX'])->first();
								if (is_null($platform_order_refund_line)) {
									PlatformOrderRefundLine::create(['platform_order_refund_id' => $refundId, 'qty' => 1, 'price' => $refund['total_tax'], 'subtotal' => $refund['total_tax'], 'total' => $refund['total_tax'], 'row_type' => 'TAX']);

									$RefundOrderLineItemAvailable = 1;
								} else {
									$platform_order_refund_line->update(['price' => $refund['total_tax'], 'subtotal' => $refund['total_tax'], 'total' => $refund['total_tax']]);
								}
							}

							if ($refundId && isset($refund['payments'][0]['provider_id'])) {
								// FOR REFUND LINES - MODIFY
								foreach ($refund['payments'] as $payment) {
									if ($payment['is_declined'] == 0 || $payment['is_declined'] == false) {
										$platform_order_transaction = PlatformOrderTransaction::where(['platform_order_id' => $platform_order->id, 'platform_order_refund_id' => $refundId, 'transaction_gateway_id' => $payment['provider_id']])->first();
										if (is_null($platform_order_transaction)) {
											PlatformOrderTransaction::create(['platform_order_id' => $platform_order->id, 'platform_order_refund_id' => $refundId, 'api_transaction_index_id' => $payment['id'], 'transaction_id' => $payment['id'], 'transaction_gateway_id' => $payment['provider_id'], 'transaction_amount' => $payment['amount'], 'row_type' => 'REFUND', 'sync_status' => 'Ready']);

											$platform_order_refund->sync_status = 'Ready';
										} else {
											$platform_order_transaction->update(['api_transaction_index_id' => $payment['id'], 'transaction_id' => $payment['id'], 'transaction_amount' => $payment['amount'], 'row_type' => 'REFUND']);
										}
									}
								}
							}

							$platform_order_refund->save();

							if ($NewRefundOrder == 1 && $RefundOrderLineItemAvailable == 1) {
								$platform_order->refund_sync_status = 'Ready';
							}
						}
					} else {
						$platform_order->refund_sync_status = 'Inactive';
					}

					$platform_order->save();

					sleep(1);
				}
			}
		} catch (\Exception $e) {
			//DB::rollBack();
			\Log::error($user_integration_id . " -> BigcommerceController -> getRefundOrders -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** GET SALES ORDER **
	private function getSalesOrder($is_initial_sync, $user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id, $insertProducts = true)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$sync_start_date = date('Y-m-d H:i:s');
			$getFlowEvents = $this->workflowSnippet->getWorkflowEvents($user_workflow_rule_id);
			if ($getFlowEvents && $getFlowEvents->sync_start_date) {
				$sync_start_date = str_replace(' ', 'T', trim($getFlowEvents->sync_start_date));
			}
			$sync_start_date = new DateTime($sync_start_date);
			$sync_start_date = $sync_start_date->format(DateTime::ATOM);
			$statusToSelectForOrders = $this->fieldMapHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "get_sorder_status", ['api_id'], 'regular', '', 'multiple');
			$consent_of_user = $this->fieldMapHelper->getMappedDataByName($user_integration_id, null, "custom_payment_consent", ['api_id']);

			if ($is_initial_sync && $account && is_array($statusToSelectForOrders) && count($statusToSelectForOrders)) {
				$env = (env('APP_ENV') == 'prod') ? 'prod' : 'stag';
				$webhook_data = ['scope' => 'store/order/*', 'destination' => env('APP_WEBHOOK_URL') . "/bigcommerce/index.php?for=order&uid=$user_integration_id&env=$env", 'is_active' => true];
				$this->setWebhook($account, $webhook_data, $user_id, $user_integration_id);

				$params = ['min_date_created' => $sync_start_date];
				$insertUrl = false;
				$orderUrlIds = [];
				$orderUrls = PlatformUrl::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => 'sales_orders', 'status' => 0])->limit(2)->get();
				if ($orderUrls->count() == 0) {
					$alreadySyncedOrderUrls = PlatformUrl::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => 'sales_orders', 'status' => 1])->count();
					if ($alreadySyncedOrderUrls == 0) {
						$insertUrl = true;
					}
				} else {
					$min_id = $max_id = null;
					foreach ($orderUrls as $orderUrl) {
						if ($orderUrl->url) {
							$urlArr = explode(",", $orderUrl->url);
							if (is_array($urlArr) && count($urlArr)) {
								foreach ($urlArr as $urlid) {
									$min_id = (is_null($min_id)) ? $urlid : (($min_id > $urlid) ? $urlid : $min_id);
									$max_id = (is_null($max_id)) ? $urlid : (($max_id < $urlid) ? $urlid : $max_id);
								}
							}
						}
						$orderUrlIds[] = $orderUrl->id;
					}
					$params['min_id'] = $min_id;
					$params['max_id'] = $max_id;
				}

				if (is_array($params) && (count($params) > 1 || $insertUrl)) {
					$apiorders = static::getAPISalesOrder($account, $params);
					if (is_array($apiorders)) {
						$apiorders = array_chunk($apiorders, 5, true);
						$productIdsArr = [];
						foreach ($apiorders as $apiorder) {
							if (is_array($apiorder)) {
								if ($insertUrl) {
									$urlStr = array_column($apiorder, 'id');
									if (is_array($urlStr)) {
										$urlStr = implode(",", $urlStr);
									}
									if (is_string($urlStr)) {
										$checkUrl = PlatformUrl::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url' => $urlStr])->first();
										if (!$checkUrl) {
											PlatformUrl::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => 'sales_orders', 'url' => $urlStr]);
										}
									}
								} else {
									foreach ($apiorder as $order) {
										//Cancelled = 5
										//Refunded = 4
										//Partially Refunded = 14
										$statusToIgnoreForOrders = [4, 5, 14];
										if (in_array($order['status_id'], $statusToSelectForOrders) && $order['is_deleted'] == 0 && !in_array($order['status_id'], $statusToIgnoreForOrders)) {
											$productIds = $this->createOrderDatabaseEntryForGetOrders($user_id, $user_integration_id, $user_workflow_rule_id, $account, $order, $consent_of_user);
											if (is_array($productIds)) {
												$productIdsArr = array_merge($productIds, $productIdsArr);
											}
										}
									}
								}
							}
						}

						if (count($productIdsArr) && $insertProducts) {
							$productIdsArr = array_unique($productIdsArr);
							sort($productIdsArr);
							$params = ['id:in' => implode(',', $productIdsArr), 'include' => 'variants,custom_fields,bulk_pricing_rules,modifiers,options'];
							$canProductRun = true;
							$params['page'] = 1;
							do {
								$products = static::getAPIProducts($account, $params);
								if (isset($products['data'][0]['id'])) {
									$response = $this->addProductsToDatabase($user_id, $user_integration_id, $user_workflow_rule_id, $products);
									if (isset($products['meta']) && isset($products['meta']['pagination'])) {
										if ($products['meta']['pagination']['total_pages'] > $params['page']) {
											$params['page'] = $params['page'] + 1;
										} else {
											$canProductRun = false;
										}
									} else {
										$canProductRun = false;
									}
								} else {
									$canProductRun = false;
									$response = true;
								}
							} while ($canProductRun);
						}

						if ($insertUrl) {
							$response = 'Orders are getting ready to inserted.';
						} else {
							if (count($orderUrlIds)) {
								PlatformUrl::whereIn('id', $orderUrlIds)->update(['status' => 1]);
								$countUrl = count($orderUrlIds) * 5;
								$response = $countUrl . ' orders inserted';
							}
						}
					} else {
						$response = $apiorders;
					}
				}
			} elseif ($account && is_array($statusToSelectForOrders) && count($statusToSelectForOrders)) {
				$dbPendingOrder = PlatformOrder::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_type' => 'SO', 'sync_status' => 'Pending', 'is_deleted' => 0])->select('id', 'api_order_id', 'linked_id')->limit(20)->get();
				if ($dbPendingOrder) {
					$productIdsArr = [];
					foreach ($dbPendingOrder as $orderToFetch) {
						$order = static::getAPISalesOrderFromId($account, $orderToFetch->api_order_id);
						if (is_array($order) && isset($order['date_created'])) {
							if ($order['is_deleted'] == 0) {
								if (strtotime($order['date_created']) >= strtotime($sync_start_date)) {
									if ($orderToFetch->linked_id) {
										$productIds = $this->createOrderDatabaseEntryForGetOrders($user_id, $user_integration_id, $user_workflow_rule_id, $account, $order, $consent_of_user);
										if (is_array($productIds)) {
											$productIdsArr = array_merge($productIds, $productIdsArr);
										}
									} else {
										//Cancelled = 5
										//Refunded = 4
										//Partially Refunded = 14
										$statusToIgnoreForOrders = [4, 5, 14];

										if (in_array($order['status_id'], $statusToSelectForOrders) && !in_array($order['status_id'], $statusToIgnoreForOrders)) {
											$productIds = $this->createOrderDatabaseEntryForGetOrders($user_id, $user_integration_id, $user_workflow_rule_id, $account, $order, $consent_of_user);
											if (is_array($productIds)) {
												$productIdsArr = array_merge($productIds, $productIdsArr);
											}
										} else {
											$orderToFetch->delete();
										}
									}
								} else {
									$orderToFetch->delete();
								}
							} else {
								if ($orderToFetch->linked_id) {
									$orderToFetch->order_status = 'Deleted';
									$orderToFetch->is_deleted = 1;
									$orderToFetch->is_voided = 1;
									$orderToFetch->sync_status = 'Ready';
									$orderToFetch->save();
								} else {
									$orderToFetch->delete();
								}
							}
						} else {
							//$orderToFetch->sync_status = 'Inactive';
							//$orderToFetch->save();
						}
					}
					if (count($productIdsArr) && $insertProducts) {
						$productIdsArr = array_unique($productIdsArr);
						sort($productIdsArr);
						$params = ['id:in' => implode(',', $productIdsArr), 'include' => 'variants,custom_fields,bulk_pricing_rules,modifiers,options'];
						$canProductRun = true;
						$params['page'] = 1;
						do {
							$products = static::getAPIProducts($account, $params);
							if (isset($products['data'][0]['id'])) {
								$response = $this->addProductsToDatabase($user_id, $user_integration_id, $user_workflow_rule_id, $products);
								if (isset($products['meta']) && isset($products['meta']['pagination'])) {
									if ($products['meta']['pagination']['total_pages'] > $params['page']) {
										$params['page'] = $params['page'] + 1;
									} else {
										$canProductRun = false;
									}
								} else {
									$canProductRun = false;
								}
							} else {
								$canProductRun = false;
								$response = true;
							}
						} while ($canProductRun);
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> getSalesOrder -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** GET SALES ORDER BACKUP**
	private function getSalesOrderBackup($is_initial_sync, $user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$sync_start_date = date('Y-m-d H:i:s');
			$getFlowEvents = $this->workflowSnippet->getWorkflowEvents($user_workflow_rule_id);
			if ($getFlowEvents && $getFlowEvents->sync_start_date) {
				$sync_start_date = str_replace(' ', 'T', trim($getFlowEvents->sync_start_date));
			}
			$sync_start_date = new DateTime($sync_start_date);
			$sync_start_date = $sync_start_date->format(DateTime::ATOM);
			$order_status_ids = $this->fieldMapHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "get_sorder_status", ['api_id'], 'regular', '', 'multiple');

			if ($is_initial_sync == 0 && $account && is_array($order_status_ids) && count($order_status_ids)) {
				$params = ['min_date_created' => $sync_start_date, 'sort' => 'date_modified:asc', 'limit' => 250];

				$platform_url = PlatformUrl::select('id', 'url')->where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => 'sales_order_backup'])->first();
				if ($platform_url) {
					$min_date_modified = new DateTime($platform_url->url);
					$min_date_modified->modify('-1 second');
					$min_date_modified = $min_date_modified->format(DateTime::ATOM);

					$params['min_date_modified'] = $min_date_modified;
				} else {
					$min_date_modified = date("Y-m-d H:i:s", strtotime('-2 hours'));
					$min_date_modified = new DateTime($min_date_modified);
					$min_date_modified = $min_date_modified->format(DateTime::ATOM);

					$params['min_date_modified'] = $min_date_modified;
				}

				$sales_orders = static::getAPISalesOrder($account, $params);
				if (isset($sales_orders[0]['id'])) {
					$new_date_modified = NULL;
					foreach ($sales_orders as $sales_order) {
						$new_date_modified = $sales_order['date_modified'];
						if (in_array($sales_order['status_id'], $order_status_ids)) {
							$platform_order = PlatformOrder::select('id', 'api_updated_at')->where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_type' => 'SO', 'api_order_id' => $sales_order['id']])->first();
							if ($platform_order) {
								if ($platform_order->api_updated_at != $sales_order['date_modified']) {
									PlatformOrder::where('id', $platform_order->id)
										->update(['sync_status' => 'Pending']);
								}
							} else {
								if ($sales_order['is_deleted'] == 0) {
									PlatformOrder::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_type' => 'SO', 'api_order_id' => $sales_order['id'], 'sync_status' => 'Pending']);
								}
							}
						}
					}

					if ($new_date_modified) {
						if ($platform_url) {
							PlatformUrl::where('id', $platform_url->id)
								->update(['url' => $new_date_modified, 'allow_retain' => 1]);
						} else {
							PlatformUrl::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url_name' => 'sales_order_backup', 'url' => $new_date_modified, 'allow_retain' => 1]);
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> getSalesOrderBackup -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** WEBHOOK ORDER CREATE / UPDATE **
	public function webhookBigcommerceOrders(Request $request, $user_integration_id)
	{
		$response = true;
		$data = [];
		try {
			if ($user_integration_id) {
				$user_integration = $this->fieldMapHelper->getUserIntegrationDetailsById($user_integration_id, self::PLATFORMNAME);
				$data = $request->getContent();
				//{"producer":"stores/boley3bvsj","hash":"0820c4188e2d7a18938cab934526d12163a3a975","created_at":1691736794,"store_id":"1002817942","scope":"store/order/updated","data":{"type":"order","id":100}}
				$data = json_decode($data, true);
				if ($user_integration && isset($data['scope']) && isset($data['data']['id'])) {
					$api_order_id = $data['data']['id'];
					if ($data['data']['type'] == 'order' && $api_order_id) {
						$user_work_flow = DB::table('user_workflow_rule as ur')
							->select('e.event_id', 'ur.platform_workflow_rule_id')
							->join('platform_workflow_rule as pr', 'ur.platform_workflow_rule_id', '=', 'pr.id')
							->join('platform_events as e', 'pr.source_event_id', '=', 'e.id')
							->where('pr.status', 1)
							->where('ur.status', 1)
							->where('e.status', 1)
							->where('ur.user_id', $user_integration->user_id)
							->where('ur.user_integration_id', $user_integration_id);
						$user_workflow = $user_work_flow->pluck('e.event_id')->toArray();
						if (in_array('GET_SALESORDER', $user_workflow) || in_array('GET_TRANSACTION', $user_workflow) || in_array('GET_REFUND', $user_workflow)) {
							$platform_order = PlatformOrder::where(['api_order_id' => $api_order_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'order_type' => 'SO'])->first();
							if (($data['scope'] == 'store/order/created' || $data['scope'] == 'store/order/updated' || $data['scope'] == 'store/order/statusUpdated') && in_array('GET_SALESORDER', $user_workflow)) {
								if ($platform_order) {
									$platform_order->sync_status = 'Pending';
									$platform_order->save();
								} else {
									PlatformOrder::create(['user_id' => $user_integration->user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_type' => 'SO', 'api_order_id' => $api_order_id, 'sync_status' => 'Pending']);
								}
							} elseif (($data['scope'] == 'store/order/transaction/created' || $data['scope'] == 'store/order/transaction/updated') && $api_order_id && in_array('GET_TRANSACTION', $user_workflow)) {
								if ($platform_order) {
									$platform_order->transaction_sync_status = 'Pending';
									$platform_order->save();
								}
							} elseif ($data['scope'] == 'store/order/archived' && $api_order_id && in_array('GET_SALESORDER', $user_workflow)) {
								if ($platform_order) {
									if ($platform_order->linked_id) {
										$platform_order->order_status = 'Deleted';
										$platform_order->is_deleted = 1;
										$platform_order->is_voided = 1;
										$platform_order->sync_status = 'Ready';
										$platform_order->save();
									} else {
										$platform_order->delete();
									}
								}
							} elseif (($data['scope'] == '' || $data['scope'] == 'store/order/refund/created') && in_array('GET_REFUND', $user_workflow)) {
								if ($platform_order) {
									$platform_order->refund_sync_status = 'Pending';
									$platform_order->save();
								}
							}
						}
					}
				}
			} else {
				$response = "No integration";
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> webhookBigcommerceOrders -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** Inventory Update **
	private function syncUpdatedInventoryToBigCommerce($user_id = null, $user_integration_id = null, $platform_workflow_id = NULL, $user_workflow_rule_id = NULL, $source_platform_name = NULL, $record_id = 0)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$source_platform_id = $this->connectionHelper->getPlatformIdByName($source_platform_name);
			$inventory_object_id = $this->connectionHelper->getObjectId('inventory');
			$invWarehouseIds = $this->fieldMapHelper->getMappedDataByName($user_integration_id, null, "inventory_warehouse", ['api_id'], 'regular', '', 'multiple');
			if ($account && $source_platform_id) {
				$source_row_data = $destination_row_data = 'sku';
				$product_identity_obj_id = $this->connectionHelper->getObjectId('product_identity');
				$mapping_data = $this->fieldMapHelper->getMappedField($user_integration_id, NULL, $product_identity_obj_id);
				if ($mapping_data) {
					if ($mapping_data['destination_platform_id'] == 'bigcommerce') {
						$destination_row_data = $mapping_data['destination_row_data'];
						$source_row_data = $mapping_data['source_row_data'];
					} else {
						$destination_row_data = $mapping_data['source_row_data'];
						$source_row_data = $mapping_data['destination_row_data'];
					}
				}

				if ($record_id) {
					$inventories = PlatformProduct::where('id', $record_id)->get();
				} else {
					$inventories = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'inventory_sync_status' => 'Ready', 'is_deleted' => 0])->where('linked_id', '<>', 0)->limit(25)->get();
				}

				if ($inventories) {
					foreach ($inventories as $inventory) {
						if ($inventory->linked_id) {
							$child_product = PlatformProduct::find($inventory->linked_id);
							if ($child_product->inventory_tracking == 'PRODUCT' || ($child_product->inventory_tracking == 'VARIANT' && !$child_product->parent_product_id)) {
								$apidata = [
									'name' => $inventory->product_name,
									'type' => 'physical', // digital
									'sku' => $inventory->sku,
									'description' => $inventory->description
								];

								$apidata[$destination_row_data] = $inventory->{$source_row_data};

								if (is_array($invWarehouseIds) && count($invWarehouseIds)) {
									$productInventories = PlatformProductInventory::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'api_product_id' => $inventory->api_product_id])->whereIn('api_warehouse_id', $invWarehouseIds)->get();
									if ($productInventories) {
										$inventoryData = [
											'inventory_tracking' => 'product', // none, variant
											'inventory_level' => 0,
											'availability' => 'available'
										];
										foreach ($productInventories as $productInventory) {
											$inventoryData['inventory_level'] = $productInventory->quantity + $inventoryData['inventory_level'];
										}
									} else {
										$inventoryData = [
											'inventory_tracking' => 'product', // none, variant
											'inventory_level' => 0,
											'availability' => 'available'
										];
									}
									$apidata = array_merge($inventoryData, $apidata);
									$apiresponse = static::updateAPIProduct($account, $apidata, $child_product->api_product_id);
								} else {
									$apiresponse = "Warehouses must be selected";
								}
							} else {
								$apiresponse = null;
								if ($child_product && $child_product->inventory_tracking == 'VARIANT' && $child_product->parent_product_id) {
									if (is_array($invWarehouseIds) && count($invWarehouseIds)) {
										$productInventories = PlatformProductInventory::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'api_product_id' => $inventory->api_product_id])->whereIn('api_warehouse_id', $invWarehouseIds)->get();
										$apidata = ['inventory_level' => 0];
										if ($productInventories) {
											foreach ($productInventories as $productInventory) {
												$apidata['inventory_level'] = $productInventory->quantity + $apidata['inventory_level'];
											}
										}
										$apiresponse = static::updateAPIProductVariantById($account, $apidata, $child_product->api_product_id, $child_product->api_variant_id);
									} else {
										$apiresponse = "Warehouses must be selected";
									}
								}
								if (is_null($apiresponse)) {
									$apiresponse = 'Error in syncing product inventory.';
								}
							}

							if (isset($apiresponse['data']['id'])) {
								$apiresponse = 'Inventory Updated Success';
								$inventory->inventory_sync_status = 'Synced';
								$message = $apiresponse;
								$status = 'success';
							} else {
								if (!is_array($apiresponse) && $apiresponse) {
									$message = $apiresponse;
								} else {
									$message = 'Error in syncing product inventory.';
								}
								$response = $message;
								$status = 'failed';
								$inventory->inventory_sync_status = 'Failed';
							}
							$inventory->save();
							$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $inventory_object_id, $status, $inventory->id, $message);
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> syncUpdatedInventoryToBigCommerce -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	private function setWebhook($platform_account, $webhook_data, $user_id, $user_integration_id)
	{
		$platform_webhook_info = PlatformWebhookInformation::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'description' => $webhook_data['scope']])->first();
		if (is_null($platform_webhook_info)) {
			$response = static::setAPIWebhook($platform_account, $webhook_data);
			if (isset($response['data']['id']) && isset($response['data']['scope'])) {
				PlatformWebhookInformation::create(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_id' => $response['data']['id'], 'description' => $response['data']['scope']]);
				return true;
			} else {
				return 'Webhook is not created.';
			}
		}
		return true;
	}

	/* Delete Webhook */
	public function DeleteWebhooks($user_id = NULL, $user_integration_id = NULL)
	{
		$return_response = false;
		try {
			$platform_account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			if ($platform_account) {
				//delete webhook
				$platform_webhooks = PlatformWebhookInformation::select('id', 'api_id', 'description')->where('user_integration_id', $user_integration_id)->where('platform_id', $this->platformId)->where('status', 1)->get();
				foreach ($platform_webhooks as $platform_webhook) {
					$result = static::deleteAPIWebhook($platform_account, $platform_webhook->api_id);
					if (isset($result['data']['id'])) {
						$this->mainModel->makeDelete('platform_webhook_info', ['id' => $platform_webhook->id]);
						$return_response = true;
					} else {
						$return_response = "Webhook not delete for " . $platform_webhook->description . " event";
					}

					$return_response = true;
				}
				$return_response = true;
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> DeleteWebhooks -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	// ** Sync Customers To BigCommerce **
	private function syncCustomersToBigCommerce($is_initial_sync, $user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $record_id)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$source_platform_id = $this->connectionHelper->getPlatformIdByName($source_platform_name);
			$pricelist_object_id = $this->connectionHelper->getObjectId('pricelist');
			$pricelist_grp_object_id = $this->connectionHelper->getObjectId('pricelist_group');
			$customer_object_id = $this->connectionHelper->getObjectId('customer');
			if ($account && $source_platform_id && $customer_object_id) {
				$def_pass = '';
				$default_password = $this->fieldMapHelper->getMappedDataByName($user_integration_id, NULL, "custom_field", ['custom_data'], "default");
				if ($default_password) {
					$def_pass = $default_password->custom_data;
				}

				if ($record_id) {
					$customers = PlatformCustomer::where('id', $record_id);
				} else {
					$customers = PlatformCustomer::where(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'sync_status' => 'Ready']);
				}

				$customers = $customers->limit(80)->get();
				if ($customers) {
					foreach ($customers as $customer) {
						if ($customer->customer_name) {
							$customer_name = explode(" ", $customer->customer_name);
							$fname = isset($customer_name[0]) ? $customer_name[0] : $customer->first_name;
							$lname = (isset($customer_name[1]) && !empty($customer_name[1])) ? $customer_name[1] : ((isset($customer_name[2]) && !empty($customer_name[2])) ? $customer_name[2] : $customer->last_name);
						} else {
							$fname = $customer->first_name;
							$lname = $customer->last_name;
						}

						$state = $customer->address3;
						$stateData = PlatformStates::where(['iso2' => $state, 'country_code' => $customer->country])->select('name')->first();
						if ($stateData) {
							$state = $stateData->name;
						}

						$apidata = [
							"email" => $customer->email,
							"first_name" => $fname,
							"last_name" => $lname,
							"company" => $customer->company_name,
							"phone" => $customer->phone,
							// "notes"=>"string",
							// "tax_exempt_category"=>"string",
							"addresses" => [
								[
									"address1" => $customer->address1,
									// "address2"=>$customer->address2,
									"address_type" => 'residential', // commercial
									"city" => $customer->address2,
									"company" => $customer->company_name,
									"country_code" => $customer->country,
									"first_name" => $fname,
									"last_name" => $lname,
									"phone" => $customer->phone,
									"postal_code" => $customer->postal_addresses,
									"state_or_province" => $state
								]
							],
							"authentication" => ["force_password_reset" => true, "new_password" => $def_pass],
							"accepts_product_review_abandoned_cart_emails" => false,
						];

						if ($pricelist_object_id && $pricelist_grp_object_id) {
							$sc_pricelist_name = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'platform_object_id' => $pricelist_object_id, 'api_id' => $customer->api_customer_group_id, 'status' => 1])->select('name')->first();
							if ($sc_pricelist_name) {
								$dc_pricelist_api_id = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $pricelist_grp_object_id, 'name' => $sc_pricelist_name->name, 'status' => 1])->select('api_id')->first();
								if ($dc_pricelist_api_id) {
									$apidata['customer_group_id'] = intval($dc_pricelist_api_id->api_id);
								}
							}
						}

						if ($customer->linked_id) {
							$linked_customer = PlatformCustomer::find($customer->linked_id);
							$apidata['id'] = intval($linked_customer->api_customer_id);
							$customerresponse = $this->createUpdateAPICustomer($account, [$apidata], true);
							if ($customerresponse && isset($customerresponse['data'])) {
								$customerresponse = $customerresponse['data'][0];
								$customer_name = $customer->first_name . ' ' . $customer->last_name;
								$linked_customer->customer_name = $customer_name;
								$linked_customer->first_name = $fname;
								$linked_customer->last_name = $lname;
								$linked_customer->company_name = $customer->company_name;
								$linked_customer->phone = $customer->phone;
								$linked_customer->fax = $customer->fax;
								$linked_customer->email = $customer->email;
								$linked_customer->address1 = $customer->address1;
								$linked_customer->address2 = $customer->address2;
								$linked_customer->address3 = $state;
								$linked_customer->postal_addresses = $customer->postal_addresses;
								$linked_customer->country = $customer->country;
								$linked_customer->company_id = $customer->company_id;
								$linked_customer->sync_status = 'Pending';
								$linked_customer->api_updated_at = $customerresponse['date_modified'];
								$linked_customer->save();

								$status = 'success';
								$message = 'Customer Updated.';

								$customer->sync_status = 'Synced';
								$customer->save();
							} else {
								$status = 'failed';
								if ($customerresponse) {
									$message = $customerresponse;
								} else {
									$message = 'Customer Not Updated.';
								}
								$response = $message;

								$customer->sync_status = 'Failed';
								$customer->save();
							}
						} else {
							$apidata["origin_channel_id"] = 1;
							$apidata["channel_ids"] = [1];
							$linked_customer = new PlatformCustomer();
							$customerresponse = $this->createUpdateAPICustomer($account, [$apidata]);
							if ($customerresponse && isset($customerresponse['data'])) {
								$customerresponse = $customerresponse['data'][0];
								$customer_name = $customer->first_name . ' ' . $customer->last_name;
								$linked_customer->user_id = $user_id;
								$linked_customer->user_integration_id = $user_integration_id;
								$linked_customer->platform_id = $this->platformId;
								$linked_customer->api_customer_id = $customerresponse['id'];
								$linked_customer->api_customer_group_id = $customerresponse['customer_group_id'];
								$linked_customer->customer_name = $customer_name;
								$linked_customer->first_name = $fname;
								$linked_customer->last_name = $lname;
								$linked_customer->company_name = $customer->company_name;
								$linked_customer->phone = $customer->phone;
								$linked_customer->fax = $customer->fax;
								$linked_customer->email = $customer->email;
								$linked_customer->address1 = $customer->address1;
								$linked_customer->address2 = $customer->address2;
								$linked_customer->address3 = $state;
								$linked_customer->postal_addresses = $customer->postal_addresses;
								$linked_customer->country = $customer->country;
								$linked_customer->company_id = $customer->company_id;
								$linked_customer->sync_status = 'Pending';
								$linked_customer->api_created_at = $customerresponse['date_created'];
								$linked_customer->api_updated_at = $customerresponse['date_modified'];
								$linked_customer->linked_id = $customer->id;
								$linked_customer->save();

								$status = 'success';
								$message = 'Customer Added.';

								$customer->linked_id = $linked_customer->id;
								$customer->sync_status = 'Synced';
								$customer->save();
							} else {
								$status = 'failed';
								if ($customerresponse) {
									$message = $customerresponse;
								} else {
									$message = 'Customer Not Added.';
								}
								$response = $message;

								$customer->sync_status = 'Failed';
								$customer->save();
							}
						}

						$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $customer_object_id, $status, $customer->id, $message);
						unset($apidata);
						unset($linked_customer);
					}
					unset($customers);
				}
				unset($account);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> syncCustomersToBigCommerce -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	//Sync Product Or Variant To BigCommerce 
	private function syncProductOrVariantToBigCommerce($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $record_id)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$source_platform_id = $this->connectionHelper->getPlatformIdByName($source_platform_name);
			if ($account && $source_platform_id) {
				if ($record_id) {
					$products = PlatformProduct::where('id', $record_id);
				} else {
					$products = PlatformProduct::where(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'product_sync_status' => 'Ready']);
				}

				$products = $products->limit(25)->where('is_deleted', 0)->orderBy('has_variations', 'asc')->orderByRaw('CONVERT(api_product_id, SIGNED) asc')->get();
				foreach ($products as $product) {
					$checkVariantProducts = PlatformProduct::select('api_product_id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'has_variations' => 1, 'api_group_id' => $product->api_group_id, 'is_deleted' => 0])->where('id', '!=', $product->id)->whereNotNull('api_group_id')->count('api_product_id');

					$checkParentProduct = PlatformProduct::select('id', 'api_product_id', 'has_variations')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'api_group_id' => $product->api_group_id, 'is_deleted' => 0])->whereNotNull('api_group_id')->orderBy('has_variations', 'asc')->orderByRaw('CONVERT(api_product_id, SIGNED) asc')->first();

					if ($product->has_variations == 0 && $product->api_group_id) {
						$response = $this->syncProductsToBigCommerce($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $product);
					} elseif ($product->has_variations == 1 && $checkParentProduct && $checkParentProduct->id == $product->id && $product->api_group_id) {
						if ($checkVariantProducts) {
							$response = $this->syncProductsToBigCommerce($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $product, true);

							if ($response === true) {
								$response = $this->syncVariantsToBigCommerce($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $product);
							}
						} else {
							$response = $this->syncProductsToBigCommerce($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $product);
						}
					} elseif ($product->has_variations == 1 && $checkVariantProducts && $checkParentProduct->id != $product->id && $product->api_group_id) {
						$response = $this->syncVariantsToBigCommerce($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $product);
					} else {
						$response = $this->syncProductsToBigCommerce($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $product);
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> syncProductOrVariantToBigCommerce -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** Sync Product To BigCommerce **
	private function syncProductsToBigCommerce($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $product, $varianceProduct = false)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$source_platform_id = $this->connectionHelper->getPlatformIdByName($source_platform_name);
			$product_object_id = $this->connectionHelper->getObjectId('product');
			$category_object_id = $this->connectionHelper->getObjectId('category');
			$brand_object_id = $this->connectionHelper->getObjectId('brand');
			$product_identity_obj_id = $this->connectionHelper->getObjectId('product_identity');
			$invWarehouseIds = $this->fieldMapHelper->getMappedDataByName($user_integration_id, null, "inventory_warehouse", ['api_id'], 'regular', '', 'multiple');

			$priceObjects = null;
			$pricelist_object_id = $this->connectionHelper->getObjectId('pricelist');
			if ($pricelist_object_id) {
				$priceObjects = PlatformObjectData::where(['platform_id' => $this->platformId, 'platform_object_id' => $pricelist_object_id, 'user_integration_id' => 0, 'status' => 1])->get();
			}

			if ($account && $source_platform_id && $product_object_id && $category_object_id && $brand_object_id && $product_identity_obj_id && $product) {
				$categoryCheck = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $category_object_id, 'status' => 1];
				$brandCheck = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $brand_object_id, 'status' => 1];

				$source_row_data = $destination_row_data = 'sku';
				$mapping_data = $this->fieldMapHelper->getMappedField($user_integration_id, null, $product_identity_obj_id);
				if ($mapping_data) {
					if ($mapping_data['destination_platform_id'] == self::PLATFORMNAME) {
						$destination_row_data = $mapping_data['destination_row_data'];
						$source_row_data = $mapping_data['source_row_data'];
					} else {
						$destination_row_data = $mapping_data['source_row_data'];
						$source_row_data = $mapping_data['destination_row_data'];
					}
				}

				$isUpdate = false;
				$child_product = null;
				if ($product->linked_id) {
					$child_product = PlatformProduct::find($product->linked_id);
					$isUpdate = true;
				} else {
					// -- CHECK FOR THE PRODUCT FROM THE UNIQUE FIELD --
					$child_product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'inventory_tracking' => 'PRODUCT', $destination_row_data => $product->{$source_row_data}, 'is_deleted' => 0])->first();
					if ($child_product) {
						$isUpdate = true;
					}
				}

				//PRICE MAPPING
				$priceData = $this->getMappedPriceListArray($product->id, $user_integration_id);
				if (!isset($priceData['price'])) {
					if ($product->price) {
						$priceData['price'] = $product->price;
					} else {
						$priceData['price'] = 0;
					}
				}

				//inventory_tracking ['none', 'product', 'variant'];
				$inventoryData = ['inventory_tracking' => 'product', 'inventory_level' => 0, 'availability' => 'available'];
				if ($product->inventory_sync_status != 'Pending') {
					if (is_array($invWarehouseIds) && count($invWarehouseIds)) {
						$productInventories = PlatformProductInventory::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'api_product_id' => $product->api_product_id])->whereIn('api_warehouse_id', $invWarehouseIds)->get();
						if ($productInventories) {
							foreach ($productInventories as $productInventory) {
								$inventoryData['inventory_level'] = $productInventory->quantity + $inventoryData['inventory_level'];
							}
						}
					}
				}

				$checkVariantProducts = PlatformProduct::select('api_product_id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'has_variations' => 1, 'api_group_id' => $product->api_group_id, 'is_deleted' => 0])->where('id', '!=', $product->id)->whereNotNull('api_group_id')->get();
				if (count($checkVariantProducts) && $product->api_group_id) {
					$inventoryData['inventory_tracking'] = 'variant'; // change the tracking to variant
					if (is_array($invWarehouseIds) && count($invWarehouseIds)) {
						foreach ($checkVariantProducts as $checkVariantProduct) {
							$productInventories = PlatformProductInventory::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'api_product_id' => $checkVariantProduct->api_product_id])->whereIn('api_warehouse_id', $invWarehouseIds)->get();
							if ($productInventories) {
								foreach ($productInventories as $productInventory) {
									$inventoryData['inventory_level'] = $productInventory->quantity + $inventoryData['inventory_level'];
								}
							}
						}
					}
				}

				$categories = [];
				if ($product->category_id) {
					$categories = $this->getOrCreateCategory($account, $product->category_id, $categoryCheck, $source_platform_id);
				}

				$brand = 0;
				if ($product->brand_id) {
					$brand = $this->getOrCreateBrand($account, $product->brand_id, $brandCheck, $source_platform_id);
				}

				$apidata = [
					'name' => $product->product_name,
					'type' => 'physical', // digital
					//'sku'=>$product->sku,
					'description' => $product->description,
					'weight' => $product->weight,
					// 'tax_class_id'=>,
					// 'product_tax_code'=>,
					'categories' => $categories,
					'brand_id' => $brand,
					// 'fixed_cost_shipping_price'=>,
					// 'is_free_shipping'=>,
					'is_visible' => true,
					// 'is_featured'=>,
					// 'related_products'=>,
					// 'warranty'=>,
					// 'bin_picking_number'=>,
					// 'layout_file'=>,
					'upc' => ($product->upc) ? $product->upc : '',
					// 'condition'=>,
					// 'is_condition_shown'=>,
					'gtin' => $product->gtin,
					'mpn' => $product->mpn
				];

				if ($varianceProduct == false && $product->sku) {
					$apidata['sku'] = $product->sku;
				}

				$apidata[$destination_row_data] = $product->{$source_row_data};

				$apidata = array_merge($priceData, $apidata); // MERGE PRICE TO DATA
				$apidata = array_merge($inventoryData, $apidata); // MERGE INVENTORY TO DATA

				// EXTRA INFO
				$detailsAttributes = PlatformProductDetailAttribute::where('platform_product_id', $product->id)->first();
				if ($detailsAttributes) {
					$apidata['width'] = $detailsAttributes->width;
					$apidata['height'] = $detailsAttributes->height;
					$apidata['depth'] = $detailsAttributes->lenght;
				}

				if ($child_product) {
					$customFields = $this->getUpdatedCustomFieldsForProduct($user_integration_id, $source_platform_id, $product->id, $child_product->id);
					if (isset($customFields['custom_fields']) && count($customFields['custom_fields'])) {
						$apidata = array_merge($customFields, $apidata);
					}
				} else {
					$customFields = $this->getCreatedCustomFieldsForProduct($user_integration_id, $source_platform_id, $product->id);
					if (isset($customFields['custom_fields']) && count($customFields['custom_fields'])) {
						$apidata = array_merge($customFields, $apidata);
					}
				}

				$apiresponse = 'Error in product syncing.';
				if ($isUpdate) {
					$apiresponse = static::updateAPIProduct($account, $apidata, $child_product->api_product_id);
				} else {
					$image_data = $this->getImagesForCreatedProduct($user_integration_id, $source_platform_id, $product->id);
					if (isset($image_data['images']) && count($image_data['images'])) {
						$apidata = array_merge($image_data, $apidata);
					}

					$apiresponse = static::createAPIProduct($account, $apidata);
				}

				// save the response to the DB
				if (isset($apiresponse['data']['id'])) {
					$apiresponse = $apiresponse['data'];

					$stock_track = ($apiresponse['inventory_tracking'] == 'none') ? 0 : 1;
					if ($this->setNonTrackedProductSKU(@$apiresponse['sku'])) {
						$stock_track = 0;
					}

					if ($child_product) {
						$child_product->linked_id = $product->id;
						$child_product->stock_track = $stock_track;
						$child_product->user_id = $user_id;
						$child_product->user_integration_id = $user_integration_id;
						$child_product->platform_id = $this->platformId;
						$child_product->product_sync_status = 'Synced';
						$child_product->save();
					} else {
						if (is_null($child_product)) {
							$child_product = new PlatformProduct();
						}

						$child_product->linked_id = $product->id;
						$child_product->api_product_id = $apiresponse['id'];
						$child_product->user_id = $user_id;
						$child_product->user_integration_id = $user_integration_id;
						$child_product->platform_id = $this->platformId;
						$child_product->product_name = $apiresponse['name'];
						$child_product->ean = @$apiresponse['ean'];
						$child_product->sku = @$apiresponse['sku'];
						$child_product->gtin = @$apiresponse['gtin'];
						$child_product->upc = @$apiresponse['upc'];
						$child_product->isbn = @$apiresponse['isbn'];
						$child_product->mpn = @$apiresponse['mpn'];
						$child_product->brand_id = $apiresponse['brand_id'];
						$child_product->weight = $apiresponse['weight'];
						$child_product->uom = @$apiresponse['uom'];
						$child_product->stock_track = $stock_track;
						$child_product->product_status = $apiresponse['condition'];
						$child_product->price = null;
						$child_product->description = $apiresponse['description'];
						$child_product->category_id = ((is_array($apiresponse['categories'])) ? implode(",", $apiresponse['categories']) : null);
						$child_product->has_variations = 0;
						$child_product->product_sync_status = 'Synced';
						$child_product->inventory_sync_status = ($apiresponse['inventory_tracking'] == 'none') ? 'Pending' : 'Synced';
						$child_product->api_updated_at = $apiresponse['date_modified'];
						$child_product->api_inventory_lastmodified_time = $apiresponse['date_modified'];
						$child_product->save();
					}

					$options = [];
					//OPTIONS FOR Products / Variants
					$productOptions = PlatformProductOption::where(['platform_product_id' => $product->id, 'status' => 1])->get();
					foreach ($productOptions as $productOption) {
						$options[$productOption->option_name][] = $productOption->option_value;
					}

					$checkVariantProducts = PlatformProduct::select('id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'api_group_id' => $product->api_group_id, 'is_deleted' => 0])->whereNotNull('api_group_id')->get();
					if (count($checkVariantProducts) && $product->api_group_id) {
						foreach ($checkVariantProducts as $checkVariantProduct) {
							$productOptions = PlatformProductOption::where(['platform_product_id' => $checkVariantProduct->id, 'status' => 1])->get();
							foreach ($productOptions as $productOption) {
								$options[$productOption->option_name][] = $productOption->option_value;
							}
						}
					}

					if (count($options)) {
						//inactive all available option for current product
						PlatformProductOption::where('platform_product_id', $child_product->id)->update(['status' => 0]);

						$availableOptionIds = [];
						foreach ($options as $product_option => $product_option_values) {
							$db_product_option = PlatformProductOption::where(['platform_product_id' => $child_product->id, 'option_name' => $product_option])->first();
							if (is_null($db_product_option)) {
								$option_values = [];
								$sort_order = 0;
								$product_option_values = array_unique($product_option_values);
								foreach ($product_option_values as $product_option_value) {
									$option_values[] = ["label" => $product_option_value, "sort_order" => $sort_order];
									$sort_order++;
								}

								$optionData = ["display_name" => $product_option, "type" => "dropdown", "option_values" => $option_values];
								$optionResponse = static::createAPIProductOption($account, $optionData, $apiresponse['id']);
								if (isset($optionResponse['data']['id'])) {
									foreach ($optionResponse['data']['option_values'] as $option_value) {
										$PlatformProductOptionData = ['option_name' => $optionResponse['data']['display_name'], 'api_option_id' => $optionResponse['data']['id'], 'platform_product_id' => $child_product->id, 'api_option_value_id' => $option_value['id'], 'option_value' => $option_value['label'], 'status' => 1];
										PlatformProductOption::create($PlatformProductOptionData);
									}
									$availableOptionIds[] = $optionResponse['data']['id'];
								}
							} else {
								$availableOptionValueIds = [];
								$option_values = [];
								$sort_order = 0;
								$product_option_values = array_unique($product_option_values);
								foreach ($product_option_values as $product_option_value) {
									$db_product_option_value = PlatformProductOption::where(['api_option_id' => $db_product_option->api_option_id, 'platform_product_id' => $child_product->id, 'option_value' => $product_option_value])->first();
									if (is_null($db_product_option_value)) {
										$option_values[] = ["label" => $product_option_value, "sort_order" => $sort_order];
									} else {
										$option_values[] = ["id" => $db_product_option_value->api_option_value_id, "label" => $product_option_value, "sort_order" => $sort_order];
									}

									$sort_order++;
								}

								$optionData = ["display_name" => $product_option, "type" => "dropdown", "option_values" => $option_values];
								$optionResponse = static::updateAPIProductOption($account, $optionData, $apiresponse['id'], $db_product_option->api_option_id);
								if (isset($optionResponse['data']['id'])) {
									foreach ($optionResponse['data']['option_values'] as $option_value) {
										$db_product_option_value = PlatformProductOption::select('id')->where(['api_option_id' => $db_product_option->api_option_id, 'platform_product_id' => $child_product->id, 'api_option_value_id' => $option_value['id']])->first();
										if (is_null($db_product_option_value)) {
											$PlatformProductOptionData = ['option_name' => $optionResponse['data']['display_name'], 'api_option_id' => $optionResponse['data']['id'], 'platform_product_id' => $child_product->id, 'api_option_value_id' => $option_value['id'], 'option_value' => $option_value['label'], 'status' => 1];
											PlatformProductOption::create($PlatformProductOptionData);
										} else {
											$PlatformProductOptionData = ['option_name' => $optionResponse['data']['display_name'], 'option_value' => $option_value['label'], 'status' => 1];
											PlatformProductOption::where('id', $db_product_option_value->id)->update($PlatformProductOptionData);
										}

										$availableOptionValueIds[] = $option_value['id'];
									}
								}

								//delete product variance option value
								$delete_product_option_values = PlatformProductOption::select('id', 'api_option_value_id')->where(['api_option_id' => $db_product_option->api_option_id, 'platform_product_id' => $child_product->id])->whereNotIn('api_option_value_id', $availableOptionValueIds)->get();
								foreach ($delete_product_option_values as $delete_product_option_value) {
									$optionResponse = static::deleteAPIProductOptionValue($account, $apiresponse['id'], $db_product_option->api_option_id, $delete_product_option_value->api_option_value_id);

									$delete_product_option_value->delete();
								}

								$availableOptionIds[] = $db_product_option->api_option_id;
							}
						}

						//delete product variance option
						$delete_product_options = PlatformProductOption::select('api_option_id')->where(['platform_product_id' => $child_product->id])->whereNotIn('api_option_id', $availableOptionIds)->groupBy('api_option_id')->get();
						foreach ($delete_product_options as $delete_product_option) {
							$optionResponse = static::deleteAPIProductOption($account, $apiresponse['id'], $delete_product_option->api_option_id);

							PlatformProductOption::where(['platform_product_id' => $child_product->id, 'api_option_id' => $delete_product_option->api_option_id])->delete();
						}
					}

					$isTaxable = (isset($apiresponse['tax_class_id']) && $apiresponse['tax_class_id']) ? $apiresponse['tax_class_id'] : null;
					$platformProductDetailAttr = PlatformProductDetailAttribute::where(['platform_product_id' => $child_product->id])->first();
					if ($platformProductDetailAttr) {
						$platformProductDetailAttr->update(['fulldescription' => $apiresponse['description'], 'height' => $apiresponse['height'], 'width' => $apiresponse['width'], 'lenght' => $apiresponse['depth'], 'volume' => 0, 'taxable' => ($isTaxable) ? 1 : 0, 'taxcode_ids' => $isTaxable, 'product_type_ids' => $apiresponse['type']]);
					} else {
						PlatformProductDetailAttribute::create(['platform_product_id' => $child_product->id, 'fulldescription' => $apiresponse['description'], 'height' => $apiresponse['height'], 'width' => $apiresponse['width'], 'lenght' => $apiresponse['depth'], 'volume' => 0, 'taxable' => ($isTaxable) ? 1 : 0, 'taxcode_ids' => $isTaxable, 'product_type_ids' => $apiresponse['type']]);
					}

					if ($product['inventory_tracking'] != 'none') {
						if ($product['inventory_tracking'] == 'product') {
							$child_product->inventory_tracking = 'PRODUCT';
						} else {
							$child_product->inventory_tracking = 'VARIANT';
						}
						$platformProductInventory = PlatformProductInventory::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_product_id' => $child_product->id, 'api_product_id' => $apiresponse['id']])->first();
						if ($platformProductInventory) {
							$platformProductInventory->update(['quantity' => $apiresponse['inventory_level'], 'sku' => $apiresponse['sku'], 'api_updated_at' => $apiresponse['date_modified']]);
						} else {
							PlatformProductInventory::create(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_product_id' => $child_product->id, 'api_product_id' => $apiresponse['id'], 'quantity' => $apiresponse['inventory_level'], 'sku' => $apiresponse['sku'], 'api_updated_at' => $apiresponse['date_modified']]);
							$child_product->inventory_sync_status = 'Ready';
							$child_product->api_inventory_lastmodified_time = $apiresponse['date_modified'];
						}
					} else {
						$child_product->inventory_tracking = 'PRODUCT';
					}

					PlatformProductPriceList::where(['platform_product_id' => $child_product->id])->update(['status' => 0]);
					if ($priceObjects) {
						foreach ($priceObjects as $priceObject) {
							if (isset($apiresponse[$priceObject->api_id])) {
								$pricelist_object = PlatformProductPriceList::where(['platform_product_id' => $child_product->id, 'platform_object_data_id' => $priceObject->id])->first();
								if ($pricelist_object) {
									$pricelist_object->update(['price' => $apiresponse[$priceObject->api_id], 'status' => 1]);
								} else {
									PlatformProductPriceList::create(['platform_product_id' => $child_product->id, 'platform_object_data_id' => $priceObject->id, 'price' => $apiresponse[$priceObject->api_id], 'status' => 1]);
								}
							}
						}
					}

					PlatformCustomFieldValue::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'record_id' => $child_product->id])->update(['status' => 0]);

					if (isset($apiresponse['custom_fields']) && is_array($apiresponse['custom_fields']) && count($apiresponse['custom_fields'])) {
						$default_product_primary_supplier_field_name = $this->fieldMapHelper->getMappedDataByName($user_integration_id, NULL, "default_product_primary_supplier_field_name", ['custom_data']);
						$default_product_supplier_field_name = $this->fieldMapHelper->getMappedDataByName($user_integration_id, NULL, "default_product_supplier_field_name", ['custom_data']);

						foreach ($apiresponse['custom_fields'] as $customfielddata) {
							$isAllowToStore = 0;
							if ($default_product_primary_supplier_field_name && $default_product_primary_supplier_field_name->custom_data && $default_product_primary_supplier_field_name->custom_data == $customfielddata['name']) {
								$isAllowToStore = 1;
							} elseif ($default_product_supplier_field_name && $default_product_supplier_field_name->custom_data && $default_product_supplier_field_name->custom_data == $customfielddata['name']) {
								$isAllowToStore = 1;
							}

							if ($isAllowToStore) {
								$customfield_object = PlatformField::select('id', 'name', 'description', 'status')->where(['custom_field_id' => (string)$customfielddata['id'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'field_type' => 'custom'])->first();
								if (!$customfield_object) {
									$customfield_object = PlatformField::create(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'custom_field_id' => $customfielddata['id'], 'name' => $customfielddata['name'], 'description' => $customfielddata['name'], 'field_type' => 'custom', 'custom_field_type' => 'TEXT', 'type' => 'product', 'platform_object_id' => $product_object_id, 'status' => 1]);
								} else {
									$customfield_object->name = $customfielddata['name'];
									$customfield_object->description = $customfielddata['name'];
									$customfield_object->status = 1;
									$customfield_object->save();
								}

								if ($customfield_object) {
									$customvalue_object = PlatformCustomFieldValue::where(['platform_field_id' => $customfield_object->id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'record_id' => $child_product->id])->first();
									if ($customvalue_object) {
										$customvalue_object->field_value = $customfielddata['value'];
										$customvalue_object->status = 1;
										$customvalue_object->save();
									} else {
										PlatformCustomFieldValue::create(['platform_field_id' => $customfield_object->id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'record_id' => $child_product->id, 'field_value' => $customfielddata['value'], 'status' => 1]);
									}
								}
							}
						}
					}

					if (isset($apiresponse['options']) && count($apiresponse['options'])) {
						PlatformProductOption::where('platform_product_id', $child_product->id)
							->update(['status' => 0]);
						foreach ($apiresponse['options'] as $option) {
							if (isset($option['option_values']) && count($option['option_values'])) {
								foreach ($option['option_values'] as $option_value) {
									$optdbdata = ['api_option_value_id' => $option_value['id'], 'api_option_id' => $option['id'], 'platform_product_id' => $child_product->id];

									$checkoptionVal = PlatformProductOption::where($optdbdata)->first();
									$valdbdata = ['option_name' => $option['display_name'], 'option_value' => $option_value['label'], 'status' => 1];
									if ($checkoptionVal) {
										$checkoptionVal->update($valdbdata);
									} else {
										PlatformProductOption::create($optdbdata + $valdbdata);
									}
								}
							}
						}
					}

					if (isset($apiresponse['variants']) && count($apiresponse['variants']) && is_null($apiresponse['base_variant_id'])) {
						foreach ($apiresponse['variants'] as $variant) {
							$this->addVariantsForProduct($child_product, $apiresponse, $variant, $priceObjects, $product_object_id);
						}
						$child_product->has_variations = 1;
					} else {
						$child_product->has_variations = 0;
					}

					$product->linked_id = $child_product->id;
					$product->product_sync_status = 'Synced';
					$product->inventory_sync_status = 'Synced';
					$status = 'success';
					$message = 'Product synced successfully';
				} else {
					if (!is_array($apiresponse) && $apiresponse) {
						$message = $apiresponse;
					} else {
						$message = 'Error in syncing product.';
					}
					$response = $message;
					$status = 'failed';
					$product->product_sync_status = 'Failed';
				}
				$product->save();

				if ($child_product) {
					$child_product->save();
				}

				$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $product_object_id, $status, $product->id, $message);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> syncProductsToBigCommerce -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	// ** Sync variant To BigCommerce **
	private function syncVariantsToBigCommerce($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $product)
	{
		$response = true;
		try {
			$account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$source_platform_id = $this->connectionHelper->getPlatformIdByName($source_platform_name);
			$product_object_id = $this->connectionHelper->getObjectId('product');
			$product_identity_obj_id = $this->connectionHelper->getObjectId('product_identity');
			$invWarehouseIds = $this->fieldMapHelper->getMappedDataByName($user_integration_id, null, "inventory_warehouse", ['api_id'], 'regular', '', 'multiple');

			if ($account && $source_platform_id && $product_object_id && $product_identity_obj_id && $product) {
				$source_row_data = $destination_row_data = 'sku';
				$mapping_data = $this->fieldMapHelper->getMappedField($user_integration_id, null, $product_identity_obj_id);
				if ($mapping_data) {
					if ($mapping_data['destination_platform_id'] == self::PLATFORMNAME) {
						$destination_row_data = $mapping_data['destination_row_data'];
						$source_row_data = $mapping_data['source_row_data'];
					} else {
						$destination_row_data = $mapping_data['source_row_data'];
						$source_row_data = $mapping_data['destination_row_data'];
					}
				}

				$isUpdate = false;
				$child_product = null;
				if ($product->linked_id) {
					$isUpdate = true;
					$child_product = PlatformProduct::find($product->linked_id);
				} else {
					//-- CHECK FOR THE PRODUCT FROM THE UNIQUE FIELD --
					$child_product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'inventory_tracking' => 'VARIANT', $destination_row_data => $product->{$source_row_data}, 'is_deleted' => 0])->first();
					if ($child_product) {
						$isUpdate = true;
					}
				}

				// PRICE MAPPING
				$priceData = $this->getMappedPriceListArray($product->id, $user_integration_id);
				if (!isset($priceData['price'])) {
					if ($product->price) {
						$priceData['price'] = $product->price;
					} else {
						$priceData['price'] = 0;
					}
				}

				$inventoryData = ['inventory_level' => 0, 'availability' => 'available'];
				if ($product->inventory_sync_status != 'Pending') {
					if (is_array($invWarehouseIds) && count($invWarehouseIds)) {
						$productInventories = PlatformProductInventory::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'api_product_id' => $product->api_product_id])->whereIn('api_warehouse_id', $invWarehouseIds)->get();
						if ($productInventories) {
							foreach ($productInventories as $productInventory) {
								$inventoryData['inventory_level'] = $productInventory->quantity + $inventoryData['inventory_level'];
							}
						}
					}
				}

				$apidata = [
					'sku' => $product->sku,
					'weight' => $product->weight,
					// 'fixed_cost_shipping_price'=>,
					// 'is_free_shipping'=>,
					// 'bin_picking_number'=>,
					'upc' => ($product->upc) ? $product->upc : '',
					'gtin' => $product->gtin,
					'mpn' => $product->mpn
				];

				$apidata[$destination_row_data] = $product->{$source_row_data};

				$apidata = array_merge($priceData, $apidata); // MERGE PRICE TO DATA
				$apidata = array_merge($inventoryData, $apidata); // MERGE INVENTORY TO DATA

				// EXTRA INFO
				$detailsAttributes = PlatformProductDetailAttribute::where('platform_product_id', $product->id)->first();
				if ($detailsAttributes) {
					$apidata['width'] = $detailsAttributes->width;
					$apidata['height'] = $detailsAttributes->height;
					$apidata['depth'] = $detailsAttributes->lenght;
				}

				$image_data = $this->getImagesForCreatedProduct($user_integration_id, $source_platform_id, $product->id);
				if (isset($image_data['images']) && count($image_data['images'])) {
					$apidata['image_url'] = $image_data['images'][0]['image_url'];
				}

				$message = NULL;
				$parent_product_id = NULL;
				$apiresponse = 'Error in product syncing.';
				// UPDATE AND INSERT PRODUCT
				if ($isUpdate && isset($child_product->api_variant_id) && $child_product->api_variant_id) {
					if ($child_product->parent_product_id) {
						$parent_product_id = $child_product->parent_product_id;
					} else {
						$parent_product_id = $child_product->id;
					}

					//OPTIONS FOR Products / Variants
					$apidata['option_values'] = [];
					$productOptions = PlatformProductOption::where(['platform_product_id' => $product->id, 'status' => 1])->get();
					foreach ($productOptions as $productOption) {
						$platform_product_option = PlatformProductOption::leftJoin('platform_product', 'platform_product_options.platform_product_id', '=', 'platform_product.id')
							->select('platform_product_options.api_option_id')
							->where('platform_product_options.option_name', $productOption->option_name)
							->where('platform_product_options.platform_product_id', $child_product->id)
							->where(['platform_product.user_id' => $user_id, 'platform_product.user_integration_id' => $user_integration_id, 'platform_product.platform_id' => $this->platformId])
							->where('platform_product_options.status', 1)
							->first();

						$platform_product_option_value = PlatformProductOption::leftJoin('platform_product', 'platform_product_options.platform_product_id', '=', 'platform_product.id')
							->select('platform_product_options.api_option_value_id')
							->where('platform_product_options.option_value', $productOption->option_value)
							->where('platform_product_options.platform_product_id', $child_product->id)
							->where(['platform_product.user_id' => $user_id, 'platform_product.user_integration_id' => $user_integration_id, 'platform_product.platform_id' => $this->platformId])
							->where('platform_product_options.status', 1)
							->first();
						if ($platform_product_option && $platform_product_option_value) {
							$apidata['option_values'][] = ['option_id' => $platform_product_option->api_option_id, 'option_display_name' => $productOption->option_name, 'id' => $platform_product_option_value->api_option_value_id, 'label' => $productOption->option_value];
						}
					}

					$apiresponse = static::updateAPIProductVariantById($account, $apidata, $child_product->api_product_id, $child_product->api_variant_id);
				} else {
					$parentProductId = NULL;
					if ($child_product) {
						$parentProductId = $child_product->api_product_id;
						$parent_product_id = $child_product->id;
					} else {
						$sourceParentProduct = PlatformProduct::select('linked_id', 'api_product_id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'api_group_id' => $product->api_group_id, 'is_deleted' => 0])->whereNotNull('api_group_id')->orderBy('has_variations', 'asc')->orderByRaw('CONVERT(api_product_id, SIGNED) asc')->first();
						if ($sourceParentProduct && $sourceParentProduct->linked_id && $product->api_group_id) {
							$parentProduct = PlatformProduct::select('id', 'api_product_id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'id' => $sourceParentProduct->linked_id, 'is_deleted' => 0])->first();
							if ($parentProduct && $parentProduct->api_product_id) {
								$parentProductId = $parentProduct->api_product_id;
								$parent_product_id = $parentProduct->id;
							}
						}
					}

					//OPTIONS FOR Products / Variants
					$apidata['option_values'] = [];
					$productOptions = PlatformProductOption::where(['platform_product_id' => $product->id, 'status' => 1])->get();
					foreach ($productOptions as $productOption) {
						$platform_product_option = PlatformProductOption::leftJoin('platform_product', 'platform_product_options.platform_product_id', '=', 'platform_product.id')
							->select('platform_product_options.api_option_id')
							->where('platform_product_options.option_name', $productOption->option_name)
							->where('platform_product_options.platform_product_id', $parent_product_id)
							->where(['platform_product.user_id' => $user_id, 'platform_product.user_integration_id' => $user_integration_id, 'platform_product.platform_id' => $this->platformId])
							->where('platform_product_options.status', 1)
							->first();

						$platform_product_option_value = PlatformProductOption::leftJoin('platform_product', 'platform_product_options.platform_product_id', '=', 'platform_product.id')
							->select('platform_product_options.api_option_value_id')
							->where('platform_product_options.option_value', $productOption->option_value)
							->where('platform_product_options.platform_product_id', $parent_product_id)
							->where(['platform_product.user_id' => $user_id, 'platform_product.user_integration_id' => $user_integration_id, 'platform_product.platform_id' => $this->platformId])
							->where('platform_product_options.status', 1)
							->first();
						if ($platform_product_option && $platform_product_option_value) {
							$apidata['option_values'][] = ['option_id' => $platform_product_option->api_option_id, 'option_display_name' => $productOption->option_name, 'id' => $platform_product_option_value->api_option_value_id, 'label' => $productOption->option_value];
						}
					}

					if ($parentProductId) {
						$apiresponse = static::createAPIProductVariant($account, $apidata, $parentProductId);
					} else {
						$message = "This variant parent product is not available.";
						$response = $message;
						$status = 'failed';
						$product->product_sync_status = 'Failed';
					}
				}

				if ($message == NULL) {
					// save the response to the DB
					if (isset($apiresponse['data']['id'])) {
						$apiresponse = $apiresponse['data'];

						$stock_track = 1;
						if ($this->setNonTrackedProductSKU(@$apiresponse['sku'])) {
							$stock_track = 0;
						}

						if ($child_product) {
							$child_product->linked_id = $product->id;
							$child_product->api_variant_id = $apiresponse['id'];
							$child_product->stock_track = $stock_track;
							$child_product->user_id = $user_id;
							$child_product->user_integration_id = $user_integration_id;
							$child_product->platform_id = $this->platformId;
							$child_product->parent_product_id = $parent_product_id;
							$child_product->product_sync_status = 'Synced';
							$child_product->inventory_sync_status = 'Synced';
							$child_product->save();
						} else {
							if (is_null($child_product)) {
								$child_product = new PlatformProduct();
							}

							$child_product->linked_id = $product->id;
							$child_product->api_product_id = $apiresponse['product_id'];
							$child_product->api_variant_id = $apiresponse['id'];
							$child_product->inventory_tracking = 'VARIANT';
							$child_product->user_id = $user_id;
							$child_product->user_integration_id = $user_integration_id;
							$child_product->platform_id = $this->platformId;
							$child_product->product_name = $product->product_name;
							$child_product->sku = @$apiresponse['sku'];
							$child_product->gtin = @$apiresponse['gtin'];
							$child_product->upc = @$apiresponse['upc'];
							$child_product->mpn = @$apiresponse['mpn'];
							$child_product->weight = @$apiresponse['weight'];
							$child_product->stock_track = $stock_track;
							$child_product->price = @$apiresponse['price'];
							$child_product->has_variations = 0;
							$child_product->parent_product_id = $parent_product_id;
							$child_product->product_sync_status = 'Synced';
							$child_product->inventory_sync_status = 'Synced';
							$child_product->api_updated_at = date('Y-m-d H:i:s');
							$child_product->api_inventory_lastmodified_time = date('Y-m-d H:i:s');
							$child_product->save();
						}

						$product->linked_id = $child_product->id;
						$product->product_sync_status = 'Synced';
						$product->inventory_sync_status = 'Synced';
						$status = 'success';
						$message = 'Product synced successfully';
					} elseif (isset($apiresponse['title'])) {
						$response = $apiresponse['title'];
						$status = 'failed';
						$product->product_sync_status = 'Failed';
					} else {
						if (!is_array($apiresponse) && $apiresponse) {
							$message = $apiresponse;
						} else {
							$message = 'Error in syncing product.';
						}

						$response = $message;
						$status = 'failed';
						$product->product_sync_status = 'Failed';
					}
				}

				$product->save();
				if ($child_product) {
					$child_product->save();
				}
				$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $product_object_id, $status, $product->id, $message);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> syncVariantsToBigCommerce -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	private function getUpdatedCustomFieldsForProduct($user_integration_id, $source_platform_id, $parent_pr_id, $child_pr_id)
	{
		$customFields = ['custom_fields' => []];
		$selectedCustomFields = $this->fieldMapHelper->getMappedDataByName($user_integration_id, null, "get_product_custom_field", ['name'], 'regular', '', 'multiple', 'source');
		if ($selectedCustomFields) {
			$customFieldObjects = PlatformCustomFieldValue::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'record_id' => $parent_pr_id, 'status' => 1])->select('platform_field_id', 'field_value')->get();
			if ($customFieldObjects) {
				foreach ($customFieldObjects as $customFieldObject) {
					$pr_field = PlatformField::find($customFieldObject->platform_field_id);
					if ($pr_field && in_array($pr_field->name, $selectedCustomFields)) {
						$child_field = PlatformCustomFieldValue::leftJoin('platform_fields', 'platform_custom_field_values.platform_field_id', '=', 'platform_fields.id')
							->select('platform_fields.custom_field_id')
							->where(['platform_custom_field_values.user_integration_id' => $user_integration_id, 'platform_custom_field_values.platform_id' => $this->platformId, 'platform_custom_field_values.record_id' => $child_pr_id])
							->where(['platform_fields.user_integration_id' => $user_integration_id, 'platform_fields.description' => $pr_field->description, 'platform_fields.field_type' => 'custom', 'platform_fields.platform_id' => $this->platformId, 'platform_fields.type' => 'product', 'platform_fields.status' => 1, 'platform_custom_field_values.status' => 1])
							->first();

						//$child_field = PlatformField::where(['user_integration_id'=>$user_integration_id, 'description'=>$pr_field->description, 'field_type'=>'custom', 'platform_id'=>$this->platformId, 'type'=>'product', 'status'=>1])->select('custom_field_id')->first();
						if ($child_field) {
							$customFields['custom_fields'][] = ['id' => $child_field->custom_field_id, 'name' => $pr_field->description, 'value' => $customFieldObject->field_value];
						} else {
							$customFields['custom_fields'][] = ['name' => $pr_field->description, 'value' => $customFieldObject->field_value];
						}
					}
				}
			}
		}
		return $customFields;
	}

	private function getCreatedCustomFieldsForProduct($user_integration_id, $source_platform_id, $parent_pr_id)
	{
		$customFields = ['custom_fields' => []];
		$selectedCustomFields = $this->fieldMapHelper->getMappedDataByName($user_integration_id, null, "get_product_custom_field", ['name'], 'regular', '', 'multiple', 'source');
		if ($selectedCustomFields) {
			$customFieldObjects = PlatformCustomFieldValue::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'record_id' => $parent_pr_id, 'status' => 1])->select('platform_field_id', 'field_value')->get();
			if ($customFieldObjects) {
				foreach ($customFieldObjects as $customFieldObject) {
					$pr_field = PlatformField::find($customFieldObject->platform_field_id);
					if ($pr_field && in_array($pr_field->name, $selectedCustomFields)) {
						$customFields['custom_fields'][] = ['name' => $pr_field->description, 'value' => $customFieldObject->field_value];
					}
				}
			}
		}

		return $customFields;
	}

	private function getImagesForCreatedProduct($user_integration_id, $source_platform_id, $parent_pr_id)
	{
		$images = ['images' => []];
		$is_thumbnail = true;
		$selectedImageCustomFields = $this->fieldMapHelper->getMappedDataByName($user_integration_id, null, "product_image_custom_field", ['name'], 'regular', '', 'multiple', 'source');
		if ($selectedImageCustomFields) {
			$customFieldObjects = PlatformCustomFieldValue::where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'record_id' => $parent_pr_id, 'status' => 1])->select('platform_field_id', 'field_value')->get();
			if ($customFieldObjects) {
				foreach ($customFieldObjects as $customFieldObject) {
					$pr_field = PlatformField::find($customFieldObject->platform_field_id);
					if ($pr_field && $customFieldObject->field_value && in_array($pr_field->name, $selectedImageCustomFields)) {
						$images['images'][] = ['image_url' => $customFieldObject->field_value, 'is_thumbnail' => $is_thumbnail];
						$is_thumbnail = false;
					}
				}
			}
		}

		return $images;
	}

	private function getDestinationPlatformName($user_integration_id)
	{
		$destination_platform = NULL;
		$user_integration = $this->fieldMapHelper->getUserIntegrationDetailsById($user_integration_id, self::PLATFORMNAME);
		if ($user_integration) {
			$platform_account = $this->mainModel->getFirstResultByConditions('platform_accounts', ['id' => $user_integration->selected_dc_account_id], ['platform_id']);
			if ($platform_account) {
				$platform_lookup = $this->mainModel->getFirstResultByConditions('platform_lookup', ['id' => $platform_account->platform_id], ['platform_id']);
				if ($platform_lookup) {
					$destination_platform = $platform_lookup->platform_id;
				}
			}
		}
		return $destination_platform;
	}

	private function getMappedPriceListArray($product_id, $user_integration_id)
	{
		$response = [];
		$SourceOrDestination = "source";
		$destination_platform_name = $this->getDestinationPlatformName($user_integration_id);
		if ($destination_platform_name == self::PLATFORMNAME) {
			$SourceOrDestination = "destination";
		}

		$pricelist_object_id = $this->connectionHelper->getObjectId('pricelist');
		$priceLists = PlatformObjectData::select('api_id')->where(['user_id' => 0, 'user_integration_id' => 0, 'platform_id' => $this->platformId, 'platform_object_id' => $pricelist_object_id, 'status' => 1])->get();
		foreach ($priceLists as $priceList) {
			$platform_object_data = $this->fieldMapHelper->getMappedDataByName($user_integration_id, null, "product_pricelist", ['api_id', 'id'], "regular", $priceList->api_id, "single", $SourceOrDestination);
			if ($platform_object_data) {
				$productPriceList = PlatformProductPriceList::select('price')->where(['platform_product_id' => $product_id, 'platform_object_data_id' => $platform_object_data->id, 'status' => 1])->first();
				if ($productPriceList) {
					$response[$priceList->api_id] = $productPriceList->price;
				}
			}
		}

		return $response;
	}

	private function getOrCreateCategory($account, $category_ids, $categoryCheck, $source_platform_id)
	{
		$response = [];
		$category_ids = explode(",", $category_ids);
		if (count($category_ids)) {
			$destinationCheck = $sourceCheck = $categoryCheck;
			foreach ($category_ids as $category_id) {
				$category_id_int = (int) $category_id;
				if (is_int($category_id_int) && $category_id_int) {
					$sourceCheck['platform_id'] = $source_platform_id;
					$sourceCheck['api_id'] = $category_id_int;
					$catObject = PlatformObjectData::where($sourceCheck)->first();
					if ($catObject) {
						$destinationCheck['platform_id'] = $this->platformId;
						$destinationCheck['name'] = $catObject->name;
						$catObject = PlatformObjectData::where($destinationCheck)->first();
						if ($catObject) {
							$response[] = $catObject->api_id;
						} else {
							$api_id = $this->createCategoryForBigcommerceProduct($account, $destinationCheck['name'], $destinationCheck);
							if ($api_id) {
								$response[] = $api_id;
							}
						}
					}
				} else {
					$categoryCheck['platform_id'] = $this->platformId;
					$categoryCheck['name'] = $category_id;
					$catObject = PlatformObjectData::where($categoryCheck)->first();
					if ($catObject) {
						$response[] = $catObject->api_id;
					} else {
						$api_id = $this->createCategoryForBigcommerceProduct($account, $category_id, $categoryCheck);
						if ($api_id) {
							$response[] = $api_id;
						}
					}
				}
			}
		}
		return $response;
	}

	private function createCategoryForBigcommerceProduct($account, $catName, $categoryDBData)
	{
		$response = null;
		if ($catName && is_numeric($catName) == false) {
			$catData = ['name' => $catName, 'parent_id' => 0];
			$apiresponse = static::createAPICategory($account, $catData);
			if (isset($apiresponse['data']['id'])) {
				$apiresponse = $apiresponse['data'];
				$categoryDBData['api_id'] = $apiresponse['id'];
				$categoryDBData['name'] = $apiresponse['name'];
				$categoryDBData['parent_id'] = $apiresponse['parent_id'];
				$categoryDBData['description'] = $apiresponse['description'];
				PlatformObjectData::create($categoryDBData);
				$response = $apiresponse['id'];
			}
		}
		return $response;
	}

	private function getOrCreateBrand($account, $brand_id, $brandCheck, $source_platform_id)
	{
		$response = null;
		$brand_id_int = (int) $brand_id;
		if (is_int($brand_id_int) && $brand_id_int) {
			$sourceCheck = $brandCheck;
			$sourceCheck['platform_id'] = $source_platform_id;
			$sourceCheck['api_id'] = $brand_id_int;
			$brandObject = PlatformObjectData::where($sourceCheck)->first();
			if ($brandObject) {
				$brandCheck['platform_id'] = $this->platformId;
				$brandCheck['name'] = $brandObject->name;
				$brandObject = PlatformObjectData::where($brandCheck)->first();
				if ($brandObject) {
					$response = $brandObject->api_id;
				} else {
					$api_id = $this->createBrandForBigcommerceProduct($account, $brandCheck['name'], $brandCheck);
					if ($api_id) {
						$response = $api_id;
					}
				}
			}
		} else {
			$sourceCheck = $brandCheck;
			$sourceCheck['platform_id'] = $this->platformId;
			$sourceCheck['name'] = $brand_id;
			$brandObject = PlatformObjectData::where($sourceCheck)->first();
			if ($brandObject) {
				$response[] = $brandObject->api_id;
			} else {
				$api_id = $this->createBrandForBigcommerceProduct($account, $brand_id, $sourceCheck);
				if ($api_id) {
					$response = $api_id;
				}
			}
		}
		return $response;
	}

	private function createBrandForBigcommerceProduct($account, $brandName, $brandDBData)
	{
		$response = null;
		if ($brandName && is_numeric($brandName) == false) {
			$brandData = ['name' => $brandName];
			$apiresponse = static::createAPIBrand($account, $brandData);
			if (isset($apiresponse['data']['id'])) {
				$apiresponse = $apiresponse['data'];
				$brandDBData['api_id'] = $apiresponse['id'];
				$brandDBData['name'] = $apiresponse['name'];
				PlatformObjectData::create($brandDBData);
				$response = $apiresponse['id'];
			}
		}
		return $response;
	}

	private function addProductsToDatabase($user_id, $user_integration_id, $user_workflow_rule_id, $products)
	{
		$response = true;
		try {
			if (isset($products['data'])) {
				$default_product_primary_supplier_field_name = $this->fieldMapHelper->getMappedDataByName($user_integration_id, NULL, "default_product_primary_supplier_field_name", ['custom_data']);
				$default_product_supplier_field_name = $this->fieldMapHelper->getMappedDataByName($user_integration_id, NULL, "default_product_supplier_field_name", ['custom_data']);
				$pricelist_object_id = $this->connectionHelper->getObjectId('pricelist');
				$product_obj_id = $this->connectionHelper->getObjectId('product');
				$priceObjects = null;
				if ($pricelist_object_id) {
					$priceObjects = PlatformObjectData::where(['platform_id' => $this->platformId, 'platform_object_id' => $pricelist_object_id, 'user_integration_id' => 0, 'status' => 1])->get();
				}
				$products = $products['data'];
				$products = array_chunk($products, 5, true);
				foreach ($products as $apiproducts) {
					if (is_array($apiproducts)) {
						foreach ($apiproducts as $product) {
							$checkProduct = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_product_id' => $product['id']];

							$inv_track = $product['inventory_tracking'];

							$stock_track = ($inv_track == 'none') ? 0 : 1;
							if ($this->setNonTrackedProductSKU(@$product['sku'])) {
								$stock_track = 0;
							}

							$productdata = [
								'product_name' => $product['name'],
								'ean' => @$product['ean'],
								'sku' => @$product['sku'],
								'gtin' => @$product['gtin'],
								'upc' => @$product['upc'],
								'isbn' => @$product['isbn'],
								'mpn' => @$product['mpn'],
								'brand_id' => $product['brand_id'],
								'weight' => $product['weight'],
								'stock_track' => $stock_track,
								'product_status' => $product['condition'], // $product['availability']
								'price' => null,
								'description' => $product['description'],
								'category_id' => (isset($product['categories']) && is_array($product['categories'])) ? implode(',', $product['categories']) : $product['categories'],
								'api_updated_at' => $product['date_modified']
							];

							$dbproduct = PlatformProduct::where($checkProduct)->first();
							if ($dbproduct) {
								if ($dbproduct->api_updated_at == $product['date_modified']) {
									//continue;
								}
								$dbproduct->update($productdata);
							} else {
								$productdata = array_merge($checkProduct, $productdata);
								$dbproduct = PlatformProduct::create($productdata);
							}

							if ($dbproduct) {
								// save product detail attributes
								$isTaxable = (isset($product['tax_class_id']) && $product['tax_class_id']) ? $product['tax_class_id'] : null;
								$platformProductDetailAttr = PlatformProductDetailAttribute::where(['platform_product_id' => $dbproduct->id])->first();
								if ($platformProductDetailAttr) {
									$platformProductDetailAttr->update(['fulldescription' => $product['description'], 'height' => $product['height'], 'width' => $product['width'], 'lenght' => $product['depth'], 'volume' => 0, 'taxable' => ($isTaxable) ? 1 : 0, 'taxcode_ids' => $isTaxable, 'product_type_ids' => $product['type']]);
								} else {
									PlatformProductDetailAttribute::create(['platform_product_id' => $dbproduct->id, 'fulldescription' => $product['description'], 'height' => $product['height'], 'width' => $product['width'], 'lenght' => $product['depth'], 'volume' => 0, 'taxable' => ($isTaxable) ? 1 : 0, 'taxcode_ids' => $isTaxable, 'product_type_ids' => $product['type']]);
								}

								if ($inv_track != 'none') {
									if ($inv_track == 'product') {
										$dbproduct->inventory_tracking = 'PRODUCT';
									} else {
										$dbproduct->inventory_tracking = 'VARIANT';
									}
									$platformProductInventory = PlatformProductInventory::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_product_id' => $dbproduct->id, 'api_product_id' => $product['id']])->first();
									if ($platformProductInventory) {
										// FOR UPDATE OF INVENTORY
										// $platformProductInventory->update(['quantity'=>$product['inventory_level'], 'sku'=>$product['sku'], 'api_updated_at'=>$product['date_modified']]);
									} else {
										PlatformProductInventory::create(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_product_id' => $dbproduct->id, 'api_product_id' => $product['id'], 'quantity' => $product['inventory_level'], 'sku' => $product['sku'], 'api_updated_at' => $product['date_modified']]);
										$dbproduct->inventory_sync_status = 'Ready';
										$dbproduct->api_inventory_lastmodified_time = $product['date_modified'];
									}
								} else {
									$dbproduct->inventory_tracking = 'PRODUCT';
								}

								PlatformProductPriceList::where(['platform_product_id' => $dbproduct->id])->update(['status' => 0]);
								if ($priceObjects) {
									foreach ($priceObjects as $priceObject) {
										if (isset($product[$priceObject->api_id])) {
											$pricelist_object = PlatformProductPriceList::where(['platform_product_id' => $dbproduct->id, 'platform_object_data_id' => $priceObject->id])->first();
											if ($pricelist_object) {
												$pricelist_object->update(['price' => $product[$priceObject->api_id], 'status' => 1]);
											} else {
												PlatformProductPriceList::create(['platform_product_id' => $dbproduct->id, 'platform_object_data_id' => $priceObject->id, 'price' => $product[$priceObject->api_id], 'status' => 1]);
											}
										}
									}
								}

								PlatformCustomFieldValue::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'record_id' => $dbproduct->id])->update(['status' => 0]);
								if (isset($product['custom_fields']) && is_array($product['custom_fields']) && count($product['custom_fields'])) {
									foreach ($product['custom_fields'] as $customfielddata) {
										$isAllowToStore = 0;
										if ($default_product_primary_supplier_field_name && $default_product_primary_supplier_field_name->custom_data && $default_product_primary_supplier_field_name->custom_data == $customfielddata['name']) {
											$isAllowToStore = 1;
										} elseif ($default_product_supplier_field_name && $default_product_supplier_field_name->custom_data && $default_product_supplier_field_name->custom_data == $customfielddata['name']) {
											$isAllowToStore = 1;
										}

										if ($isAllowToStore) {
											$customfield_object = PlatformField::select('id', 'name', 'description', 'status')->where(['custom_field_id' => (string)$customfielddata['id'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'field_type' => 'custom'])->first();
											if (!$customfield_object) {
												$customfield_object = PlatformField::create(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'custom_field_id' => $customfielddata['id'], 'name' => $customfielddata['name'], 'description' => $customfielddata['name'], 'field_type' => 'custom', 'custom_field_type' => 'TEXT', 'type' => 'product', 'platform_object_id' => $product_obj_id, 'status' => 1]);
											} else {
												$customfield_object->name = $customfielddata['name'];
												$customfield_object->description = $customfielddata['name'];
												$customfield_object->save();
											}

											if ($customfield_object) {
												$customvalue_object = PlatformCustomFieldValue::where(['platform_field_id' => $customfield_object->id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'record_id' => $dbproduct->id])->first();
												if ($customvalue_object) {
													$customvalue_object->field_value = $customfielddata['value'];
													$customvalue_object->status = 1;
													$customvalue_object->save();
												} else {
													PlatformCustomFieldValue::create(['platform_field_id' => $customfield_object->id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'record_id' => $dbproduct->id, 'field_value' => $customfielddata['value'], 'status' => 1]);
												}
											}
										}
									}
								}

								if (isset($product['options']) && count($product['options'])) {
									PlatformProductOption::where('platform_product_id', $dbproduct->id)
										->update(['status' => 0]);
									foreach ($product['options'] as $option) {
										if (isset($option['option_values']) && count($option['option_values'])) {
											foreach ($option['option_values'] as $option_value) {
												$optdbdata = ['api_option_id' => $option['id'], 'platform_product_id' => $dbproduct->id, 'api_option_value_id' => $option_value['id']];

												$checkoptionVal = PlatformProductOption::where($optdbdata)->first();
												$valdbdata = ['option_name' => $option['display_name'], 'option_value' => $option_value['label'], 'status' => 1];
												if ($checkoptionVal) {
													$checkoptionVal->update($valdbdata);
												} else {
													PlatformProductOption::create($optdbdata + $valdbdata);
												}
											}
										}
									}
								}

								if (isset($product['variants']) && count($product['variants']) && is_null($product['base_variant_id'])) {
									foreach ($product['variants'] as $variant) {
										$this->addVariantsForProduct($dbproduct, $product, $variant, $priceObjects, $product_obj_id);
									}
									$dbproduct->has_variations = 1;
								} else {
									$dbproduct->has_variations = 0;
								}
							}
							$dbproduct->product_sync_status = 'Ready';
							$dbproduct->save();
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> addProductsToDatabase -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	private function addVariantsForProduct(PlatformProduct $dbproduct, $product, $variantdata, $priceObjects, $product_obj_id)
	{
		if ($dbproduct) {
			$checkVariantProduct = ['user_id' => $dbproduct->user_id, 'user_integration_id' => $dbproduct->user_integration_id, 'platform_id' => $this->platformId, 'api_product_id' => $variantdata['product_id'], 'api_variant_id' => $variantdata['id']];

			$stock_track = 1;
			if ($this->setNonTrackedProductSKU(@$variantdata['sku'])) {
				$stock_track = 0;
			}

			$variantproductdata = [
				'product_name' => $dbproduct->product_name,
				'inventory_tracking' => 'VARIANT',
				'ean' => @$variantdata['ean'],
				'sku' => @$variantdata['sku'],
				'gtin' => @$variantdata['gtin'],
				'upc' => @$variantdata['upc'],
				'isbn' => @$variantdata['isbn'],
				'mpn' => @$variantdata['mpn'],
				'brand_id' => $dbproduct->brand_id,
				'weight' => $variantdata['calculated_weight'],
				'stock_track' => $stock_track,
				'product_status' => $dbproduct->product_status, // $product['availability']
				'price' => null,
				'description' => $dbproduct->description,
				'category_id' => $dbproduct->category_id,
				'parent_product_id' => $dbproduct->id,
				'api_updated_at' => $dbproduct->api_updated_at
			];
			$dbvarproduct = PlatformProduct::where($checkVariantProduct)->first();
			if ($dbvarproduct) {
				$dbvarproduct->update($variantproductdata);
			} else {
				$productdata = array_merge($checkVariantProduct, $variantproductdata);
				$dbvarproduct = PlatformProduct::create($productdata);
			}

			if ($dbvarproduct) {
				// save product detail attributes
				$isTaxable = (isset($product['tax_class_id']) && $product['tax_class_id']) ? $product['tax_class_id'] : null;
				$platformProductDetailAttr = PlatformProductDetailAttribute::where(['platform_product_id' => $dbvarproduct->id])->first();
				if ($platformProductDetailAttr) {
					$platformProductDetailAttr->update(['fulldescription' => $product['description'], 'height' => $product['height'], 'width' => $product['width'], 'lenght' => $product['depth'], 'volume' => 0, 'taxable' => ($isTaxable) ? 1 : 0, 'taxcode_ids' => $isTaxable, 'product_type_ids' => $product['type']]);
				} else {
					PlatformProductDetailAttribute::create(['platform_product_id' => $dbvarproduct->id, 'fulldescription' => $product['description'], 'height' => $product['height'], 'width' => $product['width'], 'lenght' => $product['depth'], 'volume' => 0, 'taxable' => ($isTaxable) ? 1 : 0, 'taxcode_ids' => $isTaxable, 'product_type_ids' => $product['type']]);
				}
				$platformProductInventory = PlatformProductInventory::where(['user_integration_id' => $dbproduct->user_integration_id, 'platform_id' => $this->platformId, 'platform_product_id' => $dbvarproduct->id, 'api_product_id' => $product['id']])->first();
				if ($platformProductInventory) {
					// FOR UPDATE IN INVENTORY
					// $platformProductInventory->update(['quantity'=>$variantdata['inventory_level'], 'sku'=>$variantdata['sku'], 'api_updated_at'=>$product['date_modified']]);
				} else {
					PlatformProductInventory::create(['user_id' => $dbproduct->user_id, 'user_integration_id' => $dbproduct->user_integration_id, 'platform_id' => $this->platformId, 'platform_product_id' => $dbvarproduct->id, 'api_product_id' => $product['id'], 'quantity' => $variantdata['inventory_level'], 'sku' => $variantdata['sku'], 'api_updated_at' => $product['date_modified']]);
					if ($product['inventory_tracking'] == 'product') {
						$dbvarproduct->inventory_tracking = 'PRODUCT';
					} else {
						$dbvarproduct->inventory_tracking = 'VARIANT';
					}
					$dbvarproduct->inventory_sync_status = 'Ready';
					$dbvarproduct->api_inventory_lastmodified_time = $product['date_modified'];
				}

				PlatformProductPriceList::where(['platform_product_id' => $dbvarproduct->id])->update(['status' => 0]);
				if ($priceObjects) {
					foreach ($priceObjects as $priceObject) {
						if (isset($variantdata[$priceObject->api_id]) && !is_null($variantdata[$priceObject->api_id])) {
							$pricelist_object = PlatformProductPriceList::where(['platform_product_id' => $dbvarproduct->id, 'platform_object_data_id' => $priceObject->id])->first();
							if ($pricelist_object) {
								$pricelist_object->update(['price' => $variantdata[$priceObject->api_id], 'status' => 1]);
							} else {
								PlatformProductPriceList::create(['platform_product_id' => $dbvarproduct->id, 'platform_object_data_id' => $priceObject->id, 'price' => $variantdata[$priceObject->api_id], 'status' => 1]);
							}
						}
					}
				}

				PlatformCustomFieldValue::where(['user_integration_id' => $dbproduct->user_integration_id, 'platform_id' => $this->platformId, 'record_id' => $dbvarproduct->id])->update(['status' => 0]);
				if (isset($product['custom_fields']) && is_array($product['custom_fields']) && count($product['custom_fields'])) {
					$default_product_primary_supplier_field_name = $this->fieldMapHelper->getMappedDataByName($dbproduct->user_integration_id, NULL, "default_product_primary_supplier_field_name", ['custom_data']);
					$default_product_supplier_field_name = $this->fieldMapHelper->getMappedDataByName($dbproduct->user_integration_id, NULL, "default_product_supplier_field_name", ['custom_data']);

					foreach ($product['custom_fields'] as $customfielddata) {
						$isAllowToStore = 0;
						if ($default_product_primary_supplier_field_name && $default_product_primary_supplier_field_name->custom_data && $default_product_primary_supplier_field_name->custom_data == $customfielddata['name']) {
							$isAllowToStore = 1;
						} elseif ($default_product_supplier_field_name && $default_product_supplier_field_name->custom_data && $default_product_supplier_field_name->custom_data == $customfielddata['name']) {
							$isAllowToStore = 1;
						}

						if ($isAllowToStore) {
							$customfield_object = PlatformField::select('id', 'name', 'description', 'status')->where(['custom_field_id' => (string)$customfielddata['id'], 'user_integration_id' => $dbproduct->user_integration_id, 'platform_id' => $this->platformId, 'field_type' => 'custom'])->first();
							if (!$customfield_object) {
								$customfield_object = PlatformField::create(['user_id' => $dbproduct->user_id, 'user_integration_id' => $dbproduct->user_integration_id, 'platform_id' => $this->platformId, 'custom_field_id' => $customfielddata['id'], 'name' => $customfielddata['name'], 'description' => $customfielddata['name'], 'field_type' => 'custom', 'custom_field_type' => 'TEXT', 'type' => 'product', 'platform_object_id' => $product_obj_id, 'status' => 1]);
							} else {
								$customfield_object->name = $customfielddata['name'];
								$customfield_object->description = $customfielddata['name'];
								$customfield_object->status = 1;
								$customfield_object->save();
							}

							if ($customfield_object) {
								$customvalue_object = PlatformCustomFieldValue::where(['platform_field_id' => $customfield_object->id, 'user_integration_id' => $dbproduct->user_integration_id, 'platform_id' => $this->platformId, 'record_id' => $dbvarproduct->id])->first();
								if ($customvalue_object) {
									$customvalue_object->field_value = $customfielddata['value'];
									$customvalue_object->status = 1;
									$customvalue_object->save();
								} else {
									PlatformCustomFieldValue::create(['platform_field_id' => $customfield_object->id, 'user_integration_id' => $dbproduct->user_integration_id, 'platform_id' => $this->platformId, 'record_id' => $dbvarproduct->id, 'field_value' => $customfielddata['value'], 'status' => 1]);
								}
							}
						}
					}
				}

				if (isset($variantdata['option_values']) && count($variantdata['option_values'])) {
					PlatformProductOption::where('platform_product_id', $dbvarproduct->id)->update(['status' => 0]);
					foreach ($variantdata['option_values'] as $option) {
						$optdbdata = ['option_name' => $option['option_display_name'], 'api_option_id' => $option['option_id'], 'platform_product_id' => $dbvarproduct->id, 'api_option_value_id' => $option['id'], 'option_value' => $option['label']];
						$checkoptionVal = PlatformProductOption::where($optdbdata)->first();
						if (!$checkoptionVal) {
							$optdbdata['status'] = 1;
							PlatformProductOption::create($optdbdata);
						} else {
							$checkoptionVal->status = 1;
							$checkoptionVal->save();
						}
					}
				}
			}
			$dbvarproduct->has_variations = 0;
			$dbvarproduct->product_sync_status = 'Ready';
			$dbvarproduct->save();
		}
		return true;
	}

	private function deleteProductsToDatabase($user_id, $user_integration_id, $api_id)
	{
		$response = true;
		try {
			$product = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_product_id' => $api_id])->first();
			if ($product) {
				$product->product_sync_status = 'Inactive';
				$product->is_deleted = 1;
				$product->save();

				if ($product->linked_id) {
					$linked_product = PlatformProduct::find($product->linked_id);
					$linked_product->linked_id = 0;
					$linked_product->save();
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> deleteProductsToDatabase -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	private function createOrderDatabaseEntryForGetOrders($user_id, $user_integration_id, $user_workflow_rule_id, $account, $order, $consent_of_user)
	{
		$productIds = false;
		try {
			if ($order['is_deleted'] == 0) {
				$customerInfo = $customerId = false;
				if (isset($order['billing_address'])) {
					$customerInfo = $order['billing_address'];
				}

				if ($customerInfo) {
					$customerId = $this->getOrCreateCustomer($user_id, $user_integration_id, $order['customer_id'], $account, $customerInfo);
				}

				if ($customerId && is_int($customerId)) {
					$new_order_status = (isset($order['status']) ? $order['status'] : null);
					$old_order_status = null;

					// Order Total issue for the store credit as it's show less amount or 0 amount and can make issue in the other platform
					$orderTotal = ($order['total_inc_tax'] == 0 && $order['subtotal_inc_tax'] != 0) ? bcdiv($order['subtotal_inc_tax'], 1, 2) : (($order['payment_method'] == 'storecredit' && ($order['total_inc_tax'] < $order['subtotal_inc_tax'])) ? bcdiv($order['subtotal_inc_tax'], 1, 2) : bcdiv($order['total_inc_tax'], 1, 2));

					$customer = PlatformCustomer::find($customerId);
					$dborder = PlatformOrder::where(['api_order_id' => $order['id'], 'user_integration_id' => $user_integration_id, 'order_type' => 'SO', 'platform_id' => $this->platformId])->first();

					if (is_null($dborder) && $customer) {
						$dborder = PlatformOrder::create([
							'user_id' => $user_id,
							'user_workflow_rule_id' => $user_workflow_rule_id,
							'platform_id' => $this->platformId,
							'user_integration_id' => $user_integration_id,
							'platform_customer_id' => $customerId,
							'trading_partner_id' => null,
							'order_type' => 'SO',
							'api_order_id' => $order['id'],
							'api_order_reference' => $order['id'],
							'customer_email' => (($customer) ? $customer->email : (($customerInfo) ? $customerInfo['email'] : null)),
							'order_number' => $order['id'],
							'currency' => isset($order['currency_code']) ? $order['currency_code'] : $order['default_currency_code'],
							'order_date' => $order['date_created'],
							'order_status' => (isset($order['status']) ? $order['status'] : null),
							'vendor' => $order['external_merchant_id'],
							'total_discount' => $order['discount_amount'],
							'total_tax' => bcdiv($order['total_tax'], 1, 2),
							'total_amount' => $orderTotal,
							'net_amount' => bcdiv($order['subtotal_inc_tax'], 1, 2),
							'shipping_total' => bcdiv($order['shipping_cost_inc_tax'], 1, 2),
							'shipping_tax' => bcdiv($order['shipping_cost_tax'], 1, 2),
							'payment_date' => null,
							'delivery_date' => null,
							'shipping_method' => null,
							'api_order_payment_status' => 'unpaid',
							'notes' => $order['staff_notes'],
							'api_updated_at' => $order['date_modified'],
							'order_updated_at' => date('Y-m-d H:i:s')
						]);
					} elseif ($dborder && $customer) {
						$old_order_status = $dborder->order_status;

						$dborder->update([
							'platform_customer_id' => $customerId,
							'trading_partner_id' => null,
							'order_type' => 'SO',
							'api_order_reference' => $order['id'],
							'customer_email' => (($customer) ? $customer->email : (($customerInfo) ? $customerInfo['email'] : null)),
							'order_number' => $order['id'],
							'currency' => isset($order['currency_code']) ? $order['currency_code'] : $order['default_currency_code'],
							'order_date' => $order['date_created'],
							'order_status' => (isset($order['status']) ? $order['status'] : null), //(isset($order['status_id']) ? isset($order['status_id']) : null),
							'vendor' => $order['external_merchant_id'],
							'total_discount' => $order['discount_amount'],
							'total_tax' => bcdiv($order['total_tax'], 1, 2),
							'total_amount' => $orderTotal,
							'net_amount' => bcdiv($order['subtotal_inc_tax'], 1, 2),
							'shipping_total' => bcdiv($order['shipping_cost_inc_tax'], 1, 2),
							'shipping_tax' => bcdiv($order['shipping_cost_tax'], 1, 2),
							'payment_date' => null,
							'delivery_date' => null,
							'shipping_method' => null,
							'api_order_payment_status' => 'unpaid',
							'notes' => $order['staff_notes'],
							'api_updated_at' => $order['date_modified'],
							'order_updated_at' => date('Y-m-d H:i:s')
						]);
					}

					if ($dborder && $customer) {
						// Save Channel ID and other additional information 
						$platformAddInfo = PlatformOrderAdditionalInformation::where(['platform_order_id' => $dborder->id])->first();
						if ($platformAddInfo) {
							$platformAddInfo->update(['api_channel_id' => isset($order['channel_id']) ? $order['channel_id'] : null, 'exchange_rate' => isset($order['currency_exchange_rate']) ? $order['currency_exchange_rate'] : 0]);
						} else {
							PlatformOrderAdditionalInformation::create(['platform_order_id' => $dborder->id, 'api_channel_id' => isset($order['channel_id']) ? $order['channel_id'] : null, 'exchange_rate' => isset($order['currency_exchange_rate']) ? $order['currency_exchange_rate'] : 0]);
						}

						if (isset($order['billing_address']) && count($order['billing_address'])) {
							sleep(1);
							$this->updateAddressDatabase($account, $dborder->id, $order['billing_address'], 'billing');
						}

						$dbaddressShipping = [];
						if ($order['shipping_address_count'] > 0 && isset($order['shipping_addresses']) && count($order['shipping_addresses'])) {
							sleep(1);
							$dbaddressShipping = $this->updateAddressDatabase($account, $dborder->id, $order['shipping_addresses'], 'shipping');
							if ($dbaddressShipping && is_array($dbaddressShipping)) {
								$dbaddressShipping = ((isset($dbaddressShipping[0])) ? $dbaddressShipping[0] : $dbaddressShipping);
								if (isset($dbaddressShipping['shipping_method'])) {
									$dborder->shipping_method = $dbaddressShipping['shipping_method'];
									$dborder->carrier_code = (isset($dbaddressShipping['shipping_method']['shippingQuote']) && count($dbaddressShipping['shipping_method']['shippingQuote'])) ? $dbaddressShipping['shipping_method']['shippingQuote']['carrier_code'] : null;
								}
							}
						}

						$lineproductIds = [];
						if (isset($order['products']) && isset($order['products']['url'])) {
							$lineproducturl = ['url' => $order['products']['url']];

							sleep(1);
							$lineproductIds = $this->updateProductAndOrderLines($account, $dborder->id, $lineproducturl);
							if (isset($lineproductIds['product_ids'])) {
								$productIds = $lineproductIds['product_ids'];
							}
						}

						// ADD SHIPPING ORDER LINE
						if (isset($order['shipping_cost_ex_tax']) && isset($order['base_shipping_cost'])) {
							if ($order['shipping_cost_ex_tax']) {
								$shipArr = ['shipping_method' => (isset($dbaddressShipping['shipping_method']) && !is_null($dbaddressShipping['shipping_method'])) ? $dbaddressShipping['shipping_method'] : 'None', 'shipping_cost_ex_tax' => bcdiv($order['shipping_cost_ex_tax'], 1, 2), 'shipping_cost_inc_tax' => bcdiv($order['shipping_cost_inc_tax'], 1, 2), 'shipping_cost_tax' => bcdiv($order['shipping_cost_tax'], 1, 2)];
								$this->updateProductAndOrderLines($account, $dborder->id, $shipArr, 'SHIPPING');
							} elseif ($order['base_shipping_cost']) {
								$shipArr = ['shipping_method' => (isset($dbaddressShipping['shipping_method']) && !is_null($dbaddressShipping['shipping_method'])) ? $dbaddressShipping['shipping_method'] : 'None', 'shipping_cost_ex_tax' => bcdiv($order['base_shipping_cost'], 1, 2), 'shipping_cost_inc_tax' => bcdiv($order['base_shipping_cost'], 1, 2), 'shipping_cost_tax' => bcdiv($order['shipping_cost_tax'], 1, 2)];
								$this->updateProductAndOrderLines($account, $dborder->id, $shipArr, 'SHIPPING');
							}
						}

						// ADD GIFT_WRAPPING ORDER LINE
						if (isset($order['wrapping_cost_ex_tax']) && isset($order['base_wrapping_cost'])) {
							if ($order['wrapping_cost_ex_tax']) {
								$shipArr = ['wrapping_cost_ex_tax' => bcdiv($order['wrapping_cost_ex_tax'], 1, 2), 'wrapping_cost_inc_tax' => bcdiv($order['wrapping_cost_inc_tax'], 1, 2), 'wrapping_cost_tax' => bcdiv($order['wrapping_cost_tax'], 1, 2)];
								$this->updateProductAndOrderLines($account, $dborder->id, $shipArr, 'GIFTWRAPPING');
							} elseif ($order['base_wrapping_cost']) {
								$shipArr = ['wrapping_cost_ex_tax' => bcdiv($order['base_wrapping_cost'], 1, 2), 'wrapping_cost_inc_tax' => bcdiv($order['base_wrapping_cost'], 1, 2), 'wrapping_cost_tax' => bcdiv($order['wrapping_cost_tax'], 1, 2)];
								$this->updateProductAndOrderLines($account, $dborder->id, $shipArr, 'GIFTWRAPPING');
							}
						}

						// ADD HANDLING ORDER LINE
						if (isset($order['handling_cost_ex_tax']) && isset($order['base_handling_cost'])) {
							if ($order['handling_cost_ex_tax']) {
								$shipArr = ['handling_cost_ex_tax' => bcdiv($order['handling_cost_ex_tax'], 1, 2), 'handling_cost_inc_tax' => bcdiv($order['handling_cost_inc_tax'], 1, 2), 'handling_cost_tax' => bcdiv($order['handling_cost_tax'], 1, 2)];
								$this->updateProductAndOrderLines($account, $dborder->id, $shipArr, 'HANDLING');
							} elseif ($order['base_handling_cost']) {
								$shipArr = ['handling_cost_ex_tax' => bcdiv($order['base_handling_cost'], 1, 2), 'handling_cost_inc_tax' => bcdiv($order['base_handling_cost'], 1, 2), 'handling_cost_tax' => bcdiv($order['handling_cost_tax'], 1, 2)];
								$this->updateProductAndOrderLines($account, $dborder->id, $shipArr, 'HANDLING');
							}
						}

						// ADD Store Credit ORDER LINE
						if (isset($order['store_credit_amount']) && $order['store_credit_amount'] && $order['store_credit_amount'] != 0) {
							$disArr = ['store_credit_amount' => $order['store_credit_amount']];
							$this->updateProductAndOrderLines($account, $dborder->id, $disArr, 'STORECREDIT');
						}

						// ADD Gift certificates ORDER LINE
						if (isset($order['gift_certificate_amount']) && $order['gift_certificate_amount'] && $order['gift_certificate_amount'] != 0) {
							$disArr = ['gift_certificate_amount' => $order['gift_certificate_amount']];
							$this->updateProductAndOrderLines($account, $dborder->id, $disArr, 'GIFTCARD');
						}

						// ADD DISCOUNT ORDER LINE
						if ($order['discount_amount'] != 0) {
							$disArr = ['discount_amount' => $order['discount_amount'], 'discount_name' => NULL];
							$this->updateProductAndOrderLines($account, $dborder->id, $disArr, 'DISCOUNT');
						}

						// Add Coupon To discount line
						if (isset($order['coupon_discount']) && $order['coupon_discount'] != 0 && isset($order['coupons']['url'])) {
							sleep(1);
							$couponsdetails = $this->getDataWithUrl($account, $order['coupons']['url']);
							if (isset($couponsdetails[0]['code'])) {
								foreach ($couponsdetails as $coupondetail) {
									$disArr = ['discount_amount' => $coupondetail['discount'], 'discount_name' => 'Coupon - ' . $coupondetail['code']];
									$this->updateProductAndOrderLines($account, $dborder->id, $disArr, 'DISCOUNT');
								}
							}
						}

						// ORDER PAYMENT TRANSACTION
						if ($order['payment_status'] != 'pending') {
							sleep(1);
							$paymentData = static::getAPIPaymentFromOrderID($account, $order['id']);
							if ($order['payment_provider_id'] && isset($paymentData['data']) && count($paymentData['data'])) {
								$row_type = 'PAYMENT';
								$total_amount = 0;
								foreach ($paymentData['data'] as $paymentDetail) {
									if ((($paymentDetail['status'] == 'capture_success' || $paymentDetail['status'] == 'refund_success') || ($paymentDetail['status'] == 'ok' && $paymentDetail['test'] == 1)) && $paymentDetail['event'] != 'pending') {
										$amount = $paymentDetail['amount'];
										if ($paymentDetail['status'] == 'capture_success') {
											//2023-05-23 comment below line, because API send actual amount now
											//$amount = $paymentDetail['amount'] / 100;
										}

										$manual_transaction = PlatformOrderTransaction::where(['platform_order_id' => $dborder->id, 'transaction_type' => 'payment', 'transaction_approval' => 'ok', 'row_type' => 'PAYMENT'])->where(function ($query) {
											$query->where('linked_id', '<>', 0)->orWhere('sync_status', 'Synced');
										})->first();
										if ($row_type == 'PAYMENT' && is_null($manual_transaction)) {
											PlatformOrderTransaction::where(['platform_order_id' => $dborder->id, 'transaction_type' => 'payment', 'transaction_approval' => 'ok', 'row_type' => 'PAYMENT', 'linked_id' => 0])->delete();

											$platform_order_transaction = PlatformOrderTransaction::select('id')->where('platform_order_id', $dborder->id)->where(function ($query) use ($paymentDetail) {
												$query->where('transaction_gateway_id', $paymentDetail['gateway_transaction_id'])->orWhere('transaction_id', $paymentDetail['id']);
											})->first();
											if (is_null($platform_order_transaction)) {
												PlatformOrderTransaction::create(['platform_order_id' => $dborder->id, 'transaction_id' => $paymentDetail['id'], 'transaction_datetime' => $paymentDetail['date_created'], 'transaction_type' => $paymentDetail['event'], 'transaction_method' => $paymentDetail['gateway'], 'transaction_amount' => $amount, 'transaction_approval' => $paymentDetail['status'], 'transaction_reference' => $paymentDetail['reference_transaction_id'], 'transaction_gateway_id' => $paymentDetail['gateway_transaction_id'], 'row_type' => $row_type, 'platform_customer_id' => $customerId, 'currency_code' => $paymentDetail['currency']]);
											}
										} elseif ($row_type == 'REFUND') {
											$platform_order_transaction = PlatformOrderTransaction::select('id')->where('platform_order_id', $dborder->id)->where(function ($query) use ($paymentDetail) {
												$query->where('transaction_gateway_id', $paymentDetail['gateway_transaction_id'])->orWhere('transaction_id', $paymentDetail['id']);
											})->first();
											if (is_null($platform_order_transaction)) {
												PlatformOrderTransaction::create(['platform_order_id' => $dborder->id, 'transaction_id' => $paymentDetail['id'], 'transaction_datetime' => $paymentDetail['date_created'], 'transaction_type' => $paymentDetail['event'], 'transaction_method' => $paymentDetail['gateway'], 'transaction_amount' => $amount, 'transaction_approval' => $paymentDetail['status'], 'transaction_reference' => $paymentDetail['reference_transaction_id'], 'transaction_gateway_id' => $paymentDetail['gateway_transaction_id'], 'row_type' => $row_type, 'platform_customer_id' => $customerId, 'currency_code' => $paymentDetail['currency']]);
											}
										}

										$total_amount += $amount;
									}

									if ($paymentDetail['event'] == 'refund') {
										$row_type = 'REFUND';
									} else {
										$row_type = 'PAYMENT';
									}
								}

								if ($total_amount == $order['total_inc_tax']) {
									$dborder->api_order_payment_status = 'paid';
									$dborder->transaction_sync_status = 'Ready';
								} elseif ($total_amount > 0) {
									$dborder->api_order_payment_status = 'partial_paid';
									$dborder->transaction_sync_status = 'Ready';
								}
							} else {
								if ((isset($order['payment_method']) && !empty($order['payment_method'])) || (isset($order['payment_status']) && $order['payment_status'] == 'paid')) {
									$canFill = false;
									if ($consent_of_user) {
										if (isset($consent_of_user->api_id) && $consent_of_user->api_id = 'Yes') {
											$canFill = true;
										}
									} else {
										$canFill = true;
									}

									if ($canFill) {
										$payment_method = $order['payment_method'] ? $order['payment_method'] : 'Manual Payment';

										$platform_order_transaction = PlatformOrderTransaction::where(['platform_order_id' => $dborder->id])->first();
										$amount = bcdiv($order['total_inc_tax'], 1, 2);
										if (is_null($platform_order_transaction)) {
											PlatformOrderTransaction::create([
												'platform_order_id' => $dborder->id,
												'transaction_id' => random_int(999999999, 9999999999),
												'transaction_datetime' => date('Y-m-d H:i:s'),
												'transaction_type' => 'payment',
												'transaction_method' => $payment_method,
												'transaction_amount' => $amount,
												'transaction_approval' => 'ok',
												'transaction_gateway_id' => random_int(999999999, 9999999999),
												'row_type' => 'PAYMENT',
												'platform_customer_id' => $customerId,
												'currency_code' => ($order['currency_code']) ? $order['currency_code'] : $order['default_currency_code']
											]);
											$dborder->api_order_payment_status = 'paid';
											$dborder->transaction_sync_status = 'Ready';
										} else {
											$dborder->api_order_payment_status = 'paid';
											$platform_order_transaction->update(['transaction_method' => $payment_method, 'transaction_amount' => $amount, 'currency_code' => ($order['currency_code']) ? $order['currency_code'] : $order['default_currency_code']]);
										}
									}
								}
							}
						}

						if (is_array($lineproductIds) && count($lineproductIds)) {
							if ($dborder->linked_id) {
								if ($new_order_status == $old_order_status) {
									$dborder->sync_status = 'Synced';
								} else {
									$dborder->sync_status = 'Ready';
								}
							} else {
								$dborder->sync_status = 'Ready';
							}
						} else {
							$dborder->sync_status = 'Pending';
						}

						if (isset($customer->api_customer_group_id) && $customer->api_customer_group_id) {
							$dborder->api_pricelist_id = $customer->api_customer_group_id;
						} else {
							$dborder->api_pricelist_id = NULL;
						}

						$dborder->save();

						$duplicate_order = PlatformOrder::where(['api_order_id' => $order['id'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'order_type' => 'SO', 'sync_status' => 'Pending'])->where('id', '!=', $dborder->id)->first();
						if ($duplicate_order) {
							PlatformOrder::where(['api_order_id' => $order['id'], 'user_integration_id' => $user_integration_id,  'platform_id' => $this->platformId, 'order_type' => 'SO', 'sync_status' => 'Pending'])->where('id', '!=', $dborder->id)->delete();
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> createOrderDatabaseEntryForGetOrders -> " . $e->getLine() . " -> " . $e->getMessage());
			$productIds = $e->getMessage();
		}

		return $productIds;
	}

	private function updateProductAndOrderLines($account, $orderId, $url, $type = 'ITEM')
	{
		$response = false;
		$uid = 0;
		try {
			if ($type == 'ITEM' && isset($url['url'])) {
				sleep(1);
				$orderlines = $this->getDataWithUrl($account, $url['url']);
				$api_product_ids = $orderline_datas = $orderlineids = [];
				if (isset($orderlines[0]['id'])) {
					foreach ($orderlines as $orderline) {
						$product_name = [];
						$product_name[] = $orderline['name'];
						if (isset($orderline['product_options'][0]['id'])) {
							foreach ($orderline['product_options'] as $product_option) {
								$product_name[] = $product_option['display_name'] . ": " . $product_option['display_value'];
							}
						}

						$api_product_ids[] = $orderline['product_id'];

						$orderline_data = [
							'api_product_id' => $orderline['product_id'],
							'product_name' => implode("\n", $product_name),
							'item_row_sequence' => 0,
							'ean' => @$orderline['ean'],
							'sku' => @$orderline['sku'],
							'gtin' => @$orderline['gtin'],
							'upc' => @$orderline['upc'],
							'mpn' => @$orderline['mpn'],
							'barcode' => null,
							'qty' => $orderline['quantity'],
							'taxes' => null,
							'variation_id' => $orderline['variant_id'],
							'price' => $orderline['base_price'],
							'unit_price' => $orderline['base_price'], // base_total
							'uom' => @$orderline['uom'],
							'description' => null,
							'notes' => $orderline['wrapping_message'],
							'api_code' => null,
							'row_type' => $type,
							'subtotal' => bcdiv($orderline['total_ex_tax'], 1, 2), // price_ex_tax
							'total' => bcdiv($orderline['total_ex_tax'], 1, 2),
							'subtotal_tax' => bcdiv($orderline['total_tax'], 1, 2), // price_tax
							'total_tax' => bcdiv($orderline['total_tax'], 1, 2)
						];

						$platformOrderLine_object = PlatformOrderLine::where(['platform_order_id' => $orderId, 'api_order_line_id' => $orderline['id']])->first();
						if ($platformOrderLine_object) {
							$platformOrderLine_object->update($orderline_data);
							$orderlineids[] = $platformOrderLine_object->id;
						} else {
							$orderline_data['platform_order_id'] = $orderId;
							$orderline_data['api_order_line_id'] = $orderline['id'];
							$orderline_datas[] = $orderline_data;
						}
					}

					if (count($orderlineids)) {
						// deleting the orderline ids which are not there
						PlatformOrderLine::where(['platform_order_id' => $orderId])->whereNotIn('id', $orderlineids)->delete();
					}

					if (count($orderline_datas)) {
						PlatformOrderLine::insert($orderline_datas);
					}
					$response = ['product_ids' => $api_product_ids];
				}
			} elseif ($type == 'SHIPPING' && isset($url['shipping_method']) && isset($url['shipping_cost_ex_tax'])) {
				$platformOrderLine_object = PlatformOrderLine::where(['platform_order_id' => $orderId, 'row_type' => $type])->first();
				if ($platformOrderLine_object) {
					$platformOrderLine_object->update(['price' => bcdiv($url['shipping_cost_ex_tax'], 1, 2), 'unit_price' => bcdiv($url['shipping_cost_ex_tax'], 1, 2), 'subtotal' => bcdiv($url['shipping_cost_ex_tax'], 1, 2), 'subtotal_tax' => bcdiv($url['shipping_cost_tax'], 1, 2), 'total' => bcdiv($url['shipping_cost_inc_tax'], 1, 2), 'total_tax' => bcdiv($url['shipping_cost_tax'], 1, 2)]);
				} else {
					PlatformOrderLine::create(['platform_order_id' => $orderId, 'row_type' => $type, 'product_name' => $url['shipping_method'], 'qty' => 1, 'price' => bcdiv($url['shipping_cost_ex_tax'], 1, 2), 'unit_price' => bcdiv($url['shipping_cost_ex_tax'], 1, 2), 'subtotal' => bcdiv($url['shipping_cost_ex_tax'], 1, 2), 'subtotal_tax' => bcdiv($url['shipping_cost_tax'], 1, 2), 'total' => bcdiv($url['shipping_cost_inc_tax'], 1, 2), 'total_tax' => bcdiv($url['shipping_cost_tax'], 1, 2)]);
				}
			} elseif ($type == 'GIFTWRAPPING' && isset($url['wrapping_cost_ex_tax'])) {
				$platformOrderLine_object = PlatformOrderLine::where(['platform_order_id' => $orderId, 'row_type' => $type])->first();
				if ($platformOrderLine_object) {
					$platformOrderLine_object->update(['price' => bcdiv($url['wrapping_cost_ex_tax'], 1, 2), 'unit_price' => bcdiv($url['wrapping_cost_ex_tax'], 1, 2), 'subtotal' => bcdiv($url['wrapping_cost_ex_tax'], 1, 2), 'subtotal_tax' => bcdiv($url['wrapping_cost_tax'], 1, 2), 'total' => bcdiv($url['wrapping_cost_inc_tax'], 1, 2), 'total_tax' => bcdiv($url['wrapping_cost_tax'], 1, 2)]);
				} else {
					PlatformOrderLine::create(['platform_order_id' => $orderId, 'row_type' => $type, 'product_name' => 'Gift Wrapping', 'qty' => 1, 'price' => bcdiv($url['wrapping_cost_ex_tax'], 1, 2), 'unit_price' => bcdiv($url['wrapping_cost_ex_tax'], 1, 2), 'subtotal' => bcdiv($url['wrapping_cost_ex_tax'], 1, 2), 'subtotal_tax' => bcdiv($url['wrapping_cost_tax'], 1, 2), 'total' => bcdiv($url['wrapping_cost_inc_tax'], 1, 2), 'total_tax' => bcdiv($url['wrapping_cost_tax'], 1, 2)]);
				}
			} elseif ($type == 'HANDLING' && isset($url['handling_cost_ex_tax'])) {
				$platformOrderLine_object = PlatformOrderLine::where(['platform_order_id' => $orderId, 'row_type' => $type])->first();
				if ($platformOrderLine_object) {
					$platformOrderLine_object->update(['price' => bcdiv($url['handling_cost_ex_tax'], 1, 2), 'unit_price' => bcdiv($url['handling_cost_ex_tax'], 1, 2), 'subtotal' => bcdiv($url['handling_cost_ex_tax'], 1, 2), 'subtotal_tax' => bcdiv($url['handling_cost_tax'], 1, 2), 'total' => bcdiv($url['handling_cost_inc_tax'], 1, 2), 'total_tax' => bcdiv($url['handling_cost_tax'], 1, 2)]);
				} else {
					PlatformOrderLine::create(['platform_order_id' => $orderId, 'row_type' => $type, 'product_name' => 'Handling', 'qty' => 1, 'price' => bcdiv($url['handling_cost_ex_tax'], 1, 2), 'unit_price' => bcdiv($url['handling_cost_ex_tax'], 1, 2), 'subtotal' => bcdiv($url['handling_cost_ex_tax'], 1, 2), 'subtotal_tax' => bcdiv($url['handling_cost_tax'], 1, 2), 'total' => bcdiv($url['handling_cost_inc_tax'], 1, 2), 'total_tax' => bcdiv($url['handling_cost_tax'], 1, 2)]);
				}
			} elseif ($type == 'DISCOUNT' && isset($url['discount_amount'])) {
				$platformOrderLine_object = PlatformOrderLine::where(['platform_order_id' => $orderId, 'product_name' => @$url['discount_name'], 'row_type' => $type])->first();
				if ($platformOrderLine_object) {
					$platformOrderLine_object->update(['price' => $url['discount_amount'] * (-1), 'unit_price' => $url['discount_amount'] * (-1), 'subtotal' => $url['discount_amount'] * (-1), 'subtotal_tax' => 0, 'subtotal' => $url['discount_amount'] * (-1), 'subtotal_tax' => 0]);
				} else {
					PlatformOrderLine::create(['platform_order_id' => $orderId, 'row_type' => $type, 'product_name' => @$url['discount_name'], 'qty' => 1, 'price' => $url['discount_amount'] * (-1), 'unit_price' => $url['discount_amount'] * (-1), 'subtotal' => $url['discount_amount'] * (-1), 'subtotal_tax' => 0, 'subtotal' => $url['discount_amount'] * (-1), 'subtotal_tax' => 0]);
				}
			} elseif ($type == 'GIFTCARD' && isset($url['gift_certificate_amount'])) {
				$platformOrderLine_object = PlatformOrderLine::where(['platform_order_id' => $orderId, 'row_type' => $type])->first();
				if ($platformOrderLine_object) {
					$platformOrderLine_object->update(['price' => $url['gift_certificate_amount'] * (-1), 'unit_price' => $url['gift_certificate_amount'] * (-1), 'subtotal' => $url['gift_certificate_amount'] * (-1), 'subtotal_tax' => 0, 'total' => $url['gift_certificate_amount'] * (-1), 'total_tax' => 0]);
				} else {
					PlatformOrderLine::create(['platform_order_id' => $orderId, 'product_name' => 'Gift Certificates', 'row_type' => $type, 'qty' => 1, 'price' => $url['gift_certificate_amount'] * (-1), 'unit_price' => $url['gift_certificate_amount'] * (-1), 'subtotal' => $url['gift_certificate_amount'] * (-1), 'subtotal_tax' => 0, 'total' => $url['gift_certificate_amount'] * (-1), 'total_tax' => 0]);
				}
			} elseif ($type == 'STORECREDIT' && isset($url['store_credit_amount'])) {
				$platformOrderLine_object = PlatformOrderLine::where(['platform_order_id' => $orderId, 'row_type' => $type])->first();
				if ($platformOrderLine_object) {
					$platformOrderLine_object->update(['price' => $url['store_credit_amount'] * (-1), 'unit_price' => $url['store_credit_amount'] * (-1), 'subtotal' => $url['store_credit_amount'] * (-1), 'subtotal_tax' => 0, 'total' => $url['store_credit_amount'] * (-1), 'total_tax' => 0]);
				} else {
					PlatformOrderLine::create(['platform_order_id' => $orderId, 'product_name' => 'Store Credit', 'row_type' => $type, 'qty' => 1, 'price' => $url['store_credit_amount'] * (-1), 'unit_price' => $url['store_credit_amount'] * (-1), 'subtotal' => $url['store_credit_amount'] * (-1), 'subtotal_tax' => 0, 'total' => $url['store_credit_amount'] * (-1), 'total_tax' => 0]);
				}
			} elseif ($type == 'TAX' && isset($url['total_tax'])) {
				$platformOrderLine_object = PlatformOrderLine::where(['platform_order_id' => $orderId, 'row_type' => $type])->first();
				if ($platformOrderLine_object) {
					$platformOrderLine_object->update(['price' => bcdiv($url['total_tax'], 1, 2), 'unit_price' => bcdiv($url['total_tax'], 1, 2), 'subtotal' => bcdiv($url['total_tax'], 1, 2), 'subtotal_tax' => 0, 'total' => bcdiv($url['total_tax'], 1, 2), 'total_tax' => 0]);
				} else {
					PlatformOrderLine::create(['platform_order_id' => $orderId, 'row_type' => $type, 'qty' => 1, 'price' => bcdiv($url['total_tax'], 1, 2), 'unit_price' => bcdiv($url['total_tax'], 1, 2), 'subtotal' => bcdiv($url['total_tax'], 1, 2), 'subtotal_tax' => 0, 'total' => bcdiv($url['total_tax'], 1, 2), 'total_tax' => 0]);
				}
			}
		} catch (\Exception $e) {
			\Log::error($uid . " -> BigcommerceController -> updateProductAndOrderLines -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	private function updateAddressDatabase($account, $orderId, $address, $type)
	{
		$response = false;
		$uid = 0;
		$shippingAddress = $shippingQuote = null;
		try {
			$addressCheck = PlatformOrderAddress::where(['platform_order_id' => $orderId, 'address_type' => $type])->first();
			if (!$addressCheck) {
				$addressArr = [];
				if ($type === 'billing') {
					$addressArr = [
						'platform_order_id' => $orderId,
						'address_type' => $type,
						'address_name' => $address['first_name'] . ' ' . $address['last_name'],
						'address_id' => null,
						'firstname' => $address['first_name'],
						'lastname' => $address['last_name'],
						'company' => $address['company'],
						'address1' => $address['street_1'],
						'address2' => $address['street_2'],
						'address3' => null,
						'address4' => null,
						'city' => $address['city'],
						'state' => $address['state'],
						'postal_code' => $address['zip'],
						'country' => $address['country_iso2'],
						'email' => $address['email'],
						'phone_number' => $address['phone']
					];
				} elseif ($type === 'shipping') {
					if (isset($address['url'])) {
						$shippingAddress = $this->getDataWithUrl($account, $address['url']);
						if (isset($shippingAddress[0]['id'])) {
							$count = 1; // count($shippingAddress); // whether to get only one shipping address or more
							for ($x = 0; $x < $count; $x++) {
								if (isset($shippingAddress[$x])) {
									if (isset($shippingAddress[$x]['shipping_quotes'])) {
										$shippingQuote = $this->getDataWithUrl($account, $shippingAddress[$x]['shipping_quotes']['url']);
									}
									$addressArr = [
										'platform_order_id' => $orderId,
										'address_type' => $type,
										'address_name' => $shippingAddress[$x]['first_name'] . ' ' . $shippingAddress[$x]['last_name'],
										'address_id' => $shippingAddress[$x]['id'],
										'firstname' => $shippingAddress[$x]['first_name'],
										'lastname' => $shippingAddress[$x]['last_name'],
										'company' => $shippingAddress[$x]['company'],
										'address1' => $shippingAddress[$x]['street_1'],
										'address2' => $shippingAddress[$x]['street_2'],
										'address3' => null,
										'address4' => null,
										'city' => $shippingAddress[$x]['city'],
										'state' => $shippingAddress[$x]['state'],
										'postal_code' => $shippingAddress[$x]['zip'],
										'country' => $shippingAddress[$x]['country_iso2'],
										'email' => $shippingAddress[$x]['email'],
										'phone_number' => $shippingAddress[$x]['phone'],
										'carrier_code' => ($shippingQuote && isset($shippingQuote['carrier_code'])) ? $shippingQuote['carrier_code'] : null
									];
								}
							}
						}
					}
				}

				if (count($addressArr) > 0) {
					$dbaddress = PlatformOrderAddress::create($addressArr);
					if ($dbaddress) {
						$response = $dbaddress->id;
						if ($type === 'shipping' && $shippingAddress) {
							$response = ((count($shippingAddress) > 0) ? $shippingAddress + ['shippingQuote' => $shippingQuote] : $response);
						}
					}
				}
			} else {
				$addressArr = [];
				if ($type === 'billing') {
					$addressArr = [
						'address_type' => $type,
						'address_name' => $address['first_name'] . ' ' . $address['last_name'],
						'address_id' => null,
						'firstname' => $address['first_name'],
						'lastname' => $address['last_name'],
						'company' => $address['company'],
						'address1' => $address['street_1'],
						'address2' => $address['street_2'],
						'address3' => null,
						'address4' => null,
						'city' => $address['city'],
						'state' => $address['state'],
						'postal_code' => $address['zip'],
						'country' => $address['country_iso2'],
						'email' => $address['email'],
						'phone_number' => $address['phone']
					];
				} elseif ($type === 'shipping') {
					if (isset($address['url'])) {
						$shippingAddress = $this->getDataWithUrl($account, $address['url']);
						if (isset($shippingAddress[0]['id'])) {
							$count = 1; // count($shippingAddress); // whether to get only one shipping address or more
							for ($x = 0; $x < $count; $x++) {
								if (isset($shippingAddress[$x])) {
									if (isset($shippingAddress[$x]['shipping_quotes'])) {
										$shippingQuote = $this->getDataWithUrl($account, $shippingAddress[$x]['shipping_quotes']['url']);
									}
									$addressArr = [
										'address_type' => $type,
										'address_name' => $shippingAddress[$x]['first_name'] . ' ' . $shippingAddress[$x]['last_name'],
										'address_id' => $shippingAddress[$x]['id'],
										'firstname' => $shippingAddress[$x]['first_name'],
										'lastname' => $shippingAddress[$x]['last_name'],
										'company' => $shippingAddress[$x]['company'],
										'address1' => $shippingAddress[$x]['street_1'],
										'address2' => $shippingAddress[$x]['street_2'],
										'address3' => null,
										'address4' => null,
										'city' => $shippingAddress[$x]['city'],
										'state' => $shippingAddress[$x]['state'],
										'postal_code' => $shippingAddress[$x]['zip'],
										'country' => $shippingAddress[$x]['country_iso2'],
										'email' => $shippingAddress[$x]['email'],
										'phone_number' => $shippingAddress[$x]['phone'],
										'carrier_code' => ($shippingQuote && isset($shippingQuote['carrier_code'])) ? $shippingQuote['carrier_code'] : null
									];
								}
							}
						}
					}
				}

				if (count($addressArr) > 0) {
					$addressCheck->update($addressArr);
					if ($addressCheck) {
						$response = $addressCheck->id;
						if ($type === 'shipping' && $shippingAddress) {
							$response = ((count($shippingAddress) > 0) ? $shippingAddress + ['shippingQuote' => $shippingQuote] : $response);
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($uid . " -> BigcommerceController -> updateAddressDatabase -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	private function getOrCreateCustomer($user_id, $user_integration_id, $customer_api_id, $account, $data)
	{
		$response = false;
		try {
			if ($customer_api_id) {
				$customer = PlatformCustomer::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_customer_id' => $customer_api_id])->select('id')->first();
			} else {
				$customer = PlatformCustomer::where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'email' => $data['email'], 'phone' => $data['phone'], 'first_name' => $data['first_name'], 'last_name' => $data['last_name']])->select('id')->first();
			}

			if ($customer) {
				$result = $this->updateCustomer($customer_api_id, $account, $customer->id, $data);
				$response = $customer->id;
			} else {
				$result = $this->createCustomer($user_id, $user_integration_id, $customer_api_id, $account, $data);
				if ($result && is_int($result)) {
					$response = $result;
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> getOrCreateCustomer -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	private function createCustomer($user_id, $user_integration_id, $customer_api_id, $account, $data)
	{
		$response = false;
		$id = null;
		try {
			if ($customer_api_id) {
				$customer = $this->getAPICustomerFromIDWithAddress($account, $customer_api_id);
				if ($customer && isset($customer['data']) && count($customer['data']) > 0) {
					$customer = $this->createCustomerDatabase($user_id, $user_integration_id, $customer['data'][0]);
					if ($customer && is_int($customer)) {
						$id = $customer;
					}
				}
			} else {
				$customer = PlatformCustomer::create([
					'user_id' => $user_id,
					'platform_id' => $this->platformId,
					'user_integration_id' => $user_integration_id,
					'api_customer_id' => 0,
					'api_customer_code' => 0,
					'api_customer_group_id' => 0,
					'customer_name' => $data['first_name'] . ' ' . $data['last_name'],
					'first_name' => $data['first_name'],
					'last_name' => $data['last_name'],
					'company_name' => $data['company'],
					'phone' => $data['phone'],
					'email' => $data['email'],
					'address1' => $data['street_1'] . ' ' . $data['street_2'],
					'address2' => $data['city'],
					'address3' => (isset($data['state']) && !empty($data['state'])) ? ',' . $data['state'] : '',
					'postal_addresses' => $data['zip'],
					'country' => $data['country_iso2'],
					'sync_status' => 'Ready',
					'api_created_at' => date('Y-m-d H:i:s'),
					'api_updated_at' => date('Y-m-d H:i:s')
				]);
				if ($customer) {
					$id = $customer->id;
				}
			}
			if ($id && is_int($id)) {
				$response = $id;
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> createCustomer -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	private function updateCustomer($customer_api_id, $account, $customer_id, $customerData)
	{
		$response = false;
		$id = null;
		$uid = 0;
		try {
			if ($customer_api_id) {
				$customer = $this->getAPICustomerFromIDWithAddress($account, $customer_api_id);
				if ($customer && isset($customer['data']) && count($customer['data']) > 0) {
					$customer = $this->updateCustomerDatabase($customer['data'][0], $customer_id);
					if ($customer && is_int($customer)) {
						$id = $customer;
					}
				}
			} else {
				$customer = PlatformCustomer::find($customer_id);
				if ($customer) {
					$uid = $customer->user_integration_id;
					$customer->update([
						'customer_name' => $customerData['first_name'] . ' ' . $customerData['last_name'],
						'first_name' => $customerData['first_name'],
						'last_name' => $customerData['last_name'],
						'company_name' => $customerData['company'],
						'phone' => $customerData['phone'],
						'email' => $customerData['email'],
						'address1' => $customerData['street_1'] . ' ' . $customerData['street_2'],
						'address2' => $customerData['city'],
						'address3' => (isset($customerData['state']) && !empty($customerData['state'])) ? ',' . $customerData['state'] : '',
						'postal_addresses' => $customerData['zip'],
						'country' => $customerData['country_iso2'],
						'sync_status' => 'Ready',
						'api_created_at' => date('Y-m-d H:i:s'),
						'api_updated_at' => date('Y-m-d H:i:s')
					]);
				}
			}

			if ($id && is_int($id)) {
				$response = $id;
			}
		} catch (\Exception $e) {
			\Log::error($uid . " -> BigcommerceController -> updateCustomer -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	private function createCustomerDatabase($user_id, $user_integration_id, $data)
	{
		$response = false;
		try {
			if (isset($data['email'])) {
				$address = [];
				if ($data['address_count'] > 0) {
					$address = $data['addresses'][0];
				}
				$customer = PlatformCustomer::create([
					'user_id' => $user_id,
					'platform_id' => $this->platformId,
					'user_integration_id' => $user_integration_id,
					'api_customer_id' => $data['id'],
					'api_customer_code' => null,
					'api_customer_group_id' => (isset($data['customer_group_id'])) ? $data['customer_group_id'] : null,
					'customer_name' => $data['first_name'] . ' ' . $data['last_name'],
					'first_name' => $data['first_name'],
					'last_name' => $data['last_name'],
					'company_name' => $data['company'],
					'phone' => $data['phone'],
					'email' => $data['email'],
					'address1' => (isset($address['address1']) ? $address['address1'] : '') . ' ' . (isset($address['address2']) ? $address['address2'] : ''),
					'address2' => isset($address['city']) ? $address['city'] : '',
					'address3' => (isset($address['state_or_province']) && !empty($address['state_or_province'])) ? $address['state_or_province'] : '',
					'postal_addresses' => isset($address['postal_code']) ? $address['postal_code'] : '',
					'country' => isset($address['country_code']) ? $address['country_code'] : '',
					'sync_status' => 'Ready',
					'api_created_at' => $data['date_created'],
					'api_updated_at' => $data['date_modified']
				]);

				if ($customer) {
					$response = $customer->id;
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> createCustomerDatabase -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	private function updateCustomerDatabase($data, $customer_id)
	{
		$response = false;
		$uid = 0;
		try {
			if (isset($data['email'])) {
				$address = [];
				if ($data['address_count'] > 0) {
					$address = $data['addresses'][0];
				}
				$customer = PlatformCustomer::find($customer_id);
				if ($customer) {
					$uid = $customer->user_integration_id;
					$customer = $customer->update([
						'api_customer_code' => null,
						'api_customer_group_id' => (isset($data['customer_group_id'])) ? $data['customer_group_id'] : null,
						'customer_name' => $data['first_name'] . ' ' . $data['last_name'],
						'first_name' => $data['first_name'],
						'last_name' => $data['last_name'],
						'company_name' => $data['company'],
						'phone' => $data['phone'],
						'email' => $data['email'],
						'address1' => (isset($address['address1']) ? $address['address1'] : '') . ' ' . (isset($address['address2']) ? $address['address2'] : ''),
						'address2' => isset($address['city']) ? $address['city'] : '',
						'address3' => (isset($address['state_or_province']) && !empty($address['state_or_province'])) ? $address['state_or_province'] : '',
						'postal_addresses' => isset($address['postal_code']) ? $address['postal_code'] : '',
						'country' => isset($address['country_code']) ? $address['country_code'] : '',
						'sync_status' => 'Ready',
						'api_created_at' => $data['date_created'],
						'api_updated_at' => $data['date_modified']
					]);
					$response = $customer_id;
				}
			}
		} catch (\Exception $e) {
			\Log::error($uid . " -> BigcommerceController -> updateCustomerDatabase -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}

	private function errorReportBC($user_integration_id, $response, $module)
	{
		Storage::disk('local')->append('bigcom-error.txt', date('Y-m-d H:i:s') . ' - START - ' . $module . ' - UID - ' . $user_integration_id);
		Storage::disk('local')->append('bigcom-error.txt', 'Response - ' . $response);
		Storage::disk('local')->append('bigcom-error.txt', '------------- ---------------- -------------');
		return true;
	}

	// WILL BE REMOVED
	public function test() // test
	{
		$account = $this->mainModel->getPlatformAccountByUserIntegration(407, $this->platformId);

		if ($account) {
			$order = static::getAPISalesOrderFromId($account, 948);

			echo '<pre>';
			print_r($order);

			if ((isset($order['payment_method']) && !empty($order['payment_method'])) || (isset($order['payment_status']) && $order['payment_status'] == 'paid')) {
				echo "Pass";
			}
		}
	}

	public function ExecuteBigCommerceEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_name, $platform_workflow_rule_id, $record_id)
	{
		$response = true;
		try {
			if ($method == 'GET' && $event == 'SALESORDER') {
				$response = $this->getSalesOrder($is_initial_sync, $user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id);
			} elseif ($method == 'GET' && $event == 'SALESORDERBACKUP') {
				$response = $this->getSalesOrderBackup($is_initial_sync, $user_id, $user_integration_id, $user_workflow_rule_id, $platform_workflow_rule_id);
			} elseif ($method == 'GET' && $event == 'CUSTOMERGROUP') {
				$response = $this->getCustomerGroup($is_initial_sync, $user_id, $user_integration_id);
			} elseif ($method == 'GET' && $event == 'PRICELIST') {
				$response = $this->getPriceList($is_initial_sync, $user_id, $user_integration_id);
			} elseif ($method == 'GET' && $event == 'CATEGORY') {
				$response = $this->getCategories($is_initial_sync, $user_id, $user_integration_id);
			} elseif ($method == 'GET' && $event == 'BRANDS') {
				$response = $this->getBrands($is_initial_sync, $user_id, $user_integration_id);
			} elseif ($method == 'GET' && $event == 'PAYMENTMETHOD') {
				$response = $this->getPaymentMethods($user_id, $user_integration_id);
			} elseif ($method == 'GET' && $event == 'ORDERSTATUS') {
				$response = $this->getOrderStatus($is_initial_sync, $user_id, $user_integration_id);
			} elseif ($method == 'GET' && $event == 'ZONE') {
				$response = $this->getShippingZones($user_id, $user_integration_id);
			} elseif ($method == 'GET' && $event == 'SHIPPINGMETHOD') {
				$response = $this->getZoneShippingMethods($user_id, $user_integration_id, $record_id);
			} elseif ($method == 'MUTATE' && $event == 'CUSTOMER') {
				$response = $this->syncCustomersToBigCommerce($is_initial_sync, $user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $record_id);
			} elseif ($method == 'MUTATE' && $event == 'PRODUCT') {
				$response = $this->syncProductOrVariantToBigCommerce($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $record_id);
			} elseif ($method == 'GET' && $event == 'PRODUCT') {
				$response = $this->getProducts($is_initial_sync, $user_id, $user_integration_id, $user_workflow_rule_id);
			} elseif ($method == 'GET' && $event == 'PRODUCTBACKUP') {
				$response = $this->getProductBackup($is_initial_sync, $user_id, $user_integration_id, $user_workflow_rule_id);
			} elseif ($method == 'MUTATE' && $event == 'SHIPMENT') {
				$response = app('App\Http\Controllers\Bigcommerce\BigCommerceSubController')->syncOrderShipment($user_id, $user_integration_id, $source_platform_name, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
			} elseif ($method == 'MUTATE' && $event == 'INVENTORY') {
				$response = $this->syncUpdatedInventoryToBigCommerce($user_id, $user_integration_id, null, $user_workflow_rule_id, $source_platform_name, $record_id);
			} elseif ($method == 'MUTATE' && $event == 'SALESORDER') {
				$response = app('App\Http\Controllers\Bigcommerce\BigCommerceSubController')->syncOrder($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_name, 'Ready', $record_id);
			} elseif ($method == 'GET' && $event == 'TRANSACTION') {
				$response = $this->getTransactionInformation($is_initial_sync, $user_id, $user_integration_id, $user_workflow_rule_id);
			} elseif ($method == 'GET' && $event == 'REFUND') {
				$response = $this->getRefundOrders($is_initial_sync, $user_id, $user_integration_id, $user_workflow_rule_id);
			} elseif ($method == 'MUTATE' && $event == 'REFUND') {
				$response = app('App\Http\Controllers\Bigcommerce\BigCommerceSubController')->syncRefundOrder($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_name, 'Ready', $record_id);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigcommerceController -> ExecuteBigCommerceEvents -> " . $e->getLine() . " -> " . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}
}
