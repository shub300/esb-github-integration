<?php

namespace App\Http\Controllers\SDMO;

use App\Http\Controllers\Controller;
use Auth;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\WorkflowSnippet;
use App\Helper\Logger;
use App\Models\PlatformAccount;
use App\Models\PlatformCustomer;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformProduct;
use App\Models\PlatformUrl;
use App\Http\Controllers\SDMO\Api\SDMOApi;
use Lang;

class SDMOController extends Controller
{
	public static $myPlatform = 'sdmo';


	/**
	 * Create a new controller instance.
	 *
	 * @return void
	 */
	public function __construct()
	{
		$this->MainModel = new MainModel();
		$this->SDMO = new SDMOApi();
		$this->ConnectionHelper = new ConnectionHelper();
		$this->FieldMappingHelper = new FieldMappingHelper();
		$this->Logger = new Logger();
		$this->WorkflowSnippet = new WorkflowSnippet();
		$this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
	}

	public function InitiateSDMOAuth(Request $request)
	{
		$platform = 'sdmo';
		return view('pages.apiauth.auth_sdmo', compact('platform'));
	}

	public function ConnectSDMOAuth(Request $request)
	{
		date_default_timezone_set('UTC');

		$request->validate(['account_name' => 'required', 'client_id' => 'required', 'client_secret' => 'required', 'tenant_id' => 'required', 'region' => 'required']);

		$account_name = trim($request->account_name);
		$client_id = trim($request->client_id);
		$client_secret = trim($request->client_secret);
		$tenant_id = trim($request->tenant_id);
		$region = trim($request->region);

		$data = [];

		if ($this->MainModel->checkHtmlTags($request->all())) {
			$data['status_code'] = 0;
			$data['status_text'] = Lang::get('tags.validate');
			return json_encode($data);
		}
		try {
			$flag = true;
			// to check whether given account is already in use or not.
			$checkExistingAc = PlatformAccount::select('id')->where('user_id', Auth::user()->id)->where('platform_id', $this->platformId)->where('account_name', $account_name)->first();
			if ($checkExistingAc) {
				$flag = false;
				$data['status_code'] = 0;
				$data['status_text'] = 'This account name already exist, Try with another account name.';
			} else {
				$response = $this->SDMO->Authentication($region, $client_id, $client_secret, $tenant_id);
				$result = json_decode($response, true);
				if (isset($result['accessToken'])) {
					PlatformAccount::insert(['user_id' => Auth::user()->id, 'platform_id' => $this->platformId, 'account_name' => $account_name, 'app_id' => $this->MainModel->encryptString($client_id), 'app_secret' => $this->MainModel->encryptString($client_secret), 'region' => $region, 'marketplace_id' => $tenant_id, 'refresh_token' => $this->MainModel->encryptString($result['refreshToken']), 'api_domain' => $result['urls']['graphQL'], 'expires_in' => 900, 'token_refresh_time' => time(), 'allow_refresh' => 1]);
				} else {
					$flag = false;
					$data['status_code'] = 0;
					$data['status_text'] = 'Invalid ' . self::$myPlatform . ' credentials!';
				}
			}

			if ($flag) {
				$data['status_code'] = 1;
				$data['status_text'] = 'Account connected successfully.';
			}
			return json_encode($data);
		} catch (\Exception $e) {
			$data['status_code'] = 0;
			$data['status_text'] = $e->getMessage();
			return json_encode($data);
		}
	}

