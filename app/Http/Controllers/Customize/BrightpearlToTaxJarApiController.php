<?php
	namespace App\Http\Controllers\Customize;
	
	use App\Helper\Api\BrightpearlApi;
	use App\Helper\ConnectionHelper;
	use App\Helper\FieldMappingHelper;
	use App\Helper\Logger;
	use App\Helper\MainModel;
	use App\Http\Controllers\Controller;
	use App\Models\PlatformObjectData;
	use App\Models\PlatformOrder;
	use DB;
	use Illuminate\Http\Request;

	use App\Http\Controllers\TaxJar\Api\TaxJarApi;
	use App\Http\Controllers\Brightpearl\BrightpearlServices;
	use App\Helper\Cache\CacheDecoder;
	
	class BrightpearlToTaxJarApiController extends Controller
	{
		/**
			* Create a new controller instance.
			*
			* @return void
		*/
		public $Cache, $MainModel, $BrightpearlApi, $TaxJarApi, $Logger, $FieldMappingHelper, $ConnectionHelper, $BrightpearlPlatformId, $TaxjarPlatformId;
		
		public function __construct()
		{
			$this->MainModel = new MainModel();
			$this->BrightpearlApi = new BrightpearlApi;
			$this->TaxJarApi = new TaxJarApi;
			$this->Logger = new Logger();
			$this->FieldMappingHelper = new FieldMappingHelper();
			$this->ConnectionHelper = new ConnectionHelper;
			$this->BrightpearlPlatformId = $this->ConnectionHelper->getPlatformIdByName('brightpearl');
			$this->TaxjarPlatformId = $this->ConnectionHelper->getPlatformIdByName('taxjar');
			$this->Cache = new CacheDecoder;
		}
		
        /* Receive Brightpearl Modified Order Status Webhook */
		public function ReceiveBrightpearlModifiedOrderStatusWebhook(Request $request, $user_integration_id)
		{
			$return_response = false;
			if($request->isMethod('post'))
            {
				$EventID = "GET_SALESORDERMODIFIEDORDERSTATUS";
				
				$user_workflow_rule = [];
				
				$integration = $this->FieldMappingHelper->getUserIntegrationDetailsById($user_integration_id, 'brightpearl');
				if($integration){
					$integration->user_id;
					$selectFields = ['e.event_id','ur.id','ur.platform_workflow_rule_id','ur.user_id','ur.status'];
					$user_workflow_rule = $this->FieldMappingHelper->getUserIntegWorkFlow($user_integration_id, $EventID, $selectFields, 'brightpearl');
				
					if(isset($user_workflow_rule[$EventID]))
					{
						if($user_workflow_rule[$EventID]['status'] == 1)
						{
							$user_id = $user_workflow_rule[$EventID]['user_id'];
							$user_workflow_rule_id = $user_workflow_rule[$EventID]['id'];
							$platform_workflow_rule_id = $user_workflow_rule[$EventID]['platform_workflow_rule_id'];
							
							$webhook_body = $request->getContent();
							
							//\Log::info("Integration - ".$user_integration_id.", Modified Order Status Webhook Data: ".$webhook_body);
							//$webhook_body = '{"accountCode":"apiworxtest4","resourceType":"order","id":"5906","lifecycleEvent":"modified","fullEvent":"order.modified.order-status","raisedOn":"2022-09-19T10:20:41.691Z","brightpearlVersion":"4.95.2647"}';
							
							/* Decode Json Body */
							$webhook_data = json_decode($webhook_body, 1);
							
							if((isset($webhook_data['id']) && is_numeric($webhook_data['id'])) && (isset($webhook_data['resourceType']) && $webhook_data['resourceType'] == 'order') && (isset($webhook_data['fullEvent']) && $webhook_data['fullEvent'] == 'order.modified.order-status'))
							{
								$brightpearl_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->BrightpearlPlatformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
								$taxjar_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->TaxjarPlatformId);
								if($brightpearl_account && $taxjar_account)
								{
									$response = $this->BrightpearlApi->GetOrder($brightpearl_account, null, $webhook_data['id']);
									if($brightpearl_order_response = json_decode($response->getBody(), true))
									{
										if(isset($brightpearl_order_response['response'][0]['id']))
										{
											$brightpearl_order = $brightpearl_order_response['response'][0];
											
											$closedOn = isset($brightpearl_order['closedOn']) ? $brightpearl_order['closedOn'] : NULL;

											$statusId = NULL;

											$statusCache = "sorder_status_filter_".$user_integration_id."_".$platform_workflow_rule_id;

											$statusFilter = $this->Cache->get_or_set($statusCache);
											if($statusFilter)
											{
												$statusId = $statusFilter->api_id;
											}
											else
											{
												$statusFilter = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "sorder_status_filter", ['api_id']);
												if($statusFilter)
												{
													$statusId = $statusFilter->api_id;
													$this->Cache->get_or_set($statusCache, $statusFilter, 43200);//set key and value pair, currently we have pass 12 hours as seconds
												}
											}

											$channelCache = "sorder_channel_filter_".$user_integration_id."_".$platform_workflow_rule_id;

											$cacheChannelFilter = $this->Cache->get_or_set($channelCache);
											if($cacheChannelFilter)
											{
												$channelFilters = $cacheChannelFilter;
											}
											else
											{
												$channelFilters = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, 'sorder_channel_filter', ['api_id'], 'regular', null, 'multiple');
												$this->Cache->get_or_set($channelCache, $channelFilters, 43200);//set key and value pair, currently we have pass 12 hours as seconds
											}
											
											$channelId = isset($brightpearl_order['assignment']['current']['channelId']) ? $brightpearl_order['assignment']['current']['channelId'] : 0;
											
											if($closedOn == NULL && $brightpearl_order['orderTypeCode'] == 'SO' && $brightpearl_order['orderStatus']['orderStatusId'] == $statusId && count($channelFilters) && $channelId && in_array($channelId, $channelFilters) && ($brightpearl_order['shippingStatusCode'] == 'NST' || $brightpearl_order['shippingStatusCode'] == 'SNS'))
											{
												$customer_url = '/contact/'.$brightpearl_order['parties']['customer']['contactId'];
												$response1 = $this->BrightpearlApi->GetCustomers($brightpearl_account, $customer_url);
												if($brightpearl_customer_response = json_decode($response1->getBody(), true))
												{
													if(isset($brightpearl_customer_response['response'][0]['contactId']))
													{
														$brightpearl_customer = $brightpearl_customer_response['response'][0];
														
														$cacheTaxCode = "customer_taxcode_".$user_integration_id."_".$platform_workflow_rule_id;
														$cacheTaxCodeFilter = $this->Cache->get_or_set($cacheTaxCode);
														if($cacheTaxCodeFilter)
														{
															$customerTaxCodeIds = $cacheTaxCodeFilter;
														}
														else
														{
															$customerTaxCodeIds = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, 'customer_taxcode', ['api_id'], 'regular', NULL, 'multiple');
															$this->Cache->get_or_set($cacheTaxCode, $customerTaxCodeIds, 43200);//set key and value pair, currently we have pass 12 hours as seconds
														}

														if(count($customerTaxCodeIds) && isset($brightpearl_customer['financialDetails']['taxCodeId']) && in_array($brightpearl_customer['financialDetails']['taxCodeId'], $customerTaxCodeIds))
														{
															/* Save Order details */
															$order_fields = ['user_id'=>$user_id, 'user_workflow_rule_id'=>$user_workflow_rule_id, 'platform_id'=>$this->BrightpearlPlatformId, 'user_integration_id'=>$user_integration_id, 'order_type'=>$brightpearl_order['orderTypeCode'], 'api_order_id'=>$brightpearl_order['id'], 'customer_email'=>@$brightpearl_order['parties']['customer']['email'], 'order_number'=>$brightpearl_order['id'], 'api_order_reference'=>@$brightpearl_order['reference'], 'currency'=>@$brightpearl_order['currency']['orderCurrencyCode'], 'order_date'=>@$brightpearl_order['createdOn'], 'tax_date'=>isset($brightpearl_order['invoices'][0]['taxDate']) ? $brightpearl_order['invoices'][0]['taxDate'] : '', 'delivery_date'=>@$brightpearl_order['delivery']['deliveryDate'], 'order_status'=>@$brightpearl_order['orderStatus']['name'], 'api_pricelist_id'=>@$brightpearl_order['priceListId'], 'total_tax'=>(empty($brightpearl_order['totalValue']['taxAmount']) ? 0 : $brightpearl_order['totalValue']['taxAmount']), 'total_amount'=>(empty($brightpearl_order['totalValue']['total']) ? 0 : $brightpearl_order['totalValue']['total']), 'net_amount'=>(empty($brightpearl_order['totalValue']['net']) ? 0 : $brightpearl_order['totalValue']['net']), 'shipping_method'=>@$brightpearl_order['delivery']['shippingMethodId'], 'warehouse_id'=>@$brightpearl_order['warehouseId'], 'api_updated_at'=>@$brightpearl_order['updatedOn'], 'api_order_payment_status'=>$this->MainModel->mapOrderPaymentStatus(@$brightpearl_order['orderPaymentStatus']), 'order_updated_at'=>date_create(), 'sync_status'=>'Ready'];

															$bp_platform_order = PlatformOrder::where(['api_order_id'=>$brightpearl_order['id'], 'order_type'=>$brightpearl_order['orderTypeCode'], 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->BrightpearlPlatformId])->first();
															if(is_null($bp_platform_order))
															{
																$platform_order_id = $this->MainModel->makeInsertGetId('platform_order', $order_fields);
															}
															else
															{
																$platform_order_id = $bp_platform_order->id;
																$this->MainModel->makeUpdate('platform_order', $order_fields, ['id'=>$bp_platform_order->id]);
															}

															$platform_order = PlatformOrder::where(['id'=>$platform_order_id])->first();

															$object_id = $this->ConnectionHelper->getObjectId('sales_order');
															$warehouse_object_id = $this->ConnectionHelper->getObjectId("warehouse");
															
															$error_message = '';
															//default attempt = 0
															if($platform_order->attempt < 5)
															{
																$line_items = [];
																$shippingAmount = 0;
																$netAmount = 0;
																
																$cacheWarehouse = "warehouse_".$user_integration_id."_".$this->BrightpearlPlatformId."_".$brightpearl_order['warehouseId'];
																$cacheWarehouseData = $this->Cache->get_or_set($cacheWarehouse);
																if($cacheWarehouseData)
																{
																	$warehouse_object_data = json_decode($cacheWarehouseData);
																}
																else
																{
																	$warehouse_object_data = PlatformObjectData::join('platform_object_data_additional_information', 'platform_object_data.id', '=', 'platform_object_data_additional_information.platform_object_data_id')
																		->select('platform_object_data_additional_information.postal_code', 'platform_object_data_additional_information.state', 'platform_object_data_additional_information.country')
																		->where(['platform_object_data.user_integration_id'=>$user_integration_id, 'platform_object_data.platform_id'=>$this->BrightpearlPlatformId, 'platform_object_data.platform_object_id'=>$warehouse_object_id, 'platform_object_data.api_id'=>$brightpearl_order['warehouseId'], 'platform_object_data.user_id'=>$user_id])
																		->whereNotNull('platform_object_data_additional_information.postal_code')
																		->whereNotNull('platform_object_data_additional_information.state')
																		->whereNotNull('platform_object_data_additional_information.country')
																		->first();

																	$this->Cache->get_or_set($cacheWarehouse, json_encode($warehouse_object_data), 43200);//set key and value pair, currently we have pass 12 hours as seconds
																}

																if($warehouse_object_data)
																{
																	$from_zip = $warehouse_object_data->postal_code;
																	$from_country = $warehouse_object_data->country;
																	
																	$from_state = $warehouse_object_data->state;
																	
																	$cacheState = "state_".$from_country."_".$from_state;

																	$cacheStateData = $this->Cache->get_or_set($cacheState);
																	if($cacheStateData)
																	{
																		$cacheStateData = json_decode($cacheStateData);
																		$from_state = $cacheStateData->iso2;
																	}
																	else
																	{
																		$es_from_state = $this->MainModel->getFirstResultByConditions('es_states', ['country_code'=>$from_country, 'name'=>$from_state], ['iso2']);
																		if($es_from_state)
																		{
																			$from_state = $es_from_state->iso2;
																			$this->Cache->get_or_set($cacheState, json_encode($es_from_state), 43200);//set key and value pair, currently we have pass 12 hours as seconds
																		}
																	}

																	if(!empty($brightpearl_order['orderRows']))
																	{
																		$cacheAdditionalInformation = "additional_information_".$user_integration_id."_".$brightpearl_account->id;
																		$cacheAdditionalInformationData = $this->Cache->get_or_set($cacheAdditionalInformation);
																		if($cacheAdditionalInformationData)
																		{
																			$additionalAccountInfo = json_decode($cacheAdditionalInformationData);
																		}
																		else
																		{
																			$additionalAccountInfo = $this->MainModel->getFirstResultByConditions('platform_account_addtional_information', ['user_integration_id'=>$user_integration_id, 'account_id'=>$brightpearl_account->id]);

																			$this->Cache->get_or_set($cacheAdditionalInformation, json_encode($additionalAccountInfo), 43200);//set key and value pair, currently we have pass 12 hours as seconds
																		}
																		
																		//['id'=>'1', 'quantity'=>1, 'product_tax_code'=>'20010', 'unit_price'=>15, 'discount'=>0]
																		foreach($brightpearl_order['orderRows'] as $line_id=>$line)
																		{
																			$row_type = isset($line['nominalCode']) ? BrightpearlServices::getBPLineItemType($additionalAccountInfo, $line['nominalCode']) : 'ITEM';
																			
																			$quantity = isset($line['quantity']['magnitude']) ? $line['quantity']['magnitude'] : 0;
																			
																			if($quantity)
																			{
																				if($row_type == 'ITEM' || $row_type == 'SHIPPING')
																				{
																					$line_items[] = array("id"=>$line_id, "quantity"=>$quantity, "unit_price"=>((isset($line['rowValue']['rowNet']['value']) ? $line['rowValue']['rowNet']['value'] : 0) /$quantity), 'discount'=>0);
																					
																					$netAmount = $netAmount + (isset($line['rowValue']['rowNet']['value']) ? $line['rowValue']['rowNet']['value'] : 0);
																				}
																			}
																		}
																		
																		if(count($line_items))
																		{
																			$to_state = @$brightpearl_order['parties']['delivery']['addressLine4'];

																			$cacheState = "state_".@$brightpearl_order['parties']['delivery']['countryIsoCode']."_".$to_state;
																			$cacheStateData = $this->Cache->get_or_set($cacheState);
																			if($cacheStateData)
																			{
																				$cacheStateData = json_decode($cacheStateData);
																				$to_state = $cacheStateData->iso2;
																			}
																			else
																			{
																				$es_to_state = $this->MainModel->getFirstResultByConditions('es_states', ['country_code'=>@$brightpearl_order['parties']['delivery']['countryIsoCode'], 'name'=>$to_state], ['iso2']);
																				if($es_to_state)
																				{
																					$to_state = $es_to_state->iso2;
																					$this->Cache->get_or_set($cacheState, json_encode($es_to_state), 43200);//set key and value pair, currently we have pass 12 hours as seconds
																				}
																			}

																			$taxForOrder = ['from_country'=>$from_country, 'from_zip'=>$from_zip, 'from_state'=>$from_state, 'to_country'=>@$brightpearl_order['parties']['delivery']['countryIsoCode'], 'to_zip'=>@$brightpearl_order['parties']['delivery']['postalCode'], 'to_state'=>$to_state, 'amount'=>($netAmount + $shippingAmount), 'shipping'=>$shippingAmount, 'line_items'=>$line_items];
																			
																			$taxjar_result = $this->TaxJarApi->postOrderTaxCalculation($taxjar_account, $taxForOrder);
																			if(isset($taxjar_result['tax']['breakdown']['line_items'][0]['id'])) 
																			{
																				foreach($taxjar_result['tax']['breakdown']['line_items'] as $line_item)
																				{
																					$rowData = array(array("op"=>"replace", "path"=>"/rowValue/rowTax/value", "value"=>$this->ConnectionHelper->getNumberFormat(@$line_item['tax_collectable'], 4)), array("op"=>"replace", "path"=>"/rowValue/taxCalculator", "value"=>"manual"));

																					$response = $this->BrightpearlApi->UpdateOrderRowTax($brightpearl_account, $brightpearl_order['id'], $line_item['id'], $rowData);
																					$result = json_decode($response->getBody(), true);
																					if(isset($result['response']['productId']))
																					{
																						$return_response = true;
																					}
																					else
																					{
																						$error_message = $this->BrightpearlApi->handleResponseError($result);
																					}
																				}

																				if($error_message == '')
																				{
																					$this->MainModel->makeUpdate('platform_order', ['sync_status'=>'Synced', 'attempt'=>$platform_order->attempt + 1], ['id'=>$platform_order->id]);

																					$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $this->BrightpearlPlatformId, $this->TaxjarPlatformId, $object_id, 'success', $platform_order->id, NULL);

																					$return_response = true;
																				}
																			}
																			elseif(isset($taxjar_result['detail']))
																			{
																				$error_message = $taxjar_result['detail'];
																			}
																			else
																			{
																				$error_message = 'Tax calculation failed due to bad shipping address.';
																			}
																		}
																		else
																		{
																			$error_message = 'Tax calculation failed due to missing items.';
																		}
																	}
																	else
																	{
																		$error_message = 'Tax calculation failed due to missing items.';
																	}
																}
																else
																{
																	$error_message = 'Tax calculation failed due to from address missing.';
																}
															}
															else
															{
																$error_message = 'More than 5 attempts tax calculation is failed.';
															}

															if($error_message)
															{
																$this->MainModel->makeUpdate('platform_order', ['sync_status'=>'Failed'], ['id'=>$platform_order->id]);

																$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $this->BrightpearlPlatformId, $this->TaxjarPlatformId, $object_id, 'failed', $platform_order->id, $error_message);

																$return_response = $error_message;
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
			    }
			}
			return $return_response;
		}
	}