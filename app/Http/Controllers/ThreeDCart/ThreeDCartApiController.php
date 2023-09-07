<?php
	namespace App\Http\Controllers\ThreeDCart;

	use App\Http\Controllers\Controller;
	use Illuminate\Http\Request;
	use App\Helper\MainModel;
	use App\Helper\ConnectionHelper;
	use App\Helper\FieldMappingHelper;
	use App\Helper\WorkflowSnippet;
	use App\Helper\Logger;
	use App\Helper\Api\ThreeDCartApi;
	use Auth, DB;
	use Lang;
	class ThreeDCartApiController extends Controller
	{
		public static $myPlatform='3dcart';

		/**
			* Create a new controller instance.
			*
			* @return void
		*/
		public function __construct()
		{
			$this->mobj=new MainModel();
			$this->ThreeDCartApi=new ThreeDCartApi();
			$this->conn=new ConnectionHelper();
			$this->mapping=new FieldMappingHelper();
			$this->log=new Logger();
			$this->WorkflowSnippet=new WorkflowSnippet();
		}

		public function Initiate3dcartAuth(Request $request)
		{
			$platform='3dcart';
			return view("pages.apiauth.auth_3dcart", compact('platform'));
		}

		public function Connect3dcartOauth(Request $request)
		{
			$store_url=trim($request->store_url);

			if($this->mobj->checkHtmlTags( $request->all() ) ){
				Session::put('auth_msg', Lang::get('tags.validate'));
				return redirect()->back();
			 }
			 
			$platform_id=$this->conn->getPlatformIdByName('3dcart');
			if($platform_id)
			{
				$platform_api_app= $this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id'=>$platform_id], ['client_id', 'client_secret']);
				if($platform_api_app)
				{
					$redirect_url=$this->mobj->makeUrlHttpsForProd(url('/RedirectHandler3dcart'));

					$state=$store_url;
					if(!$store_url)
					{
						$this->mobj->ThrowErrorAndExit("Store URL not found</h3><br>");
					}

					if($this->mobj->decryptString($platform_api_app->client_id))
					{
						$url=\Config::get('apiconfig.3dcartUrl')."/oauth/authorize?client_id=".$this->mobj->decryptString($platform_api_app->client_id)."&redirect_uri=".$redirect_url."&state=".$state."&response_type=code&store_url=".$store_url;

						return redirect($url);
					}
					else
					{
						$this->mobj->ThrowErrorAndExit("Please try again| App configuration is not found");
					}
				}
				else
				{
					$this->mobj->ThrowErrorAndExit("Please try again| App configuration is not found");
				}
			}
			else
			{
				$this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"</a>');
			}
		}

		public function RedirectHandler3dcart(Request $request)
		{
			if(isset($request->code))
			{
				$platform_id=$this->conn->getPlatformIdByName('3dcart');
				if($platform_id)
				{
					$platform_api_app=$this->mobj->getFirstResultByConditions('platform_api_app', ['platform_id'=>$platform_id], ['client_id', 'client_secret']);
					if($platform_api_app)
					{
						$store_url=$request->state;

						$curl_post_data=array('code'=>$request->code, 'client_id'=>$this->mobj->decryptString($platform_api_app->client_id), 'client_secret'=>$this->mobj->decryptString($platform_api_app->client_secret), 'grant_type'=>'authorization_code');

						$service_url=\Config::get('apiconfig.3dcartUrl')."/oauth/token";

						$headers=['Content-Type'=>'application/x-www-form-urlencoded', 'Accept'=>'application/json'];

						$response=$this->mobj->makeCurlRequest('POST', $service_url, $curl_post_data, $headers);
						if($response)
						{
							$decode_val=json_decode($response, true);
							if(isset($decode_val['access_token']))
							{
								$OauthData=['user_id'=>Auth::user()->id, 'access_token'=>$this->mobj->encryptString($decode_val['access_token']), 'token_type'=>$decode_val['token_type'], 'account_name'=>$store_url, 'platform_id'=>$platform_id, 'token_refresh_time'=>time(), 'allow_refresh'=>0];

								$platform_account=DB::table('platform_accounts')->where(['user_id'=>Auth::user()->id, 'platform_id'=>$platform_id, 'account_name'=>$store_url])->first();
								if($platform_account)
								{
									DB::table('platform_accounts')->where('id', $platform_account->id)
									->update($OauthData);
								}
								else
								{
									DB::table('platform_accounts')->insert($OauthData);
								}
							}
							else
							{
								//When Token not found
								DB::table('platform_accounts')->where(['user_id'=>Auth::user()->id, 'platform_id'=>$platform_id, 'account_name'=>$store_url])
								->update(['access_token'=>null, 'token_type'=>null]);
							}
							echo '<script>window.close();</script>';
						}
						else
						{
							$this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"</a>');
						}
					}
					else
					{
						$this->mobj->ThrowErrorAndExit("Please try again| App configuration is not found");
					}
				}
				else
				{
					$this->mobj->ThrowErrorAndExit("Please try again| Platform configuration is not found");
				}
			}
			else
			{
				//When code not received
				$this->mobj->ThrowErrorAndExit('Authentication Error<br><a href="javascript:window.close();"</a>');
			}
		}

		public function Get3dcartShipmentMethods($user_id=0, $user_integration_id=0)
		{
			$return_data = true;
			try
			{
				$platform_id=$this->conn->getPlatformIdByName('3dcart');
				if($platform_id)
				{
					$shipping_method_object=$this->mobj->getFirstResultByConditions('platform_objects', ['name'=>"shipping_method"], ['id']);
					if($shipping_method_object)
					{
						$this->mobj->makeUpdate('platform_object_data', ['status'=>0], ['user_integration_id'=>$user_integration_id, 'platform_id'=>$platform_id, 'platform_object_id'=>$shipping_method_object->id, 'status'=>1]);

						$platform_account=$this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platform_id, ['id', 'access_token']);
						if($platform_account)
						{
							$orderstatus=NULL;
							$limit=300;
							$offset=0;
							$datestart=NULL;

							$lastupdatestart=date('m/d/Y', strtotime('-10 Days'));

							$response=$this->ThreeDCartApi->GetOrderList($this->mobj->decryptString($platform_account->access_token), $limit, $offset, $lastupdatestart, $orderstatus, $datestart);

							/*
								echo "<pre>";
								print_r(json_decode($response, true));
								die;
							*/

							$Orders=json_decode($response, true);
							if(isset($Orders[0]['OrderID']))
							{
								foreach($Orders as $Order)
								{
									//order shipment details
									if(isset($Order['ShipmentList'][0]['ShipmentID']))
									{
										foreach($Order['ShipmentList'] as $Shipment)
										{
											if(isset($Shipment['ShipmentMethodName']))
											{
												if($Shipment['ShipmentMethodName'])
												{
													$ShipmentMethodData=['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$shipping_method_object->id, 'api_id'=>$Shipment['ShipmentMethodID'], 'api_id'=>$Shipment['ShipmentMethodName'], 'name'=>$Shipment['ShipmentMethodName'], 'api_code'=>$Shipment['ShipmentMethodName'], 'status'=>1];

													$platform_object_data=$this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$shipping_method_object->id, 'name'=>$Shipment['ShipmentMethodName']], ['id']);
													if($platform_object_data)
													{
														$this->mobj->makeUpdate('platform_object_data', $ShipmentMethodData, ['id'=>$platform_object_data->id]);
													}
													else
													{
														$this->mobj->makeInsert('platform_object_data', $ShipmentMethodData);
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
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id." -> ThreeDCartApiController -> Get3dcartShipmentMethods -> ".$e->getLine()." -> ".$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function Get3dcartPaymentMethods($user_id=0, $user_integration_id=0)
		{
			$return_data = true;
			try
			{
				$platform_id=$this->conn->getPlatformIdByName('3dcart');
				if($platform_id)
				{
					$payment_object=$this->mobj->getFirstResultByConditions('platform_objects', ['name'=>"payment"], ['id']);
					if($payment_object)
					{
						$this->mobj->makeUpdate('platform_object_data', ['status'=>0], ['user_integration_id'=>$user_integration_id, 'platform_id'=>$platform_id, 'platform_object_id'=>$payment_object->id, 'status'=>1]);
						$platform_account=$this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platform_id, ['id', 'access_token']);
						if($platform_account)
						{

							$orderstatus=NULL;
							$limit=300;
							$offset=0;
							$datestart=NULL;

							$lastupdatestart=date('m/d/Y', strtotime('-10 Days'));

							$response=$this->ThreeDCartApi->GetOrderList($this->mobj->decryptString($platform_account->access_token), $limit, $offset, $lastupdatestart, $orderstatus, $datestart);

							/*
								echo "<pre>";
								print_r(json_decode($response, true));
								die;
							*/

							$Orders=json_decode($response, true);
							if(isset($Orders[0]['OrderID']))
							{
								foreach($Orders as $Order)
								{
									//order transaction details
									if(isset($Order['TransactionList'][0]['TransactionIndexID']))
									{
										foreach($Order['TransactionList'] as $Transaction)
										{
											if(isset($Transaction['TransactionMethod']))
											{
												if($Transaction['TransactionMethod'])
												{
													$PaymentData=['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$payment_object->id, 'api_id'=>$Transaction['TransactionMethod'], 'name'=>$Transaction['TransactionMethod'], 'api_code'=>$Transaction['TransactionMethod'], 'status'=>1];

													$platform_object_data=$this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$payment_object->id, 'name'=>$Transaction['TransactionMethod']], ['id']);
													if($platform_object_data)
													{
														$this->mobj->makeUpdate('platform_object_data', $PaymentData, ['id'=>$platform_object_data->id]);
													}
													else
													{
														$this->mobj->makeInsert('platform_object_data', $PaymentData);
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
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id." -> ThreeDCartApiController -> Get3dcartPaymentMethods -> ".$e->getLine()." -> ".$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function Get3dcartOrders($user_id=0, $user_integration_id=0, $time_sync=false)
		{
			$return_data = true;
			try
			{
				$platform_id=$this->conn->getPlatformIdByName('3dcart');
				if($platform_id)
				{
					$platform_account=$this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platform_id, ['id', 'access_token']);
					if($platform_account)
					{
						$user_workflow_rule=$this->mobj->getFirstResultByConditions('user_workflow_rule', [ 'user_integration_id'=>$user_integration_id, 'status'=>1], ['platform_workflow_rule_id', 'sync_start_date']);
						if($user_workflow_rule)
						{
							//get mapped order statuses
							$order_status_list= $this->mapping->getMappedDataByName($user_integration_id, $user_workflow_rule->platform_workflow_rule_id, "get_sorder_status", ['api_id'], "regular", NULL, "multi", "source");
							foreach($order_status_list as $orderstatus)
							{
								$limit=300;
								$offset=0;
								$datestart=NULL;
								$lastupdatestart=NULL;

								if($user_workflow_rule->sync_start_date)
								{
									$lastupdatestart=date('m/d/Y', strtotime($user_workflow_rule->sync_start_date));
								}

								$pull_time=DB::table('platform_order')->select('api_updated_at')->where([ 'user_integration_id'=>$user_integration_id, 'platform_id'=>$platform_id, 'order_status'=>$orderstatus])->whereNotNull('api_updated_at')->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H:%i:%s') DESC")/*->orderBy("api_updated_at", "DESC")*/->first();
								if($pull_time)
								{
									$lastupdatestart=date('m/d/Y', strtotime($pull_time->api_updated_at));
								}

								do
								{
									$allow_next_cal=false;
									$response=$this->ThreeDCartApi->GetOrderList($this->mobj->decryptString($platform_account->access_token), $limit, $offset, $lastupdatestart, $orderstatus, $datestart);

									$Orders=json_decode($response, true);
									if(isset($Orders[0]['OrderID']))
									{
										$shipping_method_object=$this->mobj->getFirstResultByConditions('platform_objects', ['name'=>"shipping_method"], ['id']);
										$payment_object=$this->mobj->getFirstResultByConditions('platform_objects', ['name'=>"payment"], ['id']);
										$allow_next_cal=true;
										foreach($Orders as $Order)
										{
											//DB::beginTransaction();
											//order line item
											if(isset($Order['OrderItemList'][0]['OrderItemID']))
											{
												if($user_workflow_rule->sync_start_date && (strtotime($user_workflow_rule->sync_start_date) > strtotime($Order['OrderDate'])))
												{
													continue;
												}

												$OrderDiscount = $Order['OrderDiscount'];
												$OrderShipping = 0;
												$OrderSalesTax = ($Order['SalesTax'] + $Order['SalesTax2'] + $Order['SalesTax3']);

												//order customer details
												$CustomerData=['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'api_customer_id'=>$Order['CustomerID'], 'customer_name'=>trim($Order['BillingFirstName'].' '.$Order['BillingLastName']), 'first_name'=>$Order['BillingFirstName'], 'last_name'=>$Order['BillingLastName'], 'company_name'=>$Order['BillingCompany'], 'postal_addresses'=>$Order['BillingZipCode'], 'email'=>$Order['BillingEmail'], 'phone'=>$Order['BillingPhoneNumber']];

												if($Order['CustomerID'])
												{
													$platform_customer=$this->mobj->getFirstResultByConditions('platform_customer', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'api_customer_id'=>$Order['CustomerID']], ['id']);
												}
												else
												{
													$platform_customer=$this->mobj->getFirstResultByConditions('platform_customer', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'email'=>$Order['BillingEmail']], ['id']);
												}

												if($platform_customer)
												{
													$platform_customer_id=$platform_customer->id;
													$this->mobj->makeUpdate('platform_customer', $CustomerData, ['id'=>$platform_customer->id]);
												}
												else
												{
													$platform_customer_id=$this->mobj->makeInsertGetId('platform_customer', $CustomerData);
												}

												$OrderData=['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_customer_id'=>$platform_customer_id, 'order_type'=>'SO', 'api_order_id'=>$Order['OrderID'], 'customer_email'=>$Order['BillingEmail'], 'order_number'=>$Order['InvoiceNumberPrefix'].''.$Order['InvoiceNumber'], 'order_date'=>$Order['OrderDate'], 'order_status'=>$Order['OrderStatusID'], 'total_discount'=>$Order['OrderDiscount'], 'total_tax'=>($Order['SalesTax'] + $Order['SalesTax2'] + $Order['SalesTax3']), 'total_amount'=>$Order['OrderAmount'], 'notes'=>$Order['CustomerComments'], 'shipping_method'=>@$Order['ShipmentList'][0]['ShipmentMethodName'], 'shipping_total'=>@$Order['ShipmentList'][0]['ShipmentCost'], 'api_updated_at'=>$Order['LastUpdate']];

												if($Order['OrderStatusID']==5)
												{
													$OrderData['is_voided']=1;
												}
												else
												{
													$OrderData['is_voided']=0;
												}

												if($Order['OrderStatusID']==11)
												{
													$OrderData['api_order_payment_status']='unpaid';
												}
												else
												{
													$OrderData['api_order_payment_status']='paid';
												}

												$platform_order_id = NULL;
												$platform_order=$this->mobj->getFirstResultByConditions('platform_order', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'order_type'=>'SO', 'api_order_id'=>(string)$Order['OrderID']], ['id', 'api_updated_at', 'linked_id']);
												if($platform_order)
												{
													if($platform_order->api_updated_at != $Order['LastUpdate'] && $Order['OrderStatusID'] == 5 && $platform_order->linked_id != 0)
													{
														$OrderData['sync_status']='Ready';
													}
													elseif($platform_order->api_updated_at != $Order['LastUpdate'] && $platform_order->linked_id == 0)
													{
														$OrderData['sync_status']='Ready';
													}

													if($platform_order->api_updated_at != $Order['LastUpdate'])
													{
														$OrderData['order_updated_at']=date("Y-m-d H:i:s");
													}

													$platform_order_id = $platform_order->id;
													$this->mobj->makeUpdate('platform_order', $OrderData, ['id'=>$platform_order->id]);
												}
												else
												{
													$OrderData['sync_status']='Ready';
													$OrderData['order_updated_at']=date("Y-m-d H:i:s");

													if($Order['OrderStatusID'] != 5)
													{
														$platform_order_id=$this->mobj->makeInsertGetId('platform_order', $OrderData);
													}
												}

												if($platform_order_id)
												{
													//order address
													$OrderAddressData=['platform_order_id'=>$platform_order_id, 'address_type'=>'billing', 'address_name'=>trim($Order['BillingFirstName'].' '.$Order['BillingLastName']), 'firstname'=>$Order['BillingFirstName'], 'lastname'=>$Order['BillingLastName'], 'company'=>$Order['BillingCompany'], 'address1'=>$Order['BillingAddress'], 'address2'=>$Order['BillingAddress2'], 'city'=>$Order['BillingCity'], 'state'=>$Order['BillingState'], 'postal_code'=>$Order['BillingZipCode'], 'country'=>$Order['BillingCountry'], 'email'=>$Order['BillingEmail'], 'phone_number'=>$Order['BillingPhoneNumber']];

													$platform_order_billing_address=$this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id'=>$platform_order_id, 'address_type'=>'billing'], ['id']);
													if($platform_order_billing_address)
													{
														$this->mobj->makeUpdate('platform_order_address', $OrderAddressData, ['id'=>$platform_order_billing_address->id]);
													}
													else
													{
														$this->mobj->makeInsert('platform_order_address', $OrderAddressData);
													}

													//order line item
													foreach($Order['OrderItemList'] as $OrderItem)
													{
														$subtotal=($OrderItem['ItemQuantity'] * ($OrderItem['ItemUnitPrice'] + $OrderItem['ItemOptionPrice']));
														$subtotal_tax=0;
														$total=$subtotal + $subtotal_tax;
														$total_tax=$subtotal_tax;

														$OrderItemData=['platform_order_id'=>$platform_order_id, 'api_order_line_id'=>$OrderItem['ItemIndexID'], 'api_product_id'=>$OrderItem['CatalogID'], 'product_name'=>$OrderItem['ItemDescription'], 'sku'=>$OrderItem['ItemID'], 'qty'=>$OrderItem['ItemQuantity'], 'unit_price'=>$OrderItem['ItemUnitPrice'], 'subtotal'=>$subtotal, 'subtotal_tax'=>$subtotal_tax, 'total'=>$total, 'total_tax'=>$total_tax];

														$platform_order_line=$this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id'=>$platform_order_id, 'api_order_line_id'=>$OrderItem['ItemIndexID']], ['id']);
														if($platform_order_line)
														{
															$this->mobj->makeUpdate('platform_order_line', $OrderItemData, ['id'=>$platform_order_line->id]);
														}
														else
														{
															$this->mobj->makeInsert('platform_order_line', $OrderItemData);
														}
													}

													//order shipment details
													if(isset($Order['ShipmentList'][0]['ShipmentID']))
													{
														$firstShipment = 1;
														foreach($Order['ShipmentList'] as $Shipment)
														{
															if($firstShipment == 1)
															{
																$ShipmentEmail = $Order['BillingEmail'];
																if($Shipment['ShipmentEmail'])
																{
																	$ShipmentEmail = $Shipment['ShipmentEmail'];
																}

																$OrderShipmentAddressData=['platform_order_id'=>$platform_order_id, 'address_type'=>'shipping', 'address_name'=>trim($Shipment['ShipmentFirstName'].' '.$Shipment['ShipmentLastName']), 'firstname'=>$Shipment['ShipmentFirstName'], 'lastname'=>$Shipment['ShipmentLastName'], 'company'=>$Shipment['ShipmentCompany'], 'address1'=>$Shipment['ShipmentAddress'], 'address2'=>$Shipment['ShipmentAddress2'], 'city'=>$Shipment['ShipmentCity'], 'state'=>$Shipment['ShipmentState'], 'postal_code'=>$Shipment['ShipmentZipCode'], 'country'=>$Shipment['ShipmentCountry'], 'email'=>$ShipmentEmail, 'phone_number'=>$Shipment['ShipmentPhone']];

																$platform_order_shipping_address=$this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id'=>$platform_order_id, 'address_type'=>'shipping'], ['id']);
																if($platform_order_shipping_address)
																{
																	$this->mobj->makeUpdate('platform_order_address', $OrderShipmentAddressData, ['id'=>$platform_order_shipping_address->id]);
																}
																else
																{
																	$this->mobj->makeInsert('platform_order_address', $OrderShipmentAddressData);
																}

																$firstShipment = 0;
															}

															if($shipping_method_object && $Shipment['ShipmentMethodName'])
															{
																$ShipmentMethodData=['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$shipping_method_object->id, 'api_id'=>$Shipment['ShipmentMethodName'], 'name'=>$Shipment['ShipmentMethodName'], 'api_code'=>$Shipment['ShipmentMethodName'], 'status'=>1];

																$object_data=$this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$shipping_method_object->id, 'name'=>$Shipment['ShipmentMethodName']], ['id']);
																if($object_data)
																{
																	$this->mobj->makeUpdate('platform_object_data', $ShipmentMethodData, ['id'=>$object_data->id]);
																}
																else
																{
																	$this->mobj->makeInsert('platform_object_data', $ShipmentMethodData);
																}
															}
															$OrderShipping = $OrderShipping + $Shipment['ShipmentCost'];
														}
													}

													//order transaction details
													if(isset($Order['TransactionList'][0]['TransactionIndexID']))
													{
														foreach($Order['TransactionList'] as $Transaction)
														{
															$TransactionData=['platform_order_id'=>$platform_order_id, 'api_transaction_index_id'=>$Transaction['TransactionIndexID'], 'transaction_id'=>$Transaction['TransactionID'], 'transaction_datetime'=>$Transaction['TransactionDateTime'], 'transaction_type'=>$Transaction['TransactionType'], 'transaction_method'=>$Transaction['TransactionMethod'], 'transaction_amount'=>$Transaction['TransactionAmount'], 'transaction_approval'=>$Transaction['TransactionApproval'], 'transaction_reference'=>$Transaction['TransactionReference'], 'transaction_gateway_id'=>$Transaction['TransactionGatewayID'], 'transaction_cvv2'=>$Transaction['TransactionCVV2'], 'transaction_avs'=>$Transaction['TransactionAVS'], 'transaction_response_text'=>$Transaction['TransactionResponseText'], 'transaction_response_code'=>$Transaction['TransactionResponseCode'], 'transaction_captured'=>$Transaction['TransactionCaptured']];

															$platform_order_transaction=$this->mobj->getFirstResultByConditions('platform_order_transactions', ['platform_order_id'=>$platform_order_id, 'api_transaction_index_id'=>$Transaction['TransactionIndexID']], ['id']);
															if($platform_order_transaction)
															{
																$this->mobj->makeUpdate('platform_order_transactions', $TransactionData, ['id'=>$platform_order_transaction->id]);
															}
															else
															{
																if($Transaction['TransactionType'] != 'DECLINED-Sale')
																{
																	$this->mobj->makeInsert('platform_order_transactions', $TransactionData);
																}
															}

															if($payment_object && $Transaction['TransactionMethod'])
															{
																$PaymentData=['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$payment_object->id, 'name'=>$Transaction['TransactionMethod'], 'status'=>1];

																$object_data=$this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$payment_object->id, 'name'=>$Transaction['TransactionMethod']], ['id']);
																if($object_data)
																{
																	$this->mobj->makeUpdate('platform_object_data', $PaymentData, ['id'=>$object_data->id]);
																}
																else
																{
																	$this->mobj->makeInsert('platform_object_data', $PaymentData);
																}
															}
														}
													}

													if($OrderShipping > 0)
													{
														$OrderItemData=['platform_order_id'=>$platform_order_id, 'product_name'=>"SHIPPING", 'qty'=>1, 'unit_price'=>$OrderShipping, 'subtotal'=>$OrderShipping, 'total'=>$OrderShipping, 'row_type'=>"SHIPPING"];

														$platform_order_line=$this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id'=>$platform_order_id, 'row_type'=>"SHIPPING"], ['id']);
														if($platform_order_line)
														{
															$this->mobj->makeUpdate('platform_order_line', $OrderItemData, ['id'=>$platform_order_line->id]);
														}
														else
														{
															$this->mobj->makeInsert('platform_order_line', $OrderItemData);
														}
													}
													else
													{
														$this->mobj->makeDelete('platform_order_line', ['platform_order_id'=>$platform_order_id, 'row_type'=>"SHIPPING"]);
													}

													if($OrderDiscount > 0)
													{
														$OrderItemData=['platform_order_id'=>$platform_order_id, 'product_name'=>"DISCOUNT", 'qty'=>1, 'unit_price'=>$OrderDiscount * (-1), 'subtotal'=>$OrderDiscount * (-1), 'total'=>$OrderDiscount * (-1), 'row_type'=>"DISCOUNT"];

														$platform_order_line=$this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id'=>$platform_order_id, 'row_type'=>"DISCOUNT"], ['id']);
														if($platform_order_line)
														{
															$this->mobj->makeUpdate('platform_order_line', $OrderItemData, ['id'=>$platform_order_line->id]);
														}
														else
														{
															$this->mobj->makeInsert('platform_order_line', $OrderItemData);
														}
													}
													else
													{
														$this->mobj->makeDelete('platform_order_line', ['platform_order_id'=>$platform_order_id, 'row_type'=>"DISCOUNT"]);
													}

													if($OrderSalesTax > 0)
													{
														$OrderItemData=['platform_order_id'=>$platform_order_id, 'product_name'=>"Sales Tax", 'qty'=>1, 'unit_price'=>$OrderSalesTax, 'subtotal'=>$OrderSalesTax, 'total'=>$OrderSalesTax, 'row_type'=>"TAX"];

														$platform_order_line=$this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id'=>$platform_order_id, 'row_type'=>"TAX"], ['id']);
														if($platform_order_line)
														{
															$this->mobj->makeUpdate('platform_order_line', $OrderItemData, ['id'=>$platform_order_line->id]);
														}
														else
														{
															$this->mobj->makeInsert('platform_order_line', $OrderItemData);
														}
													}
													else
													{
														$this->mobj->makeDelete('platform_order_line', ['platform_order_id'=>$platform_order_id, 'row_type'=>"TAX"]);
													}
												}
											}

											//DB::commit();
										}
										$offset=$offset + 299;
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
				//DB::rollBack();
				\Log::error($user_integration_id.' - ThreeDCartApiController - Get3dcartOrders - '.$e->getLine().' - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function Get3dcartWarehouses($user_id=0, $user_integration_id=0)
		{
			$return_data = true;
			try
			{
				$platform_id=$this->conn->getPlatformIdByName('3dcart');
				if($platform_id)
				{
					$warehouse_object=$this->mobj->getFirstResultByConditions('platform_objects', ['name'=>"warehouse"], ['id']);
					if($warehouse_object)
					{
						$this->mobj->makeUpdate('platform_object_data', ['status'=>0], ['user_integration_id'=>$user_integration_id, 'platform_id'=>$platform_id, 'platform_object_id'=>$warehouse_object->id, 'status'=>1]);

						$platform_account=$this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platform_id, ['id', 'access_token']);
						if($platform_account)
						{
							$limit=200;
							$offset=0;
							$lastupdatestart=date('m/d/Y', strtotime('-10 Days'));

							$response=$this->ThreeDCartApi->GetProductList($this->mobj->decryptString($platform_account->access_token), $limit, $offset, $lastupdatestart);
							//echo "<pre>";
							//print_r(json_decode($response, true));
							//die;
							$Products=json_decode($response, true);
							if(isset($Products[0]['SKUInfo']['CatalogID']))
							{
								foreach($Products as $Product)
								{
									if(isset($Product['WarehouseLocation']))
									{
										if($Product['WarehouseLocation'])
										{
											$WarehouseData=['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$warehouse_object->id, 'api_id'=>$Product['WarehouseLocation'], 'name'=>$Product['WarehouseLocation'], 'api_code'=>$Product['WarehouseLocation'], 'status'=>1];

											$platform_object_data=$this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$warehouse_object->id, 'name'=>$Product['WarehouseLocation']], ['id']);
											if($platform_object_data)
											{
												$this->mobj->makeUpdate('platform_object_data', $WarehouseData, ['id'=>$platform_object_data->id]);
											}
											else
											{
												$this->mobj->makeInsert('platform_object_data', $WarehouseData);
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
				\Log::error($user_integration_id." -> ThreeDCartApiController -> Get3dcartWarehouses -> ".$e->getLine()." -> ".$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function Get3dcartProducts($user_id=0, $user_integration_id=0)
		{
			$return_data = true;
			try
			{
				$platform_id=$this->conn->getPlatformIdByName('3dcart');
				if($platform_id)
				{
					$platform_account=$this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platform_id, ['id', 'access_token']);
					if($platform_account)
					{
						$limit=200;
						$offset=0;
						$lastupdatestart=null;
						
						$pull_time=DB::table('platform_product')->select('api_updated_at')->where([ 'user_integration_id'=>$user_integration_id, 'platform_id'=>$platform_id])->whereNotNull('api_updated_at')->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H:%i:%s') DESC")/*->orderBy("api_updated_at", "DESC")*/->first();
						if($pull_time)
						{
							$lastupdatestart=date('m/d/Y', strtotime($pull_time->api_updated_at));
						}
						
						do
						{
							$allow_next_cal=false;

							$response=$this->ThreeDCartApi->GetProductList($this->mobj->decryptString($platform_account->access_token), $limit, $offset, $lastupdatestart);
							//echo "<pre>";
							//print_r(json_decode($response, true));
							//die;
							$Products=json_decode($response, true);
							if(isset($Products[0]['SKUInfo']['CatalogID']))
							{
								$warehouse_object = $this->mobj->getFirstResultByConditions('platform_objects', ['name'=>"warehouse"], ['id']);

								$allow_next_cal = true;
								foreach($Products as $Product)
								{
									DB::beginTransaction();

									$SKUInfo = $Product['SKUInfo'];

									$ProductData = ['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$SKUInfo['CatalogID'], 'product_name'=>$SKUInfo['Name'], 'sku'=>$SKUInfo['SKU'], 'gtin'=>$Product['GTIN'], 'price'=>$SKUInfo['Price'], 'description'=>$Product['ShortDescription'], 'updated_at'=>date('Y-m-d H:i:s'), 'api_updated_at'=>$Product['LastUpdate']];

									$platform_product=$this->mobj->getFirstResultByConditions('platform_product', [ 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$SKUInfo['CatalogID'], 'sku'=>$SKUInfo['SKU']], ['id', 'api_updated_at']);
									if($platform_product)
									{
										if(strtotime($platform_product->api_updated_at) != strtotime($Product['LastUpdate']))
										{
											$ProductData['product_sync_status'] = 'Ready';
										}

										$this->mobj->makeUpdate('platform_product', $ProductData, ['id'=>$platform_product->id]);
										$platform_product_id = $platform_product->id;
									}
									else
									{
										$ProductData['created_at'] = date('Y-m-d H:i:s');
										$ProductData['product_sync_status'] = 'Ready';
										$ProductData['inventory_sync_status'] = 'Ready';
										$platform_product_id = $this->mobj->makeInsertGetId('platform_product', $ProductData);
									}

									$InventoryData=['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_product_id'=>$platform_product_id, 'api_product_id'=>$SKUInfo['CatalogID'], 'quantity'=>$SKUInfo['Stock'], 'sku'=>$SKUInfo['SKU'], 'updated_at'=>date('Y-m-d H:i:s'), 'api_updated_at'=>$Product['LastUpdate']];

									$platform_product_inventory=$this->mobj->getFirstResultByConditions('platform_product_inventory', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_product_id'=>$platform_product_id], ['id', 'quantity']);
									if($platform_product_inventory)
									{
										if($platform_product_inventory->quantity != $SKUInfo['Stock'])
										{
											$InventoryData['sync_status'] = 'Ready';
											$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Ready'], ['id'=>$platform_product_id]);
										}

										$this->mobj->makeUpdate('platform_product_inventory', $InventoryData, ['id'=>$platform_product_inventory->id]);
									}
									else
									{
										$InventoryData['sync_status'] = 'Ready';
										$InventoryData['created_at'] = date('Y-m-d H:i:s');
										$this->mobj->makeInsert('platform_product_inventory', $InventoryData);
									}

									if($warehouse_object && $Product['WarehouseLocation'])
									{
										$ShipmentMethodsData=['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$warehouse_object->id, 'api_id'=>$Product['WarehouseLocation'], 'name'=>$Product['WarehouseLocation'], 'api_code'=>$Product['WarehouseLocation'], 'status'=>1];

										$platform_object_data=$this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$warehouse_object->id, 'name'=>$Product['WarehouseLocation']], ['id']);
										if($platform_object_data)
										{
											$this->mobj->makeUpdate('platform_object_data', $ShipmentMethodsData, ['id'=>$platform_object_data->id]);
										}
										else
										{
											$this->mobj->makeInsert('platform_object_data', $ShipmentMethodsData);
										}
									}

									if(isset($Product['AdvancedOptionList'][0]['AdvancedOptionCode']))
									{
										foreach($Product['AdvancedOptionList'] as $Advanced)
										{
											$ProductAdvancedData = ['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$SKUInfo['CatalogID'], 'api_product_code'=>$Advanced['AdvancedOptionCode'], 'product_name'=>$SKUInfo['Name'], 'sku'=>$Advanced['AdvancedOptionSufix'], 'gtin'=>$Advanced['AdvancedOptionGtin'], 'price'=>$Advanced['AdvancedOptionPrice'], 'description'=>$Advanced['AdvancedOptionName'], 'parent_product_id'=>$platform_product_id, 'updated_at'=>date('Y-m-d H:i:s'), 'api_updated_at'=>$Product['LastUpdate']];

											$platform_product_advance = $this->mobj->getFirstResultByConditions('platform_product', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$SKUInfo['CatalogID'], 'api_product_code'=>$Advanced['AdvancedOptionCode']], ['id', 'api_updated_at']);
											if($platform_product_advance)
											{
												if(strtotime($platform_product_advance->api_updated_at) != strtotime($Product['LastUpdate']))
												{
													$ProductAdvancedData['product_sync_status'] = 'Ready';
												}
												
												$this->mobj->makeUpdate('platform_product', $ProductAdvancedData, ['id'=>$platform_product_advance->id]);
												$platform_product_advance_id = $platform_product_advance->id;
											}
											else
											{
												$ProductAdvancedData['created_at'] = date('Y-m-d H:i:s');
												$ProductAdvancedData['product_sync_status'] = 'Ready';
												$ProductAdvancedData['inventory_sync_status'] = 'Ready';
												$platform_product_advance_id = $this->mobj->makeInsertGetId('platform_product', $ProductAdvancedData);
											}

											$InventoryAdvancedData = ['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_product_id'=>$platform_product_advance_id, 'api_product_id'=>$SKUInfo['CatalogID'], 'quantity'=>$Advanced['AdvancedOptionStock'], 'sku'=>$Advanced['AdvancedOptionSufix'], 'updated_at'=>date('Y-m-d H:i:s'), 'api_updated_at'=>$Product['LastUpdate']];

											$platform_product_advance_inventory = $this->mobj->getFirstResultByConditions('platform_product_inventory', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_product_id'=>$platform_product_advance_id, 'api_product_id'=>$SKUInfo['CatalogID']], ['id', 'quantity']);
											if($platform_product_advance_inventory)
											{
												if($platform_product_advance_inventory->quantity != $Advanced['AdvancedOptionStock'])
												{
													$InventoryAdvancedData['sync_status'] = 'Ready';
													$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Ready'], ['id'=>$platform_product_advance_id]);
												}

												$this->mobj->makeUpdate('platform_product_inventory', $InventoryAdvancedData, ['id'=>$platform_product_advance_inventory->id]);
											}
											else
											{
												$InventoryAdvancedData['sync_status'] = 'Ready';
												$InventoryAdvancedData['created_at'] = date('Y-m-d H:i:s');
												$this->mobj->makeInsert('platform_product_inventory', $InventoryAdvancedData);
											}
										}
									}

									DB::commit();
								}
								$offset=$offset + 199;
							}
						}
						while($allow_next_cal);
					}
				}
			}
			catch(\Exception $e)
			{
				DB::rollBack();
				\Log::error($user_integration_id.' - ThreeDCartApiController - Get3dcartProducts - '.$e->getLine().' - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function Get3dcartOrderStatus($user_id=0, $user_integration_id=0)
		{
			$return_data = true;
			try
			{
				$platform_id=$this->conn->getPlatformIdByName('3dcart');
				if($platform_id)
				{
					$order_status_object=$this->mobj->getFirstResultByConditions('platform_objects', ['name'=>"order_status"], ['id']);
					if($order_status_object)
					{
						$this->mobj->makeUpdate('platform_object_data', ['status'=>0], ['user_integration_id'=>$user_integration_id, 'platform_id'=>$platform_id, 'platform_object_id'=>$order_status_object->id, 'status'=>1]);
						$platform_account=$this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platform_id, ['id', 'access_token']);
						if($platform_account)
						{
							$limit=500;
							$offset=0;

							do
							{
								$allow_next_cal=false;

								$response=$this->ThreeDCartApi->GetOrderStatusList($this->mobj->decryptString($platform_account->access_token), $limit, $offset);
								//echo "<pre>";
								//print_r(json_decode($response, true));
								//die;
								$OrderStatus=json_decode($response, true);
								if(isset($OrderStatus[0]['OrderStatusID']))
								{
									$allow_next_cal=true;
									foreach($OrderStatus as $Status)
									{
										if($Status['Visible'])
										{
											$OrderStatusData=['user_id'=>$user_id, 'platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$order_status_object->id, 'api_id'=>$Status['OrderStatusID'], 'api_code'=>$Status['Sorting'], 'description'=>$Status['StatusDefinition'], 'name'=>$Status['StatusText'], 'status'=>$Status['Visible']];

											$platform_object_data=$this->mobj->getFirstResultByConditions('platform_object_data', ['platform_id'=>$platform_id, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$order_status_object->id, 'api_id'=>$Status['OrderStatusID']], ['id']);
											if($platform_object_data)
											{
												$this->mobj->makeUpdate('platform_object_data', $OrderStatusData, ['id'=>$platform_object_data->id]);
											}
											else
											{
												$this->mobj->makeInsert('platform_object_data', $OrderStatusData);
											}
										}
									}
									$offset=$offset + 499;
								}
							}
							while($allow_next_cal);
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id." -> ThreeDCartApiController -> Get3dcartOrderStatus -> ".$e->getLine()." -> ".$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function Update3dcartOrderShipment($user_id=0, $user_integration_id=0, $source_platform_name='', $platform_workflow_rule_id=0, $user_workflow_rule_id=0, $record_id=0)
		{
			$return_data = true;
			$process_limit=100;
			try
			{
				$source_platform_id=$this->conn->getPlatformIdByName($source_platform_name);
				$destination_platform_id=$this->conn->getPlatformIdByName('3dcart');
				$object_id=$this->conn->getObjectId('sales_order_shipment');

				$destination_platform_account=$this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $destination_platform_id, ['id', 'access_token']);
				if($destination_platform_account)
				{
					do
					{
						$allow_next_call=false;

						$platform_order_shipments = DB::table('platform_order_shipments')
						->where(function ($query) use($record_id, $user_id, $user_integration_id, $source_platform_id){
							if($record_id > 0)
							{
								$query->where('platform_order_id', $record_id)->where(['sync_status'=>'Failed', 'user_integration_id'=>$user_integration_id, 'platform_id'=>$source_platform_id]);
							}
							else
							{
								$query->where(['sync_status'=>'Ready', 'user_integration_id'=>$user_integration_id, 'platform_id'=>$source_platform_id]);
							}
						})
						->where(function ($query){
							$query->whereNull('linked_id')->orWhere('linked_id', 0);
						})
						->limit($process_limit)
						->orderBy('id', 'asc')
						->get();

						if(count($platform_order_shipments) == $process_limit)
						{
							//want to loop continuously
							$allow_next_call=true;
						}

						if(count($platform_order_shipments) > 0)
						{
							foreach($platform_order_shipments as $platform_order_shipment)
							{
								$destination_platform_order = $this->mobj->getFirstResultByConditions('platform_order', [ 'user_integration_id'=>$user_integration_id, 'linked_id'=>$platform_order_shipment->platform_order_id], ['id', 'api_order_id', 'shipment_status']);
								$source_platform_order = $this->mobj->getFirstResultByConditions('platform_order', [ 'user_integration_id'=>$user_integration_id, 'id'=>$platform_order_shipment->platform_order_id], ['id', 'shipment_status']);
								if($destination_platform_order && $source_platform_order)
								{
									$platform_order_shipment_lines = DB::table('platform_order_shipment_lines')
									->leftJoin('platform_product', 'platform_order_shipment_lines.product_id', '=', 'platform_product.api_product_id')
									->select('platform_product.sku', 'platform_order_shipment_lines.quantity')
									->where('platform_order_shipment_lines.platform_order_shipment_id', $platform_order_shipment->id)
									->where('platform_product.user_id', $user_id)
									->where('platform_product.user_integration_id', $user_integration_id)
									->where('platform_product.platform_id', $source_platform_id)
									->get();

									$skuList = [];
									foreach($platform_order_shipment_lines as $platform_order_shipment_line)
									{
										if($platform_order_shipment_line->sku)
										{
											$skuList[] = $platform_order_shipment_line->sku;
										}
									}

									if(count($skuList) > 0)
									{
										$platform_order_lines = DB::table('platform_order_line')->select('api_order_line_id', 'api_product_id', 'qty', 'sku', 'unit_price')->where('platform_order_id', $destination_platform_order->id)->whereIn('sku', $skuList)->get();
										if(count($platform_order_lines) > 0)
										{
											$shipping_address = $this->mobj->getFirstResultByConditions('platform_order_address', ['platform_order_id'=>$destination_platform_order->id, 'address_type'=>'shipping'], ['firstname', 'lastname', 'company', 'address1', 'address2', 'city', 'state', 'postal_code', 'country', 'email', 'phone_number']);
											if($shipping_address)
											{
												$ShipmentData = array('ShipmentFirstName'=>$shipping_address->firstname, 'ShipmentLastName'=>($shipping_address->lastname ? $shipping_address->lastname : '.'), 'ShipmentCompany'=>$shipping_address->company, 'ShipmentAddress'=>$shipping_address->address1, 'ShipmentAddress2'=>$shipping_address->address2, 'ShipmentCity'=>$shipping_address->city, 'ShipmentState'=>$shipping_address->state, 'ShipmentZipCode'=>$shipping_address->postal_code, 'ShipmentCountry'=>$shipping_address->country, 'ShipmentPhone'=>$shipping_address->phone_number, 'ShipmentEmail'=>$shipping_address->email, 'ShipmentShippedDate'=>date('n/j/Y'), 'ShipmentTrackingCode'=>$platform_order_shipment->tracking_info);

												$response=$this->ThreeDCartApi->CreateOrderShipment($this->mobj->decryptString($destination_platform_account->access_token), $destination_platform_order->api_order_id, json_encode($ShipmentData));
												$results=json_decode($response, true);
												if(isset($results[0]['Message']))
												{
													if($results[0]['Message'] == "Created successfully")
													{
														$ItemShipmentID = 0;
														foreach($results as $result)
														{
															$ItemShipmentID = $result['Value'];
														}

														if($ItemShipmentID> 0)
														{
															$ShipmentItems = [];
															foreach($platform_order_lines as $platform_order_line)
															{
																$ShipmentItems[] = array('ItemIndexID'=>$platform_order_line->api_order_line_id, 'ItemQuantity'=>$platform_order_line->qty, 'ItemID'=>$platform_order_line->sku, 'CatalogID'=>$platform_order_line->api_product_id, 'ItemShipmentID'=>$ItemShipmentID, 'ItemUnitPrice'=>$platform_order_line->unit_price);
															}

															$response1=$this->ThreeDCartApi->UpdateOrderLineItems($this->mobj->decryptString($destination_platform_account->access_token), $destination_platform_order->api_order_id, json_encode($ShipmentItems));

															$result1=json_decode($response1, true);

															if(isset($result1[0]['Message']))
															{
																if($result1[0]['Message'] == "Updated successfully")
																{
																	$this->mobj->makeUpdate('platform_order_shipments', ['sync_status'=>'Synced'], ['id'=>$platform_order_shipment->id]);

																	$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'success', $source_platform_order->id, 'Shipment synced successfully!');

																	if($source_platform_order->shipment_status == 'Ready')
																	{
																		$this->mobj->makeUpdate('platform_order', ['shipment_status'=>'Synced'], ['id'=>$source_platform_order->id]);
																		$curl_put_data1 = array('OrderStatusID'=>4);
																	}
																	elseif($source_platform_order->shipment_status == 'Synced')
																	{
																		$curl_put_data1 = array('OrderStatusID'=>4);
																	}
																	elseif($source_platform_order->shipment_status != 'Synced')
																	{
																		$this->mobj->makeUpdate('platform_order', ['shipment_status'=>'Partial'], ['id'=>$source_platform_order->id]);
																		$curl_put_data1 = array('OrderStatusID'=>3);
																	}

																	$request_data_json1=json_encode($curl_put_data1);

																	$this->ThreeDCartApi->UpdateOrderByOrderID($this->mobj->decryptString($destination_platform_account->access_token), $destination_platform_order->api_order_id, $request_data_json1);

																	$return_data = true;
																}
																else
																{
																	$return_data = $result1[0]['Message'];

																	$this->mobj->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);

																	$this->mobj->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$source_platform_order->id]);

																	$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_order->id, $result1[0]['Message']);
																}
															}
															else
															{
																$return_data = $response1;

																$this->mobj->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);

																$this->mobj->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$source_platform_order->id]);

																$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_order->id, $response1);
															}
														}
													}
													else
													{
														$return_data = $results[0]['Message'];
	
														$this->mobj->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
	
														$this->mobj->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$source_platform_order->id]);
	
														$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_order->id, $results[0]['Message']);
													}
												}
												else
												{
													$return_data = $response;
	
													$this->mobj->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
	
													$this->mobj->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$source_platform_order->id]);
	
													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_order->id, $response);
												}
											}
										}
										else
										{
											$return_data = "Shipment product not available in 3Dcart.";

											$this->mobj->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);

											$this->mobj->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$source_platform_order->id]);

											$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_order->id, "Shipment product not available in 3Dcart.");
										}
									}
									else
									{
										$return_data = "Shipment product not fetch in ".$source_platform_name.".";

										$this->mobj->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);

										$this->mobj->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$source_platform_order->id]);

										$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_order->id, "Shipment product not fetch in ".$source_platform_name.".");
									}
								}
							}
						}
					}
					while($allow_next_call);
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id." -> ThreeDCartApiController -> Update3dcartOrderShipment -> ".$e->getLine()." -> ".$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function Update3dcartInventory($user_id=0, $user_integration_id=0, $source_platform_name='', $platform_workflow_rule_id=0, $user_workflow_rule_id=0, $record_id=0)
		{
			$return_data = true;
			$process_limit=100;
			try
			{
				$source_platform_id=$this->conn->getPlatformIdByName($source_platform_name);
				$destination_platform_id=$this->conn->getPlatformIdByName('3dcart');
				$object_id=$this->conn->getObjectId('inventory');
				$product_identity_obj_id=$this->conn->getObjectId('product_identity');

				$destination_platform_account=$this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $destination_platform_id, ['id', 'access_token']);
				if($destination_platform_account)
				{
					do
					{
						$allow_next_call=false;
						$mapping_data=$this->mapping->getMappedField($user_integration_id, $platform_workflow_rule_id, $product_identity_obj_id, ['db_field_name']);
						if($mapping_data)
						{
							$source_platform_products=DB::table('platform_product as source_platform_product')
							//->join('platform_product as destination_platform_product', 'destination_platform_product.sku', '=', 'source_platform_product.sku')
							->join('platform_product as destination_platform_product', 'destination_platform_product.'.$mapping_data['destination_row_data'], '=', 'source_platform_product.'.$mapping_data['source_row_data'])
							->select('source_platform_product.id', 'source_platform_product.sku', 'destination_platform_product.api_product_id as threedcart_api_product_id', 'destination_platform_product.api_product_code as threedcart_api_product_code', 'source_platform_product.api_product_id as source_api_product_id')
							->where(function ($query) use($record_id, $user_integration_id, $source_platform_id, $destination_platform_id){
								if($record_id > 0)
								{
									$query->where('source_platform_product.id', $record_id)->where(['source_platform_product.user_integration_id'=>$user_integration_id, 'destination_platform_product.user_integration_id'=>$user_integration_id, 'source_platform_product.platform_id'=>$source_platform_id, 'destination_platform_product.platform_id'=>$destination_platform_id]);
								}
								else
								{
									$query->where(['source_platform_product.inventory_sync_status'=>'Ready', 'source_platform_product.user_integration_id'=>$user_integration_id, 'destination_platform_product.user_integration_id'=>$user_integration_id, 'source_platform_product.platform_id'=>$source_platform_id, 'destination_platform_product.platform_id'=>$destination_platform_id]);
								}
							})
							->where('source_platform_product.is_deleted', 0)
							->where('destination_platform_product.is_deleted', 0)
							->limit($process_limit)
							->orderBy('source_platform_product.updated_at', 'asc')
							->distinct()
							->get();

							if(count($source_platform_products) == $process_limit)
							{
								//want to loop continuously
								$allow_next_call=true;
							}

							if(count($source_platform_products) > 0)
							{
								foreach($source_platform_products as $source_platform_product)
								{
									$platform_product_inventories=$this->mobj->getResultByConditions('platform_product_inventory', ['user_integration_id'=>$user_integration_id, 'api_product_id'=>$source_platform_product->source_api_product_id], ['id', 'api_warehouse_id', 'quantity']);
									if(count($platform_product_inventories)>0)
									{
										$Stock = 0;

										$warehouseArray = $this->mapping->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "inventory_warehouse", ['api_id'], "regular", NULL, "multi", "source");
										if($warehouseArray)
										{
											foreach($platform_product_inventories as $platform_product_inventory)
											{
												if(in_array($platform_product_inventory->api_warehouse_id, $warehouseArray))
												{
													$Stock += $platform_product_inventory->quantity;
												}
											}
										}
										else
										{
											foreach($platform_product_inventories as $platform_product_inventory)
											{
												$Stock += $platform_product_inventory->quantity;
											}
										}

										if($source_platform_product->threedcart_api_product_code)
										{
											$curl_put_data = array("AdvancedOptionStock"=>$Stock);
											$request_data_json = json_encode($curl_put_data);

											$response = $this->ThreeDCartApi->UpdateProductAdvanceOptionByCode($this->mobj->decryptString($destination_platform_account->access_token), $source_platform_product->threedcart_api_product_id, $source_platform_product->threedcart_api_product_code, $request_data_json);

											$result = json_decode($response, true);
											if(isset($result[0]['Message']))
											{
												if($result[0]['Message'] == "Updated successfully")
												{
													$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Synced'], ['id'=>$source_platform_product->id]);

													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'success', $source_platform_product->id, 'Inventory synced successfully!');
												}
												else
												{
													$return_data = $result[0]['Message'];

													$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Failed'], ['id'=>$source_platform_product->id]);

													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_product->id, $result[0]['Message']);
												}
											}
											else
											{
												$return_data = json_encode($result);

												$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Failed'], ['id'=>$source_platform_product->id]);

												$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_product->id, json_encode($result));
											}
										}
										else
										{
											$curl_put_data=array("SKUInfo"=>array("Stock"=>$Stock));
											$request_data_json=json_encode($curl_put_data);

											$response=$this->ThreeDCartApi->UpdateProductByCatalogID($this->mobj->decryptString($destination_platform_account->access_token), $source_platform_product->threedcart_api_product_id, $request_data_json);

											$result=json_decode($response, true);
											if(isset($result[0]['Message']))
											{
												if($result[0]['Message'] == "Updated successfully")
												{
													$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Synced'], ['id'=>$source_platform_product->id]);

													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'success', $source_platform_product->id, 'Inventory synced successfully!');
												}
												else
												{
													$return_data = $result[0]['Message'];

													$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Failed'], ['id'=>$source_platform_product->id]);

													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_product->id, $result[0]['Message']);
												}
											}
											else
											{
												$return_data = json_encode($result);

												$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Failed'], ['id'=>$source_platform_product->id]);

												$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_product->id, json_encode($result));
											}
										}
									}
								}
							}
						}
					}
					while($allow_next_call);
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id." -> ThreeDCartApiController -> Update3dcartInventory -> ".$e->getLine()." -> ".$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function test()
		{
			//dd($this->mobj->decryptString('Y2MxZDBjMWFjM2JkYzRhZGIwOWU1NGZlOWUyZWNiNTg='));
			//$response=$this->Get3dcartShipmentMethods(Auth::user()->id, 94);
			//$response=$this->Get3dcartPaymentMethods(Auth::user()->id, 94);
			//$response=$this->Get3dcartWarehouses(Auth::user()->id, 94);
			//$response=$this->Get3dcartOrderStatus(Auth::user()->id, 94);
			//$response=$this->Get3dcartProducts(Auth::user()->id, 94);
			//$response=$this->Get3dcartOrders(Auth::user()->id, 98, true);
			//$response=$this->Update3dcartOrderShipment(Auth::user()->id, 98, 'brightpearl', 12, 0, 0);
			//$response=$this->Update3dcartInventory(Auth::user()->id, 44, 'brightpearl', 10, 0, 0);
			//echo $this->mobj->encryptString('d05f4fa28c3f4d992f02c631aa092e5e');

			$platform_account=$this->mobj->getPlatformAccountByUserIntegration(151, 8, ['id', 'access_token']);
			if($platform_account)
			{
				$response=$this->ThreeDCartApi->GetOrderByID($this->mobj->decryptString($platform_account->access_token), 85345);
				echo "<pre>";
				print_r(json_decode($response, true));
				$Orders = json_decode($response, true);
				foreach($Orders as $Order)
				{
					if($Order['CustomerID'])
					{
						echo $Order['CustomerID'];
					}
					else
					{
						echo $Order['BillingEmail'];
					}
				}
			}
		}

		/* Execute 3dcart Event Methods */
		public function Execute3dcartEvents($method='', $event='', $destination_platform_id='', $user_id='', $user_integration_id='', $is_initial_sync=0, $user_workflow_rule_id='', $source_platform_id='', $platform_workflow_rule_id='', $record_id='')
		{
			$response = true;
			if($method == 'GET' && $event == 'WAREHOUSE')
			{
				$this->Get3dcartWarehouses($user_id, $user_integration_id);
			}
			elseif($method == 'GET' && $event == 'SHIPPINGMETHOD')
			{
				$this->Get3dcartShipmentMethods($user_id, $user_integration_id);
			}
			elseif($method == 'GET' && $event == 'PAYMENTMETHOD')
			{
				$this->Get3dcartPaymentMethods($user_id, $user_integration_id);
			}
			elseif($method == 'GET' && $event == 'ORDERSTATUS')
			{
				$this->Get3dcartOrderStatus($user_id, $user_integration_id);
			}
			elseif($method == 'GET' && $event == 'PRODUCT')
			{
				$this->Get3dcartProducts($user_id, $user_integration_id);
			}
			elseif($method == 'GET' && $event == 'SALESORDER')
			{
				$this->Get3dcartOrders($user_id, $user_integration_id, $is_initial_sync);
			}
			elseif($method == 'MUTATE' && $event == 'SHIPMENT')
			{
				$response = $this->Update3dcartOrderShipment($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
			}
			elseif($method == 'MUTATE' && $event == 'INVENTORY')
			{
				$response = $this->Update3dcartInventory($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
			}

			return $response;
		}
	}