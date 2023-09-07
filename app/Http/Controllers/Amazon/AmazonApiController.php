<?php

namespace App\Http\Controllers\Amazon;

use App\Http\Controllers\Controller;
use Auth;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\Api\AmazonApi;
use App\Helper\Api\BrightpearlApi;
use App\Helper\Logger;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\WorkflowSnippet;

// use function GuzzleHttp\json_decode;

use Illuminate\Support\Facades\Session;
use Lang, Validator;
use Illuminate\Support\Facades\Storage;

use App\Models\PlatformOrder;
use App\Models\PlatformOrderTransaction;
use DB;
// use Illuminate\Support\Facades\DB;

use DateTimeImmutable;

class AmazonApiController extends Controller
{
	/**
	 * Create a new controller instance.
	 *
	 * @return void
	 */
	public $wfsnip, $mobj, $bp, $helper, $platformId, $map, $log, $amz;
	public static $my_platform_name = 'amazonvendor';
	public function __construct()
	{
		$this->wfsnip = new WorkflowSnippet();
		$this->bp = new BrightpearlApi;
		$this->mobj = new MainModel();
		$this->amz = new AmazonApi();
		$this->log = new Logger();
		$this->map = new FieldMappingHelper();
		$this->helper = new ConnectionHelper();
		$this->platformId = $this->helper->getPlatformIdByName(self::$my_platform_name);
	}

	public function initiateAmazonAuth(Request $request)
	{
		$platform = self::$my_platform_name;
		// return view("pages.apiauth.amazon_basic_auth", compact('platform'));
		return view("pages.apiauth.amazon_auth", compact('platform'));
	}

	public function submitAmazonAuth(Request $request)
	{
		$validator = Validator::make($request->all(), ['account_name' => 'required', 'marketplace_id' => 'required']);

		if ($this->mobj->checkHtmlTags($request->all())) {
			return back()->with('error', Lang::get('tags.validate'));
		}

		if ($validator->fails()) {
			return back()->withErrors($validator);
		} else {
			$account_name = trim($request->account_name);

			$platform_id = $this->platformId;

			$platform_name = trim($request->platform_name);
			if ($platform_name) {
				$platform_id = $this->helper->getPlatformIdByName($platform_name);
			}

			//to check whether given account is already in use or not.
			$checkExistingAccount = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $platform_id, 'account_name' => $account_name], ['user_id']);
			if ($checkExistingAccount) {
				return back()->with('error', 'Given details are already in use, Try with other details.');
			}

