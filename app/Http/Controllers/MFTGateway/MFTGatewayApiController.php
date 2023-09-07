<?php
	namespace App\Http\Controllers\MFTGateway;

	use App\Http\Controllers\Controller;
	use Illuminate\Http\Request;
	use App\Helper\ConnectionHelper;
	use App\Helper\MainModel;
	use App\Helper\Api\MFTGatewayApi;
	use App\Models\PlatformAccount;
	use App\Models\PlatformApiApp;
	use App\Models\PlatformOrder;
	use Exception, Log;

	class MFTGatewayApiController extends Controller
	{
		public static $myPlatform = 'mftgateway';

		/**
			* Create a new controller instance.
			*
			* @return void
		*/
		public function __construct()
		{
			$this->MainModel = new MainModel();
			$this->MFTGatewayApi = new MFTGatewayApi();
			$this->ConnectionHelper = new ConnectionHelper();
			$this->platformId = $this->ConnectionHelper->getPlatformIdByName(self::$myPlatform);
		}

		public function ConnectMFTGatewayAuth()
		{
			$return_response = false;
			date_default_timezone_set('UTC');

			try{
				$platform_api_app = PlatformApiApp::select('app_ref', 'client_id', 'client_secret')->where('platform_id', $this->platformId)->first();
				if($platform_api_app)
				{
					$platform_account = PlatformAccount::select('id')->where('user_id', 0)->where('platform_id', $this->platformId)->first();
					if(is_null($platform_account))
					{
						$request_data = array("username"=>$this->MainModel->encrypt_decrypt($platform_api_app->client_id, 'decrypt'), "password"=>$this->MainModel->encrypt_decrypt($platform_api_app->client_secret, 'decrypt')); 
						
						$response = $this->MFTGatewayApi->Authentication(json_encode($request_data));
						$result = json_decode($response, true);
						if(isset($result['api_token']))
						{
							PlatformAccount::create(['account_name'=>'MFT Gateway', 'user_id'=>0, 'platform_id'=>$this->platformId, 'app_id'=> $platform_api_app->app_ref, 'access_token'=>$this->MainModel->encrypt_decrypt($result['api_token'], 'encrypt'), 'refresh_token'=>$this->MainModel->encrypt_decrypt($result['refresh_token'], 'encrypt'), 'expires_in'=>3600, 'token_refresh_time'=>time()]);

							$return_response = true;
						}
						elseif(isset($result['message']))
						{
							$return_response = $result['message'];
						}
						else
						{
							$return_response = 'API Error';
						}
					}
					else
					{
						$request_data = array("username"=>$this->MainModel->encrypt_decrypt($platform_api_app->client_id, 'decrypt'), "password"=>$this->MainModel->encrypt_decrypt($platform_api_app->client_secret, 'decrypt')); 
						
						$response = $this->MFTGatewayApi->Authentication(json_encode($request_data));
						$result = json_decode($response, true);
						if(isset($result['api_token']))
						{
							PlatformAccount::where('id', $platform_account->id)
							->update(['account_name'=>'MFT Gateway', 'app_id'=> $platform_api_app->app_ref, 'access_token'=>$this->MainModel->encrypt_decrypt($result['api_token'], 'encrypt'), 'refresh_token'=>$this->MainModel->encrypt_decrypt($result['refresh_token'], 'encrypt'), 'expires_in'=>3600, 'token_refresh_time'=>time()]);

							$return_response = true;
						}
						elseif(isset($result['message']))
						{
							$return_response = $result['message'];
						}
						else
						{
							$return_response = 'API Error';
						}
					}
				}
				else
				{
					$return_response = 'MFT gateway account details not added.';
				}
			}
			catch(Exception $e)
			{
				Log::error('MFTGatewayApiController - ConnectMFTGatewayAuth - '.$e->getMessage());
				$return_response = $e->getMessage();
			}

			return $return_response;
		}

		/* Refresh token */
		function RefreshToken($platform_account_id)
		{
			$return_response = false;
			date_default_timezone_set('UTC');
			try
			{
				$platform_api_app = PlatformApiApp::select('app_ref', 'client_id', 'client_secret')->where('platform_id', $this->platformId)->first();
				if($platform_api_app)
				{
					$platform_account = PlatformAccount::select('refresh_token')->where('id', $platform_account_id)->where('user_id', 0)->where('platform_id', $this->platformId)->first();
					if($platform_account)
					{
						$request_data = ['username'=>$this->MainModel->encrypt_decrypt($platform_api_app->client_id, 'decrypt'), 'refreshToken'=>$this->MainModel->encrypt_decrypt($platform_account->refresh_token, 'decrypt')];
						
						$response = $this->MFTGatewayApi->RefreshToken(json_encode($request_data));
						$result = json_decode($response, true);
						if(isset($result['api_token']))
						{
							PlatformAccount::where('id', $platform_account_id)
							->update(['app_id'=> $platform_api_app->app_ref, 'access_token'=>$this->MainModel->encrypt_decrypt($result['api_token'], 'encrypt'), 'refresh_token'=>$this->MainModel->encrypt_decrypt($result['refresh_token'], 'encrypt'), 'expires_in'=>3600, 'token_refresh_time'=>time(), 'allow_refresh'=>1]);

							$return_response = true;
						}
						elseif(isset($result['message']))
						{
							$this->ConnectMFTGatewayAuth();
							$return_response = $result['message'];
						}
						else
						{
							$this->ConnectMFTGatewayAuth();
							$return_response = "API Error";
						}
					}
				}
			}
			catch(Exception $e)
			{
				Log::error($platform_account_id.' - MFTGatewayApiController - RefreshToken - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			return $return_response;
		}

		/* Send message station to partner */
		function SendMessage($header_data, $request_data)
		{
			$response = false;
			try
			{
				$response = $this->MFTGatewayApi->SendMessage($header_data, $request_data);
			}
			catch(Exception $e)
			{
				Log::error('MFTGatewayApiController - SendMessage - '.$e->getMessage());
				$response = $e->getMessage();
			}
			return $response;
		}

		/* create partner account */
		function CreatePartner($access_token, $request_data, $service=NULL)
		{
			$response = false;
			try
			{
				$response = $this->MFTGatewayApi->CreatePartner($access_token, $request_data, $service);
			}
			catch(Exception $e)
			{
				Log::error('MFTGatewayApiController - CreatePartner - '.$e->getMessage());
				$response = $e->getMessage();
			}
			return $response;
		}

		/* Receive send order shipment message response in webhook */
		public function ReceiveShipmentResponseWebhook(Request $request)
		{
			$return_response = false;
			try 
			{
				if($request->isMethod('post'))
				{
					$body = $request->getContent();

					//Log::info('MFTGatewayApiController - ReceiveShipmentResponseWebhook - '.$body);

					//{"messageAS2ID": "<16160421683347496@mftgateway.com>", "failureReason":"Received HTTP status code of 422", "eventType": "MESSAGE.SEND.FAILED"}
					//{"to":"support@apiworx.com","messageAS2ID":"<720784109397786.as2@mftgateway.com>","messageSubject":"TEST","partnerAS2ID":"TEST_CON","partnerName":"Test Partner","stationAS2ID":"MCHP_NEW","stationName":"Microchip UAT station","failureReason":"Received HTTP status code of 422","failures":1,"lastAttemptTime":1649915217058,"attachments":["AS2\/files\/MCHP_NEW\/TEST_CON\/outbox\/720784109397786\/EN_856.txt"],"subject":"TEST","bucketName":"mftg-apiworx","tenantName":"apiworx","tenantId":708289069406346,"tenantEmail":"support@apiworx.com","eventType":"MESSAGE.SEND.FAILED"}
					//Send a message from MFT Gateway (MESSAGE.SEND.SUCCESS)
					//Receive message to MFT Gateway (MESSAGE.RECEIVED.SUCCESS)
					//Message send failure (MESSAGE.SEND.FAILED)

					/* Decode Json */
					$result_data = json_decode($body, 1);
					if(isset($result_data['messageAS2ID']) && isset($result_data['eventType']))
					{
						$messageAS2ID = $result_data['messageAS2ID'];

						$platform_order = PlatformOrder::select('id')->where('file_name', $messageAS2ID)->first();
						if($platform_order)
						{
							$object_id = $this->ConnectionHelper->getObjectId('sales_order_shipment');

							if($result_data['eventType'] == 'MESSAGE.SEND.SUCCESS')
							{
								$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Synced'], ['platform_order_id'=>$platform_order->id]);
								$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Synced'], ['id'=>$platform_order->id]);
								$this->MainModel->makeUpdate('sync_logs', ['sync_status'=>'success', 'response'=>'Shipment synced successfully!.'], ['record_id'=>$platform_order->id, 'platform_object_id'=>$object_id]);
							}
							elseif($result_data['eventType'] == 'MESSAGE.RECEIVED.SUCCESS')
							{
								$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Synced'], ['platform_order_id'=>$platform_order->id]);
								$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Synced'], ['id'=>$platform_order->id]);
								$this->MainModel->makeUpdate('sync_logs', ['sync_status'=>'success', 'response'=>'Shipment synced successfully!.'], ['record_id'=>$platform_order->id, 'platform_object_id'=>$object_id]);
							}
							elseif($result_data['eventType'] == 'MESSAGE.SEND.FAILED')
							{
								$this->MainModel->makeUpdate('platform_order_shipments', ['sync_status'=>'Failed'], ['platform_order_id'=>$platform_order->id]);
								$this->MainModel->makeUpdate('platform_order', ['shipment_status'=>'Failed'], ['id'=>$platform_order->id]);
								$this->MainModel->makeUpdate('sync_logs', ['sync_status'=>'failed', 'response'=>@$result_data['failureReason']], ['record_id'=>$platform_order->id, 'platform_object_id'=>$object_id]);
							}
						}
					}
				}
			} 
			catch(Exception $e)
			{
				Log::error('MFTGatewayApiController - ReceiveShipmentResponseWebhook - '.$e->getMessage());
				$return_response = $e->getMessage();
			}
			
			return $return_response;
		}
	}