<?php
	namespace App\Http\Controllers\TaxJar;
	
	use Illuminate\Http\Request;
	use Illuminate\Support\Facades\Validator;
	use App\Helper\ConnectionHelper;
	use App\Helper\FieldMappingHelper;
	use App\Helper\Logger;
	use App\Helper\MainModel;
	use App\Models\PlatformAccount;
	use App\Models\PlatformOrder;
	use App\Http\Controllers\TaxJar\Api\TaxJarApi;
	
	use Auth, Lang;
	class TaxJarController extends TaxJarApi
	{
		/**
			* Default name of the controller platform name
		*/
		private const PLATFORMNAME = 'taxjar';
		public $connectionHelper, $mainModel, $logger, $platformId, $fieldMapHelper;
		public function __construct()
		{
			$this->connectionHelper = new ConnectionHelper();
			$this->mainModel = new MainModel();
			$this->logger = new Logger();
			$this->fieldMapHelper = new FieldMappingHelper();
			
			//Set the platform ID
			$this->platformId = $this->connectionHelper->getPlatformIdByName(self::PLATFORMNAME);
		}
		
		/**
			* Auth function return the view page of authentication
			*
			* @param $request Request class
		*/
		public function InitiateTaxJarAuth(Request $request)
		{
			$platform = self::PLATFORMNAME;
			return view("pages.apiauth.auth_taxjar", compact('platform'));
		}
		
		/**
			* Auth function to connect to the platform with response to the front
			*
			* @param $request Request class
			*
			* @return json_encoded data to be return with 2 parameters as `status_code` and `status_text`
		*/
		public function ConnectTaxJar(Request $request)
		{
			$response = ['status_code'=>0]; // array for return response with status_code default to 0 (false)
			
			if($this->mainModel->checkHtmlTags($request->all()))
			{
				$response['status_text'] = Lang::get('tags.validate');
				return $response;
			}
			
			try {
				$validator = Validator::make($request->all(), ['access_token'=>'required', 'env_type'=>'required'], ['access_token.required'=>'API token is required.', 'env_type.required'=>'Env Type is required.']);
				
				if($validator->fails())
				{
					$statusText = array_values(json_decode($validator->messages()->toJson(), true))[0][0];
				}
				else
				{
					$validated = array_map(function ($val) { return htmlspecialchars($val); }, $validator->validated());
					$validated = (object) $validated;

					$api_domain = self::setURL($validated->env_type, '');
					$domain = parse_url($api_domain, PHP_URL_HOST);
					//Set and Decrypt the values for security measures
					$account_name = "TaxJar_".$domain."_".date('Y-m-d');
					
					//Check for the account
					$account = PlatformAccount::select('id')->where(['user_id'=>Auth::user()->id, 'platform_id'=>$this->platformId, 'app_id'=>$validated->access_token])->count();
					if($account === 0)
					{
						$isConnected = self::checkAuthCredential($validated->env_type, $validated->access_token);
						if($isConnected['status'] === true)
						{
							//Add the given data
							$newAccount = PlatformAccount::create(['user_id'=>Auth::user()->id, 'platform_id'=>$this->platformId, 'account_name'=>$account_name, 'api_domain'=>$api_domain, 'app_id'=>$validated->access_token, 'access_token'=>$this->mainModel->encrypt_decrypt($validated->access_token), 'env_type'=>$validated->env_type]);
							if($newAccount->id)
							{
								$response['status_code'] = true;
								$statusText = 'Account Connected.';
							}
							else
							{
								$statusText = 'Account not created! Please try again.';
							}
						}
						elseif($isConnected['status'] === false)
						{
							$statusText = 'Please check for the given credential.';
						}
					} 
					else 
					{
						$statusText = "Account already connected.";
					}
				}

				$response['status_text'] = $statusText;
			} 
			catch(\Exception $e)
			{
				$response['status_text'] = $e->getMessage();
			}

			return $response;
		}

		/* Sync Sales Order Invoice */
		public function SyncSalesOrderTransaction($user_id=NULL, $user_integration_id=NULL, $user_workflow_rule_id=NULL, $source_platform_id=NULL, $record_id=NULL)
		{
			$return_response = true;
			try
			{
				$limit = 25;

				$object_id = $this->connectionHelper->getObjectId('sales_order');
				$SourcePlatformId = $this->connectionHelper->getPlatformIdByName($source_platform_id); 

				$platform_account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
				if($platform_account)
				{
					$source_row_data = $destination_row_data = 'sku';
					$product_identity_obj_id = $this->connectionHelper->getObjectId('product_identity');
					$mapping_data = $this->fieldMapHelper->getMappedField($user_integration_id, NULL, $product_identity_obj_id);
					if($mapping_data)
					{
						if($mapping_data['destination_platform_id'] == 'taxjar')
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

					$query = PlatformOrder::select('id', 'order_number', 'order_date', 'total_discount', 'shipping_total', 'total_tax', 'linked_id')
					->where(['user_integration_id'=>$user_integration_id, 'platform_id'=>$SourcePlatformId]);

					if($record_id)
					{
						$query->where('id', $record_id);
					} 
					else 
					{
						$query->where('invoice_sync_status', 'Ready');
					}

					$platform_orders = $query->where('order_type', 'SO')->take($limit)->orderBy('updated_at', 'asc')->get();
					
					foreach($platform_orders as $platform_order)
					{
						if($platform_order->linked_id)
						{
							$this->mainModel->makeUpdate('platform_order', ['invoice_sync_status'=>'Synced'], ['id'=>$platform_order->id]);
						}
						else
						{
							if($platform_order->total_tax)
							{
								$shipping_address = $this->mainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id'=>$platform_order->id, 'address_type'=>'shipping'], ['address1', 'address2', 'city', 'state', 'postal_code', 'country']);
								if($shipping_address)
								{
									$to_state = $shipping_address->state;
									$es_to_state = $this->mainModel->getFirstResultByConditions('es_states', ['country_code'=>$shipping_address->country, 'name'=>$to_state], ['iso2']);
									if($es_to_state)
									{
										$to_state = $es_to_state->iso2;
									}

									$line_items = [];
									$net_amount = 0;
									$shipping = 0;
									$sales_tax = 0;
									$platform_order_lines = $this->mainModel->getResultByConditions('platform_order_line', ['platform_order_id'=>$platform_order->id, 'is_deleted'=>0], ['id', 'product_name', 'ean', 'sku', 'gtin', 'upc', 'mpn', 'barcode', 'qty', 'subtotal', 'subtotal_tax', 'row_type'], ['id'=>'asc', 'row_type'=>'asc']);
									foreach($platform_order_lines as $platform_order_line)
									{
										if($platform_order_line->row_type == 'ITEM' && $platform_order_line->qty)
										{
											$line_items[] = array("quantity"=>$platform_order_line->qty, "product_identifier"=>$platform_order_line->{$source_row_data}, "description"=>$platform_order_line->product_name, "unit_price"=>round($platform_order_line->subtotal/$platform_order_line->qty, 2), "sales_tax"=>round($platform_order_line->subtotal_tax/$platform_order_line->qty, 2));

											$net_amount = $net_amount + $platform_order_line->subtotal;

											$sales_tax = $sales_tax + $platform_order_line->subtotal_tax;
										}
										elseif($platform_order_line->row_type == 'SHIPPING' && $platform_order_line->qty)
										{
											$shipping = $shipping + round($platform_order_line->subtotal + $platform_order_line->subtotal_tax, 2);
										}
										elseif($platform_order_line->row_type == 'DISCOUNT' && $platform_order_line->qty && $platform_order_line->subtotal)
										{
											$line_items[] = array("quantity"=>$platform_order_line->qty, "product_identifier"=>'DISCOUNT', "description"=>$platform_order_line->product_name, "unit_price"=>round($platform_order_line->subtotal/$platform_order_line->qty, 2) * (-1), "sales_tax"=>0);

											$net_amount = $net_amount + ($platform_order_line->subtotal * (-1));
										}
									}

									if($platform_order->shipping_total)
									{
										$shipping = $platform_order->shipping_total;
									}

									if($platform_order->total_discount)
									{
										$line_items[] = array("quantity"=>1, "product_identifier"=>'DISCOUNT', "description"=>'Order Discount', "unit_price"=>$platform_order->total_discount * (-1), "sales_tax"=>0);

										$net_amount = $net_amount + ($platform_order->total_discount * (-1));
									}

									$postDATA = array("transaction_id"=>$platform_order->order_number, "transaction_date"=>date('Y/m/d', strtotime($platform_order->order_date)), "to_country"=>$shipping_address->country, "to_zip"=>$shipping_address->postal_code, "to_state"=>$to_state, "to_city"=>$shipping_address->city, "to_street"=>trim($shipping_address->address1." ".$shipping_address->address2), "amount"=>($net_amount + $shipping), "shipping"=>$shipping, "sales_tax"=>$sales_tax, "line_items"=>$line_items);

									$result = self::postSalesOrderTransaction($platform_account, $postDATA);
									if(isset($result['order']['transaction_id'])) 
									{
										$OrderLinked = $this->mainModel->makeInsertGetId('platform_order', ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'order_type'=>"IO", 'api_order_id'=>$result['order']['transaction_id'], 'order_date'=>date("Y-m-d H:i:s"), 'order_number'=>$platform_order->order_number, 'linked_id'=>$platform_order->id, 'created_at'=>date("Y-m-d H:i:s"), 'updated_at'=>date("Y-m-d H:i:s"), 'order_updated_at'=>date("Y-m-d H:i:s")]);

										$this->mainModel->makeUpdate('platform_order', ['linked_id'=>$OrderLinked, 'invoice_sync_status'=>'Synced'], ['id'=>$platform_order->id]);

										$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $object_id, 'success', $platform_order->id, NULL);

										$return_response = true;
									}
									elseif(isset($result['detail']))
									{
										$error = $result['detail'];
										$this->mainModel->makeUpdate('platform_order', ['invoice_sync_status'=>'Failed'], ['id'=>$platform_order->id]);
										$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $object_id, 'failed', $platform_order->id, $result['detail']);

										$return_response = $error;
									}
									else
									{
										$error = 'Unknown error.';
										$this->mainModel->makeUpdate('platform_order', ['invoice_sync_status'=>'Failed'], ['id'=>$platform_order->id]);
										$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $object_id, 'failed', $platform_order->id, 'Unknown error.');

										$return_response = $error;
									}
								}
								else
								{
									$error = 'Order shipping address not available.';
									$this->mainModel->makeUpdate('platform_order', ['invoice_sync_status'=>'Failed'], ['id'=>$platform_order->id]);
									$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $object_id, 'failed', $platform_order->id, 'Order shipping address not available.');
									$return_response = $error;
								}
							}
							else
							{
								$error = 'Non taxable order.';
								$this->mainModel->makeUpdate('platform_order', ['invoice_sync_status'=>'Ignore'], ['id'=>$platform_order->id]);
								$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $object_id, 'failed', $platform_order->id, 'Non taxable order.');
								$return_response = $error;
							}
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id." -> TaxJarController -> SyncSalesOrderTransaction -> ".$e->getLine()." -> ".$e->getMessage());
				$return_response = $e->getMessage();
			}

			return $return_response;
		}

		/* Sync Sales Credit Invoice */
		public function SyncSalesCreditTransaction($user_id=NULL, $user_integration_id=NULL, $user_workflow_rule_id=NULL, $source_platform_id=NULL, $record_id=NULL)
		{
			$return_response = true;
			try
			{
				$limit = 25;

				$object_id = $this->connectionHelper->getObjectId('refund_order');
				$SourcePlatformId = $this->connectionHelper->getPlatformIdByName($source_platform_id); 

				$platform_account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
				if($platform_account)
				{
					$source_row_data = $destination_row_data = 'sku';
					$product_identity_obj_id = $this->connectionHelper->getObjectId('product_identity');
					$mapping_data = $this->fieldMapHelper->getMappedField($user_integration_id, NULL, $product_identity_obj_id);
					if($mapping_data)
					{
						if($mapping_data['destination_platform_id'] == 'taxjar')
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

					$query = PlatformOrder::select('id', 'api_order_reference', 'order_number', 'order_date', 'total_discount', 'shipping_total', 'total_tax', 'linked_id')
					->where(['user_integration_id'=>$user_integration_id, 'platform_id'=>$SourcePlatformId]);

					if($record_id)
					{
						$query->where('id', $record_id);
					} 
					else 
					{
						$query->where('invoice_sync_status', 'Ready');
					}

					$platform_orders = $query->where('order_type', 'SC')->take($limit)->orderBy('updated_at', 'asc')->get();
					
					foreach($platform_orders as $platform_order)
					{
						if($platform_order->linked_id)
						{
							$this->mainModel->makeUpdate('platform_order', ['invoice_sync_status'=>'Synced'], ['id'=>$platform_order->id]);
						}
						else
						{
							if($platform_order->total_tax)
							{
								$shipping_address = $this->mainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id'=>$platform_order->id, 'address_type'=>'shipping'], ['address1', 'address2', 'city', 'state', 'postal_code', 'country']);
								if($shipping_address)
								{
									$to_state = $shipping_address->state;
									$es_to_state = $this->mainModel->getFirstResultByConditions('es_states', ['country_code'=>$shipping_address->country, 'name'=>$to_state], ['iso2']);
									if($es_to_state)
									{
										$to_state = $es_to_state->iso2;
									}

									$line_items = [];
									$net_amount = 0;
									$shipping = 0;
									$sales_tax = 0;
									$platform_order_lines = $this->mainModel->getResultByConditions('platform_order_line', ['platform_order_id'=>$platform_order->id, 'is_deleted'=>0], ['id', 'product_name', 'ean', 'sku', 'gtin', 'upc', 'mpn', 'barcode', 'qty', 'subtotal', 'subtotal_tax', 'row_type'], ['id'=>'asc', 'row_type'=>'asc']);
									foreach($platform_order_lines as $platform_order_line)
									{
										if($platform_order_line->row_type == 'ITEM' && $platform_order_line->qty)
										{
											$line_items[] = array("quantity"=>$platform_order_line->qty, "product_identifier"=>$platform_order_line->{$source_row_data}, "description"=>$platform_order_line->product_name, "unit_price"=>round($platform_order_line->subtotal/$platform_order_line->qty, 2) * (-1), "sales_tax"=>round($platform_order_line->subtotal_tax/$platform_order_line->qty, 2) * (-1));

											$net_amount = $net_amount + $platform_order_line->subtotal;

											$sales_tax = $sales_tax + $platform_order_line->subtotal_tax;
										}
										elseif($platform_order_line->row_type == 'SHIPPING' && $platform_order_line->qty)
										{
											$shipping = $shipping + round($platform_order_line->subtotal + $platform_order_line->subtotal_tax, 2);
										}
										elseif($platform_order_line->row_type == 'DISCOUNT' && $platform_order_line->qty && $platform_order_line->subtotal)
										{
											$line_items[] = array("quantity"=>$platform_order_line->qty, "product_identifier"=>'DISCOUNT', "description"=>$platform_order_line->product_name, "unit_price"=>round($platform_order_line->subtotal/$platform_order_line->qty, 2) * (-1), "sales_tax"=>0);

											$net_amount = $net_amount + $platform_order_line->subtotal;
										}
									}

									if($platform_order->shipping_total)
									{
										$shipping = $platform_order->shipping_total;
									}

									if($platform_order->total_discount)
									{
										$line_items[] = array("quantity"=>1, "product_identifier"=>'DISCOUNT', "description"=>'Order Discount', "unit_price"=>$platform_order->total_discount * (-1), "sales_tax"=>0);

										$net_amount = $net_amount + $platform_order->total_discount;
									}

									$postDATA = array("transaction_id"=>$platform_order->order_number, "transaction_reference_id"=>$platform_order->api_order_reference, "transaction_date"=>date('Y/m/d', strtotime($platform_order->order_date)), "to_country"=>$shipping_address->country, "to_zip"=>$shipping_address->postal_code, "to_state"=>$to_state, "to_city"=>$shipping_address->city, "to_street"=>trim($shipping_address->address1." ".$shipping_address->address2), "amount"=>($net_amount + $shipping) * (-1), "shipping"=>$shipping * (-1), "sales_tax"=>$sales_tax * (-1), "line_items"=>$line_items);

									$result = self::postRefundOrderTransaction($platform_account, $postDATA);
									if(isset($result['refund']['transaction_id'])) 
									{
										$OrderLinked = $this->mainModel->makeInsertGetId('platform_order', ['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'order_type'=>"IO", 'api_order_id'=>$result['refund']['transaction_id'], 'order_date'=>date("Y-m-d H:i:s"), 'order_number'=>$platform_order->order_number, 'linked_id'=>$platform_order->id, 'created_at'=>date("Y-m-d H:i:s"), 'updated_at'=>date("Y-m-d H:i:s"), 'order_updated_at'=>date("Y-m-d H:i:s")]);

										$this->mainModel->makeUpdate('platform_order', ['linked_id'=>$OrderLinked, 'invoice_sync_status'=>'Synced'], ['id'=>$platform_order->id]);

										$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $object_id, 'success', $platform_order->id, NULL);

										$return_response = true;
									}
									elseif(isset($result['detail']))
									{
										$error = $result['detail'];
										$this->mainModel->makeUpdate('platform_order', ['invoice_sync_status'=>'Failed'], ['id'=>$platform_order->id]);
										$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $object_id, 'failed', $platform_order->id, $result['detail']);

										$return_response = $error;
									}
									else
									{
										$error = 'Unknown error.';
										$this->mainModel->makeUpdate('platform_order', ['invoice_sync_status'=>'Failed'], ['id'=>$platform_order->id]);
										$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $object_id, 'failed', $platform_order->id, 'Unknown error.');

										$return_response = $error;
									}
								}
								else
								{
									$error = 'Order shipping address not available.';
									$this->mainModel->makeUpdate('platform_order', ['invoice_sync_status'=>'Failed'], ['id'=>$platform_order->id]);
									$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $object_id, 'failed', $platform_order->id, 'Order shipping address not available.');
									$return_response = $error;
								}
							}
							else
							{
								$error = 'Non taxable order.';
								$this->mainModel->makeUpdate('platform_order', ['invoice_sync_status'=>'Ignore'], ['id'=>$platform_order->id]);
								$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $SourcePlatformId, $this->platformId, $object_id, 'failed', $platform_order->id, 'Non taxable order.');
								$return_response = $error;
							}
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id." -> TaxJarController -> SyncSalesCreditTransaction -> ".$e->getLine()." -> ".$e->getMessage());
				$return_response = $e->getMessage();
			}

			return $return_response;
		}
		
		/**
			* Syncing of the Order from TaxJar to Brightpearl
			* Syncing of the Product Price from Brightpearl to TaxJar
			* Syncing of the Product Inventory from Brightpearl to TaxJar
			*
			* @param $method, for 'MUTATE' it's for creation of new data and for 'GET' to get any data from the platform
			* @param $event, the event for the function is initiated
			* @param $is_initial_sync, at first it's 1 and then it's always 0
			* @param $user_id, the user's id with the current integration
			* @param $user_integration_id, the user_integration id
			* @param $source_platform_name, the source platform name eg. brightpearl
			* @param $platform_workflow_rule_id, the platform_workflow_rule id
			* @param $user_workflow_rule_id, the user_workflow_rule id
			* @param $record_id, for resync the failed data
			*
			* @return json_encoded data to be return with 2 parameters as `status_code` and `status_text`
		*/
		public function ExecuteTaxJarEvents($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
		{
			$response = true;
			
			try{
				if($method == 'MUTATE' && $event == 'SALESORDERTRANSACTION')
				{
					$response = $this->SyncSalesOrderTransaction($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id);
				}
				elseif($method == 'MUTATE' && $event == 'SALESCREDITTRANSACTION')
				{
					$response = $this->SyncSalesCreditTransaction($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $record_id);
				}

				return $response;
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - TaxJarController - ExecuteTaxJarEvents - '.$e->getLine().' - '.$e->getMessage());
				return $e->getMessage();
			}
		}
	}