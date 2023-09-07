<?php

namespace App\Http\Controllers\ShipBob;

use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\WorkflowSnippet;
use App\Helper\Logger;
use App\Helper\Api\ShipBobApi;
use App\Models\PlatformAccount;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderLine;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformProduct;
use App\Models\PlatformProductInventory;

use Illuminate\Support\Facades\Session;
use Lang;


class ShipBobApiController extends Controller
{
	public static $myPlatform = 'shipbob';

	/**
	 * Create a new controller instance.
	 *
	 * @return void
	 */
	public $ShipBobApi, $mobj, $conn, $mapping, $log, $WorkflowSnippet, $platformId;
	public function __construct()
	{
		$this->mobj = new MainModel();
		$this->ShipBobApi = new ShipBobApi();
		$this->conn = new ConnectionHelper();
		$this->mapping = new FieldMappingHelper();
		$this->log = new Logger();
		$this->WorkflowSnippet = new WorkflowSnippet();
		$this->platformId = $this->conn->getPlatformIdByName(self::$myPlatform);
	}

	public function InitiateShipBobAuth(Request $request)
	{
		$platform = 'shipbob';
		$request->session()->forget('shipbob_callback');
		return view("pages.apiauth.auth_shipbob", compact('platform'));
	}

	public function ConnectShipBobOauth(Request $request)
	{

		$request->session()->forget('shipbob_callback');
		$account_name = $request->shipbob_account_name;
		$env_type = trim($request->env_type);

		if ($this->mobj->checkHtmlTags($request->all())) {
			Session::put('auth_msg', Lang::get('tags.validate'));
			return redirect()->back();
		}

		$publicApp =  $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' =>  $this->platformId], ['app_ref', 'client_id', 'client_secret']);

		if ($publicApp) {

			$client_id = $this->mobj->decryptString($publicApp->client_id);
			// $client_secret = $this->mobj->decryptString($publicApp->client_secret);


			if ($this->platformId) {
				$redirect_uri = $this->mobj->makeUrlHttpsForProd(url('/RedirectHandlerShipBob'));


				if ($env_type == 'on') {
					// check account type if on pro.
					$OAuth_URL = \Config::get('apiconfig.ShipBobLiveAuthURL');
					$env_type = 'production';
				} else {
					$OAuth_URL = \Config::get('apiconfig.ShipBobSandboxAuthURL');
					$env_type = 'sandbox';
				}


				$state = $account_name . 'APIWORX' . $env_type;

				//use authorize or integrate
				$url = $OAuth_URL . "/connect/authorize?client_id=" . $client_id . "&scope=orders_read orders_write products_read products_write fulfillments_read inventory_read channels_read receiving_read receiving_write returns_read returns_write webhooks_read webhooks_write locations_read offline_access&redirect_uri=" . urlencode($redirect_uri) . '&state=' . $state;


				return redirect($url);
			} else {
				$this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"</a>');
			}
		} else {
			$this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"</a>');
		}
	}

	public function RedirectHandlerShipBob(Request $request)
	{

		if ($request->session()->has('shipbob_callback')) {
			$request->session()->forget('shipbob_callback');
		} else {
			$request->session()->put('shipbob_callback', 'Yes');
			echo '<script>var href = window.location.href;window.location.href = href.replace("#", "?");</script>';
		}

		if ($request->code) {

			try {
				$code = $request->code;
				$state = $request->state;
				$state = explode("APIWORX", $request->state);

				$account_name = $state[0];
				$env_type = $state[1];

				if ($code) {
					$publicApp =  $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' =>  $this->platformId], ['app_ref', 'client_id', 'client_secret']);

					if ($publicApp) {

						$client_id = $this->mobj->decryptString($publicApp->client_id);
						$client_secret = $this->mobj->decryptString($publicApp->client_secret);

						$redirect_uri = $this->mobj->makeUrlHttpsForProd(url('/RedirectHandlerShipBob'));


						$curl_post_data = array('code' => $code, 'client_id' => $client_id, 'client_secret' => $client_secret, 'redirect_uri' => $redirect_uri, 'grant_type' => 'authorization_code');

						//build array to query param
						$curl_post_data_formatted = http_build_query($curl_post_data, '', '&');


						if ($env_type == 'production') {
							$service_url = \Config::get('apiconfig.ShipBobLiveAuthURL') . "/connect/token";
						} else {
							$env_type = 'sandbox';
							$service_url = \Config::get('apiconfig.ShipBobSandboxAuthURL') . "/connect/token";
						}

						$headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

						$response = $this->mobj->makeCurlRequest('POST', $service_url, $curl_post_data_formatted, $headers);
						$result = json_decode($response, true);


						if (isset($result['access_token'])) {
							$OauthData = ['user_id' => Auth::user()->id, 'access_token' => $this->mobj->encryptString($result['access_token']), 'refresh_token' => $this->mobj->encryptString($result['refresh_token']), 'token_type' => $result['token_type'], 'expires_in' => $result['expires_in'], 'account_name' => $account_name, 'platform_id' => $this->platformId, 'env_type' => $env_type, 'token_refresh_time' => time(), 'allow_refresh' => 1];

							$platform_account = PlatformAccount::where(['user_id' => Auth::user()->id, 'platform_id' => $this->platformId, 'account_name' => $client_id])->first();
							if ($platform_account) {
								PlatformAccount::where('id', $platform_account->id)
									->update($OauthData);
							} else {
								PlatformAccount::insert($OauthData);
							}
							echo '<script>window.close();</script>';
						} else {
							//When Token not found
							$platform_account = PlatformAccount::where(['user_id' => Auth::user()->id, 'platform_id' => $this->platformId, 'account_name' => $client_id])->first();
							if ($platform_account) {
								PlatformAccount::where('id', $platform_account->id)
									->update(['access_token' => null, 'token_type' => null]);
							}

							$this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
						}
					} else {
						$this->mobj->ThrowErrorAndExit('Auth App detail not found<br><a href="javascript:window.close();"></a>');
					}
				} else {
					//When code not received
					$this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
				}
			} catch (\Exception $e) {
				$this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
			}
		} else {
			$request->session()->put('shipbob_callback', 'Yes');
			echo '<script>var href = window.location.href;window.location.href = href.replace("#", "?");</script>';
		}
	}

	/*Refresh token*/
	function RefreshToken($ID)
	{
		$return_response = false;
		date_default_timezone_set('UTC');
		try {
			$platform_account = $this->mobj->getFirstResultByConditions('platform_accounts', ['id' => $ID], ['refresh_token', 'app_id', 'app_secret', 'env_type']);
			if ($platform_account) {
				$redirect_uri = $this->mobj->makeUrlHttpsForProd(url('/RedirectHandlerShipBob'));

				//condition for old connected user
				if ($platform_account->app_id && $platform_account->app_secret) {

					$client_id = $platform_account->app_id;
					$client_secret = $platform_account->app_secret;
				} else {

					$publicApp =  $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' =>  $this->platformId], ['app_ref', 'client_id', 'client_secret']);
					$client_id = $publicApp->client_id;
					$client_secret = $publicApp->client_secret;
				}



				$curl_post_data = [
					'redirect_uri' => $redirect_uri,
					'client_id' => $this->mobj->decryptString($client_id),
					'client_secret' => $this->mobj->decryptString($client_secret),
					'refresh_token' => $this->mobj->decryptString($platform_account->refresh_token),
					'grant_type' => 'refresh_token',
				];



				//build array to query param
				$curl_post_data_formatted = http_build_query($curl_post_data, '', '&');


				if ($platform_account->env_type == 'production') {
					$service_url = \Config::get('apiconfig.ShipBobLiveAuthURL') . "/connect/token";
				} else {
					$service_url = \Config::get('apiconfig.ShipBobSandboxAuthURL') . "/connect/token";
				}

				$headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];

				$response = $this->mobj->makeCurlRequest('POST', $service_url, $curl_post_data_formatted, $headers);


				$result = json_decode($response, true);

				if (isset($result['access_token'])) {
					$this->mobj->makeUpdate('platform_accounts', ['access_token' => $this->mobj->encryptString($result['access_token']), 'expires_in' => $result['expires_in'], 'refresh_token' => $this->mobj->encryptString($result['refresh_token']), 'token_refresh_time' => time()], ['id' => $ID]);

					$return_response = true;
				} else {
					$return_response = "API Error";
				}
			}
		} catch (\Exception $e) {
			\Log::error($ID . " -> ShipBobApiController -> RefreshToken -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_response = $e->getMessage();
		}


		return $return_response;
	}


