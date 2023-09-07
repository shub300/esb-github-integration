<?php

namespace App\Http\Controllers\Amazon;

use App\Http\Controllers\Controller;
use DB;
use Illuminate\Http\Request;
use App\Helper\MainModel;
use App\Helper\WorkflowSnippet;
use App\Helper\Api\AmazonMcfApi;
use App\Helper\Logger;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Models\PlatformCountry;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformProduct;
use App\Models\PlatformStates;
use App\Models\PlatformUrl;
use App\Models\UserIntegration;
use function GuzzleHttp\json_decode;
use Lang;

class AmazonMcfController extends Controller
{
	/**
	 * Create a new controller instance.
	 *
	 * @return void
	 */
	public $mainModel, $workflowSnippet, $connectionHelper, $platformId, $fieldMappingHelper, $log, $logger, $amazonMcfApi;
	public static $my_platform_name = 'amazonmcf';
	public function __construct()
	{
		$this->workflowSnippet = new WorkflowSnippet();
		$this->mainModel = new MainModel();
		$this->amazonMcfApi = new AmazonMcfApi();
		$this->logger = new Logger();
		$this->fieldMappingHelper = new FieldMappingHelper();
		$this->connectionHelper = new ConnectionHelper();
		$this->platformId = $this->connectionHelper->getPlatformIdByName(self::$my_platform_name);
	}

	public function initiateAmazonAuth(Request $request)
	{
		$platform = self::$my_platform_name;
		return view('pages.apiauth.amazon_auth', compact('platform'));
	}

	/* Product Identity Mapping */
	public function ProductIdentityMapping($userIntegrationId, $PlatformWorkFlowRuleID)
	{
		$product_identity_obj_id = $this->connectionHelper->getObjectId('product_identity');
		$mapping_data = $this->fieldMappingHelper->getMappedField($userIntegrationId, $PlatformWorkFlowRuleID, $product_identity_obj_id);

		$source_row_data = $destination_row_data = 'sku';
		if ($mapping_data) {
			if ($mapping_data['destination_platform_id'] == self::$my_platform_name) {
				$destination_row_data = $mapping_data['destination_row_data'];
				$source_row_data = $mapping_data['source_row_data'];
			} else {
				$destination_row_data = $mapping_data['source_row_data'];
				$source_row_data = $mapping_data['destination_row_data'];
			}
		}

		return ['source_identity' => $source_row_data, 'destination_identity' => $destination_row_data];
	}

	private function removeNonASCII($remove, $replace, $string)
	{
		return str_replace($remove, $replace, $string);
	}

	/* Get address from address table*/
	public function GetAddress($addressType, $platform_order_id)
	{
		/* Find Address */
		$find = PlatformOrderAddress::where(['platform_order_id' => $platform_order_id, 'address_type' => $addressType])->select('address_name', 'address1', 'address2', 'address3', 'address4', 'city', 'state', 'postal_code', 'country', 'phone_number', 'firstname', 'lastname', 'ship_speed', 'email', 'company')->first();
		if ($find) {
			$stateName = isset($find->address4) ? $find->address4 : '';
			$countryName = isset($find->country) ? $find->country : '';
			if ($stateName) {
				$FindState = PlatformStates::select('iso2')->where(function ($query) use ($stateName) {
					$query->where('name', '=', $stateName)->orWhere('iso2', '=', $stateName);
				})->first();
				$state = isset($FindState->iso2) ? $FindState->iso2 : $stateName;
			} else {
				$state = $stateName;
			}

			if ($countryName) {
				$FindCountry = PlatformCountry::select('iso')->where(function ($query) use ($countryName) {
					$query->where('name', '=', $countryName)->orWhere('iso', '=', $countryName);
				})->first();
				$country = isset($FindCountry->iso) ? $FindCountry->iso : $countryName;
			} else {
				$country = $countryName;
			}

			$address_name = isset($find->address_name) ? $find->address_name : null;
			$company_name = isset($find->company) && !empty($find->company) ? $find->company : $address_name;
			$address1 = isset($find->address1) ? $find->address1 : null;
			$address2 = isset($find->address2) ? $find->address2 : null;
			$city = isset($find->address3) ? $find->address3 : null;

			$zip = isset($find->postal_code) ? $find->postal_code : null;

			$telephone = isset($find->phone_number) ? $find->phone_number : null;
			$email = isset($find->email) ? $find->email : null;

			return [
				'name' => $this->removeNonASCII("’", "'", $address_name), //required
				'addressLine1' => $this->removeNonASCII("’", "'", $address1), //required
				'addressLine2' => $this->removeNonASCII("’", "'", $address2), //optional
				//'addressLine3'=>$shipping_address->address3, //optional
				'city' => $this->removeNonASCII("’", "'", $city), //optional
				//'districtOrCounty'=>$shipping_address->address4, //optional
				'stateOrRegion' => $this->removeNonASCII("’", "'", $state), //required
				'postalCode' => $zip, //optional
				'countryCode' => $country, //required
				'phone' => $telephone //optional
			];
		}

		return false;
	}

