<?php

namespace App\Http\Controllers\Brightpearl;

use App\Helper\MainModel;
use App\Models\UserIntegration;
use App\Helper\ConnectionHelper;
use App\Models\PlatformCustomer;
use App\Helper\Api\BrightpearlApi;
use App\Helper\FieldMappingHelper;
use App\Models\PlatformObjectData;
use App\Models\PlatformProductOption;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class BrightpearlSearchController
{
	private const PLATFORMNAME = 'brightpearl';

	private $mobj, $bp, $map, $helper, $platformId;

	public function __construct()
	{
		$this->mobj = new MainModel();
		$this->bp = new BrightpearlApi;
		$this->map = new FieldMappingHelper();
		$this->helper = new ConnectionHelper;
		$this->platformId = $this->helper->getPlatformIdByName(self::PLATFORMNAME);
	}
	/**
	 * function : searchBrand
	 * Desc : search brand
	 * @param [type] $user_integration_id
	 * @param [type] $searchValue
	 * @return void
	 */
	public function searchBrand($user_integration_id, $searchValue)
	{
		$data = ['status' => 0];
		$account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'api_domain', 'account_name', 'app_id', 'app_secret']);
		try {
			$brand_object_id = $this->helper->getObjectId('brand');
			if ($user_integration_id && $account && $searchValue && $brand_object_id && is_numeric($searchValue) == false) {
				$brand_id = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $brand_object_id, 'name' => $searchValue])->select('api_id')->first();
				if ($brand_id) {
					$data['status'] = 1;
					$data['data'] = $brand_id->api_id;
				} else {
					$response = $this->bp->searchBrand($account, urlencode($searchValue));
					if ($response = json_decode($response->getBody(), true)) {
						if (isset($response['response']['results']) && is_array($response['response']['results'])) {
							if (count($response['response']['results'])) {
								$create = $this->createBrand($user_integration_id, $response['response']['results'][0]);
							} else {
								$create = $this->createBrand($user_integration_id, $searchValue, $account);
							}

							if ($create['status'] == 1 && isset($create['data'])) {
								$data['status'] = 1;
								$data['data'] = $create['data'];
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BrightpearlSearchController -> searchBrand -> " . $e->getLine() . " -> " . $e->getMessage());
			$data['error'] = $e->getMessage();
		}
		return $data;
	}

	/**
	 * Function : createBrand
	 * Desc : create brand
	 * @param [type] $user_integration_id
	 * @param [type] $value
	 * @param [type] $account
	 * @return void
	 */
	public function createBrand($user_integration_id, $value, $account = null)
	{
		$data['status'] = 0;
		$api_id = $brandname = null;
		try {
			$user_integration = UserIntegration::find($user_integration_id);
			$brand_object_id = $this->helper->getObjectId('brand');
			if ($user_integration && $brand_object_id) {
				if (is_array($value) && count($value) > 1) {
					$api_id = $value[0];
					$brandname = $value[1];
				} else {
					if (!$account) {
						$account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'api_domain', 'account_name', 'app_id', 'app_secret']);
					}
					$api_data = ['name' => $value];
					$response = $this->bp->createBrand($account, $api_data);
					if ($response && $response = json_decode($response->getBody(), true)) {
						if (isset($response['response']) && is_int($response['response'])) {
							$api_id = $response['response'];
							$brandname = $value;
						}
					}
				}

				if ($api_id && $brandname) {
					$objectData = PlatformObjectData::create(['user_id' => $user_integration->user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $brand_object_id, 'api_id' => $api_id, 'name' => $brandname, 'api_code' => $api_id]);
					if ($objectData) {
						$data['status'] = 1;
						$data['data'] = $api_id;
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BrightpearlSearchController -> createBrand -> " . $e->getLine() . " -> " . $e->getMessage());
			$data['error'] = $e->getMessage();
		}
		return $data;
	}

	public function searchCategory($user_integration_id, $searchValue)
	{
		$data = ['status' => 0];
		$account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'api_domain', 'account_name', 'app_id', 'app_secret']);
		try {
			$category_object_id = $this->helper->getObjectId('category');
			if ($user_integration_id && $account && $searchValue && $category_object_id && is_numeric($searchValue) == false) {
				$category_id = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $category_object_id, 'name' => $searchValue])->select('api_id')->first();
				if ($category_id) {
					$data['status'] = 1;
					$data['data'] = $category_id->api_id;
				} else {
					$response = $this->bp->searchCategory($account, urlencode($searchValue));
					if ($response = json_decode($response->getBody(), true)) {
						if (isset($response['response']['results']) && is_array($response['response']['results'])) {
							if (count($response['response']['results'])) {
								$create = $this->createCategory($user_integration_id, $response['response']['results'][0]);
							} else {
								$create = $this->createCategory($user_integration_id, $searchValue, $account);
							}

							if ($create['status'] == 1 && isset($create['data'])) {
								$data['status'] = 1;
								$data['data'] = $create['data'];
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BrightpearlSearchController -> searchCategory -> " . $e->getLine() . " -> " . $e->getMessage());
			$data['error'] = $e->getMessage();
		}
		return $data;
	}

	/**
	 * Function : createCategory
	 * Desc : create category
	 * @param [type] $user_integration_id
	 * @param [type] $value
	 * @param [type] $account
	 * @return void
	 */
	public function createCategory($user_integration_id, $value, $account = null)
	{
		$data['status'] = 0;
		$api_id = $categoryname = $parent_id = null;
		try {
			$user_integration = UserIntegration::find($user_integration_id);
			$category_object_id = $this->helper->getObjectId('category');
			if ($user_integration && $category_object_id) {
				if (is_array($value) && count($value) > 1) {
					$api_id = $value[0];
					$categoryname = $value[1];
					$parent_id = $value[2];
				} else {
					if (!$account) {
						$account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'api_domain', 'account_name', 'app_id', 'app_secret']);
					}
					$api_data = ['name' => $value];
					$response = $this->bp->createCategory($account, $api_data);
					if ($response && $response = json_decode($response->getBody(), true)) {
						if (isset($response['response']) && is_int($response['response'])) {
							$api_id = $response['response'];
							$categoryname = $value;
						}
					}
				}

				if ($api_id && $categoryname) {
					$objectData = PlatformObjectData::create(['user_id' => $user_integration->user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $category_object_id, 'api_id' => $api_id, 'name' => $categoryname, 'api_code' => $api_id, 'parent_id' => ($parent_id) ? $parent_id : null]);
					if ($objectData) {
						$data['status'] = 1;
						$data['data'] = $api_id;
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BrightpearlSearchController -> createCategory -> " . $e->getLine() . " -> " . $e->getMessage());
			$data['error'] = $e->getMessage();
		}
		return $data;
	}

	/**
	 * Function : SearchCustomerByEmail
	 * Desc : search customer by email
	 * @param [type] $email
	 * @param [type] $account
	 * @param [type] $userId
	 * @param [type] $userIntegrationId
	 * @return void
	 */
	public function SearchCustomerByEmail($email, $account = NULL, $userId = NULL, $userIntegrationId = NULL)
	{
		try {
			if ($account) {
				if ($account && $this->platformId) {
					if (isset($account->platform_id) && $account->platform_id == $this->platformId) {
						//for only BP integration
						$response = $this->bp->SearchCustomer($account, "?primaryEmail={$email}&isCustomer=true&columns=contactId,firstName,lastName,primaryEmail&sort=contactId.ASC", 'json');
						if ($result = json_decode($response->getBody(), true)) {
							if (isset($result['response']['results'][0]) && !empty($result['response']['results']) && is_array($result['response']['results'])) {
								$find = PlatformCustomer::where([['platform_id', '=', $this->platformId], ['user_integration_id', '=', $userIntegrationId], ['api_customer_id', '=', $result['response']['results'][0][0]]])->first();
								if ($find) {
									$customer_name = $result['response']['results'][0][1] . " " . $result['response']['results'][0][2];
									$find->customer_name = $customer_name;
									$find->first_name = $result['response']['results'][0][1];
									$find->last_name = $result['response']['results'][0][2];
									$find->email = $result['response']['results'][0][3];
									$find->api_updated_at = date('Y-m-d H:i:s');
									$find->save();
									$contactID = $result['response']['results'][0][0];
								} else {
									$customer_name = $result['response']['results'][0][1] . " " . $result['response']['results'][0][2];
									$fields = array('user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'api_customer_id' => $result['response']['results'][0][0], 'first_name' => $result['response']['results'][0][1], 'last_name' => $result['response']['results'][0][2], 'email' => $result['response']['results'][0][3], 'sync_status' => 'Ready');
									$this->mobj->makeInsert('platform_customer', $fields);
									$contactID = $result['response']['results'][0][0];
								}
								return $contactID;
							}
						}
						return false;
					}
				}
			} else {
				$account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
				if ($account && $this->platformId) {
					if (isset($account->platform_id) && $account->platform_id == $this->platformId) {
						//for only BP integration
						$response = $this->bp->SearchCustomer($account, "?primaryEmail={$email}&isCustomer=true&columns=contactId,firstName,lastName,primaryEmail&sort=contactId.ASC", 'json');
						if ($result = json_decode($response->getBody(), true)) {
							if (isset($result['response']['results'][0]) && !empty($result['response']['results']) && is_array($result['response']['results'])) {
								$find = PlatformCustomer::where([['platform_id', '=', $this->platformId], ['user_integration_id', '=', $userIntegrationId], ['api_customer_id', '=', $result['response']['results'][0][0]]])->first();
								if ($find) {
									$customer_name = $result['response']['results'][0][1] . " " . $result['response']['results'][0][2];
									$find->customer_name = $customer_name;
									$find->first_name = $result['response']['results'][0][1];
									$find->last_name = $result['response']['results'][0][2];
									$find->email = $result['response']['results'][0][3];
									$find->api_updated_at = date('Y-m-d H:i:s');
									$find->save();
									$contactID = $result['response']['results'][0][0];
								} else {
									$customer_name = $result['response']['results'][0][1] . " " . $result['response']['results'][0][2];
									$fields = array('user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'api_customer_id' => $result['response']['results'][0][0], 'first_name' => $result['response']['results'][0][1], 'last_name' => $result['response']['results'][0][2], 'email' => $result['response']['results'][0][3], 'sync_status' => 'Ready');
									$this->mobj->makeInsert('platform_customer', $fields);
									$contactID = $result['response']['results'][0][0];
								}
								return $contactID;
							}
						}
						return false;
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . " -> BrightpearlSearchController -> SearchCustomerByEmail -> " . $e->getLine() . " -> " . $e->getMessage());
			return false;
		}
	}

	/** Search Customer By Email from the Database
	 * Special case like for bigcommerce - we have update the customer if found to Brightpearl with order
	 * This function is extended for ConnectionHelper::class , 'findCustomerByEmail'
	 * Add Email3 as a tertiary email in bright pearl
	 */
	public function findCustomerByEmailWithUpdateSupport($email, $user_id, $user_integration_id, $sourcePlatformId = null, $sourcePlatformName = null, $customer_price_list_id = null, $pricelist_object_id = null, $account = null, $pricelist_grp_object_id = null, $apiCustomerId = null, $isAllowFromSourceId = false)
	{
		$customer = PlatformCustomer::where(['platform_id' => ($isAllowFromSourceId) ? $sourcePlatformId : $this->platformId, 'user_integration_id' => $user_integration_id, 'is_deleted' => 0])
			->where(function ($query) use ($apiCustomerId, $email) {
				if ($apiCustomerId) {
					$query->where('api_customer_id', $apiCustomerId);
				} else {
					$query->where('email', $email);
				}
			})
			->select('email', 'id', 'api_customer_id', 'api_customer_group_id', 'email3')
			->first();

		if ($customer) {
			$updatedArrData = [];
			// -- Customer Update (For Now used only for Bigcommerce) --
			if (isset(Config::get('apisettings.AllowCustomerUpdateInBrightPearl')[$sourcePlatformName]) && $sourcePlatformId && $sourcePlatformName && $customer_price_list_id && $pricelist_object_id && $account) {
				$mappedPriceListId = null;
				// Currently checking the customer group as pricelist to match with pricelist object data, later can be used for pricelist to pricelist mapping too
				$sc_pricelist_name = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $sourcePlatformId, 'platform_object_id' => $pricelist_grp_object_id, 'api_id' => $customer_price_list_id])->select('name')->first();
				if ($sc_pricelist_name) {
					$dc_pricelist_api_id = PlatformObjectData::where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $pricelist_object_id, 'name' => $sc_pricelist_name->name])->select('api_id')->first();
					if ($dc_pricelist_api_id) {
						$mappedPriceListId = $dc_pricelist_api_id->api_id;
					}
				}

				if ($mappedPriceListId && ($mappedPriceListId != $customer->api_customer_group_id)) {
					$updatedArrData[] = ['op' => 'replace', 'path' => '/financialDetails/priceListId', 'value' => $mappedPriceListId];
				}
			}

			if (isset(Config::get('apisettings.AllowCustomerUpdateInBrightPearl')[$sourcePlatformName])) {
				$updatedArrData[] = ['op' => 'replace', 'path' => '/communication/emails/TER/email', 'value' => ($customer->email3) ? trim($customer->email3) : ''];
			}

			if (!empty($updatedArrData) && $account) {
				// Add data in bright pearl (Email3/price list data)
				$response = $this->bp->updateCustomer($account, null, $updatedArrData, $customer->api_customer_id);
				if ($response = json_decode($response->getBody(), true)) {
					if (isset($response['financialDetails']['priceListId'])) {
						$customer->update(['api_customer_group_id' => $response['financialDetails']['priceListId']]);
					}
				}
			}
			return $customer;
		} else {
			return false;
		}
	}

	/**
	 * Function : searchOptionsAndValuesForProduct
	 * Desc : Search for the option name and values in the platform product option table
	 * @param [type] $account
	 * @param [type] $optionName
	 * @param [type] $optionValue
	 * @param [type] $productId
	 * @return void
	 */
	/* Get Warehouse and Update */
	public function GetOrderWarehouse($order, $user_id, $user_integration_id, $warehouse_object_id = null)
	{
		$return = NULL;
		if (isset($order['warehouseId']) && !is_null($order['warehouseId'])) {
			if (!isset($warehouse_object_id)) {
				$warehouse_object_id = $this->helper->getObjectId('warehouse');
			}
			$ord_warehouse = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $warehouse_object_id, 'api_id' => $order['warehouseId']], ['id']);
			if ($ord_warehouse) {
				$order_warehouse_id = $ord_warehouse->id;
			} else {
				$order_warehouse_id = $this->mobj->makeInsertGetId('platform_object_data', ['user_id' => $user_id, 'platform_id' => $this->platformId, 'api_id' => $order['warehouseId'], 'user_integration_id' => $user_integration_id, 'platform_object_id' => $warehouse_object_id]);
			}
			$return  = $order_warehouse_id;
		}
		return $return;
	}

	public function searchOptionsAndValuesForProduct($account, $optionName, $optionValue, $productId)
	{
		$optionName = mb_convert_encoding(substr($optionName, 0, 32), "UTF-8", "UTF-8");

		$retData = [];
		// -- Search for the option name and values in the platform product option table
		$productOptionValue = PlatformProductOption::where(['platform_product_id' => $productId, 'option_name' => $optionName, 'option_value' => $optionValue, 'status' => 1])->select('api_option_id', 'api_option_value_id')->first();
		if ($productOptionValue) {
			$retData = ['optionId' => $productOptionValue->api_option_id, 'optionValueId' => $productOptionValue->api_option_value_id];
		} else {
			$optionId = null;
			// Search With option name
			$option = PlatformProductOption::where(['platform_product_id' => $productId, 'option_name' => $optionName, 'status' => 1])->select('api_option_id')->first();
			if ($option) {
				$optionId = $option->api_option_id;
			} else {
				if ($account) {
					$optionsearch = $this->bp->searchOptionName($account, urlencode($optionName));
					if ($optionsearch = json_decode($optionsearch->getBody(), true)) {
						if (isset($optionsearch['response']['results'][0][0])) {
							$optionId = $optionsearch['response']['results'][0][0];
						} else {
							$createOptData = ['name' => $optionName];
							$optioncreate = $this->bp->createOptionName($account, $createOptData);
							if ($optioncreate = json_decode($optioncreate->getBody(), true)) {
								if (isset($optioncreate['response']) && is_int($optioncreate['response'])) {
									$optionId = $optioncreate['response'];
								}
							}
						}
					}

					if ($optionId) {
						$productTypeResponse = $this->bp->GetProductType($account);
						if ($productTypeResult = json_decode($productTypeResponse->getBody(), true)) {
							if (isset($productTypeResult['response'][0]['id'])) {
								foreach ($productTypeResult['response'] as $productType) {
									$this->bp->SetProductTypeOptionAssociation($account, $productType['id'], $optionId);
								}
							}
						}
					}
				}
			}

			if ($account && $optionId) {
				// search for value in BP Api
				$optionValueSearch = $this->bp->searchOptionValueName($account, urlencode($optionValue), $optionId);
				if ($optionValueSearch = json_decode($optionValueSearch->getBody(), true)) {
					$optionValueId = NULL;
					if (isset($optionValueSearch['response']['results'][0]['2'])) {
						foreach ($optionValueSearch['response']['results'] as $result1) {
							if (strtoupper(trim($result1[3])) == strtoupper(trim($optionValue))) {
								$retData = ['optionId' => $optionId, 'optionValueId' => $result1['2']];
								$optionValueId = $result1['2'];
							}
						}
					}

					if ($optionValueId == NULL) {
						$createOptValData = ['optionValueName' => $optionValue];
						$optionvalcreate = $this->bp->createOptionValueName($account, $optionId, $createOptValData);
						if ($optionvalcreate = json_decode($optionvalcreate->getBody(), true)) {
							if (isset($optionvalcreate['response']) && is_int($optionvalcreate['response'])) {
								$retData = ['optionId' => $optionId, 'optionValueId' => $optionvalcreate['response']];
							}
						}
					}
				}
			}
		}
		return $retData;
	}
	public function SearchOrderByCustomerReference($reference, $account = NULL, $userIntegrationId = NULL)
	{
		$api_error = $custom_error = $exception_error = false;
		$error = $order_id = null;
		try {
			if (!$account) {
				$account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
			}
			if (isset($account->platform_id) && $account->platform_id == $this->platformId) {
				//for only BP integration
				$response = $this->bp->SearchOrder($account, "?customerRef={$reference}&columns=orderId&sort=orderId.ASC", 'json');
				if ($result = json_decode($response->getBody(), true)) {
					if (isset($result['response']['results'][0])) {
						$order_id = $result['response']['results'][0][0];
					} else if (isset($result['errors'])) {
						$error =  $this->bp->handleResponseError($result);
						$api_error = true;
					} else if (isset($result['response']['results']) && empty($result['response']['results'])) {
						$custom_error = true;
						$error =  "Order has been not found in Brightpearl";
					} else {
						$error = "Unexpected, Brightpearl internal error";
						$exception_error = true;
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . " -> BrightpearlSearchController -> SearchOrderByCustomerReference -> " . $e->getLine() . " -> " . $e->getMessage());
			$error = "Unexpected, Brightpearl internal error";
			$exception_error = true;
		}
		return ['order_id' => $order_id, 'api_error' => $api_error, 'custom_error' => $custom_error, 'exception_error' => $exception_error, 'error' => $error];
	}
	public function getProductPriceByProductIDs($AccountDetail, $ProducID, $priceListID)
	{
		$url = "product-price/{$ProducID}/price-list/{$priceListID}";
		$response = $this->bp->GetProductPriceList($AccountDetail, $url);
		if (200 == $response->getStatusCode()) {
		  $result = json_decode($response->getBody(), true);    
		  if (isset($result['response']) && is_array($result['response'])) {
			$prices = $result['response'];     
			if (count($prices)) {
			  foreach ($prices as $key => $price) {
				$productListIds = $price['priceLists'];
				if (count($productListIds)) {
				  foreach ($productListIds as $listkey => $priceList) {
					$Price = $priceList['quantityPrice'];              
					$Price = array_values($Price);
					return array_shift($Price);
				  }
				}
			  }
			}
		  }
		}
		return "0";
	}
}