	public function GetChannels($user_id = 0, $user_integration_id = 0)
	{
		$return_data = true;
		try {
			$channel_object = $this->mobj->getFirstResultByConditions('platform_objects', ['name' => "channel"], ['id']);
			if ($channel_object) {
				$this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $channel_object->id, 'status' => 1]);

				$platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'env_type']);
				if ($platform_account) {
					$response = $this->ShipBobApi->GetChannelList($this->mobj->decryptString($platform_account->access_token), $platform_account->env_type);
					$channels = json_decode($response, true);
					if (isset($channels[0]['id'])) {
						foreach ($channels as $channel) {
							$ChannelData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $channel_object->id, 'api_id' => $channel['id'], 'name' => $channel['name'], 'api_code' => $channel['name'], 'description' => $channel['name'], 'status' => 1];

							$platform_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $channel_object->id, 'api_id' => $channel['id']], ['id']);
							if ($platform_object_data) {
								$this->mobj->makeUpdate('platform_object_data', $ChannelData, ['id' => $platform_object_data->id]);
							} else {
								$this->mobj->makeInsert('platform_object_data', $ChannelData);
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> ShipBobApiController -> GetChannels -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	public function GetShippingMethods($user_id = 0, $user_integration_id = 0)
	{
		$return_data = true;
		try {
			$shipping_method_object = $this->mobj->getFirstResultByConditions('platform_objects', ['name' => "shipping_method"], ['id']);
			if ($shipping_method_object) {
				//no need to do status update
				// $this->mobj->makeUpdate('platform_object_data', ['status'=>0], ['user_id'=>$user_id, 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'platform_object_id'=>$shipping_method_object->id, 'status'=>1]);

				$platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'env_type']);
				if ($platform_account) {
					$Limit = 250;
					$Page = 1;
					$response = $this->ShipBobApi->GetShippingMethodList($this->mobj->decryptString($platform_account->access_token), $platform_account->env_type, $Limit, $Page);
					$shipping_methods = json_decode($response, true);

					if (isset($shipping_methods[0]['id'])) {
						foreach ($shipping_methods as $shipping_method) {

							//$ShippingMethodData=['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$shipping_method_object->id, 'api_id'=>$shipping_method['id'], 'name'=>$shipping_method['name'], 'api_code'=>$shipping_method['name'], 'description'=>$shipping_method['name'], 'status'=>$shipping_method['active']];

							$ShippingMethodData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $shipping_method_object->id, 'api_id' => $shipping_method['name'], 'name' => $shipping_method['name'], 'api_code' => $shipping_method['name'], 'description' => $shipping_method['name'], 'status' => 1];


							//$platform_object_data=$this->mobj->getFirstResultByConditions('platform_object_data', ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$shipping_method_object->id, 'api_id'=>$shipping_method['id']], ['id']);
							$platform_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $shipping_method_object->id, 'api_id' => $shipping_method['name']], ['id']);


							if ($platform_object_data) {
								$this->mobj->makeUpdate('platform_object_data', $ShippingMethodData, ['id' => $platform_object_data->id]);
							} else {
								$this->mobj->makeInsert('platform_object_data', $ShippingMethodData);
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> ShipBobApiController -> GetShippingMethods -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	public function GetLocations($user_id = 0, $user_integration_id = 0)
	{
		$return_data = true;
		try {
			$location_object = $this->mobj->getFirstResultByConditions('platform_objects', ['name' => "location"], ['id']);
			if ($location_object) {
				$this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $location_object->id, 'status' => 1]);

				$platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'env_type']);
				if ($platform_account) {
					$response = $this->ShipBobApi->GetLocationList($this->mobj->decryptString($platform_account->access_token), $platform_account->env_type);

					$locations = json_decode($response, true);
					if (isset($locations[0]['id'])) {
						foreach ($locations as $location) {
							$ShippingMethodData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $location_object->id, 'api_id' => $location['id'], 'name' => $location['name'], 'api_code' => $location['name'], 'description' => $location['name'], 'status' => $location['is_active']];

							$platform_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $location_object->id, 'api_id' => $location['id']], ['id']);
							if ($platform_object_data) {
								$this->mobj->makeUpdate('platform_object_data', $ShippingMethodData, ['id' => $platform_object_data->id]);
							} else {
								$this->mobj->makeInsert('platform_object_data', $ShippingMethodData);
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> ShipBobApiController -> GetLocations -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	public function GetWarehouses($user_id = 0, $user_integration_id = 0)
	{
		$return_data = true;
		try {
			$warehouse_object = $this->mobj->getFirstResultByConditions('platform_objects', ['name' => "warehouse"], ['id']);
			if ($warehouse_object) {
				$this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $warehouse_object->id, 'status' => 1]);

				$platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'env_type']);
				if ($platform_account) {
					$response = $this->ShipBobApi->GetLocationList($this->mobj->decryptString($platform_account->access_token), $platform_account->env_type);

					$locations = json_decode($response, true);
					if (isset($locations[0]['id'])) {
						foreach ($locations as $location) {
							$ShippingMethodData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $warehouse_object->id, 'api_id' => $location['id'], 'name' => $location['name'], 'api_code' => $location['name'], 'description' => $location['name'], 'status' => $location['is_active']];

							$platform_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $warehouse_object->id, 'api_id' => $location['id']], ['id']);
							if ($platform_object_data) {
								$this->mobj->makeUpdate('platform_object_data', $ShippingMethodData, ['id' => $platform_object_data->id]);
							} else {
								$this->mobj->makeInsert('platform_object_data', $ShippingMethodData);
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> ShipBobApiController -> GetWarehouses -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	public function getDestinationPlatformName($user_integration_id)
	{
		$destination_platform = NULL;
		$user_integration = $this->mapping->getUserIntegrationDetailsById($user_integration_id, self::$myPlatform);
		if ($user_integration) {
			$platform_account = $this->mobj->getFirstResultByConditions('platform_accounts', ['id' => $user_integration->selected_dc_account_id], ['platform_id']);
			if ($platform_account) {
				$platform_lookup = $this->mobj->getFirstResultByConditions('platform_lookup', ['id' => $platform_account->platform_id], ['platform_id']);
				if ($platform_lookup) {
					$destination_platform = $platform_lookup->platform_id;
				}
			}
		}
		return $destination_platform;
	}

	public function GetShipBobOrders($user_id = 0, $user_integration_id = 0, $platform_workflow_rule_id = 0)
	{
		$return_data = true;
		try {
			$EventID = "GET_SALESORDER";

			$selectFields =['e.event_id','ur.status'];

			$user_work_flow = $this->mapping->getUserIntegWorkFlow($user_integration_id,  $EventID, $selectFields, self::$myPlatform);

			if(isset($user_work_flow[$EventID])){
				/* First Check whether Order Sync is ON */
			  if($user_work_flow[$EventID]['status'] == 1) {
				$platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'env_type']);
				if ($platform_account) {
					$user_workflow_rule = $this->mobj->getFirstResultByConditions('user_workflow_rule', ['user_integration_id' => $user_integration_id, 'platform_workflow_rule_id' => $platform_workflow_rule_id, 'status' => 1], ['sync_start_date']);
					if ($user_workflow_rule) {
						//get mapped order statuses
						$order_channel_list = $this->WorkflowSnippet->getStatusByWorkflow($platform_workflow_rule_id, 'sorder_channel_filter');
						foreach ($order_channel_list as $order_channel) {
							$order_channel_object = $this->mobj->getFirstResultByConditions('platform_objects', ['name' => "channel"], ['id']);
							if ($order_channel_object) {
								$platform_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $order_channel_object->id, 'name' => $order_channel, 'status' => 1], ['api_id']);
								if ($platform_object_data) {
									$channel_id = $platform_object_data->api_id;
									$Limit = 200;
									$Page = 1;
									$StartDate = NULL;

									if ($user_workflow_rule->sync_start_date) {
										$StartDate = date('Y-m-d', strtotime($user_workflow_rule->sync_start_date));
									}

									$pull_time = DB::table('platform_order')->select('order_date')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->whereNotNull('order_date')->orderByRaw("DATE_FORMAT(order_date, '%Y-%m-%d %H:%i:%s') DESC")->first();
									if ($pull_time) {
										$StartDate = date('Y-m-d', strtotime($pull_time->order_date));
									}

									$warehouse_object_id = $this->conn->getObjectId('warehouse');

									$destination_platform_id = NULL;
									$platform_object_data_id = NULL;
									$destination_platform = $this->getDestinationPlatformName($user_integration_id);
									if ($destination_platform == 'brightpearl') {
										$destination_platform_id = $this->conn->getPlatformIdByName('brightpearl');

										$product_pricelist = $this->mapping->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "product_pricelist", ['id']);
										if ($product_pricelist) {
											$platform_object_data_id = $product_pricelist->id;
										}
									}

									do {
										$allow_next_cal = false;

										$response = $this->ShipBobApi->GetOrderList($this->mobj->decryptString($platform_account->access_token), $platform_account->env_type, $Limit, $Page, $channel_id, $StartDate);

										$Orders = json_decode($response, true);
										if (isset($Orders[0]['id'])) {
											$allow_next_cal = true;
											foreach ($Orders as $Order) {
												if (isset($Order['shipments'][0]['id'])) {
													foreach ($Order['shipments'] as $shipment) {
														$email = $shipment['recipient']['email'];
														if ($shipment['recipient']['email'] == NULL || trim($shipment['recipient']['email']) == '') {
															$email = 'shipment_' . $shipment['id'] . '@shipbob.com';
														}

														//order customer details
														$CustomerData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'customer_name' => $shipment['recipient']['name'], 'first_name' => $shipment['recipient']['name'], 'company_name' => $shipment['recipient']['address']['company_name'], 'address1' => $shipment['recipient']['address']['address1'], 'address2' => $shipment['recipient']['address']['address2'], 'country' => $shipment['recipient']['address']['country'], 'postal_addresses' => $shipment['recipient']['address']['zip_code'], 'email' => $email, 'phone' => $shipment['recipient']['phone_number']];

														$platform_customer = $this->mobj->getFirstResultByConditions('platform_customer', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'email' => $email], ['id']);
														if ($platform_customer) {
															$platform_customer_id = $platform_customer->id;
															$this->mobj->makeUpdate('platform_customer', $CustomerData, ['id' => $platform_customer->id]);
														} else {
															$platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $CustomerData);
														}

														$order_warehouse_id = NULL;
														if (isset($shipment['location']['id'])) {
															$arr_warehouse = array();
															$arr_warehouse['user_id'] = $user_id;
															$arr_warehouse['platform_id'] = $this->platformId;
															$arr_warehouse['name'] = @$shipment['location']['name'];
															$arr_warehouse['api_id'] = $shipment['location']['id'];
															$arr_warehouse['user_integration_id'] = $user_integration_id;
															$arr_warehouse['platform_object_id'] = $warehouse_object_id;

															$ord_warehouse = $this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id' => $this->platformId, 'platform_object_id' => $warehouse_object_id, 'user_id' => $user_id, 'api_id' => $shipment['location']['id']], ['id']);
															if ($ord_warehouse) {
																$order_warehouse_id = $ord_warehouse->id;
																$this->mobj->makeUpdate('platform_object_data', $arr_warehouse, ['id' => $order_warehouse_id]);
															} else {
																$order_warehouse_id = $this->mobj->makeInsertGetId('platform_object_data', $arr_warehouse);
															}
														}

														$order_number = $shipment['id'];
														if ($Order['order_number']) {
															$order_number = $Order['order_number'];
														}

														$total_amount = $shipment['invoice_amount'];

														$OrderData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_customer_id' => $platform_customer_id, 'order_type' => 'SO', 'api_order_id' => $shipment['order_id'], 'customer_email' => $email, 'order_number' => $order_number, 'order_date' => date('Y-m-d H:i:s', strtotime($shipment['created_date'])), 'order_status' => $shipment['status'], 'total_amount' => $total_amount, 'shipping_method' => $Order['shipping_method'], 'warehouse_id' => $order_warehouse_id, 'api_updated_at' => date('Y-m-d H:i:s', strtotime($shipment['created_date']))];

														$platform_order = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_type' => 'SO', 'api_order_id' => $shipment['order_id']], ['id', 'order_status', 'api_updated_at', 'linked_id']);
														if ($platform_order) {
															if ($platform_order->api_updated_at != date('Y-m-d H:i:s', strtotime($shipment['created_date'])) && $platform_order->order_status != $shipment['status'] && $platform_order->linked_id == 0) {
																$OrderData['sync_status'] = 'Ready';
															}

															if ($platform_order->api_updated_at != date('Y-m-d H:i:s', strtotime($shipment['created_date']))) {
																$OrderData['order_updated_at'] = date("Y-m-d H:i:s");
															}

															$platform_order_id = $platform_order->id;
															$this->mobj->makeUpdate('platform_order', $OrderData, ['id' => $platform_order->id]);
														} else {
															$OrderData['sync_status'] = 'Ready';
															$OrderData['order_updated_at'] = date("Y-m-d H:i:s");
															$platform_order_id = $this->mobj->makeInsertGetId('platform_order', $OrderData);
														}

														//order address
														$OrderBillingAddressData = ['platform_order_id' => $platform_order_id, 'address_type' => 'billing', 'address_name' => $shipment['recipient']['name'], 'firstname' => $shipment['recipient']['name'], 'company' => $shipment['recipient']['address']['company_name'], 'address1' => $shipment['recipient']['address']['address1'], 'address2' => $shipment['recipient']['address']['address2'], 'city' => $shipment['recipient']['address']['city'], 'state' => $shipment['recipient']['address']['state'], 'postal_code' => $shipment['recipient']['address']['zip_code'], 'country' => $shipment['recipient']['address']['country'], 'email' => $email, 'phone_number' => $shipment['recipient']['phone_number']];

														$platform_order_billing_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'billing'], ['id']);
														if ($platform_order_billing_address) {
															$this->mobj->makeUpdate('platform_order_address', $OrderBillingAddressData, ['id' => $platform_order_billing_address->id]);
														} else {
															$this->mobj->makeInsert('platform_order_address', $OrderBillingAddressData);
														}

														//order address
														$OrderShippingAddressData = ['platform_order_id' => $platform_order_id, 'address_type' => 'shipping', 'address_name' => $shipment['recipient']['name'], 'firstname' => $shipment['recipient']['name'], 'company' => $shipment['recipient']['address']['company_name'], 'address1' => $shipment['recipient']['address']['address1'], 'address2' => $shipment['recipient']['address']['address2'], 'city' => $shipment['recipient']['address']['city'], 'state' => $shipment['recipient']['address']['state'], 'postal_code' => $shipment['recipient']['address']['zip_code'], 'country' => $shipment['recipient']['address']['country'], 'email' => $email, 'phone_number' => $shipment['recipient']['phone_number']];

														$platform_order_shipping_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'shipping'], ['id']);
														if ($platform_order_shipping_address) {
															$this->mobj->makeUpdate('platform_order_address', $OrderShippingAddressData, ['id' => $platform_order_shipping_address->id]);
														} else {
															$this->mobj->makeInsert('platform_order_address', $OrderShippingAddressData);
														}

														//order line item
														if (isset($shipment['products'][0]['id'])) {
															foreach ($shipment['products'] as $product) {
																//order line item
																if (isset($product['inventory_items'][0]['id'])) {
																	foreach ($product['inventory_items'] as $inventory_item) {
																		$platform_product = $this->mobj->getFirstResultByConditions('platform_product', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'manufacturer_sku' => $product['sku']], ['api_product_id', 'sku', 'barcode']);

																		$sku = $product['sku'];
																		$barcode = $product_id = NULL;
																		if ($platform_product) {
																			$barcode = $platform_product->barcode;
																			$product_id = $platform_product->api_product_id;
																			$sku = $platform_product->sku;
																		}

																		$price = $unit_price = $subtotal = $total = 0;
																		if ($destination_platform_id && $platform_object_data_id) {
																			$destination_platform_product = $this->mobj->getFirstResultByConditions('platform_product', ['platform_id' => $destination_platform_id, 'user_integration_id' => $user_integration_id, 'sku' => $sku], ['id']);
																			if ($destination_platform_product) {
																				$platform_product_price_list = $this->mobj->getFirstResultByConditions('platform_porduct_price_list', ['platform_product_id' => $destination_platform_product->id, 'platform_object_data_id' => $platform_object_data_id], ['price']);
																				if ($platform_product_price_list) {
																					$price = $unit_price = $platform_product_price_list->price;

																					$subtotal = $total = $platform_product_price_list->price * $inventory_item['quantity'];
																				}
																			}
																		}

																		$total_amount = $total_amount + $total;

																		$OrderItemData = ['platform_order_id' => $platform_order_id, 'api_order_line_id' => $product['id'], 'api_product_id' => $product_id, 'barcode' => $barcode, 'product_name' => $product['name'], 'sku' => $sku, 'price' => $price, 'unit_price' => $unit_price, 'subtotal' => $subtotal, 'total' => $total, 'qty' => $inventory_item['quantity']];

																		$platform_order_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'api_order_line_id' => $product['id']], ['id']);
																		if ($platform_order_line) {
																			$this->mobj->makeUpdate('platform_order_line', $OrderItemData, ['id' => $platform_order_line->id]);
																		} else {
																			$this->mobj->makeInsert('platform_order_line', $OrderItemData);
																		}
																	}
																}
															}
														}

														$this->mobj->makeUpdate('platform_order', ['total_amount' => $total_amount], ['id' => $platform_order_id]);
													}
												}
											}
											$Page++;
											if (count($Orders) != $Limit) {
												$allow_next_cal = false;
											}
										}
									} while ($allow_next_cal);
								}
							}
						}
					}
				}
			  }
			}

		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> ShipBobApiController -> GetShipBobOrders -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	public function GetShipBobInventory($user_id = 0, $user_integration_id = 0)
	{
		$return_data = true;
		try {
			$EventID = "GET_INVENTORY";
			$selectFields =['e.event_id','ur.status'];
			$user_work_flow = $this->mapping->getUserIntegWorkFlow($user_integration_id, $EventID, $selectFields,  self::$myPlatform);
			/* First Check whether Order Sync is ON */
			if (isset($user_work_flow[$EventID]) && $user_work_flow[$EventID]['status'] == 1) {
				$platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'env_type']);
				if ($platform_account) {
					$Limit = 250;
					$Page = 1;

					$platform_url = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'inventory_limit', 'status' => 1], ['id', 'url']);
					if ($platform_url) {
						$platform_url_id = $platform_url->id;
						$Page = $platform_url->url;
					} else {
						$url_data = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'inventory_limit', 'url' => 1, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
						$platform_url_id = $this->mobj->makeInsertGetId('platform_urls', $url_data);
					}

					do {
						$allow_next_cal = false;
						$response = $this->ShipBobApi->GetInventoryList($this->mobj->decryptString($platform_account->access_token), $platform_account->env_type, $Limit, $Page);

						$inventories = json_decode($response, true);
						if (isset($inventories[0]['id'])) {
							$allow_next_cal = true;
							foreach ($inventories as $inventory) {
								//product details
								$productData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_product_id' => $inventory['id'], 'product_name' => $inventory['name'], 'sku' => $inventory['sku'], 'manufacturer_sku' => $inventory['reference_id'], 'gtin' => $inventory['gtin'], 'upc' => $inventory['upc'], 'barcode' => $inventory['barcode'], 'price' => $inventory['unit_price']];

								$platform_product = $this->mobj->getFirstResultByConditions('platform_product', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'sku' => $inventory['sku']], ['id', 'inventory_sync_status']);
								if ($platform_product) {
									$platform_product_id = $platform_product->id;
									$this->mobj->makeUpdate('platform_product', $productData, ['id' => $platform_product->id]);
								} else {
									$productData['product_sync_status'] = 'Ready';
									$platform_product_id = $this->mobj->makeInsertGetId('platform_product', $productData);
								}

								$apiWarehouseIds = [];
								$allow_inventory_ready = false;
								if (isset($inventory['fulfillable_quantity_by_fulfillment_center'][0]['id'])) {
									foreach ($inventory['fulfillable_quantity_by_fulfillment_center'] as $warehouse) {
										$InventoryData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_product_id' => $platform_product_id, 'api_product_id' => $inventory['id'], 'api_warehouse_id' => $warehouse['id'], 'sku' => $inventory['sku'], 'quantity' => $warehouse['onhand_quantity']];

										$platform_product_inventory = $this->mobj->getFirstResultByConditions('platform_product_inventory', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'sku' => $inventory['sku'], 'api_warehouse_id' => $warehouse['id']], ['id', 'quantity']);
										// Note::upar wale query me warehouse id agr nhi aayega (if value empty ho like '') to empty field se search krne se query kuch return nhikrega if db me warehouse id null set ho tab
										if ($platform_product_inventory) {
											//if ($platform_product_inventory->quantity != $warehouse['onhand_quantity']) {
												$InventoryData['sync_status'] = 'Ready';
												$this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Ready'], ['id' => $platform_product_id]);
											//}

											$this->mobj->makeUpdate('platform_product_inventory', $InventoryData, ['id' => $platform_product_inventory->id]);
										} else {
											$InventoryData['sync_status'] = 'Ready';
											$this->mobj->makeInsertGetId('platform_product_inventory', $InventoryData);
											$this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Ready'], ['id' => $platform_product_id]);
											$allow_inventory_ready = true;
										}

										$apiWarehouseIds[] = $warehouse['id'];
									}
								}

								if (count($apiWarehouseIds)) {
									$product_inventories = PlatformProductInventory::select('id')->whereNotIn('api_warehouse_id', $apiWarehouseIds)->where('platform_product_id', $platform_product_id)->where('quantity', '!=', 0)->where('sync_status', 'Synced')->count();
									if ($product_inventories) {
										PlatformProductInventory::whereNotIn('api_warehouse_id', $apiWarehouseIds)->where('platform_product_id', $platform_product_id)->where('quantity', '!=', 0)->where('sync_status', 'Synced')
											->update(['quantity' => 0, 'sync_status' => 'Ready']);

										$this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Ready'], ['id' => $platform_product_id]);
										$allow_inventory_ready = false;
									}
								} else {
									$product_inventories = PlatformProductInventory::select('id')->where('platform_product_id', $platform_product_id)->where('quantity', '!=', 0)->where('sync_status', 'Synced')->count();
									if ($product_inventories) {
										PlatformProductInventory::where('platform_product_id', $platform_product_id)->where('quantity', '!=', 0)->where('sync_status', 'Synced')
											->update(['quantity' => 0, 'sync_status' => 'Ready']);

										$this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Ready'], ['id' => $platform_product_id]);
										$allow_inventory_ready = false;
									}
								}
							}

							if ($allow_inventory_ready) {
								$this->mobj->makeUpdate('platform_product', ['inventory_sync_status' => 'Ready'], ['id' => $platform_product_id]);
							}

							//max 4 time run this script in single call
							if (($Page % 4) == 0) {
								$allow_next_cal = false;
							}

							$Page++;
							if (count($inventories) == $Limit) {
								$this->mobj->makeUpdate('platform_urls', ['url' => $Page, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_url_id]);

								$return_data = "Next get page " . $Page . " data";
							} else {
								$allow_next_cal = false;
								$this->mobj->makeUpdate('platform_urls', ['url' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_url_id]);

								$return_data = true;
							}
						}
					} while ($allow_next_cal);
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> ShipBobApiController -> GetShipBobInventory -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	public function GetShipBobProduct($user_id = 0, $user_integration_id = 0)
	{
		$return_data = true;
		try {
			$EventID = "GET_PRODUCT";
			$selectFields =['e.event_id','ur.status'];
			$user_workflow = $this->mapping->getUserIntegWorkFlow($user_integration_id, $EventID, $selectFields, self::$myPlatform);

			/* First Check whether Order Sync is ON */
			if (isset($user_workflow[$EventID]) && $user_workflow[$EventID]['status'] == 1) {
				$platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'env_type']);
				if ($platform_account) {
					$Limit = 250;
					$Page = 1;

					$platform_url = $this->mobj->getFirstResultByConditions('platform_urls', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'product_limit', 'status' => 1], ['id', 'url']);
					if ($platform_url) {
						$platform_url_id = $platform_url->id;
						$Page = $platform_url->url;
					} else {
						$url_data = ['user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'url_name' => 'product_limit', 'url' => 1, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
						$platform_url_id = $this->mobj->makeInsertGetId('platform_urls', $url_data);
					}

					do {
						$allow_next_cal = false;

						$response = $this->ShipBobApi->GetInventoryList($this->mobj->decryptString($platform_account->access_token), $platform_account->env_type, $Limit, $Page);

						$products = json_decode($response, true);
						if (isset($products[0]['id'])) {
							$allow_next_cal = true;
							foreach ($products as $product) {
								//product details
								$productData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_product_id' => $product['id'], 'product_name' => $product['name'], 'sku' => $product['sku'], 'manufacturer_sku' => $product['reference_id'], 'gtin' => $product['gtin'], 'upc' => $product['upc'], 'barcode' => $product['barcode'], 'price' => $product['unit_price']];

								$platform_product = $this->mobj->getFirstResultByConditions('platform_product', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_product_id' => $product['id']], ['id']);
								if ($platform_product) {
									$this->mobj->makeUpdate('platform_product', $productData, ['id' => $platform_product->id]);
								} else {
									$productData['product_sync_status'] = 'Ready';
									$this->mobj->makeInsertGetId('platform_product', $productData);
								}
							}

							//max 4 time run this function in single call
							if (($Page % 4) == 0) {
								$allow_next_cal = false;
							}

							$Page++;

							if (count($products) == $Limit) {
								$this->mobj->makeUpdate('platform_urls', ['url' => $Page, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_url_id]);

								$return_data = "Next get page " . $Page . " data";
							} else {
								$allow_next_cal = false;
								$this->mobj->makeUpdate('platform_urls', ['url' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_url_id]);

								$return_data = true;
							}
						}
					} while ($allow_next_cal);
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> ShipBobApiController -> GetShipBobProduct -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	/* Create Webhook */
	public function CreateOrDeleteWebhook($userId = NULL, $userIntegrationId = NULL, array $webhookTypes, $attempt)
	{
		$return_response = false;
		try {
			$platform_account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'env_type']);
			if ($platform_account) {
				if ($attempt == 1) {
					// create webhook
					/* Please pass last param as 0=for staging mode and 1=for live mode */
					if (!empty($webhookTypes)) {
						$Baseurl = env('APP_WEBHOOK_URL');

						$arrayWebhookList = array();

						/* Please pass last param as if APP_ENV=stag or local then 0 for staging/local mode and APP_ENV=prod then 1=for live mode */
						$Mode = env('APP_ENV') == 'prod' ? "1" : "0";

						$check_already_subscribed = DB::table('platform_webhook_info')->where('user_integration_id', $userIntegrationId)->where('platform_id', $this->platformId)->where('status', 1)->pluck('description')->toArray();

						if (in_array('shipment', $webhookTypes) && (!in_array('order_shipped', $check_already_subscribed))) {
							$arrayWebhookList[] = ['topic' => 'order_shipped', 'subscription_url' => $Baseurl . "/shipbob/public/shipment/" . $userIntegrationId . "/" . $Mode];
						}

						if (count($arrayWebhookList) > 0) {
							$message = [];
							$error_message = '';
							foreach ($arrayWebhookList as $row) {
								$postData = $row;

								$response = $this->ShipBobApi->CreateWebhook($this->mobj->decryptString($platform_account->access_token), json_encode($postData), $platform_account->env_type);
								$result = json_decode($response, true);
								if (isset($result['id'])) {
									$webhookDetails = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'api_id' => $result['id'], 'description' => $row['topic'], 'status' => 1];
									$this->mobj->makeInsert('platform_webhook_info', $webhookDetails);
								} elseif (isset($result['error']) || (isset($result['errors']) && !isset($result['errors'][0]['code']))) {
									$message[] = 'Webhook not create for ' . $row['topic'] . ' topic';
								}
							}

							if (count($message) == 0) {
								$return_response = true;
							} else {
								$return_response = implode(" | ", $message);
							}
						}
					} else {
						$return_response = "error can not create webhook";
					}
				} elseif ($attempt == 2) {
					//delete webhook
					if (!empty($webhookTypes)) {
						if (in_array('all', $webhookTypes)) {
							$hookList = $this->mobj->getResultByConditions('platform_webhook_info', ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId], ['api_id'], ['id' => 'asc']);
							if ($hookList->count() > 0) {
								$hook = $hookList->pluck('api_id')->toArray();
								foreach ($hook as $value) {
									$response = $this->ShipBobApi->DeleteWebhook($this->mobj->decryptString($platform_account->access_token), $platform_account->env_type, $value);
									if ($response->getStatusCode() == 204) {
										$this->mobj->makeDelete('platform_webhook_info', ['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'api_id' => $value]);
									}
								}
							}
							$return_response = true;
						} else {
							$hookList = DB::table('platform_webhook_info')->where([['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $this->platformId]])->whereIn('api_id', $webhookTypes)->get();

							if ($hookList->count() > 0) {
								$hook = $hookList->pluck('api_id')->toArray();
								foreach ($hook as $value) {
									$response = $this->ShipBobApi->DeleteWebhook($this->mobj->decryptString($platform_account->access_token), $platform_account->env_type, $value);
									if ($response->getStatusCode() == 204) {
										$this->mobj->makeDelete('platform_webhook_info', ['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'api_id' => $value]);
									}
								}
								$return_response = true;
							}
						}
					} else {
						$return_response = "Error can not delete webhook";
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . " -> ShipBobApiController -> CreateOrDeleteWebhook -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	/*Get Sales Order || For Only good out note.shipped and order created from our panel */
	public function GetShipment($userId = NULL, $userIntegrationId = NULL, array $webhookTypes, $attempt = 1, $is_initial_sync)
	{
		$return_response = false;
		try {
			if ($is_initial_sync) {
				return $this->CreateOrDeleteWebhook($userId, $userIntegrationId, $webhookTypes, $attempt);
				$return_response = true;
			} elseif ($attempt == 2) {
				return $this->CreateOrDeleteWebhook($userId, $userIntegrationId, $webhookTypes, $attempt);
				$return_response = true;
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . " -> ShipBobApiController -> GetShipment -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}
	/* Find Order ID in order table */
	public function FindOrderID($OrderID = null, $userId = null, $PlatformId = null, $userIntegrationId = null)
	{
		$platform_order = $this->mobj->getFirstResultByConditions('platform_order', ['api_order_id' => $OrderID, 'user_integration_id' => $userIntegrationId, 'platform_id' => $PlatformId], ['id']);
		if ($platform_order) {
			return $platform_order->id;
		}

		return false;
	}
	/* Receive Order shipment webhook from Shipbob */
	public function ReceiveShipmentWebhook(Request $request, $userIntegrationId)
	{
		\Storage::disk('local')->append(date('d-m-Y') . '_shipbob_webhook.txt', 'ReceiveShipmentWebhook ' . ' time: ' . date('Y-m-d H:i:s')
			. json_encode($request->all()) . PHP_EOL);


		$return_response = false;
		try {
			if ($request->isMethod('post')) {
				$EventID = "GET_SHIPMENT";
				$integration = $this->mapping->getUserIntegrationDetailsById($userIntegrationId, self::$myPlatform);
				if ($integration) {
					$q = DB::table('user_workflow_rule as ur')->select('e.event_id', 'pl.platform_id')
						->join('platform_workflow_rule as pr', 'ur.platform_workflow_rule_id', '=', 'pr.id')
						->join('platform_events as e', 'pr.source_event_id', '=', 'e.id')
						->join('platform_events as de', 'pr.destination_event_id', '=', 'de.id')
						->join('platform_lookup as pl', 'de.platform_id', '=', 'pl.id')
						->where('pr.status', 1)
						->where('de.status', 1)
						->where('ur.status', 1)
						->where('pl.status', 1)
						->where('e.status', 1)
						#->where('ur.user_id', $integration->user_id)
						->where('ur.user_integration_id', $userIntegrationId);

					/* Check whether shipment is ON or OFF */
					if ($q->count() > 0) {
						$user_work_flow = $q->pluck('pl.platform_id', 'e.event_id')->toArray();
						if (isset($user_work_flow[$EventID])) {
							$body = $request->getContent();

							$warehouse_object_id = $this->conn->getObjectId('warehouse');

							/* Decode Json */
							$result_data = json_decode($body, 1);

							if (isset($result_data['shipments'][0]['id'])) {
								//get order level shipment status for Fulfilled/Partial
								$orderLevelShipmentStatus = @$result_data['status'];

								foreach ($result_data['shipments'] as $shipment) {
									if (isset($shipment['tracking']['tracking_number']) && !empty($shipment['tracking']['tracking_number'])) {
										//Only update when tracking info is available
										$isSave = true;
										if (isset(\Config::get('apisettings.AllowOrderNumberCheckInShipBob')[$user_work_flow[$EventID]])) {
											$find = $this->FindOrderID($shipment['order_id'], $integration->user_id, $this->platformId, $userIntegrationId);
											if ($find == false) {
												$isSave = false;
											}
										}
										if ($isSave) {
											$order_warehouse_id = NULL;
											if (isset($shipment['location']['id'])) {
												$arr_warehouse = array();
												$arr_warehouse['user_id'] = $integration->user_id;
												$arr_warehouse['platform_id'] = $this->platformId;
												$arr_warehouse['name'] = @$shipment['location']['name'];
												$arr_warehouse['api_id'] = $shipment['location']['id'];
												$arr_warehouse['user_integration_id'] = $userIntegrationId;
												$arr_warehouse['platform_object_id'] = $warehouse_object_id;

												$ord_warehouse = $this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id' => $this->platformId, 'platform_object_id' => $warehouse_object_id, 'user_id' => $integration->user_id, 'api_id' => $shipment['location']['id']], ['id']);
												if ($ord_warehouse) {
													$order_warehouse_id = $ord_warehouse->id;
													$this->mobj->makeUpdate('platform_object_data', $arr_warehouse, ['id' => $order_warehouse_id]);
												} else {
													$order_warehouse_id = $this->mobj->makeInsertGetId('platform_object_data', $arr_warehouse);
												}
											}

											$order_number = $shipment['id'];
											$platform_account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'access_token', 'env_type']);
											if ($platform_account) {
												$order_response = $this->ShipBobApi->GetOrderByID($this->mobj->decryptString($platform_account->access_token), $platform_account->env_type, $shipment['order_id']);

												$Order = json_decode($order_response, true);
												if (isset($Order['order_number'])) {
													if ($Order['order_number']) {
														$order_number = $Order['order_number'];
													}
												}
											}

											$OrderData = ['user_id' => $integration->user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'order_type' => 'SO', 'api_order_id' => $shipment['order_id'], 'order_number' => $order_number, 'warehouse_id' => $order_warehouse_id, 'shipping_method' => @$result_data['shipping_method']];

											$platform_order = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'order_type' => 'SO', 'api_order_id' => $shipment['order_id']], ['id', 'shipment_status', 'linked_id']);

											if ($platform_order) {
												$platform_order_id = $platform_order->id;

												$OrderData['shipment_api_status'] = $orderLevelShipmentStatus;

												if ($platform_order->shipment_status != 'Synced') {
													$OrderData['shipment_status'] = 'Ready';
												}

												if ($platform_order->linked_id) {

													$created_on_date = isset($shipment['actual_fulfillment_date']) && $shipment['actual_fulfillment_date'] ? $shipment['actual_fulfillment_date'] : $shipment['last_update_at'];

													$ShipmentData = [
														'shipment_id' => $shipment['id'],
														'order_id' => $shipment['order_id'],
														'warehouse_id' => @$shipment['location']['id'],
														'shipment_status' => $shipment['status'],
														'tracking_info' => @$shipment['tracking']['tracking_number'],
														'shipping_method' => @$result_data['shipping_method'],
														'carrier_code' => @$shipment['tracking']['carrier'],
														'tracking_url' => @$shipment['tracking']['tracking_url'],
														'weight' => @$shipment['measurements']['total_weight_oz'],
														'created_on' => $created_on_date
													];

													$isShipmentReady = false;
													// Find destination shipment info by Shipbob order's linked id
													$destShipment = PlatformOrderShipment::where('platform_order_id', $platform_order->linked_id)->select('id', 'linked_id')->first();
													if ($destShipment && $destShipment->linked_id > 0) {
														// Pick shipbob shipment id and sync status if it is linked with a destination shipment
														$sourceShipment = PlatformOrderShipment::where('id', $destShipment->linked_id)->select('id', 'sync_status')->first();
														if ($sourceShipment) {
															if ($sourceShipment->sync_status != 'Synced') {
																$ShipmentData['sync_status'] = 'Ready';
																$isShipmentReady = true;
															}
														}
														$sourceShipment->update($ShipmentData);
														$platform_order_shipment_id = $sourceShipment->id;
													} else {
														$linked_id = null;
														if ($destShipment) {
															// Set linked id if there is a destination shipment record exists
															$linked_id = $destShipment->id;
														}
														$findShipment = PlatformOrderShipment::where(['platform_order_id' => $platform_order_id, 'shipment_id' => $shipment['id']])->first();
														if ($findShipment) {
															if ($findShipment->sync_status != "Synced" || $findShipment->shipment_status != $shipment['status']) {
																$findShipment->shipment_status = $shipment['status'];
																$findShipment->tracking_info = @$shipment['tracking']['tracking_number'];
																$findShipment->shipping_method = @$result_data['shipping_method'];
																$findShipment->carrier_code = @$shipment['tracking']['carrier'];
																$findShipment->tracking_url = @$shipment['tracking']['tracking_url'];
																$findShipment->weight = @$shipment['measurements']['total_weight_oz'];
																$findShipment->created_on = $created_on_date;
																$findShipment->save();
															}
														} else {
															$ShipmentData += [
																'user_id' => $integration->user_id,
																'platform_id' => $this->platformId,
																'user_integration_id' => $userIntegrationId,
																'platform_order_id' => $platform_order_id,
																'linked_id' => $linked_id,
																'sync_status' => 'Ready'
															];

															$sourceShipment = PlatformOrderShipment::create($ShipmentData);
															$platform_order_shipment_id = $sourceShipment->id;
															$isShipmentReady = false;

															if ($destShipment) {
																$destShipment->linked_id = $platform_order_shipment_id;
																$destShipment->sync_status = 'Ready';
																$destShipment->save();
															}
														}
													}

													if ($isShipmentReady) {
														// Make order status "Ready" only if its child shipment record status is Ready
														$this->mobj->makeUpdate('platform_order', $OrderData, ['id' => $platform_order->id]);
													}

													foreach ($shipment['products'] as $product) {
														$platform_product = $this->mobj->getFirstResultByConditions('platform_product', ['platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'manufacturer_sku' => $product['sku']], ['api_product_id', 'sku', 'barcode']);

														$sku = $product['sku'];
														$barcode = $product_id = NULL;
														if ($platform_product) {
															$barcode = $platform_product->barcode;
															$product_id = $platform_product->api_product_id;
															$sku = $platform_product->sku;
														}

														//get log code in case of array & object both
														$lotCode = null;
														$quantity = null;
														if (isset($product['inventory_items']) && isset($product['inventory_items']['lot'])) {
															$lotCode = @$product['inventory_items']['lot'];
															$quantity = @$product['inventory_items']['quantity'];
														} else if (isset($product['inventory_items']) && is_array($product['inventory_items']) && count($product['inventory_items']) > 0) {
															$lotCode = @$product['inventory_items'][0]['lot'];
															$quantity = @$product['inventory_items'][0]['quantity'];
														}

														//stored lot code in user_batch_reference for trailsend
														$ProductData = ['platform_order_shipment_id' => $platform_order_shipment_id, 'row_id' => $product['id'], 'sku' => $sku, 'quantity' => $quantity, 'warehouse_id' => @$shipment['location']['id'], 'location_id' => @$shipment['location']['id'], 'product_id' => $product_id, 'barcode' => $barcode, 'user_batch_reference' => $lotCode];

														$platform_order_shipment_line = $this->mobj->getFirstResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id' => $platform_order_shipment_id, 'row_id' => $product['id']], ['id']);
														if ($platform_order_shipment_line) {
															$this->mobj->makeUpdate('platform_order_shipment_lines', $ProductData, ['id' => $platform_order_shipment_line->id]);
														} else {
															$this->mobj->makeInsert('platform_order_shipment_lines', $ProductData);
														}
													}
													$return_response = true;
												}
											}
										}
									}
								}
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . " -> ShipBobApiController -> ReceiveShipmentWebhook -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	/* shipment backup call Event - SHIPMENTBACKUP */
	public function ShipmentBackupCall($userId = NULL, $userIntegrationId = NULL, $UserWorkFlow = NULL, $SourcePlatformName = NULL, $DestinationPlatformName = NULL, $sync_status = "Pending")
	{

		$return_response = true;
		try {
			$limit  = 10;
			$ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'access_token', 'env_type']);
			if ($ufound && $this->platformId) {
				$access_token = $this->mobj->decryptString($ufound->access_token);
				$env_type = $ufound->env_type;
				//get orders  PlatformOrder
				$platform_orders = PlatformOrder::select('id', 'order_number', 'order_status', 'api_order_id', 'shipment_status', 'linked_id')
					->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'shipment_status' => $sync_status, 'order_type' => 'SO'])->take($limit)->orderBy('order_updated_at', 'asc')->get();
				if (count($platform_orders) > 0) {
					foreach ($platform_orders as $order) {
						if (isset(\Config::get('apisettings.AllowOrderNumberCheckInShipBob')[$DestinationPlatformName])) {
							$find = PlatformOrder::where('id', $order->linked_id)->first(); //if order number not found ignore to insert record
							if (!$find) {
								continue;
							}
						}
						$platform_order_id = $order->id;
						$api_order_id = $order->api_order_id;

						//call shipment for selected order
						$response = $this->ShipBobApi->GetShipmentByOrder($access_token, $env_type, $api_order_id);
						$shipmentResp = json_decode($response, true);

						if ($shipmentResp) {

							foreach ($shipmentResp as $shipment) {

								//Fulfilled ,Completed LabeledCreated
								if ($shipment['status'] == "Completed") {

									$created_on_date = isset($shipment['actual_fulfillment_date']) && $shipment['actual_fulfillment_date'] ? $shipment['actual_fulfillment_date'] : $shipment['last_update_at'];

									//insert update shipment data
									$ShipmentData = ['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'shipment_id' => $shipment['id'], 'platform_order_id' => $platform_order_id, 'order_id' => $shipment['order_id'], 'warehouse_id' => @$shipment['location']['id'], 'shipment_status' => $shipment['status'], 'tracking_info' => @$shipment['tracking']['tracking_number'], 'carrier_code' => @$shipment['tracking']['carrier'], 'tracking_url' => @$shipment['tracking']['tracking_url'], 'weight' => @$shipment['measurements']['total_weight_oz'], 'created_on' => $created_on_date];

									$destShipment = PlatformOrderShipment::where('platform_order_id', $order->linked_id)->select('id', 'linked_id')->first();
									if ($destShipment && $destShipment->linked_id > 0) {
										$sourceShipment = PlatformOrderShipment::where('id', $destShipment->linked_id)->select('id', 'sync_status')->first();
										if ($sourceShipment) {
											if ($sourceShipment->sync_status != 'Synced') {
												$ShipmentData['sync_status'] = 'Ready';
											}
										}
										$sourceShipment->update($ShipmentData);
										$platform_order_shipment_id = $sourceShipment->id;
									} else {
										$linked_id = null;
										if ($destShipment) {
											$linked_id = $destShipment->id;
										}

										$ShipmentData += [
											'user_id' => $userId,
											'platform_id' => $this->platformId,
											'user_integration_id' => $userIntegrationId,
											'platform_order_id' => $platform_order_id,
											'linked_id' => $linked_id,
											'sync_status' => 'Ready'
										];
										$sourceShipment = PlatformOrderShipment::create($ShipmentData);
										$platform_order_shipment_id = $sourceShipment->id;

										if ($destShipment) {
											$destShipment->linked_id = $platform_order_shipment_id;
											$destShipment->sync_status = 'Ready';
											$destShipment->save();
										}
									}

									//insert update shipment line data
									foreach ($shipment['products'] as $product) {

										//get log code in case of array & object both
										$lotCode = null;
										$quantity = null;
										if (isset($product['inventory_items']) && isset($product['inventory_items']['lot'])) {
											$lotCode = @$product['inventory_items']['lot'];
											$quantity = @$product['inventory_items']['quantity'];
										} else if (isset($product['inventory_items']) && is_array($product['inventory_items']) && count($product['inventory_items']) > 0) {
											$lotCode = @$product['inventory_items'][0]['lot'];
											$quantity = @$product['inventory_items'][0]['quantity'];
										}



										$ProductData = ['platform_order_shipment_id' => $platform_order_shipment_id, 'row_id' => $product['id'], 'sku' => $product['sku'], 'quantity' => $quantity, 'warehouse_id' => @$shipment['location']['id'], 'location_id' => @$shipment['location']['id'], 'product_id' => $product['id'], 'user_batch_reference' => $lotCode];


										$platform_order_shipment_line = $this->mobj->getFirstResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id' => $platform_order_shipment_id, 'row_id' => $product['id']], ['id']);
										if ($platform_order_shipment_line) {
											$this->mobj->makeUpdate('platform_order_shipment_lines', $ProductData, ['id' => $platform_order_shipment_line->id]);
										} else {
											$this->mobj->makeInsert('platform_order_shipment_lines', $ProductData);
										}
									}


									//update order updated at
									if ($order->shipment_status != 'Synced') {
										$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Ready', 'order_updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_order_id]);
									} else {
										$this->mobj->makeUpdate('platform_order', ['order_updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_order_id]);
									}
								}

								//update order updated at
								$this->mobj->makeUpdate('platform_order', ['order_updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_order_id]);
							}
						} else {
							$this->mobj->makeUpdate('platform_order', ['order_updated_at' => date('Y-m-d H:i:s')], ['id' => $platform_order_id]);
						}
					}
				}


				return $return_response;
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "--SHIPMENTBACKUP-->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	public function preparePostData($userIntegrationId, $order, $SourcePlatformName, $location, $default_shipping_method)
	{
		$nolines = false;
		//Default mappings

		/*----------------Start to find order shipping method----------------*/
		$sales_order_shipping_method = $this->mapping->getMappedDataByName($userIntegrationId, null, "sorder_shipping_method", ['api_id'], 'regular', $order->shipping_method, "single", "source");
		if ($sales_order_shipping_method) {
			$shippingMethodId = $sales_order_shipping_method->api_id;
		} else if ($sales_order_shipping_method = $this->mapping->getMappedDataByName($userIntegrationId, null, "sorder_shipping_method", ['name'], 'regular', $order->shipping_method, "single", "source", ['api_id'])) {
			$shippingMethodId = $sales_order_shipping_method->api_id;
		} else if ($sales_order_shipping_method = $this->mapping->getMappedDataByName($userIntegrationId, null, "sorder_shipping_method", ['api_id'], 'regular', $order->shipping_method, "single", "destination")) {
			$shippingMethodId = $sales_order_shipping_method->api_id;
		} else if ($sales_order_shipping_method = $this->mapping->getMappedDataByName($userIntegrationId, null, "sorder_shipping_method", ['name'], 'regular', $order->shipping_method, "single", "destination", ['api_id'])) {
			$shippingMethodId = $sales_order_shipping_method->api_id;
		} else {
			$shippingMethodId = $default_shipping_method;
		}
		/*----------------End to find order shipping method----------------*/

		$default_location = $location;
		/*----------------Start to find order warehouse----------------*/
		$OrderlocationId = null;
		// $location_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $order->warehouse_id, 'status' => 1], ['api_id']);
		// if ($location_object_data) {
		// 	$warehouseId = $this->mapping->getMappedDataByName($userIntegrationId, null, "sorder_location", ['api_id'], 'regular', $location_object_data->api_id);
		// 	if ($warehouseId) {
		// 		$OrderlocationId = $warehouseId->api_id;
		// 	} else {
		// 		$OrderlocationId = $default_location;
		// 	}
		// } else {
		$OrderlocationId = $default_location;
		//}
		/*----------------End to find order warehouse----------------*/



		$order_shipping_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $order->id, 'address_type' => 'shipping'], ['address_name', 'firstname', 'lastname', 'address1', 'address2', 'city', 'state', 'postal_code', 'country', 'phone_number', 'email', 'company']);

		//prepare post data for create sales order
		$payload = [];
		$payload['shipping_method'] = $shippingMethodId;
		$payload['recipient']['name'] = @$order_shipping_address->address_name;

		//Enum: "MarkFor" "ShipFrom"
		$payload['recipient']['address']['type'] = 'MarkFor';
		$payload['recipient']['address']['address1'] = @$order_shipping_address->address1;
		/* Added address2 if available */
		if (isset($order_shipping_address->address2) && !empty($order_shipping_address->address2)) {
			$payload['recipient']['address']['address2'] = @$order_shipping_address->address2;
		}
		$payload['recipient']['address']['city'] = @$order_shipping_address->city;
		$payload['recipient']['address']['state'] = @$order_shipping_address->state;
		$payload['recipient']['address']['zip_code'] = @$order_shipping_address->postal_code;
		$payload['recipient']['address']['country'] = @$order_shipping_address->country;
		$payload['recipient']['email'] = @$order_shipping_address->email;
		$payload['recipient']['phone_number'] = @$order_shipping_address->phone_number;

		//Product line items
		$items_posting = [];
		$platform_order_lines = PlatformOrderLine::select('id', 'api_product_id', 'product_name', 'sku', 'qty', 'price', 'unit_price', 'ean', 'gtin', 'upc', 'mpn', 'total', 'total_tax', 'row_type', 'item_row_sequence', 'description', 'notes')->where(['platform_order_id' => $order->id, 'is_deleted' => 0])->orderBy('item_row_sequence', 'asc')->orderBy('id', 'asc')->orderBy('row_type', 'asc')->get();

		//$platform_order_lines = $this->mobj->getResultByConditions('platform_order_line', ['platform_order_id' => $order->id, 'is_deleted' => 0], ['id', 'api_product_id', 'product_name', 'sku', 'qty', 'price', 'unit_price', 'ean', 'gtin', 'upc', 'mpn', 'total', 'total_tax', 'row_type', 'item_row_sequence', 'description', 'notes'], ['item_row_sequence' => 'asc', 'id' => 'asc', 'row_type' => 'asc']);

		if (count($platform_order_lines) > 0) {
			foreach ($platform_order_lines as $platform_order_line) {
				if ($platform_order_line->row_type == 'ITEM') {
					if ($platform_order_line->qty) {

						$line['reference_id'] = $platform_order_line->sku;
						$line['quantity'] = $platform_order_line->qty;
						$line['name'] = $platform_order_line->product_name;
						$line['sku'] = $platform_order_line->sku;
						$line['unit_price'] = (int) $platform_order_line->unit_price;


						$items_posting[] = $line;
					}
				}
			}
		} else {
			$nolines = true;
		}


		$payload['products'] = $items_posting;


		//Unique and immutable order identifier from your upstream system....if got error then make this diffrent
		$payload['reference_id'] = $order->api_order_id;
		$payload['order_number'] = $order->order_number;
		if (isset(\Config::get('apisettings.makeOrderStatusReadyInShipbob')[$SourcePlatformName])) {
			$payload['reference_id'] = $order->order_number;
			$payload['order_number'] = trim($order->api_order_reference) ? trim($order->api_order_reference) : $order->order_number;
		}

		$payload['type'] = 'DTC';

		//if location mapping found then send otherwise ignore
		if ($OrderlocationId) {
			$payload['location_id'] = $OrderlocationId;
		}


		//gift msg
		if ($order->order_number && $order->order_number) {
			$payload['gift_message'] = '"Order#:' . $order->order_number . ' and Reference#:' . $order->api_order_id . '"';
		}

		$so_payload = json_encode($payload);
		if (!$nolines) {
			return $so_payload;
		} else {
			return $nolines;
		}
	}

	/* Create Sales Orders */
	public function CreateSalesOrders($userId = NULL, $userIntegrationId = NULL, $WorkFlowID = NULL, $UserWorkFlow = NULL, $SourcePlatformName = NULL, $RecordID = NULL)
	{
		$return_response = false;
		try {
			$limit = 20;
			$object_id = $this->conn->getObjectId('sales_order');
			$SourcePlatformId = $this->conn->getPlatformIdByName($SourcePlatformName);

			$platform_account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'access_token', 'env_type']);

			if ($platform_account) {

				$access_token = $this->mobj->decryptString($platform_account->access_token);
				$env_type = $platform_account->env_type;

				$default_channel = null;
				$Default_channel_mapping = $this->mapping->getMappedDataByName($userIntegrationId, NULL, "sorder_channel", ['api_id']);
				if ($Default_channel_mapping) {
					$default_channel = $Default_channel_mapping->api_id;
				}

				/* Default Warehouse ID */
				// $DefaultOrderWarehouseId = NULL;
				// $DefaultWarehouseId = $this->mapping->getMappedDataByName($userIntegrationId, NULL, "order_warehouse", ['api_id']);
				// if($DefaultWarehouseId)
				// {
				// 	$DefaultOrderWarehouseId = $DefaultWarehouseId->api_id;
				// }
				/* Default Shipping Method */
				$default_shipping_method = NULL;
				$shipping_mapping = $this->mapping->getMappedDataByName($userIntegrationId, $WorkFlowID, "sorder_shipping_method", ['api_id']);
				if ($shipping_mapping) {
					$default_shipping_method = $shipping_mapping->api_id;
				}
				/* Default Location Method */
				$default_location = null;
				$location_mapping = $this->mapping->getMappedDataByName($userIntegrationId, $WorkFlowID, "sorder_location", ['api_id']);
				if ($location_mapping) {
					$default_location = $location_mapping->api_id;
				}

				// $source_row_data = $destination_row_data = 'sku';
				// $product_identity_obj_id = $this->conn->getObjectId('product_identity');
				// $mapping_data = $this->mapping->getMappedField($userIntegrationId, NULL, $product_identity_obj_id);

				// if($mapping_data)
				// {
				// 	$source_row_data = $destination_row_data = 'sku';
				// 	if($mapping_data['destination_platform_id'] == 'trailsend')
				// 	{
				// 		$destination_row_data = $mapping_data['destination_row_data'];
				// 		$source_row_data = $mapping_data['source_row_data'];
				// 	}
				// 	else
				// 	{
				// 		$destination_row_data = $mapping_data['source_row_data'];
				// 		$source_row_data = $mapping_data['destination_row_data'];
				// 	}
				// }

				//DB::table('platform_order')->
				$query = PlatformOrder::select('id', 'order_number', 'order_date', 'total_discount', 'total_tax', 'discount_tax', 'total_amount', 'notes', 'linked_id', 'shipping_total', 'shipping_tax', 'carrier_code', 'warehouse_id', 'order_update_status', 'currency', 'shipping_method', 'payment_date', 'delivery_date', 'is_voided', 'net_amount', 'trading_partner_id', 'api_order_reference', 'order_status', 'api_order_id', 'attempt');
				if ($RecordID) {
					$query->where('id', $RecordID);
				} else {
					$query->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $SourcePlatformId])
						->whereIn('sync_status', ['Ready', 'Failed'])->where('attempt', '<=', 2);
				}

				$platform_orders = $query->where('order_type', 'SO')->where('linked_id', 0)->where('is_voided', 0)
					//resync attempt only 2 times

					->take($limit)
					->orderBy('updated_at', 'asc')->get();
				if (count($platform_orders) > 0) {

					foreach ($platform_orders as $order) {
						$postData = $this->preparePostData($userIntegrationId, $order, $SourcePlatformName, $default_location, $default_shipping_method);

						if (!is_bool($postData)) {


							\Storage::append(date('Y-m-d') . '/zyx_shipbob_CreateSalesOrders.txt', "\n\r" . 'post data: ' . print_r($postData, true));
							$response = $this->ShipBobApi->createSalesOrder($access_token, $postData, $env_type, $default_channel);
							\Storage::append(date('Y-m-d') . '/zyx_shipbob_CreateSalesOrders.txt', 'response data: ' . print_r($response, true));
							$result = json_decode($response, true);

							if (isset($result['id'])) {
								$orderInfo = [
									'user_id' => $userId,
									'platform_id' => $this->platformId,
									'user_integration_id' => $userIntegrationId,
									'order_type' => "SO",
									'api_order_id' => $result['id'],
									'api_order_reference' => $result['order_number'],
									'order_date' => date("Y-m-d H:i:s"),
									'order_number' => $order->order_number,
									'sync_status' => 'Pending',
									'linked_id' => $order->id,
									'shipment_status' => "Pending",
									'created_at' => date("Y-m-d H:i:s"),
									'updated_at' => date("Y-m-d H:i:s"),
									'order_updated_at' => date("Y-m-d H:i:s"),
									'user_workflow_rule_id' => $UserWorkFlow,
									'linked_api_order_id' => isset($result['shipments'][0]['id']) ? $result['shipments'][0]['id'] : null,
								];

								if (isset(\Config::get('apisettings.makeOrderStatusReadyInShipbob')[$SourcePlatformName])) {
									$orderInfo["order_number"] = $order->api_order_reference;
									$orderInfo["sync_status"] = 'Ready';
								}

								$OrderLinked = $this->mobj->makeInsertGetId('platform_order', $orderInfo);

								$this->mobj->makeUpdate('platform_order', ['linked_id' => $OrderLinked, 'sync_status' => 'Synced'], ['id' => $order->id]);

								$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $order->id, NULL);

								$return_response = true;
							} else {

								if ($result) {
									foreach ($result as $result_line) {
										$errorMsg = $result_line[0];
									}
								} else {
									$errorMsg = 'Product not created';
								}


								$this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed', 'attempt' => $order->attempt + 1], ['id' => $order->id]);

								$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order->id, $errorMsg);


								$return_response = $errorMsg;
							}
						} else {

							$errorMsg = 'No order product lines found';
							$this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $order->id]);
							$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order->id, $errorMsg);
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . ' - ShipBobApiController - CreateSalesOrders - ' . $e->getLine() . ' - ' . $e->getMessage());
			$return_response = $e->getMessage();
		}

		return $return_response;
	}

	public function prepareProductPostData($products)
	{
		$itemPostArr = $syncedPfProdIds = $processedSku = [];
		foreach ($products as $key => $product) {
			if ($product->linked_id > 0) {
				// We don't process items for update, if already synced then just update the sync status as "Synced'.
				$syncedPfProdIds[] = $product->id;
				continue;
			}

			$item['reference_id'] = $product->sku;
			$item['sku'] = $product->sku;
			$item['name'] = $product->sku;
			$item['unit_price'] = 0;

			if ($product->product_name) {
				$item['name'] = trim($product->product_name);
			}
			if ($product->barcode) {
				$item['barcode'] = trim($product->barcode);
			}
			if ($product->gtin) {
				$item['gtin'] = trim($product->gtin);
			}
			if ($product->upc) {
				$item['upc'] = trim($product->upc);
			}
			if ($product->price) {
				$item['unit_price'] = (int)$product->unit_price;
			}

			$processedSku[$product->id] = $product->sku;
			$itemPostArr[] = $item;
		}

		$itemPayload = "";
		if (count($itemPostArr)) {
			$itemPayload = json_encode($itemPostArr);
		}
		return [$itemPayload, $processedSku, $syncedPfProdIds];
	}

	public function CreateProducts($userId = NULL, $userIntegrationId = NULL, $sourcePlatformName = NULL, $userWorkFlowRuleId = NULL,  $recordId = NULL)
	{
		$returnResponse = true;
		try {
			$limit = 20;
			$objectId = $this->conn->getObjectId('product');
			$sourcePlatformId = $this->conn->getPlatformIdByName($sourcePlatformName);

			$platformAccount = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'access_token', 'env_type']);
			if ($platformAccount) {
				$access_token = $this->mobj->decryptString($platformAccount->access_token);
				$envType = $platformAccount->env_type;

				// get channel mapping
				$defaultChannel = null;
				$defaultChannelMapping = $this->mapping->getMappedDataByName($userIntegrationId, NULL, "sorder_channel", ['api_id']);
				if ($defaultChannelMapping) {
					$defaultChannel = $defaultChannelMapping->api_id;
				}

				$prodQuery = PlatformProduct::where([
					'platform_id' => $sourcePlatformId,
					'user_integration_id' => $userIntegrationId,
					'is_deleted' => 0
				]);
				$prodQuery->whereNotNull('sku')->where('sku', '!=', '');
				if ($recordId) {
					$prodQuery->where('id', $recordId);
				} else {
					$prodQuery->where('product_sync_status', "Ready");
				}
				$products = $prodQuery->select('id', 'sku', 'product_name', 'barcode', 'gtin', 'upc', 'price')->limit($limit)->get();

				if ($products->count()) {
					$resPostFormat = $this->prepareProductPostData($products);
					if (!is_bool($resPostFormat) && count($resPostFormat)) {

						if (!$resPostFormat[0]) {
							return "Post request body is empty.";
						}

						if (!empty($resPostFormat[2]) && count($resPostFormat[2])) { // Already synced product ids in array
							PlatformProduct::whereIn('id', $resPostFormat[2])->update(['product_sync_status' => 'Synced']);
						}
						// '{"ReferenceId":["Cannot insert duplicate reference ids: MBC-1333"]}'; //

						$response = $this->ShipBobApi->createProduct($access_token, $resPostFormat[0], $envType, $defaultChannel);
						$result = json_decode($response, true);
						\Storage::append(date('Y-m-d') . '/zyx_shipbob_createProduct.txt', 'response: ' . print_r($result, true));
						if ($result && is_array($result) && count($result) && isset($result[0]['id'])) {
							foreach ($result as $key => $item) {
								if (isset($item['id'])) {
									$itemStoreRes = $this->StoreItemDetails($userId, $userIntegrationId, $sourcePlatformId, $item);
									if ($itemStoreRes && is_numeric($itemStoreRes)) {
										$this->log->syncLog($userId, $userIntegrationId, $userWorkFlowRuleId, $sourcePlatformId, $this->platformId, $objectId, 'success', $itemStoreRes, NULL);
									} else {
										$errorMsg = 'Error while store product details.';
										if (is_string($itemStoreRes)) {
											$errorMsg = $itemStoreRes;
										} else if (is_array($itemStoreRes) || is_object($itemStoreRes)) {
											$errorMsg = json_encode($itemStoreRes);
										}
										$this->log->syncLog($userId, $userIntegrationId, $userWorkFlowRuleId, $sourcePlatformId, $this->platformId, $objectId, 'failed', $itemStoreRes, $errorMsg);
									}
								}
							}
						} else {
							$errorMsg = 'Invalid request or mismatch account credentials';
							$errorHandled = false;
							if ($result && is_array($result)) {
								if (isset($result['errors'])) {
									$arrErrorMsg = static::splitErrorMsg($result['errors']);
									$processedItems = $resPostFormat[1];
									$failedIds = [];
									foreach ($arrErrorMsg as $key => $err) {
										$errorMsg = implode(" | ", $err);

										$keys = array_keys($processedItems);
										if (isset($keys[$key])) {
											$pf_prod_id = $keys[$key];
											PlatformProduct::where('id', $pf_prod_id)->update(['product_sync_status' => 'Failed']);
											$this->log->syncLog($userId, $userIntegrationId, $userWorkFlowRuleId, $sourcePlatformId, $this->platformId, $objectId, 'failed', $pf_prod_id, $errorMsg);
											$failedIds[] = $pf_prod_id;
										}
									}

									$errorHandled = true;
								} else if ($result['ReferenceId'] && count($result['ReferenceId'])) {
									$errorMsg = implode(" | ", $result['ReferenceId']);

									if (!empty($resPostFormat[1]) && count($resPostFormat[1])) { // Processed product sku with rowid as key
										foreach ($resPostFormat[1] as $rowId => $itemSku) {
											if (str_contains($result['ReferenceId'][0], $itemSku)) {
												if (str_contains($errorMsg, 'duplicate reference id')) {
													$existing_item_res = $this->GetExistingProduct($access_token, $envType, $userId, $userIntegrationId, $sourcePlatformId, $itemSku);
													\Storage::append(date('Y-m-d') . '/zyx_shipbob_createProduct.txt', 'existing_item_res: ' . print_r($existing_item_res, true));
													if ($existing_item_res !== true) {
														$errorMsg = 'Item already exist in Shipbob but issue while getting it by Api call.';
														if ($existing_item_res) {
															if (is_string($existing_item_res)) {
																$errorMsg = $existing_item_res;
															} else if (is_array($existing_item_res) || is_object($existing_item_res)) {
																$errorMsg = json_encode($existing_item_res);
															}
														}
														PlatformProduct::where('id', $rowId)->update(['product_sync_status' => 'Failed']);
														$this->log->syncLog($userId, $userIntegrationId, $userWorkFlowRuleId, $sourcePlatformId, $this->platformId, $objectId, 'failed', $rowId, $errorMsg);
													} else {
														$errorMsg = true; // no error
													}
												} else {
													PlatformProduct::where('id', $rowId)->update(['product_sync_status' => 'Failed']);
													$this->log->syncLog($userId, $userIntegrationId, $userWorkFlowRuleId, $sourcePlatformId, $this->platformId, $objectId, 'failed', $rowId, $errorMsg);
												}
											}
										}

										$errorHandled = true;
									}
								}
							} else if ($result && !isset($result[0]['id'])) {
								$errorMsg = json_encode($result, true);
							}

							$returnResponse = $errorMsg;

							if (!$errorHandled) {
								foreach ($resPostFormat[1] as $pfProdId => $psku) {
									PlatformProduct::where('id', $pfProdId)->update(['product_sync_status' => 'Failed']);
									$this->log->syncLog($userId, $userIntegrationId, $userWorkFlowRuleId, $sourcePlatformId, $this->platformId, $objectId, 'failed', $pfProdId, $errorMsg);
								}
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . ' - ShipBobApiController - CreateProducts - ' . $e->getLine() . ' - ' . $e->getMessage());
			$returnResponse = $e->getMessage();
		}

		return $returnResponse;
	}

	public function GetExistingProduct($access_token, $envType, $userId, $userIntegrationId, $sourcePlatformId, $itemSku)
	{
		$returnResponse = false;
		try {
			$response = $this->ShipBobApi->GetProductByReferenceId($access_token, $envType, $itemSku);
			\Storage::append(date('Y-m-d') . '/zyx_shipbob_createProduct.txt', 'item api response: ' . print_r($response, true));
			$result = json_decode($response, true);
			if ($result && is_array($result) && count($result) && isset($result[0]['id'])) {
				foreach ($result as $key => $item) {
					if (isset($item['id'])) {
						$itemStoreRes = $this->StoreItemDetails($userId, $userIntegrationId, $sourcePlatformId, $item);
						\Storage::append(date('Y-m-d') . '/zyx_shipbob_createProduct.txt', 'itemStoreRes: ' . print_r($itemStoreRes, true));
						if ($itemStoreRes && is_numeric($itemStoreRes)) {
							$returnResponse = true;
						} else {
							$errorMsg = 'Error while store product details.';
							if (is_string($itemStoreRes)) {
								$errorMsg = $itemStoreRes;
							} else if (is_array($itemStoreRes) || is_object($itemStoreRes)) {
								$errorMsg = json_encode($itemStoreRes);
							}
							$returnResponse = $errorMsg;
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . ' - ShipBobApiController - GetExistingProduct - ' . $e->getLine() . ' - ' . $e->getMessage());
			$returnResponse = $e->getMessage();
		}

		return $returnResponse;
	}

	public function StoreItemDetails($userId, $userIntegrationId, $sourcePlatformId, $item)
	{
		$returnResponse = false;
		try {
			$sourceProd = PlatformProduct::where([
				'user_integration_id' => $userIntegrationId,
				'platform_id' => $sourcePlatformId,
				'sku' => trim($item['sku'])
			])->first();

			if ($sourceProd) {
				$productData = [
					'sku' => $item['sku'] ?? null,
					'product_name' => $item['name'] ?? null,
					'barcode' => $item['barcode'] ?? null,
					'gtin' => $item['gtin'] ?? null,
					'upc' => $item['upc'] ?? null,
					'price' => $item['unit_price'] ?? null,
					'product_sync_status' => 'Synced'
				];

				if ($sourceProd->linked_id === 0) {
					$productData += [
						'user_id' => $userId,
						'user_integration_id' => $userIntegrationId,
						'platform_id' => $this->platformId,
						'api_product_id' => $item['id'],
						'linked_id' => $sourceProd->id,
					];
					$destProd = PlatformProduct::create($productData);
					$sourceProd->linked_id = $destProd->id;
				} else {
					$destProd = PlatformProduct::find($sourceProd->linked_id)->update($productData);
				}
				\Storage::append(date('Y-m-d') . '/zyx_shipbob_createProduct.txt', 'destProd: ' . print_r($destProd, true));
				$sourceProd->product_sync_status = 'Synced';
				$sourceProd->save();
				$returnResponse = $sourceProd->id;

			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . ' - ShipBobApiController - StoreItemDetails - ' . $e->getLine() . ' - ' . $e->getMessage());
			$returnResponse = $e->getMessage();
		}

		return $returnResponse;
	}

	private static function splitErrorMsg($errors)
	{
		$errorMessages = [];

		foreach ($errors as $key => $error) {
			$pattern = '/\[(.*?)\]/'; // Regular expression pattern to match anything inside square brackets

			if (preg_match($pattern, $key, $matches)) {
				$position = $matches[1]; // Extract the value inside the square brackets
				$errorMessage = implode(', ', $error); // Combine error messages into a comma-separated string

				$errorMessages["$position"][] = $errorMessage;
			}
		}
		return $errorMessages;
	}
	/* Execute ShipBob Event Methods */
	public function ExecuteShipBobEvents($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = '')
	{
		$response = true;
		if ($method == 'GET' && $event == 'CHANNEL') {
			$response = $this->GetChannels($user_id, $user_integration_id);
		} elseif ($method == 'GET' && $event == 'SHIPPINGMETHOD') {
			$response = $this->GetShippingMethods($user_id, $user_integration_id);
		} elseif ($method == 'GET' && $event == 'LOCATION') {
			$response = $this->GetLocations($user_id, $user_integration_id);
		} elseif ($method == 'GET' && $event == 'WAREHOUSE') {
			$response = $this->GetWarehouses($user_id, $user_integration_id);
		} elseif ($method == 'GET' && $event == 'SALESORDER') {
			$response = $this->GetShipBobOrders($user_id, $user_integration_id, $platform_workflow_rule_id);
		} elseif ($method == 'GET' && $event == 'INVENTORY') {
			$response = $this->GetShipBobInventory($user_id, $user_integration_id);
		} elseif ($method == 'GET' && $event == 'PRODUCT') {
			$response = $this->GetShipBobProduct($user_id, $user_integration_id);
		} elseif ($method == 'GET' && $event == 'SHIPMENT') {
			//To get created order_shipped webhook
			$response = $this->GetShipment($user_id, $user_integration_id, ['shipment'], 1, $is_initial_sync);
		} elseif ($method == 'MUTATE' && $event == 'SALESORDER') {
			$response = $this->CreateSalesOrders($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, $record_id);
		} elseif ($method == 'MUTATE' && $event == 'PRODUCT') {
			$response = $this->CreateProducts($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $record_id);
		} else if ($method == 'GET' && $event == 'SHIPMENTBACKUP') {
			if (!$is_initial_sync) {
				$response = $this->ShipmentBackupCall($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, "Pending");
			}
		} elseif ($method == 'GET' && $event == 'DBSALESORDER') {
			$response = true;
		}

		return $response;
	}

	//get shipment for test only by order primary id
	public function get_Shipment_for_testing($userId = NULL, $userIntegrationId = NULL, $record_id)
	{

		$return_response = true;
		try {
			$limit  = 1;
			$ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'access_token', 'env_type']);
			if ($ufound && $this->platformId) {
				$access_token = $this->mobj->decryptString($ufound->access_token);
				$env_type = $ufound->env_type;

				//get orders  PlatformOrder
				$platform_orders = PlatformOrder::select('id', 'order_number', 'order_status', 'api_order_id', 'shipment_status', 'linked_id')->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'id' => $record_id])->take($limit)->orderBy('order_updated_at', 'asc')->get();

				if (count($platform_orders) > 0) {

					foreach ($platform_orders as $order) {

						$platform_order_id = $order->id;
						$api_order_id = $order->api_order_id;

						//call shipment for selected order
						$response = $this->ShipBobApi->GetShipmentByOrder($access_token, $env_type, $api_order_id);
						$shipmentResp = json_decode($response, true);
					}
				}


				return $return_response;
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "--SHIPMENTBACKUP-->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	//test-shipbob
	public function test()
	{
		// $platform_account = $this->mobj->getPlatformAccountByUserIntegration(309, 29, ['id', 'access_token', 'env_type']);

		// 	$order_response = $this->ShipBobApi->GetOrderByID($this->mobj->decryptString($platform_account->access_token), $platform_account->env_type, $shipment['order_id']);

		// 	$Order = json_decode($order_response, true);

	}
}
