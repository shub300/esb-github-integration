<?php

namespace App\Http\Controllers\Smartsheet;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\WorkflowSnippet;
use App\Helper\Logger;
use App\Models\PlatformAccount;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderLine;
use App\Models\PlatformUrl;
use App\Http\Controllers\Smartsheet\Api\SmartsheetApi;
use Auth, DB, Lang, Validator;

class SmartsheetApiController extends Controller
{
	public static $myPlatform = 'smartsheet';

	/**
	 * Create a new controller instance.
	 *
	 * @return void
	 */
	public $MainModel, $SmartsheetApi, $ConnectionHelper, $FieldMappingHelper, $Logger, $WorkflowSnippet, $platformId;
	public function __construct()
	{
		$this->MainModel = new MainModel();
		$this->SmartsheetApi = new SmartsheetApi();
		$this->ConnectionHelper = new ConnectionHelper();
		$this->FieldMappingHelper = new FieldMappingHelper();
		$this->Logger = new Logger();
		$this->WorkflowSnippet = new WorkflowSnippet();
		$this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
	}

	public function InitiateSmartsheetAuth(Request $request)
	{
		$platform = 'smartsheet';
		return view('pages.apiauth.auth_smartsheet', compact('platform'));
	}

	public function ConnectSmartsheetAuth(Request $request)
	{
		$validator = Validator::make($request->all(), ['account_name' => 'required']);

		if ($this->MainModel->checkHtmlTags($request->all())) {
			return back()->with('error', Lang::get('tags.validate'));
		}

		if ($validator->fails()) {
			return back()->withErrors($validator);
		} else {
			$account_name = trim($request->account_name);

			//to check whether given account is already in use or not.
			$checkExistingAccount = $this->MainModel->getFirstResultByConditions('platform_accounts', ['user_id' => Auth::user()->id, 'platform_id' => $this->platformId, 'account_name' => $account_name], ['id']);
			if ($checkExistingAccount) {
				return back()->with('error', 'Given details are already in use, Try with other details.');
			}

			$platform_api_app = $this->MainModel->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->platformId], ['client_id', 'client_secret']);
			if ($platform_api_app) {
				$client_id = $this->MainModel->encrypt_decrypt($platform_api_app->client_id, 'decrypt');
				$client_secret = $this->MainModel->encrypt_decrypt($platform_api_app->client_secret, 'decrypt');
				$redirect_url = $this->MainModel->makeUrlHttpsForProd(url('/RedirectHandlerSmartsheet'));

				$state_i = Auth::user()->id . '-' . $account_name;

				if ($client_id && $client_secret) {
					$url = 'https://app.smartsheet.com/b/authorize?response_type=code&scope=READ_SHEETS%20WRITE_SHEETS%20READ_CONTACTS%20READ_EVENTS&client_id=' . $client_id . '&state=' . $state_i;

					return redirect($url);
				} else {
					Session::put('auth_msg', 'App config not found');
					echo '<script>window.close();</script>';
				}
			} else {
				$this->MainModel->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"</a>');
			}
		}
	}

	/* Get Token */
	public function RedirectHandlerSmartsheet(Request $request)
	{
		date_default_timezone_set('UTC');

		if (isset($request->code)) {
			$platform_api_app = $this->MainModel->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->platformId], ['app_ref', 'client_id', 'client_secret']);
			if ($platform_api_app) {
				$code = $request->code;
				$client_id = $this->MainModel->encrypt_decrypt($platform_api_app->client_id, 'decrypt');
				$client_secret = $this->MainModel->encrypt_decrypt($platform_api_app->client_secret, 'decrypt');

				$redirect_url = $this->MainModel->makeUrlHttpsForProd(url('/RedirectHandlerSmartsheet'));
				$state = $request->state;
				$state_arr = explode('-', $state);
				if (isset($state_arr[0]) && isset($state_arr[1])) {
					// Valid request
					$user_id = $state_arr[0];
					$account_name = $state_arr[1]; // Account Code
					if (isset($state_arr[0]) && isset($state_arr[1])) {
						$curl_post_data = array('client_id' => $client_id, 'client_secret' => $client_secret, 'code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => $redirect_url);

						$service_url = 'https://api.smartsheet.com/2.0/token';

						$headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

						$response = $this->MainModel->makeRequest('POST', $service_url, $curl_post_data, $headers, 'http');
						if (json_decode($response->getBody(), true)) {
							if ($smartsheet_token = json_decode($response->getBody(), true)) {
								if (isset($smartsheet_token['access_token'])) {
									$accountData = ['access_token' => $this->MainModel->encrypt_decrypt($smartsheet_token['access_token']), 'refresh_token' => $this->MainModel->encrypt_decrypt($smartsheet_token['refresh_token']), 'token_type' => $smartsheet_token['token_type'], 'expires_in' => $smartsheet_token['expires_in'], 'account_name' => $account_name, 'user_id' => $user_id, 'platform_id' => $this->platformId, 'token_refresh_time' => time()];

									$platform_account = DB::table('platform_accounts')->where(['user_id' => $user_id, 'platform_id' => $this->platformId, 'account_name' => $account_name])->first();

									if (is_null($platform_account)) {
										DB::table('platform_accounts')->insert($accountData);
									} else {
										DB::table('platform_accounts')->where('id', $platform_account->id)
											->update($accountData);
									}
								} else {
									if (isset($smartsheet_token['message'])) {
										$error = $smartsheet_token['message'];
									} else {
										$error = 'Something went wrong in your account';
									}

									echo '<script>alert("' . $error . '");window.close();</script>';
								}
							}
							echo '<script>window.close();</script>';
						} else {
							$this->MainModel->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
						}
					}
				}
			}
		} else {
			// When code not received from BP
			$this->MainModel->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"></a>');
		}
	}

	/* Refresh token */
	public function RefreshToken($id)
	{
		date_default_timezone_set('UTC');

		$return_response = false;

		try {
			$platform_api_app = $this->MainModel->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->platformId]);
			if ($platform_api_app) {
				$platform_account = $this->MainModel->getFirstResultByConditions('platform_accounts', ['id' => $id], ['id', 'refresh_token']);
				if ($platform_account) {
					$curl_post_data = ['client_id' => $this->MainModel->encrypt_decrypt($platform_api_app->client_id, 'decrypt'), 'client_secret' => $this->MainModel->encrypt_decrypt($platform_api_app->client_secret, 'decrypt'), 'refresh_token' => $this->MainModel->encrypt_decrypt($platform_account->refresh_token, 'decrypt'), 'grant_type' => 'refresh_token'];

					$service_url = 'https://api.smartsheet.com/2.0/token';

					$headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

					$response = $this->MainModel->makeRequest('POST', $service_url, $curl_post_data, $headers, 'http');

					$smartsheet_token = json_decode($response->getBody(), true);

					if (isset($smartsheet_token['access_token'])) {
						$accountData = ['access_token' => $this->MainModel->encrypt_decrypt($smartsheet_token['access_token']), 'refresh_token' => $this->MainModel->encrypt_decrypt($smartsheet_token['refresh_token']), 'token_type' => $smartsheet_token['token_type'], 'expires_in' => $smartsheet_token['expires_in'], 'token_refresh_time' => time()];

						DB::table('platform_accounts')->where('id', $platform_account->id)
							->update($accountData);

						$return_response = true;
					} else {
						if (isset($smartsheet_token['message'])) {
							$return_response = $smartsheet_token['message'];
						} else {
							$return_response = 'Something went wrong in your account';
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($id . ' - SmartsheetApiController - RefreshToken - ' . $e->getLine() . ' - ' . $e->getMessage());
			$return_response = $e->getMessage();
		}

		return $return_response;
	}

	public function GetSheets($user_id = 0, $user_integration_id = 0)
	{
		$return_data = true;
		try {
			$sheet_object = $this->MainModel->getFirstResultByConditions('platform_objects', ['name' => 'sheet'], ['id']);
			if ($sheet_object) {
				$this->MainModel->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $sheet_object->id, 'status' => 1]);

				$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token']);
				if ($platform_account) {
					$queryString = 'includeAll=true';
					$response = $this->SmartsheetApi->getSheetList($this->MainModel->encrypt_decrypt($platform_account->access_token, 'decrypt'), $queryString);
					$result = json_decode($response, true);

					if (isset($result['data'][0]['id'])) {
						foreach ($result['data'] as $sheet) {
							$sheetData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $sheet_object->id, 'api_id' => $sheet['id'], 'name' => $sheet['name'], 'api_code' => $sheet['permalink'], 'description' => $sheet['name'], 'status' => 1];

							$platform_object_data = $this->MainModel->getFirstResultByConditions('platform_object_data', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'platform_object_id' => $sheet_object->id, 'api_id' => $sheet['id']], ['id']);
							if ($platform_object_data) {
								$this->MainModel->makeUpdate('platform_object_data', $sheetData, ['id' => $platform_object_data->id]);
							} else {
								$this->MainModel->makeInsert('platform_object_data', $sheetData);
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' - SmartsheetApiController - GetSheets - ' . $e->getLine() . ' - ' . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	public function GetSalesOrders($user_id, $user_integration_id, $user_workflow_rule_id)
	{
		$return_data = true;
		try {
			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token']);
			if ($platform_account) {
				$sheetId = NULL;
				$default_so_sheet = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, 'default_so_sheet', ['api_id'], 'default');
				if ($default_so_sheet) {
					$sheetId = $default_so_sheet->api_id;
				}

				$sync_start_date = NULL;
				$user_workflow_rule = $this->WorkflowSnippet->getWorkflowEvents($user_workflow_rule_id);
				if ($user_workflow_rule && $user_workflow_rule->sync_start_date) {
					$sync_start_date = date('Y-m-d\TH:i:s\Z', strtotime($user_workflow_rule->sync_start_date));
				}

				if ($sheetId && $sync_start_date) {
					$queryString = 'includeAll=true';

					$url_with_page = PlatformUrl::select('id', 'url')->where(['url_name' => 'sheet_order_filter_date', 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'status' => 1])->first();
					if ($url_with_page) {
						$queryString = $queryString . '&rowsModifiedSince=' . date('Y-m-d\TH:i:s\Z', strtotime($url_with_page->url));
					} else {
						$queryString = $queryString . '&rowsModifiedSince=' . date('Y-m-d\TH:i:s\Z', strtotime($sync_start_date));

						$url_with_page = PlatformUrl::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url' => $sync_start_date, 'url_name' => 'sheet_order_filter_date', 'status' => 1, 'allow_retain' => 1]);
					}

					$response = $this->SmartsheetApi->readSheet($this->MainModel->encrypt_decrypt($platform_account->access_token, 'decrypt'), $sheetId, $queryString);
					$result = json_decode($response, true);

					$columnInfo = [];
					if (isset($result['columns']) && is_array($result['columns'])) {
						foreach ($result['columns'] as $column) {
							if (isset($column['title']) && $column['title']) {
								$columnInfo[trim($column['title'])] = @$column['id'];
							}
						}
					}

					$rowRecords = [];
					if (isset($result['rows']) && is_array($result['rows'])) {
						foreach ($result['rows'] as $row) {
							if (isset($row['cells']) && is_array($row['cells'])) {
								if (strtotime($sync_start_date) <= strtotime($row['createdAt'])) {
									$cell_data = [];
									$cell_data['id'] = $row['id'];
									$cell_data['createdAt'] = $row['createdAt'];
									$cell_data['modifiedAt'] = $row['modifiedAt'];
									foreach ($row['cells'] as $cell) {
										$cell_data[$cell['columnId']] = @$cell['value'];
									}
									$rowRecords[] = $cell_data;
								}
							}
						}
					}

					if (count($columnInfo) && count($rowRecords)) {
						$approvalStatus = NULL;
						$sorder_approval_status = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, 'sorder_approval_status', ['custom_data'], 'default');
						if ($sorder_approval_status && $sorder_approval_status->custom_data) {
							$approvalStatus = $sorder_approval_status->custom_data;
						}

						$max_sheet_order_updated_date = NULL;
						foreach ($rowRecords as $rowRecord) {
							if ($approvalStatus && isset($columnInfo['Approval Status']) && isset($rowRecord[$columnInfo['Approval Status']]) && $rowRecord[$columnInfo['Approval Status']] == $approvalStatus) {
								if (isset($columnInfo['PO#']) && isset($rowRecord[$columnInfo['PO#']]) && $rowRecord[$columnInfo['PO#']]) {
									$name = (isset($columnInfo['Tx Operator']) && isset($rowRecord[$columnInfo['Tx Operator']])) ? trim($rowRecord[$columnInfo['Tx Operator']]) : NULL;
									$company = (isset($columnInfo['Community Name']) && isset($rowRecord[$columnInfo['Community Name']])) ? trim($rowRecord[$columnInfo['Community Name']]) : NULL;
									$address = (isset($columnInfo['Street']) && isset($rowRecord[$columnInfo['Street']])) ? trim($rowRecord[$columnInfo['Street']]) : NULL;
									$city = (isset($columnInfo['City']) && isset($rowRecord[$columnInfo['City']])) ? trim($rowRecord[$columnInfo['City']]) : NULL;
									$state = (isset($columnInfo['State']) && isset($rowRecord[$columnInfo['State']])) ? trim($rowRecord[$columnInfo['State']]) : NULL;
									$postal_code = (isset($columnInfo['Zip']) && isset($rowRecord[$columnInfo['Zip']])) ? trim($rowRecord[$columnInfo['Zip']]) : NULL;
									$country = 'US';

									$phone_number = (isset($columnInfo['Community Phone Number']) && isset($rowRecord[$columnInfo['Community Phone Number']])) ? trim($rowRecord[$columnInfo['Community Phone Number']]) : NULL;

									//order customer details
									$CustomerData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'customer_name' => $name, 'first_name' => $name, 'address1' => $address, 'address2' => $city, 'address3' => $state, 'country' => $country, 'postal_addresses' => $postal_code, 'phone' => $phone_number];

									$platform_customer = $this->MainModel->getFirstResultByConditions('platform_customer', ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'customer_name' => $name], ['id']);
									if ($platform_customer) {
										$platform_customer_id = $platform_customer->id;
										$this->MainModel->makeUpdate('platform_customer', $CustomerData, ['id' => $platform_customer->id]);
									} else {
										$platform_customer_id = $this->MainModel->makeInsertGetId('platform_customer', $CustomerData);
									}

									$order_number = trim($rowRecord[$columnInfo['PO#']]);

									$order_status = (isset($columnInfo['PE Status']) && isset($rowRecord[$columnInfo['PE Status']])) ? trim($rowRecord[$columnInfo['PE Status']]) : NULL;
									$delivery_date = (isset($columnInfo['Anticipated Delivery Date (Ancillary Therapy Suppl']) && isset($rowRecord[$columnInfo['Anticipated Delivery Date (Ancillary Therapy Suppl']])) ? date('Y-m-d H:i:s', strtotime(trim($rowRecord[$columnInfo['Anticipated Delivery Date (Ancillary Therapy Suppl']]))) : NULL;
									$status_column = isset($columnInfo['PE Status']) ? trim($columnInfo['PE Status']) : NULL;

									$notes = 'status_column:' . $status_column;
									$OrderData = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_workflow_rule_id' => $user_workflow_rule_id, 'user_integration_id' => $user_integration_id, 'platform_customer_id' => $platform_customer_id, 'order_type' => 'SO', 'api_order_id' => $rowRecord['id'], 'api_order_reference' => $order_number, 'order_number' => $order_number, 'order_date' => date('Y-m-d H:i:s', strtotime($rowRecord['createdAt'])), 'order_status' => $order_status, 'total_discount' => 0, 'total_tax' => 0, 'shipping_total' => 0, 'notes' => $notes, 'delivery_date' => $delivery_date, 'api_updated_at' => date('Y-m-d H:i:s', strtotime($rowRecord['modifiedAt']))];

									$platform_order_id = NULL;
									$platform_order = $this->MainModel->getFirstResultByConditions('platform_order', ['api_order_id' => $rowRecord['id'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'order_type' => 'SO'], ['id', 'sync_status', 'linked_id']);
									if (is_null($platform_order)) {
										$OrderData['sync_status'] = 'Ready';
										$OrderData['order_updated_at'] = date('Y-m-d H:i:s');

										$platform_order_id = $this->MainModel->makeInsertGetId('platform_order', $OrderData);
									} else {
										if ($platform_order->linked_id == 0 && $platform_order->sync_status != 'Ready' && $platform_order->sync_status != 'Synced') {
											$platform_order_id = $platform_order->id;

											$OrderData['sync_status'] = 'Ready';
											$OrderData['order_updated_at'] = date('Y-m-d H:i:s');

											$this->MainModel->makeUpdate('platform_order', $OrderData, ['id' => $platform_order_id]);
										}
									}

									if ($platform_order_id) {
										//order billing address
										// $OrderBillingAddressData = ['platform_order_id'=>$platform_order_id, 'address_type'=>'billing', 'address_name'=>$name, 'firstname'=>$name, 'company'=>$company, 'address1'=>$address, 'city'=>$city, 'state'=>$state, 'postal_code'=>$postal_code, 'country'=>$country, 'phone_number'=>$phone_number];

										/* Custom Code | Need to do dynamic*/
										if ($name == "EMW") {
											$bill_address_name = "1335 Strassner Drive";
											$bill_city = "St. Louis";
											$bill_state = "MO";
											$bill_postal_code = "63144";
											$bill_country = "US";
											$bill_phone_number = null;
											$bill_company = "EmpowerMe";
											$bill_email = null;
										} else if ($name == "ONR") {
											$bill_address_name = "8500 Bluffstone Cove";
											$bill_city = "Suite A201, Austin";
											$bill_state = "TX";
											$bill_postal_code = "78759";
											$bill_country = "US";
											$bill_phone_number = null;
											$bill_company = "ONR Rehab";
											$bill_email = null;
										} else {
											$bill_address_name = $address;
											$bill_company = $company;
											$bill_city = $city;
											$bill_state = $state;
											$bill_postal_code = $postal_code;
											$bill_country = $country;
											$bill_phone_number = $phone_number;
											$bill_email = null;
										}
										$OrderBillingAddressData = ['platform_order_id' => $platform_order_id, 'address_type' => 'billing', 'address_name' => $name, 'firstname' => $name, 'company' => $bill_company, 'address1' => $bill_address_name, 'city' => $bill_city, 'state' => $bill_state, 'postal_code' => $bill_postal_code, 'country' => $bill_country, 'phone_number' => $bill_phone_number, 'email' => $bill_email];

										$platform_order_billing_address = $this->MainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'billing'], ['id']);
										if ($platform_order_billing_address) {
											$this->MainModel->makeUpdate('platform_order_address', $OrderBillingAddressData, ['id' => $platform_order_billing_address->id]);
										} else {
											$this->MainModel->makeInsert('platform_order_address', $OrderBillingAddressData);
										}

										//order shipping address
										/* Custom Code | Need to do dynamic*/
										if ($name == "EMW") {
											$address_name = "ATTN: EmpowerMe Welness";
										} else if ($name == "ONR") {
											$address_name = "ATTN: ONR Rehab";
										} else {
											$address_name = $name;
										}
										/* Custom Email Code | Need to do dynamic*/
										$shipEmailAddress = "supplyorders@empowerme.com";
										$OrderShippingAddressData = ['platform_order_id' => $platform_order_id, 'address_type' => 'shipping', 'address_name' => $address_name, 'firstname' => $name, 'company' => $company, 'address1' => $address, 'city' => $city, 'state' => $state, 'postal_code' => $postal_code, 'country' => $country, 'phone_number' => $phone_number, 'email' => $shipEmailAddress];

										$platform_order_shipping_address = $this->MainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $platform_order_id, 'address_type' => 'shipping'], ['id']);
										if ($platform_order_shipping_address) {
											$this->MainModel->makeUpdate('platform_order_address', $OrderShippingAddressData, ['id' => $platform_order_shipping_address->id]);
										} else {
											$this->MainModel->makeInsert('platform_order_address', $OrderShippingAddressData);
										}

										$lineItems = [];

										$TherapySupplies = (isset($columnInfo['Therapy Supplies (PE)']) && isset($rowRecord[$columnInfo['Therapy Supplies (PE)']])) ? trim($rowRecord[$columnInfo['Therapy Supplies (PE)']]) : NULL;
										$TherapySupplyArray = explode(']', $TherapySupplies);
										foreach ($TherapySupplyArray as $TherapySupply) {
											if (strpos($TherapySupply, '[') !== false) {
												$item = explode('[', $TherapySupply);
												$itemName = trim($item[0]);
												$itemSku = trim($item[1]);

												$orderItem = [];
												$orderItem['name'] = $itemName;
												$orderItem['sku'] = $itemSku;
												$orderItem['qty'] = 1;
												$lineItems[] = $orderItem;
											}
										}

										$TherapySuppliesWithQuantities = (isset($columnInfo['Therapy Supplies (PE) with quantities']) && isset($rowRecord[$columnInfo['Therapy Supplies (PE) with quantities']])) ? trim($rowRecord[$columnInfo['Therapy Supplies (PE) with quantities']]) : NULL;
										$TherapySupplyArray = explode(']', $TherapySuppliesWithQuantities);
										foreach ($TherapySupplyArray as $TherapySupply) {
											if (strpos($TherapySupply, '[') !== false) {
												$item = explode('[', $TherapySupply);
												$itemName = trim($item[0]);
												$itemSku = trim($item[1]);

												$orderItem = [];
												$orderItem['name'] = $itemName;
												$orderItem['sku'] = $itemSku;
												$orderItem['qty'] = 1;
												$lineItems[] = $orderItem;
											}
										}

										$PPESuppliesWithQuantities = (isset($columnInfo['PPE Supplies with quantities']) && isset($rowRecord[$columnInfo['PPE Supplies with quantities']])) ? trim($rowRecord[$columnInfo['PPE Supplies with quantities']]) : NULL;
										$TherapySupplyArray = explode(']', $PPESuppliesWithQuantities);
										foreach ($TherapySupplyArray as $TherapySupply) {
											if (strpos($TherapySupply, '[') !== false) {
												$item = explode('[', $TherapySupply);
												$itemName = trim($item[0]);
												$itemSku = trim($item[1]);

												$orderItem = [];
												$orderItem['name'] = $itemName;
												$orderItem['sku'] = $itemSku;
												$orderItem['qty'] = 1;
												$lineItems[] = $orderItem;
											}
										}

										$AncillaryAgencySupplies = (isset($columnInfo['Ancillary Agency Supplies (PE)']) && isset($rowRecord[$columnInfo['Ancillary Agency Supplies (PE)']])) ? trim($rowRecord[$columnInfo['Ancillary Agency Supplies (PE)']]) : NULL;
										$TherapySupplyArray = explode(']', $AncillaryAgencySupplies);
										foreach ($TherapySupplyArray as $TherapySupply) {
											if (strpos($TherapySupply, '[') !== false) {
												$item = explode('[', $TherapySupply);
												$itemName = trim($item[0]);
												$itemSku = trim($item[1]);

												$orderItem = [];
												$orderItem['name'] = $itemName;
												$orderItem['sku'] = $itemSku;
												$orderItem['qty'] = 1;
												$lineItems[] = $orderItem;
											}
										}

										$AgencySuppliesWithQuantities = (isset($columnInfo['Agency Supplies (PE) with Quantities']) && isset($rowRecord[$columnInfo['Agency Supplies (PE) with Quantities']])) ? trim($rowRecord[$columnInfo['Agency Supplies (PE) with Quantities']]) : NULL;
										$TherapySupplyArray = explode(']', $AgencySuppliesWithQuantities);
										foreach ($TherapySupplyArray as $TherapySupply) {
											if (strpos($TherapySupply, '[') !== false) {
												$item = explode('[', $TherapySupply);
												$itemName = trim($item[0]);
												$itemSku = trim($item[1]);

												$orderItem = [];
												$orderItem['name'] = $itemName;
												$orderItem['sku'] = $itemSku;
												$orderItem['qty'] = 1;
												$lineItems[] = $orderItem;
											}
										}

										foreach ($columnInfo as $columnKey => $column) {
											if (strpos($columnKey, '[') !== false && strpos($columnKey, ']') !== false) {
												if ($rowRecord[$column]) {
													$item = explode(']', $columnKey);
													$itemSku = trim(str_replace('[', '', $item[0]));
													$itemName = trim(str_replace('QTY', '', $item[1]));
													$quantity = (int)$rowRecord[$column];

													//if ($quantity > 0) {
													$orderItem = [];
													$orderItem['name'] = $itemName;
													$orderItem['sku'] = $itemSku;
													$orderItem['qty'] = $quantity;
													$lineItems[] = $orderItem;
													//}
												}
											}
										}

										$platform_order_line_ids = [];
										foreach ($lineItems as $lineItem) {
											$product_name = ltrim($lineItem['name'], ', ');
											$product_name = ltrim($product_name, '", ');
											$product_name = ltrim($product_name, ', "');
											$product_name = rtrim($product_name, ' -');

											//order line item
											$OrderItemData = ['platform_order_id' => $platform_order_id, 'api_order_line_id' => $lineItem['sku'], 'api_product_id' => $lineItem['sku'], 'product_name' => $product_name, 'sku' => $lineItem['sku'], 'price' => 0, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'qty' => $lineItem['qty']];

											$platform_order_line = $this->MainModel->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'sku' => $lineItem['sku']], ['id']);
											if (is_null($platform_order_line)) {
												$platform_order_line_id = $this->MainModel->makeInsertGetId('platform_order_line', $OrderItemData);
												$platform_order_line_ids[] = $platform_order_line_id;
											} else {
												$this->MainModel->makeUpdate('platform_order_line', $OrderItemData, ['id' => $platform_order_line->id]);
												$platform_order_line_ids[] = $platform_order_line->id;
											}
										}

										PlatformOrderLine::where('platform_order_id', $platform_order_id)->whereNotIn('id', $platform_order_line_ids)->delete();
									}
								}
							}

							$max_sheet_order_updated_date = $rowRecord['modifiedAt'];
						}

						if ($url_with_page && $max_sheet_order_updated_date) {
							//Update last order fetch updated date time
							$url_with_page->url = $max_sheet_order_updated_date;
							$url_with_page->save();
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' - SmartsheetApiController - GetSalesOrders - ' . $e->getLine() . ' - ' . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}


	/* Update Sales Order Status */
	public function UpdateSalesOrderStatus($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_name, $record_id)
	{
		$return_response = false;
		try {
			/* Get Source Platform Details */
			$source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);

			$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token']);
			if ($platform_account && $source_platform_id) {
				$sales_order_object_id = $this->ConnectionHelper->getObjectId('sales_order');

				$sheetId = NULL;
				$default_so_sheet = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, 'default_so_sheet', ['api_id'], 'default');
				if ($default_so_sheet) {
					$sheetId = $default_so_sheet->api_id;
				}

				$sorder_complete_status = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, 'sorder_complete_status', ['custom_data'], 'default');
				if ($sorder_complete_status && $sorder_complete_status->custom_data) {
					$statusCode = $sorder_complete_status->custom_data;
				}

				$query = PlatformOrder::select('id', 'linked_id');
				if ($record_id) {
					$query->where('id', $record_id);
				} else {
					$query->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'sync_status' => 'Ready', 'order_type' => 'SO']);
				}

				$platform_orders = $query->where('linked_id', '<>', 0)->orderBy('id', 'ASC')->take(25)->get();

				foreach ($platform_orders as $platform_order) {
					$sync_error = NULL;
					if ($sheetId) {
						if ($statusCode) {
							$destination_platform_order = PlatformOrder::select('api_order_id', 'notes')->where('id', $platform_order->linked_id)->first();
							if ($destination_platform_order) {
								$columnId = NULL;
								$notes = $destination_platform_order->notes;
								$note_lists = explode('|', $notes);
								foreach ($note_lists as $note_list) {
									if ($note_list) {
										$statusColumn = explode(':', $note_list);
										if (isset($statusColumn[0]) && isset($statusColumn[1]) && $statusColumn[1] && $statusColumn[0] == 'status_column') {
											$columnId = $statusColumn[1];
										}
									}
								}

								if ($columnId) {
									$postData = array(
										array(
											'id' => $destination_platform_order->api_order_id,
											'cells' => array(
												array(
													'columnId' => $columnId,
													'value' => $statusCode
												)
											)
										)
									);

									$response = $this->SmartsheetApi->updateSalesOrderStatus($this->MainModel->encrypt_decrypt($platform_account->access_token, 'decrypt'), $sheetId, json_encode($postData));
									$result = json_decode($response, true);
									if (isset($result['resultCode'])) {
										/* change source platform order sync status */
										$platform_order->sync_status = 'Synced';
										$platform_order->save();

										/* save log */
										$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sales_order_object_id, 'success', $platform_order->id, NULL);
									} elseif (isset($result['errorCode'])) {
										$sync_error = $result['message'];
									} else {
										$sync_error = 'API Error';
									}

									sleep(1);
								} else {
									$sync_error = 'Smartsheet status column not found.';
								}
							} else {
								$sync_error = 'Smartsheet order not found.';
							}
						} else {
							$sync_error = 'Smartsheet complete status not define.';
						}
					} else {
						$sync_error = 'Smartsheet not found.';
					}

					if ($sync_error) {
						/* change source platform order sync status */
						$platform_order->sync_status = 'Failed';
						$platform_order->save();

						$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $sales_order_object_id, 'failed', $platform_order->id, $sync_error);
						$return_response = $sync_error;
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' --> SmartsheetApiController --> UpdateSalesOrderStatus --> ' . $e->getLine() . ' --> ' . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	public function test()
	{
		$response = $this->GetSalesOrders(495, 489, 1034);
		dd($response);
	}

	/* Execute Smartsheet Event Methods */
	public function ExecuteSmartsheetEvents($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = '')
	{
		$response = true;
		if ($method == 'GET' && $event == 'SHEET') {
			$response = $this->GetSheets($user_id, $user_integration_id);
		} elseif ($method == 'GET' && $event == 'SALESORDER') {
			$response = $this->GetSalesOrders($user_id, $user_integration_id, $user_workflow_rule_id);
		} elseif ($method == 'MUTATE' && $event == 'CHANGESALESORDERSTATUS') {
			$response = $this->UpdateSalesOrderStatus($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id);
		}

		return $response;
	}
}
