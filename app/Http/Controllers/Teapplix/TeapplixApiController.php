<?php
	namespace App\Http\Controllers\Teapplix;

	use App\Http\Controllers\Controller;
	use Illuminate\Http\Request;
	use App\Helper\MainModel;
	use App\Helper\ConnectionHelper;
	use App\Helper\FieldMappingHelper;
	use App\Helper\WorkflowSnippet;
	use App\Helper\Logger;
	use App\Helper\Api\TeapplixApi;
	use App\Models\PlatformAccount;

	use Auth, DB;
	use Lang;
	class TeapplixApiController extends Controller
	{
		public static $myPlatform='teapplix';

		/**
			* Create a new controller instance.
			*
			* @return void
		*/
		public function __construct()
		{
			$this->mobj=new MainModel();
			$this->TeapplixApi=new TeapplixApi();
			$this->conn=new ConnectionHelper();
			$this->mapping=new FieldMappingHelper();
			$this->log=new Logger();
			$this->WorkflowSnippet=new WorkflowSnippet();
			$this->platformId = $this->conn->getPlatformIdByName(self::$myPlatform);
		}

		public function InitiateTeapplixAuth(Request $request)
		{
			$platform='teapplix';
			return view("pages.apiauth.auth_teapplix", compact('platform'));
		}

		/* Save Credentials */
		public function ConnectTeapplixAuth(Request $request)
		{
			$request->validate(['teapplix_account_name'=>'required', 'teapplix_api_token'=>'required']);

			$teapplix_account_name = trim($request->teapplix_account_name);
			$teapplix_api_token = trim($request->teapplix_api_token);

			$data = [];

			if($this->mobj->checkHtmlTags( $request->all() ) ){
				$data['status_code'] = 0;
				$data['status_text'] = Lang::get('tags.validate');
				return json_encode($data);
			}
			
			try {
				$flag = true;
				// to check whether given account is already in use or not.
				$checkExistingAc = PlatformAccount::select('id')->where('platform_id', $this->platformId)->where('account_name', $teapplix_account_name)->where('access_token', $this->mobj->encryptString($teapplix_api_token))->first();
				if ($checkExistingAc)
				{
					$flag = false;
					$data['status_code'] = 0;
					$data['status_text'] = 'This account detail already exist, Try with another account.';
				}
				else
				{
					$response = $this->TeapplixApi->GetInventoryList($teapplix_api_token);
					$result=json_decode($response, true);
					if(isset($result['ProductQuantities']))
					{
						$arr_field = ['account_name'=>$teapplix_account_name, 'user_id'=>Auth::user()->id, 'platform_id'=>$this->platformId, 'access_token'=>$this->mobj->encryptString($teapplix_api_token), 'allow_refresh'=>0];

						$this->mobj->makeInsertGetId('platform_accounts', $arr_field);
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

		public function GetWarehouses($user_id=0, $user_integration_id=0)
		{
			$return_data = true;
			try
			{
				$warehouse_object=$this->mobj->getFirstResultByConditions('platform_objects', ['name'=>"warehouse"], ['id']);
				if($warehouse_object)
				{
					$this->mobj->makeUpdate('platform_object_data', ['status'=>0], ['user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'platform_object_id'=>$warehouse_object->id, 'status'=>1]);

					$platform_account=$this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'env_type']);
					if($platform_account)
					{
						$response=$this->TeapplixApi->GetWarehouseList($this->mobj->decryptString($platform_account->access_token));
						$result=json_decode($response, true);
						if(isset($result['Warehouses'][0]['WarehouseId']))
						{
							foreach($result['Warehouses'] as $warehouse)
							{
								$WarehouseData=['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$warehouse_object->id, 'api_id'=>$warehouse['WarehouseId'], 'name'=>$warehouse['WarehouseName'], 'api_code'=>$warehouse['WarehouseType'], 'description'=>$warehouse['QuantityAlgorithm'], 'status'=>1];

								$platform_object_data=$this->mobj->getFirstResultByConditions('platform_object_data', [ 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_object_id'=>$warehouse_object->id, 'api_id'=>$warehouse['WarehouseId']], ['id']);
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
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - TeapplixApiController - GetWarehouses - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function GetTeapplixProduct($user_id=0, $user_integration_id=0)
		{
			$return_data = true;
			try
			{
				$EventID = "GET_PRODUCT";
				$selectFields = ['e.event_id','ur.status'];
				$user_work_flow = $this->mapping->getUserIntegWorkFlow($user_integration_id, $EventID, $selectFields, self::$myPlatform);
				
				/* First Check whether Order Sync is ON */
				if(isset($user_work_flow[$EventID]) && $user_work_flow[$EventID]['status']==1){
					$platform_account=$this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token']);
					if($platform_account)
					{
						$PageSize = 200;
						$PageNumber = 1;
						do{
							$allow_next_call = false;
							$response=$this->TeapplixApi->GetInventoryList($this->mobj->decryptString($platform_account->access_token), $PageSize, $PageNumber);
							$result=json_decode($response, true);
							if(isset($result['Products'][0]['ItemName']))
							{
								foreach($result['Products'] as $product)
								{
									//product details
									$productData=['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$product['ItemName'], 'product_name'=>@$product['ItemTitle'], 'sku'=>$product['ItemName'], 'upc'=>@$product['Upc'], 'price'=>$product['DefaultPrice'], 'category_id'=>$product['JetCategoryId']];

									$platform_product=$this->mobj->getFirstResultByConditions('platform_product', [ 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$product['ItemName']], ['id']);
									if($platform_product)
									{
										$this->mobj->makeUpdate('platform_product', $productData, ['id'=>$platform_product->id]);
									}
									else
									{
										$productData['product_sync_status']='Ready';
										$this->mobj->makeInsertGetId('platform_product', $productData);
									}
								}	

								if(count($result['Products'])== $PageSize)
								{
									$allow_next_call = true;
									$PageNumber ++;
								}
							}
						}while($allow_next_call);
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - TeapplixApiController - GetTeapplixProduct - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function GetTeapplixInventory($user_id=0, $user_integration_id=0)
		{
			$return_data = true;
			try
			{
				$EventID = "GET_INVENTORY";
				$selectFields = ['e.event_id','ur.status'];
				$user_work_flow = $this->mapping->getUserIntegWorkFlow($user_integration_id, $EventID, $selectFields, self::$myPlatform);
				
				/* First Check whether Order Sync is ON */
				if(isset($user_work_flow[$EventID]) && $user_work_flow[$EventID]['status']==1)
				{
					$platform_account=$this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token']);
					if($platform_account)
					{
						$response=$this->TeapplixApi->GetInventoryList($this->mobj->decryptString($platform_account->access_token));
						$inventories=json_decode($response, true);
						if(isset($inventories['ProductQuantities'][0]['ItemName']))
						{
							foreach($inventories['ProductQuantities'] as $inventory)
							{
								//product details
								$productData=['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$inventory['ItemName'], 'product_name'=>@$inventory['ItemTitle'], 'sku'=>$inventory['ItemName'], 'upc'=>@$inventory['Upc'], 'api_warehouse_id'=>$inventory['WarehouseId']];

								$platform_product=$this->mobj->getFirstResultByConditions('platform_product', [ 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'api_product_id'=>$inventory['ItemName']], ['id']);
								if($platform_product)
								{
									$platform_product_id=$platform_product->id;
									$this->mobj->makeUpdate('platform_product', $productData, ['id'=>$platform_product->id]);
								}
								else
								{
									$productData['inventory_sync_status']='Ready';
									$platform_product_id=$this->mobj->makeInsertGetId('platform_product', $productData);
								}

								$InventoryData=['user_id'=>$user_id, 'platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_product_id'=>$platform_product_id, 'api_product_id'=>$inventory['ItemName'], 'sku'=>$inventory['ItemName'], 'quantity'=>$inventory['QtyAvailable'], 'api_warehouse_id'=>$inventory['WarehouseId']];

								$platform_product_inventory=$this->mobj->getFirstResultByConditions('platform_product_inventory', ['platform_id'=>$this->platformId, 'user_integration_id'=>$user_integration_id, 'platform_product_id'=>$platform_product_id, 'api_product_id'=>$inventory['ItemName'], 'api_warehouse_id'=>$inventory['WarehouseId']], ['id', 'quantity']);
								if($platform_product_inventory)
								{
									if($platform_product_inventory->quantity != $inventory['QtyAvailable'])
									{
										$InventoryData['sync_status']='Ready';
										$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Ready'], ['id'=>$platform_product_id]);
									}

									$this->mobj->makeUpdate('platform_product_inventory', $InventoryData, ['id'=>$platform_product_inventory->id]);
								}
								else
								{
									$InventoryData['sync_status']='Ready';
									$this->mobj->makeInsertGetId('platform_product_inventory', $InventoryData);
								}
							}	
						}
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - TeapplixApiController - GetTeapplixInventory - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function UpdateTeapplixInventory($user_id=0, $user_integration_id=0, $source_platform_name='', $platform_workflow_rule_id=0, $user_workflow_rule_id=0, $record_id=0)
		{
			$return_data = true;
			$process_limit=25;
			try
			{
				$source_platform_id=$this->conn->getPlatformIdByName($source_platform_name);
				$destination_platform_id=$this->conn->getPlatformIdByName('teapplix');
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
							->select('source_platform_product.id', 'source_platform_product.sku', 'destination_platform_product.api_product_id as teapplix_api_product_id', 'source_platform_product.api_product_id as source_api_product_id')
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
							->whereIn('source_platform_product.sku', ['642872886803', '672468000450', '672468000535'])
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

							$DefaultInventoryWarehouseId = NULL;
							$default_inventory_warehouse = $this->mapping->getMappedDataByName($user_integration_id, NULL, "inventory_warehouse_tp", ['api_id']);
							if($default_inventory_warehouse) 
							{
								$DefaultInventoryWarehouseId = $default_inventory_warehouse->api_id;
							}

							if(count($source_platform_products) > 0)
							{
								foreach($source_platform_products as $source_platform_product)
								{
									$platform_product_inventories=$this->mobj->getResultByConditions('platform_product_inventory', ['user_integration_id'=>$user_integration_id, 'platform_product_id'=>$source_platform_product->id, 'sync_status'=>'Ready'], ['id', 'api_warehouse_id', 'quantity']);
									if(count($platform_product_inventories) > 0)
									{
										foreach($platform_product_inventories as $platform_product_inventory)
										{
											/*-----------------start to find inventory warehouse-----------------*/
											$InventoryWarehouseId = null;
											$inventory_warehouse = $this->mapping->getMappedDataByName($user_integration_id, NULL, "inventory_warehouse", ['api_id'], 'regular', $platform_product_inventory->api_warehouse_id);
											if($inventory_warehouse) 
											{
												$InventoryWarehouseId = $inventory_warehouse->api_id;
											}
											else
											{
												if($DefaultInventoryWarehouseId)
												{
													$InventoryWarehouseId = $DefaultInventoryWarehouseId;
												}
											}
											/*-----------------stop to find inventory warehouse-----------------*/
											
											if($InventoryWarehouseId)
											{
												$destination_platform_product_inventory=$this->mobj->getFirstResultByConditions('platform_product_inventory', ['user_integration_id'=>$user_integration_id, 'api_product_id'=>$source_platform_product->teapplix_api_product_id, 'platform_id'=>$destination_platform_id, 'api_warehouse_id'=>$InventoryWarehouseId], ['quantity']);
												if($destination_platform_product_inventory)
												{
													if($destination_platform_product_inventory->quantity != $platform_product_inventory->quantity)
													{
														$curl_put_data=array("Quantities"=>array(array("PostDate"=>date('Y/m/d'), "PostType"=>"in-stock", "WarehouseId"=>$InventoryWarehouseId, "ItemName"=>$source_platform_product->teapplix_api_product_id, "Quantity"=>$platform_product_inventory->quantity)), "Cleanup"=>true, "ProductCrossReference"=>"reject");

														$request_data_json=json_encode($curl_put_data);

														$response=$this->TeapplixApi->UpdateProductQuantity($this->mobj->decryptString($destination_platform_account->access_token), $request_data_json);

														$result=json_decode($response, true);
														if(isset($result['Products'][0]['Status']))
														{
															if($result['Products'][0]['Status'] == 'Success')
															{
																$this->mobj->makeUpdate('platform_product_inventory', ['sync_status'=>'Synced'], ['id'=>$platform_product_inventory->id]);

																$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Synced'], ['id'=>$source_platform_product->id]);

																$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'success', $source_platform_product->id, 'Inventory synced successfully!');
															}
															elseif(isset($result['Products'][0]['Message']))
															{
																$return_data = $result['Products'][0]['Message'];
																
																$this->mobj->makeUpdate('platform_product_inventory', ['sync_status'=>'Failed'], ['id'=>$platform_product_inventory->id]);

																$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Failed'], ['id'=>$source_platform_product->id]);

																$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_product->id, $result['Products'][0]['Message']);
															}
														}
													}
													else
													{
														$this->mobj->makeUpdate('platform_product_inventory', ['sync_status'=>'Synced'], ['id'=>$platform_product_inventory->id]);

														$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Synced'], ['id'=>$source_platform_product->id]);

														$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'success', $source_platform_product->id, 'Inventory synced successfully!');
													}
												}
												else
												{
													$this->mobj->makeUpdate('platform_product_inventory', ['sync_status'=>'Synced'], ['id'=>$platform_product_inventory->id]);

													$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Synced'], ['id'=>$source_platform_product->id]);

													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'success', $source_platform_product->id, 'Inventory synced successfully!');
												}
											}
											else
											{
												$return_data = "Inventory warehouse not matched.";

												$this->mobj->makeUpdate('platform_product_inventory', ['sync_status'=>'Failed'], ['id'=>$platform_product_inventory->id]);

												$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Failed'], ['id'=>$source_platform_product->id]);

												$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_product->id, "Inventory warehouse not matched.");
											}
										}
									}
									else
									{
										$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Synced'], ['id'=>$source_platform_product->id]);

										$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'success', $source_platform_product->id, 'Inventory synced successfully!');
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
				\Log::error($user_integration_id.' - TeapplixApiController - UpdateTeapplixInventory - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function test()
		{
			
		}

		/* Execute Teapplix Event Methods */
		public function ExecuteTeapplixEvents($method='', $event='', $destination_platform_id='', $user_id='', $user_integration_id='', $is_initial_sync=0, $user_workflow_rule_id='', $source_platform_id='', $platform_workflow_rule_id='', $record_id='')
		{
			if($method == 'GET' && $event == 'WAREHOUSE')
			{
				$this->GetWarehouses($user_id, $user_integration_id);
			}
			elseif($method == 'GET' && $event == 'PRODUCT')
			{
				$this->GetTeapplixProduct($user_id, $user_integration_id);
			}
			elseif($method == 'GET' && $event == 'INVENTORY')
			{
				$this->GetTeapplixInventory($user_id, $user_integration_id);
			}
			elseif($method == 'MUTATE' && $event == 'INVENTORY')
			{
				$this->UpdateTeapplixInventory($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
			}

			return true;
		}
	}