	/*Refresh token*/
	function RefreshToken($id)
	{
		$return_response = false;
		date_default_timezone_set('UTC');
		try {
			$platform_account = PlatformAccount::select('id', 'app_id', 'app_secret', 'region', 'marketplace_id', 'refresh_token')->where('id', $id)->first();
			if ($platform_account) {
				$response = $this->SDMO->RefreshToken($platform_account->region, $this->MainModel->decryptString($platform_account->app_id), $this->MainModel->decryptString($platform_account->app_secret), $platform_account->marketplace_id, $this->MainModel->decryptString($platform_account->refresh_token));
				$result = json_decode($response, true);
				if (isset($result['accessToken'])) {
					PlatformAccount::where('id', $id)
						->update(['access_token' => $this->MainModel->encryptString($result['accessToken']), 'refresh_token' => $this->MainModel->encryptString($result['refreshToken']), 'token_refresh_time' => time()]);
					$return_response = true;
				} else {
					$return_response = 'API Error';
				}
			}
		} catch (\Exception $e) {
			\Log::error($id . ' - SDMOController - RefreshToken - ' . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	/** GET CATEGORY LIST **/
	private function GetCategories($user_id, $user_integration_id, $is_initial_sync)
	{
		$return_data = true;
		try {
			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$category_object_id = $this->ConnectionHelper->getObjectId('category');
			if ($platform_account && $category_object_id) {
				$categoryData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $category_object_id];

				if ($is_initial_sync) {
					//Update the object data status to 0
					PlatformObjectData::where($categoryData)
						->update(['status' => 0]);
				}

				$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\")';
				do {
					$allow_next_cal = false;

					$request_data_json = '{"query":"{ xtremMasterData { itemCategory { ' . $query_parameter . ' { totalCount pageInfo { endCursor hasNextPage } edges { node { _id id name type _createStamp _updateStamp } } } } } }","variables":{}}';

					$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);

					$result = json_decode($response, true);
					if (isset($result['data']['xtremMasterData']['itemCategory']['query']['pageInfo'])) {
						$pageInfo = $result['data']['xtremMasterData']['itemCategory']['query']['pageInfo'];

						if (isset($result['data']['xtremMasterData']['itemCategory']['query']['edges'][0]['node']['_id'])) {
							$categoryInsertData = [];
							foreach ($result['data']['xtremMasterData']['itemCategory']['query']['edges'] as $category) {
								$categoryData['api_id'] = $category['node']['_id'];
								$platform_object_data = PlatformObjectData::where($categoryData)->first();
								if ($platform_object_data) {
									$platform_object_data->name = $category['node']['name'];
									$platform_object_data->api_code = $category['node']['id'];
									$platform_object_data->description = $category['node']['type'];
									$platform_object_data->status = 1;
									$platform_object_data->save();
								} else {
									$categoryData['name'] = $category['node']['name'];
									$categoryData['api_code'] = $category['node']['id'];
									$categoryData['description'] = $category['node']['type'];
									$categoryInsertData[] = $categoryData;
								}
							}

							if (count($categoryInsertData)) {
								PlatformObjectData::insert($categoryInsertData);
							}
						}

						if ($pageInfo['hasNextPage']) {
							$allow_next_cal = true;
							$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\", after: \"' . str_replace('"', "'", $pageInfo['endCursor']) . '\")';
						} else {
							$allow_next_cal = false;
						}
					} elseif (is_array($result)) {
						$return_data = $this->handleErrorResponse($result);
					} else {
						$return_data = $response;
					}
				} while ($allow_next_cal);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> SDMOController -> GetCategories -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}

		return $return_data;
	}

	/** GET SALES SITE LIST **/
	private function GetSalesSites($user_id, $user_integration_id, $is_initial_sync)
	{
		$return_data = true;
		try {
			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$sales_site_object_id = $this->ConnectionHelper->getObjectId('sales_site');
			if ($platform_account && $sales_site_object_id) {
				$siteData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $sales_site_object_id];

				if ($is_initial_sync) {
					//Update the object data status to 0
					PlatformObjectData::where($siteData)
						->update(['status' => 0]);
				}

				$query_parameter = 'query(filter:\"{isSales:true}\", first: 500, orderBy: \"{_createStamp:+1}\")';
				do {
					$allow_next_cal = false;

					$request_data_json = '{"query":"{ xtremSystem { site { ' . $query_parameter . ' { totalCount pageInfo { endCursor hasNextPage } edges { node { _id id name description isSales isActive _createStamp _updateStamp } } } } } }","variables":{}}';

					$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);

					$result = json_decode($response, true);
					if (isset($result['data']['xtremSystem']['site']['query']['pageInfo'])) {
						$pageInfo = $result['data']['xtremSystem']['site']['query']['pageInfo'];

						if (isset($result['data']['xtremSystem']['site']['query']['edges'][0]['node']['_id'])) {
							$siteInsertData = [];
							foreach ($result['data']['xtremSystem']['site']['query']['edges'] as $site) {
								$siteData['api_id'] = $site['node']['_id'];
								$platform_object_data = PlatformObjectData::where($siteData)->first();
								if ($platform_object_data) {
									$platform_object_data->name = $site['node']['name'];
									$platform_object_data->api_code = $site['node']['id'];
									$platform_object_data->description = $site['node']['description'];
									$platform_object_data->status = $site['node']['isActive'];
									$platform_object_data->save();
								} else {
									$siteData['name'] = $site['node']['name'];
									$siteData['api_code'] = $site['node']['id'];
									$siteData['description'] = $site['node']['description'];
									$siteData['status'] = $site['node']['isActive'];
									$siteInsertData[] = $siteData;
								}
							}

							if (count($siteInsertData)) {
								PlatformObjectData::insert($siteInsertData);
							}
						}

						if ($pageInfo['hasNextPage']) {
							$allow_next_cal = true;
							$query_parameter = 'query(filter:\"{isSales:true}\", first: 500, orderBy: \"{_createStamp:+1}\", after: \"' . str_replace('"', "'", $pageInfo['endCursor']) . '\")';
						} else {
							$allow_next_cal = false;
						}
					} elseif (is_array($result)) {
						$return_data = $this->handleErrorResponse($result);
					} else {
						$return_data = $response;
					}
				} while ($allow_next_cal);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> SDMOController -> GetSalesSites -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}

		return $return_data;
	}

	/** GET COUNTRY LIST **/
	private function GetCountries($user_id, $user_integration_id, $is_initial_sync)
	{
		$return_data = true;
		try {
			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$country_object_id = $this->ConnectionHelper->getObjectId('country');
			if ($platform_account && $country_object_id) {
				$countryData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $country_object_id];

				if ($is_initial_sync) {
					//Update the object data status to 0
					PlatformObjectData::where($countryData)
						->update(['status' => 0]);
				}

				$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\")';
				do {
					$allow_next_cal = false;

					$request_data_json = '{"query":"{ xtremStructure { country { ' . $query_parameter . ' { totalCount pageInfo { endCursor hasNextPage } edges { node { _id id name _createStamp _updateStamp } } } } } }","variables":{}}';

					$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);

					$result = json_decode($response, true);
					if (isset($result['data']['xtremStructure']['country']['query']['pageInfo'])) {
						$pageInfo = $result['data']['xtremStructure']['country']['query']['pageInfo'];

						if (isset($result['data']['xtremStructure']['country']['query']['edges'][0]['node']['_id'])) {
							$countryInsertData = [];
							foreach ($result['data']['xtremStructure']['country']['query']['edges'] as $country) {
								$countryData['api_id'] = $country['node']['_id'];
								$platform_object_data = PlatformObjectData::where($countryData)->first();
								if ($platform_object_data) {
									$platform_object_data->name = $country['node']['name'];
									$platform_object_data->api_code = $country['node']['id'];
									$platform_object_data->status = 1;
									$platform_object_data->save();
								} else {
									$countryData['name'] = $country['node']['name'];
									$countryData['api_code'] = $country['node']['id'];
									$countryInsertData[] = $countryData;
								}
							}

							if (count($countryInsertData)) {
								PlatformObjectData::insert($countryInsertData);
							}
						}

						if ($pageInfo['hasNextPage']) {
							$allow_next_cal = true;
							$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\", after: \"' . str_replace('"', "'", $pageInfo['endCursor']) . '\")';
						} else {
							$allow_next_cal = false;
						}
					} elseif (is_array($result)) {
						$return_data = $this->handleErrorResponse($result);
					} else {
						$return_data = $response;
					}
				} while ($allow_next_cal);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> SDMOController -> GetCountries -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}

		return $return_data;
	}

	/** GET CURRENCY LIST **/
	private function GetCurrencies($user_id, $user_integration_id, $is_initial_sync)
	{
		$return_data = true;
		try {
			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$currency_object_id = $this->ConnectionHelper->getObjectId('currency');
			if ($platform_account && $currency_object_id) {
				$currencyData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $currency_object_id];

				if ($is_initial_sync) {
					//Update the object data status to 0
					PlatformObjectData::where($currencyData)
						->update(['status' => 0]);
				}

				$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\")';
				do {
					$allow_next_cal = false;

					$request_data_json = '{"query":"{ xtremMasterData { currency { ' . $query_parameter . ' { totalCount pageInfo { endCursor hasNextPage } edges { node { _id id name symbol isActive _createStamp _updateStamp } } } } } }","variables":{}}';

					$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);

					$result = json_decode($response, true);
					if (isset($result['data']['xtremMasterData']['currency']['query']['pageInfo'])) {
						$pageInfo = $result['data']['xtremMasterData']['currency']['query']['pageInfo'];

						if (isset($result['data']['xtremMasterData']['currency']['query']['edges'][0]['node']['_id'])) {
							$currencyInsertData = [];
							foreach ($result['data']['xtremMasterData']['currency']['query']['edges'] as $currency) {
								$currencyData['api_id'] = $currency['node']['_id'];
								$platform_object_data = PlatformObjectData::where($currencyData)->first();
								if ($platform_object_data) {
									$platform_object_data->name = $currency['node']['name'];
									$platform_object_data->api_code = $currency['node']['id'];
									$platform_object_data->description = $currency['node']['symbol'];
									$platform_object_data->status = $currency['node']['isActive'];
									$platform_object_data->save();
								} else {
									$currencyData['name'] = $currency['node']['name'];
									$currencyData['api_code'] = $currency['node']['id'];
									$currencyData['description'] = $currency['node']['symbol'];
									$currencyData['status'] = $currency['node']['isActive'];
									$currencyInsertData[] = $currencyData;
								}
							}

							if (count($currencyInsertData)) {
								PlatformObjectData::insert($currencyInsertData);
							}
						}

						if ($pageInfo['hasNextPage']) {
							$allow_next_cal = true;
							$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\", after: \"' . str_replace('"', "'", $pageInfo['endCursor']) . '\")';
						} else {
							$allow_next_cal = false;
						}
					} elseif (is_array($result)) {
						$return_data = $this->handleErrorResponse($result);
					} else {
						$return_data = $response;
					}
				} while ($allow_next_cal);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> SDMOController -> GetCurrencies -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}