	/* Prepare Order Lines */
	public function PrepareOrderLine($orderLines, $userID, $userIntegrationId, $SourcePlatformId, $product_identifier, $source_platform_name, $parentIntgId = null)
	{
		$items = [];
		$productNotFound = false;
		if ($orderLines) {
			$userIntegrationId = $parentIntgId ? $parentIntgId : $userIntegrationId;
			foreach ($orderLines as $key => $val) {
				$q = PlatformProduct::select($product_identifier)->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $SourcePlatformId, 'api_product_id' => $val->product_id, 'is_deleted' => 0]);
				$bp_product = $q->pluck($product_identifier)->first(); //Find Source Product by product_id

				if ($bp_product) {
					array_push($items, ['sellerSku' => $bp_product, 'sellerFulfillmentOrderItemId' => $val->id, 'quantity' => (int) $val->quantity]);
				} else {
					$productNotFound = true;
				}
			}
		}

		return ['items' => $items, 'productNotFound' => $productNotFound];
	}

	/* Handle Errors */
	public function handleErrorResponse($response)
	{
		$errors_list = [];

		if (isset($response['errors']) && is_array($response['errors']) && count($response['errors'])) {
			foreach ($response['errors'] as $error) {
				$errors_list[] = $error['message'];
			}
		}
		return implode(', ', $errors_list);
	}

	//Create Fulfillment Orders
	public function createFulfillmentOrders($user_id = NULL, $user_integration_id = NULL, $user_workflow_rule_id = NULL, $source_platform_name = NULL, $destination_platform_name = NULL, $platform_workflow_rule_id = NULL, $record_id = NULL)
	{
		$return_response = false;
		try {
			$recordExist = 0;
			$limit = 100;
			$log_time = time();
			$platform_account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			$platform_api_app = $this->mainModel->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->platformId], ['access_key', 'secret_key', 'role_arn']);
			if ($platform_account && $platform_api_app) {
				$source_platform_id = $this->connectionHelper->getPlatformIdByName($source_platform_name);

				$query = PlatformOrder::with(['platformOrderAddress', 'getShipmentReadyAndFailed'])->select('id', 'api_order_id', 'order_number', 'order_date', 'notes', 'linked_id', 'attempt');
				if ($record_id) {
					$query->where('id', $record_id);
				} else {
					$query->where(['platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id, 'sync_status' => 'Ready', 'order_type' => 'SO']);
				}
				$platform_orders = $query->where('is_deleted', 0)->orderBy('updated_at', 'ASC')->take($limit)->get();

				$parentIntgId = null;

				if (count($platform_orders)) {
					$recordExist = 1;

					$object_id = $this->connectionHelper->getObjectId('sales_order');

					/* Find Product Identity Mapping */
					$mapping_data = $this->ProductIdentityMapping($user_integration_id, null);
					$source_row_data = $mapping_data['source_identity'];

					\Storage::disk('local')->append('amz_mcf.txt', 'AmazonFulfillmentOrderLogEnd time: ' . $log_time . ' limit: ' . $limit . ' productCount: ' . count($platform_orders) . ' datetime: ' . date('Y-m-d H:i:s'));

					$accessControl = app('App\Utility\PlatformConfig')->accessControl($source_platform_name, $destination_platform_name);
					if ($accessControl['status'] == true && $accessControl['action'] == 'share') {
						$parentIntgId = UserIntegration::where('id', $user_integration_id)->pluck('parent_integration_id')->first();
					}

					foreach ($platform_orders as $platform_order) {
						if ($platform_order->linked_id == 0) {
							$shipment = isset($platform_order->getShipmentReadyAndFailed) ? $platform_order->getShipmentReadyAndFailed : null;
							if ($shipment) {
								$error_message = NULL;

								$shipmentId = $shipment->id;
								$shipping_method = $shipment->shipping_method;

								$destinationAddress = $this->GetAddress('shipping', $platform_order->id);
								if (is_array($destinationAddress)) {
									$orderLines = PlatformOrderShipmentLine::where('platform_order_shipment_id', $shipmentId)->get();
									if (count($orderLines)) {
										$orderLines = $this->PrepareOrderLine($orderLines, $user_id, $user_integration_id, $source_platform_id, $source_row_data, $source_platform_name, $parentIntgId);
										if (count($orderLines['items']) > 0) {
											if ($orderLines['productNotFound']) {
												$error_message = 'Some line item product identifier are not found in this order.';
											} else {
												$shippingSpeedCategory = NULL;
												$order_shipping_method = $this->mainModel->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'platform_object_id' => $this->connectionHelper->getObjectId('shipping_method'), 'name' => $shipping_method, 'status' => 1], ['api_id']);
												if ($order_shipping_method) {
													$order_shipping = $this->fieldMappingHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, 'sorder_shipping_method', ['api_id'], 'cross', $order_shipping_method->api_id);
													if ($order_shipping) {
														$shippingSpeedCategory = $order_shipping->api_id;
													}
												} else {
													$order_shipping = $this->fieldMappingHelper->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, 'sorder_shipping_method', ['api_id'], 'cross', $shipping_method);
													if ($order_shipping) {
														$shippingSpeedCategory = $order_shipping->api_id;
													}
												}

												if ($shippingSpeedCategory) {
													$order_number = str_replace("/", "-", $platform_order->order_number);

													$CreateFulfillmentOrderRequestData = array(
														'marketplaceId' => $this->mainModel->encrypt_decrypt($platform_account->marketplace_id, 'decrypt'), //optional
														'sellerFulfillmentOrderId' => $order_number, //required
														'displayableOrderId' => $order_number, //required
														'displayableOrderDate' => date('Y-m-d\TH:i:s\Z', strtotime($platform_order->order_date)), //required
														'displayableOrderComment' => 'Order Number: ' . $order_number, //required
														'shippingSpeedCategory' => $shippingSpeedCategory, //required Standard, Expedited, Priority, ScheduledDelivery
														'destinationAddress' => $destinationAddress,
														//'fulfillmentAction'=>'Hold', //optional Ship:The fulfillment order ships now. Hold: An order hold is put on the fulfillment order.
														//'fulfillmentPolicy'=>'FillOrKill', //optional FillOrKill: If an item in a fulfillment order is determined to be unfulfillable before any shipment in the order has acquired the status of Pending (the process of picking units from inventory has begun), then the entire order is considered unfulfillable. However, if an item in a fulfillment order is determined to be unfulfillable after a shipment in the order has acquired the status of Pending, Amazon cancels as much of the fulfillment order as possible. See the FulfillmentShipment object for shipment status definitions. FillAll: All fulfillable items in the fulfillment order are shipped. The fulfillment order remains in a processing state until all items are either shipped by Amazon or cancelled by the seller. FillAllAvailable: All fulfillable items in the fulfillment order are shipped. All unfulfillable items in the order are cancelled.
														'items' => $orderLines['items']
													);

													$result = $this->amazonMcfApi->CreateFulfillmentOrder($platform_account, $platform_api_app, $CreateFulfillmentOrderRequestData);
													if (is_array($result) && count($result) == 0) {
														$OrderLinked = $this->mainModel->makeInsertGetId('platform_order', ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_type' => 'SO', 'api_order_id' => $platform_order->id, 'order_date' => date('Y-m-d H:i:s'), 'order_number' => $order_number, 'sync_status' => 'Pending', 'linked_id' => $platform_order->id, 'shipment_status' => 'Pending', 'order_updated_at' => date('Y-m-d H:i:s')]);

														$platform_order->sync_status = 'Synced';
														$platform_order->linked_id = $OrderLinked;
														$platform_order->order_updated_at = date('Y-m-d H:i:s');
														$platform_order->save();

														/* Shipment Table sync status updated */
														PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => 'Synced']);
														$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $platform_order->id, null);
														$return_response = true;
													} elseif (is_array($result) && isset($result['errors']) && count($result['errors'])) {
														//when no inventory available for some selected items
														//No inventory available for Items.SellerFulfillmentOrderItemId: 2767891.
														$error_message = $this->handleErrorResponse($result);
														if (strpos($error_message, 'No inventory available for Items') !== false) {
															$OrderLinked = $this->mainModel->makeInsertGetId('platform_order', ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_type' => 'SO', 'api_order_id' => $platform_order->id, 'order_date' => date('Y-m-d H:i:s'), 'order_number' => $order_number, 'order_status' => 'Cancelled', 'notes' => $error_message, 'sync_status' => 'Ready', 'linked_id' => $platform_order->id, 'is_voided' => 1, 'shipment_status' => 'Pending', 'order_updated_at' => date('Y-m-d H:i:s')]);

															$platform_order->sync_status = 'Failed';
															$platform_order->linked_id = $OrderLinked;
															$platform_order->order_updated_at = date('Y-m-d H:i:s');
															$platform_order->save();

															/* Shipment Table sync status updated */
															PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => 'Failed']);
															$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order->id, $error_message);
														}
													} else {
														$error_message = $result;
													}
												} else {
													$error_message = 'Amazon shipping speed category is required.';
												}
											}
										} else {
											$error_message = 'No line items are not found in this order.';
										}
									} else {
										$error_message = 'No line items are not found in this order.';
									}
								} else {
									if ($platform_order->attempt == 0) {
										PlatformOrderShipment::where('id', $shipmentId)
											->update(['sync_status' => 'Pending']);
										$platform_order->attempt = 1;
										$platform_order->save();

										$return_response = 'Please wait, we are try to reprocess this record.';

										continue;
									}

									$error_message = 'Customer shipping address may be not found.';
								}

								if ($error_message) {
									/* Proceed to failed order */
									$platform_order->sync_status = 'Failed';
									$platform_order->order_updated_at = date('Y-m-d H:i:s');
									$platform_order->save();

									/* Shipment Table sync status updated */
									PlatformOrderShipment::where('id', $shipmentId)->update(['sync_status' => 'Failed']);
									$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order->id, $error_message);

									$return_response = $error_message;
								}
							} else {
								$platform_order->sync_status = 'Synced';
								$platform_order->save();
								$return_response = true;
							}
						} else {
							if ($platform_order->notes) {
								/* Proceed to failed order */
								$platform_order->sync_status = 'Failed';
								$platform_order->order_updated_at = date('Y-m-d H:i:s');
								$platform_order->save();

								$return_response = $platform_order->notes;
							} else {
								$platform_order->sync_status = 'Synced';
								$platform_order->save();
								$return_response = true;
							}
						}
					}

					\Storage::disk('local')->append('amz_mcf.txt', 'AmazonFulfillmentOrderLogEnd time: ' . $log_time . ' limit: ' . $limit . ' productCount: ' . count($platform_orders) . ' datetime: ' . date('Y-m-d H:i:s'));
				}

				if ($recordExist == 0) {
					$return_response = 'Record not exist.';
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' -> AmazonMcfController -> createFulfillmentOrders -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	//Get Fulfillment Order Status Information 
	public function checkFulfillmentOrderStatus($user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync)
	{
		$returnstatus = true;
		try {
			if ($is_initial_sync) {
				return $returnstatus;
			} else {
				$loop_breaker = 5;

				$platform_account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
				$platform_api_app = $this->mainModel->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->platformId], ['access_key', 'secret_key', 'role_arn']);
				if ($platform_account && $platform_api_app) {
					$url_with_page = PlatformUrl::select('id', 'url')->where(['url_name' => 'amazon_mfc_order_filter_date', 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'status' => 1])->first();
					if ($url_with_page) {
						$queryStartDate = date('Y-m-d\TH:i:s\Z', strtotime($url_with_page->url));
					} else {
						$queryStartDate = date('Y-m-d\TH:i:s\Z', strtotime('-1 day'));

						$user_workflow_rule = $this->workflowSnippet->getWorkflowEvents($user_workflow_rule_id);
						if ($user_workflow_rule && $user_workflow_rule->sync_start_date) {
							$queryStartDate = date('Y-m-d\TH:i:s\Z', strtotime(trim($user_workflow_rule->sync_start_date)));
						}

						$url_with_page = PlatformUrl::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url' => $queryStartDate, 'url_name' => 'amazon_mfc_order_filter_date', 'status' => 1]);
					}

					$nextToken = NULL;
					$loop = true;
					$loop_counter = 1;

					while ($loop) {
						$loop = false;

						$result = $this->amazonMcfApi->GetAllFulfillmentOrders($platform_account, $platform_api_app, $queryStartDate, $nextToken);

						if (isset($result['payload']['fulfillmentOrders'][0]['sellerFulfillmentOrderId']) && count($result['payload']['fulfillmentOrders'])) {
							$loop = true;
							foreach ($result['payload']['fulfillmentOrders'] as $fulfillmentOrder) {
								//check only cancelled order status fulfillment
								if ($fulfillmentOrder['fulfillmentOrderStatus'] == 'Cancelled') {
									//Select order synced in Amazon MCF 
									$platform_order = PlatformOrder::select('id', 'order_status')->where(['order_number' => $fulfillmentOrder['sellerFulfillmentOrderId'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->first();
									if ($platform_order && $platform_order->order_status != $fulfillmentOrder['fulfillmentOrderStatus']) {
										PlatformOrder::where('id', $platform_order->id)
											->update(['order_status' => $fulfillmentOrder['fulfillmentOrderStatus'], 'sync_status' => 'Ready', 'order_updated_at' => date('Y-m-d H:i:s'), 'api_updated_at' => $fulfillmentOrder['statusUpdatedDate'], 'is_voided' => 1]);
									}
								}

								$returnstatus = true;
								$queryStartDate = $fulfillmentOrder['statusUpdatedDate'];

								if ($url_with_page) {
									//Update last order fetch created time
									$url_with_page->url = $queryStartDate;
									$url_with_page->save();
								}
							}

							if (isset($result['payload']['nextToken']) && $result['payload']['nextToken']) {
								$nextToken = $result['payload']['nextToken'];
							} else {
								$loop = false;
							}
						} else {
							$loop = false;
						}

						if ($loop_counter == $loop_breaker) {
							$loop = false;
						}
						$loop_counter++;
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' -> AmazonMcfController -> checkFulfillmentOrderStatus -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			$returnstatus = $e->getMessage();
		}
		return $returnstatus;
	}

	//Get Fulfillment Order Shipment Information 
	public function getFulfillmentOrderShipmentDetails($user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync)
	{
		$returnstatus = true;
		try {
			if ($is_initial_sync) {
				return $returnstatus;
			} else {
				$loop_breaker = 5;

				$platform_account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
				$platform_api_app = $this->mainModel->getFirstResultByConditions('platform_api_app', ['platform_id' => $this->platformId], ['access_key', 'secret_key', 'role_arn']);
				if ($platform_account && $platform_api_app) {
					$url_with_page = PlatformUrl::select('id', 'url')->where(['url_name' => 'amazon_mfc_shipment_filter_date', 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'status' => 1])->first();
					if ($url_with_page) {
						$queryStartDate = date('Y-m-d\TH:i:s\Z', strtotime($url_with_page->url));
					} else {
						$queryStartDate = date('Y-m-d\TH:i:s\Z', strtotime('-1 day'));

						$user_workflow_rule = $this->workflowSnippet->getWorkflowEvents($user_workflow_rule_id);
						if ($user_workflow_rule && $user_workflow_rule->sync_start_date) {
							$queryStartDate = date('Y-m-d\TH:i:s\Z', strtotime(trim($user_workflow_rule->sync_start_date)));
						}

						$url_with_page = PlatformUrl::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'url' => $queryStartDate, 'url_name' => 'amazon_mfc_shipment_filter_date', 'status' => 1]);
					}

					$nextToken = NULL;
					$loop = true;
					$loop_counter = 1;

					while ($loop) {
						$loop = false;

						$result = $this->amazonMcfApi->GetAllFulfillmentOrders($platform_account, $platform_api_app, $queryStartDate, $nextToken);

						if (isset($result['payload']['fulfillmentOrders'][0]['sellerFulfillmentOrderId']) && count($result['payload']['fulfillmentOrders'])) {
							$loop = true;
							foreach ($result['payload']['fulfillmentOrders'] as $fulfillmentOrder) {
								//check order fulfillment is completed
								if ($fulfillmentOrder['fulfillmentOrderStatus'] == 'Complete') {
									//store only last record tracking information
									$packageNumber = NULL;
									$trackingNumber = NULL;
									$carrierCode = NULL;
									$shipDate = NULL;
									$result1 = $this->amazonMcfApi->GetFulfillmentOrderShipmentDetails($platform_account, $platform_api_app, $fulfillmentOrder['sellerFulfillmentOrderId']);
									if (isset($result1['payload']['fulfillmentShipments'][0]['fulfillmentShipmentPackage'])) {
										foreach ($result1['payload']['fulfillmentShipments'] as $fulfillmentShipment) {
											if (isset($fulfillmentShipment['fulfillmentShipmentPackage'])) {
												foreach ($fulfillmentShipment['fulfillmentShipmentPackage'] as $fulfillmentShipmentPackage) {
													if (isset($fulfillmentShipmentPackage['trackingNumber']) && $fulfillmentShipmentPackage['trackingNumber']) {
														$packageNumber = @$fulfillmentShipmentPackage['packageNumber'];
														$trackingNumber = @$fulfillmentShipmentPackage['trackingNumber'];
														$carrierCode = @$fulfillmentShipmentPackage['carrierCode'];
														$shipDate = @$fulfillmentShipmentPackage['shipDate'];
													}
												}
											}
										}
									}

									//Select order synced in Amazon MCF 
									$platform_order = PlatformOrder::select('id', 'linked_id', 'api_order_id', 'platform_order_shipment_id', 'shipment_status')->where(['order_number' => $fulfillmentOrder['sellerFulfillmentOrderId'], 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId])->first();
									if ($platform_order) {
										//Select the brightpearl inserted shipment or the parent shipment data
										$source_order_shipment = PlatformOrderShipment::select('id', 'linked_id')->where(['user_integration_id' => $user_integration_id, 'platform_order_id' => $platform_order->linked_id])->first();
										if ($source_order_shipment && ($source_order_shipment->linked_id == 0 || $source_order_shipment->linked_id == NULL)) {
											$platform_order_shipment = PlatformOrderShipment::select('id', 'linked_id')->where(['user_integration_id' => $user_integration_id, 'platform_order_id' => $platform_order->id])->first();
											if (is_null($platform_order_shipment)) {
												$newShipment = PlatformOrderShipment::create(['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'shipment_id' => $packageNumber, 'platform_order_id' => $platform_order->id, 'order_id' => $platform_order->api_order_id, 'shipment_status' => 'SHIPPED', 'tracking_info' => $trackingNumber, 'realease_date' => $shipDate, 'carrier_code' => $carrierCode, 'shipping_method' => $carrierCode, 'sync_status' => 'Ready', 'linked_id' => $source_order_shipment->id]);

												$source_order_shipment->linked_id = $newShipment->id;
												$source_order_shipment->save();

												$platform_order->platform_order_shipment_id = $newShipment->id;
												$platform_order->shipment_status = 'Ready';
												$platform_order->shipment_api_status = 'SHIPPED';
												$platform_order->save();

												$returnstatus = true;
											} elseif ($platform_order_shipment->linked_id == 0) {
												$platform_order_shipment->tracking_info = $trackingNumber;
												$platform_order_shipment->carrier_code = $carrierCode;
												$platform_order_shipment->shipping_method = $carrierCode;
												$platform_order_shipment->realease_date = $shipDate;
												$platform_order_shipment->shipment_status = 'SHIPPED';
												$platform_order_shipment->sync_status = 'Ready';
												$platform_order_shipment->save();

												$platform_order->shipment_status = 'Ready';
												$platform_order->shipment_api_status = 'SHIPPED';
												$platform_order->save();

												$returnstatus = true;
											}
										}

										$returnstatus = true;
									}
								}

								$queryStartDate = $fulfillmentOrder['statusUpdatedDate'];

								if ($url_with_page) {
									//Update last order fetch created time
									$url_with_page->url = $queryStartDate;
									$url_with_page->save();
								}
							}

							if (isset($result['payload']['nextToken']) && $result['payload']['nextToken']) {
								$nextToken = $result['payload']['nextToken'];
							} else {
								$loop = false;
							}
						} else {
							$loop = false;
						}

						if ($loop_counter == $loop_breaker) {
							$loop = false;
						}
						$loop_counter++;
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' -> AmazonMcfController -> getFulfillmentOrderShipmentDetails -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			$returnstatus = $e->getMessage();
		}
		return $returnstatus;
	}

	//Execute event for calling function by events
	public function ExecuteEventAmazonMCF($method, $event, $destination_platform_id, $user_id, $user_integration_id, $is_initial_sync, $user_workflow_rule_id, $source_platform_id, $platform_workflow_rule_id, $record_id)
	{
		try {
			$response = true;
			if ($method == 'MUTATE' && $event == 'SALESORDER') {
				$response = $this->createFulfillmentOrders($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $platform_workflow_rule_id, $record_id);
			} elseif ($method == 'GET' && $event == 'CHECKFULFILLMENTORDERSTATUS') {
				$response = $this->checkFulfillmentOrderStatus($user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync);
			} elseif ($method == 'GET' && $event == 'SHIPMENT') {
				$response = $this->getFulfillmentOrderShipmentDetails($user_id, $user_integration_id, $user_workflow_rule_id, $is_initial_sync);
			}
			return $response;
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' -> AmazonMcfController -> ExecuteEventAmazonMCF -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			return $e->getMessage();
		}
	}

	//test amazon apis & function //amazon-mcf-test
	public function test()
	{
		//$response = $this->createFulfillmentOrders(109, 591, 1147, 'brightpearl', 171, 557712);
		//dd($response);
		/*
				$queryStartDate = '2022-10-15T00:00:00Z';
				$platform_account = $this->mainModel->getPlatformAccountByUserIntegration(591, $this->platformId);
				if($platform_account)
				{
				$nextToken = NULL;
				$result = $this->amazonMcfApi->GetAllFulfillmentOrders($platform_account, $queryStartDate, $nextToken);
				echo '<pre>';
				print_r($result);
				}
			*/
	}
}