			$platform_api_app = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $platform_id], ['app_ref']);
			if ($platform_api_app) {
				$redirect_uri = $this->mobj->makeUrlHttpsForProd(url('/ConnectAmazonAuth'));

				$marketplace_id = trim($request->marketplace_id);
				$env_type = trim($request->env_type);

				$application_id = $this->mobj->encrypt_decrypt($platform_api_app->app_ref, 'decrypt');
				if ($application_id) {
					$dyn_url = null;
					$region = null;



					//North America
					if ($marketplace_id == "A2EUQ1WTGCTBG2") {
						//canada
						$dyn_url = 'https://sellercentral.amazon.ca';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.ca';
						}
						$region = "us-east-1";
					} elseif ($marketplace_id == "ATVPDKIKX0DER") {
						//usa
						$dyn_url = 'https://sellercentral.amazon.com';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.com';
						}
						$region = "us-east-1";
					} elseif ($marketplace_id == "A1AM78C64UM0Y8") {
						//maxico
						$dyn_url = 'https://sellercentral.amazon.com.mx';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.com.mx';
						}
						$region = "us-east-1";
					} elseif ($marketplace_id == "A2Q3Y263D00KWC") {
						//brazil
						$dyn_url = 'https://sellercentral.amazon.com.br';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.com.br';
						}
						$region = "us-east-1";
					}
					//Europe
					elseif ($marketplace_id == "A1RKKUPIHCS9HS" || $marketplace_id == "A1F83G8C2ARO7P" || $marketplace_id == "A13V1IB3VIYZZH" || $marketplace_id == "A1PA6795UKMFR9" || $marketplace_id == "APJ6JRA9NG5V4") {
						//Spain || UK || France || Germany || Italy
						$dyn_url = 'https://sellercentral-europe.amazon.com';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral-europe.amazon.com';
						}
						$region = "eu-west-1";
					} elseif ($marketplace_id == "A1805IZSGTT6HS") {
						//Netherlands
						$dyn_url = 'https://sellercentral.amazon.nl';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.nl';
						}
						$region = "eu-west-1";
					} elseif ($marketplace_id == "A2NODRKZP88ZB9") {
						//Sweden
						$dyn_url = 'https://sellercentral.amazon.se';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.se';
						}
						$region = "eu-west-1";
					} elseif ($marketplace_id == "A1C3SOZRARQ6R3") {
						//Poland
						$dyn_url = 'https://sellercentral.amazon.pl';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.pl';
						}
						$region = "eu-west-1";
					} elseif ($marketplace_id == "ARBP9OOSHTCHU") {
						//Egypt
						$dyn_url = 'https://sellercentral.amazon.eg';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.eg';
						}
						$region = "eu-west-1";
					} elseif ($marketplace_id == "A33AVAJ2PDY3EV") {
						//Turkey
						$dyn_url = 'https://sellercentral.amazon.com.tr';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.com.tr';
						}
						$region = "eu-west-1";
					} elseif ($marketplace_id == "A17E79C6D8DWNP") {
						//Saudi Arabia
						$dyn_url = 'https://sellercentral.amazon.com.sa';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.com.sa';
						}
						$region = "eu-west-1";
					} elseif ($marketplace_id == "A2VIGQ35RCS4UG") {
						//U.A.E.
						$dyn_url = 'https://sellercentral.amazon.ae';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.ae';
						}
						$region = "eu-west-1";
					} elseif ($marketplace_id == "A21TJRUUN4KGV") {
						//India
						$dyn_url = 'https://sellercentral.amazon.in';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.in';
						}
						$region = "eu-west-1";
					}
					//Far East
					elseif ($marketplace_id == "A19VAU5U5O7RUS") {
						//Singapore
						$dyn_url = 'https://sellercentral.amazon.sg';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.sg';
						}
						$region = "us-west-2";
					} elseif ($marketplace_id == "A39IBJ37TRP1C6") {
						//Australia
						$dyn_url = 'https://sellercentral.amazon.com.au';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.com.au';
						}
						$region = "us-west-2";
					} elseif ($marketplace_id == "A1VC38T7YXB528") {
						//Japan
						$dyn_url = 'https://sellercentral.amazon.co.jp';
						if ($platform_name == "amazonvendor") {
							$dyn_url = 'https://vendorcentral.amazon.co.jp';
						}
						$region = "us-west-2";
					}

					if ($dyn_url) {
						$state_i = Auth::user()->id . '~' . $platform_id . '~' . $account_name . '~' . $marketplace_id . '~' . $region . '~' . $env_type;

						$url = $dyn_url . '/apps/authorize/consent?application_id=' . $application_id . '&redirect_uri=' . $redirect_uri . '&state=' . $state_i;
						return redirect($url);
					} else {
						Session::put('auth_msg', 'Please select correct marketplace');
						echo '<script>window.close();</script>';
					}
				} else {
					Session::put('auth_msg', 'App config not found');
					echo '<script>window.close();</script>';
				}
			} else {
				$this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"</a>');
			}
		}
	}

	public function connectAmazonAuth(Request $request)
	{
		date_default_timezone_set('UTC');

		if (isset($request->spapi_oauth_code)) {
			//$code = $_GET['spapi_oauth_code'];
			//$state = trim(stripslashes(htmlspecialchars($_GET['state'])));
			//$state_i = Auth::user()->id.'~'.$platform_id.'~'.$account_name.'~'.$marketplace_id.'~'.$region.'~'.$env_type;
			$state = $request->state;
			$state_arr = explode('~', $state);

			$user_id = $state_arr[0];
			$platform_id = $state_arr[1];
			$account_name = $state_arr[2];
			$marketplace_id = $state_arr[3];
			$region = $state_arr[4];
			$env_type = $state_arr[5];

			$platform_api_app = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $platform_id], ['app_ref', 'client_id', 'client_secret']);
			if ($platform_api_app) {
				$code = $request->spapi_oauth_code;
				$client_id = $this->mobj->encrypt_decrypt($platform_api_app->client_id, 'decrypt');
				$client_secret = $this->mobj->encrypt_decrypt($platform_api_app->client_secret, 'decrypt');
				$redirect_url = $this->mobj->makeUrlHttpsForProd(url('/ConnectAmazonAuth'));

				$response = $this->amz->getAmazonAccessToken($code, $client_id, $client_secret, $redirect_url);

				$result = json_decode($response->getBody(), true);
				if (isset($result['access_token'])) {
					$env_prefix = ($env_type == 'sandbox') ? 'sandbox.' : '';

					//find endpoint
					if ($region == 'us-east-1') {
						$endpoint = $env_prefix . 'sellingpartnerapi-na.amazon.com';
					} elseif ($region == 'eu-west-1') {
						$endpoint = $env_prefix . 'sellingpartnerapi-eu.amazon.com';
					} elseif ($region == 'us-west-2') {
						$endpoint = $env_prefix . 'sellingpartnerapi-fe.amazon.com';
					}

					//store amazon credential
					DB::table('platform_accounts')->insert(['user_id' => $user_id, 'platform_id' => $platform_id, 'account_name' => $account_name, 'refresh_token' => $this->mobj->encrypt_decrypt($result['refresh_token'], 'encrypt'), 'access_token' => $this->mobj->encrypt_decrypt($result['access_token'], 'encrypt'), 'region' => $region, 'marketplace_id' => $this->mobj->encrypt_decrypt($marketplace_id, 'encrypt'), 'api_domain' => $endpoint, 'token_refresh_time' => time(), 'env_type' => $env_type, 'token_type' => $result['token_type'], 'expires_in' => $result['expires_in']]);

					echo '<script>window.close();</script>';
				} elseif (isset($result['error_description'])) {
					$error = $result['error_description'];
					echo '<script>alert("' . $error . '");window.close();</script>';
				}
			} else {
				$this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
			}
		} else {
			$this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
		}
	}

	public function connectAmazonBasicAuth(Request $request)
	{
		//server side validation
		$request->validate(['amazon_account_name' => 'required', 'access_key' => 'required', 'secret_key' => 'required', 'role_arn' => 'required', 'region' => 'required', 'market_place_id' => 'required', 'client_id' => 'required', 'client_secret' => 'required', 'refresh_token' => 'required']);

		$account_name = trim($request->amazon_account_name);
		$access_key = trim($request->access_key);
		$secret_key = trim($request->secret_key);
		$role_arn = trim($request->role_arn);
		$region = trim($request->region);
		$market_place_id = trim($request->market_place_id);
		$client_id = trim($request->client_id);
		$client_secret = trim($request->client_secret);
		$refresh_token = trim($request->refresh_token);
		$env_type = trim($request->env_type);

		$platform_id = $this->platformId;

		$platform_name = trim($request->platform_name);
		if ($platform_name) {
			$platform_id = $this->helper->getPlatformIdByName($platform_name);
		}

		$data = [];

		if ($this->mobj->checkHtmlTags($request->all())) {
			$data['status_code'] = 0;
			$data['status_text'] = Lang::get('tags.validate');
			return json_encode($data);
		}

		try {
			$platform_account = $this->mobj->getFirstResultByConditions('platform_accounts', ['access_key' => $this->mobj->encrypt_decrypt($access_key, 'encrypt'), 'secret_key' => $this->mobj->encrypt_decrypt($secret_key, 'encrypt'), 'platform_id' => $platform_id], ['user_id']);
			if ($platform_account) {
				$data['status_code'] = 0;
				$data['status_text'] = 'Given details are already in use, Try with other details.';
				return json_encode($data);
			}

			$platform_account = $this->mobj->getFirstResultByConditions('platform_accounts', ['user_id' => Auth::user()->id, 'account_name' => $account_name, 'platform_id' => $platform_id], ['id']);
			$flag = true;
			if (is_null($platform_account)) {
				//check account env type .
				if ($env_type == 'on') {
					$env_type = 'production';
				} else {
					$env_type = 'sandbox';
				}

				$response = $this->amz->getAmazonRefreshToken($refresh_token, $client_id, $client_secret);

				$result = json_decode($response->getBody(), true);
				if (isset($result['access_token'])) {
					$assumeRoleCredentials = $this->amz->getAssumeRole($access_key, $secret_key, $role_arn, $region);
					if (isset($assumeRoleCredentials['SessionToken'])) {
						$sandbox = ($env_type == 'sandbox') ? 'sandbox.' : '';
						if ($region == 'us-east-1') {
							$endpoint = $sandbox . 'sellingpartnerapi-na.amazon.com';
						} elseif ($region == 'eu-west-1') {
							$endpoint = $sandbox . 'sellingpartnerapi-eu.amazon.com';
						} elseif ($region == 'us-west-2') {
							$endpoint = $sandbox . 'sellingpartnerapi-fe.amazon.com';
						}

						//store amazon credential
						DB::table('platform_accounts')->insert([
							'user_id' => Auth::user()->id,
							'platform_id' => $platform_id,
							'account_name' => $account_name,
							'refresh_token' => $this->mobj->encrypt_decrypt($result['refresh_token'], 'encrypt'), 'access_token' => $this->mobj->encrypt_decrypt($result['access_token'], 'encrypt'), 'access_key' => $this->mobj->encrypt_decrypt($access_key, 'encrypt'), 'secret_key' => $this->mobj->encrypt_decrypt($secret_key, 'encrypt'), 'role_arn' => $this->mobj->encrypt_decrypt($role_arn, 'encrypt'),
							'region' => $region,
							'marketplace_id' => $this->mobj->encrypt_decrypt($market_place_id, 'encrypt'), 'app_id' => $this->mobj->encrypt_decrypt($client_id, 'encrypt'),
							'app_secret' => $this->mobj->encrypt_decrypt($client_secret, 'encrypt'),
							'api_domain' => $endpoint,
							'token_refresh_time' => time(),
							'env_type' => $env_type,
							'token_type' => 'bearer',
							'expires_in' => '3600'
						]);
					} else {
						$flag = false;
						$data['status_code'] = 0;
						$data['status_text'] = 'Sign-in credentials is incorrect';
					}
				} else {
					$flag = false;
					$data['status_code'] = 0;
					$data['status_text'] = 'Sign-in credentials is incorrect';
				}
			} else {
				$flag = false;
				$data['status_code'] = 0;
				$data['status_text'] = 'Account name identifier is already exist with the same user, Try with another name.';
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

	function refreshTokens($id, $user_id, $platform_name)
	{
		$platform_id = $this->helper->getPlatformIdByName($platform_name);

		if ($platform_name == 'amazonvendor') {
			$platform_api_app = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $platform_id], ['client_id', 'client_secret']);
			$platform_account = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $platform_id, 'user_id' => $user_id, 'id' => $id], ['app_id', 'app_secret', 'refresh_token']);
			if ($platform_account && $platform_api_app) {
				if ($platform_account->app_id && $platform_account->app_secret) {
					$client_id = $this->mobj->encrypt_decrypt($platform_account->app_id, 'decrypt');
					$client_secret = $this->mobj->encrypt_decrypt($platform_account->app_secret, 'decrypt');
				} else {
					$client_id = $this->mobj->encrypt_decrypt($platform_api_app->client_id, 'decrypt');
					$client_secret = $this->mobj->encrypt_decrypt($platform_api_app->client_secret, 'decrypt');
				}

				$refresh_token = $this->mobj->encrypt_decrypt($platform_account->refresh_token, 'decrypt');

				$response = $this->amz->getAmazonRefreshToken($refresh_token, $client_id, $client_secret);
				$result = json_decode($response->getBody(), true);
				if (isset($result['access_token'])) {
					$this->mobj->makeUpdate('platform_accounts', ['access_token' => $this->mobj->encrypt_decrypt($result['access_token'], 'encrypt'), 'refresh_token' => $this->mobj->encrypt_decrypt($result['refresh_token'], 'encrypt'), 'token_refresh_time' => time(), 'token_type' => $result['token_type'], 'expires_in' => $result['expires_in']], ['id' => $id]);
				}

				return $response;
			}
		} elseif ($platform_name == 'amazonmcf') {
			$platform_api_app = $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id' => $platform_id], ['client_id', 'client_secret']);
			$platform_account = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $platform_id, 'user_id' => $user_id, 'id' => $id], ['refresh_token']);
			if ($platform_api_app && $platform_account) {
				$client_id = $this->mobj->encrypt_decrypt($platform_api_app->client_id, 'decrypt');
				$client_secret = $this->mobj->encrypt_decrypt($platform_api_app->client_secret, 'decrypt');
				$refresh_token = $this->mobj->encrypt_decrypt($platform_account->refresh_token, 'decrypt');

				$response = $this->amz->getAmazonRefreshToken($refresh_token, $client_id, $client_secret);
				$result = json_decode($response->getBody(), true);
				if (isset($result['access_token'])) {
					$this->mobj->makeUpdate('platform_accounts', ['access_token' => $this->mobj->encrypt_decrypt($result['access_token'], 'encrypt'), 'refresh_token' => $this->mobj->encrypt_decrypt($result['refresh_token'], 'encrypt'), 'token_refresh_time' => time(), 'token_type' => $result['token_type'], 'expires_in' => $result['expires_in']], ['id' => $id]);
				}
			}
		}

		$amazonAccount = $this->mobj->getFirstResultByConditions('platform_accounts', ['platform_id' => $platform_id, 'user_id' => $user_id, 'id' => $id]);

		return $amazonAccount;
	}

	public function storeOnetimeAmazonFulfillmentLocations()
	{

		if (($handle = fopen(public_path() . '/Amazon Warehouse code & Address (Vendor central) - Sheet1.csv', 'r')) !== FALSE) { // Check the resource is valid
			$header = [];
			$rows = [];
			$i = 0;
			while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) { // Check opening the file is OK!

				if ($i != 0) {
					$data = array_map("utf8_encode", $data);
					echo '<pre>';
					print_r($data);
					$code = trim($data[0]);
					$address = trim($data[1]);
					$city = trim($data[2]);
					$state = trim($data[3]);
					$zipcode = trim($data[4]);
					$country = trim($data[6]);

					$address_object = json_encode(['address' => $address, 'city' => $city, 'state' => $state, 'zipcode' => $zipcode, 'country' => $country], true);
					//   print_r($address_object);

					$platform = DB::table('platform_lookup')->where('platform_id', 'amazonvendor')->first();
					$get_object = DB::table('platform_objects')->where('name', 'common_address')->first();

					$check = DB::table('platform_object_data')->where('platform_object_id', $get_object->id)->where('api_code', $code)->first();
					if (is_null($check)) {
						DB::table('platform_object_data')->insert([
							'user_id' => 0, 'user_integration_id' => 0, 'platform_id' => $platform->id, 'platform_object_id' => $get_object->id,
							'api_id' => $code,
							'name' => $code,
							'api_code' => $code,
							'description' => $address_object,
							'status' => 1
						]);
					}
				}
				$i++;
			}
		}
	}



	//get purchase orders & direct fulfillment Orders
	public function CallRetailAndVenderOrders($user_id, $user_integration_id, $is_initial_sync)
	{
		\Storage::disk('local')->append('amazon_api_call_log.txt', 'CallRetailAndVenderOrders call - ' . date('Y-m-d H:i:s') . PHP_EOL);

		//get order type filter mapping
		$selected_order_type_filter = 'VendorRetail';
		$default_order_type_filter = $this->map->getMappedDataByName($user_integration_id, NULL, "order_type_filter", ['api_id']);
		if ($default_order_type_filter) {
			$selected_order_type_filter = $default_order_type_filter->api_id;
		}

		//call based on selected order type mapping
		if ($selected_order_type_filter == 'VendorDF') {
			//call Direct Fulfillment Orders
			$this->getStorePurchaseOrders($user_id, $user_integration_id, $is_initial_sync, 'DIRECT_FULLFILMENT_ORDER');
		} else if ($selected_order_type_filter == 'VendorRetail') {

			//call Retail orders
			$this->getStorePurchaseOrders($user_id, $user_integration_id, $is_initial_sync, 'PURCHASEORDER');
		} else {
			//call Retail orders
			$this->getStorePurchaseOrders($user_id, $user_integration_id, $is_initial_sync, 'PURCHASEORDER');
			//call Direct Fulfillment Orders
			$this->getStorePurchaseOrders($user_id, $user_integration_id, $is_initial_sync, 'DIRECT_FULLFILMENT_ORDER');
		}
	}
	//get purchase orders & direct fulfillment Orders
	public function getStorePurchaseOrders($user_id, $user_integration_id, $is_initial_sync, $orderType)
	{

		try {

			$limit = 50;
			$response = true;
			$platform_id = $this->platformId;

			$get_connect_account_id = $this->map->getUserIntegrationDetailsById($user_integration_id, self::$my_platform_name);


			//temporary status set to 0 make it 1 
			$get_workflow_rule = $this->mobj->getFirstResultByConditions('user_workflow_rule', ['user_integration_id' => $user_integration_id, 'status' => 0], ['id', 'platform_workflow_rule_id', 'sync_start_date']);

			$uwfrId = ($get_workflow_rule) ? $get_workflow_rule->id : NULL;
			//get from based on onetime sync logic

			//PO status filter
			$next_token = '';
			$has_next_data = false;
			$amazonToken = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'user_id', 'app_id', 'app_secret', 'refresh_token', 'access_token', 'access_key', 'secret_key', 'role_arn', 'region', 'marketplace_id', 'api_domain', 'env_type']);


			//set orderType
			$get_order_limit = NULL;
			if ($orderType == "DIRECT_FULLFILMENT_ORDER") {
				$orderTypeCode = 'SO';
				$filter_by_status = "NEW";

				//set limit if allow create shipment lable yes
				$selected_allow_label_create = 'NO';
				$default_allow_label_create = $this->map->getMappedDataByName($user_integration_id, NULL, "allow_label_create", ['api_code']);
				if ($default_allow_label_create) {
					$selected_allow_label_create = $default_allow_label_create->api_code;
				}
				if ($selected_allow_label_create == 'YES') {
					$get_order_limit = 20;
				}
				//end

			} else {
				$orderTypeCode = 'PO';
				$filter_by_status = "New";
			}


			if ($is_initial_sync == 1) {

				if ($get_workflow_rule) {
					$from_date =  date(DATE_ISO8601, strtotime($get_workflow_rule->sync_start_date));  //get start from date setup by user

					//temporary disabled do while due to from date filter is not working in php script
					do {

						//get WF order api
						if ($get_connect_account_id) {

							//get amazon purchase orders
							$result = $this->amz->GetRetailAndVenderOrders($amazonToken, $next_token, $from_date, $filter_by_status, $orderType, $get_order_limit);

							//call Log
							\Storage::disk('local')->append('amazon_api_call_log.txt', 'GetRetailAndVenderOrders call after initial for -' . $orderType . ' Total-' . json_encode($result, true) . date('Y-m-d H:i:s') . PHP_EOL);

							if ((isset($result['payload']['orders']) && (!empty($result['payload']['orders']))) || (isset($result['payload']) && (!empty($result['payload'])))) {

								//check next token for pagination
								if (isset($result['payload']['pagination']['nextToken']) && $result['payload']['pagination']['nextToken'] != '') {
									$next_token = $result['payload']['pagination']['nextToken'];
									$has_next_data = true;
								} else {
									$next_token = '';
									$has_next_data = false;
								}

								//get insert update order details
								$this->insertUpdatePODetails($user_id, $user_integration_id, $result, $uwfrId, $orderType, $amazonToken);

								// sleep(1);
							} else {
								//if data is empty or any random error
								if (isset($result['errors']['message'])) {
									$response = $result['errors']['message'];
								} else {
									if (isset($result['payload']['orders']) && (empty($result['payload']['orders'])) || (isset($result['payload']) && (!empty($result['payload'])))) {
										$response = true;
									} else {
										$response = json_encode($result, true);
									}
								}

								\Log::channel('webhook')->info("Amazon get purchase order - user" . $user_id . ">> User integration : " . $user_integration_id . " Response: " . $response . " Created Date : " . date('Y-m-d H:i:s'));
								return $response;
							}
						}
					} while ($has_next_data);  //until has next data

				} else {

					$response =  'GET Amazon Purchase Order workflow rule not found';
					\Log::channel('webhook')->info("Amazon get purchase order - user" . $user_id . ">> User integration : " . $user_integration_id . " Response: " . $response . " Created Date : " . date('Y-m-d H:i:s'));
				}
			} else {

				$get_order_date = DB::table('platform_order')->select('order_date')->where('order_type',  $orderTypeCode)->where('platform_id', $platform_id)->where('user_integration_id', $user_integration_id)->orderByRaw("DATE_FORMAT(order_date, '%Y-%m-%d %H-%i-%s') DESC")->first();


				if ($get_order_date) {
					$from_date =  date(DATE_ISO8601, strtotime($get_order_date->order_date . '+1 seconds'));
				} else {
					$from_date = date(DATE_ISO8601, strtotime(date('Y-m-d H:i:s' . '-10 minutes')));
				}

				// $from_date =  date(DATE_ISO8601, strtotime('2022-07-22 12:30:18' . '+1 seconds'));




				if ($get_connect_account_id) {

					//get amazon purchase orders
					$result = $this->amz->GetRetailAndVenderOrders($amazonToken, $next_token, $from_date, $filter_by_status, $orderType, $get_order_limit);

					//call Log
					\Storage::disk('local')->append('amazon_api_call_log.txt', 'GetRetailAndVenderOrders call after initial for -' . $orderType . ' Total-' . json_encode($result, true) . date('Y-m-d H:i:s') . PHP_EOL);

					//    echo '<pre>';
					//    print_r($result);
					//    exit;

					if ((isset($result['payload']['orders']) && (!empty($result['payload']['orders']))) || (isset($result['payload']) && (!empty($result['payload'])))) {

						//check next token for pagination
						if (isset($result['payload']['pagination']['nextToken']) && $result['payload']['pagination']['nextToken'] != '') {
							$next_token = $result['payload']['pagination']['nextToken'];
							$has_next_data = true;
						} else {
							$next_token = '';
							$has_next_data = false;
						}

						//get insert update order details
						$this->insertUpdatePODetails($user_id, $user_integration_id, $result, $uwfrId, $orderType, $amazonToken);

						sleep(1);
					} else {
						//if data is empty
						//if data is empty or any random error
						if (isset($result['errors']['message'])) {
							$response = $result['errors']['message'];
						} else {
							if (isset($result['payload']['orders']) && (empty($result['payload']['orders'])) || (isset($result['payload']) && (!empty($result['payload'])))) {
								$response = true;
							} else {
								$response = json_encode($result, true);
							}
						}

						\Log::channel('webhook')->info("Amazon get purchase order - user" . $user_id . ">> User integration : " . $user_integration_id . " Response: " . $response . " Created Date : " . date('Y-m-d H:i:s') . ' orderType-' . $orderType);
						return;
					}
				}
			}
		} catch (\Exception $e) {
			$response = $e->getMessage();
		}
		return $response;
	}
	public function insertUpdatePODetails($user_id, $user_integration_id, $orders, $uwfrId, $orderType, $amazonToken)
	{
		// echo 'abcd<pre>';
		// print_r($orders);
		if (isset($orders['payload']['orders']) || isset($orders['payload'])) {

			//if (!empty($orders['payload']['orders'])) {
			if (isset($orders['payload']['orders'])) {
				foreach ($orders['payload']['orders'] as $ord) {
					$this->insertUpdateInDB($ord, $user_id, $user_integration_id, $uwfrId, $orderType, $amazonToken);
				}
			} else if (isset($orders['payload'])) {

				$this->insertUpdateInDB($orders['payload'], $user_id, $user_integration_id, $uwfrId, $orderType, $amazonToken);
			}


			//}
		}
	}

	//function to store direct fulfillment orders & other order
	public function insertUpdateInDB($ord, $user_id, $user_integration_id, $uwfrId, $orderType, $amazonToken)
	{
		//set orderType
		if ($orderType == "DIRECT_FULLFILMENT_ORDER") {
			$orderTypeCode = 'SO';
		} else {
			$orderTypeCode = 'PO';
		}

		$arr_order = array();
		$arr_order['user_id'] = $user_id;
		$arr_order['platform_id'] = $this->platformId;
		$arr_order['platform_customer_id'] = 0;
		$arr_order['user_integration_id'] = $user_integration_id;
		$arr_order['user_workflow_rule_id'] = $uwfrId;

		//order type SO will be passed for direct fulfillment order & SO for other orders
		$arr_order['order_type'] = $orderTypeCode;
		$arr_order['api_order_id'] = @$ord['purchaseOrderNumber'];
		$arr_order['order_number'] = @$ord['purchaseOrderNumber'];


		//array to store vender party's Data
		$venderItemArray = [];

		if ($orderType == "DIRECT_FULLFILMENT_ORDER") {

			//include shipping method also
			$arr_order['shipping_method'] = @$ord['orderDetails']['shipmentDetails']['shipMethod'];

			//store requiredShipDate for update in custom field
			if (isset($ord['orderDetails']['shipmentDetails']['shipmentDates']['requiredShipDate'])) {
				$ship_date = $ord['orderDetails']['shipmentDetails']['shipmentDates']['requiredShipDate'];
				$date = new DateTimeImmutable($ship_date);
				$arr_order['ship_date'] = $date->format('l F j, Y h:i A');
			}


			$arr_order['order_status'] = @$ord['orderDetails']['orderStatus'];
			$arr_order['order_date'] = date('Y-m-d H:i:s', strtotime(@$ord['orderDetails']['orderDate']));
			$venderItemArray['shipFromParty'] = @$ord['orderDetails']['shipFromParty']['partyId'];
			$venderItemArray['buyingParty'] = null;
			$venderItemArray['vendorOrderNumber'] = @$ord['orderDetails']['customerOrderNumber'];
		} else {

			$arr_order['order_status'] = @$ord['purchaseOrderState'];
			$arr_order['order_date'] = date('Y-m-d H:i:s', strtotime(@$ord['orderDetails']['purchaseOrderDate']));

			//store shipWindow as delivery_date
			if (isset($ord['orderDetails']['shipWindow'])) {

				$shipWindow = $ord['orderDetails']['shipWindow'];
				$delivery_date_data = explode("--", $shipWindow);
				$arr_order['delivery_date'] = $delivery_date_data[1];
			}


			$venderItemArray['buyingParty'] = @$ord['orderDetails']['buyingParty']['partyId'];
			$venderItemArray['shipFromParty'] = null;
			$venderItemArray['vendorOrderNumber'] = null;
		}

		//remaining data of vender
		$venderItemArray['sellingParty'] = @$ord['orderDetails']['sellingParty']['partyId'];
		$venderItemArray['shipToParty'] = @$ord['orderDetails']['shipToParty']['partyId'];
		$venderItemArray['billToParty'] = @$ord['orderDetails']['billToParty']['partyId'];


		$arr_order['vendor'] = json_encode($venderItemArray, true);


		//insert or update order
		$order_details = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_number' => @$ord['purchaseOrderNumber']], ['id']);
		if ($order_details) {
			$platform_order_id = $order_details->id;
			$this->mobj->makeUpdate('platform_order', $arr_order, ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_order_id' => @$ord['purchaseOrderNumber']]);
		} else {
			$arr_order = array_merge($arr_order, array("sync_status" => "Ready"));
			$platform_order_id = $this->mobj->makeInsertGetId('platform_order', $arr_order);
		}

		$currency_code = '';

		//Insert or update order Line items after sort order line items by sku
		$order_total = $this->SortOrderLineItems($ord['orderDetails'], $platform_order_id, $orderType);

		// foreach (@$ord['orderDetails']['items'] as $lineitem) {
		// 	$arr_order_line = array();
		// 	$arr_order_line['platform_order_id'] = $platform_order_id;
		// 	$arr_order_line['item_row_sequence'] = @$lineitem['itemSequenceNumber'];

		// 	if($orderType=="DIRECT_FULLFILMENT_ORDER"){
		// 		$arr_order_line['api_product_id'] = @$lineitem['buyerProductIdentifier'];
		// 	} else {
		// 		$arr_order_line['api_product_id'] = @$lineitem['amazonProductIdentifier'];
		// 	}

		// 	$arr_order_line['product_name'] = @$lineitem['title'];
		// 	$arr_order_line['sku'] = @$lineitem['vendorProductIdentifier'];

		// 	//set order qty & unit size
		// 	$unitSize = @$lineitem['orderedQuantity']['unitSize'];
		// 	$qty = ($unitSize) ? ($unitSize * @$lineitem['orderedQuantity']['amount'] ) : @$lineitem['orderedQuantity']['amount'];
		// 	$arr_order_line['qty'] = ($qty) ? $qty : 0;


		// 	//get price conditional
		// 	if($orderType=="DIRECT_FULLFILMENT_ORDER"){ 
		// 		$dynPriceIndex = 'netPrice';
		// 	} else {
		// 		$dynPriceIndex = 'netCost';
		// 	}

		// 	$currency_code = $lineitem[$dynPriceIndex]['currencyCode'];
		// 	if($unitSize){
		// 		$formattedRowCost =  $lineitem[$dynPriceIndex]['amount']/$unitSize;
		// 		$netCost = $this->helper->getNumberFormat($formattedRowCost, 4);
		// 	} else {
		// 		$netCost = $this->helper->getNumberFormat(@$lineitem[$dynPriceIndex]['amount'], 4);
		// 	}
		// 	$arr_order_line['price'] = $netCost;


		// 	$arr_order_line['uom'] = @$lineitem['orderedQuantity']['unitOfMeasure'] ? $lineitem['orderedQuantity']['unitOfMeasure'] : null;
		// 	$arr_order_line['total'] = ($qty * $netCost);
		// 	$arr_order_line['subtotal'] = ($qty * $netCost);

		// 	$order_total += floatval($netCost * $qty);

		// 	$ct_order_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'sku' => @$lineitem['vendorProductIdentifier']]);

		// 	if ($ct_order_line > 0) {
		// 		$this->mobj->makeUpdate('platform_order_line', $arr_order_line, ['platform_order_id' => $platform_order_id, 'sku' => @$lineitem['vendorProductIdentifier']]);
		// 		} else {
		// 		$this->mobj->makeInsert('platform_order_line', $arr_order_line);
		// 	}
		// }

		//update total amount into order table
		$ordtotal['total_amount'] =  $order_total;
		$this->mobj->makeUpdate('platform_order', $ordtotal, ["id" => $platform_order_id]);


		//store address for retail orders
		if ($orderType == "PURCHASEORDER") {

			//shipping
			$order_details = $this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id' => $this->platformId, 'api_id' => @$ord['orderDetails']['shipToParty']['partyId']], ['description']);
			if ($order_details) {

				$address = json_decode($order_details->description, true);
				//shipping address

				$arr_order_address = array();
				$arr_order_address['platform_order_id'] = $platform_order_id;
				$arr_order_address['address_type'] = 'Shipping';
				$arr_order_address['address_name'] = 'Amazon.com Services, Inc';
				$arr_order_address['firstname'] = 'Amazon.com Services,';
				$arr_order_address['lastname'] = 'Inc';
				$arr_order_address['address1'] = @$address['address'];
				$arr_order_address['city'] = @$address['city'];
				$arr_order_address['state'] = @$address['state'];
				$arr_order_address['postal_code'] = @$address['zipcode'];
				$arr_order_address['country'] = @$address['country'];


				$ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);

				if ($ct_address > 0) {
					$this->mobj->makeUpdate('platform_order_address', $arr_order_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Shipping']);
				} else {
					$this->mobj->makeInsert('platform_order_address', $arr_order_address);
				}
			} else {

				//failed order if amazon PO address code not found in common address table.
				$this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_order_id' => @$ord['purchaseOrderNumber']]);
			}


			//billing address
			$order_details_b = $this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id' => $this->platformId, 'api_id' => @$ord['orderDetails']['billToParty']['partyId']], ['description']);
			if ($order_details_b) {

				$bill_address = json_decode($order_details_b->description, true);

				$arr_order_bill_address = array();
				$arr_order_bill_address['platform_order_id'] = $platform_order_id;
				$arr_order_bill_address['address_type'] = 'Billing';
				$arr_order_bill_address['address_name'] = 'Amazon.com Services, Inc';
				$arr_order_bill_address['firstname'] = 'Amazon.com Services,';
				$arr_order_bill_address['lastname'] = 'Inc';
				$arr_order_bill_address['address1'] = @$bill_address['address'];
				$arr_order_bill_address['city'] = @$bill_address['city'];
				$arr_order_bill_address['state'] = @$bill_address['state'];
				$arr_order_bill_address['postal_code'] = @$bill_address['zipcode'];
				$arr_order_bill_address['country'] = @$bill_address['country'];



				$bill_ct_address = $this->mobj->getCountsByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);

				if ($bill_ct_address > 0) {
					$this->mobj->makeUpdate('platform_order_address', $arr_order_bill_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing']);
				} else {
					$this->mobj->makeInsert('platform_order_address', $arr_order_bill_address);
				}
			} else {

				//failed order if amazon PO address code not found in common address table.
				$this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_order_id' => @$ord['purchaseOrderNumber']]);
			}
		}



		//store address for vender order
		if ($orderType == "DIRECT_FULLFILMENT_ORDER") {

			$ship_address_data = @$ord['orderDetails']['shipToParty'];
			if (count($ship_address_data) > 1) {
				$ship_address = array();
				$ship_address['platform_order_id'] = $platform_order_id;
				$ship_address['address_type'] = 'shipping';
				$ship_address['address_name'] = @$ship_address_data['name'];
				$ship_address['firstname'] = @$ship_address_data['name'];
				$ship_address['address1'] = @$ship_address_data['addressLine1'];
				$ship_address['address2'] = @$ship_address_data['addressLine2'];
				$ship_address['address3'] = @$ship_address_data['addressLine3'];
				$ship_address['city'] = @$ship_address_data['city'];
				$ship_address['state'] = @$ship_address_data['stateOrRegion'];
				$ship_address['postal_code'] = @$ship_address_data['postalCode'];
				$ship_address['country'] = @$ship_address_data['countryCode'];
				$ship_address['phone_number'] = @$ship_address_data['phone'];

				//store as shipping address
				$order_details_s = $this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'shipping'], ['id']);
				if ($order_details_s) {
					//update
					$this->mobj->makeUpdate('platform_order_address', $ship_address, ['platform_order_id' => $platform_order_id, 'address_type' => 'shipping', 'id' => $order_details_s->id]);
				} else {
					//insert
					$this->mobj->makeInsert('platform_order_address', $ship_address);
				}

				// store as Billing address also
				$order_details_bill = $this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'Billing'], ['id']);
				if (!$order_details_bill) {
					$ship_address['address_type'] = 'Billing';
					$this->mobj->makeInsert('platform_order_address', $ship_address);
				}
			}

			//store shipping method as shippingLines
			$shipping_method = @$ord['orderDetails']['shipmentDetails']['shipMethod'];

			//check Brightpearl Allow Shipping method in order line status
			$allow_shipping_in_ol = 'NO';
			$default_allow_shipping_method = $this->map->getMappedDataByName($user_integration_id, NULL, "allow_shipping_method_in_order_line", ['name']);
			if ($default_allow_shipping_method) {
				$allow_shipping_in_ol = $default_allow_shipping_method->name;
			}

			if ($shipping_method && $allow_shipping_in_ol == 'YES') {

				$arr_shipping_line = array();
				$arr_shipping_line['platform_order_id'] = $platform_order_id;
				$arr_shipping_line['row_type'] = 'SHIPPING';
				$arr_shipping_line['product_name'] = $shipping_method;
				$arr_shipping_line['description'] = $shipping_method;
				$arr_shipping_line['price'] = 0;
				$arr_shipping_line['qty'] = 1;
				$arr_shipping_line['subtotal'] =  0;
				$arr_shipping_line['total'] =  0;
				// $arr_shipping_line['item_row_sequence'] =  @$ord['orderDetails']['items'] ? count($ord['orderDetails']['items']) + 1  : 0;
				$arr_shipping_line['item_row_sequence'] =  0;

				$ct_shipping_line = $this->mobj->getCountsByConditions('platform_order_line', [
					'platform_order_id' => $platform_order_id,
					'row_type' => 'SHIPPING'
				]);

				if (!$ct_shipping_line) {
					$this->mobj->makeInsert('platform_order_line', $arr_shipping_line);
				}
			}


			//start new shipment create login || check allow_label_create mapping is yes then generate shipment label
			$selected_allow_label_create = 'NO';
			$default_allow_label_create = $this->map->getMappedDataByName($user_integration_id, NULL, "allow_label_create", ['api_code']);
			if ($default_allow_label_create) {
				$selected_allow_label_create = $default_allow_label_create->api_code;
			}

			if ($selected_allow_label_create == 'YES') {
				$this->createShipmentLabels($amazonToken, $ord, $user_id, $user_integration_id);
			}
			//end new shipment create logic


		}


		//check payment update mapping for store payment details
		$allow_order_mark_as_paid = 'NO';
		$def_payment_update_map = $this->map->getMappedDataByName($user_integration_id, NULL, "order_mark_as_paid", ['api_code']);
		if ($def_payment_update_map) {
			$allow_order_mark_as_paid = $def_payment_update_map->api_code;
		}

		if ($allow_order_mark_as_paid == 'YES') {
			$this->insertUpdateOrderPaymentInfo($platform_order_id, $ord['purchaseOrderNumber'], $order_total, $currency_code);
		}
		//end


	}
	//sort order line by sku to pass same as Amazon UI in destination platform
	public function SortOrderLineItems($orderDetails, $platform_order_id, $orderType)
	{
		$order_total = 0;

		//initial array to store order line data
		$order_line_raw_array = [];

		foreach ($orderDetails['items'] as $lineitem) {

			$arr_order_line = array();

			$arr_order_line['platform_order_id'] = $platform_order_id;
			$arr_order_line['item_row_sequence'] = @$lineitem['itemSequenceNumber'];

			if ($orderType == "DIRECT_FULLFILMENT_ORDER") {
				$arr_order_line['api_product_id'] = @$lineitem['buyerProductIdentifier'];
			} else {
				$arr_order_line['api_product_id'] = @$lineitem['amazonProductIdentifier'];
			}

			$arr_order_line['product_name'] = @$lineitem['title'];
			$arr_order_line['sku'] = @$lineitem['vendorProductIdentifier'];

			//set order qty & unit size
			$unitSize = @$lineitem['orderedQuantity']['unitSize'];
			$qty = ($unitSize) ? ($unitSize * @$lineitem['orderedQuantity']['amount']) : @$lineitem['orderedQuantity']['amount'];
			$arr_order_line['qty'] = ($qty) ? $qty : 0;


			//get price conditional
			if ($orderType == "DIRECT_FULLFILMENT_ORDER") {
				$dynPriceIndex = 'netPrice';
			} else {
				$dynPriceIndex = 'netCost';
			}

			$currency_code = $lineitem[$dynPriceIndex]['currencyCode'];
			if ($unitSize) {
				$formattedRowCost =  $lineitem[$dynPriceIndex]['amount'] / $unitSize;
				$netCost = $this->helper->getNumberFormat($formattedRowCost, 4);
			} else {
				$netCost = $this->helper->getNumberFormat(@$lineitem[$dynPriceIndex]['amount'], 4);
			}
			$arr_order_line['price'] = $netCost;


			$arr_order_line['uom'] = @$lineitem['orderedQuantity']['unitOfMeasure'] ? $lineitem['orderedQuantity']['unitOfMeasure'] : null;
			$arr_order_line['total'] = ($qty * $netCost);
			$arr_order_line['subtotal'] = ($qty * $netCost);

			$order_total += floatval($netCost * $qty);

			//push in array for sorting
			$order_line_raw_array[$lineitem['vendorProductIdentifier']] = $arr_order_line;
		}

		//short line items by index desc
		krsort($order_line_raw_array);

		$item_row_sequence = 1;
		foreach ($order_line_raw_array as $line) {

			$arr_order_line = array();

			$arr_order_line['platform_order_id'] = $line['platform_order_id'];
			$arr_order_line['item_row_sequence'] = $item_row_sequence;
			$arr_order_line['api_product_id'] = $line['api_product_id'];
			$arr_order_line['product_name'] = $line['product_name'];
			$arr_order_line['sku'] = $line['sku'];
			$arr_order_line['qty'] = $line['qty'];
			$arr_order_line['price'] = $line['price'];
			$arr_order_line['uom'] = $line['uom'];
			$arr_order_line['total'] = $line['total'];
			$arr_order_line['subtotal'] = $line['subtotal'];

			//find in order line
			$ct_order_line = $this->mobj->getCountsByConditions('platform_order_line', ['platform_order_id' => $line['platform_order_id'], 'sku' => $line['sku']]);
			if ($ct_order_line > 0) {
				$this->mobj->makeUpdate('platform_order_line', $arr_order_line, ['platform_order_id' => $line['platform_order_id'], 'sku' => $line['sku']]);
			} else {
				$this->mobj->makeInsert('platform_order_line', $arr_order_line);
			}

			$item_row_sequence++;
		}

		return $order_total;
	}

	//handle order payment
	public function insertUpdateOrderPaymentInfo($platform_order_id, $order_number, $order_total, $currency_code)
	{
		$row_type = 'PAYMENT';
		$transaction_id = $order_number . "-" . time();
		$transaction_datetime = date(DATE_ISO8601, strtotime(date('Y-m-d H:i:s')));
		$transaction_type = 'payment';
		$transaction_approval = 'ok';


		$manual_transaction = PlatformOrderTransaction::where(['platform_order_id' => $platform_order_id, 'transaction_type' => $transaction_type, 'transaction_approval' => 'ok', 'row_type' => $row_type])->first();

		if (!$manual_transaction) {

			PlatformOrderTransaction::create(['platform_order_id' => $platform_order_id, 'transaction_id' => $transaction_id, 'transaction_datetime' => $transaction_datetime, 'transaction_type' => $transaction_type, 'transaction_amount' => $order_total, 'transaction_approval' => $transaction_approval, 'row_type' => $row_type,  'currency_code' => $currency_code]);

			//update order 
			PlatformOrder::where('id', $platform_order_id)->update(['api_order_payment_status' => 'paid', 'transaction_sync_status' => 'Ready']);
		}
	}




	//create shipment label for direct fullfilment orders
	public function createShipmentLabels($ufound, $ord, $user_id, $user_integration_id)
	{

		$payload = array();
		$payload["purchaseOrderNumber"] = @$ord['purchaseOrderNumber'];

		//sellingParty details... no need to pass address detais blank will accept
		$payload["sellingParty"]["partyId"] = @$ord['orderDetails']['sellingParty']['partyId'];

		//shipFromParty details... no need to pass address detais blank will accept
		$payload["shipFromParty"]["partyId"] = @$ord['orderDetails']['shipFromParty']['partyId'];


		//final post data prepare
		$final_payload['shippingLabelRequests'][] = $payload;
		$shipment_label_payload = json_encode($final_payload, true);

		//create shipment label call
		$response = $this->amz->createShipmentLabel($ufound, $shipment_label_payload);

		//pull shipment label & update table || no need to pull Imidiatly will pull in backup call
		if ($response) {

			//update order before pull so that it can be repulled by backup logic also
			PlatformOrder::where('user_integration_id', $user_integration_id)->where('platform_id', $this->platformId)
				->where('order_number', $ord['purchaseOrderNumber'])->update(['label_generation' => 1]);

			//log shipment label create
			\Storage::disk('local')->append('amazon_shipment_label_response.txt', 'createShipmentLabels Response - true ' . 'request : ' . $shipment_label_payload
				. PHP_EOL . PHP_EOL);

			$this->pullCreatedLabeledShipment($user_id, $user_integration_id, $ord['purchaseOrderNumber']);
		} else {
			//update order to avoid struct... if shipment label not created
			PlatformOrder::where('user_integration_id', $user_integration_id)->where('platform_id', $this->platformId)
				->where('order_number', $ord['purchaseOrderNumber'])->update(['updated_at' => date('Y-m-d H:i:s')]);
		}

		return true;
	}
	//prepare order line for shipment label create
	public function makeShipmentLabelLine($order_line_array)
	{

		$packedItems = [];

		//Loop order line array
		if (!empty($order_line_array)) {
			$seq = 0;
			foreach ($order_line_array as $lineitem) {

				$seq++;
				$item_row_sequence = ($lineitem['itemSequenceNumber'] > 0) ? $lineitem['itemSequenceNumber'] : $seq;

				$line_packedItems['itemSequenceNumber'] = $item_row_sequence;
				$line_packedItems['buyerProductIdentifier'] =  @$lineitem['buyerProductIdentifier'];
				$line_packedItems['vendorProductIdentifier'] = @$lineitem['amazonProductIdentifier'];

				//set order qty & unit size
				$unitSize = @$lineitem['orderedQuantity']['unitSize'];
				$qty = ($unitSize) ? ($unitSize * @$lineitem['orderedQuantity']['amount']) : @$lineitem['orderedQuantity']['amount'];


				$line_packedItems['packedQuantity']['amount'] = ($qty) ? $qty : 0;
				$line_packedItems['packedQuantity']['unitOfMeasure'] = @$lineitem['orderedQuantity']['unitOfMeasure'] ? $lineitem['orderedQuantity']['unitOfMeasure'] : "Each";

				$packedItems[] = $line_packedItems;
			}
		}

		return ['packedItems' => $packedItems];
	}

	//label shipment create in not updating imidiatly
	public function pullCreatedLabeledShipment($user_id, $user_integration_id, $po_number)
	{
		try {

			$response = true;

			//find amazon account
			$ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'user_id', 'app_id', 'app_secret', 'refresh_token', 'access_token', 'access_key', 'secret_key', 'role_arn', 'region', 'marketplace_id', 'api_domain', 'env_type', 'platform_id']);

			//get shipment label by order number
			$result = $this->amz->getDirectFullfillmentShipmentLabelsByOrderNumber($ufound, $po_number);


			if (isset($result['payload']) && isset($result['payload']['purchaseOrderNumber'])) {

				//log shipment label pull
				\Storage::disk('local')->append('amazon_shipment_label_response.txt', 'pullCreatedLabeledShipment - true po_number : ' . $po_number . PHP_EOL . PHP_EOL);

				//label received for single PO
				$shippingLabels['shippingLabels'] = $result['payload'];

				//get insert update order details
				$this->insertUpdateShipmentLabels($user_id, $user_integration_id, $shippingLabels);
			} else {

				//if data is empty or any random error
				if (isset($result['errors']['message'])) {
					$response = $result['errors']['message'];
				} else {

					if (isset($result['payload']['shippingLabels']) && (empty($result['payload']['shippingLabels'])) || (isset($result['payload']) && (!empty($result['payload'])))) {
						$response = false;
					} else {
						$response = json_encode($result, true);
					}
				}

				return $response;
			}
		} catch (\Exception $e) {
			$response = $e->getMessage();
			\Log::error($e->getMessage());
		}

		return $response;
	}
	//insert shipment in db
	public function insertUpdateShipmentLabels($user_id, $user_integration_id, $shippingLabels)
	{
		$response = true;

		if (isset($shippingLabels)) {

			foreach ($shippingLabels as $shipment) {

				//Find platform_order_id for recieved PO number
				$purchaseOrderNumber = $shipment['purchaseOrderNumber'];

				$findOrder = $this->mobj->getFirstResultByConditions('platform_order', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_number' => $purchaseOrderNumber], ['id', 'api_order_id']);

				if ($findOrder) {


					$platform_order_id = $findOrder->id;
					$api_order_id = $findOrder->api_order_id;

					//insert or update order
					$shipment_details = $this->mobj->getFirstResultByConditions('platform_order_shipments', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_order_id' => $platform_order_id], ['id']);


					//Prepare Shipment Data
					$arr_shipment = array();
					$arr_shipment['user_id'] = $user_id;
					$arr_shipment['platform_id'] = $this->platformId;
					$arr_shipment['user_integration_id'] = $user_integration_id;
					$arr_shipment['platform_order_id'] = $platform_order_id;
					$arr_shipment['sync_status'] = 'Ready';
					$arr_shipment['order_id'] = $api_order_id;


					$content = '';
					if (isset($shipment['labelData']['trackingNumber'])) {

						$arr_shipment['tracking_info'] = $shipment['labelData']['trackingNumber'];
						$arr_shipment['shipping_method'] = $shipment['labelData']['shipMethod'];
						$content = base64_decode($shipment['labelData']['content']);
					} else if (isset($shipment['labelData'][0]['trackingNumber'])) {

						$arr_shipment['tracking_info'] = $shipment['labelData'][0]['trackingNumber'];
						$arr_shipment['shipping_method'] = $shipment['labelData'][0]['shipMethod'];
						$content = base64_decode($shipment['labelData'][0]['content']);
					}

					if (isset($shipment['labelFormat'])) {
						$labelFormat = '.' . strtolower($shipment['labelFormat']);
					} else {
						$labelFormat = '.png';
					}

					//formate store shipment label url
					$dynamic_file_name = 'esb/amazonvendor/' . $user_integration_id . '/labeled_shipment/' . $purchaseOrderNumber . $labelFormat;

					//store in server also
					// Storage::disk('local')->put($dynamic_file_name, $content);
					//end store in server

					//upload file in s3 bucket
					Storage::disk('s3')->put($dynamic_file_name, $content);

					if (Storage::disk('s3')->exists($dynamic_file_name)) {

						$bucket_name = env('AWS_BUCKET');
						$aws_region = env('AWS_DEFAULT_REGION');
						$labled_url = 'https://' . $bucket_name . '.s3.' . $aws_region . '.amazonaws.com/' . $dynamic_file_name;

						//update labeled url as tracking url
						$arr_shipment['tracking_url'] = $labled_url;

						echo $labled_url;
						echo "<br>";
					}



					if ($shipment_details) {

						$shipment_pid = $shipment_details->id;
						$this->mobj->makeUpdate('platform_order_shipments', $arr_shipment, ['id' => $shipment_pid]);
						//make order shipment status ready
						$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Ready'], ['id' => $platform_order_id], ['label_generation' => 1]);
					} else {

						$this->mobj->makeInsertGetId('platform_order_shipments', $arr_shipment);
						//make order shipment status ready
						$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Ready'], ['id' => $platform_order_id], ['label_generation' => 1]);
					}
				} else {
					echo 'Order not found';
					echo "<br>";
				}
			}
		} else {
			$response = false;
		}


		return $response;
	}



	//Pull labeled shipment as backup Logic to get the labeled shipment manualy generated or missed 
	public function GetDirectFullfillmentShipmentLabels($user_id, $user_integration_id, $is_initial_sync)
	{
		try {
			$limit = 10;
			$response = true;

			//find amazon account
			$ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'user_id', 'app_id', 'app_secret', 'refresh_token', 'access_token', 'access_key', 'secret_key', 'role_arn', 'region', 'marketplace_id', 'api_domain', 'env_type', 'platform_id']);

			if ($is_initial_sync == 1) {

				return true;
			} else {

				$amazonToken = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'user_id', 'app_id', 'app_secret', 'refresh_token', 'access_token', 'access_key', 'secret_key', 'role_arn', 'region', 'marketplace_id', 'api_domain', 'env_type']);
				$from_date = date(DATE_ISO8601, strtotime(date('Y-m-d H:i:s' . '-1 day')));

				//start new shipment create login
				$selected_allow_label_create = 'NO';
				$default_allow_label_create = $this->map->getMappedDataByName($user_integration_id, NULL, "allow_label_create", ['api_code']);
				if ($default_allow_label_create) {
					$selected_allow_label_create = $default_allow_label_create->api_code;
				}

				//Create or pull shipment label only for direct fullfilment orders
				$List_pending_orders = PlatformOrder::select('id', 'order_number', 'label_generation')
					->where('user_integration_id', $user_integration_id)
					->where('platform_id', $this->platformId)
					->where('shipment_status', 'Pending')
					->where('order_type', 'SO')
					->orderBy('updated_at', 'asc')
					->limit($limit)
					->get();


				if ($ufound && $List_pending_orders) {

					foreach ($List_pending_orders as $orderRow) {

						//get shipment label if already created
						$getShipmentLabel = $this->pullCreatedLabeledShipment($user_id, $user_integration_id, $orderRow->order_number);

						if ($getShipmentLabel != true) {

							//check label_generation flag
							if ($orderRow->label_generation != 1 && $selected_allow_label_create == 'YES') {

								//Get orders  & created shipment label request...
								$orders = $this->amz->GetRetailAndVenderOrders($amazonToken, NULL, $from_date, 'NEW', 'DIRECT_FULLFILMENT_ORDER', 1, $orderRow->order_number);

								if (isset($orders['payload']) || isset($orders['payload']['purchaseOrderNumber'])) {
									$ord = $orders['payload'];
									$this->createShipmentLabels($amazonToken, $ord, $user_id, $user_integration_id);
								}
							}
						}
					}
				}
			}
		} catch (\Exception $e) {

			$response = $e->getMessage();
		}
		return $response;
	}



	/* Acknowledgement tested for retail orders*/
	//send acknowledgement for Retail & Vender orders
	public function CallRetailAndVenderAcknowledge($userId = NULL, $userIntegrationId = NULL, $platform_WorkFlowID = NULL, $UserWorkFlow = NULL, $SourcePlatformName = NULL, $sync_status = "Ready")
	{
		\Storage::disk('local')->append('amazon_api_call_log.txt', 'CallRetailAndVenderAcknowledge call - ' . date('Y-m-d H:i:s') . PHP_EOL);

		//get order type filter mapping
		$selected_order_type_filter = 'Both';
		$default_order_type_filter = $this->map->getMappedDataByName($userIntegrationId, NULL, "order_type_filter", ['api_id']);
		if ($default_order_type_filter) {
			$selected_order_type_filter = $default_order_type_filter->api_id;
		}


		//call based on selected order type mapping
		if ($selected_order_type_filter == 'VendorDF') {

			//call for venders order stored as SO
			$this->sendAmazonPOacknowledge($userId, $userIntegrationId, $platform_WorkFlowID, $UserWorkFlow, $SourcePlatformName, $sync_status, 'DIRECT_FULLFILMENT_ORDER_ACKNOWLEDGEMENT');
		} else if ($selected_order_type_filter == 'VendorRetail') {

			//call for retails order stored as PO
			$this->sendAmazonPOacknowledge($userId, $userIntegrationId, $platform_WorkFlowID, $UserWorkFlow, $SourcePlatformName, $sync_status, 'PURCHASEORDERACKNOWLEDGEMENT');
		} else {

			//call for retails order stored as PO
			$this->sendAmazonPOacknowledge($userId, $userIntegrationId, $platform_WorkFlowID, $UserWorkFlow, $SourcePlatformName, $sync_status, 'PURCHASEORDERACKNOWLEDGEMENT');
			//call for venders order stored as SO
			$this->sendAmazonPOacknowledge($userId, $userIntegrationId, $platform_WorkFlowID, $UserWorkFlow, $SourcePlatformName, $sync_status, 'DIRECT_FULLFILMENT_ORDER_ACKNOWLEDGEMENT');
		}
	}
	//update function for both type of achnoledgenet direct fulfillment order & other
	public function sendAmazonPOacknowledge($userId = NULL, $userIntegrationId = NULL, $platform_WorkFlowID = NULL, $UserWorkFlow = NULL, $SourcePlatformName = NULL, $sync_status = "Ready", $acknowledgementType)
	{
		try {
			$limit = 1;
			$return_response = false;
			$object_id = $this->helper->getObjectId('sales_order');
			$ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'user_id', 'app_id', 'app_secret', 'platform_id', 'refresh_token', 'access_token', 'access_key', 'secret_key', 'role_arn', 'region', 'marketplace_id', 'api_domain', 'env_type']);

			$SourcePlatformId = $this->helper->getPlatformIdByName($SourcePlatformName);

			//source platform
			$SOurceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['id', 'app_id', 'app_secret', 'platform_id', 'id', 'user_id', 'api_domain']);

			if ($ufound && $this->platformId && $SourcePlatformId && $SOurceUfound) {

				if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

					if ($acknowledgementType == "DIRECT_FULLFILMENT_ORDER_ACKNOWLEDGEMENT") {
						$orderTypeCode = 'SO';
						$rootArrayDynamicIndex = 'orderAcknowledgements';
					} else {
						$orderTypeCode = 'SO';
						$rootArrayDynamicIndex = 'acknowledgements';
					}

					$list = $this->mobj->getResultByConditions('platform_order', [
						'user_integration_id' => $userIntegrationId,
						'platform_id' => $SOurceUfound->platform_id,  //bp platform to testing only
						'order_status' => 'Acknowledge',
						'order_type' => $orderTypeCode
					], ['id', 'user_id', 'platform_id', 'user_integration_id', 'api_order_id', 'order_number', 'linked_id', 'order_updated_at', 'currency'], ['id' => 'asc'], $limit);



					if (!empty($list) && count($list) > 0) {

						// $arrayAckData = []; 
						$items_posting = [];
						$rootArray = [];

						$ackArray = [];

						foreach ($list as $key => $order) {

							$source_order = $this->mobj->getFirstResultByConditions('platform_order', ['id' => $order->linked_id], ['id', 'order_number', 'total_amount', 'vendor', 'api_order_id']);


							//prepare Acknowledgement Items
							$items_posting = $this->makePOAcknowledgeLineItems($source_order->id, $source_order->order_number, $order->id, $order->currency, $userIntegrationId, $platform_WorkFlowID, $SourcePlatformId, $userId, $acknowledgementType);


							//Brighout vender data from order vender column json data
							$party = json_decode($source_order->vendor, true);

							$payload = array();

							if ($acknowledgementType == "DIRECT_FULLFILMENT_ORDER_ACKNOWLEDGEMENT") {

								//required fields
								$payload['acknowledgementDate'] = (isset($order->order_updated_at)) ? date("Y-m-d\TH:i:s\Z", strtotime($order->order_updated_at)) : '';
								$payload['acknowledgementStatus']["code"] = "00";
								//line items
								$payload['itemAcknowledgements'] = $items_posting['items_posting'];
								$payload['purchaseOrderNumber'] = $source_order->api_order_id;
								$payload["sellingParty"]["partyId"] = @$party['sellingParty'];
								$payload["shipFromParty"]["partyId"] = @$party['shipFromParty'];
								$payload['vendorOrderNumber'] = @$party['vendorOrderNumber'];
							} else {
								//required fields
								$payload["purchaseOrderNumber"] = $source_order->api_order_id;
								$payload["sellingParty"]["partyId"] = @$party['sellingParty'];
								$payload['acknowledgementDate'] = (isset($order->order_updated_at)) ? date("Y-m-d\TH:i:s\Z", strtotime($order->order_updated_at)) : '';
								$payload["items"] = $items_posting['items_posting'];

								$ackArray[] = $payload;
							}



							//prepare array for postData 
							$rootArray[$rootArrayDynamicIndex] = $ackArray;

							$ack_payload = json_encode($rootArray, true);


							if (!empty($ack_payload)) {

								//call api for push acknowledgement
								//$response = $this->amz->sendAcknowledge($ufound, $ack_payload);
								$response = $this->amz->pushAcknowledgement($ufound, $ack_payload, $acknowledgementType);


								if (isset($response['payload']['transactionId'])) {

									$this->mobj->makeUpdate('platform_order', ['sync_status' => 'Synced'], ['id' => $order->id]);

									############# Set Ready status to amazon PO and is_fully_sync = 1 so that it can update the order status to Acknowledge via order update event. ###########
									$this->mobj->makeUpdate('platform_order', ['sync_status' => 'Ready', 'is_fully_synced' => 1], ['id' => $order->linked_id]);

									$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $order->id, NULL);

									//sleep(1);

									$return_response = true;
								} else {

									$error = $this->bp->handleResponseError($response);
									$this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $order->id]);
									$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId,  $object_id, 'failed', $order->id, $error);
									$return_response = true;
								}
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($e->getMessage());
			// echo $e->getMessage();
			return $e->getMessage();
		}

		return $return_response;
	}
	public function makePOAcknowledgeLineItems($amazon_order_id, $order_number, $bp_order_id, $currency, $userIntegrationId, $platform_WorkFlowID, $platformId, $userId, $acknowledgementType)
	{

		//Doubt clear to shubham k sir temporary set $bp_order_id as amazon_order_id
		$bp_order_id = $amazon_order_id;


		$items_posting = [];
		$shipping = [];
		$discount = [];
		$itemAcknowledgements = [];

		$products = $this->mobj->getResultByConditions('platform_order_line', ['platform_order_id' => $amazon_order_id], [
			'platform_order_id', 'item_row_sequence', 'api_product_id', 'product_name', 'sku', 'ean', 'upc',  'qty', 'price', 'total', 'subtotal', 'subtotal_tax', 'uom', 'row_type'
		]);


		if (!empty($products)) {

			$product_identity_obj_id = $this->helper->getObjectId('product_identity');

			$mapping_data = $this->map->getMappedField($userIntegrationId, $platform_WorkFlowID, $product_identity_obj_id);



			if ($mapping_data) {

				$source_row_data = $destination_row_data = '';
				if ($mapping_data['source_platform_id'] == 'amazonvendor') {
					$destination_row_data = $mapping_data['source_row_data'];
					$source_row_data = $mapping_data['destination_row_data'];
				}

				$i = 0;
				foreach ($products as $v) {

					$product_value = '';
					$get_product = $v;
					$found = (array) $get_product;
					if (isset($found[$source_row_data])) {
						$product_value = $found[$source_row_data];
					}


					if ($product_value != '') {

						$get_so_line = DB::table('platform_order_line')->where('platform_order_id', $bp_order_id)->where($destination_row_data, $product_value)->select('price', 'qty', 'sku')->first();

						//if not found by identity then search with name
						if (is_null($get_so_line)) {
							$nm = $v->api_product_id . '-' . $v->sku;
							$get_so_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $bp_order_id, 'product_name' => $nm], ['price', 'qty', 'sku']);
						}
					} else {
						//if not found in order line table then match from sku default
						$get_so_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $bp_order_id, 'sku' => $v->sku], ['price', 'qty', 'sku']);
					}



					//start json modulation for post data
					if ($get_so_line) {

						$vendorProductIdentifier = $v->sku;
						$itemSequenceNumber = $v->item_row_sequence;
						$api_product_id = $v->api_product_id;
						$unitOfMeasure = ($v->uom) ? $v->uom : "Each";
						$amount = $get_so_line->price;
						$qty = $get_so_line->qty;

						if ($acknowledgementType == "DIRECT_FULLFILMENT_ORDER_ACKNOWLEDGEMENT") {

							$line['acknowledgedQuantity']['amount'] = $v->qty;
							$line['acknowledgedQuantity']['unitOfMeasure'] = $unitOfMeasure;
							$line['itemSequenceNumber'] = $itemSequenceNumber;
							$line['buyerProductIdentifier'] = $api_product_id;
							$line['vendorProductIdentifier'] = $vendorProductIdentifier;
						} else {
							//optional fields
							$line['itemSequenceNumber'] = $itemSequenceNumber;
							$line['amazonProductIdentifier'] = $api_product_id;
							$line['vendorProductIdentifier'] = $vendorProductIdentifier;

							//Required field
							$line['orderedQuantity']['amount'] = $v->qty;
							$line['orderedQuantity']['unitOfMeasure'] = $unitOfMeasure;
							$line['orderedQuantity']['unitSize'] = $qty;

							$line['netCost']['currencyCode'] = 'USD';
							$line['netCost']['amount'] = $amount;


							$line_ack['acknowledgementCode'] = "Accepted";
							$line_ack['acknowledgedQuantity']['amount'] = $get_so_line->qty;

							$itemAcknowledgements = $line_ack;
							$line_ack_posting[] = $line_ack;
							$line['itemAcknowledgements'] =  $line_ack_posting;
						}


						//append line items in items_posting array
						$items_posting[] = $line;
					}
				}
			}
		}

		// echo '<pre>';
		// print_r($items_posting);
		// die();

		return ['items_posting' => $items_posting];
	}



	//call invoice for payment
	public function CallRetailAndVenderInvoice($userId = NULL, $userIntegrationId = NULL, $platform_WorkFlowID = NULL, $UserWorkFlow = NULL, $SourcePlatformName = NULL, $sync_status = "Ready")
	{
		// \Storage::disk('local')->append('amazon_api_call_log.txt', 'CallRetailAndVenderInvoice call - '.date('Y-m-d H:i:s') .PHP_EOL);

		//get order type filter mapping
		$selected_order_type_filter = 'Both';
		$default_order_type_filter = $this->map->getMappedDataByName($userIntegrationId, NULL, "order_type_filter", ['api_id']);
		if ($default_order_type_filter) {
			$selected_order_type_filter = $default_order_type_filter->api_id;
		}

		//call based on selected order type mapping
		if ($selected_order_type_filter == 'VendorDF') {
			$this->syncInvoice($userId, $userIntegrationId, $platform_WorkFlowID, $UserWorkFlow, $SourcePlatformName, $sync_status, 'DIRECT_FULLFILMENT_ORDER_INVOICE');
		} else if ($selected_order_type_filter == 'VendorRetail') {
			$this->syncInvoice($userId, $userIntegrationId, $platform_WorkFlowID, $UserWorkFlow, $SourcePlatformName, $sync_status, 'INVOICE');
		} else {
			$this->syncInvoice($userId, $userIntegrationId, $platform_WorkFlowID, $UserWorkFlow, $SourcePlatformName, $sync_status, 'INVOICE');
			$this->syncInvoice($userId, $userIntegrationId, $platform_WorkFlowID, $UserWorkFlow, $SourcePlatformName, $sync_status, 'DIRECT_FULLFILMENT_ORDER_INVOICE');
		}
	}

	public function isJson($string, $return_data = false)
	{
		$data = json_decode($string);
		return (json_last_error() == JSON_ERROR_NONE) ? ($return_data ? $data : true) : false;
	}
	//sync invoice to amazon from brightpearl for direct fulfillment orders & other orders
	public function syncInvoice($userId = NULL, $userIntegrationId = NULL, $platform_WorkFlowID = NULL, $UserWorkFlow = NULL, $SourcePlatformName = NULL, $sync_status = "Ready", $invoiceType)
	{
		try {

			//set it to 50
			$limit = 1;

			$return_response = false;
			$object_id = $this->helper->getObjectId('invoice');
			$ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, [
				'id', 'user_id', 'app_id', 'app_secret', 'platform_id', 'refresh_token', 'access_token', 'access_key', 'secret_key', 'role_arn', 'region', 'marketplace_id', 'api_domain',
				'env_type'
			]);

			$SourcePlatformId = $this->helper->getPlatformIdByName($SourcePlatformName);

			//source platform
			$SOurceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['id', 'app_id', 'app_secret', 'platform_id', 'id', 'user_id', 'api_domain']);


			if ($ufound && $this->platformId && $SourcePlatformId && $SOurceUfound) {

				if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {

					$get_tax_scheme = '';

					if ($invoiceType == "DIRECT_FULLFILMENT_ORDER_INVOICE") {
						$orderTypeCode = 'SO';
					} else {
						$orderTypeCode = 'PO';
					}

					$list = $this->mobj->getResultByConditions('platform_order', [
						'user_integration_id' => $userIntegrationId,
						'platform_id' => $SOurceUfound->platform_id,  //bp platform to testing only
						// 'sync_status' => 'Ready', 
						'invoice_sync_status' => $sync_status,
						'order_type' => $orderTypeCode
					], ['user_id', 'platform_id', 'user_integration_id', 'platform_customer_id', 'trading_partner_id', 'order_type', 'api_order_id', 'customer_email', 'order_number', 'order_date', 'due_days', 'department', 'vendor', 'total_discount', 'total_tax', 'discount_tax', 'total_amount', 'sync_status', 'linked_id',  'shipping_total', 'shipping_tax', 'id', 'currency'], ['id' => 'asc'], $limit);


					if (!empty($list) && count($list) > 0) {

						$items_posting = [];
						$taxRegistrationDetails = [];

						$obj = [];
						foreach ($list as $key => $order) {


							$source_order = $this->mobj->getFirstResultByConditions('platform_order', ['id' => $order->linked_id], ['id', 'order_number', 'total_amount', 'vendor']);

							$pf_invoice = $this->mobj->getFirstResultByConditions('platform_invoice', [
								'user_integration_id' => $userIntegrationId, 'platform_id' => $SOurceUfound->platform_id, 'ref_number' => $order->api_order_id
							], ['id', 'invoice_date', 'ref_number']);


							$address_arr = $this->isJson($source_order->vendor) ? json_decode($source_order->vendor, true) : null;

							if ($address_arr) {

								//prepare invoice items
								$items_posting = $this->makeInvoiceLineItems($order->id, $source_order->order_number, $source_order->id, $order->currency, $get_tax_scheme, $userIntegrationId, $platform_WorkFlowID, $SourcePlatformId, $userId, $address_arr, $invoiceType);



								//Create order in brightpearl
								$payload = array();

								if ($invoiceType == "DIRECT_FULLFILMENT_ORDER_INVOICE") {

									$payload["invoiceNumber"] = @$pf_invoice->ref_number;
									$payload["invoiceDate"] = (isset($pf_invoice->invoice_date)) ? date("Y-m-d\TH:i:s\Z", strtotime($pf_invoice->invoice_date)) : '';

									//seller information
									$payload["remitToParty"]["partyId"] = $address_arr['sellingParty'];

									//
									$seller_address_Line['addressLine1'] = '';
									$seller_address_Line['city'] = '';
									$seller_address_Line['countryCode'] = '';
									$seller_address_Line['name'] = '';
									$seller_address_Line['postalCode'] = '';
									$seller_address_Line['stateOrRegion'] = '';
									$seller_address_Line['county'] = '';

									$payload["remitToParty"]["address"] = $seller_address_Line;


									//put taxRegistrationDetails
									$tax_Line['taxRegistrationNumber'] = '';
									$tax_Line['taxRegistrationType'] = '';
									$tax_Line['taxRegistrationAddress'] = '';
									$tax_Line['taxRegistrationMessage'] = '';

									//tax address line
									$tax_address_Line['addressLine1'] = '';
									$tax_address_Line['city'] = '';
									$tax_address_Line['countryCode'] = '';
									$tax_address_Line['name'] = '';
									$tax_address_Line['postalCode'] = '';
									$tax_address_Line['stateOrRegion'] = '';
									$tax_address_Line['county'] = '';
									$tax_address_Line['county'] = '';

									$tax_Line['taxRegistrationAddress'] = $tax_address_Line;


									$taxRegistrationDetails[] = $tax_Line;
									$payload["remitToParty"]['taxRegistrationDetails'] = $taxRegistrationDetails;



									$payload["shipFromParty"]["partyId"] = $address_arr['shipFromParty'];
									$payload["invoiceTotal"]['amount'] = $this->helper->getNumberFormat($source_order->total_amount, 2);
									$payload["invoiceTotal"]['currencyCode'] = $order->currency;;
									//items
									$payload["items"] = $items_posting['items_posting'];
								} else {

									//required
									$payload["invoiceType"] = "Invoice";
									$payload["id"] = $order->api_order_id;
									$payload["date"] = (isset($pf_invoice->invoice_date)) ? date("Y-m-d\TH:i:s\Z", strtotime($pf_invoice->invoice_date)) : '';
									$payload["remitToParty"]["partyId"] = $address_arr['sellingParty'];


									//not required
									$payload["shipToParty"]["partyId"] = $address_arr['shipToParty'];
									$payload["shipFromParty"]["partyId"] = $address_arr['shipFromParty'];
									$payload["billToParty"]["partyId"] = $address_arr['billToParty'];
									$payload["paymentTerms"]["type"] = "Basic";
									//required
									$payload["invoiceTotal"]["amount"] = $this->helper->getNumberFormat($source_order->total_amount, 2);
									$payload["invoiceTotal"]["currencyCode"] = $order->currency;
									//not required
									$payload["items"] = $items_posting['items_posting'];
								}



								if ($payload) {
									$invoice_array_payload['invoices'][] = $payload;
									$invoice_payload = json_encode($invoice_array_payload, true);

									// echo "<pre>";
									// print_r($invoice_payload);
									// die();



									if (!empty($invoice_payload)) {
										//#######------- endpoint is set for sandbox, change this before make live -------#########

										// $response = $this->amz->createInvoice($ufound, $invoice_payload,);
										$response = $this->amz->pushInvoice($ufound, $invoice_payload, $invoiceType);

										// dd($response, $invoice_payload);

										// print_r($response);
										if (isset($response['payload']['transactionId']) || isset($response['transactionId'])) {

											$transactionId = isset($response['payload']['transactionId']) ? $response['payload']['transactionId'] : $response['transactionId'];

											$InvoiceLinked = $this->mobj->makeInsertGetId('platform_invoice', [
												'user_id' => $userId,
												'platform_id' => $this->platformId,
												'user_integration_id' => $userIntegrationId,
												'platform_order_id' => $source_order->id,
												'api_invoice_id' => $transactionId,
												'sync_status' => 'Synced',
												'linked_id' => $pf_invoice->id,
												'api_created_at' => date('Y-m-d H:i:s'),
												'api_updated_at' => date('Y-m-d H:i:s')
											]);

											$this->mobj->makeUpdate('platform_invoice', ['linked_id' => $InvoiceLinked, 'sync_status' => 'Synced'], ['id' => $pf_invoice->id]);
											$this->mobj->makeUpdate('platform_order', ['invoice_sync_status' => 'Synced'], ['id' => $order->id]);

											$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $pf_invoice->id, NULL);

											// sleep(1);

											$return_response = true;
										} else {

											$error = $this->bp->handleResponseError($response);
											$this->mobj->makeUpdate('platform_invoice', ['sync_status' => 'Failed'], ['id' => $pf_invoice->id]);
											$this->mobj->makeUpdate('platform_order', ['invoice_sync_status' => 'Failed'], ['id' => $order->id]);
											$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId,  $object_id, 'failed', $pf_invoice->id, $error);
											$return_response = true;
										}
									}
								}
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($e->getMessage());
			// dd($e->getMessage());
			return $e->getMessage();
		}

		return $return_response;
	}
	public function makeInvoiceLineItems($orderID, $order_number, $amazon_order_id, $currency, $tax_scheme, $userIntegrationId, $platform_WorkFlowID, $platformId, $userId, $address_arr, $invoiceType)
	{

		$items_posting = [];
		$shipping = [];
		$discount = [];

		$products = DB::table('platform_order_line')->select('platform_order_id', 'item_row_sequence', 'api_product_id', 'product_name', 'sku', 'ean', 'upc',  'qty', 'price', 'total', 'subtotal', 'subtotal_tax', 'uom', 'row_type')->where('platform_order_id', $orderID)->orderBy('id', 'asc')->get();

		if (!empty($products)) {

			$product_identity_obj_id = $this->helper->getObjectId('product_identity');

			$mapping_data = $this->map->getMappedField($userIntegrationId, $platform_WorkFlowID, $product_identity_obj_id);

			if ($mapping_data) {

				$source_row_data = $destination_row_data = '';
				if ($mapping_data['source_platform_id'] == 'amazonvendor') {
					$destination_row_data = $mapping_data['source_row_data'];
					$source_row_data = $mapping_data['destination_row_data'];
				}


				$seq = 0;
				$unitOfMeasure = 'Each';

				foreach ($products as $v) {


					$seq++;
					if ($v->row_type == "ITEM") {

						$product_value = 0;

						$get_product = $this->mobj->getFirstResultByConditions('platform_product', ['user_integration_id' => $userIntegrationId, 'platform_id' => $platformId, 'api_product_id' => $v->api_product_id], [$source_row_data]);

						if ($get_product) {
							$found = (array) $get_product;
							$product_value = ($found[$source_row_data] != null) ? $found[$source_row_data] : 0; //0 is default case so can be used to item_row_sequence directly
						}


						if (!isset($v->sku) || empty($v->sku)) {

							$get_po_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $orderID, 'item_row_sequence' => $seq], ['item_row_sequence', 'uom', 'api_product_id', 'sku']);

							if ($get_po_line) {
								$itemSequenceNumber = $get_po_line->item_row_sequence;
								$amazonProductIdentifier = $get_po_line->api_product_id;
								$vendorProductIdentifier = $get_po_line->sku;
								$unitOfMeasure = ($get_po_line->uom) ? $get_po_line->uom : "Each";
								$seq++;
							}
						} else {


							if ($product_value != 0) {

								$get_po_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $orderID, $destination_row_data => $product_value], ['item_row_sequence', 'uom', 'api_product_id', 'sku']);
							} else {

								//if not found in product table then get details from order line using sku
								$get_po_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $orderID, 'sku' => $v->sku], ['item_row_sequence', 'uom', 'api_product_id', 'sku']);
							}

							if ($get_po_line) {
								$amazonProductIdentifier = $get_po_line->api_product_id;
								$vendorProductIdentifier = $get_po_line->sku;
								$itemSequenceNumber = $get_po_line->item_row_sequence;
								$unitOfMeasure = ($get_po_line->uom) ? $get_po_line->uom : "Each";
							}
						}
					} else if ($v->row_type == 'SHIPPING') {

						$shipping[] = array("type" => "Freight", "description" => "Shipping Charges", "chargeAmount" => array("amount" => $this->helper->getNumberFormat($v->subtotal, 2)));
					} else if ($v->row_type == 'DISCOUNT') {

						$discount[] = array("type" => "Discount", "description" => "Discount", "allowanceAmount" => array("amount" => $this->helper->getNumberFormat($v->subtotal, 2)));
					}


					$item_row_sequence = ($v->item_row_sequence > 0) ? $v->item_row_sequence : $seq;

					if ($invoiceType == "DIRECT_FULLFILMENT_ORDER_INVOICE") {


						$line['itemSequenceNumber'] = $item_row_sequence;
						$line['invoicedQuantity']['amount'] = $v->qty;
						$line['invoicedQuantity']['unitOfMeasure'] = $unitOfMeasure;
						$line['netCost']['amount'] = $this->helper->getNumberFormat($v->subtotal, 2);
						$line['netCost']['currencyCode'] = $currency;
						$line['purchaseOrderNumber'] = $order_number;
					} else {

						//required
						$line['itemSequenceNumber'] = $item_row_sequence;
						//not req
						$line['amazonProductIdentifier'] = $amazonProductIdentifier;
						$line['vendorProductIdentifier'] = $vendorProductIdentifier;
						//required
						$line['invoicedQuantity']['amount'] = $v->qty;
						$line['invoicedQuantity']['unitOfMeasure'] = $unitOfMeasure;
						$line['netCost']['amount'] = $this->helper->getNumberFormat($v->subtotal, 2);
						$line['netCost']['currencyCode'] = $currency;
						//not req
						$line['purchaseOrderNumber'] = $order_number;
					}

					$items_posting[] = $line;
				}
			}
		}

		return ['items_posting' => $items_posting, 'shipping' => $shipping, 'discount' => $discount];
	}







	// Shipment push to amazon from bp for direct fulfillment orders
	public function syncShipment($userId = NULL, $userIntegrationId = NULL, $WorkFlowID = NULL, $UserWorkFlow = NULL, $SourcePlatformName = NULL, $sync_status = "Ready", $record_id = null)
	{
		try {
			$return_response = false;
			$limit = 20;

			$ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'user_id', 'app_id', 'app_secret', 'refresh_token', 'access_token', 'access_key', 'secret_key', 'role_arn', 'region', 'marketplace_id', 'api_domain', 'env_type', 'platform_id']);

			if ($ufound && $this->platformId) {

				$object_id = $this->helper->getObjectId('sales_order_shipment');
				$SourcePlatformId = $this->helper->getPlatformIdByName($SourcePlatformName);
				$SourceUfound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $SourcePlatformId, ['app_id',  'platform_id']);

				if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId && isset($SourceUfound->platform_id)) {

					if ($record_id) {

						$list = DB::table('platform_order_shipments as s')
							->select('s.tracking_info', 's.shipping_method', 's.realease_date',  's.tracking_url', 's.shipment_status', 'o.order_number', 'o.id as order_primary_id', 's.id', 'a.vendor', 'a.id as amazon_order_id', 's.shipment_id', 's.platform_order_id', 's.transaction_id', 's.attempt', 'a.shipping_method as amz_shipping_method')
							->join('platform_order as o', 'o.id', '=', 's.platform_order_id')   //synced bp order join
							->join('platform_order as a', 'a.id', '=', 'o.linked_id')  // amazon PO join for bill to and ship to ids
							->where([['o.id', '=', $record_id], ['s.user_integration_id', '=', $userIntegrationId], ['a.user_integration_id', '=', $userIntegrationId]])
							->take($limit)->get();
					} else {
						$list = DB::table('platform_order_shipments as s')
							->select('s.tracking_info', 's.shipping_method', 's.realease_date',  's.tracking_url', 's.shipment_status', 'o.order_number', 'o.id as order_primary_id', 's.id', 'a.vendor', 'a.id as amazon_order_id', 's.shipment_id', 's.platform_order_id', 's.transaction_id', 's.attempt', 'a.shipping_method as amz_shipping_method')
							->join('platform_order as o', 'o.id', '=', 's.platform_order_id')   //synced bp order join
							->join('platform_order as a', 'a.id', '=', 'o.linked_id')  // amazon PO join for bill to and ship to ids
							->where([
								['s.platform_id', '=', $SourceUfound->platform_id], ['s.user_integration_id', '=', $userIntegrationId],
								['o.user_integration_id', '=', $userIntegrationId], ['a.user_integration_id', '=', $userIntegrationId],
								['a.sync_status', '=', 'Synced']
							])
							//pull failed & Ready both shipment
							->whereIn('s.sync_status', ['Ready', 'Failed'])
							->whereIn('o.shipment_status', ['Ready', 'Failed'])
							//Pull only direct fullfillment order for shipment confirmation
							->where('a.order_type', 'SO')
							->orderBy('o.updated_at', 'ASC')
							->take($limit)->get();
					}



					if (!empty($list) && count($list) > 0) {
						$orderIds = [];
						foreach ($list as $key => $value) {

							$payload = [];
							$final_payload = [];
							$shipment_containers_line = [];
							$shipment_containers = [];
							$shipment_id = null;

							$shipmentStatus = unserialize($value->shipment_status);
							$address_arr = $this->isJson($value->vendor) ? json_decode($value->vendor, true) : null;
							$shipment_id = $value->shipment_id;
							//get stored transaction_id in first call for reverify shipment confirmation status
							$transaction_id = $value->transaction_id;

							//if Already $transaction_id exist in db & attemp is 1 means shipment confirmation post call done.. if attemp ==2 means transaction status checked
							if ($transaction_id && $value->attempt == 1) {

								$shipmentConfirmationSynced = false;
								$error = '';

								//check transaction status... Commented for now bcos transaction status get not work on instance have already tried with 5 sec sleep
								$transactionStatus = $this->amz->getTransactionStatus($ufound, $transaction_id);

								//Shipment Confirmation Push Request & response
								\Storage::disk('local')->append('amazon_shipment_confirmation.txt', 'shipmentConfirmation  transaction status check time: ' . date('Y-m-d H:i:s') . ' userIntegrationId : ' . $userIntegrationId  . PHP_EOL . ' Response : ' . json_encode($transactionStatus, true) . PHP_EOL . PHP_EOL);


								if (isset($transactionStatus['payload']) && isset($transactionStatus['payload']['transactionStatus']) && isset($transactionStatus['payload']['transactionStatus']['status']) && $transactionStatus['payload']['transactionStatus']['status'] == 'Success') {
									$shipmentConfirmationSynced = true;
									$return_response = true;
								} else {

									if (isset($transactionStatus['payload']) && isset($transactionStatus['payload']['transactionStatus']) && isset($transactionStatus['payload']['transactionStatus']['errors']) && isset($transactionStatus['payload']['transactionStatus']['errors'][0])) {
										$error = @$transactionStatus['payload']['transactionStatus']['errors'][0]['message'];
										$return_response = $error;
									} else {
										$error = 'Transaction status is processing try after some time';
										$return_response = $error;
									}

									//if error msg has already confirmed text
									if (str_contains($error, 'already confirmed')) {
										$shipmentConfirmationSynced = true;
										$return_response = 'already confirmed';
									} else {
										$shipmentConfirmationSynced = false;
									}
								}

								if ($shipmentConfirmationSynced == true) {

									$shipmentLinked = $this->mobj->makeInsertGetId('platform_order_shipments', [
										'user_id' => $userId,
										'platform_id' => $this->platformId,
										'user_integration_id' => $userIntegrationId,
										'shipment_id' => $transaction_id,
										'sync_status' => 'Synced',
										'linked_id' => $value->id,
									]);

									$this->mobj->makeUpdate('platform_order_shipments', ['linked_id' => $shipmentLinked, 'sync_status' => 'Synced', 'attempt' => 2], ['id' => $value->id]);
									$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Synced'], ['id' => $value->order_primary_id]);

									$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $value->order_primary_id, NULL);
									$return_response = true;
								} else {
									//reset attemp 0 for next call will again for shipment confirmation
									$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed', 'attempt' => 2], ['id' => $value->id]);
									$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $value->order_primary_id]);
									$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId,  $object_id, 'failed', $value->order_primary_id, $error);
									$return_response = $error;
								}
							} else {

								//pass amazon order shipping method string
								$shipMethod = $value->amz_shipping_method;

								//before passing bp shipping method name
								// $shipMethod = '';
								// if ($value->shipping_method) {
								// 	$shipping_method_obj_id = $this->helper->getObjectId('shipping_method');
								// 	$find_shipMethod = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $userIntegrationId, 'platform_id' => $SourceUfound->platform_id, 'platform_object_id' => $shipping_method_obj_id, 'api_id' => $value->shipping_method], ['name']);

								// 	if ($find_shipMethod) {
								// 		$shipMethod = $find_shipMethod->name;
								// 	}
								// }

								//Pass thease default values to avoid failed. with error weight not 
								$default_weight_unitOfMeasure = 'KG';
								$default_weight_value = 1;

								//get order line details of amazon order order
								$shipmentID = $value->id;
								$items_posting = $this->MakeShipmentLineItems($userId, $userIntegrationId, $WorkFlowID, $shipmentID, $value->amazon_order_id, $SourceUfound->platform_id);
								$payload['purchaseOrderNumber'] = $value->order_number;
								$payload['shipmentDetails']['shippedDate'] = date("Y-m-d\TH:i:s\Z", strtotime($shipmentStatus['shippedOn']));
								$payload['shipmentDetails']['shipmentStatus'] = 'SHIPPED';

								if ($address_arr) {
									$payload['sellingParty']['partyId'] = $address_arr['sellingParty'];
									$payload['shipFromParty']['partyId'] = $address_arr['shipFromParty'];
								} else {
									$payload['sellingParty']['partyId'] = '';
									$payload['shipFromParty']['partyId'] = '';
								}

								$payload['items'] = $items_posting['items_posting'];

								//Carton, Pallet
								$shipment_containers_line['containerType'] = 'Carton';
								$shipment_containers_line['containerIdentifier'] = $shipment_id;
								$shipment_containers_line['shipMethod'] = $shipMethod;
								$shipment_containers_line['trackingNumber'] = $value->tracking_info;

								//aditinal param
								$shipment_containers_line['weight']['unitOfMeasure'] = $default_weight_unitOfMeasure;
								$shipment_containers_line['weight']['value'] = $default_weight_value;

								// $shipment_containers_line['dimensions']['unitOfMeasure'] = '';
								// $shipment_containers_line['dimensions']['height']['value'] = '';
								// $shipment_containers_line['dimensions']['length']['value'] = '';
								// $shipment_containers_line['dimensions']['width']['value'] = '';


								// $shipment_containers_line['weight']['unitOfMeasure'] = '';
								// $shipment_containers_line['weight']['value'] = '';
								//end



								$shipment_containers_line['packedItems'] = $items_posting['packedQuantity_posting'];
								$shipment_containers[] = $shipment_containers_line;
								$payload['containers'] = $shipment_containers;

								$final_payload['shipmentConfirmations'][] = $payload;
								$shipment_payload = json_encode($final_payload, true);


								$response = $this->amz->pushShipment($ufound, $shipment_payload);


								//Shipment Confirmation Push Request & response
								\Storage::disk('local')->append('amazon_shipment_confirmation.txt', 'shipmentPush call time: ' . date('Y-m-d H:i:s') . ' userIntegrationId : ' . $userIntegrationId  . PHP_EOL . ' Request : ' . $shipment_payload . PHP_EOL . ' Response : ' . json_encode($response, true) . PHP_EOL . PHP_EOL);

								//condition for fresh shipment push response check
								if (isset($response['payload']['transactionId'])) {

									$transaction_id = $response['payload']['transactionId'];
									//update transaction id & updated date & return true
									$this->mobj->makeUpdate('platform_order_shipments', ['transaction_id' => $transaction_id, 'attempt' => 1], ['id' => $value->id]);
									$this->mobj->makeUpdate('platform_order', ['updated_at' => date('Y-m-d H:i:s')], ['id' => $value->order_primary_id]);
									$return_response = true;
								} else {

									$error = $this->bp->handleResponseError($response);
									$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed', 'attempt' => 1], ['id' => $value->id]);
									$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $value->order_primary_id]);
									$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId,  $object_id, 'failed', $value->order_primary_id, $error);
									$return_response = $error;
								}
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($e->getMessage());
			return $e->getMessage();
		}
		return $return_response;
	}

	public function makeShipmentLineItems($userId, $userIntegrationId, $WorkFlowID, $shipmentID, $platform_order_id, $platformId)
	{
		$items_posting = [];
		$packedQuantity_posting = [];

		//get bp shipment details
		$get_po_line_data = $this->mobj->getResultByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => 'ITEM'], ['id', 'api_order_line_id', 'product_name', 'qty', 'item_row_sequence', 'uom', 'api_product_id', 'sku']);

		if (!empty($get_po_line_data)) {

			$seq = 0;
			foreach ($get_po_line_data as $get_po_line) {

				$seq++;

				$item_row_sequence = ($get_po_line->item_row_sequence > 0) ? $get_po_line->item_row_sequence : $seq;

				$line['itemSequenceNumber'] = $item_row_sequence;
				$line['shippedQuantity']['amount'] = $get_po_line->qty;
				$line['shippedQuantity']['unitOfMeasure'] = ($get_po_line->uom) ? $get_po_line->uom : "Each";

				//Buyer's Standard Identification Number (ASIN) of an item. Either buyerProductIdentifier or vendorProductIdentifier is required
				$line['buyerProductIdentifier'] = $get_po_line->api_product_id;
				//The vendor selected product identification of the item. Should be the same as was sent in the purchase order, like SKU Number.
				$line['vendorProductIdentifier'] = $get_po_line->sku;


				$line_packedQuantity['itemSequenceNumber'] = $item_row_sequence;
				$line_packedQuantity['packedQuantity']['amount'] = $get_po_line->qty;
				$line_packedQuantity['packedQuantity']['unitOfMeasure'] = ($get_po_line->uom) ? $get_po_line->uom : "Each";

				//include this as additional
				$line_packedQuantity['buyerProductIdentifier'] = $get_po_line->api_product_id;
				$line_packedQuantity['vendorProductIdentifier'] = $get_po_line->sku;

				$items_posting[] = $line;
				$packedQuantity_posting[] = $line_packedQuantity;
			}
		}



		return ['items_posting' => $items_posting, 'packedQuantity_posting' => $packedQuantity_posting];
	}

	//NOT is use for now used for shipmentConfirmation.. here 
	public function makeShipmentLineItems_OLD($userId, $userIntegrationId, $WorkFlowID, $shipmentID, $platform_order_id, $platformId)
	{
		$items_posting = [];
		$packedQuantity_posting = [];

		$product_identity_obj_id = $this->helper->getObjectId('product_identity');
		$mapping_data = $this->map->getMappedField($userIntegrationId, $WorkFlowID, $product_identity_obj_id);


		if ($mapping_data) {

			//here dest menas amazon & source bp
			$source_row_data = $mapping_data['source_row_data'];
			$destination_row_data = $mapping_data['destination_row_data'];


			//get bp shipment details
			$shipment_line_array = $this->mobj->getResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id' => $shipmentID], ['product_id', 'quantity', 'sku']);

			if (!empty($shipment_line_array)) {

				$seq = 0;
				foreach ($shipment_line_array as $v) {

					$seq++;

					//find brightpearl product
					$find_source_product = $this->mobj->getFirstResultByConditions('platform_product', ['api_product_id' => $v->product_id, 'user_integration_id' => $userIntegrationId], ['isbn', 'sku']);

					if ($find_source_product) {

						$find_source_product = (array) $find_source_product;


						$get_po_line = null;
						if ($find_source_product && isset($find_source_product[$destination_row_data])) {
							$amz_api_product_id = $find_source_product[$destination_row_data];
							//fetch the order details of amazon order 
							$get_po_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => 'ITEM', $source_row_data => $amz_api_product_id], ['id', 'api_order_line_id', 'product_name', 'qty', 'item_row_sequence', 'uom', 'api_product_id', 'sku']);
						}

						if ($get_po_line) {

							$item_row_sequence = ($get_po_line->item_row_sequence > 0) ? $get_po_line->item_row_sequence : $seq;

							$line['itemSequenceNumber'] = $item_row_sequence;
							$line['shippedQuantity']['amount'] = $v->quantity;
							$line['shippedQuantity']['unitOfMeasure'] = ($get_po_line->uom) ? $get_po_line->uom : "Each";

							//Buyer's Standard Identification Number (ASIN) of an item. Either buyerProductIdentifier or vendorProductIdentifier is required
							$line['buyerProductIdentifier'] = $get_po_line->api_product_id;
							//The vendor selected product identification of the item. Should be the same as was sent in the purchase order, like SKU Number.
							$line['vendorProductIdentifier'] = $get_po_line->sku;



							$line_packedQuantity['itemSequenceNumber'] = $item_row_sequence;
							$line_packedQuantity['packedQuantity']['amount'] = $v->quantity;
							$line_packedQuantity['packedQuantity']['unitOfMeasure'] = ($get_po_line->uom) ? $get_po_line->uom : "Each";



							$items_posting[] = $line;
							$packedQuantity_posting[] = $line_packedQuantity;
						}
					}
				}
			}
		}

		return ['items_posting' => $items_posting, 'packedQuantity_posting' => $packedQuantity_posting];
	}





	//Execute event for calling function by events
	public function ExecuteEventAmazon($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
	{
		try {
			$response = true;

			if ($method == 'GET' && $event == 'PURCHASEORDER') {
				$response =  $this->CallRetailAndVenderOrders($user_id, $user_integration_id, $is_initial_sync);
			} else if ($method == 'MUTATE' && $event == 'PURCHASEORDERACKNOWLEDGEMENT') {
				$response = $this->CallRetailAndVenderAcknowledge($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, 'Brightpearl', 'Ready');
			} else if ($method == 'MUTATE' && $event == 'INVOICE') {
				return true;
				// $response =  $this->CallRetailAndVenderInvoice($user_id, $user_integration_id,$platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, 'Ready','INVOICE');
			} else if ($method == 'MUTATE' && $event == 'SHIPMENT') {
				$this->syncShipment($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_id, 'Ready', $record_id);
			} else if ($method == 'GET' && $event == 'SHIPMENT') {
				$this->GetDirectFullfillmentShipmentLabels($user_id, $user_integration_id, $is_initial_sync);
			}

			return $response;
		} catch (\Exception $e) {
			return $e->getMessage();
		}
	}

	//test amazon apis & function  //amazon_test
	public function test()
	{


		$userId = 152;
		$userIntegrationId = 272;
		$SourcePlatformName = 'brightpearl';
		$sync_status = 'Ready';

		//for all flow
		$RecordID = 2445009;

		//get amazon purchase orders
		$amazonToken = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['id', 'user_id', 'app_id', 'app_secret', 'refresh_token', 'access_token', 'access_key', 'secret_key', 'role_arn', 'region', 'marketplace_id', 'api_domain', 'env_type']);


		//update tracking info in bp
		// $platform_WorkFlowID = 145;
		// $UserWorkFlow = 1041;
		// $test =  app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->findGoodsoutAndUpdatePrintPackTracking($userId, $userIntegrationId, $platform_WorkFlowID, $UserWorkFlow, $SourcePlatformName, $sync_status, $RecordID);
		// dd($test);


		//test shipment sync
		// $platform_WorkFlowID = 129;
		// $UserWorkFlow = 824;
		// $test = $this->syncShipment($userId,$userIntegrationId, $platform_WorkFlowID, $UserWorkFlow, $SourcePlatformName, $sync_status,$RecordID);
		// dd($test);


		//get transaction status
		// $test_transaction_status = $this->amz->getTransactionStatus($amazonToken,'e514cfe0-1d7e-408a-a82e-c64a99449719-20230304080457');
		// dd($test_transaction_status);




		// $next_token = '';
		// $orderType = 'DIRECT_FULLFILMENT_ORDER';
		// $filter_by_status = "NEW";
		// $from_date = date(DATE_ISO8601, strtotime(date('Y-m-d H:i:s'. '-1 day')));
		// $get_order_limit = 10;
		// $orderNumber = 'GknvVFvSY';

		// // Get orders 
		// $orders = $this->amz->GetRetailAndVenderOrders($amazonToken,$next_token,$from_date,$filter_by_status,$orderType,$get_order_limit,$orderNumber);
		// dd($orders);

		// if (isset($orders['payload']) || isset($orders['payload']['purchaseOrderNumber'])) {

		// 	$ord = $orders['payload'];

		// 	//start new shipment create login
		// 	$selected_allow_label_create = 'NO';
		// 	$default_allow_label_create = $this->map->getMappedDataByName($userIntegrationId, NULL, "allow_label_create", ['api_code']);
		// 	if($default_allow_label_create){
		// 		$selected_allow_label_create = $default_allow_label_create->api_code;
		// 	}   

		// 	//set it to YES
		// 	if($selected_allow_label_create=='YES') {
		// 		$this->createShipmentLabels($amazonToken,$ord,$userId,$userIntegrationId);
		// 	}

		// 	//end new shipment create logic         


		// }



		// $shipment = $this->GetDirectFullfillmentShipmentLabels($userId, $userIntegrationId, false);
		// dd($shipment);	

	}
}
