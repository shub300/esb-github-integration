<?php
	namespace App\Http\Controllers\MicroChip;

	use App\Http\Controllers\Controller;
	use Illuminate\Http\Request;
	use App\Helper\Api\MicroChipApi;
	use App\Helper\MainModel;
	use App\Helper\ConnectionHelper;
	use App\Helper\FieldMappingHelper;
	use App\Helper\Logger;
	use App\Models\PlatformAccount;
	use App\Models\PlatformOrder;
	use App\Models\PlatformOrderAddress;
	use App\Models\PlatformOrderShipment;
	use App\Models\PlatformOrderShipmentLine;
	use App\Models\PlatformOrderShipmentLineAdditionalInformation;
	use Lang;
	use Auth, Exception, File, Log;

	class MicroChipApiController extends Controller
	{
		public static $myPlatform = 'microchip';

		/**
			* Create a new controller instance.
			*
			* @return void
		*/
		public function __construct()
		{
			$this->MainModel = new MainModel();
			$this->MicroChipApi = new MicroChipApi();
			$this->ConnectionHelper = new ConnectionHelper();
			$this->FieldMappingHelper=new FieldMappingHelper();
			$this->Logger = new Logger();
			$this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
		}

		public function InitiateMicroChipAuth(Request $request)
		{
			$platform='microchip';
			return view("pages.apiauth.auth_microchip", compact('platform'));
		}

		public function ConnectMicroChipAuth(Request $request)
		{
			$request->validate(['microchip_partner_identity'=>'required', 'microchip_as2_url'=>'required', 'microchip_encryption_certificate'=>'required']);

			$microchip_partner_identity = trim($request->microchip_partner_identity);
			$microchip_as2_url = trim($request->microchip_as2_url);
			$microchip_encryption_certificate = trim($request->microchip_encryption_certificate);

			$data = [];

			if($this->MainModel->checkHtmlTags( $request->all() ) ){
				$data['status_code'] = 0;
				$data['status_text'] = Lang::get('tags.validate');
				return json_encode($data);
			}
			try{
				$flag = true;
				$mftgateway_platform_id = $this->ConnectionHelper->getPlatformIdByName('mftgateway');
				$mftgateway_platform_account = PlatformAccount::select('app_id', 'access_token')->where('user_id', 0)->where('platform_id', $mftgateway_platform_id)->first();
				if($mftgateway_platform_account)
				{
					$checkExistingAc = PlatformAccount::select('id')->where('platform_id', $this->platformId)->where('app_secret', $this->MainModel->encrypt_decrypt($microchip_partner_identity, 'encrypt'))->first();
					if($checkExistingAc)
					{
						$flag = false;
						$data['status_code'] = 0;
						$data['status_text'] = 'This partner identity detail already exist, Try with another partner identity.';
					}
					else
					{
						//$partnerData = array("name"=>"Partner Name", "identifier"=>"partner_as2_id", "url"=>"https://partner.com", "encryptionCertificate"=>"<Base64 encoded encryption certificate>", "encryptMessage"=>true, "encryptionAlgorithm"=>"DES_EDE3_CBC", "signMessage"=>true, "signatureAlgorithm"=>"SHA256", "httpsCertificate"=>"<Base64 encoded SSL certificate>", "httpsChainCertificates"=>array("<Base64 encoded SSL chain certificate one>", "<Base64 encoded SSL chain certificate two>"), "customHeaders"=>array(array("headerName"=>"<custom-header-name>", "headerValue"=>"<custom-header-value>")));

						$partnerData = array("name"=>$microchip_partner_identity, "identifier"=>$microchip_partner_identity, "url"=>$microchip_as2_url, "encryptionCertificate"=>base64_encode($microchip_encryption_certificate), "encryptMessage"=>true, "encryptionAlgorithm"=>"DES_EDE3_CBC", "signMessage"=>true, "signatureAlgorithm"=>"SHA256");

						$response = app('App\Http\Controllers\MFTGateway\MFTGatewayApiController')->CreatePartner($this->MainModel->encrypt_decrypt($mftgateway_platform_account->access_token, 'decrypt'), json_encode($partnerData), 'as2');
						$result = json_decode($response, true);
						if(isset($result['identifier']))
						{
							PlatformAccount::create(['user_id'=>Auth::user()->id, 'platform_id'=>$this->platformId, 'account_name'=>$microchip_partner_identity, 'app_id'=>$mftgateway_platform_account->app_id, 'app_secret'=>$this->MainModel->encrypt_decrypt($result['identifier'], 'encrypt'), 'api_domain'=>$this->MainModel->encrypt_decrypt($microchip_as2_url, 'encrypt'), 'access_key'=>$this->MainModel->encrypt_decrypt($microchip_encryption_certificate, 'encrypt'), 'allow_refresh'=>0]);
						}
						elseif(isset($result['errors'][0]['as2Identifier']))
						{
							PlatformAccount::create(['user_id'=>Auth::user()->id, 'platform_id'=>$this->platformId, 'account_name'=>$microchip_partner_identity, 'app_id'=>$mftgateway_platform_account->app_id, 'app_secret'=>$this->MainModel->encrypt_decrypt($microchip_partner_identity, 'encrypt'), 'api_domain'=>$this->MainModel->encrypt_decrypt($microchip_as2_url, 'encrypt'), 'access_key'=>$this->MainModel->encrypt_decrypt($microchip_encryption_certificate, 'encrypt'), 'allow_refresh'=>0]);
						}
						elseif(isset($result['errors'][0]['identifier']))
						{
							PlatformAccount::create(['user_id'=>Auth::user()->id, 'platform_id'=>$this->platformId, 'account_name'=>$microchip_partner_identity, 'app_id'=>$mftgateway_platform_account->app_id, 'app_secret'=>$this->MainModel->encrypt_decrypt($microchip_partner_identity, 'encrypt'), 'api_domain'=>$this->MainModel->encrypt_decrypt($microchip_as2_url, 'encrypt'), 'access_key'=>$this->MainModel->encrypt_decrypt($microchip_encryption_certificate, 'encrypt'), 'allow_refresh'=>0]);
						}
						elseif(isset($result['message']))
						{
							$flag = false;
							$data['status_code'] = 0;
							$data['status_text'] = $result['message'];
						}
						else
						{
							$flag = false;
							$data['status_code'] = 0;
							$data['status_text'] = "API Error";
						}
					}
				}
				else
				{
					$flag = false;
					$data['status_code'] = 0;
					$data['status_text'] = "MFT gateway integration not available.";
				}

				if($flag)
				{
					$data['status_code'] = 1;
					$data['status_text'] = 'Account connected successfully.';
				}

				return json_encode($data);
			}
			catch(Exception $e)
			{
				$data['status_code'] = 0;
				$data['status_text'] = $e->getMessage();
				return json_encode($data);
			}
		}

		public function CreateShipment($user_id=0, $user_integration_id=0, $source_platform_name='', $platform_workflow_rule_id=0, $user_workflow_rule_id=0, $record_id=0)
		{
			$return_data = true;
			$process_limit = 25;
			try
			{
				$mftgateway_platform_id = $this->ConnectionHelper->getPlatformIdByName('mftgateway');
				$mftgateway_platform_account = PlatformAccount::select('app_id', 'access_token')->where('user_id', 0)->where('platform_id', $mftgateway_platform_id)->first();
				$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['app_secret']);
				if($mftgateway_platform_account && $platform_account)
				{
					$source_platform_id = $this->ConnectionHelper->getPlatformIdByName($source_platform_name);
					$object_id = $this->ConnectionHelper->getObjectId('sales_order_shipment');

					$interchange_receiver_id_qualifier = '';
					$interchange_receiver_id_qualifier_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "interchange_receiver_id_qualifier", ['custom_data'], "default");
					if($interchange_receiver_id_qualifier_record)
					{
						$interchange_receiver_id_qualifier = $interchange_receiver_id_qualifier_record->custom_data;
					}

					$interchange_receiver_id = '';
					$interchange_receiver_space = '               ';
					$interchange_receiver_id_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "interchange_receiver_id", ['custom_data'], "default");
					if($interchange_receiver_id_record)
					{
						$interchange_receiver_id = $interchange_receiver_id_record->custom_data;
						$interchange_receiver_strlen = strlen($interchange_receiver_id_record->custom_data);
						$interchange_receiver_space = '';
						if((15 - $interchange_receiver_strlen) > 0)
						{
							for($i=0; $i<(15 - $interchange_receiver_strlen); $i++)
							{
								$interchange_receiver_space .= ' ';
							}
						}
					}

					$interchange_sender_id_qualifier = '';
					$interchange_sender_id_qualifier_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "interchange_sender_id_qualifier", ['custom_data'], "default");
					if($interchange_sender_id_qualifier_record)
					{
						$interchange_sender_id_qualifier = $interchange_sender_id_qualifier_record->custom_data;
					}

					$interchange_sender_id = '';
					$interchange_sender_space = '               ';
					$interchange_sender_id_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "interchange_sender_id", ['custom_data'], "default");
					if($interchange_sender_id_record)
					{
						$interchange_sender_id = $interchange_sender_id_record->custom_data;
						$interchange_sender_strlen = strlen($interchange_sender_id_record->custom_data);
						$interchange_sender_space = '';
						if((15 - $interchange_sender_strlen) > 0)
						{
							for($i=0; $i<(15 - $interchange_sender_strlen); $i++)
							{
								$interchange_sender_space .= ' ';
							}
						}
					}

					//Usage Indicator "T" for test, "P" for Production
					$usage_indicator = 'T';
					$usage_indicator_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "usage_indicator", ['custom_data'], "default");
					if($usage_indicator_record && $usage_indicator_record->custom_data == 'P')
					{
						$usage_indicator = 'P';
					}

					$as2_subject = '';
					$as2_subject_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "as2_subject", ['custom_data'], "default");
					if($as2_subject_record)
					{
						$as2_subject = $as2_subject_record->custom_data;
					}

					$ship_from_warehouse_id = '';
					$ship_from_warehouse_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "ship_from_warehouse_id", ['custom_data'], "default");
					if($ship_from_warehouse_record)
					{
						$ship_from_warehouse_id = $ship_from_warehouse_record->custom_data;
					}

					$ship_to_warehouse_id = '';
					$ship_to_warehouse_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "ship_to_warehouse_id", ['custom_data'], "default");
					if($ship_to_warehouse_record)
					{
						$ship_to_warehouse_id = $ship_to_warehouse_record->custom_data;
					}

					$control_number = 100000000;
					$platform_url = $this->MainModel->getFirstResultByConditions('platform_urls', ['user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'shipment_control_number', 'status'=>1], ['id', 'url']);
					if($platform_url)
					{
						$platform_url_id = $platform_url->id;
						$control_number = $platform_url->url;
					}
					else
					{
						$platform_url_id = $this->MainModel->makeInsertGetId('platform_urls', ['user_id'=>$user_id, 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'shipment_control_number', 'url'=>100000000, 'status'=>1, 'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s')]);
					}

					$ST_control_number = 0001;
					$platform_url = $this->MainModel->getFirstResultByConditions('platform_urls', ['user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'ST', 'status'=>1], ['id', 'url']);
					if($platform_url)
					{
						$ST_platform_url_id = $platform_url->id;
						$ST_control_number = '000'.$platform_url->url;
					}
					else
					{
						$ST_platform_url_id = $this->MainModel->makeInsertGetId('platform_urls', ['user_id'=>$user_id, 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'ST', 'url'=>1, 'status'=>1, 'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s')]);
					}

					$platform_order_shipments = PlatformOrderShipment::join('platform_order', 'platform_order_shipments.platform_order_id', '=', 'platform_order.id')
					->select('platform_order_shipments.id', 'platform_order_shipments.shipment_id', 'platform_order_shipments.platform_order_id', 'platform_order_shipments.order_id', 'platform_order_shipments.shipping_method', 'platform_order_shipments.carrier_code', 'platform_order_shipments.realease_date', 'platform_order_shipments.created_on', 'platform_order_shipments.weight', 'platform_order.order_number')
					->where(function($query) use($record_id){
                        if($record_id){
                            $query->where(['platform_order.id'=>$record_id]);
                            }else{
                            $query->where(['platform_order.shipment_status'=>'Ready', 'platform_order_shipments.sync_status'=>'Ready']);
                        }
                    })
					->where(['platform_order_shipments.user_id'=>$user_id, 'platform_order_shipments.user_integration_id'=>$user_integration_id, 'platform_order_shipments.platform_id'=>$source_platform_id])
					->where(function ($query){
						$query->whereNull('platform_order_shipments.linked_id')->orWhere('platform_order_shipments.linked_id', 0);
					})
					->orderBy('platform_order_shipments.id', 'asc')
					->limit($process_limit)
					->get();

					foreach($platform_order_shipments as $platform_order_shipment)
					{
						$SetTrailer = 0;
						$MicroChipTXT = "856_".$user_integration_id."_".$platform_order_shipment->id.".txt";

						//https://www.stedi.com/edi/x12-004010/segment important link

						//$lineISA = "ISA|00(Authorization Information Qualifier(00 No Authorization Information Present (No Meaningful Information in I02), 01 UCS Communications ID, 02 EDX Communications ID, 03 Additional Data Identification, 04 Rail Communications ID, 05 Department of Defense (DoD) Communication Identifier, 06 United States Federal Government Communication Identifier))(FIXED)|          (Ten Spaces(or blanks))|00(Security Information Qualifier(Authorization Information) (00 No Security Information Present (No Meaningful Information in I04) 01 Password))(FIXED)|          (Ten Spaces(or blanks))|01(Interchange Sender ID Qualifier)|123456789      (Interchange Sender ID)|ZZ(Interchange Receiver ID Qualifier)(DYNAMIC)|MCHPPROD       (Interchange Receiver ID)(DYNAMIC)|211209(Date)|2204(Time)|U(Repetition Separator(Interchange Standards ID))(FIXED)|00401(Interchange Control Version Number(https://www.stedi.com/edi/x12-005010/element/I11))|311619613(A control number assigned by the interchange sender)|1(Acknowledgment Requested("0" for no "1" for yes))|P(Usage Indicator("T" for test "P" for Production))|^(Sub_Element Separator(ebcdic, 6e ascii, 3e))~\n";
						//Interchange Control Header
						$plainText = "ISA|00|          |00|          |".$interchange_sender_id_qualifier."|".$interchange_sender_id.$interchange_sender_space."|".$interchange_receiver_id_qualifier."|".$interchange_receiver_id.$interchange_receiver_space."|".date('ymd')."|".date('Hi')."|U|00401|".$control_number."|1|".$usage_indicator."|^~\n";

						//$lineGS = "GS|SH(Functional ID Code)(FIXED)|123456789(Sender ID Code)|MCHPPROD(Receiver ID Code)|20211209(Date)|2204(Time)|8508(Assigned number originated and maintained by the sender)|X(Responsible Agency Code(T = TDCC, X = ANSI X12))(FIXED)|004010(Version/Release/Identifier Code)(FIXED)~\n";
						//Functional Group Header
						$plainText .= "GS|SH|".$interchange_sender_id."|".$interchange_receiver_id."|".date('Ymd')."|".date('Hi')."|".$control_number."|X|004010~\n";

						$SetTrailer++;
						//Transaction Set Header
						$plainText .= "ST|856|".$ST_control_number."~\n";

						$SetTrailer++;
						//Beginning Segment for Ship Notice
						$plainText .= "BSN|00|".$platform_order_shipment->shipment_id."|".date('Ymd', strtotime($platform_order_shipment->realease_date))."|".date('His', strtotime($platform_order_shipment->realease_date))."~\n";

						$SetTrailer++;
						//Date/Time Reference
						$plainText .= "DTM|011|".date('Ymd', strtotime($platform_order_shipment->realease_date))."~\n";

						$SetTrailer++;
						//Hierarchical Level
						$plainText .= "HL|1||S~\n";

						$SetTrailer++;
						//Carrier Details (Routing Sequence/Transit Time)
						$plainText .= "TD5|B|2|".$platform_order_shipment->shipping_method."|T~\n";

						$ship_from_address = PlatformOrderAddress::select('address_name', 'address1', 'address2', 'address3', 'city', 'state', 'postal_code', 'country')->where('platform_order_id', $platform_order_shipment->platform_order_id)->where('address_type', 'shippedfrom')->first();
						if($ship_from_address)
						{
							$SetTrailer++;
							//Ship From
							//Name
							$plainText .= "N1|SF|".$ship_from_address->address_name."|92|".$ship_from_warehouse_id."~\n";
						}

						$ship_to_address = PlatformOrderAddress::select('address_name', 'address1', 'address2', 'address3', 'city', 'state', 'postal_code', 'country')->where('platform_order_id', $platform_order_shipment->platform_order_id)->where('address_type', 'shipping')->first();
						if($ship_to_address)
						{
							$SetTrailer++;
							//Ship To
							//Name
							$plainText .= "N1|ST|".$ship_to_address->address_name."|92|".$ship_to_warehouse_id."~\n";
						}

						$HL = 2;
						$LIN = 1;
						$CTT = 0;
						$platform_order_shipment_lines = PlatformOrderShipmentLine::select('id', 'row_id', 'sku', 'barcode', 'quantity')->where('platform_order_shipment_id', $platform_order_shipment->id)->get();
						foreach($platform_order_shipment_lines as $platform_order_shipment_line)
						{
							$additional_information = PlatformOrderShipmentLineAdditionalInformation::select('id', 'country_of_origin', 'serial_number', 'carton_id', 'tca_revision', 'tla_revision', 'pca_revision', 'pgc_date_code')->where('platform_order_shipment_line_id', $platform_order_shipment_line->id)->first();
							if($additional_information && $additional_information->serial_number)
							{
								$country_of_origin = $additional_information->country_of_origin;
								$es_country_code = $this->MainModel->getFirstResultByConditions('es_country_codes', ['iso3'=>$country_of_origin], ['iso']);
								if($es_country_code)
								{
									$country_of_origin = $es_country_code->iso;
								}

								//Default Part Number Carton Qty
								/*$PartNumberCartonQty = 48;

								$PartNumberCartonQtyList = ['2301200-R'=>48, '2294500-R'=>10, '2401200-R'=>48];
								if(isset($PartNumberCartonQtyList[$platform_order_shipment_line->barcode]))
								{
									$PartNumberCartonQty = $PartNumberCartonQtyList[$platform_order_shipment_line->barcode];
								}*/

								$SerialNumberCounter = 1;
								$serial_numbers = explode(",", $additional_information->serial_number);
								$carton_ids = explode(",", $additional_information->carton_id);
								$serial_no_per_carton = (int)ceil( count($serial_numbers) / count($carton_ids) );
								$index = $ival = 0;
								foreach($serial_numbers as $serial_number)
								{
									/* If count of serialNumber is equals to serialNumbers then increment the index value so that it can pick cartonId from the next pkt of the array. Also set $ival so that it can count for the next cartonId */
									if($serial_no_per_carton == $ival){
										$index++;
										$ival = 0;
									}
									$SetTrailer++;
									//Hierarchical Level
									$plainText .= "HL|".$HL."|1|I~\n";

									$SetTrailer++;
									//Item Identification
									$plainText .= "LIN|".$LIN."|BP|".$platform_order_shipment_line->barcode."|PD|".$platform_order_shipment_line->sku."~\n";

									//Reference Identification List
									$SetTrailer++;
									$plainText .= "REF|BV|".$platform_order_shipment_line->row_id."~\n"; //Purchase Order Line Item Identifier (Buyer)

									$SetTrailer++;
									$plainText .= "REF|PO|".$platform_order_shipment->order_number."~\n"; //Purchase Order Number

									$SetTrailer++;
									$plainText .= "REF|4B|".$country_of_origin."~\n"; //Shipment Origin Code

									$SetTrailer++;
									//$plainText .= "REF|BT|".$platform_order_shipment->shipment_id."~\n"; //Vendor Batch Number or Lot ID
									$plainText .= "REF|BT|".$platform_order_shipment_line->id."~\n"; //Vendor Batch Number or Lot ID

									//$CartonNumber = ceil($SerialNumberCounter/$PartNumberCartonQty);
									//<random id>-<qty allowed in part number>-<Cartoon in which this item is placed>
									$SetTrailer++;
									//$plainText .= "REF|98|".$platform_order_shipment->shipment_id."-".$PartNumberCartonQty."-".$CartonNumber."~\n"; //Carton ID
									//$plainText .= "REF|98|".$platform_order_shipment_line->id."-".$PartNumberCartonQty."-".$CartonNumber."~\n"; //Carton ID
									$plainText .= "REF|98|".$carton_ids[$index]."~\n"; //Carton ID

									$SetTrailer++;
									$plainText .= "REF|LS|".$serial_number."~\n"; //Bar-Coded 'Serial Number' of main/TLA part

									$SetTrailer++;
									$plainText .= "REF|R1|".$additional_information->tca_revision."~\n"; //TCA Revision Number(s)

									$SetTrailer++;
									$plainText .= "REF|V0|".$additional_information->tla_revision."~\n"; //TLA Revision Number(s)

									$SetTrailer++;
									$plainText .= "REF|YB|".$additional_information->pca_revision."~\n"; //PCA Revision Number(s)

									$SetTrailer++;
									$plainText .= "REF|PGC|".$additional_information->pgc_date_code."~\n"; //PGC Date Code

									$HL++;
									$LIN++;
									$CTT++;
									$ival++;
									$SerialNumberCounter++;
								}
							}
						}

						$SetTrailer++;
						//Transaction Totals
						$plainText .= "CTT|".$CTT."~\n";

						$SetTrailer++;
						//Transaction Set Trailer
						$plainText .= "SE|".$SetTrailer."|".$ST_control_number."~\n";

						//"GE|1(Number of Transaction Sets)(FIXED)|8508(Group Control Number)~\n";
						//Functional Group Trailer
						$plainText .= "GE|1|".$control_number."~\n";

						//"IEA|1(Number of Include Function Group)(FIXED)|311619613(Interchange Control Number)~\n";
						//Interchange Control Trailer
						$plainText .= "IEA|1|".$control_number."~\n";

						//file_put_contents(storage_path("app/public/MicroChip/".$MicroChipTXT), $plainText);

						$header = array('Authorization: '.$this->MainModel->encrypt_decrypt($mftgateway_platform_account->access_token, 'decrypt'), 'AS2-From: '.$this->MainModel->encrypt_decrypt($mftgateway_platform_account->app_id, 'decrypt'), 'AS2-To: '.$this->MainModel->encrypt_decrypt($platform_account->app_secret, 'decrypt'), 'Subject: '.$as2_subject, 'Attachment-Name: '.$MicroChipTXT, 'Content-Type: text/plain');

						$response = app('App\Http\Controllers\MFTGateway\MFTGatewayApiController')->SendMessage($header, $plainText);
						$result = json_decode($response, true);
						if(isset($result['as2messageId']))
						{
							$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Processing', 'shipment_file_name'=>$MicroChipTXT], ['id'=>$platform_order_shipment->id]);
							$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Processing', 'file_name'=>$result['as2messageId'], 'notes'=>$control_number], ['id'=>$platform_order_shipment->platform_order_id]);

							$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'pending', $platform_order_shipment->platform_order_id, 'Shipment message send successfully!');

							/*
							if(file_exists(storage_path("app/public/MicroChip/".$MicroChipTXT)))
							{
								File::delete(storage_path("app/public/MicroChip/".$MicroChipTXT));
							}
							*/

							$return_data = true;
						}
						elseif(isset($result['message']))
						{
							$return_data = $result['message'];

							$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['id'=>$platform_order_shipment->id]);
							$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed', 'notes'=>$control_number], ['id'=>$platform_order_shipment->platform_order_id]);

							$this->Logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, $result['message']);
						}

						$control_number++;
						$this->MainModel->makeUpdate('platform_urls', ['url'=>$control_number, 'updated_at'=>date('Y-m-d H:i:s')], ['id'=>$platform_url_id]);

						$ST_control_number++;
						$this->MainModel->makeUpdate('platform_urls', ['url'=>$ST_control_number, 'updated_at'=>date('Y-m-d H:i:s')], ['id'=>$ST_platform_url_id]);
					}
				}
			}
			catch(Exception $e)
			{
				Log::error($user_integration_id.' - MicroChipApiController - CreateShipment - '.$e->getLine().' - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function Acknowledgement($user_id=0, $user_integration_id=0, $group_control_number)
		{
			$return_data = false;
			try
			{
				$mftgateway_platform_id = $this->ConnectionHelper->getPlatformIdByName('mftgateway');
				$mftgateway_platform_account = PlatformAccount::select('app_id', 'access_token')->where('user_id', 0)->where('platform_id', $mftgateway_platform_id)->first();
				$platform_account = $this->MainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['app_secret']);
				if($mftgateway_platform_account && $platform_account)
				{
					$interchange_receiver_id_qualifier = '';
					$interchange_receiver_id_qualifier_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "interchange_receiver_id_qualifier", ['custom_data'], "default");
					if($interchange_receiver_id_qualifier_record)
					{
						$interchange_receiver_id_qualifier = $interchange_receiver_id_qualifier_record->custom_data;
					}

					$interchange_receiver_id = '';
					$interchange_receiver_space = '               ';
					$interchange_receiver_id_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "interchange_receiver_id", ['custom_data'], "default");
					if($interchange_receiver_id_record)
					{
						$interchange_receiver_id = $interchange_receiver_id_record->custom_data;
						$interchange_receiver_strlen = strlen($interchange_receiver_id_record->custom_data);
						$interchange_receiver_space = '';
						if((15 - $interchange_receiver_strlen) > 0)
						{
							for($i=0; $i<(15 - $interchange_receiver_strlen); $i++)
							{
								$interchange_receiver_space .= ' ';
							}
						}
					}

					$interchange_sender_id_qualifier = '';
					$interchange_sender_id_qualifier_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "interchange_sender_id_qualifier", ['custom_data'], "default");
					if($interchange_sender_id_qualifier_record)
					{
						$interchange_sender_id_qualifier = $interchange_sender_id_qualifier_record->custom_data;
					}

					$interchange_sender_id = '';
					$interchange_sender_space = '               ';
					$interchange_sender_id_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "interchange_sender_id", ['custom_data'], "default");
					if($interchange_sender_id_record)
					{
						$interchange_sender_id = $interchange_sender_id_record->custom_data;
						$interchange_sender_strlen = strlen($interchange_sender_id_record->custom_data);
						$interchange_sender_space = '';
						if((15 - $interchange_sender_strlen) > 0)
						{
							for($i=0; $i<(15 - $interchange_sender_strlen); $i++)
							{
								$interchange_sender_space .= ' ';
							}
						}
					}

					//Usage Indicator "T" for test, "P" for Production
					$usage_indicator = 'T';
					$usage_indicator_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "usage_indicator", ['custom_data'], "default");
					if($usage_indicator_record && $usage_indicator_record->custom_data == 'P')
					{
						$usage_indicator = 'P';
					}

					$as2_subject = '';
					$as2_subject_record = $this->FieldMappingHelper->getMappedDataByName($user_integration_id, NULL, "as2_subject", ['custom_data'], "default");
					if($as2_subject_record)
					{
						$as2_subject = $as2_subject_record->custom_data;
					}

					$control_number = 100000000;
					$platform_url = $this->MainModel->getFirstResultByConditions('platform_urls', ['user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'ack_control_number', 'status'=>1], ['id', 'url']);
					if($platform_url)
					{
						$platform_url_id = $platform_url->id;
						$control_number = $platform_url->url;
					}
					else
					{
						$platform_url_id = $this->MainModel->makeInsertGetId('platform_urls', ['user_id'=>$user_id, 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'ack_control_number', 'url'=>100000000, 'status'=>1, 'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s')]);
					}

					$ST_control_number = 0001;
					$platform_url = $this->MainModel->getFirstResultByConditions('platform_urls', ['user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'ST', 'status'=>1], ['id', 'url']);
					if($platform_url)
					{
						$ST_platform_url_id = $platform_url->id;
						$ST_control_number = '000'.$platform_url->url;
					}
					else
					{
						$ST_platform_url_id = $this->MainModel->makeInsertGetId('platform_urls', ['user_id'=>$user_id, 'user_integration_id'=>$user_integration_id, 'platform_id'=>$this->platformId, 'url_name'=>'ST', 'url'=>1, 'status'=>1, 'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s')]);
					}

					$SetTrailer = 0;
					$MicroChipTXT = "997_".$user_integration_id."_".time().".txt";

					//https://www.stedi.com/edi/x12-004010/segment important link

					//$lineISA = "ISA|00(Authorization Information Qualifier(00 No Authorization Information Present (No Meaningful Information in I02), 01 UCS Communications ID, 02 EDX Communications ID, 03 Additional Data Identification, 04 Rail Communications ID, 05 Department of Defense (DoD) Communication Identifier, 06 United States Federal Government Communication Identifier))(FIXED)|          (Ten Spaces(or blanks))|00(Security Information Qualifier(Authorization Information) (00 No Security Information Present (No Meaningful Information in I04) 01 Password))(FIXED)|          (Ten Spaces(or blanks))|01(Interchange Sender ID Qualifier)|123456789      (Interchange Sender ID)|ZZ(Interchange Receiver ID Qualifier)(DYNAMIC)|MCHPPROD       (Interchange Receiver ID)(DYNAMIC)|211209(Date)|2204(Time)|U(Repetition Separator(Interchange Standards ID))(FIXED)|00401(Interchange Control Version Number(https://www.stedi.com/edi/x12-005010/element/I11))|311619613(A control number assigned by the interchange sender)|1(Acknowledgment Requested("0" for no "1" for yes))|P(Usage Indicator("T" for test "P" for Production))|^(Sub_Element Separator(ebcdic, 6e ascii, 3e))~\n";
					//Interchange Control Header
					$plainText = "ISA|00|          |00|          |".$interchange_sender_id_qualifier."|".$interchange_sender_id.$interchange_sender_space."|".$interchange_receiver_id_qualifier."|".$interchange_receiver_id.$interchange_receiver_space."|".date('ymd')."|".date('Hi')."|U|00401|".$control_number."|1|".$usage_indicator."|^~\n";

					//$lineGS = "GS|SH(Functional ID Code)(FIXED)|123456789(Sender ID Code)|MCHPPROD(Receiver ID Code)|20211209(Date)|2204(Time)|8508(Assigned number originated and maintained by the sender)|X(Responsible Agency Code(T = TDCC, X = ANSI X12))(FIXED)|004010(Version/Release/Identifier Code)(FIXED)~\n";
					//Functional Group Header
					$plainText .= "GS|FA|".$interchange_sender_id."|".$interchange_receiver_id."|".date('Ymd')."|".date('Hi')."|".$control_number."|X|004010X098~\n";

					$SetTrailer++;
					//Transaction Set Header
					$plainText .= "ST|997|".$ST_control_number."~\n";

					$SetTrailer++;
					//Functional Group Response Header
					$plainText .= "AK1|PO|".$group_control_number."~\n";

					$SetTrailer++;
					//Transaction Set Response Header
					$plainText .= "AK2|856|".$group_control_number."~\n";

					$SetTrailer++;
					//Transaction Set Response Trailer
					$plainText .= "AK5|A~\n";

					$SetTrailer++;
					//Functional Group Response Trailer
					$plainText .= "AK9|A|1|1|1~\n";

					$SetTrailer++;
					//Transaction Set Trailer
					$plainText .= "SE|".$SetTrailer."|".$ST_control_number."~\n";

					//"GE|1(Number of Transaction Sets)(FIXED)|8508(Group Control Number)~\n";
					//Functional Group Trailer
					$plainText .= "GE|1|".$control_number."~\n";

					//"IEA|1(Number of Include Function Group)(FIXED)|311619613(Interchange Control Number)~\n";
					//Interchange Control Trailer
					$plainText .= "IEA|1|".$control_number."~\n";

					//file_put_contents(storage_path("app/public/MicroChip/".$MicroChipTXT), $plainText);

					$header = array('Authorization: '.$this->MainModel->encrypt_decrypt($mftgateway_platform_account->access_token, 'decrypt'), 'AS2-From: '.$this->MainModel->encrypt_decrypt($mftgateway_platform_account->app_id, 'decrypt'), 'AS2-To: '.$this->MainModel->encrypt_decrypt($platform_account->app_secret, 'decrypt'), 'Subject: '.$as2_subject.' Acknowledgement', 'Attachment-Name: '.$MicroChipTXT, 'Content-Type: text/plain');

					$response = app('App\Http\Controllers\MFTGateway\MFTGatewayApiController')->SendMessage($header, $plainText);
					$result = json_decode($response, true);
					if(isset($result['as2messageId']))
					{
						/*
						if(file_exists(storage_path("app/public/MicroChip/".$MicroChipTXT)))
						{
							File::delete(storage_path("app/public/MicroChip/".$MicroChipTXT));
						}
						*/

						$return_data = true;
					}
					elseif(isset($result['message']))
					{
						$return_data = $result['message'];
					}

					$control_number++;
					$this->MainModel->makeUpdate('platform_urls', ['url'=>$control_number, 'updated_at'=>date('Y-m-d H:i:s')], ['id'=>$platform_url_id]);

					$ST_control_number++;
					$this->MainModel->makeUpdate('platform_urls', ['url'=>$ST_control_number, 'updated_at'=>date('Y-m-d H:i:s')], ['id'=>$ST_platform_url_id]);
				}
			}
			catch(Exception $e)
			{
				Log::error($user_integration_id.' - MicroChipApiController - Acknowledgement - '.$e->getLine().' - '.$e->getMessage());
				$return_data = $e->getMessage();
			}
			return $return_data;
		}

		public function test()
		{
			//$this->CreateShipment(Auth::user()->id, 434, 'cetecerp', 126, 750, 146);
			//$this->Acknowledgement(Auth::user()->id, 488, 100000001);
		}

		/* Execute MicroChip Event Methods */
		public function ExecuteMicroChipEvents($method='', $event='', $destination_platform_id='', $user_id='', $user_integration_id='', $is_initial_sync=0, $user_workflow_rule_id='', $source_platform_id='', $platform_workflow_rule_id='', $record_id='')
		{
			$response = true;
			if($method == 'MUTATE' && $event == 'SHIPMENT')
			{
				$response = $this->CreateShipment($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
			}

			return $response;
		}
	}