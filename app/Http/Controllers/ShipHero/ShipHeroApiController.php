<?php
	namespace App\Http\Controllers\ShipHero;

	use App\Http\Controllers\Controller;
	use Auth;
	use DB;
	use Illuminate\Http\Request;
	use App\Helper\MainModel;
	use App\Helper\ConnectionHelper;
	use App\Helper\FieldMappingHelper;
	use App\Helper\WorkflowSnippet;
	use App\Helper\Logger;
	use App\Models\PlatformAccount;
	use App\Models\PlatformOrder;
	use App\Models\PlatformProduct;
	use App\Http\Controllers\ShipHero\Api\ShipHeroApi;
	use Lang;
	class ShipHeroApiController extends Controller
	{
		public static $myPlatform = 'shiphero';

		/**
			* Create a new controller instance.
			*
			* @return void
		*/
		public function __construct()
		{
			$this->MainModel = new MainModel();
			$this->ShipHeroApi = new ShipHeroApi();
			$this->ConnectionHelper = new ConnectionHelper();
			$this->FieldMappingHelper = new FieldMappingHelper();
			$this->Logger = new Logger();
			$this->WorkflowSnippet = new WorkflowSnippet();
			$this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
		}

		public function InitiateShipHeroAuth(Request $request)
		{
			$platform = 'shiphero';
			return view("pages.apiauth.auth_shiphero", compact('platform'));
		}

		public function ConnectShipHeroAuth(Request $request)
		{
			$request->validate(['shiphero_username'=>'required', 'shiphero_password'=>'required']);

			$shiphero_username = trim($request->shiphero_username);
			$shiphero_password = trim($request->shiphero_password);

			$data = [];

			if($this->MainModel->checkHtmlTags( $request->all() ) ){
				$data['status_code'] = 0;
				$data['status_text'] = Lang::get('tags.validate');
				return json_encode($data);
			}

			try{
				$flag = true;
				//to check whether given account is already in use or not.
				$checkExistingAc = PlatformAccount::select('id')->where('platform_id', $this->platformId)->where('app_id', $this->MainModel->encryptString($shiphero_username))->where('app_secret', $this->MainModel->encryptString($shiphero_password))->first();
				if ($checkExistingAc)
				{
					$flag = false;
					$data['status_code'] = 0;
					$data['status_text'] = 'This account detail already exist, Try with another account.';
				}
				else
				{
					$request_data = array("username"=>$shiphero_username, "password"=>$shiphero_password);

					$response = $this->ShipHeroApi->Authentication(json_encode($request_data));
					$result = json_decode($response, true);
					if(isset($result['access_token']))
					{
						PlatformAccount::insert(['account_name'=>$shiphero_username, 'user_id'=>Auth::user()->id, 'platform_id'=>$this->platformId, 'app_id'=>$this->MainModel->encryptString($shiphero_username), 'app_secret'=>$this->MainModel->encryptString($shiphero_password), 'access_token'=>$this->MainModel->encryptString($result['access_token']), 'refresh_token'=>$this->MainModel->encryptString($result['refresh_token']), 'expires_in'=>$result['expires_in'], 'token_type'=>$result['token_type'], 'token_refresh_time'=>time(), 'allow_refresh'=>1]);
					}
					else
					{
						$flag = false;
						$data['status_code'] = 0;
						$data['status_text'] = 'Invalid '.self::$myPlatform.' credentials!';
					}
				}

				if($flag)
				{
					$data['status_code'] = 1;
					$data['status_text'] = 'Account connected successfully.';
				}
				return json_encode($data);
			}
			catch (\Exception $e)
			{
				$data['status_code'] = 0;
				$data['status_text'] = $e->getMessage();
				return json_encode($data);
			}
		}

		/*Refresh token*/
		function RefreshToken($ID)
		{
			$return_response = false;
			date_default_timezone_set('UTC');
			try
			{
				$platform_account = $this->MainModel->getFirstResultByConditions('platform_accounts', ['id'=>$ID], ['refresh_token']);
				if($platform_account)
				{
					$request_data = ['refresh_token'=>$this->MainModel->decryptString($platform_account->refresh_token)];

					$response = $this->ShipHeroApi->RefreshToken(json_encode($request_data));
					$result = json_decode($response, true);
					if(isset($result['access_token']))
					{
						$this->MainModel->makeUpdate('platform_accounts', ['access_token'=>$this->MainModel->encryptString($result['access_token']),'token_refresh_time'=>time()], ['id'=> $ID]);
						$return_response = true;
					}
					else
					{
						$return_response = "API Error";
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($ID.' - ShipHeroApiController - RefreshToken - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		public function GetWarehouses($user_id=0, $user_integration_id=0)
		{
			$return_data = true;
			try
			{
				$warehouse_object = $this->MainModel->getFirstResultByConditions('platform_objects', ['name'=>"warehouse"], ['id']);
				if($warehouse_object)
				{
					$this->MainModel->makeUpdate('platform_object_data', ['status'=>0], ['user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'platform_object_id'=>$warehouse_object->id, 'status'=>1]);

					$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token']);
					if($platform_account)
					{
						$request_data_json = '{"query":"query{ account{ data{ warehouses{ id legacy_id identifier }}}}"}';

						$response = $this->ShipHeroApi->CallAPI($this->MainModel->decryptString($platform_account->access_token), $request_data_json);
						$result = json_decode($response, true);
						if(isset($result['data']['account']['data']['warehouses'][0]['id']))
						{
							foreach($result['data']['account']['data']['warehouses'] as $warehouse)
							{
								$warehouseData = ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$warehouse_object->id, 'api_id'=>$warehouse['id'], 'name'=>$warehouse['identifier'], 'api_code'=>$warehouse['legacy_id'], 'description'=>$warehouse['identifier'], 'status'=>1];

								$platform_object_data = $this->MainModel->getFirstResultByConditions('platform_object_data', ['platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$warehouse_object->id, 'api_id'=>$warehouse['id']], ['id']);
								if($platform_object_data)
								{
									$this->MainModel->makeUpdate('platform_object_data', $warehouseData, ['id'=>$platform_object_data->id]);
								}
								else
								{
									$this->MainModel->makeInsert('platform_object_data', $warehouseData);
								}
							}
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - ShipHeroApiController - GetWarehouses - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function GetCustomerAccounts($user_id=0, $user_integration_id=0)
		{
			$return_data = true;
			try
			{
				$customer_account_object = $this->MainModel->getFirstResultByConditions('platform_objects', ['name'=>"customer_account"], ['id']);
				if($customer_account_object)
				{
					$this->MainModel->makeUpdate('platform_object_data', ['status'=>0], ['user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'platform_object_id'=>$customer_account_object->id, 'status'=>1]);

					$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token']);
					if($platform_account)
					{
						$request_data_json = '{"query":"query{ account{ data{ customers{ edges{ node{ id legacy_id email status is_3pl warehouses{company_alias}}}}}}}"}';

						$response = $this->ShipHeroApi->CallAPI($this->MainModel->decryptString($platform_account->access_token), $request_data_json);
						$result = json_decode($response, true);
						if(isset($result['data']['account']['data']['customers']['edges'][0]['node']['id']))
						{
							foreach($result['data']['account']['data']['customers']['edges'] as $customer)
							{
								$customerAccountData = ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$customer_account_object->id, 'api_id'=>$customer['node']['id'], 'name'=>$customer['node']['warehouses'][0]['company_alias'], 'api_code'=>$customer['node']['legacy_id'], 'description'=>$customer['node']['email'], 'status'=>(($customer['node']['status'] == 'active') ? 1 : 0)];

								$platform_object_data = $this->MainModel->getFirstResultByConditions('platform_object_data', ['platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$customer_account_object->id, 'api_id'=>$customer['node']['id']], ['id']);
								if($platform_object_data)
								{
									$this->MainModel->makeUpdate('platform_object_data', $customerAccountData, ['id'=>$platform_object_data->id]);
								}
								else
								{
									$this->MainModel->makeInsert('platform_object_data', $customerAccountData);
								}
							}
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - ShipHeroApiController - GetCustomerAccounts - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function getDestinationPlatformName($user_integration_id)
		{
			$destination_platform = NULL;
			$user_integration = $this->FieldMappingHelper->getUserIntegrationDetailsById($user_integration_id, self::$myPlatform);
			if($user_integration)
			{
				$platform_account = $this->MainModel->getFirstResultByConditions('platform_accounts', ['id'=>$user_integration->selected_dc_account_id], ['platform_id']);
				if($platform_account)
				{
					$platform_lookup = $this->MainModel->getFirstResultByConditions('platform_lookup', ['id'=>$platform_account->platform_id], ['platform_id']);
					if($platform_lookup)
					{
						$destination_platform = $platform_lookup->platform_id;
					}
				}
			}
			return $destination_platform;
		}

		public function GetProducts($user_id=0, $user_integration_id=0, $is_initial_sync=0)
		{
			$return_data = true;
			try
			{
				$EventID = "GET_PRODUCT";
				$selectFields = ['e.event_id','ur.status'];
				$user_work_flow = $this->FieldMappingHelper->getUserIntegWorkFlow($user_integration_id, $EventID, $selectFields,  self::$myPlatform);
				/* First Check whether Order Sync is ON */
				if(isset($user_work_flow[$EventID]) && $user_work_flow[$EventID]['status'] == 1)
				{
					$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token']);
					if($platform_account)
					{
						$cursor_attr = "(first:100)";

						$platform_url = $this->MainModel->getFirstResultByConditions('platform_urls', [ 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'product_limit', 'status'=>1], ['id', 'url']);
						if($platform_url)
						{
							$platform_url_id = $platform_url->id;
							$cursor_attr = $platform_url->url;
						}
						else
						{
							$url_data = ['user_id'=>$user_id, 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'product_limit', 'url'=>'', 'status'=>1, 'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s')];
							$platform_url_id = $this->MainModel->makeInsertGetId('platform_urls', $url_data);
						}

						$callAPI = 1;
						do
						{
							$allow_next_cal = false;

							if($is_initial_sync)
							{
								$updated_from = '';
							}
							else
							{
								$updated_from = '(updated_from:"'.date('Y-m-d', strtotime('-1 day')).'")';
							}

							$request_data_json = '{"query":"query{ products'.$updated_from.'{ data'.$cursor_attr.'{ edges{ cursor node{ id legacy_id name sku barcode active updated_at warehouse_products{ warehouse_id on_hand updated_at }}}}}}"}';

							$response = $this->ShipHeroApi->CallAPI($this->MainModel->decryptString($platform_account->access_token), $request_data_json);
							$products = json_decode($response, true);
							if(isset($products['data']['products']['data']['edges'][0]['node']['id']))
							{
								$allow_next_cal = true;
								foreach($products['data']['products']['data']['edges'] as $node)
								{
									$product = $node['node'];

									//product details
									$productData = ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$product['id'], 'api_product_code'=>$product['legacy_id'], 'product_name'=>$product['name'], 'sku'=>$product['sku'], 'barcode'=>$product['barcode'], 'updated_at'=>date('Y-m-d H:i:s'), 'api_updated_at'=>$product['updated_at'], 'is_deleted'=>($product['active'] ? 0 : 1)];

									$platform_product = $this->MainModel->getFirstResultByConditions('platform_product', [ 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$product['id']], ['id', 'api_updated_at', 'linked_id']);
									if($platform_product)
									{
										if(strtotime($platform_product->api_updated_at) != strtotime($product['updated_at']) && $platform_product->linked_id == 0)
										{
											$productData['product_sync_status'] = 'Ready';
										}

										$this->MainModel->makeUpdate('platform_product', $productData, ['id'=>$platform_product->id]);
										$platform_product_id = $platform_product->id;
									}
									else
									{
										$productData['product_sync_status'] = 'Ready';
										//$productData['inventory_sync_status'] = 'Ready';
										$productData['created_at'] = date('Y-m-d H:i:s');
										$platform_product_id = $this->MainModel->makeInsertGetId('platform_product', $productData);
									}

									if(isset($product['warehouse_products'][0]['warehouse_id']))
									{
										foreach($product['warehouse_products'] as $inventory)
										{
											//inventory details
											$inventoryData = ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_product_id'=>$platform_product_id, 'api_product_id'=>$product['id'], 'quantity'=>$inventory['on_hand'], 'sku'=>$product['sku'], 'api_warehouse_id'=>$inventory['warehouse_id'], 'updated_at'=>date('Y-m-d H:i:s'), 'api_updated_at'=>$inventory['updated_at']];

											$platform_product_inventory = $this->MainModel->getFirstResultByConditions('platform_product_inventory', ['platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_product_id'=>$platform_product_id, 'api_warehouse_id'=>$inventory['warehouse_id']], ['id']);
											if($platform_product_inventory)
											{
												$this->MainModel->makeUpdate('platform_product_inventory', $inventoryData, ['id'=>$platform_product_inventory->id]);
											}
											else
											{
												$inventoryData['sync_status'] = 'Ready';
												$inventoryData['created_at'] = date('Y-m-d H:i:s');
												$this->MainModel->makeInsertGetId('platform_product_inventory', $inventoryData);
											}
										}
									}

									$cursor_attr = '(first:100 after:"'.$node['cursor'].'")';
								}

								//max 4 time run this function in single call
								if(($callAPI % 4) == 0)
								{
									$allow_next_cal=false;
								}

								$callAPI++;

								if(count($products['data']['products']['data']['edges']) == 100)
								{
									$this->MainModel->makeUpdate('platform_urls', ['url'=>$cursor_attr, 'updated_at'=>date('Y-m-d H:i:s')], ['id'=>$platform_url_id]);

									$return_data = "Next cursor ".$cursor_attr." data";
								}
								else
								{
									$allow_next_cal=false;
									$this->MainModel->makeUpdate('platform_urls', ['url'=>'', 'updated_at'=>date('Y-m-d H:i:s')], ['id'=>$platform_url_id]);

									$return_data = true;
								}
							}
						}
						while($allow_next_cal);
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - ShipHeroApiController - GetProducts - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function GetVendors($user_id=0, $user_integration_id=0, $is_initial_sync=0)
		{
			$return_data = true;
			try
			{
				$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token']);
				if($platform_account)
				{
					$cursor_attr = "(first:100)";

					$platform_url = $this->MainModel->getFirstResultByConditions('platform_urls', [ 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'vendor_limit', 'status'=>1], ['id', 'url']);
					if($platform_url)
					{
						$platform_url_id = $platform_url->id;
						$cursor_attr = $platform_url->url;
					}
					else
					{
						$url_data = ['user_id'=>$user_id, 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'vendor_limit', 'url'=>'', 'status'=>1, 'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s')];
						$platform_url_id = $this->MainModel->makeInsertGetId('platform_urls', $url_data);
					}

					$callAPI = 1;
					do
					{
						$allow_next_cal = false;

						if($is_initial_sync)
						{
							$updated_from = '';
						}
						else
						{
							$updated_from = '(updated_from:"'.date('Y-m-d', strtotime('-1 day')).'")';
						}

						$request_data_json = '{"query":"query{ vendors'.$updated_from.'{ data'.$cursor_attr.'{ edges{ cursor node{ id legacy_id name email account_number account_id address{name address1 address2 city state country zip phone } currency internal_note default_po_note logo partner_vendor_id created_at }}}}}"}';

						$response = $this->ShipHeroApi->CallAPI($this->MainModel->decryptString($platform_account->access_token), $request_data_json);
						$vendors = json_decode($response, true);
						if(isset($vendors['data']['vendors']['data']['edges'][0]['node']['id']))
						{
							$allow_next_cal = true;
							foreach($vendors['data']['vendors']['data']['edges'] as $node)
							{
								$vendor = $node['node'];

								//vendor details
								$vendorData = ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'api_customer_id'=>$vendor['id'], 'api_customer_code'=>$vendor['legacy_id'], 'customer_name'=>$vendor['name'], 'email'=>$vendor['email'], 'type'=>'Vendor', 'updated_at'=>date('Y-m-d H:i:s'), 'api_created_at'=>$vendor['created_at'], 'api_updated_at'=>date('Y-m-d H:i:s')];

								$platform_customer = $this->MainModel->getFirstResultByConditions('platform_customer', [ 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'type'=>'Vendor', 'api_customer_id'=>$vendor['id']], ['id', 'linked_id']);
								if($platform_customer)
								{
									if($platform_customer->linked_id == 0)
									{
										$vendorData['sync_status'] = 'Ready';
									}

									$this->MainModel->makeUpdate('platform_customer', $vendorData, ['id'=>$platform_customer->id]);
								}
								else
								{
									$vendorData['sync_status'] = 'Ready';
									$vendorData['created_at'] = date('Y-m-d H:i:s');
									$this->MainModel->makeInsert('platform_customer', $vendorData);
								}

								$cursor_attr = '(first:100 after:"'.$node['cursor'].'")';
							}

							//max 4 time run this function in single call
							if(($callAPI % 4) == 0)
							{
								$allow_next_cal=false;
							}

							$callAPI++;

							if(count($vendors['data']['vendors']['data']['edges']) == 100)
							{
								$this->MainModel->makeUpdate('platform_urls', ['url'=>$cursor_attr, 'updated_at'=>date('Y-m-d H:i:s')], ['id'=>$platform_url_id]);

								$return_data = "Next cursor ".$cursor_attr." data";
							}
							else
							{
								$allow_next_cal=false;
								$this->MainModel->makeUpdate('platform_urls', ['url'=>'', 'updated_at'=>date('Y-m-d H:i:s')], ['id'=>$platform_url_id]);

								$return_data = true;
							}
						}
					}
					while($allow_next_cal);
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - ShipHeroApiController - GetVendors - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		/* Create Webhook */
		public function CreateWebhook($userId=NULL, $userIntegrationId=NULL, $webhookEvent=NULL)
		{
			$return_response = false;
			try
			{
				$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token']);
				if($platform_account)
				{
					if($webhookEvent)
					{
						//create webhook
						$platform_webhook_info = DB::table('platform_webhook_info')->where('user_integration_id', $userIntegrationId)->where('platform_id', $this->platformId)->where('description', $webhookEvent)->where('status', 1)->first();
						if(is_null($platform_webhook_info))
						{
							$BaseURL = env('APP_WEBHOOK_URL');

							/*Please pass last param as if APP_ENV=stag or local then 0 for staging/local mode and APP_ENV=prod then 1=for live mode */
							$Mode = env('APP_ENV') == 'prod' ? "1" : "0";

							$url = NULL;
							if($webhookEvent == 'Shipment Update')
							{
								$url = $BaseURL."/shiphero/public/shipment/".$userIntegrationId."/".$Mode;
							}
							elseif($webhookEvent == 'Inventory Update')
							{
								$url = $BaseURL."/shiphero/public/inventory/".$userIntegrationId."/".$Mode;
							}
							elseif($webhookEvent == 'PO Update')
							{
								$url = $BaseURL."/shiphero/public/po_revieved/".$userIntegrationId."/".$Mode;
							}

							$webhookQuery = '{"query":"mutation{webhook_create(data:{name:\"'.$webhookEvent.'\", url:\"'.$url.'\" shop_name:\"'.$userIntegrationId.'\"}) {request_id complexity webhook{ name url shared_signature_secret}}}","variables":{}}';

							$response = $this->ShipHeroApi->CallAPI($this->MainModel->decryptString($platform_account->access_token), $webhookQuery);
							$result = json_decode($response, true);
							if(isset($result['data']['webhook_create']['webhook']['shared_signature_secret']))
							{
								$webhookDetails = ['user_id'=>$userId, 'user_integration_id'=>$userIntegrationId, 'platform_id'=>$this->platformId, 'api_id'=>$result['data']['webhook_create']['webhook']['shared_signature_secret'], 'description'=>$webhookEvent, 'status'=>1];
								$this->MainModel->makeInsert('platform_webhook_info', $webhookDetails);
								$return_response = true;
							}
							else
							{
								$return_response = "Webhook not create for ".$webhookEvent." event";
							}
						}
						else
						{
							$return_response = true;
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($userIntegrationId.' - ShipHeroApiController - CreateWebhook - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		/* Delete Webhook */
		public function DeleteWebhooks($userId=NULL, $userIntegrationId=NULL)
		{
			$return_response = false;
			try
			{
				$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token']);
				if($platform_account)
				{
					//delete webhook
					$platform_webhooks = DB::table('platform_webhook_info')->select('id', 'description')->where('user_integration_id', $userIntegrationId)->where('platform_id', $this->platformId)->where('status', 1)->get();
					foreach($platform_webhooks as $platform_webhook)
					{
						$request_data_json = '{"query":"mutation{webhook_delete(data:{name:\"'.$platform_webhook->description.'\" shop_name:\"'.$userIntegrationId.'\"}){request_id complexity}}","variables":{}}';

						$response = $this->ShipHeroApi->CallAPI($this->MainModel->decryptString($platform_account->access_token), $request_data_json);
						$result = json_decode($response, true);
						if(isset($result['data']['webhook_delete']['request_id']))
						{
							$this->MainModel->makeDelete('platform_webhook_info', ['id'=>$platform_webhook->id]);
							$return_response = true;
						}
						else
						{
							$return_response = "Webhook not delete for ".$platform_webhook->description." event";
						}

						$return_response = true;
					}
					$return_response = true;
				}
			}
			catch(\Exception $e)
			{
				\Log::error($userIntegrationId.' - ShipHeroApiController - DeleteWebhooks - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		public function GetShipment($userId=NULL, $userIntegrationId=NULL, $webhookEvent=NULL, $is_initial_syn)
		{
			$return_response = false;
			try
			{
				if($is_initial_syn)
				{
					return $this->CreateWebhook($userId, $userIntegrationId, $webhookEvent);
				}
				$return_response = true;
			}
			catch(\Exception $e)
			{
				\Log::error($userIntegrationId.' - ShipHeroApiController - GetShipment - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		/* Receive Order shipment webhook */
		public function ReceiveShipmentWebhook(Request $request, $userIntegrationId)
		{
			$return_response = false;
			try
			{
				if($request->isMethod('post'))
				{
					$EventID = "GET_SHIPMENT";
					$user_work_flow = [];
					
					$integration = $this->FieldMappingHelper->getUserIntegrationDetailsById($userIntegrationId, self::$myPlatform);
					if($integration){
						$userId = $integration->user_id;
						$selectFields = ['e.event_id','ur.user_id','ur.status'];
						$user_work_flow = $this->FieldMappingHelper->getUserIntegWorkFlow($userIntegrationId, $EventID, $selectFields, self::$myPlatform);
				
						if(isset($user_work_flow[$EventID]))
						{
							$userId = $user_work_flow[$EventID]['user_id'];
							/* Check whether shipment is ON or OFF */
							if($user_work_flow[$EventID]['status'] == 1)
							{
								$body = $request->getContent();

								//{"test":"0","webhook_type":"Shipment Update","fulfillment":{"shipment_id":243781217,"shipment_uuid":"U2hpcG1lbnQ6MjQzNzgxMjE3","warehouse":"Primary","warehouse_id":80600,"warehouse_uuid":"V2FyZWhvdXNlOjgwNjAw","webhook_type":"Shipment Update","partner_order_id":"Test_Order123","order_number":"Test_Order123","tracking_number":"Pickup","line_items":[{"id":"test_line","shiphero_id":663869930,"quantity":1,"sku":"Test1234","serial_numbers":[],"customs_description":null,"package":"Package #1","lot_id":null,"lot_name":null,"lot_expiration":null}],"custom_tracking_url":"","shipping_method":"genericlabel","shipping_carrier":"genericlabel","shipping_address":{"name":"John Johnson","address1":"2543 Duck St.","address2":"Apt. 2","address_city":"Oklahoma","address_zip":"73008","address_state":"OK","address_country":"US"},"package":{"length":1,"width":1,"height":1,"weight":33},"completed":true,"created_at":"2022-03-08 06:56:44","order_uuid":"T3JkZXI6MjUzMjIyODk1","order_gift_note":""}}

								/* Decode Json */
								$result_data = json_decode($body, 1);
								if(isset($result_data['fulfillment']['shipment_id']))
								{
									$shipment = $result_data['fulfillment'];

									$platform_order = $this->MainModel->getFirstResultByConditions('platform_order', ['platform_id'=>$this->platformId, 'user_integration_id'=>$userIntegrationId, 'api_order_id'=>$shipment['order_uuid'], 'order_type'=>'SO'], ['id']);
									if($platform_order)
									{
										$shipmentData = ['user_id'=>$userId, 'platform_id'=>$this->platformId, 'user_integration_id'=>$userIntegrationId, 'shipment_id'=>$shipment['shipment_id'], 'platform_order_id'=>$platform_order->id, 'order_id'=>$shipment['order_uuid'], 'tracking_info'=>$shipment['tracking_number'], 'shipping_method'=>$shipment['shipping_method'], 'carrier_code'=>$shipment['shipping_carrier'], 'tracking_url'=>$shipment['custom_tracking_url'], 'boxes'=>json_encode($shipment['package']), 'weight'=>$shipment['package']['weight'], 'sync_status'=>'Ready', 'updated_at'=>date('Y-m-d H:i:s')];

										$platform_order_shipment = $this->MainModel->getFirstResultByConditions('platform_order_shipments', ['platform_id'=>$this->platformId, 'user_integration_id'=>$userIntegrationId, 'shipment_id'=>$shipment['shipment_id']], ['id']);
										if($platform_order_shipment)
										{
											$platform_order_shipment_id = $platform_order_shipment->id;
											$this->MainModel->makeUpdate('platform_order_shipments', $shipmentData, ['id'=>$platform_order_shipment->id]);
										}
										else
										{
											$shipmentData['sync_status'] = 'Ready';
											$shipmentData['created_at'] = date('Y-m-d H:i:s');
											$platform_order_shipment_id = $this->MainModel->makeInsertGetId('platform_order_shipments', $shipmentData);
										}

										if(isset($shipment['line_items'][0]['id']))
										{
											foreach($shipment['line_items'] as $line_item)
											{
												$shipmentLineData = ['platform_order_shipment_id'=>$platform_order_shipment_id, 'sku'=>$line_item['sku'], 'quantity'=>$line_item['quantity'], 'user_batch_reference'=>$line_item['package'], 'updated_at'=>date('Y-m-d H:i:s')];

												$platform_order_shipment_line = $this->MainModel->getFirstResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id'=>$platform_order_shipment_id, 'sku'=>$line_item['sku']], ['id']);
												if($platform_order_shipment_line)
												{
													$this->MainModel->makeUpdate('platform_order_shipment_lines', $shipmentLineData, ['id'=>$platform_order_shipment_line->id]);
												}
												else
												{
													$shipmentLineData['created_at'] = date('Y-m-d H:i:s');
													$this->MainModel->makeInsert('platform_order_shipment_lines', $shipmentLineData);
												}
											}
										}

										$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Ready'], ['id'=>$platform_order->id]);
									}
								}
							}
						}
				    }
				}
			}
			catch(\Exception $e)
			{
				\Log::error($userIntegrationId.' - ShipHeroApiController - ReceiveShipmentWebhook - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		public function GetInventory($userId=NULL, $userIntegrationId=NULL, $webhookEvent=NULL, $is_initial_syn)
		{
			$return_response = false;
			try
			{
				if($is_initial_syn)
				{
					return $this->CreateWebhook($userId, $userIntegrationId, $webhookEvent);
				}
				else
				{
					return $this->ProcessInventoryWebhook($userId, $userIntegrationId);
				}
				$return_response = true;
			}
			catch(\Exception $e)
			{
				\Log::error($userIntegrationId.' - ShipHeroApiController - GetInventory - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		/* Receive Inventory webhook */
		public function ReceiveInventoryWebhook(Request $request, $userIntegrationId)
		{
			$return_response = false;
			try
			{
				if($request->isMethod('post'))
				{
					$EventID = "GET_INVENTORY";
					$user_work_flow = [];
					
					$integration = $this->FieldMappingHelper->getUserIntegrationDetailsById($userIntegrationId, self::$myPlatform);
					if($integration){
						$userId = $integration->user_id;
						$selectFields = ['e.event_id','ur.user_id','ur.status'];
						$user_work_flow = $this->FieldMappingHelper->getUserIntegWorkFlow($userIntegrationId, $EventID,$selectFields, self::$myPlatform);
					
						if(isset($user_work_flow[$EventID]))
						{
							$userId = $user_work_flow[$EventID]['user_id'];
							/* Check whether shipment is ON or OFF */
							if($user_work_flow[$EventID]['status'] == 1)
							{
								$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token']);
								if($platform_account)
								{
									$body = $request->getContent();

									/* Decode Json */
									$result_data = json_decode($body, 1);
									if(isset($result_data['inventory'][0]['sku']))
									{
										foreach($result_data['inventory'] as $webhook)
										{
											$platform_product = $this->MainModel->getFirstResultByConditions('platform_product', ['platform_id'=>$this->platformId, 'user_integration_id'=>$userIntegrationId, 'sku'=>$webhook['sku']], ['id']);
											if(is_null($platform_product))
											{
												//insert product details
												$this->MainModel->makeInsert('platform_product', ['user_id'=>$userId, 'platform_id'=>$this->platformId, 'user_integration_id'=>$userIntegrationId, 'sku'=>$webhook['sku'], 'inventory_sync_status'=>'Pending', 'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s')]);
											}
											else
											{
												//update product details
												$this->MainModel->makeUpdate('platform_product', ['inventory_sync_status'=>'Pending', 'updated_at'=>date('Y-m-d H:i:s')], ['id'=>$platform_product->id]);
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
			catch(\Exception $e)
			{
				\Log::error($userIntegrationId.' - ShipHeroApiController - ReceiveInventoryWebhook - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		/* Process Inventory webhook */
		public function ProcessInventoryWebhook($userId, $userIntegrationId)
		{
			$return_response = false;
			try
			{
				$process_limit = 25;

				$EventID = "GET_INVENTORY";

				$selectFields = ['e.event_id','ur.status'];
				
				$user_work_flow = $this->FieldMappingHelper->getUserIntegWorkFlow($userIntegrationId, $userId, $EventID, $selectFields,  self::$myPlatform);
				
				if(isset($user_work_flow[$EventID])){
					/* Check whether shipment is ON or OFF */
					if($user_work_flow[$EventID]['status'] == 1)
					{
					$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token']);
					if($platform_account)
					{
						$platform_products = DB::table('platform_product')->select('id', 'sku', 'linked_id')->where('user_integration_id', $userIntegrationId)->where('platform_id', $this->platformId)->where('inventory_sync_status', 'Pending')->where('is_deleted', 0)->limit($process_limit)->orderBy('updated_at', 'asc')->distinct()->get();
						foreach($platform_products as $platform_product)
						{
							$request_data_json = '{"query":"query{ product(sku:\"'.$platform_product->sku.'\"){ data{ id legacy_id name sku barcode active updated_at warehouse_products{ warehouse_id on_hand updated_at }}}}"}';

							$response = $this->ShipHeroApi->CallAPI($this->MainModel->decryptString($platform_account->access_token), $request_data_json);
							$result = json_decode($response, true);
							if(isset($result['data']['product']['data']['id']))
							{
								$product = $result['data']['product']['data'];

								//product details
								$productData = ['api_product_id'=>$product['id'], 'api_product_code'=>$product['legacy_id'], 'product_name'=>$product['name'], 'barcode'=>$product['barcode'], 'inventory_sync_status'=>'Ready', 'updated_at'=>date('Y-m-d H:i:s'), 'api_updated_at'=>$product['updated_at'], 'is_deleted'=>($product['active'] ? 0 : 1)];

								if($platform_product->linked_id == 0)
								{
									$productData['product_sync_status'] = 'Ready';
								}

								$this->MainModel->makeUpdate('platform_product', $productData, ['id'=>$platform_product->id]);

								if(isset($product['warehouse_products'][0]['warehouse_id']))
								{
									foreach($product['warehouse_products'] as $inventory)
									{
										//inventory details
										$inventoryData = ['user_id'=>$userId, 'platform_id'=>$this->platformId, 'user_integration_id'=>$userIntegrationId, 'platform_product_id'=>$platform_product->id, 'api_product_id'=>$product['id'], 'quantity'=>$inventory['on_hand'], 'sku'=>$product['sku'], 'api_warehouse_id'=>$inventory['warehouse_id'], 'updated_at'=>date('Y-m-d H:i:s'), 'api_updated_at'=>$inventory['updated_at']];

										$platform_product_inventory = $this->MainModel->getFirstResultByConditions('platform_product_inventory', ['platform_id'=>$this->platformId, 'user_integration_id'=>$userIntegrationId, 'platform_product_id'=>$platform_product->id, 'api_warehouse_id'=>$inventory['warehouse_id']], ['id', 'quantity']);
										if($platform_product_inventory)
										{
											if($platform_product_inventory->quantity != $inventory['on_hand'])
											{
												$inventoryData['sync_status'] = 'Ready';
											}

											$this->MainModel->makeUpdate('platform_product_inventory', $inventoryData, ['id'=>$platform_product_inventory->id]);
										}
										else
										{
											$inventoryData['sync_status'] = 'Ready';
											$inventoryData['created_at'] = date('Y-m-d H:i:s');
											$this->MainModel->makeInsert('platform_product_inventory', $inventoryData);
										}
									}
								}
								$return_response = true;
							}
						}
					}
				  }
				}
				
			}
			catch(\Exception $e)
			{
				\Log::error($userIntegrationId.' - ShipHeroApiController - ProcessInventoryWebhook - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		public function stringGraphQL($string)
		{
			return rawurlencode(str_replace('"', '\"', trim($string)));
		}

		/* Create Sales Orders */
		public function CreateSalesOrders($userId=NULL, $userIntegrationId=NULL, $UserWorkFlow=NULL, $SourcePlatformName=NULL, $RecordID=NULL)
		{
			$return_response = false;
			try
			{
				$limit = 25;

				$object_id = $this->ConnectionHelper->getObjectId('sales_order');
				$SourcePlatformId = $this->ConnectionHelper->getPlatformIdByName($SourcePlatformName);

				$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId);
				if($platform_account)
				{
					$DefaultOrderWarehouseId = NULL;
					$DefaultWarehouse = $this->FieldMappingHelper->getMappedDataByName($userIntegrationId, NULL, "order_warehouse", ['api_id']);
					if($DefaultWarehouse)
					{
						$DefaultOrderWarehouseId = $DefaultWarehouse->api_id;
					}

					$customer_account_id = '';
					$DefaultCustomerAccount = $this->FieldMappingHelper->getMappedDataByName($userIntegrationId, NULL, "default_customer_account", ['api_id']);
					if($DefaultCustomerAccount)
					{
						$customer_account_id = 'customer_account_id:"'.$DefaultCustomerAccount->api_id.'"';
					}

					$source_row_data = $destination_row_data = 'sku';
					$product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');
					$mapping_data = $this->FieldMappingHelper->getMappedField($userIntegrationId, NULL, $product_identity_obj_id);
					if($mapping_data)
					{
						$source_row_data = $destination_row_data = 'sku';
						if($mapping_data['destination_platform_id'] == 'shiphero')
						{
							$destination_row_data = $mapping_data['destination_row_data'];
							$source_row_data = $mapping_data['source_row_data'];
						}
						else
						{
							$destination_row_data = $mapping_data['source_row_data'];
							$source_row_data = $mapping_data['destination_row_data'];
						}
					}

					$query = PlatformOrder::select('id', 'order_number', 'order_date', 'total_discount', 'total_tax', 'discount_tax', 'total_amount', 'notes', 'linked_id', 'shipping_total', 'shipping_tax', 'carrier_code', 'warehouse_id', 'order_update_status', 'currency', 'shipping_method', 'payment_date', 'delivery_date', 'is_voided', 'net_amount', 'trading_partner_id', 'api_order_reference', 'order_status', 'file_name');
					if($RecordID)
					{
						$query->where('id', $RecordID);
					}
					else
					{
						$query->where(['user_integration_id'=>$userIntegrationId, 'platform_id'=>$SourcePlatformId, 'sync_status'=>'Ready']);
					}

					$platform_orders = $query->where('order_type', 'SO')->where('linked_id', 0)->where('is_voided', 0)->take($limit)->orderBy('id', 'asc')->get();

					foreach($platform_orders as $order)
					{
						/* Find Shipping Address */
						$shipping_address = '';
						$order_shipping_address = $this->MainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id'=>$order->id, 'address_type'=>'shipping'], ['address_name', 'firstname', 'lastname', 'address1', 'address2', 'city', 'state', 'postal_code', 'country', 'phone_number', 'email', 'company']);
						if($order_shipping_address)
						{
							$shipping_address = 'shipping_address:{
							first_name:"'.$this->stringGraphQL(($order_shipping_address->firstname) ? $order_shipping_address->firstname : $order_shipping_address->address_name).'"
							last_name:"'.(($order_shipping_address->lastname) ? $this->stringGraphQL($order_shipping_address->lastname) : '.').'"
							company:"'.$this->stringGraphQL($order_shipping_address->company).'"
							address1:"'.$this->stringGraphQL($order_shipping_address->address1).'"
							address2:"'.$this->stringGraphQL($order_shipping_address->address2).'"
							city:"'.$this->stringGraphQL($order_shipping_address->city).'"
							state:"'.$this->stringGraphQL($order_shipping_address->state).'"
							state_code:"'.$this->stringGraphQL($order_shipping_address->state).'"
							zip:"'.$this->stringGraphQL($order_shipping_address->postal_code).'"
							country:"'.$this->stringGraphQL($order_shipping_address->country).'"
							country_code:"'.$this->stringGraphQL($order_shipping_address->country).'"
							email:"'.$this->stringGraphQL($order_shipping_address->email).'"
							phone:"'.$this->stringGraphQL($order_shipping_address->phone_number).'"
							}';
						}

						/* Find Billing Address */
						$billing_address = '';
						$order_billing_address = $this->MainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id'=>$order->id, 'address_type'=>'billing'], ['address_name', 'firstname', 'lastname', 'address1', 'address2', 'city', 'state', 'postal_code', 'country', 'phone_number', 'email', 'company']);
						if($order_billing_address)
						{
							$billing_address = 'billing_address:{
							first_name:"'.$this->stringGraphQL(($order_billing_address->firstname) ? $order_billing_address->firstname : $order_billing_address->address_name).'"
							last_name:"'.(($order_billing_address->lastname) ? $this->stringGraphQL($order_billing_address->lastname) : '.').'"
							company:"'.$this->stringGraphQL($order_billing_address->company).'"
							address1:"'.$this->stringGraphQL($order_billing_address->address1).'"
							address2:"'.$this->stringGraphQL($order_billing_address->address2).'"
							city:"'.$this->stringGraphQL($order_billing_address->city).'"
							state:"'.$this->stringGraphQL($order_billing_address->state).'"
							state_code:"'.$this->stringGraphQL($order_billing_address->state).'"
							zip:"'.$this->stringGraphQL($order_billing_address->postal_code).'"
							country:"'.$this->stringGraphQL($order_billing_address->country).'"
							country_code:"'.$this->stringGraphQL($order_billing_address->country).'"
							email:"'.$this->stringGraphQL($order_billing_address->email).'"
							phone:"'.$this->stringGraphQL($order_billing_address->phone_number).'"
							}';
						}

						$warehouse = '';
						if($DefaultOrderWarehouseId)
						{
							$warehouse = 'warehouse_id:"'.$DefaultOrderWarehouseId.'"';
						}

						$line_items = '';
						$SHIPPING = 0;
						$DISCOUNT = 0;
						$TAX = 0;
						$platform_order_lines = $this->MainModel->getResultByConditions('platform_order_line', ['platform_order_id'=>$order->id, 'is_deleted'=>0], ['id', 'product_name', 'sku', 'qty', 'price', 'unit_price', 'ean', 'gtin', 'upc', 'mpn', 'total', 'total_tax', 'row_type', 'item_row_sequence', 'description', 'notes'], ['item_row_sequence'=>'asc', 'id'=>'asc', 'row_type'=>'asc']);
						foreach($platform_order_lines as $platform_order_line)
						{
							if($platform_order_line->row_type == 'ITEM')
							{
								if($platform_order_line->qty)
								{
									$product_name = '';
									$platform_product = $this->MainModel->getFirstResultByConditions('platform_product', [ 'user_integration_id'=>$userIntegrationId, 'platform_id'=>$SourcePlatformId, $source_row_data=>$platform_order_line->{$source_row_data}], ['product_name']);
									if($platform_product && $platform_product->product_name)
									{
										$product_name = 'product_name:"'.$this->stringGraphQL($platform_product->product_name).'"';
									}

									$custom_options = '';
									if($platform_order_line->upc && ($platform_order_line->description || $platform_order_line->notes))
									{
										$custom_options = 'custom_options:{style:"'.$this->stringGraphQL($platform_order_line->sku).'" sequence:"'.$platform_order_line->item_row_sequence.'" color:"'.$this->stringGraphQL($platform_order_line->description).'" size:"'.$this->stringGraphQL($platform_order_line->notes).'"}';
									}

									$line_items .= '{sku:"'.$platform_order_line->{$source_row_data}.'" '.$product_name.' partner_line_item_id:"'.$platform_order_line->id.'" quantity:'.$platform_order_line->qty.' price:"'.round(($platform_order_line->total/$platform_order_line->qty), 2).'" '.$warehouse.' '.$custom_options.'}';
								}
							}
							elseif($platform_order_line->row_type == 'SHIPPING')
							{
								$SHIPPING = $SHIPPING + $platform_order_line->total;
							}
							elseif($platform_order_line->row_type == 'DISCOUNT')
							{
								$DISCOUNT = $DISCOUNT + $platform_order_line->total;
							}
							elseif($platform_order_line->row_type == 'TAX')
							{
								$TAX = $TAX + $platform_order_line->total;
							}
						}

						$total_discounts = $order->total_discount;
						$total_tax = $order->total_tax;
						$shipping_price = $order->shipping_total;

						if($SHIPPING){ $shipping_price = $SHIPPING; }
						if($TAX){ $total_tax = $TAX; }

						if($DISCOUNT)
						{
							$total_discounts = $DISCOUNT;
							if($DISCOUNT < 0)
							{
								$total_discounts = $DISCOUNT * (-1);
							}
						}

						$required_ship_date = '';
						if($order->delivery_date)
						{
							$required_ship_date = 'required_ship_date:"'.date("Y-m-d H:i:s", strtotime($order->delivery_date)).'"';
						}

						$tags = '';
						$packing_note = '';
						if($order->trading_partner_id && $order->api_order_reference)
						{
							$tags = 'tags:["Order#:'.$this->stringGraphQL($order->api_order_reference).'", "PO#:'.$this->stringGraphQL($order->trading_partner_id).'", "ALT PO#:'.$this->stringGraphQL($order->file_name).'"]';

							$packing_note = 'packing_note:"Order#:'.$this->stringGraphQL($order->api_order_reference).', PO#:'.$this->stringGraphQL($order->trading_partner_id).', ALT PO#:'.$this->stringGraphQL($order->file_name).'"';
						}

						//shipping_lines:{title:"'.(($order->carrier_code) ? $this->stringGraphQL($order->carrier_code) : $this->stringGraphQL($order->shipping_method)).'" price:"'.round($shipping_price, 2).'" carrier:"'.$this->stringGraphQL($order->carrier_code).'" method:"'.$this->stringGraphQL($order->shipping_method).'"}

						$create_order_data = 'query=mutation{
						order_create(
						data:{
						'.$customer_account_id.'
						order_number:"'.$this->stringGraphQL($order->order_number).'"
						fulfillment_status:"pending"
						order_date:"'.date("Y-m-d H:i:s", strtotime($order->order_date)).'"
						total_tax:"'.round($total_tax, 2).'"
						subtotal:"'.round($order->net_amount, 2).'"
						total_discounts:"'.round($total_discounts, 2).'"
						total_price:"'.round($order->total_amount, 2).'"
						shipping_lines:{title:"Manual Order Shipping Method" price:"'.round($shipping_price, 2).'" carrier:"Genericlabel" method:"Generic"},
						'.$shipping_address.'
						'.$billing_address.'
						'.$tags.'
						'.$packing_note.'
						line_items:['.$line_items.']
						'.$required_ship_date.'
						}
						){order{id legacy_id}}
						}';

						$response = $this->ShipHeroApi->CreateOrder($this->MainModel->decryptString($platform_account->access_token), $create_order_data);
						$result = json_decode($response, true);
						if(isset($result['data']['order_create']['order']['id']))
						{
							$OrderLinked = $this->MainModel->makeInsertGetId('platform_order', ['user_id'=>$userId, 'platform_id'=>$this->platformId, 'user_integration_id'=>$userIntegrationId, 'order_type'=>"SO", 'api_order_id'=>$result['data']['order_create']['order']['id'], 'api_order_reference'=>$result['data']['order_create']['order']['legacy_id'], 'order_date'=>date("Y-m-d H:i:s"), 'order_number'=>$order->order_number, 'sync_status'=>'Pending', 'linked_id'=>$order->id, 'shipment_status'=>"Pending", 'created_at'=>date("Y-m-d H:i:s"), 'updated_at'=>date("Y-m-d H:i:s"), 'order_updated_at'=>date("Y-m-d H:i:s")]);

							$this->MainModel->makeUpdate('platform_order', ['linked_id'=>$OrderLinked, 'sync_status'=>'Synced'], ['id'=>$order->id]);

							$this->Logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $order->id, 'Sales order synced successfully!');
							$return_response = true;
						}
						elseif(isset($result['errors'][0]['message']))
						{
							$error = $result['errors'][0]['message'];
							$this->MainModel->makeUpdate('platform_order', ['sync_status'=>'Failed'], ['id'=>$order->id]);
							$this->Logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order->id, $result['errors'][0]['message']);
							$return_response = $error;
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($userIntegrationId.' - ShipHeroApiController - CreateSalesOrders - '.$e->getLine().' - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		/* Create Purchase Orders */
		public function CreatePurchaseOrders($userId=NULL, $userIntegrationId=NULL, $UserWorkFlow=NULL, $SourcePlatformName=NULL, $RecordID=NULL)
		{
			$return_response = false;
			try
			{
				$limit = 25;

				$object_id = $this->ConnectionHelper->getObjectId('purchase_order');
				$SourcePlatformId = $this->ConnectionHelper->getPlatformIdByName($SourcePlatformName);

				$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId);
				if($platform_account)
				{
					$DefaultOrderWarehouseId = NULL;
					$DefaultWarehouse = $this->FieldMappingHelper->getMappedDataByName($userIntegrationId, NULL, "order_warehouse", ['api_id']);
					if($DefaultWarehouse)
					{
						$DefaultOrderWarehouseId = $DefaultWarehouse->api_id;
					}

					$customer_account_id = '';
					$DefaultCustomerAccount = $this->FieldMappingHelper->getMappedDataByName($userIntegrationId, NULL, "default_customer_account", ['api_id']);
					if($DefaultCustomerAccount)
					{
						$customer_account_id = 'customer_account_id:"'.$DefaultCustomerAccount->api_id.'"';
					}

					$source_row_data = $destination_row_data = 'sku';
					$product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');
					$mapping_data = $this->FieldMappingHelper->getMappedField($userIntegrationId, NULL, $product_identity_obj_id);
					if($mapping_data)
					{
						$source_row_data = $destination_row_data = 'sku';
						if($mapping_data['destination_platform_id'] == 'shiphero')
						{
							$destination_row_data = $mapping_data['destination_row_data'];
							$source_row_data = $mapping_data['source_row_data'];
						}
						else
						{
							$destination_row_data = $mapping_data['source_row_data'];
							$source_row_data = $mapping_data['destination_row_data'];
						}
					}

					$query = PlatformOrder::select('id', 'user_id', 'user_integration_id', 'platform_customer_id', 'order_number', 'order_date', 'total_discount', 'total_tax', 'discount_tax', 'total_amount', 'notes', 'linked_id', 'shipping_total', 'shipping_tax', 'carrier_code', 'warehouse_id', 'order_update_status', 'currency', 'shipping_method', 'payment_date', 'delivery_date', 'is_voided', 'net_amount', 'order_status');
					if($RecordID)
					{
						$query->where('id', $RecordID);
					}
					else
					{
						$query->where(['user_integration_id'=>$userIntegrationId, 'platform_id'=>$SourcePlatformId, 'sync_status'=>'Ready']);
					}

					$platform_orders = $query->where('order_type', 'PO')->where('linked_id', 0)->where('is_voided', 0)->take($limit)->orderBy('id', 'asc')->get();

					foreach($platform_orders as $order)
					{
						$vendor_id = NULL;
						$platform_customer = $this->MainModel->getFirstResultByConditions('platform_customer', ['id'=>$order->platform_customer_id], ['customer_name', 'email', 'linked_id']);
						if($platform_customer)
						{
							if($platform_customer->linked_id)
							{
								$destination_platform_customer = $this->MainModel->getFirstResultByConditions('platform_customer', ['id'=>$platform_customer->linked_id], ['api_customer_id']);
								if($destination_platform_customer)
								{
									$vendor_id = $destination_platform_customer->api_customer_id;
								}
							}

							if($vendor_id == NULL && $platform_customer->customer_name)
							{
								$destination_platform_customer = $this->MainModel->getFirstResultByConditions('platform_customer', ['customer_name'=>$platform_customer->customer_name, 'user_integration_id'=>$userIntegrationId, 'platform_id'=>$this->platformId, 'type'=>'Vendor'], ['api_customer_id']);
								if($destination_platform_customer)
								{
									$vendor_id = $destination_platform_customer->api_customer_id;
								}
							}

							if($vendor_id == NULL && $platform_customer->email)
							{
								$destination_platform_customer = $this->MainModel->getFirstResultByConditions('platform_customer', ['email'=>$platform_customer->email, 'user_integration_id'=>$userIntegrationId, 'platform_id'=>$this->platformId, 'type'=>'Vendor'], ['api_customer_id']);
								if($destination_platform_customer)
								{
									$vendor_id = $destination_platform_customer->api_customer_id;
								}
							}
						}

						if($vendor_id == NULL)
						{
							$vendor_id = $this->CreatePurchaseOrderVendor($platform_account, $customer_account_id, $order);
						}

						if($vendor_id == NULL)
						{
							$error = 'Vendor not available for this order!.';
							$this->MainModel->makeUpdate('platform_order', ['sync_status'=>'Failed'], ['id'=>$order->id]);
							$this->Logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order->id, 'Vendor not available for this order!.');
							$return_response = $error;
						}
						else
						{
							$warehouse = '';
							if($DefaultOrderWarehouseId)
							{
								$warehouse = 'warehouse_id:"'.$DefaultOrderWarehouseId.'"';
							}

							$line_items = '';
							$SHIPPING = 0;
							$DISCOUNT = 0;
							$TAX = 0;
							$platform_order_lines = $this->MainModel->getResultByConditions('platform_order_line', ['platform_order_id'=>$order->id, 'is_deleted'=>0], ['id', 'product_name', 'sku', 'qty', 'price', 'unit_price', 'ean', 'gtin', 'upc', 'mpn', 'total', 'total_tax', 'row_type', 'item_row_sequence', 'description', 'notes'], ['item_row_sequence'=>'asc', 'id'=>'asc', 'row_type'=>'asc']);
							foreach($platform_order_lines as $platform_order_line)
							{
								if($platform_order_line->row_type == 'ITEM')
								{
									if($platform_order_line->qty)
									{
										$vendor_sku = '';
										$expected_weight_in_lbs = 0.1;
										$platform_product = $this->MainModel->getFirstResultByConditions('platform_product', [$source_row_data=>$platform_order_line->{$source_row_data}, 'user_integration_id'=>$userIntegrationId, 'platform_id'=>$SourcePlatformId], ['manufacturer_sku', 'weight']);
										if($platform_product)
										{
											if($platform_product->manufacturer_sku)
											{
												$vendor_sku = $platform_product->manufacturer_sku;
											}

											if($platform_product->weight)
											{
												$expected_weight_in_lbs = $platform_product->weight;
											}
										}

										$line_items .= '{sku:"'.$this->stringGraphQL($platform_order_line->{$source_row_data}).'" quantity:'.$platform_order_line->qty.' vendor_id:"'.$vendor_id.'" vendor_sku:"'.$vendor_sku.'" price:"'.round(($platform_order_line->total/$platform_order_line->qty),2).'" expected_weight_in_lbs:"'.$expected_weight_in_lbs.'"}';
									}
								}
								elseif($platform_order_line->row_type == 'SHIPPING')
								{
									$SHIPPING = $SHIPPING + $platform_order_line->total;
								}
								elseif($platform_order_line->row_type == 'DISCOUNT')
								{
									$DISCOUNT = $DISCOUNT + $platform_order_line->total;
								}
								elseif($platform_order_line->row_type == 'TAX')
								{
									$TAX = $TAX + $platform_order_line->total;
								}
							}

							$total_discounts = $order->total_discount;
							$total_tax = $order->total_tax;
							$shipping_price = $order->shipping_total;

							if($SHIPPING){ $shipping_price = $SHIPPING; }
							if($TAX){ $total_tax = $TAX; }

							if($DISCOUNT)
							{
								$total_discounts = $DISCOUNT;
								if($DISCOUNT < 0)
								{
									$total_discounts = $DISCOUNT * (-1);
								}
							}

							$create_order_data = 'query=mutation{
							purchase_order_create(
							data:{
							'.$customer_account_id.'
							po_date:"'.($order->delivery_date ? $order->delivery_date : $order->order_date).'"
							po_number:"'.$order->order_number.'"
							subtotal:"'.round($order->net_amount, 2).'"
							tax:"'.round($total_tax, 2).'"
							shipping_price:"'.round($shipping_price, 2).'"
							total_price:"'.round($order->total_amount, 2).'"
							'.$warehouse.'
							line_items:['.$line_items.']
							discount:"'.round($total_discounts, 2).'"
							vendor_id:"'.$vendor_id.'"
							}
							){ purchase_order{ id legacy_id }}
							}';

							$response = $this->ShipHeroApi->CreateOrder($this->MainModel->decryptString($platform_account->access_token), $create_order_data);
							$result = json_decode($response, true);
							if(isset($result['data']['purchase_order_create']['purchase_order']['id']))
							{
								$OrderLinked = $this->MainModel->makeInsertGetId('platform_order', ['user_id'=>$userId, 'platform_id'=>$this->platformId, 'user_integration_id'=>$userIntegrationId, 'order_type'=>"PO", 'api_order_id'=>$result['data']['purchase_order_create']['purchase_order']['id'], 'api_order_reference'=>$result['data']['purchase_order_create']['purchase_order']['legacy_id'], 'order_date'=>date("Y-m-d H:i:s"), 'order_number'=>$order->order_number, 'sync_status'=>'Pending', 'linked_id'=>$order->id, 'shipment_status'=>"Pending", 'created_at'=>date("Y-m-d H:i:s"), 'updated_at'=>date("Y-m-d H:i:s"), 'order_updated_at'=>date("Y-m-d H:i:s")]);

								$this->MainModel->makeUpdate('platform_order', ['linked_id'=>$OrderLinked, 'sync_status'=>'Synced'], ['id'=>$order->id]);

								$this->Logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $order->id, NULL);

								$return_response = true;
							}
							elseif(isset($result['errors'][0]['message']))
							{
								$error = $result['errors'][0]['message'];
								$this->MainModel->makeUpdate('platform_order', ['sync_status'=>'Failed'], ['id'=>$order->id]);
								$this->Logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order->id, $result['errors'][0]['message']);
								$return_response = $error;
							}
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($userIntegrationId.' - ShipHeroApiController - CreatePurchaseOrders - '.$e->getLine().' - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		/* Create Purchase Order Vendor */
		public function CreatePurchaseOrderVendor($platform_account, $customer_account_id, $order)
		{
			$return_data = NULL;
			try
			{
				$platform_customer = $this->MainModel->getFirstResultByConditions('platform_customer', ['id'=>$order->platform_customer_id], ['id', 'customer_name', 'email', 'phone', 'address1', 'address2', 'address3', 'postal_addresses', 'country']);
				if($platform_customer)
				{
					$create_vendor_data = 'query=mutation{
					vendor_create(
					data:{
					'.$customer_account_id.'
					name:"'.$this->stringGraphQL($platform_customer->customer_name).'"
					email:"'.$this->stringGraphQL($platform_customer->email).'"
					address:{
					name:"'.$this->stringGraphQL($platform_customer->customer_name).'"
					address1:"'.$this->stringGraphQL($platform_customer->address1).'"
					address2:""
					city:"'.$this->stringGraphQL($platform_customer->address2).'"
					state:"'.$this->stringGraphQL($platform_customer->address3).'"
					zip:"'.$this->stringGraphQL($platform_customer->postal_addresses).'"
					country:"'.$this->stringGraphQL($platform_customer->country).'"
					phone:"'.$this->stringGraphQL($platform_customer->phone).'"
					}
					currency:"'.$this->stringGraphQL($order->currency).'"
					}) { vendor{ id legacy_id }}
					}';

					$response = $this->ShipHeroApi->CreateVendor($this->MainModel->decryptString($platform_account->access_token), $create_vendor_data);
					$result = json_decode($response, true);
					if(isset($result['data']['vendor_create']['vendor']['id']))
					{
						$linked_id = $this->MainModel->makeInsertGetId('platform_customer', ['user_id'=>$order->user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$order->user_integration_id, 'api_customer_id'=>$result['data']['vendor_create']['vendor']['id'], 'api_customer_code'=>$result['data']['vendor_create']['vendor']['legacy_id'], 'customer_name'=>$platform_customer->customer_name, 'email'=>$platform_customer->email, 'linked_id'=>$platform_customer->id, 'type'=>'Vendor']);

						$this->MainModel->makeUpdate('platform_customer', ['sync_status'=>'Synced', 'linked_id'=>$linked_id], ['id'=>$platform_customer->id]);

						$return_data = $result['data']['vendor_create']['vendor']['id'];
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error("ShipHeroApiController -> CreatePurchaseOrderVendor -> ".$e->getLine()." -> ".$e->getMessage());
				$return_data = NULL;
			}
			return $return_data;
		}

		/* Create Products */
		public function CreateProducts($user_id=0, $user_integration_id=0, $source_platform_name='', $user_workflow_rule_id=0, $record_id=0)
		{
			$return_data = true;
			$process_limit = 100;
			try
			{
				$source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
				$object_id = $this->ConnectionHelper->getObjectId('product');

				$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
				if($platform_account)
				{
					$DefaultProductWarehouseId = NULL;
					$DefaultWarehouse = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "inventory_warehouse", ['api_id']);
					if($DefaultWarehouse)
					{
						$DefaultProductWarehouseId = $DefaultWarehouse->api_id;
					}

					$customer_account_id = '';
					$DefaultCustomerAccount = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "default_customer_account", ['api_id']);
					if($DefaultCustomerAccount)
					{
						$customer_account_id = 'customer_account_id:"'.$DefaultCustomerAccount->api_id.'"';
					}

					$source_row_data = $destination_row_data = 'sku';
					$product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');
					$mapping_data = $this->FieldMappingHelper->getMappedField($user_integration_id, NULL, $product_identity_obj_id);
					if($mapping_data)
					{
						if($mapping_data['destination_platform_id'] == 'shiphero')
						{
							$destination_row_data = $mapping_data['destination_row_data'];
							$source_row_data = $mapping_data['source_row_data'];
						}
						else
						{
							$destination_row_data = $mapping_data['source_row_data'];
							$source_row_data = $mapping_data['destination_row_data'];
						}
					}

					$platform_products = PlatformProduct::select('id', 'product_name', 'ean', 'sku', 'upc', 'isbn', 'mpn', 'barcode', 'manufacturer_sku', 'weight', 'price', 'api_product_code', 'description', 'brand_id', 'category_id')
					->where(['user_integration_id'=>$user_integration_id, 'platform_id'=>$source_platform_id])
					->where(function($query) use ($record_id){
						if($record_id > 0)
						{
							$query->where('id', $record_id);
						}
						else
						{
							$query->where('product_sync_status', 'Ready');
						}
					})
					->where('is_deleted', 0)
					->limit($process_limit)
					->orderBy('id', 'asc')
					->distinct()
					->get();

					foreach($platform_products as $platform_product)
					{
						$destination_platform_product = $this->MainModel->getFirstResultByConditions('platform_product', [ 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'linked_id'=>$platform_product->id], ['id', 'api_product_id']);
						if(is_null($destination_platform_product))
						{
							$destination_platform_product = $this->MainModel->getFirstResultByConditions('platform_product', ['user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, $destination_row_data=>$platform_product->{$source_row_data}], ['id', 'api_product_id']);
						}

						$tags = '';
						$packer_note = '';
						$product_name = '';
						$platform_product_detail_attribute = $this->MainModel->getFirstResultByConditions('platform_product_detail_attributes', ['platform_product_id'=>$platform_product->id], ['product_type_ids', 'merch', 'material', 'product_type_desc', 'gender', 'style_type', 'color_code', 'size_desc']);
						if($platform_product_detail_attribute)
						{
							$tags = 'tags:["'.$this->stringGraphQL($platform_product_detail_attribute->merch).'", "'.$this->stringGraphQL($platform_product->brand_id).'", "'.$this->stringGraphQL($platform_product->category_id).'", "'.$this->stringGraphQL($platform_product_detail_attribute->material).'", "'.$this->stringGraphQL($platform_product_detail_attribute->product_type_ids).'", "'.$this->stringGraphQL($platform_product_detail_attribute->product_type_desc).'", "'.$this->stringGraphQL($platform_product_detail_attribute->gender).'", "'.$this->stringGraphQL($platform_product_detail_attribute->style_type).'"]';

							$packer_note = 'packer_note:"Manufacturer Sku - '.$this->stringGraphQL($platform_product->manufacturer_sku).' \nStyle Code - '.$this->stringGraphQL($platform_product->api_product_code).' \nStyle Size - '.$this->stringGraphQL($platform_product_detail_attribute->size_desc).' \nStyle Color - '.$this->stringGraphQL($platform_product_detail_attribute->color_code).' \nStyle Description - '.str_replace("%0D%0A", "%20", $this->stringGraphQL($platform_product->description)).'"';

							$product_name = $platform_product->product_name.'. Style Code - '.$platform_product->api_product_code.', Style Size - '.$platform_product_detail_attribute->size_desc.', Style Color - '.$platform_product_detail_attribute->color_code.'.';
							$product_name = str_replace("..", ".", $product_name);
						}
						else
						{
							$product_name = $platform_product->product_name;
						}

						$dimensions = '';
						if($platform_product->weight)
						{
							$dimensions = 'dimensions:{weight:"'.$platform_product->weight.'"}';
						}

						if(is_null($destination_platform_product))
						{
							$price = '';
							if($platform_product->price){$price = 'price:"'.round($platform_product->price, 2).'"';}

							$barcode = '';
							if($platform_product->barcode){$barcode = 'barcode:"'.$platform_product->barcode.'"';}

							$create_product_data = 'query=mutation{product_create(data:{'.$customer_account_id.' name:"'.$this->stringGraphQL($product_name).'" sku:"'.$platform_product->{$source_row_data}.'" '.$dimensions.' '.$price.' '.$barcode.' '.$tags.' warehouse_products:[{ warehouse_id:"'.$DefaultProductWarehouseId.'" on_hand:0 }]}){ product{ id legacy_id }}}';

							$response = $this->ShipHeroApi->CreateOrUpdateProduct($this->MainModel->decryptString($platform_account->access_token), $create_product_data);
							$result = json_decode($response, true);
							if(isset($result['data']['product_create']['product']['id']))
							{
								$linked_id = $this->MainModel->makeInsertGetId('platform_product', ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$result['data']['product_create']['product']['id'], 'api_product_code'=>$result['data']['product_create']['product']['legacy_id'], 'product_name'=>$product_name, $destination_row_data=>$platform_product->{$source_row_data}, 'linked_id'=>$platform_product->id]);
								$this->MainModel->makeUpdate('platform_product', ['product_sync_status'=>'Synced', 'linked_id'=>$linked_id], ['id'=>$platform_product->id]);
								$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $platform_product->id, 'Product synced successfully!');
								$return_data = true;

								$update_product_data = 'query=mutation{product_update(data:{'.$customer_account_id.' name:"'.$this->stringGraphQL($product_name).'" sku:"'.$platform_product->{$source_row_data}.'" '.$dimensions.' '.$barcode.' '.$packer_note.' '.$tags.'}){ product{ id legacy_id }}}';

								$this->ShipHeroApi->CreateOrUpdateProduct($this->MainModel->decryptString($platform_account->access_token), $update_product_data);
							}
							elseif(isset($result['errors'][0]['message']))
							{
								if($result['errors'][0]['message'] == 'A product with sku '.$platform_product->{$source_row_data}.' already exists')
								{
									$update_product_data = 'query=mutation{product_update(data:{'.$customer_account_id.' name:"'.$this->stringGraphQL($product_name).'" sku:"'.$platform_product->{$source_row_data}.'" '.$dimensions.' '.$barcode.' '.$packer_note.' '.$tags.'}){ product{ id legacy_id }}}';

									$response = $this->ShipHeroApi->CreateOrUpdateProduct($this->MainModel->decryptString($platform_account->access_token), $update_product_data);
									$result = json_decode($response, true);
									if(isset($result['data']['product_update']['product']['id']))
									{
										$linked_id = $this->MainModel->makeInsertGetId('platform_product', ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$result['data']['product_update']['product']['id'], 'api_product_code'=>$result['data']['product_update']['product']['legacy_id'], 'product_name'=>$product_name, $destination_row_data=>$platform_product->{$source_row_data}, 'linked_id'=>$platform_product->id]);
										$this->MainModel->makeUpdate('platform_product', ['product_sync_status'=>'Synced', 'linked_id'=>$linked_id], ['id'=>$platform_product->id]);
										$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $platform_product->id, 'Product synced successfully!');
										$return_data = true;
									}
									elseif(isset($result['errors'][0]['message']))
									{
										$error = $result['errors'][0]['message'];
										$this->MainModel->makeUpdate('platform_product', ['product_sync_status'=>'Failed'], ['id'=>$platform_product->id]);
										$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, $result['errors'][0]['message']);
										$return_data = $error;
									}
								}
								else
								{
									$error = $result['errors'][0]['message'];
									$this->MainModel->makeUpdate('platform_product', ['product_sync_status'=>'Failed'], ['id'=>$platform_product->id]);
									$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, $result['errors'][0]['message']);
									$return_data = $error;
								}
							}
						}
						else
						{
							$barcode = '';
							if($platform_product->barcode){$barcode = 'barcode:"'.$platform_product->barcode.'"';}

							$update_product_data = 'query=mutation{product_update(data:{'.$customer_account_id.' name:"'.$this->stringGraphQL($product_name).'" sku:"'.$platform_product->{$source_row_data}.'" '.$dimensions.' '.$barcode.' '.$packer_note.' '.$tags.'}){ product{ id legacy_id }}}';

							$response = $this->ShipHeroApi->CreateOrUpdateProduct($this->MainModel->decryptString($platform_account->access_token), $update_product_data);
							$result = json_decode($response, true);
							if(isset($result['data']['product_update']['product']['id']))
							{
								$this->MainModel->makeUpdate('platform_product', ['linked_id'=>$platform_product->id], ['id'=>$destination_platform_product->id]);
								$this->MainModel->makeUpdate('platform_product', ['product_sync_status'=>'Synced', 'linked_id'=>$destination_platform_product->id], ['id'=>$platform_product->id]);
								$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $platform_product->id, 'Product synced successfully!');
								$return_data = true;
							}
							elseif(isset($result['errors'][0]['message']))
							{
								$error = $result['errors'][0]['message'];
								$this->MainModel->makeUpdate('platform_product', ['product_sync_status'=>'Failed'], ['id'=>$platform_product->id]);
								$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, $result['errors'][0]['message']);
								$return_data = $error;
							}
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id . " -> ShipHeroApiController -> CreateProducts -> ".$e->getLine()." -> ".$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		/* set webhook for PO Update or Purchase order received*/
		public function GetPOReceived($userId=NULL, $userIntegrationId=NULL, $webhookEvent=NULL, $is_initial_syn)
		{
			$return_response = false;
			try
			{
				if($is_initial_syn)
				{
					return $this->CreateWebhook($userId, $userIntegrationId, $webhookEvent);
				}
				$return_response = true;
			}
			catch(\Exception $e)
			{
				\Log::error($userIntegrationId.' - ShipHeroApiController - GetPOReceived - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		/* receive po_received webhook */
		public function ReceivePOWebhook(Request $request, $userIntegrationId)
		{
			$return_response = false;
			try
			{
				if($request->isMethod('post'))
				{
					$EventID = "GET_PURCHASEORDERRECIEPT";
					$user_work_flow = [];
					
					$integration = $this->FieldMappingHelper->getUserIntegrationDetailsById($userIntegrationId, self::$myPlatform);
					if($integration){
						$userId = $integration->user_id;
						$selectFields = ['e.event_id','ur.user_id','ur.status'];
						$user_work_flow = $this->FieldMappingHelper->getUserIntegWorkFlow($userIntegrationId, $EventID, $selectFields, self::$myPlatform);

						if(isset($user_work_flow[$EventID]))
						{
							$userId = $user_work_flow[$EventID]['user_id'];
							/* Check whether shipment is ON or OFF */
							if($user_work_flow[$EventID]['status'] == 1)
							{
								$body = $request->getContent();

								$result_data = json_decode($body, 1);

								\Storage::disk('local')->append('po_received_test.txt', 'webhook data received from shiphero '.' time:' . date('Y-m-d H:i:s') .PHP_EOL .json_encode($result_data,true));

								if(isset($result_data['purchase_order']['id']))
								{
									$poResp = $result_data['purchase_order'];

									if($poResp['status']!='canceled'){

										$platform_order = $this->MainModel->getFirstResultByConditions('platform_order', ['platform_id'=>$this->platformId, 'user_integration_id'=>$userIntegrationId, 'order_number'=>$poResp['po_number'], 'order_type'=>'PO'], ['id']);
										if($platform_order)
										{
											/* saved order_number in order_id & order row id as shipment id*/
											$shipmentData = ['user_id'=>$userId, 'platform_id'=>$this->platformId, 'user_integration_id'=>$userIntegrationId, 'shipment_id'=>$poResp['id'], 'platform_order_id'=>$platform_order->id, 'order_id'=>$poResp['po_number'], 'tracking_info'=>$poResp['po_number'], 'type'=>'POShipment'];

											//shipment_id & platform_order_id as act as same here to store data in order , shipment & shipment line
											$platform_order_shipment = $this->MainModel->getFirstResultByConditions('platform_order_shipments', ['platform_id'=>$this->platformId, 'user_integration_id'=>$userIntegrationId,'type'=>'POShipment', 'shipment_id'=>$poResp['id']], ['id','sync_status']);
											$sync_status = "Ready";
											if($platform_order_shipment)
											{
												$platform_order_shipment_id = $platform_order_shipment->id;
												$sync_status = $platform_order_shipment->sync_status;
												$this->MainModel->makeUpdate('platform_order_shipments', $shipmentData, ['id'=>$platform_order_shipment->id]);
											}
											else
											{
												//$shipmentData['sync_status'] = 'Ready';
												$platform_order_shipment_id = $this->MainModel->makeInsertGetId('platform_order_shipments', $shipmentData);
											}

											$is_found_all_received_zero = 1;
											if(isset($poResp['line_items'][0]['id']))
											{
												foreach($poResp['line_items'] as $line_item)
												{
													if($line_item['quantity_received'] != 0)
													{
														$is_found_all_received_zero = 0;

														$shipmentLineData = ['platform_order_shipment_id'=>$platform_order_shipment_id, 'sku'=>$line_item['sku'], 'quantity'=>$line_item['quantity_received']];

														$platform_order_shipment_line = $this->MainModel->getFirstResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id'=>$platform_order_shipment_id, 'sku'=>$line_item['sku']], ['id']);
														if($platform_order_shipment_line)
														{
															$this->MainModel->makeUpdate('platform_order_shipment_lines', $shipmentLineData, ['id'=>$platform_order_shipment_line->id]);
														}
														else
														{
															$this->MainModel->makeInsert('platform_order_shipment_lines', $shipmentLineData);
														}
													}
												}
											}

											$order_data = ['shipment_status'=>'Pending'];
											if($is_found_all_received_zero==1)
											{
												$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Pending'], ['id'=>$platform_order_shipment_id]);

											}else
											//elseif($sync_status!='Synced')
											{
												$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Ready'], ['id'=>$platform_order_shipment_id]);
												$order_data = ['shipment_status'=>'Ready'];
											}

											//purchase order get with closed status then set is_fully_synced = 1
											if($poResp['status']=='closed')
											{
												$order_data['is_fully_synced'] = 1;
												$this->MainModel->makeUpdate('platform_order', $order_data, ['id'=>$platform_order->id]);
											}
											else
											{
												$this->MainModel->makeUpdate('platform_order', $order_data, ['id'=>$platform_order->id]);
											}
										}


									}
								}
							}
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($userIntegrationId.' - ShipHeroApiController - ReceivePOWebhook - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		public function test()
		{
			//$this->GetVendors(Auth::user()->id, 474, 1);
			//$response = $this->CreatePurchaseOrders(Auth::user()->id, 474, 145, 889, 'bluecherry', 525213);
			//dd($response);
		}

		/* Execute ShipHero Event Methods */
		public function ExecuteShipHeroEvents($method='', $event='', $destination_platform_id='', $user_id='', $user_integration_id='', $is_initial_sync=0, $user_workflow_rule_id='', $source_platform_id='', $platform_workflow_rule_id='', $record_id='')
		{
			$response = true;
			if($method == 'GET' && $event == 'WAREHOUSE')
			{
				$this->GetWarehouses($user_id, $user_integration_id);
			}
			elseif($method == 'GET' && $event == 'CUSTOMERACCOUNT')
			{
				$this->GetCustomerAccounts($user_id, $user_integration_id);
			}
			elseif($method == 'GET' && $event == 'PRODUCT')
			{
				$this->GetProducts($user_id, $user_integration_id, $is_initial_sync);
			}
			elseif($method == 'GET' && $event == 'VENDOR')
			{
				$this->GetVendors($user_id, $user_integration_id, $is_initial_sync);
			}
			elseif($method == 'GET' && $event == 'SHIPMENT')
			{
				//To get created 'Shipment Update' webhook
				$response = $this->GetShipment($user_id, $user_integration_id, 'Shipment Update', $is_initial_sync);
			}
			elseif($method == 'GET' && $event == 'INVENTORY')
			{
				//To get created 'Inventory Update' webhook
				$response = $this->GetInventory($user_id, $user_integration_id, 'Inventory Update', $is_initial_sync);
			}
			elseif($method == 'MUTATE' && $event == 'SALESORDER')
			{
				$response = $this->CreateSalesOrders($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id);
			}
			elseif($method == 'MUTATE' && $event == 'PURCHASEORDER')
			{
				$response = $this->CreatePurchaseOrders($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id);
			}
			elseif($method == 'MUTATE' && $event == 'PRODUCT')
			{
				$response = $this->CreateProducts($user_id, $user_integration_id, $source_platform_id, $user_workflow_rule_id, $record_id);
			}
			elseif($method == 'GET' && $event == 'PURCHASEORDERRECIEPT')
			{
				//To get created 'Shipment Update' webhook
				$response = $this->GetPOReceived($user_id, $user_integration_id, 'PO Update', $is_initial_sync);
			}

			return $response;
		}
	}

