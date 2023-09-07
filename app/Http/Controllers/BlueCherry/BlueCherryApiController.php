<?php
	namespace App\Http\Controllers\BlueCherry;

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
	use App\Models\PlatformProduct;
	use App\Models\PlatformProductInventory;
    use App\Http\Controllers\BlueCherry\Api\BlueCherryApi;
	use Lang;

	class BlueCherryApiController extends Controller
	{
		public static $myPlatform='bluecherry';

		/**
			* Create a new controller instance.
			*
			* @return void
		*/
		public function __construct()
		{
			$this->MainModel = new MainModel();
			$this->BlueCherryApi = new BlueCherryApi();
			$this->ConnectionHelper = new ConnectionHelper();
			$this->FieldMappingHelper = new FieldMappingHelper();
			$this->Logger = new Logger();
			$this->WorkflowSnippet = new WorkflowSnippet();
			$this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
		}

		public function InitiateBlueCherryAuth(Request $request)
		{
			$platform='bluecherry';
			return view("pages.apiauth.auth_bluecherry", compact('platform'));
		}

		public function ConnectBlueCherryAuth(Request $request)
		{
			$request->validate(['bluecherry_api_url'=>'required', 'bluecherry_subscription_key'=>'required']);

			$bluecherry_api_url = trim($request->bluecherry_api_url);
			$bluecherry_subscription_key = trim($request->bluecherry_subscription_key);

			$data = [];

			if($this->MainModel->checkHtmlTags( $request->all() ) ){
				$data['status_code'] = 0;
				$data['status_text'] = Lang::get('tags.validate');
				return json_encode($data);
			}
			try{
				$flag = true;
				// to check whether given account is already in use or not.
				$checkExistingAc = PlatformAccount::select('id')->where('platform_id', $this->platformId)->where('api_domain', $this->MainModel->encryptString($bluecherry_api_url))->where('access_token', $this->MainModel->encryptString($bluecherry_subscription_key))->first();
				if ($checkExistingAc)
				{
					$flag = false;
					$data['status_code'] = 0;
					$data['status_text'] = 'This account detail already exist, Try with another account.';
				}
				else
				{
					$response = $this->BlueCherryApi->Authentication($bluecherry_api_url, $bluecherry_subscription_key);
					$result = json_decode($response, true);
					if(isset($result['version']))
					{
						PlatformAccount::insert(['user_id'=>Auth::user()->id, 'platform_id'=>$this->platformId, 'account_name'=>$bluecherry_api_url, 'api_domain'=>$this->MainModel->encryptString($bluecherry_api_url), 'access_token'=>$this->MainModel->encryptString($bluecherry_subscription_key), 'allow_refresh'=>0]);
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

		public function GetShippingMethods($user_id=0, $user_integration_id=0)
		{
			$return_data = true;
			try
			{
				$shipping_method_object = $this->MainModel->getFirstResultByConditions('platform_objects', ['name'=>"shipping_method"], ['id']);
				if($shipping_method_object)
				{
					$this->MainModel->makeUpdate('platform_object_data', ['status'=>0], ['user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'platform_object_id'=>$shipping_method_object->id, 'status'=>1]);

					$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['api_domain', 'access_token']);
					if($platform_account)
					{
						$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/Shipper?_pgno=1&_pgsize=500';

						$response = $this->BlueCherryApi->CallAPI('GET', $this->MainModel->decryptString($platform_account->access_token), $service_url);
						$shipping_methods=json_decode($response, true);
						if(isset($shipping_methods['data'][0]['pkey']))
						{
							foreach($shipping_methods['data'] as $shipping_method)
							{
								$ShippingMethodData = ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$shipping_method_object->id, 'api_id'=>$shipping_method['pkey'], 'name'=>$shipping_method['ship_name'], 'api_code'=>$shipping_method['shipper'], 'description'=>$shipping_method['ship_name'], 'status'=>1];

								$platform_object_data=$this->MainModel->getFirstResultByConditions('platform_object_data', ['platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$shipping_method_object->id, 'api_id'=>$shipping_method['pkey']], ['id']);
								if($platform_object_data)
								{
									$this->MainModel->makeUpdate('platform_object_data', $ShippingMethodData, ['id'=>$platform_object_data->id]);
								}
								else
								{
									$this->MainModel->makeInsert('platform_object_data', $ShippingMethodData);
								}
							}
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - BlueCherryApiController - GetShippingMethods - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
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

					$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['api_domain', 'access_token']);
					if($platform_account)
					{
						$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/Location?$filter='.urlencode("loc_type eq 'W'").'&_pgno=1&_pgsize=500';

						$response = $this->BlueCherryApi->CallAPI('GET', $this->MainModel->decryptString($platform_account->access_token), $service_url);
						$warehouses=json_decode($response, true);
						if(isset($warehouses['data'][0]['pkey']))
						{
							foreach($warehouses['data'] as $warehouse)
							{
								$warehouseData = ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$warehouse_object->id, 'api_id'=>$warehouse['pkey'], 'name'=>$warehouse['loc_name'].' ('.$warehouse['location'].')', 'api_code'=>$warehouse['location'], 'description'=>$warehouse['resv_wh'], 'status'=>1];

								$platform_object_data=$this->MainModel->getFirstResultByConditions('platform_object_data', ['platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$warehouse_object->id, 'api_id'=>$warehouse['pkey']], ['id']);
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
				\Log::error($user_integration_id.' - BlueCherryApiController - GetWarehouses - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function GetSalesOrders($user_id=0, $user_integration_id=0, $user_workflow_rule_id=0)
		{
			$return_data = true;
			try
			{
				$EventID = "GET_SALESORDER";
				
				$selectFields = ['e.event_id','ur.status'];

				$user_work_flow = $this->FieldMappingHelper->getUserIntegWorkFlow($user_integration_id, $EventID, $selectFields, self::$myPlatform);

				if(isset($user_work_flow[$EventID])){
					/* First Check whether Order Sync is ON */
					if($user_work_flow[$EventID]['status'] == 1)
					{
						$platform_account=$this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['api_domain', 'access_token']);
						if($platform_account)
						{
							$user_workflow_rule=$this->MainModel->getFirstResultByConditions('user_workflow_rule', [ 'user_integration_id'=>$user_integration_id, 'id'=>$user_workflow_rule_id, 'status'=>1], ['platform_workflow_rule_id', 'sync_start_date']);
							if($user_workflow_rule)
							{
								$Limit = 25; //200 max
								$Page = 1;
								$last_mod = NULL;
								$pick_date = NULL;

								if($user_workflow_rule->sync_start_date)
								{
									$pick_date = date('Y-m-d H:i:s', strtotime($user_workflow_rule->sync_start_date));
									$last_mod = date('Y-m-d H:i:s', strtotime($user_workflow_rule->sync_start_date));
								}

								$pull_time = DB::table('platform_order')->select('api_updated_at')->where([ 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId])->where('order_type', 'SO')->whereNotNull('api_updated_at')->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H:%i:%s') DESC")->first();
								if($pull_time)
								{
									$last_mod = date('Y-m-d H:i:s', strtotime($pull_time->api_updated_at));
								}

								do
								{
									$allow_next_cal = false;

									$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/pick?_include=all&_pgno='.$Page.'&_pgsize='.$Limit;

									if($pick_date)
									{
										$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/pick?$filter='.urlencode("last_mod gt '".$last_mod."' and pick_date gt '".$pick_date."'").'&_include=all&_pgno='.$Page.'&_pgsize='.$Limit;
									}
									elseif($last_mod)
									{
										$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/pick?$filter='.urlencode("last_mod gt '".$last_mod."'").'&_include=all&_pgno='.$Page.'&_pgsize='.$Limit;
									}

									$response = $this->BlueCherryApi->CallAPI('GET', $this->MainModel->decryptString($platform_account->access_token), $service_url);
									$Orders = json_decode($response, true);
									if(isset($Orders['data']['header'][0]['pkey']))
									{
										$allow_next_cal=true;
										foreach($Orders['data']['header'] as $Order)
										{
											$platform_customer_id = NULL;
											foreach($Order['pickaddress']['rows'] as $address)
											{
												if($address['addr_type'] == 'BT')
												{
													$email = $address['email'];
													if($address['email'] == NULL || trim($address['email']) == '')
													{
														$email = 'order_'.$Order['ord_num'].'@bluecherry.com';
													}

													//order customer details
													$CustomerData = ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'customer_name'=>$address['name'], 'first_name'=>$address['name'], 'address1'=>$address['addr1'], 'address2'=>$address['city'], 'address3'=>$address['state'], 'country'=>$address['country'], 'postal_addresses'=>$address['zipcode'], 'email'=>$email, 'phone'=>$address['contact']];

													$platform_customer = $this->MainModel->getFirstResultByConditions('platform_customer', ['platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'email'=>$email], ['id']);
													if($platform_customer)
													{
														$platform_customer_id = $platform_customer->id;
														$this->MainModel->makeUpdate('platform_customer', $CustomerData, ['id'=>$platform_customer->id]);
													}
													else
													{
														$platform_customer_id = $this->MainModel->makeInsertGetId('platform_customer', $CustomerData);
													}
												}
											}

											$OrderData = ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_workflow_rule_id'=>$user_workflow_rule_id, 'user_integration_id'=>$user_integration_id, 'platform_customer_id'=>$platform_customer_id, 'order_type'=>'SO', 'api_order_id'=>$Order['pkey'], 'customer_email'=>$email, 'trading_partner_id'=>$Order['po_num'], 'api_order_reference'=>$Order['ord_num'], 'order_number'=>$Order['pick_num'], 'order_date'=>date('Y-m-d H:i:s', strtotime($Order['pick_date'])), 'order_status'=>$Order['ord_type'], 'total_discount'=>round($Order['disc_amt'], 2), 'total_tax'=>round($Order['tax_amt'], 2), 'shipping_total'=>round($Order['frgt_amt'], 2), 'currency'=>$Order['curr_code'], 'department'=>$Order['department'], 'delivery_date'=>date('Y-m-d H:i:s', strtotime($Order['end_date'])), 'shipping_method'=>$Order['shipper'], 'file_name'=>$Order['altpo'], 'api_updated_at'=>date('Y-m-d H:i:s', strtotime($Order['last_mod']))];

											$platform_order = $this->MainModel->getFirstResultByConditions('platform_order', [ 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'order_type'=>'SO', 'api_order_id'=>$Order['pkey']], ['id', 'order_status', 'api_updated_at', 'linked_id']);
											if($platform_order)
											{
												//if($platform_order->api_updated_at != date('Y-m-d H:i:s', strtotime($Order['last_mod'])) && $platform_order->order_status != $Order['ord_type'] && $platform_order->linked_id != 0)
												if($platform_order->api_updated_at != date('Y-m-d H:i:s', strtotime($Order['last_mod'])) && $platform_order->linked_id == 0)
												{
													$OrderData['sync_status'] = 'Ready';
												}

												if($platform_order->api_updated_at != date('Y-m-d H:i:s', strtotime($Order['last_mod'])))
												{
													$OrderData['order_updated_at'] = date("Y-m-d H:i:s");
												}

												$platform_order_id = $platform_order->id;
												$this->MainModel->makeUpdate('platform_order', $OrderData, ['id'=>$platform_order->id]);
											}
											else
											{
												$OrderData['sync_status'] = 'Ready';
												$OrderData['order_updated_at'] = date("Y-m-d H:i:s");
												$platform_order_id = $this->MainModel->makeInsertGetId('platform_order', $OrderData);
											}

											foreach($Order['pickaddress']['rows'] as $address)
											{
												if($address['addr_type'] == 'BT')
												{
													//order billing address
													$OrderBillingAddressData = ['platform_order_id'=>$platform_order_id, 'address_type'=>'billing', 'address_name'=>$address['name'], 'firstname'=>$address['name'], 'address1'=>$address['addr1'], 'address2'=>$address['addr2'], 'city'=>$address['city'], 'state'=>$address['state'], 'postal_code'=>$address['zipcode'], 'country'=>$address['country'], 'email'=>$address['email'], 'phone_number'=>$address['contact']];

													$platform_order_billing_address = $this->MainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id'=>$platform_order_id, 'address_type'=>'billing'], ['id']);
													if($platform_order_billing_address)
													{
														$this->MainModel->makeUpdate('platform_order_address', $OrderBillingAddressData, ['id'=>$platform_order_billing_address->id]);
													}
													else
													{
														$this->MainModel->makeInsert('platform_order_address', $OrderBillingAddressData);
													}
												}
												elseif($address['addr_type'] == 'ST')
												{
													//order shipping address
													$OrderShippingAddressData = ['platform_order_id'=>$platform_order_id, 'address_type'=>'shipping', 'address_name'=>$address['name'], 'firstname'=>$address['name'], 'address1'=>$address['addr1'], 'address2'=>$address['addr2'], 'city'=>$address['city'], 'state'=>$address['state'], 'postal_code'=>$address['zipcode'], 'country'=>$address['country'], 'email'=>$address['email'], 'phone_number'=>$address['contact']];

													$platform_order_shipping_address = $this->MainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id'=>$platform_order_id, 'address_type'=>'shipping'], ['id']);
													if($platform_order_shipping_address)
													{
														$this->MainModel->makeUpdate('platform_order_address', $OrderShippingAddressData, ['id'=>$platform_order_shipping_address->id]);
													}
													else
													{
														$this->MainModel->makeInsert('platform_order_address', $OrderShippingAddressData);
													}
												}
											}

											$net_amount = 0;
											foreach($Order['pickdetail']['rows'] as $line)
											{
												//order line item
												$OrderItemData = ['platform_order_id'=>$platform_order_id, 'api_order_line_id'=>$line['pkey'], 'item_row_sequence'=>$line['line_seq'], 'product_name'=>$line['style'], 'sku'=>$line['style'], 'upc'=>$line['upc'], 'price'=>round($line['price'], 2), 'unit_price'=>round($line['price'], 2), 'subtotal'=>(round($line['price'], 2) * $line['bk_qty']), 'total'=>(round($line['price'], 2) * $line['bk_qty']), 'qty'=>$line['bk_qty'], 'description'=>$line['color_code'], 'notes'=>$line['size_desc']];

												$platform_order_line = $this->MainModel->getFirstResultByConditions('platform_order_line', ['platform_order_id'=>$platform_order_id, 'upc'=>$line['upc']], ['id']);
												if($platform_order_line)
												{
													if($line['bk_qty'])
													{
														$this->MainModel->makeUpdate('platform_order_line', $OrderItemData, ['id'=>$platform_order_line->id]);
													}
													else
													{
														$this->MainModel->makeDelete('platform_order_line', ['id'=>$platform_order_line->id]);
													}
												}
												else
												{
													if($line['bk_qty'])
													{
														$this->MainModel->makeInsert('platform_order_line', $OrderItemData);
													}
												}

												$net_amount = $net_amount + (round($line['price'], 2) * $line['bk_qty']);
											}

											if($Order['frgt_amt'] != 0)
											{
												$OrderItemData=['platform_order_id'=>$platform_order_id, 'product_name'=>"SHIPPING", 'qty'=>1, 'price'=>round($Order['frgt_amt'], 2), 'unit_price'=>round($Order['frgt_amt'], 2), 'subtotal'=>round($Order['frgt_amt'], 2), 'total'=>round($Order['frgt_amt'], 2), 'row_type'=>"SHIPPING"];

												$platform_order_line=$this->MainModel->getFirstResultByConditions('platform_order_line', ['platform_order_id'=>$platform_order_id, 'row_type'=>"SHIPPING"], ['id']);
												if($platform_order_line)
												{
													$this->MainModel->makeUpdate('platform_order_line', $OrderItemData, ['id'=>$platform_order_line->id]);
												}
												else
												{
													$this->MainModel->makeInsert('platform_order_line', $OrderItemData);
												}
											}
											else
											{
												$this->MainModel->makeDelete('platform_order_line', ['platform_order_id'=>$platform_order_id, 'row_type'=>"SHIPPING"]);
											}

											if($Order['disc_amt'] != 0)
											{
												$OrderItemData=['platform_order_id'=>$platform_order_id, 'product_name'=>"DISCOUNT", 'qty'=>1, 'price'=>round($Order['disc_amt'], 2), 'unit_price'=>round($Order['disc_amt'], 2), 'subtotal'=>round($Order['disc_amt'], 2), 'total'=>round($Order['disc_amt'], 2), 'row_type'=>"DISCOUNT"];

												$platform_order_line=$this->MainModel->getFirstResultByConditions('platform_order_line', ['platform_order_id'=>$platform_order_id, 'row_type'=>"DISCOUNT"], ['id']);
												if($platform_order_line)
												{
													$this->MainModel->makeUpdate('platform_order_line', $OrderItemData, ['id'=>$platform_order_line->id]);
												}
												else
												{
													$this->MainModel->makeInsert('platform_order_line', $OrderItemData);
												}
											}
											else
											{
												$this->MainModel->makeDelete('platform_order_line', ['platform_order_id'=>$platform_order_id, 'row_type'=>"DISCOUNT"]);
											}

											if($Order['tax_amt'] != 0)
											{
												$OrderItemData = ['platform_order_id'=>$platform_order_id, 'product_name'=>"Sales Tax", 'qty'=>1, 'price'=>round($Order['tax_amt'], 2), 'unit_price'=>round($Order['tax_amt'], 2), 'subtotal'=>round($Order['tax_amt'], 2), 'total'=>round($Order['tax_amt'], 2), 'row_type'=>"TAX"];

												$platform_order_line=$this->MainModel->getFirstResultByConditions('platform_order_line', ['platform_order_id'=>$platform_order_id, 'row_type'=>"TAX"], ['id']);
												if($platform_order_line)
												{
													$this->MainModel->makeUpdate('platform_order_line', $OrderItemData, ['id'=>$platform_order_line->id]);
												}
												else
												{
													$this->MainModel->makeInsert('platform_order_line', $OrderItemData);
												}
											}
											else
											{
												$this->MainModel->makeDelete('platform_order_line', ['platform_order_id'=>$platform_order_id, 'row_type'=>"TAX"]);
											}

											$total_amount = ($net_amount + round($Order['tax_amt'], 2) + round($Order['frgt_amt'], 2)) - (round($Order['disc_amt'], 2));
											$this->MainModel->makeUpdate('platform_order', ['net_amount'=>round($net_amount, 2), 'total_amount'=>round($total_amount, 2)], ['id'=>$platform_order_id]);
										}

										$Page++;
										if(count($Orders['data']) != $Limit)
										{
											$allow_next_cal = false;
										}
									}
								}
								while($allow_next_cal);
							}
						}
					}
			    }
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - BlueCherryApiController - GetSalesOrders - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function GetPurchaseOrders($user_id=0, $user_integration_id=0, $user_workflow_rule_id=0)
		{
			$return_data = true;
			try
			{
				$EventID = "GET_PURCHASEORDER";

				$selectFields = ['e.event_id','ur.status'];

				$user_work_flow = $this->FieldMappingHelper->getUserIntegWorkFlow($user_integration_id, $EventID, $selectFields,  self::$myPlatform);

				if(isset($user_work_flow[$EventID])){
					/* First Check whether Order Sync is ON */
					if($user_work_flow[$EventID]['status'] == 1)
					{
						$platform_account=$this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['api_domain', 'access_token']);
						if($platform_account)
						{
							$user_workflow_rule=$this->MainModel->getFirstResultByConditions('user_workflow_rule', [ 'user_integration_id'=>$user_integration_id, 'id'=>$user_workflow_rule_id, 'status'=>1], ['platform_workflow_rule_id', 'sync_start_date']);
							if($user_workflow_rule)
							{
								$Limit = 25; //200 max
								$Page = 1;
								$last_mod = NULL;
								$orgentdate = NULL;

								if($user_workflow_rule->sync_start_date)
								{
									$orgentdate = date('Y-m-d H:i:s', strtotime($user_workflow_rule->sync_start_date));
									$last_mod = date('Y-m-d H:i:s', strtotime($user_workflow_rule->sync_start_date));
								}

								$pull_time = DB::table('platform_order')->select('api_updated_at')->where([ 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId])->where('order_type', 'PO')->whereNotNull('api_updated_at')->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H:%i:%s') DESC")->first();
								if($pull_time)
								{
									$last_mod = date('Y-m-d H:i:s', strtotime($pull_time->api_updated_at));
								}

								do
								{
									$allow_next_cal = false;

									$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/productionOrderByStage?$filter='.urlencode("stage_num eq '3'").'&_include=all&_pgno='.$Page.'&_pgsize='.$Limit;

									if($orgentdate)
									{
										$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/productionOrderByStage?$filter='.urlencode("last_mod gt '".$last_mod."' and orgentdate gt '".$orgentdate."' and stage_num eq '3'").'&_include=all&_pgno='.$Page.'&_pgsize='.$Limit;
									}
									elseif($last_mod)
									{
										$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/productionOrderByStage?$filter='.urlencode("last_mod gt '".$last_mod."' and stage_num eq '3'").'&_include=all&_pgno='.$Page.'&_pgsize='.$Limit;
									}

									$response = $this->BlueCherryApi->CallAPI('GET', $this->MainModel->decryptString($platform_account->access_token), $service_url);
									$Orders = json_decode($response, true);
									if(isset($Orders['data']['header'][0]['pkey']))
									{
										$allow_next_cal=true;
										foreach($Orders['data']['header'] as $Order)
										{
											$platform_customer_id = NULL;
											foreach($Order['productionorderaddressbystage']['rows'] as $address)
											{
												if($address['addr_type'] == 'VN')
												{
													$email = $address['email'];
													if($address['email'] == NULL || trim($address['email']) == '')
													{
														$email = 'order_'.$Order['prod_num'].'@bluecherry.com';
													}

													//order customer details
													$CustomerData = ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'customer_name'=>$address['name'], 'first_name'=>$address['name'], 'address1'=>$address['address1'], 'address2'=>$address['city'], 'address3'=>$address['state'], 'country'=>$address['country'], 'postal_addresses'=>$address['zipcode'], 'phone'=>$address['contact'], 'type'=>'Vendor'];

													if($address['email'] == NULL || trim($address['email']) == '')
													{
														$platform_customer = $this->MainModel->getFirstResultByConditions('platform_customer', ['platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'customer_name'=>$address['name'], 'type'=>'Vendor'], ['id']);
													}
													else
													{
														$platform_customer = $this->MainModel->getFirstResultByConditions('platform_customer', ['platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'email'=>$email, 'type'=>'Vendor'], ['id']);
													}

													if($platform_customer)
													{
														$platform_customer_id = $platform_customer->id;
														$this->MainModel->makeUpdate('platform_customer', $CustomerData, ['id'=>$platform_customer->id]);
													}
													else
													{
														$CustomerData['email'] = $email;
														$platform_customer_id = $this->MainModel->makeInsertGetId('platform_customer', $CustomerData);
													}
												}
											}

											if($platform_customer_id)
											{
												$OrderData = ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_workflow_rule_id'=>$user_workflow_rule_id, 'user_integration_id'=>$user_integration_id, 'platform_customer_id'=>$platform_customer_id, 'order_type'=>'PO', 'api_order_id'=>$Order['pkey'], 'customer_email'=>$email, 'api_order_reference'=>$Order['ref_num'], 'order_number'=>$Order['prod_num'], 'order_date'=>date('Y-m-d H:i:s', strtotime($Order['orgentdate'])), 'delivery_date'=>date('Y-m-d H:i:s', strtotime($Order['due_date'])), 'order_status'=>$Order['prod_type'], 'currency'=>$Order['co_curr'], 'department'=>$Order['department'], 'shipping_method'=>$Order['terms_ship'], 'api_updated_at'=>date('Y-m-d H:i:s', strtotime($Order['last_mod']))];

												$platform_order = $this->MainModel->getFirstResultByConditions('platform_order', ['platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'order_type'=>'PO', 'api_order_id'=>$Order['pkey']], ['id', 'order_status', 'api_updated_at', 'linked_id']);
												if($platform_order)
												{
													//if($platform_order->api_updated_at != date('Y-m-d H:i:s', strtotime($Order['last_mod'])) && $platform_order->order_status != $Order['ord_type'] && $platform_order->linked_id != 0)
													if($platform_order->api_updated_at != date('Y-m-d H:i:s', strtotime($Order['last_mod'])) && $platform_order->linked_id == 0)
													{
														$OrderData['sync_status'] = 'Ready';
													}

													if($platform_order->api_updated_at != date('Y-m-d H:i:s', strtotime($Order['last_mod'])))
													{
														$OrderData['order_updated_at'] = date("Y-m-d H:i:s");
													}

													$platform_order_id = $platform_order->id;
													$this->MainModel->makeUpdate('platform_order', $OrderData, ['id'=>$platform_order->id]);
												}
												else
												{
													$OrderData['sync_status'] = 'Ready';
													$OrderData['order_updated_at'] = date("Y-m-d H:i:s");
													$platform_order_id = $this->MainModel->makeInsertGetId('platform_order', $OrderData);
												}

												foreach($Order['productionorderaddressbystage']['rows'] as $address)
												{
													if($address['addr_type'] == 'VN')
													{
														//order billing address
														$OrderBillingAddressData = ['platform_order_id'=>$platform_order_id, 'address_type'=>'billing', 'address_name'=>$address['name'], 'firstname'=>$address['name'], 'address1'=>$address['address1'], 'address2'=>$address['address2'], 'city'=>$address['city'], 'state'=>$address['state'], 'postal_code'=>$address['zipcode'], 'country'=>$address['country'], 'email'=>$address['email'], 'phone_number'=>$address['contact']];

														$platform_order_billing_address = $this->MainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id'=>$platform_order_id, 'address_type'=>'billing'], ['id']);
														if($platform_order_billing_address)
														{
															$this->MainModel->makeUpdate('platform_order_address', $OrderBillingAddressData, ['id'=>$platform_order_billing_address->id]);
														}
														else
														{
															$this->MainModel->makeInsert('platform_order_address', $OrderBillingAddressData);
														}
													}
													elseif($address['addr_type'] == 'ST')
													{
														//order shipping address
														$OrderShippingAddressData = ['platform_order_id'=>$platform_order_id, 'address_type'=>'shipping', 'address_name'=>$address['name'], 'firstname'=>$address['name'], 'address1'=>$address['address1'], 'address2'=>$address['address2'], 'city'=>$address['city'], 'state'=>$address['state'], 'postal_code'=>$address['zipcode'], 'country'=>$address['country'], 'email'=>$address['email'], 'phone_number'=>$address['contact']];

														$platform_order_shipping_address = $this->MainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id'=>$platform_order_id, 'address_type'=>'shipping'], ['id']);
														if($platform_order_shipping_address)
														{
															$this->MainModel->makeUpdate('platform_order_address', $OrderShippingAddressData, ['id'=>$platform_order_shipping_address->id]);
														}
														else
														{
															$this->MainModel->makeInsert('platform_order_address', $OrderShippingAddressData);
														}
													}
												}

												$net_amount = 0;
												foreach($Order['productionorderdetailbystage']['rows'] as $line)
												{
													//order line item
													$OrderItemData = ['platform_order_id'=>$platform_order_id, 'api_order_line_id'=>$line['pkey'], 'product_name'=>$line['style'], 'sku'=>$line['style'], 'upc'=>$line['upc'], 'price'=>round($line['total_cost'], 2), 'unit_price'=>round($line['cost'], 2), 'subtotal'=>($line['prod_qty'] * round($line['total_cost'], 2)), 'total'=>($line['prod_qty'] * round($line['total_cost'], 2)), 'qty'=>$line['prod_qty'], 'description'=>$line['color_code'], 'notes'=>$line['size_desc']];

													$platform_order_line = $this->MainModel->getFirstResultByConditions('platform_order_line', ['platform_order_id'=>$platform_order_id, 'upc'=>$line['upc']], ['id']);
													if($platform_order_line)
													{
														if($line['prod_qty'])
														{
															$this->MainModel->makeUpdate('platform_order_line', $OrderItemData, ['id'=>$platform_order_line->id]);
														}
														else
														{
															$this->MainModel->makeDelete('platform_order_line', ['id'=>$platform_order_line->id]);
														}
													}
													else
													{
														if($line['prod_qty'])
														{
															$this->MainModel->makeInsert('platform_order_line', $OrderItemData);
														}
													}

													$net_amount = $net_amount + ($line['prod_qty'] * round($line['total_cost'], 2));
												}

												$this->MainModel->makeUpdate('platform_order', ['net_amount'=>round($net_amount, 2), 'total_amount'=>round($net_amount, 2)], ['id'=>$platform_order_id]);
											}
										}

										$Page++;
										if(count($Orders['data']) != $Limit)
										{
											$allow_next_cal = false;
										}
									}
								}
								while($allow_next_cal);
							}
						}
					}
			   }
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - BlueCherryApiController - GetPurchaseOrders - '.$e->getLine().' - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function GetProducts($user_id=0, $user_integration_id=0, $is_initial_sync=0)
		{
			$return_data = true;
			try
			{
				$platform_account=$this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['api_domain', 'access_token']);
				if($platform_account)
				{
					$Page = 1;
					$Limit = 25; //200 max

					do
					{
						$allow_next_cal = false;
						if($is_initial_sync)
						{
							$platform_url = $this->MainModel->getFirstResultByConditions('platform_urls', [ 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'product_limit', 'status'=>1], ['id', 'url']);
							if($platform_url)
							{
								$platform_url_id = $platform_url->id;
								$Page = $platform_url->url;
							}
							else
							{
								$url_data = ['user_id'=>$user_id, 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'product_limit', 'url'=>1, 'status'=>1, 'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s')];
								$platform_url_id = $this->MainModel->makeInsertGetId('platform_urls', $url_data);
							}

							$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/style?_pgno='.$Page.'&_pgsize='.$Limit.'&_include=all';
						}
						else
						{
							$last_product = PlatformProduct::select('api_updated_at')->where('user_integration_id', $user_integration_id)->where('platform_id', $this->platformId)->orderBy('api_updated_at', 'desc')->first();
							if($last_product)
							{
								$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/style?$filter='.urlencode("last_mod gt '".$last_product->api_updated_at."'").'&_pgno='.$Page.'&_pgsize='.$Limit.'&_include=all';
							}
							else
							{
								$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/style?_pgno='.$Page.'&_pgsize='.$Limit.'&_include=all';
							}
						}

						$response = $this->BlueCherryApi->CallAPI('GET', $this->MainModel->decryptString($platform_account->access_token), $service_url);
						$products = json_decode($response, true);
						if(isset($products['data']['header'][0]['style']))
						{
							$allow_next_cal=true;
							foreach($products['data']['header'] as $product)
							{
								if(isset($product['styledetail']['rows'][0]['pkey']))
								{
									foreach($product['styledetail']['rows'] as $row)
									{
										if(isset($row['stylecolorsizes']['rows-i'][0]['fkey']))
										{
											foreach($row['stylecolorsizes']['rows-i'] as $styleColorSize)
											{
												//'weight_unit'=>'lbs' default setup for current client
												//product details
												$productData = ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$product['pkey'], 'product_name'=>$product['style_name'], 'api_product_code'=>$product['style'], 'description'=>$product['style_desc'], 'api_variant_id'=>$styleColorSize['fkey'], 'ean'=>$styleColorSize['ean'], 'upc'=>$styleColorSize['upc'], 'manufacturer_sku'=>$row['third_party_item'], 'price'=>round($row['std_cost'], 2), 'brand_id'=>$product['group_code3'], 'category_id'=>$product['group_code2'], 'weight'=>$row['gar_wgt'], 'weight_unit'=>'lbs', 'updated_at'=>date('Y-m-d H:i:s'), 'api_updated_at'=>date('Y-m-d H:i:s', strtotime($product['last_mod']))];

												$platform_product = $this->MainModel->getFirstResultByConditions('platform_product', ['platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'upc'=>$styleColorSize['upc']], ['id', 'api_updated_at', 'linked_id']);
												if($platform_product)
												{
													if($platform_product->linked_id == 0 && strtotime($platform_product->api_updated_at) != strtotime($product['last_mod']) && ($row['catg_dest1'] == 'SHP' || $row['catg_dest2'] == 'SHP'))
													{
														$productData['product_sync_status'] = 'Ready';
													}

													$this->MainModel->makeUpdate('platform_product', $productData, ['id'=>$platform_product->id]);
													$platform_product_id = $platform_product->id;
												}
												else
												{
													if($row['catg_dest1'] == 'SHP' || $row['catg_dest2'] == 'SHP')
													{
														$productData['product_sync_status'] = 'Ready';
														$productData['inventory_sync_status'] = 'Ready';
													}

													$productData['created_at'] = date('Y-m-d H:i:s');
													$platform_product_id =$this->MainModel->makeInsertGetId('platform_product', $productData);
												}

												//product attribute details
												$productAttributeData = ['platform_product_id'=>$platform_product_id, 'merch'=>$product['group_code1'], 'material'=>$product['group_code4'], 'product_type_ids'=>$product['group_code5'], 'product_type_desc'=>$product['group_code6'], 'gender'=>$product['group_code7'], 'style_type'=>$product['group_code8'], 'color_code'=>$styleColorSize['color_code'], 'size_desc'=>$styleColorSize['size_desc'], 'dimension'=>$styleColorSize['dimension'], 'division'=>$row['division'], 'lbl_code'=>$styleColorSize['lbl_code'], 'season'=>$row['season'], 'updated_at'=>date('Y-m-d H:i:s')];

												$platform_product_detail_attribute = $this->MainModel->getFirstResultByConditions('platform_product_detail_attributes', ['platform_product_id'=>$platform_product_id], ['id']);
												if($platform_product_detail_attribute)
												{
													$this->MainModel->makeUpdate('platform_product_detail_attributes', $productAttributeData, ['id'=>$platform_product_detail_attribute->id]);
												}
												else
												{
													$productData['created_at'] = date('Y-m-d H:i:s');
													$this->MainModel->makeInsert('platform_product_detail_attributes', $productAttributeData);
												}
											}
										}
									}
								}
							}

							if($is_initial_sync)
							{
								//max 4 time run this script in single call
								if(($Page % 4) == 0)
								{
									$allow_next_cal = false;
								}

								$Page++;
								if(count($products['data']['header']) == $Limit)
								{
									$this->MainModel->makeUpdate('platform_urls', ['url'=>$Page, 'updated_at'=>date('Y-m-d H:i:s')], ['id'=>$platform_url_id]);
									$return_data = "Next get page ".$Page." data";
								}
								else
								{
									$allow_next_cal = false;
									$this->MainModel->makeUpdate('platform_urls', ['url'=>0, 'updated_at'=>date('Y-m-d H:i:s')], ['id'=>$platform_url_id]);

									$return_data = true;
								}
							}
							else
							{
								$Page++;
								if(count($products['data']['header']) != $Limit)
								{
									$allow_next_cal = false;
								}
							}
						}
					}
					while($allow_next_cal);
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - BlueCherryApiController - GetProducts - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function getCarrierSCAC($Carrier)
		{
			$SCAC = $Carrier;
			$CarrierNames = ['Atlantic Container Line'=>'ACLU', 'Alianca'=>'ANRM', 'Australia National Line'=>'ANNU', 'American President Lines'=>'APLU', 'Compagnie Maritime d Affretement Compagnie Generale Maritime'=>'CMDU', 'COSCO Container Lines'=>'COSU', 'Crowley'=>'CMCU', 'Compania Sud Americana de Vapores'=>'CHIW', 'CSAV Norasia'=>'NSLU', 'China Shipping Container Lines Co'=>'CHNJ', 'Eimskip'=>'EIMU', 'Emirates Shipping Line'=>'ESPU', 'Evergreen Line'=>'EGLV', 'Grimaldi'=>'GRIU', 'Hamburg Sud'=>'SUDU', 'Hapag Lloyd Container Line'=>'HLCU', 'Hyundai Merchant Marine Co., Ltd.'=>'HDMU', 'Horizon Lines'=>'HRZU', 'Independent Container Line'=>'IILU', 'Kawasaki Kisen Kaisha, Ltd.'=>'KKLU', 'Maersk Line'=>'MAEU', 'Matson'=>'MATS', 'Ocean Network Express (ONE Line)'=>'ONEY', 'Orient Overseas Container Line Ltd.'=>'OOLU', 'PM&O Lines'=>'PMOL', 'Safmarine'=>'SAFM', 'Seaboard Marine'=>'SMLU', 'Sealand'=>'SEAU', 'TOTE Maritime'=>'TOTE', 'Turkon Line Inc'=>'TRKU', 'US Lines'=>'USLU', 'Wan Hai Lines'=>'22AA', 'Yang Ming Line'=>'YMLU', 'ZIM Integrated Shipping Services Ltd'=>'ZIMU'];
			if(array_key_exists($Carrier, $CarrierNames))
			{
				$SCAC = $CarrierNames[$Carrier];
			}

			$AbbreviatedCarrierNames = ['ACL'=>'ACLU', 'Alianca'=>'ANRM', 'ANL'=>'ANNU', 'APL'=>'APLU', 'CMA CGM'=>'CMDU', 'COSCO'=>'COSU', 'Crowley'=>'CMCU', 'CSAV'=>'CHIW', 'CSAV Norasia'=>'NSLU', 'CSCL'=>'CHNJ', 'Eimskip'=>'EIMU', 'Emirates'=>'ESPU', 'Evergreen'=>'EGLV', 'Grimaldi'=>'GRIU', 'HAMBURG SUD'=>'SUDU', 'Hapag Lloyd'=>'HLCU', 'HMM'=>'HDMU', 'Horizon'=>'HRZU', 'ICL'=>'IILU', 'K Line'=>'KKLU', 'Maersk'=>'MAEU', 'Matson'=>'MATS', 'Ocean Network Express'=>'ONEY', 'OOCL'=>'OOLU', 'PMO'=>'PMOL', 'Safmarine'=>'SAFM', 'Seaboard Marine'=>'SMLU', 'Sealand'=>'SEAU', 'TOT'=>'TOTE', 'Turkon'=>'TRKU', 'USL'=>'USLU', 'WHL'=>'22AA', 'YML'=>'YMLU', 'ZIM'=>'ZIMU'];
			if(array_key_exists($Carrier, $AbbreviatedCarrierNames))
			{
				$SCAC = $AbbreviatedCarrierNames[$Carrier];
			}

			return $SCAC;
		}

		public function GetDigitCalculatorSSCC($inputValue)
		{
			$currentCharacter = (string)$inputValue;

			$remaining17Character = 17 - strlen($currentCharacter);

			$stepOne = str_pad('', $remaining17Character, '0').$currentCharacter;

			$stepTwo = 0;
			$stepFour = 0;
			$stepOneString = $stepOne;
			
			for($j=1; $j <= 17; $j++)
			{
				if($j%2 == 1)
				{
					$stepTwo = $stepTwo + (int) substr($stepOneString, 0, 1);
				}
				else
				{
					$stepFour = $stepFour + (int) substr($stepOneString, 0, 1);
				}

				$stepOneString = substr($stepOneString, 1);
			}

			$stepThree = $stepTwo * 3;

			$stepFive = $stepThree + $stepFour;

			$stepSix = ceil($stepFive/10) * 10;

			$find18Character = $stepSix - $stepFive;

			$final20Character = '00'.$stepOne.$find18Character;

			return $final20Character;
		}

		public function CreateOrderShipment($user_id=0, $user_integration_id=0, $source_platform_name='', $platform_workflow_rule_id=0, $user_workflow_rule_id=0, $record_id=0)
		{
			$return_data = true;
			$process_limit = 25;
			try
			{
				$source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
				$object_id = $this->ConnectionHelper->getObjectId('sales_order_shipment');

				$platform_account=$this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['api_domain', 'access_token']);
				if($platform_account)
				{
					$source_row_data = $destination_row_data = 'sku';
					$product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');
					$mapping_data = $this->FieldMappingHelper->getMappedField($user_integration_id, NULL, $product_identity_obj_id);
					if($mapping_data)
					{
						$source_row_data = $destination_row_data = 'sku';
						if($mapping_data['destination_platform_id'] == 'bluecherry')
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

					$platform_order_shipments = DB::table('platform_order_shipments')
					->select('id', 'shipment_id', 'platform_order_id', 'order_id', 'tracking_info', 'shipping_method', 'carrier_code', 'tracking_url', 'boxes', 'weight', 'created_at')
					->where(['user_integration_id'=>$user_integration_id, 'platform_id'=>$source_platform_id, 'type'=>'Shipment'])
					->where(function($query) use($record_id){
						if($record_id > 0)
						{
							$query->where(['platform_order_id'=>$record_id, 'sync_status'=>'Failed']);
						}
						else
						{
							$query->where(['sync_status'=>'Ready']);
						}
					})
					->where(function ($query){
						$query->whereNull('linked_id')->orWhere('linked_id', 0);
					})
					->limit($process_limit)
					->orderBy('id', 'asc')
					->get();

					foreach($platform_order_shipments as $platform_order_shipment)
					{
						$destination_platform_order = $this->MainModel->getFirstResultByConditions('platform_order', [ 'user_integration_id'=>$user_integration_id, 'linked_id'=>$platform_order_shipment->platform_order_id], ['id', 'api_order_id', 'shipment_status']);
						$source_platform_order = $this->MainModel->getFirstResultByConditions('platform_order', [ 'user_integration_id'=>$user_integration_id, 'id'=>$platform_order_shipment->platform_order_id], ['id', 'shipment_status']);
						if($destination_platform_order && $source_platform_order)
						{
							$line_items_qty = [];
							$line_items_carton = [];
							$cartons = [];
                            $platform_order_shipment_lines = $this->MainModel->getResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id'=>$platform_order_shipment->id]);
							foreach($platform_order_shipment_lines as $platform_order_shipment_line)
							{
                                $line_items_qty[$platform_order_shipment_line->sku] = $platform_order_shipment_line->quantity;
								$line_items_carton[$platform_order_shipment_line->sku] = $platform_order_shipment_line->user_batch_reference;
								$cartons[] = $platform_order_shipment_line->user_batch_reference;
                            }

							$cartons = array_unique($cartons);

							$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/pick?_include=all&pkey='.$destination_platform_order->api_order_id;

							$response = $this->BlueCherryApi->CallAPI('GET', $this->MainModel->decryptString($platform_account->access_token), $service_url);
							$result = json_decode($response, true);
							if(isset($result['data']['header'][0]['pkey']))
							{
								$order = $result['data']['header'][0];

								$shipper = 'GGL';
								if($platform_order_shipment->carrier_code && strlen($platform_order_shipment->carrier_code) <= 3)
								{
									$shipper = $platform_order_shipment->carrier_code;
								}

								$scac_code = 'GGL';
								if($platform_order_shipment->shipping_method && strlen($platform_order_shipment->shipping_method) <= 4)
								{
									$scac_code = $platform_order_shipment->shipping_method;
								}

								$postOrderShipment = array(
									array(
										"altpo"=>$order['altpo'], //pick
										//"appoint_num"=>"C20", //Required if Present
										"bill_num"=>$order['bill_num'], //pick //required if present
										"bill_optn"=>$order['bill_optn'], //pick
										"carton"=>1, //pick //required
										"center_code"=>$order['center_code'], //pick
										"cmpl_code"=>"N", //required //Hardcode "N"
										"consol_num"=>$order['consol_num'], //pick
										"customer"=>$order['customer'], //pick
										//"division"=>$order['division'], //pick
										"doc_num"=>$order['edi_doc_num'], //pick
										"eod_actual_frgt"=>$order['eod_actual_frgt'], //pick
										"frgt_amt"=>$order['frgt_amt'], //pick
										"insu_amt"=>$order['insu_amt'], //pick
										"inv_num"=>$order['inv_num'], //pick
										"isa_num"=>date('yHs').rand(100,999), //ISA Control Number //Sequential 9 digit counter unique to each transaction
										"load_id"=>$order['load_id'], //pick //required
										"location"=>$order['location'], //pick //required
										"mani_num"=>$order['mani_num'], //pick
										"misc_amt"=>$order['misc_amt'], //pick
										"notes"=>$order['notes'], //pick
										"ord_num"=>$order['ord_num'], //pick //required
										"ord_status"=>$order['ord_status'], //pick
										"ord_volume"=>$order['ord_volume'], //pick
										"our_id"=>"AVID", //required
										"our_qual"=>"ZZ", //required
										"pick_num"=>$order['pick_num'], //pick //required
										"po_num"=>$order['po_num'], //pick //required
										"pre_inv"=>$order['pre_inv'], //pick
										"pro_num"=>$order['pro_num'], //pick //Required if Present
										"rrc"=>$order['rrc'], //pick
										//"scac_code"=>$this->getCarrierSCAC($platform_order_shipment->shipping_method), //required //dynamic value
										"scac_code"=>$scac_code, //required //dynamic value
										"ship_date"=>date('m/d/Y', strtotime($platform_order_shipment->created_at)), //pick //required //actual ship date //dynamic pass
										//"ship_terms"=>$platform_order_shipment->carrier_code, //pick //dynamic pass
										//"ship_time"=>date('H:i:s', strtotime($platform_order_shipment->created_at)), //dynamic pass //Removed
										"shipfrom"=>$order['location'], //required
										"shipper"=>$shipper, //pick //required //dynamic value
										"trans_type"=>"A", //required //A = Air|M= Motor|R=Rail (please request a list if needed)
										//"udford1c"=>$order['udford1c'], //pick //Removed
										//"udford2c"=>$order['udford2c'], //pick //Removed
										//"udford3d"=>$order['udford3d'], //pick //Removed
										//"udford4i"=>$order['udford4i'], //pick //Removed
										"vicsmbol"=>$order['vicsmbol'], //pick
										"vnd_id"=>strtoupper($source_platform_name), //Required //dynamic pass
										"vnd_qual"=>"ZZ", //Required
										"volume_uom"=>"EA", //Required //EA is not a valid Volume Unit of Measurement
										"weight"=>$platform_order_shipment->weight, //pick
										"weight_uom"=>"U", //Required //EA is not a valid Weight Unit of Measurement
									)
								);

								$pickDetail = [];
								$cartonHeader = [];
								$cartonDetail = [];
								if(isset($order['pickdetail']['rows'][0]['pkey']))
								{
									foreach($order['pickdetail']['rows'] as $item)
									{
										if(isset($item[$destination_row_data]) && array_key_exists($item[$destination_row_data], $line_items_qty))
										{
											$pickDetail[] = array(
												"color_code"=>$item['color_code'], //pick //Required
												"dimension"=>$item['dimension'], //pick //Required if Present
												//"division"=>$item['division'], //pick
												"doc_num"=>$order['edi_doc_num'],
												//"ean"=>"C14", //Required if Present
												"lbl_code"=>$item['lbl_code'], //pick //Required if Present
												"line_seq"=>$item['line_seq'], //pick //Required
												"location"=>$item['location'], //pick //Required
												"notes"=>$item['notes'], //pick
												"org_color"=>$item['org_color'], //pick
												"pick_num"=>$item['pick_num'], //pick //Required
												"price"=>$item['price'], //pick
												"size_desc"=>$item['size_desc'], //pick //Required
												"size_qty"=>@$line_items_qty[$item[$destination_row_data]], //Required
												"style"=>$item['style'], //pick //Required
												"upc"=>$item['upc'] //pick //Required
											);

											$carton_num = $this->GetDigitCalculatorSSCC($platform_order_shipment->id);
											if(count($cartonHeader) == 0)
											{
												$cartonHeader[] = array(
													"carton_num"=>$carton_num, //Required
													"carton_wgt"=>$platform_order_shipment->weight, //Required //value not found
													"ctn_volume"=>1, //Required
													"customer"=>$order['customer'], //pick
													//"division"=>$item['division'], //pick
													"doc_num"=>$order['edi_doc_num'],
													"notes"=>$item['notes'], //pick
													"pick_num"=>$item['pick_num'], //pick //Required
													"track_no"=>$platform_order_shipment->tracking_info, //Required if Present //dynamic data //Not a valid tracking number. Either valid tracking number is required or do not send a value
													"trailer_num"=>$platform_order_shipment->carrier_code, //Required if Present //not a valid carrier trailer number. Either valid trailer number is required or do not send a value
													"volume_uom"=>"EA", //Required //EA is not a valid Volume Unit of Measurement
													"weight_uom"=>"U" //Required //EA is not a valid Weight Unit of Measurement
												);
											}

											$cartonDetail[] = array(
												"carton_num"=>$carton_num, //Required
												"color_code"=>$item['color_code'], //pick //Required
												"dimension"=>$item['dimension'], //pick //Required
												//"division"=>$item['division'], //pick
												"doc_num"=>$order['edi_doc_num'],
												//"ean"=>"C14", //Required if Present
												"lbl_code"=>$item['lbl_code'], //pick //Required if Present
												"line_seq"=>$item['line_seq'], //pick //Required
												//"lot"=>$item['lot'], //pick
												"notes"=>$item['notes'], //pick
												"org_color"=>$item['org_color'], //pick
												"pick_num"=>$item['pick_num'], //pick //Required
												"ship_qty"=>@$line_items_qty[$item[$destination_row_data]], //Required //dynamic data pass may be
												"shipqty_uom"=>"EA", //Required
												"size_desc"=>$item['size_desc'], //pick //Required
												"style"=>$item['style'], //pick //Required
												"total_qty"=>$item['total_qty'], //pick //Required
												"upc"=>$item['upc'], //pick //Required
												"vas_serial_no"=>$item['vas_serial_no'] //pick
											);
										}
									}
								}

								$postOrderShipment[0]['pickDetail'] = $pickDetail;
								$postOrderShipment[0]['cartonHeader'] = $cartonHeader;
								$postOrderShipment[0]['cartonHeader'][0]['cartonDetail'] = $cartonDetail;

								$service_url1 = $this->MainModel->decryptString($platform_account->api_domain).'/api/OrderShipment';

								$response1 = $this->BlueCherryApi->OrderShipment($this->MainModel->decryptString($platform_account->access_token), $service_url1, json_encode($postOrderShipment));
								$result1 = json_decode($response1, true);
								if(isset($result1['pkey']))
								{
									$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Synced'], ['id'=>$platform_order_shipment->id]);
									$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $source_platform_order->id, 'Shipment synced successfully!');
									$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Synced'], ['id'=>$source_platform_order->id]);
								}
								elseif(isset($result1['errors'][0]['errorMessage']))
								{
									$return_data = $result1['errors'][0]['errorMessage'];
									$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
									$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$source_platform_order->id]);
									$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_order->id, $result1['errors'][0]['errorMessage']);
								}
								elseif(isset($result1['message'][0]['message']))
								{
									$return_data = $result1['message'][0]['message'];
									$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
									$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$source_platform_order->id]);
									$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_order->id, $result1['message'][0]['message']);
								}
								elseif(isset($result1['message']))
								{
									$return_data = $result1['message'];
									$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
									$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$source_platform_order->id]);
									$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_order->id, $result1['message']);
								}
							}
							elseif(isset($result['errors'][0]['errorMessage']))
							{
								$return_data = $result['errors'][0]['errorMessage'];
								$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
								$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$source_platform_order->id]);
								$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_order->id, $result['errors'][0]['errorMessage']);
							}
							elseif(isset($result['errors'][0]['message']))
							{
								$return_data = $result['errors'][0]['message'];
								$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
								$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$source_platform_order->id]);
								$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_order->id, $result['errors'][0]['message']);
							}
							elseif(isset($result['message']))
							{
								$return_data = $result['message'];
								$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
								$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$source_platform_order->id]);
								$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_order->id, $result['message']);
							}
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - BlueCherryApiController - CreateOrderShipment - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function UpdateInventory($user_id=0, $user_integration_id=0, $source_platform_name='', $platform_workflow_rule_id=0, $user_workflow_rule_id=0, $record_id=0)
		{
			date_default_timezone_set("US/Eastern");

			$return_data = true;
			$process_limit = 500;
			try
			{
				$source_platform_id=$this->ConnectionHelper->getPlatformIdByName($source_platform_name);
				$allowHours = ['00', '01', '02', '03', '04', '05'];

				if(date('H') == 23 && $record_id < 1)
				{
					$notReadyProductCount = PlatformProduct::select('id')->where(['user_integration_id'=>$user_integration_id, 'platform_id'=>$source_platform_id, 'is_deleted'=>0])->whereNotIn('inventory_sync_status', ['Pending', 'Ready'])->count('id');
					if($notReadyProductCount)
					{
						PlatformProduct::where(['user_integration_id'=>$user_integration_id, 'platform_id'=>$source_platform_id, 'is_deleted'=>0])->whereNotIn('inventory_sync_status', ['Pending', 'Ready'])
						->update(['inventory_sync_status'=>'Ready']);
					}
				}
				elseif(in_array(date('H'), $allowHours) || $record_id)
				{
					$object_id=$this->ConnectionHelper->getObjectId('inventory');
					$product_identity_obj_id=$this->ConnectionHelper->getObjectId('product_identity');

					$platform_account=$this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['api_domain', 'access_token']);
					if($platform_account)
					{
						$DefaultInventoryWarehouseId = NULL;
						$DefaultWarehouseId = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "inventory_warehouse", ['api_code']);
						if($DefaultWarehouseId)
						{
							$DefaultInventoryWarehouseId = $DefaultWarehouseId->api_code;
						}

						$mapping_data=$this->FieldMappingHelper->getMappedField($user_integration_id, NULL, $product_identity_obj_id);
						if($mapping_data)
						{
							if($mapping_data['destination_platform_id'] == 'bluecherry')
							{
								$destination_row_data = $mapping_data['destination_row_data'];
								$source_row_data = $mapping_data['source_row_data'];
							}
							else
							{
								$destination_row_data = $mapping_data['source_row_data'];
								$source_row_data = $mapping_data['destination_row_data'];
							}

							$source_platform_products=DB::table('platform_product as source_platform_product')
							->join('platform_product as destination_platform_product', 'source_platform_product.'.$source_row_data, '=',  'destination_platform_product.'.$destination_row_data)
							->join('platform_product_inventory', 'source_platform_product.id', '=', 'platform_product_inventory.platform_product_id')
							->leftJoin('platform_product_detail_attributes', 'destination_platform_product.id', '=', 'platform_product_detail_attributes.platform_product_id')
							->select('source_platform_product.id', 'source_platform_product.upc', 'source_platform_product.sku', 'destination_platform_product.api_product_code as bluecherry_api_product_code', 'destination_platform_product.upc as upc_num', 'destination_platform_product.ean as ean_num', 'platform_product_detail_attributes.color_code', 'platform_product_detail_attributes.size_desc', 'platform_product_detail_attributes.dimension', 'platform_product_detail_attributes.division', 'platform_product_detail_attributes.lbl_code', 'platform_product_detail_attributes.season')
							->where(['source_platform_product.user_integration_id'=>$user_integration_id, 'destination_platform_product.user_integration_id'=>$user_integration_id, 'source_platform_product.platform_id'=>$source_platform_id, 'destination_platform_product.platform_id'=>$this->platformId])
							->where(function($query) use($record_id){
								if($record_id > 0)
								{
									$query->where('source_platform_product.id', $record_id);
								}
								else
								{
									$query->where('source_platform_product.inventory_sync_status', 'Ready');
								}
							})
							->where('platform_product_inventory.quantity', '>', 0)
							->where('source_platform_product.is_deleted', 0)
							->where('destination_platform_product.is_deleted', 0)
							->limit($process_limit)
							->orderBy('source_platform_product.updated_at', 'asc')
							->distinct()
							->get();

							$inventoryData = [];
							$productIds = [];
							$inventoryIds = [];
							foreach($source_platform_products as $source_platform_product)
							{
								$platform_product_inventories=PlatformProductInventory::select('id', 'quantity', 'api_warehouse_id')->where(['user_integration_id'=>$user_integration_id, 'platform_product_id'=>$source_platform_product->id])->where('quantity', '>', 0)->get();
								if(count($platform_product_inventories))
								{
									foreach($platform_product_inventories as $platform_product_inventory)
									{
										$InventoryWarehouseId = null;
										$warehouseId = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "inventory_warehouse", ['api_code'], 'regular', $platform_product_inventory->api_warehouse_id);
										if($warehouseId)
										{
											$InventoryWarehouseId = $warehouseId->api_code;
										}
										else
										{
											$InventoryWarehouseId = $DefaultInventoryWarehouseId;
										}

										if($InventoryWarehouseId)
										{
											$productIds[] = $source_platform_product->id;
											$inventoryIds[] = $platform_product_inventory->id;

											$inventoryData[] = array(
												'adj_num'=>date('dHis'), //Required //Unique Adjustment Transaction
												'color_code'=>$source_platform_product->color_code, //Required if Present //style
												'dimension'=>$source_platform_product->dimension, //Required if Present //style
												'division'=>$source_platform_product->division, //style
												'ean'=>$source_platform_product->ean_num, //Required if Present //style
												'isa_num'=>date('yHs').rand(100,999), //ISA Control Number //Sequential 9 digit counter unique to each transaction
												'lbl_code'=>$source_platform_product->lbl_code, //Required if Present //style
												'location'=>$InventoryWarehouseId, //Required //BC Warehouse Location Code //style
												'pix_code'=>'00', //Required //Hardcode value '00'
												'pix_type'=>'605', //Required //Hardcode value '605'
												'receipt_date'=>date('Y-m-d'),
												'season'=>$source_platform_product->season, //style
												'size_adj'=>'A', //Required //Hardcode 'A'
												'size_desc'=>$source_platform_product->size_desc, //Required //BC Style Size Description //style
												'size_qty'=>$platform_product_inventory->quantity, //Required //Quantity
												'style'=>$source_platform_product->bluecherry_api_product_code, //Required //style
												'upc_num'=>$source_platform_product->upc_num, //Required //UPC Number for Style
												'vnd_id'=>strtoupper($source_platform_name),
												'vnd_qual'=>'ZZ'
											);
										}
										else
										{
											$return_data = "Inventory warehouse not mapped!.";
											$this->MainModel->makeUpdate('platform_product', ['inventory_sync_status'=>'Ignore'], ['id'=>$source_platform_product->id]);
											$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_product->id, "Inventory warehouse not mapped!.");
										}
									}
								}
								else
								{
									$this->MainModel->makeUpdate('platform_product', ['inventory_sync_status'=>'Synced'], ['id'=>$source_platform_product->id]);
									$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_product->id, 'Inventory synced successfully!');
								}
							}

							//-----
							if(count($inventoryData))
							{
								$sync_status = 'Failed';
								$log_status = 'failed';
								$log_message = '';

								$productIds = array_unique($productIds);
								$inventoryIds = array_unique($inventoryIds);

								$service_url1 = $this->MainModel->decryptString($platform_account->api_domain).'/api/PhysicalInventoryTM';
								$response1 = $this->BlueCherryApi->PhysicalInventoryTM($this->MainModel->decryptString($platform_account->access_token), $service_url1, json_encode($inventoryData));

								$result1 = json_decode($response1, true);
								if(isset($result1['pkey']))
								{
									$sync_status = 'Synced';
									$log_status = 'success';
									$log_message = 'Inventory synced successfully!';
								}
								elseif(isset($result1['errors'][0]['errorMessage']))
								{
									$log_message = $return_data = $result1['errors'][0]['errorMessage'];
								}
								elseif(isset($result1['message'][0]['message']))
								{
									$log_message = $return_data = $result1['message'][0]['message'];
								}
								elseif(isset($result1['message']))
								{
									$log_message = $return_data = $result1['message'];
								}

								PlatformProduct::whereIn('id', $productIds)->update(['inventory_sync_status'=>$sync_status]);
								PlatformProductInventory::whereIn('id', $inventoryIds)->update(['sync_status'=>$sync_status]);
								foreach($productIds as $productId)
								{
									$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, $log_status, $productId, $log_message);
								}
							}
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - BlueCherryApiController - UpdateInventory - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		/* create purchase order received get from shiphero*/
		public function CreatePurchaseOrderReceived($user_id=0, $user_integration_id=0, $source_platform_name='', $platform_workflow_rule_id=0, $user_workflow_rule_id=0, $record_id=0)
		{
			$return_data = true;
			$process_limit = 25;
			try
			{
				$source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
				$object_id = $this->ConnectionHelper->getObjectId('goods_in_note');

				$platform_account=$this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['api_domain', 'access_token']);
				if($platform_account)
				{
                    $source_row_data = $destination_row_data = 'sku';
					$product_identity_obj_id = $this->ConnectionHelper->getObjectId('product_identity');
					$mapping_data = $this->FieldMappingHelper->getMappedField($user_integration_id, NULL, $product_identity_obj_id);
					if($mapping_data)
					{
						$source_row_data = $destination_row_data = 'sku';
						if($mapping_data['destination_platform_id'] == 'bluecherry')
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

					$platform_order_shipments = DB::table('platform_order_shipments as pos')->join('platform_order as po', 'pos.platform_order_id', '=', 'po.id')
					->select('pos.id', 'pos.shipment_id', 'platform_order_id', 'pos.order_id', 'pos.tracking_info', 'pos.shipping_method', 'pos.carrier_code', 'pos.tracking_url', 'pos.created_at','pos.sync_status','po.shipment_status','pos.linked_id')
					->where(['pos.user_id'=>$user_id, 'pos.user_integration_id'=>$user_integration_id, 'pos.platform_id'=>$source_platform_id,'po.order_type'=>'PO','pos.type'=>'POShipment'])
					->where(function($query) use($record_id){
						if($record_id > 0)
						{
							$query->where(['pos.id'=>$record_id]);
						}
						else
						{
							$query->where(['pos.sync_status'=>'Ready']);
						}
					})
					->limit($process_limit)
					->orderBy('pos.id', 'asc')
					->get();

					foreach($platform_order_shipments as $platform_order_shipment)
					{
						$linked_id = $platform_order_shipment->linked_id;

						$destination_platform_order = $this->MainModel->getFirstResultByConditions('platform_order', [ 'user_integration_id'=>$user_integration_id, 'linked_id'=>$platform_order_shipment->platform_order_id, 'order_type'=>'PO'], ['id', 'api_order_id','order_number', 'shipment_status']);

						/*$source_platform_order = $this->MainModel->getFirstResultByConditions('platform_order', ['user_id'=>$user_id, 'user_integration_id'=>$user_integration_id, 'id'=>$platform_order_shipment->platform_order_id,'order_type'=>'PO'], ['id', 'shipment_status']);*/

                        //\Storage::disk('local')->append('po_received_bluecherry_tracking.txt', ' time: ' . date('Y-m-d H:i:s') .PHP_EOL .'Record Id '.$platform_order_shipment->id.'| Step 1 Response '.json_encode($destination_platform_order,true));

						if($destination_platform_order)
						{
                            $line_items_qty = [];
                            $line_send_item_qty = [];
                            $platform_order_shipment_lines = $this->MainModel->getResultByConditions('platform_order_shipment_lines', ['platform_order_shipment_id'=>$platform_order_shipment->id],['quantity','sent_quantity','id','sku']);
							if(count($platform_order_shipment_lines) > 0){
								foreach($platform_order_shipment_lines as $posl){
                                //sent_quantity is used for keeping  track of all the quaitities sent to destination platform
                                    $new_sent_qty = floatval($posl->quantity) - floatval($posl->sent_quantity);

                                    if($new_sent_qty > 0){
                                        $line_items_qty[$posl->sku] = $new_sent_qty;
                                        $line_send_item_qty[$posl->id] = floatval($new_sent_qty) + floatval($posl->sent_quantity);
                                    }

                                }
                            }

							//call pick
							//$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/pick?_include=all&pkey='.$destination_platform_order->api_order_id;

							$service_url = $this->MainModel->decryptString($platform_account->api_domain).'/api/productionOrderByStage?_include=all&stage_num=3&pkey='
							.$destination_platform_order->api_order_id;

							//productionOrderByStage?_include=all&pkey=55
							$response = $this->BlueCherryApi->CallAPI('GET', $this->MainModel->decryptString($platform_account->access_token), $service_url);

                            //\Storage::disk('local')->append('po_received_bluecherry_tracking.txt', ' time: ' . date('Y-m-d H:i:s') .PHP_EOL .'Record Id '.$platform_order_shipment->id.'| Step 2 Response '.$response);

							$result = json_decode($response, true);
                            //echo "<pre>";
							//print_r($result);

							if(isset($result['data']['header'][0]['pkey']))
							{
								$order = $result['data']['header'][0];

                                $receiptDetail = [];
                                if(isset($order['productionorderdetailbystage']['rows'][0]['pkey']))
								{
									foreach($order['productionorderdetailbystage']['rows'] as $item)
									{
                                        if (isset($item[$destination_row_data]) && array_key_exists($item[$destination_row_data], $line_items_qty)) {

                                            $receiptDetail[] = array(
                                                "adj_type"=> "A", //Required--
                                                //"cntr_num"=> @$order['center_code'], //Required if Present--
                                                "color_code"=> @$item['color_code'], //Required--
                                                //"contr_lot"=> "C10", //Limited Use
                                                "dimension"=> @$item['dimension'], //Required--
                                                //"ean"=> @$item['ean'], //Required
                                                "isa_num"=> date('yHs').rand(100,999),//Required-- //ISA Control Number //Sequential 9 digit counter unique to each transaction
                                                //"lbl_code"=> @$item['lbl_code'], //Required if Present--
                                                "location"=> @$item['location'], //Required--
                                                //"over_recv_ok"=> "C", //Limited Use
                                                "pix_code"=> "01",//yuri to confirm
                                                //"pix_lock_type"=> "C2",//yuri to confirm
                                                "pix_type"=> "100", //Required--
                                                "prod_line"=> @$item['prod_line'], //Required--
                                                "prod_num"=> @$destination_platform_order->order_number, //Required--
                                                "receipt_date"=> date('m/d/Y', strtotime($platform_order_shipment->created_at)),  //Required--
                                                "shipment_num"=> 0, //Required
                                                "size_adj"=> "A", //Required--
                                                "size_desc"=> @$item['size_desc'], //Required--
                                                "size_qty"=> @$line_items_qty[$item[$destination_row_data]] ? @$line_items_qty[$item[$destination_row_data]] : 0, //Required
                                                "style"=> @$item['style'], //Required--
                                                "upc_num"=> @$item['upc'], //Required--
                                                "vnd_id"=> strtoupper($source_platform_name), //Required--
                                                //"vnd_key"=> "C20",//Limited Use
                                                "vnd_qual"=> "ZZ"//Required--
                                            );
										}
									}

                                    //start api call for create PO in bluecherry
                                    $service_url1 = $this->MainModel->decryptString($platform_account->api_domain).'/api/ProductionReceiptAdvice';
                                    $response1 = $this->BlueCherryApi->CreatePurchaseOrderReceipt($this->MainModel->decryptString($platform_account->access_token),
                                    $service_url1, json_encode($receiptDetail));

                                    \Storage::disk('local')->append('po_received_bluecherry_tracking.txt', ' time: ' . date('Y-m-d H:i:s') .PHP_EOL .'Record Id '.$platform_order_shipment->id.'| Step 3 Response '.$response1);

                                    $result1 = json_decode($response1, true);
                                    //echo "<pre>";
                                    //print_r($result1);
                                    if(isset($result1['pkey']))
                                    {
										$ShipmentData = [];
										$ShipmentData['user_id'] = $user_id;
										$ShipmentData['platform_id'] = $this->platformId;
										$ShipmentData['user_integration_id'] = $user_integration_id;
										$ShipmentData['shipment_id'] = $result1['pkey'];
										$ShipmentData['platform_order_id'] = @$destination_platform_order->id ? $destination_platform_order->id : 0;
										$ShipmentData['order_id'] = @$destination_platform_order->order_number;
										$ShipmentData['type'] = "POShipment";
										$ShipmentData['linked_id'] = $platform_order_shipment->id;
										$ShipmentData['sync_status'] = "Pending";

										if ($linked_id != '') {
                                            $this->MainModel->makeUpdate('platform_order_shipments', ['sync_status' => 'Pending'], ['id' => $linked_id]);
                                        } else {
                                            $linked_id = $this->MainModel->makeInsertGetId('platform_order_shipments', $ShipmentData);
                                        }

                                        $this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Synced','linked_id'=>$linked_id], ['id'=>$platform_order_shipment->id]);

                                        $this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Synced'], ['id'=>$platform_order_shipment->platform_order_id]);

                                        $this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $platform_order_shipment->id, null);

                                        foreach($line_send_item_qty as $key=>$val){
                                            $this->MainModel->makeUpdate('platform_order_shipment_lines', ['sent_quantity'=>$val], ['id'=>$key]);
                                        }
                                    }
                                    elseif(isset($result1['errors'][0]['errorMessage']))
                                    {
                                        $return_data = $result1['errors'][0]['errorMessage'];
                                        $this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
                                        $this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$platform_order_shipment->platform_order_id]);

                                        $this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->id, $result1['errors'][0]['errorMessage']);
                                    }
                                    elseif(isset($result1['message'][0]['message']))
                                    {
                                        $return_data = $result1['message'][0]['message'];
                                        $this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
                                        $this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$platform_order_shipment->platform_order_id]);

                                        $this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->id, $result1['message'][0]['message']);
                                    }
                                    elseif(isset($result1['message']))
                                    {
                                        $return_data = $result1['message'];
                                        $this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
                                        $this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$platform_order_shipment->platform_order_id]);

                                        $this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->id, $result1['message']);
                                    }
                                }
							}
							elseif(isset($result['errors'][0]['errorMessage']))
							{
								$return_data = $result['errors'][0]['errorMessage'];
								$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
								$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$platform_order_shipment->platform_order_id]);

								$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->id, $result['errors'][0]['errorMessage']);
							}
							elseif(isset($result['errors'][0]['message']))
							{
								$return_data = $result['errors'][0]['message'];
								$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
								$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$platform_order_shipment->platform_order_id]);

								$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->id, $result['errors'][0]['message']);
							}
							elseif(isset($result['message']))
							{
								$return_data = $result['message'];
								$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
								$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$platform_order_shipment->platform_order_id]);

								$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->id, $result['message']);
							}
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - BlueCherryApiController - CreatePurchaseOrderReceived - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function test_blue()
		{
			//dd($this->MainModel->decryptString('Y2MxZDBjMWFjM2JkYzRhZGIwOWU1NGZlOWUyZWNiNTg='));

			//$this->GetProducts(Auth::user()->id, 431, 0);

			//echo $this->MainModel->encryptString('d05f4fa28c3f4d992f02c631aa092e5e');

			//$response = $this->GetPurchaseOrders(Auth::user()->id, 474, 1);

			$this->CreatePurchaseOrderReceived($user_id=97, $user_integration_id=474, $source_platform_name='shiphero', $platform_workflow_rule_id=0, $user_workflow_rule_id=890, $record_id=0);

		}

		/* Execute BlueCherry Event Methods */
		public function ExecuteBlueCherryEvents($method='', $event='', $destination_platform_id='', $user_id='', $user_integration_id='', $is_initial_sync=0, $user_workflow_rule_id='', $source_platform_id='', $platform_workflow_rule_id='', $record_id='')
		{
			$response = true;
			if($method == 'GET' && $event == 'SHIPPINGMETHOD')
			{
				$this->GetShippingMethods($user_id, $user_integration_id);
			}
			elseif($method == 'GET' && $event == 'WAREHOUSE')
			{
				$this->GetWarehouses($user_id, $user_integration_id);
			}
			elseif($method == 'GET' && $event == 'SALESORDER')
			{
				$this->GetSalesOrders($user_id, $user_integration_id, $user_workflow_rule_id);
			}
			elseif($method == 'GET' && $event == 'PURCHASEORDER')
			{
				$this->GetPurchaseOrders($user_id, $user_integration_id, $user_workflow_rule_id);
			}
			elseif($method == 'GET' && $event == 'PRODUCT')
			{
				$this->GetProducts($user_id, $user_integration_id, $is_initial_sync);
			}
			elseif($method == 'MUTATE' && $event == 'SHIPMENT')
			{
				$response = $this->CreateOrderShipment($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
			}
			elseif($method == 'MUTATE' && $event == 'INVENTORY')
			{
				$response = $this->UpdateInventory($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
			}
			elseif($method == 'MUTATE' && $event == 'PURCHASEORDERRECIEPT')
			{
				// \Storage::disk('local')->append('po_received_test.txt', 'method-'.$method.' event-'.$event);

				$response = $this->CreatePurchaseOrderReceived($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
			}

			return $response;
		}
	}