		return $return_data;
	}

	/** GET DELIVERY MODE LIST **/
	private function GetDeliveryModes($user_id, $user_integration_id, $is_initial_sync)
	{
		$return_data = true;
		try {
			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$delivery_mode_object_id = $this->ConnectionHelper->getObjectId('delivery_mode');
			if ($platform_account && $delivery_mode_object_id) {
				$deliveryModeData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $delivery_mode_object_id];

				if ($is_initial_sync) {
					//Update the object data status to 0
					PlatformObjectData::where($deliveryModeData)
						->update(['status' => 0]);
				}

				$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\")';
				do {
					$allow_next_cal = false;

					$request_data_json = '{"query":"{ xtremMasterData { deliveryMode { ' . $query_parameter . ' { totalCount pageInfo { endCursor hasNextPage } edges { node { _id id name description isActive _createStamp _updateStamp } } } } } }","variables":{}}';

					$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);

					$result = json_decode($response, true);
					if (isset($result['data']['xtremMasterData']['deliveryMode']['query']['pageInfo'])) {
						$pageInfo = $result['data']['xtremMasterData']['deliveryMode']['query']['pageInfo'];

						if (isset($result['data']['xtremMasterData']['deliveryMode']['query']['edges'][0]['node']['_id'])) {
							$deliveryModeInsertData = [];
							foreach ($result['data']['xtremMasterData']['deliveryMode']['query']['edges'] as $deliveryMode) {
								$deliveryModeData['api_id'] = $deliveryMode['node']['_id'];
								$platform_object_data = PlatformObjectData::where($deliveryModeData)->first();
								if ($platform_object_data) {
									$platform_object_data->name = $deliveryMode['node']['name'];
									$platform_object_data->api_code = $deliveryMode['node']['id'];
									$platform_object_data->description = $deliveryMode['node']['description'];
									$platform_object_data->status = $deliveryMode['node']['isActive'];
									$platform_object_data->save();
								} else {
									$deliveryModeData['name'] = $deliveryMode['node']['name'];
									$deliveryModeData['api_code'] = $deliveryMode['node']['id'];
									$deliveryModeData['description'] = $deliveryMode['node']['description'];
									$deliveryModeData['status'] = $deliveryMode['node']['isActive'];
									$deliveryModeInsertData[] = $deliveryModeData;
								}
							}

							if (count($deliveryModeInsertData)) {
								PlatformObjectData::insert($deliveryModeInsertData);
							}
						}

						if ($pageInfo['hasNextPage']) {
							$allow_next_cal = true;
							$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\", after: \"' . str_replace('"', "'", $pageInfo['endCursor']) . '\")';
						} else {
							$allow_next_cal = false;
						}
					} elseif (is_array($result)) {
						$return_data = $this->handleErrorResponse($result);
					} else {
						$return_data = $response;
					}
				} while ($allow_next_cal);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> SDMOController -> GetDeliveryModes -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}

		return $return_data;
	}

	/** GET INCOTERMS (International Commercial Terms) LIST **/
	private function GetIncoterms($user_id, $user_integration_id, $is_initial_sync)
	{
		$return_data = true;
		try {
			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$incoterm_object_id = $this->ConnectionHelper->getObjectId('incoterm');
			if ($platform_account && $incoterm_object_id) {
				$incotermData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $incoterm_object_id];

				if ($is_initial_sync) {
					//Update the object data status to 0
					PlatformObjectData::where($incotermData)
						->update(['status' => 0]);
				}

				$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\")';
				do {
					$allow_next_cal = false;

					$request_data_json = '{"query":"{ xtremMasterData { incoterm { ' . $query_parameter . ' { totalCount pageInfo { endCursor hasNextPage } edges { node { _id id name description isActive _createStamp _updateStamp } } } } } }","variables":{}}';

					$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);

					$result = json_decode($response, true);
					if (isset($result['data']['xtremMasterData']['incoterm']['query']['pageInfo'])) {
						$pageInfo = $result['data']['xtremMasterData']['incoterm']['query']['pageInfo'];

						if (isset($result['data']['xtremMasterData']['incoterm']['query']['edges'][0]['node']['_id'])) {
							$incotermInsertData = [];
							foreach ($result['data']['xtremMasterData']['incoterm']['query']['edges'] as $incoterm) {
								$incotermData['api_id'] = $incoterm['node']['_id'];
								$platform_object_data = PlatformObjectData::where($incotermData)->first();
								if ($platform_object_data) {
									$platform_object_data->name = $incoterm['node']['name'];
									$platform_object_data->api_code = $incoterm['node']['id'];
									$platform_object_data->description = $incoterm['node']['description'];
									$platform_object_data->status = $incoterm['node']['isActive'];
									$platform_object_data->save();
								} else {
									$incotermData['name'] = $incoterm['node']['name'];
									$incotermData['api_code'] = $incoterm['node']['id'];
									$incotermData['description'] = $incoterm['node']['description'];
									$incotermData['status'] = $incoterm['node']['isActive'];
									$incotermInsertData[] = $incotermData;
								}
							}

							if (count($incotermInsertData)) {
								PlatformObjectData::insert($incotermInsertData);
							}
						}

						if ($pageInfo['hasNextPage']) {
							$allow_next_cal = true;
							$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\", after: \"' . str_replace('"', "'", $pageInfo['endCursor']) . '\")';
						} else {
							$allow_next_cal = false;
						}
					} elseif (is_array($result)) {
						$return_data = $this->handleErrorResponse($result);
					} else {
						$return_data = $response;
					}
				} while ($allow_next_cal);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> SDMOController -> GetIncoterms -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}

		return $return_data;
	}

	/** GET PAYMENT TERM LIST **/
	private function GetPaymentTerms($user_id, $user_integration_id, $is_initial_sync)
	{
		$return_data = true;
		try {
			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$payment_term_object_id = $this->ConnectionHelper->getObjectId('payment_term');
			if ($platform_account && $payment_term_object_id) {
				$paymentTermData = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $payment_term_object_id];

				if ($is_initial_sync) {
					//Update the object data status to 0
					PlatformObjectData::where($paymentTermData)
						->update(['status' => 0]);
				}

				$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\")';
				do {
					$allow_next_cal = false;

					$request_data_json = '{"query":"{ xtremMasterData { paymentTerm { ' . $query_parameter . ' { totalCount pageInfo { endCursor hasNextPage } edges { node { _id id name description isActive _createStamp _updateStamp } } } } } }","variables":{}}';

					$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);

					$result = json_decode($response, true);
					if (isset($result['data']['xtremMasterData']['paymentTerm']['query']['pageInfo'])) {
						$pageInfo = $result['data']['xtremMasterData']['paymentTerm']['query']['pageInfo'];

						if (isset($result['data']['xtremMasterData']['paymentTerm']['query']['edges'][0]['node']['_id'])) {
							$incotermInsertData = [];
							foreach ($result['data']['xtremMasterData']['paymentTerm']['query']['edges'] as $paymentTerm) {
								$paymentTermData['api_id'] = $paymentTerm['node']['_id'];
								$platform_object_data = PlatformObjectData::where($paymentTermData)->first();
								if ($platform_object_data) {
									$platform_object_data->name = $paymentTerm['node']['name'];
									$platform_object_data->api_code = $paymentTerm['node']['id'];
									$platform_object_data->description = $paymentTerm['node']['description'];
									$platform_object_data->status = $paymentTerm['node']['isActive'];
									$platform_object_data->save();
								} else {
									$paymentTermData['name'] = $paymentTerm['node']['name'];
									$paymentTermData['api_code'] = $paymentTerm['node']['id'];
									$paymentTermData['description'] = $paymentTerm['node']['description'];
									$paymentTermData['status'] = $paymentTerm['node']['isActive'];
									$incotermInsertData[] = $paymentTermData;
								}
							}

							if (count($incotermInsertData)) {
								PlatformObjectData::insert($incotermInsertData);
							}
						}

						if ($pageInfo['hasNextPage']) {
							$allow_next_cal = true;
							$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\", after: \"' . str_replace('"', "'", $pageInfo['endCursor']) . '\")';
						} else {
							$allow_next_cal = false;
						}
					} elseif (is_array($result)) {
						$return_data = $this->handleErrorResponse($result);
					} else {
						$return_data = $response;
					}
				} while ($allow_next_cal);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> SDMOController -> GetPaymentTerms -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}

		return $return_data;
	}

	/** GET PRODUCT LIST **/
	public function GetProducts($user_id, $user_integration_id, $is_initial_sync)
	{
		$return_data = true;
		try {
			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token', 'api_domain']);
			if ($platform_account) {
				$site = '';
				$default_sales_site = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "default_sales_site", ['api_id']);
				if ($default_sales_site) {
					$site = $default_sales_site->api_id;
				}

				if ($is_initial_sync) {
					$query_parameter = 'query(filter: \"{site:{_id:' . $site . '}}\", first: 500, orderBy: \"{_createStamp:+1}\")';
					$platform_url = PlatformUrl::select('id', 'url')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'product_limit', 'status' => 1])->first();
					if ($platform_url) {
						$platform_url_id = $platform_url->id;
						if ($platform_url->url) {
							$query_parameter = $platform_url->url;
						}
					} else {
						$platform_url = PlatformUrl::create(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'product_limit', 'url' => '', 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
						$platform_url_id = $platform_url->id;
					}
				} else {
					$query_parameter = 'query(filter: \"{site:{_id:' . $site . '}}\", first: 500, orderBy: \"{_updateStamp:+1}\")';
					$last_updated_product = PlatformProduct::select('api_updated_at')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->orderBy('api_updated_at', 'desc')->first();
					if ($last_updated_product) {
						$query_parameter = 'query(first: 500, orderBy: \"{_updateStamp:+1}\", filter: \"{site:{_id:' . $site . '}, _updateStamp:{_gt:\'' . $last_updated_product->api_updated_at . '\'}}\")';
					}
				}

				$callAPI = 1;
				do {
					$allow_next_cal = false;

					$request_data_json = '{"query":"{ xtremMasterData{ item{ ' . $query_parameter . '{ totalCount pageInfo{ endCursor hasNextPage hasPreviousPage } edges{ node{ _id id description name type eanNumber weight volume commodityCode itemTaxGroup{ id name } image{ value } prices{ query{ edges{ node{ _id currency{ id } type price toQuantity } } } } category{ _id id name } suppliers{ query{ edges{ node{ _id id supplierItemName } } } } _updateStamp _createStamp }} }} } }","variables":{}}';

					$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);
					$result = json_decode($response, true);
					if (isset($result['data']['xtremMasterData']['item']['query']['pageInfo'])) {
						$pageInfo = $result['data']['xtremMasterData']['item']['query']['pageInfo'];
						if (isset($result['data']['xtremMasterData']['item']['query']['edges'][0]['node']['_id'])) {
							$allow_next_cal = true;
							foreach ($result['data']['xtremMasterData']['item']['query']['edges'] as $node) {
								$product = $node['node'];

								//product details
								$productData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_product_id' => $product['_id'], 'product_name' => $product['name'], 'sku' => $product['id'], 'barcode' => $product['eanNumber'], 'description' => $product['description'], 'weight' => $product['weight'], 'category_id' => @$product['category']['_id'], 'api_updated_at' => $product['_updateStamp']];

								$platform_product = PlatformProduct::select('id', 'linked_id', 'api_updated_at')->where(['api_product_id' => $product['_id'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->first();
								if ($platform_product) {
									if ($platform_product->api_updated_at != $product['_updateStamp'] && $platform_product->linked_id == 0) {
										$productData['product_sync_status'] = 'Ready';
									}

									$platform_product->update($productData);
								} else {
									$productData['product_sync_status'] = 'Ready';
									PlatformProduct::create($productData);
								}
							}
						}

						if ($is_initial_sync) {
							if ($pageInfo['hasNextPage']) {
								$allow_next_cal = true;

								$query_parameter = 'query(filter: \"{site:{_id:' . $site . '}}\", first: 500, orderBy: \"{_createStamp:+1}\", after: \"' . str_replace('"', "'", $pageInfo['endCursor']) . '\")';

								PlatformUrl::where('id', $platform_url_id)
									->update(['url' => $query_parameter, 'updated_at' => date('Y-m-d H:i:s')]);

								$return_data = "Next cursor " . $query_parameter . " data";
							} else {
								$allow_next_cal = false;

								PlatformUrl::where('id', $platform_url_id)
									->update(['status' => 0, 'updated_at' => date('Y-m-d H:i:s')]);

								$return_data = true;
							}

							//max 2 time run this function in single call
							if (($callAPI % 2) == 0) {
								$allow_next_cal = false;
							}

							$callAPI++;
						} else {
							$allow_next_cal = false;
						}
					} elseif (is_array($result)) {
						$allow_next_cal = false;
						$return_data = $this->handleErrorResponse($result);
					} else {
						$allow_next_cal = false;
						$return_data = $response;
					}
				} while ($allow_next_cal);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' -> SDMOController -> GetProducts -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	/** GET CUSTOMER LIST **/
	public function GetCustomers($user_id, $user_integration_id, $is_initial_sync)
	{
		$return_data = true;
		try {
			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token', 'api_domain']);
			if ($platform_account) {
				if ($is_initial_sync) {
					$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\", filter: \"{isActive:true}\")';
					$platform_url = PlatformUrl::select('id', 'url')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'customer_limit', 'status' => 1])->first();
					if ($platform_url) {
						$platform_url_id = $platform_url->id;
						if ($platform_url->url) {
							$query_parameter = $platform_url->url;
						}
					} else {
						$platform_url = PlatformUrl::create(['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'customer_limit', 'url' => '', 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
						$platform_url_id = $platform_url->id;
					}
				} else {
					$query_parameter = 'query(first: 500, orderBy: \"{_updateStamp:+1}\", filter: \"{isActive:true}\")';
					$last_updated_product = PlatformCustomer::select('api_updated_at')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->orderBy('api_updated_at', 'desc')->first();
					if ($last_updated_product) {
						$query_parameter = 'query(first: 500, orderBy: \"{_updateStamp:+1}\", filter: \"{isActive:true, _updateStamp:{_gt:\'' . $last_updated_product->api_updated_at . '\'}}\")';
					}
				}

				$callAPI = 1;
				do {
					$allow_next_cal = false;

					$request_data_json = '{"query":"{xtremMasterData { customer { ' . $query_parameter . ' {totalCount pageInfo { endCursor hasNextPage startCursor } edges { node { _id id isActive primaryContact { firstName lastName locationPhoneNumber email intacctPrintAs } _createStamp _updateStamp }}}}}}","variables":{}}';

					$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);
					$result = json_decode($response, true);
					if (isset($result['data']['xtremMasterData']['customer']['query']['pageInfo'])) {
						$pageInfo = $result['data']['xtremMasterData']['customer']['query']['pageInfo'];
						if (isset($result['data']['xtremMasterData']['customer']['query']['edges'][0]['node']['_id'])) {
							$allow_next_cal = true;
							foreach ($result['data']['xtremMasterData']['customer']['query']['edges'] as $node) {
								$customer = $node['node'];

								//customer details
								$customerData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_customer_id' => $customer['_id'], 'api_customer_code' => $customer['id'], 'customer_name' => @$customer['primaryContact']['intacctPrintAs'], 'first_name' => @$customer['primaryContact']['firstName'], 'last_name' => @$customer['primaryContact']['lastName'], 'phone' => @$customer['primaryContact']['locationPhoneNumber'], 'email' => @$customer['primaryContact']['email'], 'api_updated_at' => $customer['_updateStamp']];

								$platform_customer = PlatformCustomer::select('id', 'linked_id', 'api_updated_at')->where(['api_customer_id' => $customer['_id'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->first();
								if ($platform_customer) {
									if ($platform_customer->api_updated_at != $customer['_updateStamp'] && $platform_customer->linked_id == 0) {
										$customerData['sync_status'] = 'Ready';
									}

									$platform_customer->update($customerData);
								} else {
									$customerData['sync_status'] = 'Ready';
									PlatformCustomer::create($customerData);
								}
							}
						}

						if ($is_initial_sync) {
							if ($pageInfo['hasNextPage']) {
								$allow_next_cal = true;

								$query_parameter = 'query(first: 500, orderBy: \"{_createStamp:+1}\", filter: \"{isActive:true}\", after: \"' . str_replace('"', "'", $pageInfo['endCursor']) . '\")';

								PlatformUrl::where('id', $platform_url_id)
									->update(['url' => $query_parameter, 'updated_at' => date('Y-m-d H:i:s')]);

								$return_data = "Next cursor " . $query_parameter . " data";
							} else {
								$allow_next_cal = false;

								PlatformUrl::where('id', $platform_url_id)
									->update(['status' => 0, 'updated_at' => date('Y-m-d H:i:s')]);

								$return_data = true;
							}

							//max 2 time run this function in single call
							if (($callAPI % 2) == 0) {
								$allow_next_cal = false;
							}

							$callAPI++;
						} else {
							$allow_next_cal = false;
						}
					} elseif (is_array($result)) {
						$allow_next_cal = false;
						$return_data = $this->handleErrorResponse($result);
					} else {
						$allow_next_cal = false;
						$return_data = $response;
					}
				} while ($allow_next_cal);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' -> SDMOController -> GetCustomers -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	/** SYNC SALES ORDERS **/
	public function SyncSalesOrders($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id)
	{
		$return_response = true;
		try {
			$limit = 25;

			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			if ($platform_account) {
				$source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);

				$query = PlatformOrder::with('platformCustomer', 'platformOrderLine')->select('id', 'platform_customer_id', 'api_order_id', 'order_number', 'order_date', 'due_days', 'notes', 'sync_status', 'currency', 'shipping_method', 'payment_date', 'delivery_date', 'is_voided', 'order_status')
					->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'order_type' => 'SO']);

				if ($record_id) {
					$query->where('id', $record_id);
				} else {
					$query->where('sync_status', 'Ready');
				}

				$platform_orders = $query->where('linked_id', 0)->take($limit)->orderBy('updated_at', 'asc')->get();

				if (count($platform_orders)) {
					$sales_order_object_id = $this->ConnectionHelper->getObjectId('sales_order');

					$source_row_data = $destination_row_data = 'sku';
					$product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');

					$mapping_data = $this->FieldMappingHelper->getMappedField($user_integration_id, NULL, $product_identity_obj_id);
					if ($mapping_data) {
						if ($mapping_data['destination_platform_id'] == 'sdmo') {
							$destination_row_data = $mapping_data['destination_row_data'];
							$source_row_data = $mapping_data['source_row_data'];
						} else {
							$destination_row_data = $mapping_data['source_row_data'];
							$source_row_data = $mapping_data['destination_row_data'];
						}
					}

					$SourceOrDestination = "source";
					$platform_workflow_rule = $this->ConnectionHelper->getPlatformFlowDetail($platform_workflow_rule_id);
					if ($platform_workflow_rule && $platform_workflow_rule->destination_platform_id == $this->platformId) {
						$SourceOrDestination = "destination";
					}

					$salesSite = '';
					$default_sales_site = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "default_sales_site", ['api_id']);
					if ($default_sales_site) {
						$salesSite = $default_sales_site->api_id;
					}

					$currency = '';
					$default_currency = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "default_currency", ['api_id']);
					if ($default_currency && $default_currency->api_id) {
						$currency = $default_currency->api_id;
					}

					$incoterm = '';
					$default_incoterm = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "default_incoterm", ['api_id']);
					if ($default_incoterm && $default_incoterm->api_id) {
						$incoterm = $default_incoterm->api_id;
					}

					$paymentTerm = '';
					$default_payment_term = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "default_payment_term", ['api_id']);
					if ($default_payment_term && $default_payment_term->api_id) {
						$paymentTerm = $default_payment_term->api_id;
					}

					$deliveryMode = '';
					$default_delivery_mode = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, 'default_delivery_mode', ['api_id']);
					if ($default_delivery_mode && $default_delivery_mode->api_id) {
						$deliveryMode = $default_delivery_mode->api_id;
					}

					$displayStatus = '';
					$default_sorder_status = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, 'sorder_status', ['api_id']);
					if ($default_sorder_status && $default_sorder_status->api_id) {
						$displayStatus = $default_sorder_status->api_id;
					}

					$customer_object_id = NULL;
					$country_object_id = NULL;
					$taxIdNumber = NULL;
					foreach ($platform_orders as $platform_order) {
						$order_sync_error = NULL;
						$sourceCustomer = $platform_order->platformCustomer;
						if ($sourceCustomer) {
							$soldToCustomer = NULL;
							$linkedCustomer = $sourceCustomer->linkedCustomer;
							if ($linkedCustomer) {
								$soldToCustomer = $linkedCustomer->api_customer_id;
							} else {
								$destinationCustomer = $this->ConnectionHelper->findCustomerByName($sourceCustomer->customer_name, $user_id, $this->platformId, $user_integration_id);
								if ($destinationCustomer) {
									$soldToCustomer = $destinationCustomer->api_customer_id;
								} else {
									if ($customer_object_id == NULL) {
										$customer_object_id = $this->ConnectionHelper->getObjectId('customer');
									}

									if ($country_object_id == NULL) {
										$country_object_id = $this->ConnectionHelper->getObjectId('country');
									}

									if ($country_object_id == NULL) {
										$country_object_id = $this->ConnectionHelper->getObjectId('country');
									}

									if ($taxIdNumber == NULL) {
										$default_customer_taxcode = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, 'customer_taxcode', ['custom_data']);
										if ($default_customer_taxcode && $default_customer_taxcode->custom_data) {
											$taxIdNumber = $default_customer_taxcode->custom_data;
										}
									}

									$CreateCustomerResponse = $this->CreateCustomer($platform_account, $sourceCustomer, $customer_object_id, $country_object_id, $currency, $taxIdNumber, $paymentTerm, $deliveryMode);
									if (is_int($CreateCustomerResponse)) {
										$soldToCustomer = $CreateCustomerResponse;
									} else {
										$order_sync_error = $CreateCustomerResponse;
									}
								}
							}

							if ($soldToCustomer) {
								$lines = '';
								$getAllLine = 1;
								$source_order_lines = $platform_order->platformOrderLine;
								foreach ($source_order_lines as $source_order_line) {
									if ($source_order_line->row_type == 'ITEM') {
										if ($source_order_line->{$source_row_data}) {
											$destination_product = PlatformProduct::select('api_product_id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'is_deleted' => 0, $destination_row_data => $source_order_line->{$source_row_data}])->first();
											if ($destination_product) {
												$lines .= '{ item: ' . $destination_product->api_product_id . ', quantityInSalesUnit: ' . $source_order_line->qty . '},';
											} else {
												$getAllLine = 0;
											}
										} else {
											$getAllLine = 0;
										}
									}
								}

								if ($getAllLine) {
									$shipToAddressDetail = '';
									$platform_order_address = PlatformOrderAddress::where('platform_order_id', $platform_order->id)->where('address_type', 'shipping')->first();
									if ($platform_order_address) {
										$shipToAddressDetail = 'shipToAddressDetail: {isActive: true, name: \"' . $platform_order_address->address_name . '\", addressLine1: \"' . $platform_order_address->address1 . '\", addressLine2: \"' . $platform_order_address->address2 . '\", city: \"' . $platform_order_address->city . '\", region: \"' . $platform_order_address->state . '\", postcode: \"' . $platform_order_address->postal_code . '\", locationPhoneNumber: \"' . $platform_order_address->phone_number . '\"}, ';
									}

									$request_data_json = '{"query":"mutation{ xtremSales{ salesOrder{ create( data:{ ' . $shipToAddressDetail . ' soldToCustomer: ' . $soldToCustomer . ', displayStatus: \"' . $displayStatus . '\", salesSite: ' . $salesSite . ', number:\"' . $platform_order->order_number . '\", currency: ' . $currency . ', incoterm: ' . $incoterm . ', paymentTerm: ' . $paymentTerm . ', deliveryMode: ' . $deliveryMode . ', orderDate: \"' . date('Y-m-d', strtotime($platform_order->order_date)) . '\", requestedDeliveryDate: \"' . date('Y-m-d', strtotime($platform_order->order_date)) . '\", internalNote: {value: \"' . $platform_order->notes . '\"}, lines: [' . rtrim($lines, ",") . ']}) { _id number orderDate }}}}","variables":{}}';

									$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);
									$result = json_decode($response, true);
									if (isset($result['data']['xtremSales']['salesOrder']['create']['_id'])) {
										$new_sales_order = $result['data']['xtremSales']['salesOrder']['create'];

										$OrderLinked = PlatformOrder::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_type' => "SO", 'api_order_id' => $new_sales_order['_id'], 'order_date' => $new_sales_order['orderDate'], 'order_number' => $new_sales_order['number'], 'sync_status' => 'Pending', 'linked_id' => $platform_order->id, 'shipment_status' => "Pending", 'order_updated_at' => date("Y-m-d H:i:s")]);

										$platform_order->sync_status = 'Synced';
										$platform_order->linked_id = $OrderLinked->id;

										$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sales_order_object_id, 'success', $platform_order->id, NULL);
									} elseif (is_array($result)) {
										$order_sync_error = $this->handleErrorResponse($result);
									} else {
										$order_sync_error = $response;
									}
								} else {
									$order_sync_error = "Some product are not available in SDMO platform.";
								}
							}

							sleep(1);
						} else {
							$order_sync_error = "Customer not found.";
						}
					}

					if ($order_sync_error) {
						$platform_order->sync_status = 'Failed';

						$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sales_order_object_id, 'failed', $platform_order->id, $order_sync_error);

						$return_response = $order_sync_error;
					}

					$platform_order->save();
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' -> SDMOController -> SyncSalesOrders -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	/** SYNC CUSTOMER **/
	public function SyncCustomers($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id)
	{
		$return_response = true;
		try {
			$limit = 25;

			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			if ($platform_account) {
				$source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);

				$query = PlatformCustomer::select('id', 'user_id', 'platform_id', 'user_integration_id', 'api_customer_id', 'api_customer_code', 'api_customer_group_id', 'customer_name', 'first_name', 'last_name', 'company_name', 'phone', 'fax', 'email', 'address1', 'address2', 'address3', 'postal_addresses', 'country')
					->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'type' => 'Customer']);

				if ($record_id) {
					$query->where('id', $record_id);
				} else {
					$query->where('sync_status', 'Ready');
				}

				$platform_customers = $query->where('linked_id', 0)->take($limit)->orderBy('updated_at', 'asc')->get();

				if (count($platform_customers)) {
					$customer_object_id = $this->ConnectionHelper->getObjectId('customer');
					$country_object_id = $this->ConnectionHelper->getObjectId('country');

					$currency = '';
					$default_currency = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, 'default_currency', ['api_id']);
					if ($default_currency && $default_currency->api_id) {
						$currency = $default_currency->api_id;
					}

					$taxIdNumber = '';
					$default_customer_taxcode = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, 'customer_taxcode', ['custom_data']);
					if ($default_customer_taxcode && $default_customer_taxcode->custom_data) {
						$taxIdNumber = $default_customer_taxcode->custom_data;
					}

					$paymentTerm = '';
					$default_payment_term = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, 'default_payment_term', ['api_id']);
					if ($default_payment_term && $default_payment_term->api_id) {
						$paymentTerm = $default_payment_term->api_id;
					}

					$deliveryMode = '';
					$default_delivery_mode = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, 'default_delivery_mode', ['api_id']);
					if ($default_delivery_mode && $default_delivery_mode->api_id) {
						$deliveryMode = $default_delivery_mode->api_id;
					}

					$response = NULL;
					foreach ($platform_customers as $platform_customer) {
						$response = $this->CreateCustomer($platform_account, $platform_customer, $customer_object_id, $country_object_id, $currency, $taxIdNumber, $paymentTerm, $deliveryMode, $user_workflow_rule_id);
					}

					if (is_string($response)) {
						$return_response = $response;
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' -> SDMOController -> SyncCustomers -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	/** Create CUSTOMER **/
	public function CreateCustomer($platform_account, $customer, $customer_object_id, $country_object_id, $currency, $taxIdNumber, $paymentTerm, $deliveryMode, $user_workflow_rule_id = NULL)
	{
		$return_response = true;
		try {
			if ($platform_account && $customer && $customer_object_id) {
				$error_message = NULL;

				$country = 0;
				$country_object_data = PlatformObjectData::select('api_id')->where(['user_id' => $customer->user_id, 'user_integration_id' => $customer->user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $country_object_id, 'api_code' => $customer->country])->first();
				if ($country_object_data && $country_object_data->api_id) {
					$country = $country_object_data->api_id;
				}

				if ($country) {
					$businessEntityId = NULL;

					$request_data_json = '{"query":"mutation{ xtremMasterData{ businessEntity{ create( data:{ id: \"' . $customer->api_customer_id . '\", name: \"' . $customer->customer_name . '\", country: ' . $country . ', currency: ' . $currency . ', isCustomer: true, addresses:{isActive: true, name: \"' . $customer->customer_name . '\", addressLine1: \"' . $customer->address1 . '\", addressLine2: \"\", city: \"' . $customer->address2 . '\", region: \"' . $customer->address3 . '\", postcode: \"' . $customer->postal_addresses . '\", locationPhoneNumber: \"' . $customer->phone . '\", isPrimary: true, contacts:{title: \"mr\", firstName: \"' . $customer->first_name . '\", lastName: \"' . $customer->last_name . '\", email: \"' . $customer->email . '\", isPrimary: true}, intacctId: \"' . $customer->api_customer_id . '\", intacctIntegrationState: \"success\"}, taxIdNumber: \"' . $taxIdNumber . '\"}) { _id id name } } } }","variables":{}}';

					$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);
					$result = json_decode($response, true);
					if (isset($result['data']['xtremMasterData']['businessEntity']['create']['_id'])) {
						$businessEntityId = $result['data']['xtremMasterData']['businessEntity']['create']['_id'];
					} elseif (is_array($result)) {
						$error_message = $this->handleErrorResponse($result);
						if ($error_message == 'A record already exists with the same data.') {
							$error_message = NULL;

							$request_data_json = '{"query":"{ xtremMasterData{ businessEntity{ query(filter: \"{name:{_eq:\'' . $customer->customer_name . '\'}}\") {edges { node { _id id name } } } } }}","variables":{}}';

							$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);
							$result = json_decode($response, true);
							if (isset($result['data']['xtremMasterData']['businessEntity']['query']['edges'][0]['node']['_id'])) {
								$businessEntityId = $result['data']['xtremMasterData']['businessEntity']['query']['edges'][0]['node']['_id'];
							} else {
								$businessEntityId = '\"#' . $customer->api_customer_id . '\"';
							}
						}
					} else {
						$error_message = $response;
					}

					if ($businessEntityId) {
						$request_data_json1 = '{"query":"mutation{ xtremMasterData{ businessEntityAddress{ create( data:{ businessEntity : ' . $businessEntityId . ', isActive: true, name: \"' . $customer->customer_name . '\", addressLine1: \"' . $customer->address1 . '\", addressLine2: \"\", city: \"' . $customer->address2 . '\", region: \"' . $customer->address3 . '\", postcode: \"' . $customer->postal_addresses . '\", locationPhoneNumber: \"' . $customer->phone . '\", intacctId: \"' . $customer->api_customer_id . '\", intacctIntegrationState: \"success\"} ) { _id } } } }","variables":{}}';

						$response1 = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json1);
						$result1 = json_decode($response1, true);
						if (isset($result1['data']['xtremMasterData']['businessEntityAddress']['create']['_id'])) {
							$businessEntityAddressId = $result1['data']['xtremMasterData']['businessEntityAddress']['create']['_id'];
							$request_data_json2 = '{"query":"mutation { xtremMasterData{ customer{ create( data: { businessEntity: ' . $businessEntityId . ', paymentTerm: ' . $paymentTerm . ', deliveryAddresses: { shipToAddress: ' . $businessEntityAddressId . ', deliveryMode: ' . $deliveryMode . ' } } ) { _id id name } } } }","variables":{}}';

							$response2 = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json2);
							$result2 = json_decode($response2, true);
							if (isset($result2['data']['xtremMasterData']['customer']['create']['_id'])) {
								$new_customer = $result2['data']['xtremMasterData']['customer']['create'];

								$CustomerLinked = PlatformCustomer::create(['user_id' => $customer->user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $customer->user_integration_id, 'type' => 'Customer', 'api_order_id' => $new_customer['_id'], 'api_customer_code' => $new_customer['id'], 'customer_name' => $new_customer['name'], 'email' => $customer->email, 'linked_id' => $customer->id]);

								$customer->sync_status = 'Synced';
								$customer->linked_id = $CustomerLinked->id;
								$customer->save();

								if ($user_workflow_rule_id) {
									$this->Logger->syncLog($customer->user_id, $customer->user_integration_id, $user_workflow_rule_id, $customer->platform_id, $this->platformId, $customer_object_id, 'success', $customer->id, NULL);
								}

								return (int) $new_customer['_id'];
							} elseif (is_array($result2)) {
								$error_message = $this->handleErrorResponse($result2);
							} else {
								$error_message = $response2;
							}
						} elseif (is_array($result1)) {
							$error_message = $this->handleErrorResponse($result1);
						} else {
							$error_message = $response1;
						}
					}
				} else {
					$error_message = "Country code: '" . $customer->country . "' service not allow for connected account.";
				}

				if ($error_message) {
					$customer->sync_status = 'Failed';
					$customer->save();

					if ($user_workflow_rule_id) {
						$this->Logger->syncLog($customer->user_id, $customer->user_integration_id, $user_workflow_rule_id, $customer->platform_id, $this->platformId, $customer_object_id, 'failed', $customer->id, $error_message);
					}

					$return_response = $error_message;
				}
			} else {
				$return_response = 'Please check connection mapping data.';
			}
		} catch (\Exception $e) {
			\Log::error($customer->user_integration_id . ' -> SDMOController -> CreateCustomer -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	public function handleErrorResponse($data)
	{
		$error_message = NULL;
		if (is_array($data)) {
			if (isset($data['message'])) {
				$error_message = $data['message'];
			} elseif (isset($data['errors'][0]['message'])) {
				$error_message = $data['errors'][0]['message'];
			} elseif (isset($data['$diagnoses'][0]['$message'])) {
				//unauthorized: POST /api
				$error_message = $data['$diagnoses'][0]['$message'];
			} else {
				\Log::info('SDMO => Error:' . json_encode($data));
				$error_message = 'Please pass correct value in required fields.';
			}
		} else {
			$error_message = $data;
		}

		return $error_message;
	}

	public function test()
	{
		$return_data = true;
		try {
			$user_id = 109;
			$user_integration_id = 752;
			$is_initial_sync = 1;
			$response = $this->GetProducts($user_id, $user_integration_id, $is_initial_sync);
			dd($response);

			$user_workflow_rule_id = 1597;
			$platform_workflow_rule_id = 260;
			$source_platform_name = 'bigcommerce';
			$record_id = 594076;
			$response = $this->SyncSalesOrders($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id);

			//$user_workflow_rule_id = 1600;
			//$platform_workflow_rule_id = 263;
			//$response = $this->SyncCustomers($user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $platform_workflow_rule_id, 2718984);
			//$response = $this->GetPaymentTerms($user_id, $user_integration_id, $is_initial_sync);
			dd($response);
			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token', 'api_domain']);
			if ($platform_account) {
				$request_data_json = '{"query":"{xtremMasterData { customer { query(orderBy: \"{_updateStamp:+1}\") {totalCount pageInfo { endCursor hasNextPage startCursor } edges { node { _id id isActive primaryContact { firstName lastName locationPhoneNumber email intacctPrintAs } _createStamp _updateStamp }}}}}}","variables":{}}';
				$response = $this->SDMO->CallAPI($platform_account->api_domain, $this->MainModel->decryptString($platform_account->access_token, 'decrypt'), $request_data_json);
				$result = json_decode($response, true);

				echo '<pre>';
				print_r($result);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' -> SDMOController -> GetProducts -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	/* Execute SDMO Event Methods */
	public function ExecuteSDMOEvents($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = '')
	{
		try {
			$response = true;
			if ($method == 'GET' && $event == 'CATEGORY') {
				$response = $this->GetCategories($user_id, $user_integration_id, $is_initial_sync);
			} elseif ($method == 'GET' && $event == 'PRODUCT') {
				$response = $this->GetProducts($user_id, $user_integration_id, $is_initial_sync);
			} elseif ($method == 'GET' && $event == 'CUSTOMER') {
				$response = $this->GetCustomers($user_id, $user_integration_id, $is_initial_sync);
			} elseif ($method == 'GET' && $event == 'SALESSITE') {
				$response = $this->GetSalesSites($user_id, $user_integration_id, $is_initial_sync);
			} elseif ($method == 'GET' && $event == 'CURRENCY') {
				$response = $this->GetCurrencies($user_id, $user_integration_id, $is_initial_sync);
			} elseif ($method == 'GET' && $event == 'COUNTRY') {
				$response = $this->GetCountries($user_id, $user_integration_id, $is_initial_sync);
			} elseif ($method == 'GET' && $event == 'DELIVERYMODE') {
				$response = $this->GetDeliveryModes($user_id, $user_integration_id, $is_initial_sync);
			} elseif ($method == 'GET' && $event == 'INCOTERM') {
				$response = $this->GetIncoterms($user_id, $user_integration_id, $is_initial_sync);
			} elseif ($method == 'GET' && $event == 'PAYMENTTERM') {
				$response = $this->GetPaymentTerms($user_id, $user_integration_id, $is_initial_sync);
			} elseif ($method == 'MUTATE' && $event == 'CUSTOMER') {
				$response = $this->SyncCustomers($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id);
			} elseif ($method == 'MUTATE' && $event == 'SALESORDER') {
				$response = $this->SyncSalesOrders($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $platform_workflow_rule_id, $record_id);
			} elseif ($method == 'MUTATE' && $event == 'SHIPMENT') {
				//$response = $this->CreateOrderShipment($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' -> SDMOController -> ExecuteSDMOEvents -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			$response = $e->getMessage();
		}

		return $response;
	}
}
