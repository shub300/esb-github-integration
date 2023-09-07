<?php
	namespace App\Http\Controllers\CetecERP;

	use App\Http\Controllers\Controller;
	use Illuminate\Http\Request;
	use App\Helper\ConnectionHelper;
	use App\Helper\FieldMappingHelper;
	use App\Helper\MainModel;
	use App\Models\PlatformAccount;
	use App\Models\PlatformOrder;
	use App\Models\PlatformOrderAddress;
	use App\Models\PlatformOrderShipment;
	use App\Models\PlatformOrderShipmentLine;
	use App\Models\PlatformOrderShipmentLineAdditionalInformation;
	use App\Helper\Api\CetecERPApi;
	use Auth;
	use Lang;
	class CetecERPApiController extends Controller
	{
		public static $myPlatform = 'cetecerp';

		/**
			* Create a new controller instance.
			*
			* @return void
		*/
		public function __construct()
		{
			$this->MainModel = new MainModel();
			$this->CetecERPApi = new CetecERPApi();
			$this->ConnectionHelper = new ConnectionHelper();
			$this->FieldMappingHelper = new FieldMappingHelper();
			$this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
		}

		public function InitiateCetecERPAuth(Request $request)
		{
			$platform='cetecerp';
			return view("pages.apiauth.auth_cetecerp", compact('platform'));
		}

		public function ConnectCetecERPAuth(Request $request)
		{
			$request->validate(['cetecerp_ftp_domain'=>'required', 'cetecerp_ftp_username'=>'required', 'cetecerp_ftp_password'=>'required']);
			
			$cetecerp_ftp_domain = trim($request->cetecerp_ftp_domain);
			$cetecerp_ftp_username = trim($request->cetecerp_ftp_username);
			$cetecerp_ftp_password = trim($request->cetecerp_ftp_password);
			
			$data = [];

			if($this->MainModel->checkHtmlTags( $request->all() ) ){
				$data['status_code'] = 0;
				$data['status_text'] = Lang::get('tags.validate');
				return json_encode($data);
			}
			
			try{
				$flag = true;
				// to check whether given account is already in use or not.
				$checkExistingAc = PlatformAccount::select('id')->where('platform_id', $this->platformId)->where('api_domain', $this->MainModel->encryptString($cetecerp_ftp_domain))->where('app_id', $this->MainModel->encryptString($cetecerp_ftp_username))->where('app_secret', $this->MainModel->encryptString($cetecerp_ftp_password))->first();
				if ($checkExistingAc)
				{
					$flag = false;
					$data['status_code'] = 0;
					$data['status_text'] = 'This account detail already exist, Try with another account.';
				}
				else
				{
					try {
						// connect and login to FTP server
						$ftp_conn = ftp_connect($cetecerp_ftp_domain);
						$login = ftp_login($ftp_conn, $cetecerp_ftp_username, $cetecerp_ftp_password);

						PlatformAccount::insert(['user_id'=>Auth::user()->id, 'platform_id'=>$this->platformId, 'account_name'=>$cetecerp_ftp_domain, 'api_domain'=>$this->MainModel->encryptString($cetecerp_ftp_domain), 'app_id'=>$this->MainModel->encryptString($cetecerp_ftp_username), 'app_secret'=>$this->MainModel->encryptString($cetecerp_ftp_password), 'allow_refresh'=>0]);
					}
					//catch exception
					catch(\Exception $e){
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

		public function GetShipments($user_id=0, $user_integration_id=0, $user_workflow_rule_id=0)
		{
			$return_data = true;
			try
			{
				$process_limit = 5;
				$read_file = 0;
				$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['api_domain', 'app_id', 'app_secret']);
				if($platform_account)
				{
					$shipment_folder_path = '';
					$shipment_folder_path_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "shipment_folder_path", ['custom_data'], "default");
					if($shipment_folder_path_record)
					{
						$shipment_folder_path = $shipment_folder_path_record->custom_data;
					}

					try{
						// connect and login to FTP server
						$ftp_conn = ftp_connect($this->MainModel->decryptString($platform_account->api_domain));
						$login = ftp_login($ftp_conn, $this->MainModel->decryptString($platform_account->app_id), $this->MainModel->decryptString($platform_account->app_secret));
						ftp_pasv($ftp_conn, true);// or die("Passive mode failed");
						// get file list of current directory
						$files = ftp_nlist($ftp_conn, $shipment_folder_path);
						//echo "<pre>";
						//print_r($files);

						$pathArray = explode('/', $shipment_folder_path);
						array_pop($pathArray);
						@ftp_mkdir($ftp_conn, implode('/', $pathArray)."/archive");

						if(is_array($files) || is_object($files))
						{
							foreach($files as $file)
							{
								if(strpos($file, '.csv') !== false || strpos($file, '.CSV') !== false)
								{
									$platform_order_id = NULL;
									$platform_order_shipment_id = NULL;
									$InvoiceDetailCounter = 0;
									$CartonIdCounter = 0;
									$SerialDetailCounter = 0;
									$LineItems = [];

									$csvFile = fopen('ftp://'.$this->MainModel->decryptString($platform_account->app_id).':'.urlencode($this->MainModel->decryptString($platform_account->app_secret)).'@'.$this->MainModel->decryptString($platform_account->api_domain).':21'.$file, 'r');
									while(($line = fgetcsv($csvFile)) !== FALSE)
									{
										if($platform_order_shipment_id == NULL)
										{
											$shipmentString = '';
											if(count($line) == 1)
											{
												$shipmentString = $line[0];
											}
											else
											{
												foreach($line as $csvData)
												{
													$shipmentString .= $csvData;
												}
											}

											$shipment = explode("~", $shipmentString);
											//echo "<pre>";
											//print_r($shipment);//die;
											if(isset($shipment[0]) && $shipment[0] == 'Header' && count($shipment) == 27 && $shipment[26] == 856)
											{
												$platform_order = PlatformOrder::select('id')->where('user_id', $user_id)->where('user_integration_id', $user_integration_id)->where('platform_id', $this->platformId)->where('api_order_id', $shipment[3])->where('order_number', $shipment[4])->first();
												if(is_null($platform_order))
												{
													$platform_order = PlatformOrder::create(['user_id'=>$user_id, 'user_integration_id'=>$user_integration_id, 'user_workflow_rule_id'=>$user_workflow_rule_id, 'platform_id'=>$this->platformId, 'shipment_status'=>'Ready', 'order_type'=>'SO', 'api_order_id'=>$shipment[3], 'order_number'=>$shipment[4], 'shipping_method'=> $shipment[8], 'order_date'=>date('Y-m-d', strtotime($shipment[2])), 'delivery_date'=>date('Y-m-d H:i:s', strtotime($shipment[5].' '.$shipment[6])), 'order_updated_at'=>date('Y-m-d H:i:s')]);

													$platform_order_id = $platform_order->id;
												}
												else
												{
													$platform_order_id = $platform_order->id;

													PlatformOrder::where('id', $platform_order_id)
													->update(['shipping_method'=>$shipment[8], 'order_date'=>date('Y-m-d', strtotime($shipment[2])), 'delivery_date'=>date('Y-m-d H:i:s', strtotime($shipment[5].' '.$shipment[6])), 'order_updated_at'=>date('Y-m-d H:i:s')]);
												}

												$ship_to_address = PlatformOrderAddress::select('id')->where('platform_order_id', $platform_order_id)->where('address_type', 'shipping')->first();
												if(is_null($ship_to_address))
												{
													PlatformOrderAddress::create(['platform_order_id'=>$platform_order_id, 'address_type'=>'shipping', 'address_name'=>$shipment[10], 'address1'=>$shipment[11], 'address2'=>$shipment[12], 'address3'=>$shipment[13], 'city'=>$shipment[14], 'state'=>$shipment[15], 'postal_code'=>$shipment[16], 'country'=>$shipment[17]]);
												}
												else
												{
													PlatformOrderAddress::where('id', $ship_to_address->id)
													->update(['address_name'=>$shipment[10], 'address1'=>$shipment[11], 'address2'=>$shipment[12], 'address3'=>$shipment[13], 'city'=>$shipment[14], 'state'=>$shipment[15], 'postal_code'=>$shipment[16], 'country'=>$shipment[17]]);
												}

												$ship_from_address = PlatformOrderAddress::select('id')->where('platform_order_id', $platform_order_id)->where('address_type', 'shippedfrom')->first();
												if(is_null($ship_from_address))
												{
													PlatformOrderAddress::create(['platform_order_id'=>$platform_order_id, 'address_type'=>'shippedfrom', 'address_name'=>$shipment[18], 'address1'=>$shipment[19], 'address2'=>$shipment[20], 'address3'=>$shipment[21], 'city'=>$shipment[22], 'state'=>$shipment[23], 'postal_code'=>$shipment[24], 'country'=>$shipment[25]]);
												}
												else
												{
													PlatformOrderAddress::where('id', $ship_from_address->id)
													->update(['address_name'=>$shipment[18], 'address1'=>$shipment[19], 'address2'=>$shipment[20], 'address3'=>$shipment[21], 'city'=>$shipment[22], 'state'=>$shipment[23], 'postal_code'=>$shipment[24], 'country'=>$shipment[25]]);
												}

												$platform_order_shipment = PlatformOrderShipment::select('id')->where('user_id', $user_id)->where('user_integration_id', $user_integration_id)->where('platform_id', $this->platformId)->where('platform_order_id', $platform_order_id)->first();
												if(is_null($platform_order_shipment))
												{
													$platform_order_shipment = PlatformOrderShipment::create(['user_id'=>$user_id, 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'platform_order_id'=>$platform_order_id, 'sync_status'=>'Ready', 'shipment_id'=>$shipment[3], 'order_id'=>$shipment[3], 'carrier_code'=>$shipment[7], 'shipping_method'=>$shipment[8], 'created_on'=>date('Y-m-d', strtotime($shipment[2])), 'realease_date'=>date('Y-m-d H:i:s', strtotime($shipment[5].' '.$shipment[6]))]);

													$platform_order_shipment_id = $platform_order_shipment->id;
												}
												else
												{
													$platform_order_shipment_id = $platform_order_shipment->id;

													PlatformOrderShipment::where('id', $platform_order_shipment_id)
													->update(['carrier_code'=>$shipment[7], 'shipping_method'=>$shipment[8], 'created_on'=>date('Y-m-d', strtotime($shipment[2])), 'realease_date'=>date('Y-m-d H:i:s', strtotime($shipment[5].' '.$shipment[6]))]);
												}
											}
										}
										else
										{
											$itemString = '';
											if(count($line) == 1)
											{
												$itemString = $line[0];
											}
											else
											{
												foreach($line as $csvData)
												{
													$itemString .= $csvData;
												}
											}

											$item = explode("~", $itemString);
											//echo "<pre>";
											//print_r($item);
											
											if(isset($item[0]) && $item[0] == 'InvoiceDetail')
											{
												$LineItems[$InvoiceDetailCounter]['InvoiceDetail'] = $item;
												$InvoiceDetailCounter++;
											}
											else if(isset($item[0]) && $item[0] == 'SerialDetail' && isset($LineItems[$SerialDetailCounter]['InvoiceDetail']))
											{
												array_shift($item);

												$SerialDetail = array_filter($item);
												$LineItems[$SerialDetailCounter]['SerialDetail'] = implode(",", $SerialDetail);

												$SerialDetailCounter++;
											}
											else if(isset($item[0]) && $item[0] == 'CartonID' && ( isset($LineItems[$CartonIdCounter]['InvoiceDetail']) || isset($LineItems[$CartonIdCounter]['SerialDetail']) ) )
											{
												array_shift($item);

												$CartonID = array_filter($item);
												$LineItems[$CartonIdCounter]['CartonID'] = implode(",", $CartonID);

												$CartonIdCounter++;
											}
										}
									}

									//dd($LineItems);
									$validateLineExist = 0;
									if($platform_order_shipment_id && count($LineItems) > 0)
									{
										foreach($LineItems as $LineItem)
										{
											if(isset($LineItem['InvoiceDetail']) && isset($LineItem['SerialDetail']))
											{
												$row_id = @$LineItem['InvoiceDetail'][1];
												if(isset($LineItem['InvoiceDetail'][11]) && $LineItem['InvoiceDetail'][11])
												{
													$row_id = $LineItem['InvoiceDetail'][11];
												}

												$platform_order_shipment_line_id = NULL;
												$platform_order_shipment_line = PlatformOrderShipmentLine::select('id')->where('platform_order_shipment_id', $platform_order_shipment_id)->where('row_id', $row_id)->first();
												if(is_null($platform_order_shipment_line))
												{
													$platform_order_shipment_line = PlatformOrderShipmentLine::create(['platform_order_shipment_id'=>$platform_order_shipment_id, 'row_id'=>$row_id, 'sku'=>@$LineItem['InvoiceDetail'][2], 'barcode'=>@$LineItem['InvoiceDetail'][3], 'quantity'=>@$LineItem['InvoiceDetail'][4], 'user_batch_reference'=>@$LineItem['InvoiceDetail'][5]]);

													$platform_order_shipment_line_id = $platform_order_shipment_line->id;
												}
												else
												{
													$platform_order_shipment_line_id = $platform_order_shipment_line->id;

													PlatformOrderShipmentLine::where('id', $platform_order_shipment_line_id)
													->update(['sku'=>@$LineItem['InvoiceDetail'][2], 'barcode'=>@$LineItem['InvoiceDetail'][3], 'quantity'=>@$LineItem['InvoiceDetail'][4], 'user_batch_reference'=>@$LineItem['InvoiceDetail'][5]]);
												}

												if($platform_order_shipment_line_id)
												{
													$additional_information = PlatformOrderShipmentLineAdditionalInformation::select('id')->where('platform_order_shipment_line_id', $platform_order_shipment_line_id)->first();
													if(is_null($additional_information))
													{
														PlatformOrderShipmentLineAdditionalInformation::create(['platform_order_shipment_line_id'=>$platform_order_shipment_line_id, 'country_of_origin'=>@$LineItem['InvoiceDetail'][9], 'serial_number'=>@$LineItem['SerialDetail'], 'carton_id'=>@$LineItem['CartonID'], 'tca_revision'=>@$LineItem['InvoiceDetail'][7], 'tla_revision'=>@$LineItem['InvoiceDetail'][6], 'pca_revision'=>@$LineItem['InvoiceDetail'][8], 'pgc_date_code'=>@$LineItem['InvoiceDetail'][10]]);
													}
													else
													{
														PlatformOrderShipmentLineAdditionalInformation::where('platform_order_shipment_line_id', $platform_order_shipment_line_id)
														->update(['country_of_origin'=>@$LineItem['InvoiceDetail'][9], 'serial_number'=>@$LineItem['SerialDetail'], 'carton_id'=>@$LineItem['CartonID'], 'tca_revision'=>@$LineItem['InvoiceDetail'][7], 'tla_revision'=>@$LineItem['InvoiceDetail'][6], 'pca_revision'=>@$LineItem['InvoiceDetail'][8], 'pgc_date_code'=>@$LineItem['InvoiceDetail'][10]]);
													}

													$validateLineExist++;
												}
											}
										}
									}

									if($validateLineExist == 0)
									{
										PlatformOrderAddress::where('platform_order_id', $platform_order_id)->delete();
										PlatformOrderShipment::where('id', $platform_order_shipment_id)->delete();
										PlatformOrder::where('id', $platform_order_id)->delete();
									}
									fclose($csvFile);

									$file_info = explode('/', $file);
									/* Download remote_file and save to local_file */
									if(ftp_get($ftp_conn, end($file_info), $file, FTP_BINARY))
									{
										/* Send local_file to FTP */
										if(ftp_put($ftp_conn, implode('/', $pathArray)."/archive/".end($file_info), end($file_info), FTP_BINARY))
										{
											ftp_delete($ftp_conn, $file);
										}
									}

									$read_file++;
								}

								if($process_limit == $read_file)
								{
									break;
								}
							}
						}
					}
					//catch exception
					catch(\Exception $e)
					{
						\Log::error($user_integration_id.' - CetecERPApiController - GetShipments - '.$e->getLine().' -'.$e->getMessage());
						$return_data = $e->getMessage();
					}
				}
			}
			catch(\Exception $e)
			{
				\Log::error($user_integration_id.' - CetecERPApiController - GetShipments - '.$e->getLine().' - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function test()
		{
			
		}

		/* Execute Cetec ERP Event Methods */
		public function ExecuteCetecERPEvents($method='', $event='', $destination_platform_id='', $user_id='', $user_integration_id='', $is_initial_sync=0, $user_workflow_rule_id='', $source_platform_id='', $platform_workflow_rule_id='', $record_id='')
		{
			$response = true;
			if($method == 'GET' && $event == 'SHIPMENT')
			{
				$response = $this->GetShipments($user_id, $user_integration_id, $user_workflow_rule_id);
			}

			return $response;
		}
	}