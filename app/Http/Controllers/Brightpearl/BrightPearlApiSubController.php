<?php

namespace App\Http\Controllers\Brightpearl;

use Auth, DB;

use App\Helper\Logger;
use App\Helper\MainModel;
use Illuminate\Http\Request;
use App\Models\PlatformField;
use App\Models\PlatformOrder;
use Illuminate\Support\Carbon;
use App\Helper\WorkflowSnippet;
use App\Models\PlatformProduct;
use App\Helper\ConnectionHelper;
use App\Models\PlatformCustomer;
use App\Models\PlatformOrderLine;
use App\Helper\Api\BrightpearlApi;
use App\Helper\Api\WoocommerceApi;
use App\Helper\FieldMappingHelper;
use App\Models\PlatformObjectData;
use App\Http\Controllers\Controller;
use App\Models\PlatformOrderRefund;
use App\Models\PlatformOrderShipment;
use App\Models\PlatformOrderTransaction;
use App\Models\PlatformInventoryTrail;
use App\Models\PlatformProductPriceList;
use App\Models\PlatformOrderShipmentLine;
use App\Models\PlatformAccountAdditionalInfo;
use App\Models\PlatformObjectDataAdditionalInformation;
use App\Http\Controllers\Brightpearl\BrightpearlSearchController;
use App\Models\Enum\PlatformRecordType;
use App\Models\PlatformProductOption;
use App\Models\PlatformCustomFieldValue;
use App\Models\PlatformInvoice;
use Illuminate\Support\Facades\Config;
use App\Models\PlatformDataMapping;

class BrightPearlApiSubController extends Controller
{
	/**
	 * Create a new controller instance.
	 *
	 * @return void
	 */
	public $wfsnip, $mobj, $bp, $helper, $platformId, $map, $log, $wc, $bpSearch;
	public static $myPlatform = 'brightpearl';
	public function __construct()
	{
		$this->wfsnip = new WorkflowSnippet();
		$this->mobj = new MainModel();
		$this->wc = new WoocommerceApi();
		$this->bp = new BrightpearlApi;
		$this->log = new Logger();
		$this->map = new FieldMappingHelper();
		$this->helper = new ConnectionHelper;
		$this->bpSearch = new BrightpearlSearchController();
		$this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
	}

	/* Search Customer by ID and if not found Store in DB */
	public function SearchCustomer($user_id, $user_integration_id, $contact_id, $account)
	{
		$find = PlatformCustomer::select('id')->where(['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_customer_id' => $contact_id])->first();
		if ($find) {
			$return = $find->id;
		} else {
			$return = app('App\Http\Controllers\Brightpearl\BrightpearlApiController')->GetAdditionalDetailsOfContact($account, NULL, $contact_id, $user_id, $user_integration_id);
		}
		return $return;
	}


	/* Update Goods Out Note As Final Shipped  | Also You can update GON for shipping method,tracking_info,box etc | Based on last param*/
	public function UpdateGoodOutNote($user_id, $user_integration_id, $order_number, $customer_number, $account, $type, $UpdateGoodsOutNoteData = [], $allowGoodsOutNoteUpdate = false, $shipmentLinkedId = null)
	{
		$return = false;
		$goods_out_note_final_shipment = true;
		$break = explode("/", $order_number);
		if (isset($break[0]) && isset($break[1])) {
			$q = PlatformOrderShipment::select('shipment_id');
			$OrderID = $break[0];
			$shipmentSequenceID = $break[1];
			if ($type == "ReceiveExternalTransfer") {
				$conditions = ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'shipment_id' => $OrderID, 'shipment_sequence_number' => $shipmentSequenceID, 'sync_status' => "Synced"];
			} elseif ($type == "UpdateOrderStatusAndGoodOutNotes") {
				$conditions = ['platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'order_id' => $OrderID, 'shipment_sequence_number' => $shipmentSequenceID, 'sync_status' => "Synced"];
				$allowGoodsOutNoteUpdate = true;
				$goods_out_note_final_shipment = false;
			}
			if ($shipmentLinkedId) {
				$q->where('id', $shipmentLinkedId);
			} else {
				$q->where($conditions);
			}

			$findShipment = $q->first();
			if ($findShipment) {
				$shipmentID = $findShipment->shipment_id;
				if ($allowGoodsOutNoteUpdate) {
					if ($UpdateGoodsOutNoteData) {
						$response = $this->bp->UpdateGoodsOutNote($account, $shipmentID, $UpdateGoodsOutNoteData);
						$goods_out_note_update_response = json_decode($response->getBody(), true);
						if (is_array($goods_out_note_update_response) && count($goods_out_note_update_response) == 0) {
							$goods_out_note_final_shipment = true;
						} else if (isset($goods_out_note_update_response['errors']) && is_array($goods_out_note_update_response['errors'])) {
							$error = $this->bp->handleResponseError($goods_out_note_update_response);
							$return = $error;
							$goods_out_note_final_shipment = false;
							//\Log::error("UIntegration: " . $user_integration_id . " OrderNo: " . $order_number . " ShipmentID: " . $shipmentID . " Method=UpdateGoodsOutNote for final GON tracking & Shi method update" .  $error);
						} else {
							$goods_out_note_final_shipment = false;
						}
					}
				}

				if ($goods_out_note_final_shipment) {
					$ShipmentData = array('events' => array(array('eventCode' => 'SHW', 'occured' => date('Y-m-d\TH:i:s'), 'eventOwnerId' => $customer_number)));
					$response = $this->bp->GoodsOutNoteMarkAsShipped($account, $shipmentID, $ShipmentData);
					$result = json_decode($response->getBody(), true);

					if (is_array($result) && empty($result)) {
						$return = true;
					} elseif (isset($result['errors']) && is_array($result)) {
						$error = $this->bp->handleResponseError($result);
						$return = $error;
					//	\Log::error("UIntegration: " . $user_integration_id . " OrderNo: " . $order_number . " ShipmentID: " . $shipmentID . " Method=UpdateOrderStatusAndGoodOutNote for final GON shipment" .  $error);
					}
				}
			}
		}
		return $return;
	}

	public function CreateOrderShipment($user_id = 0, $user_integration_id = 0, $source_platform_name = '', $platform_workflow_rule_id = 0, $user_workflow_rule_id = 0, $record_id = 0)
	{
		$return_data = true;
		$process_limit = 25;
		try {
			$source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
			$object_id = $this->helper->getObjectId('sales_order_shipment');

			$platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'api_domain', 'account_name', 'app_id', 'app_secret']);
			if ($platform_account) {
				$DefaultOrderWarehouseId = NULL;
				$default_order_warehouse = $this->map->getMappedDataByName($user_integration_id, NULL, "order_warehouse", ['api_id']);
				if ($default_order_warehouse) {
					$DefaultOrderWarehouseId = $default_order_warehouse->api_id;
				}

				$suffix_text = '';
				$suffix_text_record = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "custom_field", ['custom_data'], "default");
				if ($suffix_text_record) {
					$suffix_text = $suffix_text_record->custom_data;
				}

				$arrayStatus = ['Ready', 'Failed'];
				if ($record_id) {
					$arrayStatus[] = 'Ignore';
				}

				$platform_order_shipments = PlatformOrderShipment::select('platform_order_shipments.id', 'platform_order_shipments.platform_order_id', 'platform_order.warehouse_id', 'platform_order.order_number', 'platform_order_shipments.shipping_method', 'platform_order_shipments.tracking_info', 'platform_order_shipments.tracking_url', 'platform_order.shipment_status', 'platform_order_shipments.sync_status', 'platform_order_shipments.created_at')
					->join('platform_order', 'platform_order_shipments.platform_order_id', '=', 'platform_order.id')
					->where(['platform_order_shipments.user_integration_id' => $user_integration_id, 'platform_order_shipments.platform_id' => $source_platform_id, 'platform_order_shipments.user_id' => $user_id])
					->where(function ($query) use ($record_id) {
						if ($record_id) {
							$query->where(['platform_order.id' => $record_id]);
						}
					})
					->where(function ($query) {
						$query->whereNull('platform_order_shipments.linked_id')->orWhere('platform_order_shipments.linked_id', 0);
					})
					->whereIn('platform_order_shipments.sync_status', $arrayStatus)
					->orderByRaw("FIELD(platform_order_shipments.sync_status, 'Ready', 'Failed', 'Ignore')")
					->orderBy('platform_order_shipments.id', 'desc')
					->limit($process_limit)
					->distinct()
					->get();

				foreach ($platform_order_shipments as $platform_order_shipment) {
					$order_number = $platform_order_shipment->order_number;
					if (in_array($source_platform_name, \Config::get('apisettings.FindBrightpearlShipmentSalesOrder'))) {
						$order_number = $platform_order_shipment->order_number . $suffix_text;
					}

					$destination_platform_order = $this->mobj->getFirstResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'linked_id' => $platform_order_shipment->platform_order_id], ['id', 'platform_customer_id', 'api_order_id']);
					if (is_null($destination_platform_order)) {
						$destination_platform_order = $this->mobj->getFirstResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'order_number' => $order_number], ['id', 'platform_customer_id', 'api_order_id']);
					}
					if (is_null($destination_platform_order)) {
						$destination_platform_order = $this->mobj->getFirstResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_order_reference' => $order_number], ['id', 'platform_customer_id', 'api_order_id']);
					}

					if ($destination_platform_order) {
						$platform_customer = $this->mobj->getFirstResultByConditions('platform_customer', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'id' => $destination_platform_order->platform_customer_id], ['api_customer_id']);
						if ($platform_customer) {
							$source_row_data = $destination_row_data = 'sku';

							$product_identity_obj_id = $this->helper->getObjectId('product_identity');
							$mapping_data = $this->map->getMappedField($user_integration_id, NULL, $product_identity_obj_id);
							if ($mapping_data) {
								if ($mapping_data['destination_platform_id'] == 'brightpearl') {
									$destination_row_data = $mapping_data['destination_row_data'];
									$source_row_data = $mapping_data['source_row_data'];
								} else {
									$destination_row_data = $mapping_data['source_row_data'];
									$source_row_data = $mapping_data['destination_row_data'];
								}
							}

							$products = [];
							$shipment_line = [];
							$shipment_sku = [];
							$platform_order_shipment_lines = PlatformOrderShipmentLine::select('sku', 'quantity')->where('platform_order_shipment_id', $platform_order_shipment->id)->whereNotNull('sku')->get();
							foreach ($platform_order_shipment_lines as $platform_order_shipment_line) {
								$shipment_line[$platform_order_shipment_line->sku] = $platform_order_shipment_line->quantity;
								$shipment_sku[] = $platform_order_shipment_line->sku;
							}

							if (count($shipment_sku) > 0) {
								$platform_order_lines = PlatformOrderLine::select('api_order_line_id', 'api_product_id', 'sku')->where('platform_order_id', $destination_platform_order->id)->whereIn('sku', $shipment_sku)->where('row_type', 'ITEM')->whereNotNull('api_order_line_id')->whereNotNull('sku')->get();
								foreach ($platform_order_lines as $platform_order_line) {
									if (in_array($platform_order_line->sku, $shipment_sku)) {
										$products[] = array("productId" => $platform_order_line->api_product_id, "salesOrderRowId" => $platform_order_line->api_order_line_id, "quantity" => $shipment_line[$platform_order_line->sku]);
									}
								}
							} else {
								$platform_order_lines = PlatformOrderLine::select('api_order_line_id', 'api_product_id', 'qty')->where('platform_order_id', $destination_platform_order->id)->where('row_type', 'ITEM')->whereNotNull('api_order_line_id')->whereNotNull($destination_row_data)->get();
								foreach ($platform_order_lines as $platform_order_line) {
									$products[] = array("productId" => $platform_order_line->api_product_id, "salesOrderRowId" => $platform_order_line->api_order_line_id, "quantity" => $platform_order_line->qty);
								}
							}

							if (count($products) > 0) {
								/*----------------Start to find order warehouse----------------*/
								$OrderWarehouseId = NULL;
								$warehouse_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['id' => $platform_order_shipment->warehouse_id, 'status' => 1], ['api_id']);
								if ($warehouse_object_data) {
									$warehouseId = $this->map->getMappedDataByName($user_integration_id, NULL, "order_warehouse", ['api_id'], 'regular', $warehouse_object_data->api_id);
									if ($warehouseId) {
										$OrderWarehouseId = $warehouseId->api_id;
									} else {
										$OrderWarehouseId = $DefaultOrderWarehouseId;
									}
								} else {
									$OrderWarehouseId = $DefaultOrderWarehouseId;
								}
								if ($OrderWarehouseId) {
									$GoodsOutNoteData = array('warehouses' => array(array('releaseDate' => $platform_order_shipment->created_at, 'warehouseId' => $OrderWarehouseId, 'transfer' => false, 'products' => $products)), 'priority' => false, 'labelUri' => $platform_order_shipment->tracking_url);

									$shippingMethodId = NULL;
									$sorder_shipping_method = $this->map->getMappedDataByName($user_integration_id, NULL, "sorder_shipping_method", ['api_id'], 'regular', $platform_order_shipment->shipping_method);
									if ($sorder_shipping_method) {
										$shippingMethodId = $sorder_shipping_method->api_id;
									} else {
										$default_sorder_shipping_method = $this->map->getMappedDataByName($user_integration_id, NULL, "sorder_shipping_method", ['api_id']);
										if ($default_sorder_shipping_method) {
											$shippingMethodId = $default_sorder_shipping_method->api_id;
										}
									}

									if ($shippingMethodId) {
										$GoodsOutNoteData['shippingMethodId'] = $shippingMethodId;
									}

									$response = $this->bp->CreateOrderGoodsOutNote($platform_account, $destination_platform_order->api_order_id, $GoodsOutNoteData);
									$result = json_decode($response->getBody(), true);
									if (isset($result['response'][0]) && is_array($result['response'])) {
										$GoodsOutNoteID = (int)$result['response'][0];
										if ($GoodsOutNoteID > 0) {
											$UpdateGoodsOutNoteData = array("shipping" => array("shippingMethodId" => $shippingMethodId, "reference" => $platform_order_shipment->tracking_info));

											$response1 = $this->bp->UpdateGoodsOutNote($platform_account, $GoodsOutNoteID, $UpdateGoodsOutNoteData);
											$result1 = json_decode($response1->getBody(), true);
											if (is_array($result1) && count($result1) == 0) {
												$ShipmentData = array('events' => array(array('eventCode' => 'SHW', 'occured' => date('Y-m-d\TH:i:s'), 'eventOwnerId' => $platform_customer->api_customer_id)));

												$response2 = $this->bp->GoodsOutNoteMarkAsShipped($platform_account, $GoodsOutNoteID, $ShipmentData);
												$result2 = json_decode($response2->getBody(), true);
												if (is_array($result2) && count($result2) == 0) {
													$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Synced', 'linked_id' => 1], ['id' => $platform_order_shipment->id]);

													$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Synced'], ['id' => $platform_order_shipment->platform_order_id]);

													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $platform_order_shipment->platform_order_id, 'Shipment synced successfully!');
												} elseif (isset($result2['response'])) {
													$return_data = $result2['response'];
													$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

													$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->platform_order_id]);

													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, $result2['response']);
												} elseif (isset($result2['errors'][0]['message'])) {
													$return_data = $result2['errors'][0]['message'];
													$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

													$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->platform_order_id]);

													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, $result2['errors'][0]['message']);
												}
											} elseif (isset($result1['response'])) {
												$return_data = $result1['response'];
												$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

												$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->platform_order_id]);

												$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, $result1['response']);
											} elseif (isset($result1['errors'][0]['message'])) {
												$return_data = $result1['errors'][0]['message'];
												$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

												$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->platform_order_id]);

												$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, $result1['errors'][0]['message']);
											}
										} elseif (isset($result['response'])) {
											$return_data = $result['response'];
											$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

											$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->platform_order_id]);

											$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, $result['response']);
										} elseif (isset($result['errors'][0]['message'])) {
											$return_data = $result['errors'][0]['message'];
											$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

											$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->platform_order_id]);

											$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, $result['errors'][0]['message']);
										}
									} elseif (isset($result['response'])) {
										$error = $this->bp->handleResponseError($result);
										$return_data = isset($error) ? $error : "API Error";
										$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

										$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->platform_order_id]);

										$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, $return_data);
									} elseif (isset($result['errors'])) {
										$error = $this->bp->handleResponseError($result);
										$return_data = isset($error) ? $error : "API Error";
										$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

										$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->platform_order_id]);

										$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, $return_data);
									}
								} else {
									$return_data = 'Destination platform sales order goods-out note related default warehouse not mapped.';
									$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

									$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->platform_order_id]);

									$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, 'Destination platform sales order goods-out note related default warehouse not mapped.');
								}
							} else {
								$return_data = 'Destination platform sales order related product not fetched.';
								$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

								$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->platform_order_id]);

								$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, 'Destination platform sales order related product not fetched.');
							}
						} else {
							$return_data = 'Destination platform sales order related customer not fetched.';
							$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

							$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->platform_order_id]);

							$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, 'Destination platform sales order related customer not fetched.');
						}
					} else {
						if (in_array($source_platform_name, \Config::get('apisettings.FindBrightpearlShipmentSalesOrder'))) {
							$orderCreated = $this->FindShipmentOrderByOrderNumberAndStoreNewCreatedOrderDetail($user_id, $user_integration_id, $order_number, $platform_account);
							if ($orderCreated == false) {
								$return_data = 'Destination platform related sales order not fetched.';
								$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Ignore'], ['id' => $platform_order_shipment->id]);

								$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Ignore'], ['id' => $platform_order_shipment->platform_order_id]);

								$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, 'Destination platform related sales order not fetched.');
							}
						} else {
							$return_data = 'Destination platform related sales order not fetched.';
							$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

							$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order_shipment->platform_order_id]);

							$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_order_shipment->platform_order_id, 'Destination platform related sales order not fetched.');
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BrightPearlApiSubController -> CreateOrderShipment -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	/* Get Inventory Trail History */
	public function GetInventoryTrail($user_id = 0, $user_integration_id = 0, $source_platform_name = '', $platform_workflow_rule_id = 0, $user_workflow_rule_id = 0, $record_id = 0)
	{
		$return_data = true;
		try {
			$account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
			if ($account) {
				$goods_note_type_code = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, 'goods_note_type_code', ['api_id'], 'regular', null, 'multiple');
				$actualProductIds = [];
				if (is_array($goods_note_type_code)) {
					if (count($goods_note_type_code) == 7) {
						//unset all array if total mapping count is 7 (we have only 7 type that why added this case)
						unset($goods_note_type_code);
						$goods_note_type_code[] = 'all'; //assign an element which is all for loop
					}

					foreach ($goods_note_type_code as $goodtype) {
						$q = PlatformInventoryTrail::select('id', 'api_updated_at')->where('user_integration_id', $user_integration_id)->where('platform_id', $this->platformId);
						if ($goodtype !== 'all') {
							//check all condition
							$q->where('api_type_code', $goodtype);
							$param = "&goodsNoteTypeCode={$goodtype}"; //which is added to url when good type is not all
						} else {
							$param = ''; //which is added to url when good type is all
						}

						$findLastDatetime = $q->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();
						if ($findLastDatetime) {
							$from_date = urlencode(Carbon::parse($findLastDatetime->api_updated_at)->subSeconds(1)->format('c'));
						} else {
							$from_date = urlencode(Carbon::now()->subMinutes(10)->format('c'));
						}

						$firstResult = 1;
						$nextCall = true;
						$loopCounter = 1;
						$memo = [];
						do {
							$url = "/warehouse-service/goods-movement-search?columns=goodsMovementId,productId,quantity,destinationLocationId,warehouseId,currencyCode,goodsNoteTypeCode,updatedOn{$param}&updatedOn={$from_date}/&sort=updatedOn.ASC&pageSize=500&firstResult={$firstResult}";

							$response = $this->bp->GetInventoryTrail($account, $url);
							if ($result = json_decode($response->getBody(), true)) {
								if (!empty($result) && isset($result['response']['metaData']['morePagesAvailable'])) {
									if (isset($result['response']['results']) && is_array($result['response']['results']) && !empty($result['response']['results'])) {
										$List = [];
										foreach ($result['response']['results'] as $key => $value) {
											$product_Id = NULL;
											if (isset($memo[$value[1]])) {
												$product_Id = $memo[$value[1]];
											} else {
												$find = PlatformProduct::select('id')->where([['user_integration_id', '=', $user_integration_id], ['is_deleted', '=', 0], ['api_product_id', '=', $value[1]]])->first();
												if ($find) {
													$memo[$value[1]] = $find->id;
													$product_Id = $find->id;
												}
											}

											$List = [
												'user_id' => $user_id,
												'platform_id' => $this->platformId,
												'user_integration_id' => $user_integration_id,
												'api_id' => isset($value[0]) ? $value[0] : 0,
												'platform_product_id' => isset($product_Id) ? $product_Id : NULL,
												'api_product_id' => isset($value[1]) ? $value[1] : NULL,
												'api_quantity' => isset($value[2]) ? $value[2] : NULL,
												'api_location_id' => isset($value[3]) ? $value[3] : NULL,
												'api_warehouse_id' => isset($value[4]) ? $value[4] : NULL,
												'api_currency_code' => isset($value[5]) ? $value[5] : NULL,
												'api_type_code' => isset($value[6]) ? $value[6] : NULL,
												'api_updated_at' => isset($value[7]) ? $value[7] : NULL,
											];

											$find = PlatformInventoryTrail::select('id', 'user_id', 'user_integration_id', 'platform_id', 'api_id', 'platform_product_id', 'api_product_id', 'api_quantity', 'api_warehouse_id', 'api_location_id', 'api_currency_code', 'api_type_code', 'api_updated_at', 'sync_status')->where([['user_integration_id', '=', $user_integration_id], ['platform_id', '=', $this->platformId], ['api_id', '=', $value[0]]])->first();
											if ($find) {
												$find->platform_product_id = $product_Id;
												$find->api_product_id = isset($value[1]) ? $value[1] : NULL;
												$find->api_quantity = isset($value[2]) ? $value[2] : NULL;
												$find->api_warehouse_id = isset($value[4]) ? $value[4] : NULL;
												$find->api_location_id = isset($value[3]) ? $value[3] : NULL;
												$find->api_currency_code = isset($value[5]) ? $value[5] : NULL;
												$find->api_updated_at = isset($value[7]) ? $value[7] : NULL;
												$find->api_type_code = isset($value[6]) ? $value[6] : NULL;
												$find->save();
												if ($find->sync_status == 'Ready') {
													$actualProductIds[] = $product_Id;
												}
											} else {
												PlatformInventoryTrail::insert($List);
												$actualProductIds[] = $product_Id;
											}
										}
									}
									$nextCall = $result['response']['metaData']['morePagesAvailable'];
									$firstResult = $firstResult + 500;
								} elseif (!isset($result['response']['results'])) {
									$return_data = $this->bp->handleResponseError($result);
								} elseif (isset($result['errors'])) {
									$return_data = $this->bp->handleResponseError($result);
								}
							}
							$loopCounter++;
						} while ($nextCall && $loopCounter <= 2);
					}

					if (!empty($actualProductIds)) {
						//Reset inventory_sync_status=Ready based on productIds
						$actualProductIds = array_unique($actualProductIds);
						PlatformProduct::whereIn('id', $actualProductIds)->where('inventory_sync_status', '!=', 'Ready')->update(['inventory_sync_status' => 'Ready']);
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . ' --> BrightPearlApiSubController --> GetInventoryTrail --> ' . $e->getLine() . ' --> ' . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	/* Get and check BP order status by ID and update 'Ready' in table */
	public function GetCheckOrderStatus($user_id = 0, $user_integration_id = 0, $sync_status = 'Pending', $initial_sync = true)
	{
		if ($initial_sync) {
			$return_data = true;
		} else {
			$return_data = false;
			try {
				$process_limit = 50;
				$account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
				if ($account && $this->platformId) {
					$platform_orders = PlatformOrder::select('id', 'api_order_id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'sync_status' => $sync_status])->orderBy('updated_at', 'asc')->orderBy('api_order_id', 'asc')->take($process_limit)->get();
					if (count($platform_orders)) {
						$api_order_ids = [];
						$orderArray = [];
						foreach ($platform_orders as $platform_order) {
							$api_order_ids[] = $platform_order->api_order_id;
							$orderArray[$platform_order->api_order_id] = $platform_order->id;
						}

						$api_order_ids = array_unique($api_order_ids);
						sort($api_order_ids); // arrange order id (api_order_id) in asc order

						$orderIDs = implode(',', $api_order_ids); // implode array by comma separated
						$response = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetOrderDetails($orderIDs, $user_integration_id, $account);

						if (isset($response['response']) && !empty($response['response']) && is_array($response['response'])) {
							$matchedPlatformOrderID = $UnmatchedPlatformOrderID = [];
							$mappedOrderStatus = $this->map->getMappedDataByName($user_integration_id, NULL, 'sorder_status_filter', ['api_id'], 'regular', NULL, 'multi', 'source');
							foreach ($response['response'] as $key => $value) {
								if ($mappedOrderStatus) {
									// Check whether there are selected order status
									if (in_array($value['orderStatus']['orderStatusId'], $mappedOrderStatus)) {
										// filter the data according to order status and set ready
										$matchedPlatformOrderID[] = $orderArray[$value['id']];
									} else {
										$UnmatchedPlatformOrderID[] = $orderArray[$value['id']];
									}
								} else {
									/* If user has no mapping for order status checking */
									$UnmatchedPlatformOrderID[] = $orderArray[$value['id']];
								}
							}

							if (count($matchedPlatformOrderID)) {
								//If matched order then set sync_status='Ready'
								PlatformOrder::whereIn('id', $matchedPlatformOrderID)->where('sync_status', '!=', 'Ready')
									->update(['sync_status' => 'Ready']);
							}

							if (count($UnmatchedPlatformOrderID)) {
								//If unmatched order then set sync_status='Pending' || this is need to update because we need updated_at column
								PlatformOrder::whereIn('id', $UnmatchedPlatformOrderID)
									->update(['sync_status' => 'Pending', 'updated_at' => date('Y-m-d H:i:s')]);
							}
							$return_data = true;
						} else {
							$return_data = $this->bp->handleResponseError($response);
						}
					}
				}
			} catch (\Exception $e) {
				\Log::error($user_integration_id . ' -> BrightPearlApiSubController -> GetCheckOrderStatus -> ' . $e->getLine() . ' -> ' . $e->getMessage());
				$return_data = $e->getMessage();
			}
		}

		return $return_data;
	}

	/* Create Purchase Order Goods In Note */
	public function CreatePOGoodsInNote($userId, $userIntegrationId, $PlatformWorkFlowID, $UserWorkFlowRuleID, $SourcePlatformName = NULL, $sync_status = "Ready", $RecordID = NULL)
	{
		$return_response = false;

		try {
			$object_id = $this->helper->getObjectId('goods_in_note');
			$purchase_object_id = $this->helper->getObjectId('purchase_order');

			//pull received complete mapping added by gajendra
			$fullReceivedOrderStatusMap = $this->map->getMappedDataByName($userIntegrationId, NULL, "porder_complete_status", ['api_id']);
			$fullReceivedOrderStatusID = isset($fullReceivedOrderStatusMap->api_id) ? $fullReceivedOrderStatusMap->api_id : null;

			//pull received partial mapping added by gajendra
			$partialReceivedOrderStatusMap = $this->map->getMappedDataByName($userIntegrationId, NULL, "porder_partial_status", ['api_id']);
			$partialReceivedOrderStatusID = isset($partialReceivedOrderStatusMap->api_id) ? $partialReceivedOrderStatusMap->api_id : null;


			$ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
			$SourcePlatformId = $this->helper->getPlatformIdByName($SourcePlatformName); //Get source platform id by their name
			// dd( $ufound, $SourcePlatformId );
			if ($ufound && $SourcePlatformId) {
				if ($RecordID && $RecordID !== 0) {
					$list = PlatformOrderShipment::where(['platform_order_id' => $RecordID, 'type' => PlatformRecordType::POSHIPMENT])->whereIn('sync_status', ['Failed', 'Ready'])->select(['id', 'shipment_id', 'platform_order_id', 'order_id', 'linked_id', 'created_on', 'tracking_info', 'shipping_method', 'carrier_code', 'warehouse_id', 'realease_date'])->orderBy('id', 'asc')->get();
				} else {
					$list = PlatformOrderShipment::where(['user_integration_id' => $userIntegrationId, 'platform_id' => $SourcePlatformId, 'sync_status' => $sync_status, 'type' => PlatformRecordType::POSHIPMENT])->select(['id', 'shipment_id', 'platform_order_id', 'order_id', 'linked_id', 'created_on', 'tracking_info', 'shipping_method', 'carrier_code', 'warehouse_id', 'realease_date'])->orderBy('id', 'asc')->take(25)->get();
				}

				if (!empty($list) && count($list) > 0) {
					$AllowCustomFieldAction = true; //This variable is used to perform custom field action based on integration condition
					$ReadyToUpdateBrightpearlOrderStatus = [];
					if (isset(Config::get('apisettings.RestrictCustomFieldActionInCreateGoodsInNoteInBP')[$SourcePlatformName])) {
						$AllowCustomFieldAction = false;
					}

					//get default base currency
					$default_base_currency_code = NULL;
					if (isset(\Config::get('apisettings.AllowCurrencyExchangeInBP')[$SourcePlatformName])) {
						$accountInformation = $this->mobj->getFirstResultByConditions('platform_account_addtional_information', ['account_id' => $ufound->id, 'user_integration_id' => $userIntegrationId], ['account_currency_code'], ['id' => 'asc']);
						$default_base_currency_code = isset($accountInformation->account_currency_code) ? $accountInformation->account_currency_code : "";
					}
					//end get default base currency

					//make shipment status processing to avoid duplicate
					$selected_shipment_ids = [];
					foreach ($list as $goods) {
						array_push($selected_shipment_ids,$goods->id);
					}
					if($selected_shipment_ids) {
						PlatformOrderShipment::whereIn('id',$selected_shipment_ids)->update(['sync_status' => 'Processing']);
					}
					//end

					//fully synced mark order ids
					$full_synced_mark_order_ids = [];

					foreach ($list as $goods) {
						if ($goods->shipment_id != "final_shipment") { //"final_shipment" value is identify the order to update their status, we received all the item receipt

							$id = $goods->id;
							$order_id = $goods->order_id;
							$platform_order_id = $goods->platform_order_id;
							$created_on = $goods->created_on;

							$warehouseId = $this->map->getMappedDataByName($userIntegrationId, NULL, "order_warehouse", ['api_id'], 'regular', $goods->warehouse_id); //This method is check mapping from source to destination
							if ($warehouseId == false) {
								$warehouseId = $this->map->getMappedDataByName($userIntegrationId, NULL, "order_warehouse", ['api_id'], 'regular', $goods->warehouse_id, 'single', NULL); //This method is check mapping from destination to source
							}

							$warehouse_id = isset($warehouseId) && $warehouseId ? $warehouseId->api_id : NULL;

							//Default warehouse added by gajendra for jasci
							if ($warehouse_id == NULL) {
								/* Find Default Warehouse */
								$warehouseId = $this->map->getMappedDataByName($userIntegrationId, NULL, "inventory_warehouse", ['api_id']);
								$warehouse_id = isset($warehouseId) && $warehouseId ? $warehouseId->api_id : NULL;
							}

							if ($warehouse_id) {
								// Getting Default location of Warehouse
								$responseloc = $this->bp->GetWarehouseDefaultLocation($ufound, $warehouse_id);
								$responseLocation = json_decode($responseloc->getBody(), true);
								$location_id = '';
								if (isset($responseLocation['response'])) {
									$location_id = $responseLocation['response'];
								}

								if ($location_id) {
									$item_list = PlatformOrderShipmentLine::where(['platform_order_shipment_id' => $id])->select(['id', 'row_id', 'product_id', 'sku', 'currency', 'price', 'quantity'])
										//added by gajendra
										->where('sync_status', 'Ready')
										->get();
									$goods_moved = array();
									$ct_items = 0;

									$shipment_line_ids = [];
									foreach ($item_list as $items) {

										$product_currency = $items->currency;
										$product_price = $items->price;

										//if platform exists in AllowCurrencyExchangeInBP
										if (isset(\Config::get('apisettings.AllowCurrencyExchangeInBP')[$SourcePlatformName])) {
											//compare default base currency & order line currency
											if ($default_base_currency_code && ($default_base_currency_code != $items->currency)) {

												//get currency exchange for order line currency & convert it to default base currency
												$response_order_currency = $this->bp->searchCurrency($ufound, $items->currency);
												$response_order_currency = json_decode($response_order_currency->getBody(), true);
												$order_currency_exchange_rate = @$response_order_currency['response']['results'][0][4] ? @$response_order_currency['response']['results'][0][4] : '';

												//calculate currency exchange rate for order currency... 
												$amount_in_base_currency = $items->price / $order_currency_exchange_rate;  // ex.. GBP to USD
												// $amount_in_base_currency = $items->price * $order_currency_exchange_rate; // //for USD to GBP

												$product_currency = $default_base_currency_code;
												$product_price = $amount_in_base_currency;
											}
										}

										//if platform exists in calculate unit price by shipment qty in BP
										if (isset(\Config::get('apisettings.calculateUnitPriceByShipmentQtyInBP')[$SourcePlatformName]) && $items->quantity) {
											$product_price = $product_price / $items->quantity;
										}

										//push shipment line id in array for update
										array_push($shipment_line_ids, $items->id);

										$goods_moved[$ct_items]['productId'] = $items->product_id;
										$goods_moved[$ct_items]['purchaseOrderRowId'] = $items->row_id;
										$goods_moved[$ct_items]['destinationLocationId'] = $location_id;
										$goods_moved[$ct_items]['quantity'] = $items->quantity;
										$goods_moved[$ct_items]['productValue']['currency'] = $product_currency;
										$goods_moved[$ct_items]['productValue']['value'] = $product_price;
										$ct_items++;
									}

									$post_data = array();
									$post_data['transfer'] = false;
									$post_data['warehouseId'] = $warehouse_id;
									$post_data['receivedOn'] = $created_on;
									$post_data['goodsMoved'] = $goods_moved;

									// \Storage::disk('local')->append('Bhoopendra.txt', "\r\n" . "CreatePOGoodsInNote Date -> " . date('Y-m-d H:i:s') .  "userIntegrationId : " . $userIntegrationId . " | post_data : " . json_encode($post_data, true));

									$url = '/warehouse-service/order/' . $order_id . '/goods-note/goods-in';

									$response = $this->bp->CreateGoodInNote($ufound, $url, $post_data, 'json');
									$responseBP = json_decode($response->getBody(), true);

									// \Storage::disk('local')->append('test_order_receive_push.txt', "\r\n" . "CreatePOGoodsInNote Date -> " . date('Y-m-d H:i:s') .  "userIntegrationId : " . $userIntegrationId . " | response : " . $response->getBody());

									if ( isset($responseBP['response']) && is_int($responseBP['response']) ) {
										$return_response = true;

										//Commented on 26-07-2023.. no need to insert destination side 
										// $destinationOrderPid = PlatformOrder::where(['id' => $platform_order_id])->select('linked_id')->pluck('linked_id')->first();
										// $linked_id = PlatformOrderShipment::insertGetId(['user_id' => $userId, 'platform_id' => $this->platformId, 'platform_order_id' => $destinationOrderPid, 'order_id' => $order_id, 'user_integration_id' => $userIntegrationId, 'shipment_id' => $responseBP['response'], 'sync_status' => 'Synced', 'type' => PlatformRecordType::POSHIPMENT, 'linked_id' => $id]);
										// PlatformOrderShipment::where(['id' => $id])->update(['linked_id' => $linked_id, 'sync_status' => 'Synced']);


										PlatformOrderShipment::where(['id' => $id])->update(['sync_status' => 'Synced']);  //'linked_id' => $linked_id, 

										//update shipment line also as synced added by gajendra
										PlatformOrderShipmentLine::whereIn('id', $shipment_line_ids)->update(['sync_status' => 'Synced']);

										$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $this->platformId, $SourcePlatformId, $object_id, 'synced', $platform_order_id, NULL);

										if ($AllowCustomFieldAction) {
											PlatformOrder::where(['id' => $platform_order_id])->update(['shipment_status' => 'Synced']);
											// Update custom fields For PO
											// Future Development -> We need to add condition of mapping rule check to avoid running of custom field sync in every integration
											$product_identity_obj_id = $this->helper->getObjectId('purchase_order');

											$PlatformFields = PlatformField::where(['status' => 1, 'platform_id' => $SourcePlatformId, 'field_type' => 'default', 'platform_object_id' => $product_identity_obj_id])->select(['id'])->get();

											$post_custom_field_data = array();
											$ct_custom = 0;
											foreach ($PlatformFields as $rowfields) {
												$mapping_data = $this->map->getMappedField($userIntegrationId, $UserWorkFlowRuleID, $product_identity_obj_id, [], $rowfields->id);
												if ($mapping_data) {
													if ($mapping_data['destination_platform_id'] == 'brightpearl') {

														if ($mapping_data['destination_custom_field_type'] == 'TEXT' && substr($mapping_data['destination_field_name'], 0, 3) == 'PCF') {
															//custom fields
															$post_custom_field_data[$ct_custom]['op'] = 'add';
															$post_custom_field_data[$ct_custom]['path'] = '/' . $mapping_data['destination_field_name'];
															$post_custom_field_data[$ct_custom]['value'] = ${$mapping_data['source_row_data']};
															$ct_custom++;
														}
													}
												}
											}

											if (count($post_custom_field_data) > 0) {
												// \Storage::disk('local')->append('Bhoopendra.txt', "\r\n" . "CreatePOGoodsInNote Date -> " . date('Y-m-d H:i:s') .  "userIntegrationId : " . $userIntegrationId . " | post_custom_field_data : " . json_encode($post_custom_field_data, true));

												$url = '/order-service/order/' . $order_id . '/custom-field';
												$response = $this->bp->UpdateCustomField($ufound, $url, $post_custom_field_data, 'json');
												$responseBP = json_decode($response->getBody(), true);

												// \Storage::disk('local')->append('Bhoopendra.txt', "\r\n" . "CreatePOGoodsInNote Date -> " . date('Y-m-d H:i:s') .  "userIntegrationId : " . $userIntegrationId . " | response custom field : " . $response->getBody());

												if (isset($responseBP['response'])) {
												} else if (isset($responseBP['errors'])) {

													$error = $this->bp->handleResponseError($responseBP);
													if (empty($error)) {
														$error = "Unexpected, Brightpearl internal error, please resync again";
													}
												} else {
													if (isset($responseBP['response']) && !is_int($responseBP['response'])) {
														$error = $responseBP['response'];
													}
													if (empty($error)) {
														$error = "Unexpected, Brightpearl internal error, please resync again";
													}
												}
											}
										} else {
											if (isset(Config::get('apisettings.RestrictCustomFieldActionInCreateGoodsInNoteInBP')[$SourcePlatformName])) { //this entry is only for infplus account for PO order type
												$findFinalShipmentCount = PlatformOrder::find($platform_order_id); //find final shipment entry in table to identify its fully received and set status on basis of this
												if (isset($findFinalShipmentCount->is_fully_synced) && !$findFinalShipmentCount->is_fully_synced) {
													$findFinalShipmentCount->shipment_status = 'Partial';
													$findFinalShipmentCount->save();
												}
											}
										}

										//start order status update for full received
										$find_order_row = PlatformOrder::find($platform_order_id); //find final shipment entry in table to identify its fully received

										
										//if fullreceivedOrderStatusID mapping exists & is_fully_synced true
										if (isset($find_order_row) && $find_order_row->is_fully_synced == 1 && $fullReceivedOrderStatusID) {
											app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->UpdateOrderStatus($ufound, $order_id, $fullReceivedOrderStatusID);
										} else if (isset($find_order_row) && $find_order_row->is_fully_synced == 0 ) {

											/* check markOrderFullySynced.. for handle jasci complete status update when is_fully_synced not updated */
											if ( isset(\Config::get('apisettings.markOrderFullySynced')[$SourcePlatformName]) ) {

												if(!in_array($platform_order_id, $full_synced_mark_order_ids)) {

													$update_response = $this->markOrderFullySynced($platform_order_id, $find_order_row->linked_id,$userIntegrationId,$SourcePlatformId);
													if($update_response && $fullReceivedOrderStatusID) {

														$update_order_data['is_fully_synced'] = 1;
														$this->mobj->makeUpdate('platform_order', $update_order_data, ['id' => $platform_order_id]);

														app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->UpdateOrderStatus($ufound, $order_id, $fullReceivedOrderStatusID);
														
														//push in $full_synced_mark_order_ids
														array_push($full_synced_mark_order_ids,$platform_order_id);

													} else {
														app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->UpdateOrderStatus($ufound, $order_id, $partialReceivedOrderStatusID);
													}
													
												}
													
											} else {
												app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->UpdateOrderStatus($ufound, $order_id, $partialReceivedOrderStatusID);
											}


										}
										//end

									} else if (isset($responseBP['errors'])) {
										$error = $this->bp->handleResponseError($responseBP);
										if (empty($error)) {
											$error = "Unexpected, Brightpearl internal error, please resync again";
										}

										PlatformOrderShipment::where(['id' => $id])->update(['sync_status' => 'Failed']);
										$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId,  $object_id, 'failed', $platform_order_id, $error);

										PlatformOrder::where(['id' => $platform_order_id])->update(['shipment_status' => 'Failed']);
										if (!$AllowCustomFieldAction) {
											$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId,   $purchase_object_id, 'failed', $platform_order_id, $error);
										}
										$return_response = $error;
									} else {
										
										$error = "Unexpected, Brightpearl internal error, please resync again";
										if (isset($responseBP['response']) && is_string($responseBP['response'])) {
											$error = $responseBP['response'];
										}
										
										if (str_contains($error, 'You have send too many request')) {
											PlatformOrder::where(['id' => $platform_order_id])->update(['shipment_status' => 'Ready']);
										} else {
											$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId,  $object_id, 'failed', $platform_order_id, $error);
											PlatformOrder::where(['id' => $platform_order_id])->update(['shipment_status' => 'Failed']);
											if (!$AllowCustomFieldAction) {
												$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId,   $purchase_object_id, 'failed', $platform_order_id, $error);
											}
										}

										PlatformOrderShipment::where(['id' => $id])->update(['sync_status' => 'Failed']);
									
										$return_response = $error;
									}
								} else {
									PlatformOrderShipment::where(['id' => $id])->update(['sync_status' => 'Failed']);

									$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId,  $object_id, 'failed', $platform_order_id, "Warehouse location not found");
									PlatformOrder::where(['id' => $platform_order_id])->update(['shipment_status' => 'Failed']);
									if (!$AllowCustomFieldAction) {
										$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId,  $purchase_object_id, 'failed', $platform_order_id, "Warehouse location not found");
									}
								}
							} else {

								PlatformOrderShipment::where(['id' => $id])->update(['sync_status' => 'Failed']);

								$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId,  $object_id, 'failed', $platform_order_id, "Warehouse not found");
								PlatformOrder::where(['id' => $platform_order_id])->update(['shipment_status' => 'Failed']);
								if (!$AllowCustomFieldAction) {
									$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID,  $SourcePlatformId, $this->platformId,  $purchase_object_id, 'failed', $platform_order_id, "Warehouse not found");
								}
							}
						} else {
							if (!isset($ReadyToUpdateBrightpearlOrderStatus[$goods->platform_order_id])) {
								$ReadyToUpdateBrightpearlOrderStatus[$goods->platform_order_id] = $goods->id;
							}
						}
					}
					/* This condition is basically to update purchase order status in brightpearl */
					if (!$AllowCustomFieldAction && $ReadyToUpdateBrightpearlOrderStatus) {
						foreach ($ReadyToUpdateBrightpearlOrderStatus as $order_primary_id => $index) {
							$return = app('App\Http\Controllers\Brightpearl\BrightpearlApiSubDivController')->PurchaseOrderStatusUpdate($userId, $userIntegrationId, $PlatformWorkFlowID, $UserWorkFlowRuleID, $SourcePlatformId, $order_primary_id, $ufound);
							if (is_bool($return) && $return == true) {
								PlatformOrderShipment::where(['id' => $index])->update(['sync_status' => 'Synced']);
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "--CreatePOGoodInNote-->" . $e->getMessage());
			$return_response = $e->getMessage();
		}

		return $return_response;
	}

	//markOrderFullySynced
	public function markOrderFullySynced($platform_order_id, $linked_id,$userIntegrationId,$SourcePlatformId)
	{	
		$return_response = false;
		//get total order line qty of bp order
		$total_order_line_qty = 0;
		$order_line_data = DB::table('platform_order_line')->select(DB::raw("SUM(qty) as Quantity"))->where('platform_order_id', $linked_id)->first();
		if ($order_line_data) {
			$total_order_line_qty = $order_line_data->Quantity;
		}

		//Find shipmentIds by order for get total shipment line quantity sum
		$total_shipment_line_qty = 0;
		$list_shipment_ids = PlatformOrderShipment::where('platform_order_id', $platform_order_id)->where('user_integration_id', $userIntegrationId)->where('platform_id', $SourcePlatformId)
		->select('id')->pluck('id')->toArray();
		$shipment_line_data = DB::table('platform_order_shipment_lines')->select(DB::raw("SUM(quantity) as Quantity"))->whereIn('platform_order_shipment_id', $list_shipment_ids)->first();
		if ($shipment_line_data) {
		   $total_shipment_line_qty = $shipment_line_data->Quantity;
		}

		// if total shipment line quantity > = total order line quantity
		if ($total_order_line_qty && $total_shipment_line_qty && $total_shipment_line_qty >= $total_order_line_qty) {
			$return_response = true;
		} 

		return $return_response;

	}



	public function createWarehouseAddress($userId = NULL, $userIntegrationId = NULL, $attempt)
	{
		$returnstatus = true;
		try {
			if ($attempt === 1) {
				$object_id = $this->helper->getObjectId('warehouse');
				$ufound = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
				if ($ufound && $this->platformId) {
					if (isset($ufound->platform_id) && $ufound->platform_id == $this->platformId) {
						$warehousesAddresses = PlatformObjectDataAdditionalInformation::where(['user_integration_id' => $userIntegrationId])->select('api_address_id')->get();
						if ($warehousesAddresses) {
							$warehousesAddressesArr = $warehousesAddresses->toArray();
							$warehousesAddressesIds = array_column($warehousesAddressesArr, 'api_address_id');
							if (count($warehousesAddressesIds) > 0) {
								sort($warehousesAddressesIds);
								$warehousesAddressesIds = implode(",", $warehousesAddressesIds);
								$addressResponse = $this->bp->GetPostalAddress($ufound, $warehousesAddressesIds);
								if ($addressResponse->getBody()) {
									$addressResponse = json_decode($addressResponse->getBody(), true);
									if (isset($addressResponse['response'])) {
										$addressData = $addressResponse['response'];
										if (count($addressData)) {
											foreach ($addressData as $address) {
												$warehouseAddress = PlatformObjectDataAdditionalInformation::where(['user_integration_id' => $userIntegrationId, 'api_address_id' => $address['addressId']])->first();
												if ($warehouseAddress) {
													$data = [
														'address1' => isset($address['addressLine1']) ? $address['addressLine1'] : null,
														'city' => isset($address['addressLine3']) ? $address['addressLine3'] : null,
														'state' => isset($address['addressLine4']) ? $address['addressLine4'] : null,
														'country' => isset($address['countryIsoCode2']) ? $address['countryIsoCode2'] : null,
														'postal_code' => isset($address['postalCode']) ? $address['postalCode'] : null
													];
													$warehouseAddress->update($data);
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
		} catch (\Exception $e) {
			$returnstatus = $e->getMessage();
		}
		return $returnstatus;
	}

	/* Get Brands */
	public function GetBrands($userId = NULL, $userIntegrationId = NULL)
	{
		$return_response = false;
		try {
			$platform_account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'api_domain', 'account_name', 'app_id', 'app_secret']);
			if ($platform_account) {
				$brand_object_id = $this->helper->getObjectId('brand');
				if ($brand_object_id) {
					//update users integration channels status to 0.
					$this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $brand_object_id]);

					$response = $this->bp->GetBrands($platform_account);
					if ($brands = json_decode($response->getBody(), true)) {
						if (isset($brands['response'][0]['id'])) {
							foreach ($brands['response'] as $brand) {
								$brandData = ['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'name' => $brand['name'], 'api_id' => $brand['id'], 'api_code' => $brand['id'], 'status' => 1, 'platform_object_id' => $brand_object_id];

								$platform_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $brand_object_id, 'api_id' => $brand['id']], ['id']);
								if ($platform_object_data) {
									$this->mobj->makeUpdate('platform_object_data', $brandData, ['id' => $platform_object_data->id]);
								} else {
									$this->mobj->makeInsert('platform_object_data', $brandData);
								}
							}
							$return_response = true;
						} else {
							$error = $this->bp->handleResponseError($brands);
							$return_response = isset($error) ? $error : "API Error";
						}
					} else {
						$return_response = "API Error";
					}
				}
			}
		} catch (\Exception $e) {
			//  \Log::error($userIntegrationId . "->BrightPearlApiSubController->GetBrands->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return  $return_response;
	}

	public function CreateOrUpdateProducts($user_id = 0, $user_integration_id = 0, $source_platform_name = '', $platform_workflow_rule_id = 0, $user_workflow_rule_id = 0, $record_id = 0)
	{
		ini_set('serialize_precision', -1);
		$return_data = true;
		$process_limit = 50;

		try {
			if (isset(\Config::get('apifetchlimit.SyncProductInBP')[$source_platform_name])) {
				$process_limit = \Config::get('apifetchlimit.SyncProductInBP')[$source_platform_name];
			}

			$source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
			$object_id = $this->helper->getObjectId('product');
			$brand_object_id = $this->helper->getObjectId('brand');
			$category_object_id = $this->helper->getObjectId('category');

			$platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['id', 'access_token', 'api_domain', 'account_name', 'app_id', 'app_secret']);
			if ($platform_account) {
				$default_product_primary_supplier_field_name = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "default_product_primary_supplier_field_name", ['custom_data']);
				$default_product_supplier_field_name = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "default_product_supplier_field_name", ['custom_data']);

				$checkNewProductExist = PlatformProduct::select('id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'linked_id' => 0, 'product_sync_status' => 'Ready', 'is_deleted' => 0])->first();

				$platform_products = PlatformProduct::select('id', 'api_variant_id', 'inventory_tracking', 'product_name', 'ean', 'sku', 'upc', 'isbn', 'mpn', 'barcode', 'brand_id', 'weight', 'stock_track', 'price', 'product_status', 'description', 'category_id', 'has_variations')
					->where(function ($query) use ($record_id, $user_id, $user_integration_id, $source_platform_id) {
						if ($record_id > 0) {
							$query->where('id', $record_id)->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id]);
						} else {
							$query->where(['product_sync_status' => 'Ready', 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id]);
						}
					});
				if ($checkNewProductExist) {
					$platform_products = $platform_products->orderBy('linked_id', 'asc');
				}
				$platform_products = $platform_products->where('is_deleted', 0)->limit($process_limit)->orderBy('updated_at', 'asc')->get();

				$DefaultProductPriceListId = NULL;
				$default_product_price_list = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "product_pricelist", ['api_id']);
				if ($default_product_price_list) {
					$DefaultProductPriceListId = $default_product_price_list->api_id;
				}

				$DefaultBrandId = NULL;
				/*
						$default_product_brand = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "product_brand", ['api_id']);
						if($default_product_brand)
						{
                        $DefaultBrandId = $default_product_brand->api_id;
						}
					*/
				$default_product_brand_name = $this->map->getMappedDataByName($user_integration_id, NULL, "product_brand", ['custom_data'], "default");
				if ($default_product_brand_name && $default_product_brand_name->custom_data) {
					$default_destination_platform_brand_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $brand_object_id, 'name' => $default_product_brand_name->custom_data, 'status' => 1], ['api_id']);
					if ($default_destination_platform_brand_object_data) {
						$DefaultBrandId = $default_destination_platform_brand_object_data->api_id;
					} else {
						$brandApiId = $this->bpSearch->searchBrand($user_integration_id, $default_product_brand_name->custom_data);
						if (isset($brandApiId['status']) && $brandApiId['status'] === 1) {
							if (isset($brandApiId['data'])) {
								$DefaultBrandId = $brandApiId['data'];
							}
						}
					}
				}

				$DefaultCategoryId = NULL;
				$default_product_category_name = $this->map->getMappedDataByName($user_integration_id, NULL, "product_category", ['custom_data'], "default");
				if ($default_product_category_name && $default_product_category_name->custom_data) {
					$default_destination_platform_category_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $category_object_id, 'name' => $default_product_category_name->custom_data, 'status' => 1], ['api_id']);
					if ($default_destination_platform_category_object_data) {
						$DefaultCategoryId = $default_destination_platform_category_object_data->api_id;
					} else {
						$categoryApiId = $this->bpSearch->searchCategory($user_integration_id, $default_product_category_name->custom_data);
						if (isset($categoryApiId['status']) && $categoryApiId['status'] === 1) {
							if (isset($categoryApiId['data'])) {
								$DefaultCategoryId = $categoryApiId['data'];
							}
						}
					}
				}

				$source_row_data = $destination_row_data = 'sku';
				$product_identity_obj_id = $this->helper->getObjectId('product_identity');
				$mapping_data = $this->map->getMappedField($user_integration_id, NULL, $product_identity_obj_id);
				if ($mapping_data) {
					if ($mapping_data['destination_platform_id'] == 'brightpearl') {
						$destination_row_data = $mapping_data['destination_row_data'];
						$source_row_data = $mapping_data['source_row_data'];
					} else {
						$destination_row_data = $mapping_data['source_row_data'];
						$source_row_data = $mapping_data['destination_row_data'];
					}
				}

				foreach ($platform_products as $platform_product) {
					$categories = [];
					if (trim($platform_product->{$source_row_data})) {
						if (is_numeric($platform_product->weight)) {
							if ($DefaultCategoryId || $platform_product->category_id) {
								if ($DefaultCategoryId) {
									$categories[] = array("categoryCode" => $DefaultCategoryId);
								} elseif ($platform_product->category_id && $category_object_id) {
									$categoryIds = explode(",", $platform_product->category_id);
									if (is_array($categoryIds) && count($categoryIds)) {
										for ($i = 0; $i < count($categoryIds); $i++) {
											$platform_category_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'platform_object_id' => $category_object_id, 'api_id' => $categoryIds[$i], 'status' => 1], ['name']);
											if (is_null($platform_category_object_data)) {
												$platform_category_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'platform_object_id' => $category_object_id, 'name' => $categoryIds[$i], 'status' => 1], ['name']);
											}

											$allowSearchInApiForCategory = 1;
											if ($platform_category_object_data) {
												$destination_platform_category_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $category_object_id, 'name' => $platform_category_object_data->name, 'status' => 1], ['api_id']);
												if ($destination_platform_category_object_data) {
													$allowSearchInApiForCategory = 0;
													$categories[] = array("categoryCode" => $destination_platform_category_object_data->api_id);
												}
											}

											if ($allowSearchInApiForCategory) {
												$searchValue = isset($platform_category_object_data->name) ? $platform_category_object_data->name : $categoryIds[$i];
												$categoryApiId = $this->bpSearch->searchCategory($user_integration_id, $searchValue);
												if (isset($categoryApiId['status']) && $categoryApiId['status'] === 1) {
													if (isset($categoryApiId['data'])) {
														$categories[] = array("categoryCode" => $categoryApiId['data']);
													}
												}
											}

											//pass only single category to create product
											// if(count($categories))
											// {
											// 	break;
											// }
										}
									}
								}

								if ($platform_product->product_name) {
									if (is_array($categories) && count($categories)) {
										$brandId = NULL;
										if ($platform_product->brand_id && $brand_object_id) {
											$platform_brand_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'platform_object_id' => $brand_object_id, 'api_id' => $platform_product->brand_id, 'status' => 1], ['name']);
											if (is_null($platform_brand_object_data)) {
												$platform_brand_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id, 'platform_object_id' => $brand_object_id, 'name' => $platform_product->brand_id, 'status' => 1], ['name']);
											}

											$allowSearchInApiForBrand = 1;
											if ($platform_brand_object_data) {
												$destination_platform_brand_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $brand_object_id, 'name' => $platform_brand_object_data->name, 'status' => 1], ['api_id']);
												if ($destination_platform_brand_object_data) {
													$allowSearchInApiForBrand = 0;
													$brandId = $destination_platform_brand_object_data->api_id;
												}
											}

											if ($allowSearchInApiForBrand) {
												$searchValue = isset($platform_brand_object_data->name) ? $platform_brand_object_data->name : $platform_product->brand_id;
												$brandApiId = $this->bpSearch->searchBrand($user_integration_id, $searchValue);
												if (isset($brandApiId['status']) && $brandApiId['status'] === 1) {
													if (isset($brandApiId['data'])) {
														$brandId = $brandApiId['data'];
													}
												}
											}
										} else {
											$brandId = $DefaultBrandId;
										}

										if ($brandId) {
											$platform_product_detail_attribute = $this->mobj->getFirstResultByConditions('platform_product_detail_attributes', ['platform_product_id' => $platform_product->id], ['fulldescription', 'shortdescription', 'lenght', 'height', 'width', 'volume', 'taxable', 'taxcode_ids', 'product_type_ids', 'language_code']);

											$destination_platform_product = $this->mobj->getFirstResultByConditions('platform_product', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'linked_id' => $platform_product->id, 'is_deleted' => 0], ['id', 'api_product_id']);
											if (is_null($destination_platform_product)) {
												$destination_platform_product = $this->mobj->getFirstResultByConditions('platform_product', ['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, $destination_row_data => $platform_product->{$source_row_data}, 'is_deleted' => 0], ['id', 'api_product_id']);
											}

											$dimensions = $description = $shortDescription = [];
											$dimensions['length'] = 0;
											$dimensions['height'] = 0;
											$dimensions['width'] = 0;
											$dimensions['volume'] = 0;
											if ($platform_product_detail_attribute) {
												//dimensions
												if (trim($platform_product_detail_attribute->lenght)) {
													$dimensions['length'] = $platform_product_detail_attribute->lenght;
												}

												if (trim($platform_product_detail_attribute->height)) {
													$dimensions['height'] = $platform_product_detail_attribute->height;
												}

												if (trim($platform_product_detail_attribute->width)) {
													$dimensions['width'] = $platform_product_detail_attribute->width;
												}

												if (trim($platform_product_detail_attribute->volume)) {
													$dimensions['volume'] = $platform_product_detail_attribute->volume;
												}

												//shortDescription
												if (trim($platform_product_detail_attribute->shortdescription)) {
													$shortDescription['languageCode'] = 'en';
													$shortDescription['text'] = $platform_product_detail_attribute->shortdescription;
													$shortDescription['format'] = 'HTML_FRAGMENT';
												}

												//fulldescription
												if (trim($platform_product_detail_attribute->fulldescription)) {
													$description['languageCode'] = 'en';
													$description['text'] = $platform_product_detail_attribute->fulldescription;
													$description['format'] = 'HTML_FRAGMENT';
												}

												if (trim($platform_product_detail_attribute->language_code)) {
													$shortDescription['languageCode'] = $platform_product_detail_attribute->language_code;
													$description['languageCode'] = $platform_product_detail_attribute->language_code;
												}
											}

											$identity = [];

											if (trim($platform_product->sku)) {
												$identity['sku'] = $platform_product->sku;
											}

											if (trim($platform_product->ean)) {
												$identity['ean'] = $platform_product->ean;
											}

											//Brightpearl allow max 14 character in upc value
											if (trim($platform_product->upc) && strlen($platform_product->upc) <= 14) {
												$identity['upc'] = $platform_product->upc;
											}

											if (trim($platform_product->isbn)) {
												$identity['isbn'] = $platform_product->isbn;
											}

											if (trim($platform_product->mpn)) {
												$identity['mpn'] = $platform_product->mpn;
											}

											if (trim($platform_product->barcode)) {
												$identity['barcode'] = $platform_product->barcode;
											}

											if (trim($platform_product->{$source_row_data})) {
												$identity[$destination_row_data] = $platform_product->{$source_row_data};
											}

											$productData = array(
												"brandId" => $brandId, //Required
												"stock" => array("stockTracked" => ($platform_product->stock_track ? true : false)),
												"salesChannels" => array( //Required
													array(
														"salesChannelName" => "Brightpearl", //Required This specifies the name for the sales channel. Must be "Brightpearl".
														"productName" => mb_convert_encoding(substr($platform_product->product_name, 0, 128), "UTF-8", "UTF-8"), //Required Max 128 characters.
														"productCondition" => "new", //Possible values are: 'new', 'used' and 'refurbished'.
														"categories" => $categories
													)
												)
											);

											$passOptions = true;
											if ($platform_product->inventory_tracking == 'VARIANT' && is_null($platform_product->api_variant_id)) {
												$passOptions = false;
											}

											// Options and Values Data
											$productOptions = PlatformProductOption::where(['platform_product_id' => $platform_product->id, 'status' => 1])->get();
											if ($productOptions && $passOptions) {
												foreach ($productOptions as $productOption) {
													$optionData = $this->bpSearch->searchOptionsAndValuesForProduct($platform_account, $productOption->option_name, $productOption->option_value, $platform_product->linked_id);
													if (is_array($optionData) && count($optionData)) {
														$productData['variations'][] = $optionData;
													}
												}
											}

											//check duplicate variation
											if (isset($productData['variations'])) {
												$isDuplicateAvailable = false;
												$usedVariations = [];
												foreach ($productData['variations'] as $variation) {
													if (!in_array($variation['optionId'], $usedVariations)) {
														$usedVariations[] = $variation['optionId'];
													} else {
														$isDuplicateAvailable = true;
													}
												}

												if ($isDuplicateAvailable) {
													unset($productData['variations']);
												} else {
													//$variations = $productData['variations'];
													//$max4variation = array_chunk($variations, 4);
													//$productData['variations'] = $max4variation[0];
												}
											}

											if (is_array($identity) && count($identity)) {
												$productData["identity"] = $identity;
											}

											if (is_numeric($platform_product->weight)) {
												$productData["stock"]["weight"]["magnitude"] = floatval($platform_product->weight);
											}

											if (is_array($dimensions) && count($dimensions)) {
												$productData["stock"]["dimensions"] = $dimensions;
											}

											if (is_array($shortDescription) && count($shortDescription) == 3) {
												$productData["salesChannels"][0]["shortDescription"] = $shortDescription;
											}

											if (is_array($description) && count($description) == 3) {
												$productData["salesChannels"][0]["description"] = $description;
											} elseif (trim($platform_product->description)) {
												$productData["salesChannels"][0]["description"] = array("languageCode" => "en", "text" => $platform_product->description, "format" => "HTML_FRAGMENT");
											}

											if (is_null($destination_platform_product)) {
												//$productData["salesChannels"][0]["productCondition"] = "new";
												sleep(1);
												$response = $this->bp->CreateProduct($platform_account, $productData);

												$result = json_decode($response->getBody(), true);
												if (isset($result['response'])) {
													$productId = (int)$result['response'];
													if ($productId > 0) {
														$destination_platform_product_data = ['user_id' => $user_id, 'platform_id' => $this->platformId, 'user_integration_id' => $user_integration_id, 'api_product_id' => $productId, 'product_name' => $platform_product->product_name, 'ean' => $platform_product->ean, 'sku' => $platform_product->sku, 'upc' => $platform_product->upc, 'isbn' => $platform_product->isbn, 'mpn' => $platform_product->mpn, 'barcode' => $platform_product->barcode, 'linked_id' => $platform_product->id];

														$linked_id = $this->mobj->makeInsertGetId('platform_product', $destination_platform_product_data);

														if ($DefaultProductPriceListId && $platform_product->price) {
															$priceListData = array("priceLists" => array(array("priceListId" => $DefaultProductPriceListId, "quantityPrice" => array("1" => $platform_product->price), "sku" => $platform_product->sku)));
															$this->bp->UpdateProductPrice($platform_account, $productId, $priceListData);
														} else {
															$this->UpdateProductPriceListing($platform_account, $platform_product->id, $platform_product->sku, $linked_id, $productId, $user_integration_id);
														}

														$this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Synced', 'linked_id' => $linked_id], ['id' => $platform_product->id]);

														$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $platform_product->id, 'Product synced successfully!');

														if ($default_product_primary_supplier_field_name && $default_product_primary_supplier_field_name->custom_data) {
															$this->CreateProductPrimarySupplier($platform_account, $productId, $user_id, $user_integration_id, $platform_product->id, $default_product_primary_supplier_field_name->custom_data);
														}

														if ($default_product_supplier_field_name && $default_product_supplier_field_name->custom_data) {
															$this->CreateProductSupplier($platform_account, $user_id, $user_integration_id, $productId, $platform_product->id, $default_product_supplier_field_name->custom_data);
														}
													} else {
														$return_data = $result['response'];
														if ($result['response'] == 'You have sent too many requests. Please wait before sending another request' && $record_id < 1) {
															sleep(1);
															continue;
														}

														$this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $platform_product->id]);

														$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, $result['response']);
													}
												} elseif (isset($result['errors'][0]['message'])) {
													$return_data = $result['errors'][0]['message'];
													if ($result['errors'][0]['message'] == 'You have sent too many requests. Please wait before sending another request' && $record_id < 1) {
														sleep(1);
														continue;
													}

													$this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $platform_product->id]);

													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, $result['errors'][0]['message']);
												}
											} else {
												sleep(1);
												$response = $this->bp->UpdateProduct($platform_account, $destination_platform_product->api_product_id, $productData);

												$result = json_decode($response->getBody(), true);
												if (is_array($result) && count($result) == 0) {
													if ($DefaultProductPriceListId && $platform_product->price) {
														$priceListData = array("priceLists" => array(array("priceListId" => $DefaultProductPriceListId, "quantityPrice" => array("1" => $platform_product->price), "sku" => $platform_product->sku)));

														$this->bp->UpdateProductPrice($platform_account, $destination_platform_product->api_product_id, $priceListData);
													} else {
														$this->UpdateProductPriceListing($platform_account, $platform_product->id, $platform_product->sku, $destination_platform_product->id, $destination_platform_product->api_product_id, $user_integration_id);
													}

													$this->mobj->makeUpdate('platform_product', ['linked_id' => $platform_product->id], ['id' => $destination_platform_product->id]);
													$this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Synced', 'linked_id' => $destination_platform_product->id], ['id' => $platform_product->id]);
													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $platform_product->id, 'Product synced successfully!');

													if ($default_product_primary_supplier_field_name && $default_product_primary_supplier_field_name->custom_data) {
														$this->CreateProductPrimarySupplier($platform_account, $user_id, $user_integration_id, $destination_platform_product->api_product_id, $platform_product->id, $default_product_primary_supplier_field_name->custom_data);
													}

													if ($default_product_supplier_field_name && $default_product_supplier_field_name->custom_data) {
														$this->CreateProductSupplier($platform_account, $user_id, $user_integration_id, $destination_platform_product->api_product_id, $platform_product->id, $default_product_supplier_field_name->custom_data);
													}
												} elseif (isset($result['response'])) {
													$return_data = $result['response'];
													if ($result['response'] == 'You have sent too many requests. Please wait before sending another request' && $record_id < 1) {
														sleep(1);
														continue;
													}

													$this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $platform_product->id]);
													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, $result['response']);
												} elseif (isset($result['errors'][0]['message'])) {
													$return_data = $result['errors'][0]['message'];
													if ($result['errors'][0]['message'] == 'You have sent too many requests. Please wait before sending another request' && $record_id < 1) {
														sleep(1);
														continue;
													}

													$this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $platform_product->id]);
													$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, $result['errors'][0]['message']);
												}
											}
										} else {
											$return_data = "Product brand not matched";

											$this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $platform_product->id]);
											$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, "Product brand not matched");
										}
									} else {
										$return_data = "Product category not matched";

										$this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $platform_product->id]);
										$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, "Product category not matched");
									}
								} else {
									$return_data = "Product name is required";

									$this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $platform_product->id]);
									$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, $return_data);
								}
							} else {
								$return_data = "Product category is required";

								$this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $platform_product->id]);
								$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, $return_data);
							}
						} else {
							$return_data = "Product weight is required";

							$this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $platform_product->id]);
							$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, $return_data);
						}
					} else {
						$return_data = "Product code/sku is required";

						$this->mobj->makeUpdate('platform_product', ['product_sync_status' => 'Failed'], ['id' => $platform_product->id]);
						$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $platform_product->id, $return_data);
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BrightPearlApiSubController -> CreateOrUpdateProducts -> " . $e->getLine() . " -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	/* Create Product Primary Supplier */
	public function CreateProductPrimarySupplier($platform_account, $user_id, $user_integration_id, $productId, $platform_product_id, $platform_field_name)
	{
		try {
			$platform_custom_field_value = PlatformCustomFieldValue::join('platform_fields', 'platform_custom_field_values.platform_field_id', '=', 'platform_fields.id')
				->select('platform_custom_field_values.field_value')
				->where('platform_custom_field_values.record_id', $platform_product_id)
				->where('platform_fields.name', $platform_field_name)
				->where('platform_custom_field_values.status', 1)
				->first();
			if ($platform_custom_field_value && trim($platform_custom_field_value->field_value)) {
				$supplier_object_id = $this->helper->getObjectId("supplier");
				$platform_object_data = PlatformObjectData::select('api_id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $supplier_object_id, 'name' => trim($platform_custom_field_value->field_value)])->first();
				if ($platform_object_data) {
					$ProductPrimarySupplierData = array("productIds" => array($productId));
					sleep(1);
					$this->bp->PutProductPrimarySupplier($platform_account, $platform_object_data->api_id, $ProductPrimarySupplierData);
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BrightPearlApiSubController -> CreateProductPrimarySupplier -> " . $e->getMessage());
		}
	}

	/* Create Product Supplier */
	public function CreateProductSupplier($platform_account, $user_id, $user_integration_id, $productId, $platform_product_id, $platform_field_name)
	{
		try {
			$platform_custom_field_value = PlatformCustomFieldValue::join('platform_fields', 'platform_custom_field_values.platform_field_id', '=', 'platform_fields.id')
				->select('platform_custom_field_values.field_value')
				->where('platform_custom_field_values.record_id', $platform_product_id)
				->where('platform_fields.name', $platform_field_name)
				->where('platform_custom_field_values.status', 1)
				->first();
			if ($platform_custom_field_value && trim($platform_custom_field_value->field_value)) {
				$supplier_object_id = $this->helper->getObjectId("supplier");

				$suppliers = str_replace(", ", ",", trim($platform_custom_field_value->field_value));

				$supplier_ids = PlatformObjectData::select('api_id')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'platform_object_id' => $supplier_object_id])->whereIn('name', explode(',', $suppliers))->pluck('api_id')->toArray();
				if (count($supplier_ids)) {
					sleep(1);
					$this->bp->PostProductSupplier($platform_account, $productId, $supplier_ids);
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BrightPearlApiSubController -> CreateProductSupplier -> " . $e->getMessage());
		}
	}

	public function SaveTransferedGoodsOutNotes($gon, $gonId, $userId, $userIntegrationId)
	{
		try {
			$brightPearlService = new BrightpearlServices();
			$brightPearlService->saveShipmentsFromGoodsOutNote($gon, $gonId, $userId, $userIntegrationId, $this->platformId);
		} catch (\Exception $ex) {
			\Log::error($userIntegrationId . "->BrightPearlApiSubController->SaveTransferedGoodsOutNotes->" . $ex->getMessage());
			return $ex->getMessage();
		}

		return true;
	}

	/** Get Tags */
	public function GetTags($userId = NULL, $userIntegrationId = NULL)
	{
		$return_response = false;
		try {
			$platform_account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'api_domain', 'account_name', 'app_id', 'app_secret']);
			if ($platform_account) {
				$tag_object_id = $this->helper->getObjectId('tag');
				if ($tag_object_id) {
					//update users integration channels status to 0.
					$this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $tag_object_id]);
					$response = $this->bp->GetTags($platform_account);
					if ($tags = json_decode($response->getBody(), true)) {
						if (isset($tags['response'])) {
							foreach ($tags['response'] as $tag) {
								$tagData = ['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'name' => $tag['tagName'], 'api_id' => $tag['tagId'], 'api_code' => $tag['tagId'], 'status' => 1, 'platform_object_id' => $tag_object_id];

								$platform_object_data = $this->mobj->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $tag_object_id, 'api_id' => $tag['tagId']], ['id']);
								if ($platform_object_data) {
									$this->mobj->makeUpdate('platform_object_data', $tagData, ['id' => $platform_object_data->id]);
								} else {
									$this->mobj->makeInsert('platform_object_data', $tagData);
								}
							}
							$return_response = true;
						} else {
							if (isset($tags['errors']) && is_array($tags['errors'])) {
								$error = $this->bp->handleResponseError($tags);
								$return_response = isset($error) ? $error : "API Error";
							} else {
								$return_response = isset($tags['response']) ? $tags['response'] : "API Error";
							}
						}
					} else {
						$return_response = "API Error";
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "->BrightPearlApiSubController->GetTags->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return  $return_response;
	}

	/** Check Brightpearl customers for tag update, whether tag filter data is updated.
	 * If filter data is updated the check for Inactive customer who match current filter values.
	 * If existing inactive customer satisfy the current tag filter then update its status as "Ready" .*/
	public function GetUpdateCustomerAsTagFilter($user_id = '', $user_integration_id = '')
	{
		$TagFilterData = $this->map->getMappedDataByName($user_integration_id, '', "tag", ['api_id'], 'regular', '', 'multiple'); // Get Tag filter data (if tag filter is applied)

		$page_limit = 200;
		do {
			$flag = true;

			$inactive_customers = DB::table('platform_customer AS cust')
				->leftJoin('platform_customer_additional_information AS cust_adinfo', 'cust_adinfo.platform_customer_id', '=', 'cust.id')
				->select('cust.id', 'cust_adinfo.api_tag_id')
				->where(['cust.user_id' => $user_id, 'cust.user_integration_id' => $user_integration_id, 'cust.platform_id' => $this->platformId, 'cust.sync_status' => 'Inactive'])
				->limit($page_limit)->get();

			if (count($inactive_customers)) {
				foreach ($inactive_customers as $key => $customer) {
					$update_data = [];
					if (count($TagFilterData) && isset($customer->api_tag_id)) {
						$response = app('App\Http\Controllers\Brightpearl\BrightpearlUtility')->CustomerTagFilter($TagFilterData, $customer->api_tag_id);
						if ($response) {
							$update_data['sync_status'] = 'Ready';
						}
					} else if (!$TagFilterData && empty($TagFilterData)) {
						$update_data['sync_status'] = 'Ready';
					}
					// Update customer status from Inactive to Ready
					if (count($update_data)) {
						DB::table('platform_customer')->where(['id' => $customer->id, 'user_integration_id' => $user_integration_id])->update($update_data);
					}
				}

				if (count($inactive_customers) <  $page_limit) {
					$flag = false;
				}
			}
		} while ($flag);
	}

	public function syncCustomerToBP($user_id, $user_integration_id, $platform_workflow_rule_id, $user_workflow_rule_id, $source_platform_name, $sync_status, $record_id)
	{
		$response = true;
		try {
			$object_id = $this->helper->getObjectId('customer');
			$bpaccount = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);

			$source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);

			if ($object_id && $bpaccount && $source_platform_id) {
				$gettimezone =  $this->mobj->getFirstResultByConditions('platform_account_addtional_information', ['account_id' => $bpaccount->id, 'user_integration_id' => $user_integration_id], ['account_timezone']);
				if ($gettimezone) {
					date_default_timezone_set($gettimezone->account_timezone);
				}
				$current_time = date('Y-m-d\TH:i:s', time());
				if ($record_id) {
					$customers = PlatformCustomer::where(['id' => $record_id, 'sync_status' => 'Failed']);
				} else {
					$customers = PlatformCustomer::where(['platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id, 'sync_status' => 'Ready']);
				}
				$customers = $customers->get();
				if ($customers) {
					foreach ($customers as $customer) {
						// customer syncing to brightpearl
						$customerdata = $addressdata = [];
						$addressData['addressLine1'] = $customer->address1;
						$addressData['addressLine2'] = $customer->address2;
						$addressData['addressLine3'] = $customer->address3;
						$addressData['postalCode'] = $customer->postal_addresses;
						$addressData['countryIsoCode'] = $customer->country;

						$response = $this->bp->CreatePostalAddress($bpaccount, null, $addressData);

						if ($address = json_decode($response->getBody(), true)) {
							if (!empty($address) && isset($address['response'])) {
								$customerdata = [
									"firstName" => $customer->first_name,
									"lastName" => $customer->last_name,
									"postAddressIds" => [
										"DEF" => $address['response'],
										"BIL" => $address['response'],
										"DEL" => $address['response'],
									],
									"communication" => [
										"emails" => [
											"PRI" => [
												"email" => $customer->email,
											],
										],
										"telephones" => [
											"PRI" => $customer->phone,
											"FAX" => $customer->fax,
										],
									],
									"relationshipToAccount" => [
										"isSupplier" => false,
										"isStaff" => false,
									],
									"organisation" => [
										"name" => $customer->company_name
									],
								];
								$response = $this->bp->CreateCustomer($bpaccount, NULL, $customerdata);

								if ($customerapi = json_decode($response->getBody(), true)) {

									if (!empty($customerapi) && isset($customerapi['response']) && is_int($customerapi['response'])) {

										$fields = array(
											'api_customer_id' => $customerapi['response'],
											'customer_name' => $customer->customer_name,
											'first_name' => $customer->first_name,
											'last_name' => $customer->last_name,
											'company_name' => $customer->company_name,
											'phone' => $customer->phone,
											'email' => $customer->email,
											'address1' => $customer->address1,
											'address2' => $customer->address2,
											'address3' => $customer->address3,
											'postal_addresses' => $customer->postal_addresses,
											'country' => $customer->country,
											'sync_status' => 'Pending',
											'api_updated_at' => $current_time
										);
										if ($customer->linked_id) {
											$this->mobj->makeUpdate('platform_customer', $fields, ['id' => $customer->linked_id]);
										} else {
											$fields += [
												'user_id' => $user_id,
												'user_integration_id' => $user_integration_id,
												'platform_id' => $bpaccount->platform_id,
												'linked_id' => $customer->id,
											];
											$id = $this->mobj->makeInsertGetId('platform_customer', $fields);
											$customer->linked_id = $id;
										}
										$customer->sync_status = 'Synced';
										$message = 'Customer Synced Successfully.';
										$status = 'success';
									} else {
										$message = (isset($customerapi['errors'][0]['message'])) ? $customerapi['errors'][0]['message'] : 'Customer not Synced.';
										$status = 'failed';
										$customer->sync_status = 'Failed';
									}
									$customer->save();
									$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $bpaccount->platform_id, $object_id, $status, $customer->id, $message);
								}
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			$response = $e->getMessage();
		}
		return $response;
	}

	public function getWarehouseLocation($userId = NULL, $userIntegrationId = NULL)
	{
		$return_response = false;
		try {
			$platform_account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'api_domain', 'account_name', 'app_id', 'app_secret']);
			if ($platform_account) {
				$warehouse_object_id = $this->helper->getObjectId('warehouse');
				$location_object_id = $this->helper->getObjectId('location');
				if ($warehouse_object_id && $location_object_id) {
					//update users integration channels status to 0.
					$this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $location_object_id]);

					$warehouses_api_ids = PlatformObjectData::where(['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $warehouse_object_id, 'status' => 1])->selectRaw('GROUP_CONCAT(api_id) as api_ids')->get();
					if ($warehouses_api_ids) {
						$warehouses_api_ids = explode(',', array_column($warehouses_api_ids->toArray(), 'api_ids')[0]);
						foreach ($warehouses_api_ids as $warehouses_api_id) {
							$response = $this->bp->getWarehouseLocation($platform_account, $warehouses_api_id);
							if ($response) {
								if ($response = json_decode($response->getBody(), true)) {
									if (isset($response['response'])) {
										foreach ($response['response'] as $locationData) {
											$groupA = isset($locationData['groupingA']) ? $locationData['groupingA'] : 0;
											$groupB = isset($locationData['groupingB']) ? $locationData['groupingB'] : 0;
											$groupC = isset($locationData['groupingC']) ? $locationData['groupingC'] : 0;
											$groupD = isset($locationData['groupingD']) ? $locationData['groupingD'] : 0;
											$locationName = $groupA . ((!$groupB && !$groupC && !$groupD) ? '' : ((($groupB) ? '.' . $groupB : '') . ((!$groupC && !$groupD) ? '' : ((!$groupB) ? '.0' : '') . ((($groupC) ? '.' . $groupC : (($groupD) ? '.0.' . $groupD : '')) . (($groupC && $groupD) ? '.' . $groupD : '')))));
											$checkData = [
												'user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'api_id' => $locationData['id'], 'api_code' => $locationData['id'], 'platform_object_id' => $location_object_id
											];

											$location_object_data = PlatformObjectData::where($checkData)->first();
											if ($location_object_data) {
												$location_object_data->name = $locationName;
												$location_object_data->save();
											} else {
												$data = $checkData + ['name' => $locationName];
												PlatformObjectData::create($data);
											}
										}
									}
								}
							}
						}
						$return_response = true;
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "->BrightPearlApiSubController->getWarehouseLocation->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return  $return_response;
	}

	public function UpdateProductPriceListing($bp_account = null, $platform_product_id = 0, $platform_sku = '', $created_product_id = 0, $created_product_api_id = 0, $user_integration_id = 0)
	{
		$response = false;
		try {
			$api_data = [];
			if ($bp_account && $platform_product_id && $created_product_id && $created_product_api_id) {
				$account_currency_code = null;
				$bp_account_additional_info = PlatformAccountAdditionalInfo::where(['account_id' => $bp_account->id, 'user_integration_id' => $user_integration_id])->select('account_currency_code')->first();
				if ($bp_account_additional_info) {
					$account_currency_code = $bp_account_additional_info->account_currency_code;
				}

				$api_data = $this->getMappedPriceListArray($platform_product_id, $user_integration_id, $platform_sku, $created_product_id, $account_currency_code);
				if (is_array($api_data) && isset($api_data['priceLists']) && is_array($api_data['priceLists']) && count($api_data['priceLists'])) {
					$api_response = $this->bp->UpdateProductPrice($bp_account, $created_product_api_id, $api_data);
					if ($api_response) {
						if (count(json_decode($api_response->getBody(), true)) == 0) {
							$response = true;
						}
					}
				}
			}
		} catch (\Exception $e) {
			$response = $e->getMessage();
		}
		return $response;
	}

	public function getMappedPriceListArray($platform_product_id, $user_integration_id, $platform_sku, $created_product_id, $account_currency_code)
	{
		$response = [];
		$pricelists = PlatformProductPriceList::select('platform_object_data_id', 'price', 'api_currency_code')->where(['platform_product_id' => $platform_product_id, 'status' => 1])->distinct()->get();
		if ($pricelists) {
			$response = ['priceLists' => []];
			foreach ($pricelists as $pricelist) {
				$priceObject = PlatformObjectData::find($pricelist->platform_object_data_id);

				if ($priceObject) {
					$result = $this->map->getMappedDataByName($user_integration_id, null, "product_pricelist", ['api_id', 'id'],  "regular", $priceObject->api_id, "single", "destination");
					if ($result) {
						$created_product_price = PlatformProductPriceList::where(['platform_product_id' => $created_product_id, 'platform_object_data_id' => $result->id])->first();
						if ($created_product_price) {
							$created_product_price->price = $pricelist->price;
							$created_product_price->api_currency_code = $account_currency_code;
							$created_product_price->save();
						} else {
							PlatformProductPriceList::create(['platform_product_id' => $created_product_id, 'platform_object_data_id' => $result->id, 'price' => $pricelist->price, 'api_currency_code' => $account_currency_code]);
						}

						$response['priceLists'][] = ['priceListId' => $result->api_id, 'quantityPrice' => ['1' => $pricelist->price], 'sku' => $platform_sku];
					}
				}
			}
		}
		return $response;
	}

	/* Find Shipment Order By Order Number And Store New Created Order Detail */
	public function FindShipmentOrderByOrderNumberAndStoreNewCreatedOrderDetail($user_id, $user_integration_id, $reference, $platform_account)
	{
		$return_response = false;
		try {
			$url = "order-search?orderTypeId=1&customerRef=" . rawurlencode($reference);
			$response = $this->bp->GetOrder($platform_account, $url, NULL, "search");
			if ($result = json_decode($response->getBody(), true)) {
				if (isset($result['response']['results'][0][0])) {
					$api_order_id = $result['response']['results'][0][0];

					$response1 = $this->bp->GetOrder($platform_account, NULL, $api_order_id, "normal");
					if ($orders = json_decode($response1->getBody(), true)) {
						if (isset($orders['response'][0]['id'])) {
							foreach ($orders['response'] as $order) {
								$platform_customer_id = NULL;
								$customer = $order['parties']['customer'];
								if (is_array($customer)) {
									/** save customer details */
									$customerData = array('user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'api_customer_id' => $customer['contactId'], 'customer_name' => @$customer['addressFullName'], 'company_name' => @$customer['companyName'],  'phone' => ((@$customer['telephone']) ? @$customer['telephone'] : @$customer['mobileTelephone']), 'email' => @$customer['email'], 'address1' => @$customer['addressLine1'], 'address2' => @$customer['addressLine2'], 'address3' => @$customer['addressLine3'], 'postal_addresses' => @$customer['postalCode'], 'country' => @$customer['countryIsoCode']);

									$platform_customer = $this->mobj->getFirstResultByConditions('platform_customer', ['platform_id' => $this->platformId, 'api_customer_id' => $customer['contactId'], 'user_integration_id' => $user_integration_id], ['id']);
									if ($platform_customer) {
										$platform_customer_id = $platform_customer->id;
										$this->mobj->makeUpdate('platform_customer', $customerData, ['id' => $platform_customer_id]);
									} else {
										$platform_customer_id = $this->mobj->makeInsertGetId('platform_customer', $customerData);
									}
								}

								$orderDetails = [
									'user_id' => $user_id, 'user_integration_id' => $user_integration_id, 'platform_id' => $this->platformId, 'order_type' => "SO", 'platform_customer_id' => $platform_customer_id, 'api_order_id' => $order['id'], 'api_order_reference' => $order['reference'], 'order_number' => $order['reference'], 'order_status' => @$order['orderStatus']['orderStatusId'], 'order_date' => $order['placedOn'], 'api_pricelist_id' => @$order['priceListId'], 'api_order_payment_status' => strtolower($order['orderPaymentStatus']), 'currency' => @$order['currency']['orderCurrencyCode'], 'delivery_date' => @$order['delivery']['deliveryDate'], 'shipping_method' => @$order['delivery']['shippingMethodId'], 'total_tax' => isset($order['totalValue']['taxAmount']) ? $order['totalValue']['taxAmount'] : 0, 'total_amount' => isset($order['totalValue']['total']) ? $order['totalValue']['total'] : 0, 'net_amount' => isset($order['totalValue']['net']) ? $order['totalValue']['net'] : 0,
									'order_updated_at' => date("Y-m-d H:i:s"), 'sync_status' => 'Ready', 'shipment_status' => "Pending"
								];

								$platform_order_id = $this->mobj->makeInsertGetId('platform_order', $orderDetails);

								$additionalAccountInfo = PlatformAccountAdditionalInfo::where(['account_id' => $platform_account->id, 'user_integration_id' => $user_integration_id])->select('account_currency_code', 'account_product_lenght_unit', 'account_product_weight_unit', 'account_shipping_nominal_code', 'account_discount_nominal_code', 'account_sale_nominal_code', 'account_purchase_nominal_code', 'account_timezone', 'account_tax_scheme', 'account_giftcard_nominal_code')->first();
								if ($additionalAccountInfo) {
									if (count($order['orderRows']) > 0) {
										foreach ($order['orderRows'] as $orderRowKey => $orderRow) {
											$lineItem = ['platform_order_id' => $platform_order_id, 'api_order_line_id' => $orderRowKey, 'api_product_id' => $orderRow['productId'], 'product_name' => $orderRow['productName'], 'sku' => @$orderRow['productSku'], 'qty' => isset($orderRow['quantity']['magnitude']) ? $orderRow['quantity']['magnitude'] : 0, 'unit_price' => isset($orderRow['itemCost']['value']) ? $orderRow['itemCost']['value'] : 0, 'total' => isset($orderRow['rowValue']['rowNet']['value']) ? $orderRow['rowValue']['rowNet']['value'] : 0, 'total_tax' => isset($orderRow['rowValue']['rowTax']['value']) ? $orderRow['rowValue']['rowTax']['value'] : 0, 'taxes' => @$orderRow['rowValue']['taxClassId'], 'api_code' => @$orderRow['nominalCode'], 'row_type' => isset($orderRow['nominalCode']) ? BrightpearlServices::getBPLineItemType($additionalAccountInfo, $orderRow['nominalCode']) : 'ITEM'];

											$where = ['platform_order_id' => $platform_order_id, 'api_order_line_id' => $orderRowKey];
											PlatformOrderLine::updateOrCreate($where, $lineItem);
										}
									}
								}

								$orderAddressData = [];
								$delivery = @$order['parties']['delivery'];
								if (is_array($delivery)) {
									$deliveryAddress = ['platform_order_id' => $platform_order_id, 'address_name' => @$delivery['addressFullName'], 'company' => @$delivery['companyName'], 'address1' => @$delivery['addressLine1'], 'address2' => @$delivery['addressLine2'], 'address3' => @$delivery['addressLine3'], 'address4' => @$delivery['addressLine4'], 'postal_code' => @$delivery['postalCode'], 'country' => @$delivery['countryIsoCode'], 'phone_number' => @$delivery['telephone'], 'email' => @$delivery['email'], 'address_type' => "shipping"];
									array_push($orderAddressData, $deliveryAddress);
								}

								if (is_array($customer)) {
									$customerAddress = ['platform_order_id' => $platform_order_id, 'address_name' => @$customer['addressFullName'], 'company' => @$customer['companyName'], 'address1' => @$customer['addressLine1'], 'address2' => @$customer['addressLine2'], 'address3' => @$customer['addressLine3'], 'address4' => @$customer['addressLine4'], 'postal_code' => @$customer['postalCode'], 'country' => @$customer['countryIsoCode'], 'phone_number' => @$customer['telephone'], 'email' => @$customer['email'], 'address_type' => "customer"];
									array_push($orderAddressData, $customerAddress);
								}

								$billing = $order['parties']['billing'];
								if (is_array($billing)) {
									$billingAddress = ['platform_order_id' => $platform_order_id, 'address_name' => @$billing['addressFullName'], 'company' => @$billing['companyName'], 'address1' => @$billing['addressLine1'], 'address2' => @$billing['addressLine2'], 'address3' => @$billing['addressLine3'], 'address4' => @$billing['addressLine4'], 'postal_code' => @$billing['postalCode'], 'country' => @$billing['countryIsoCode'], 'phone_number' => @$billing['telephone'], 'email' => @$billing['email'], 'address_type' => "billing"];
									array_push($orderAddressData, $billingAddress);
								}

								$this->mobj->makeInsert('platform_order_address', $orderAddressData);

								$return_response = true;
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BrightPearlApiSubController -> FindShipmentOrderByOrderNumberAndStoreNewCreatedOrderDetail -> " . $e->getLine() . " -> " . $e->getMessage());
		}

		return $return_response;
	}

	/* Create Invoice On Sales Credit Orders */
	public function CreateInvoiceOnSalesCreditOrders($userId = NULL, $userIntegrationId = NULL, $UserWorkFlowRuleID = NULL, $SourcePlatformName = NULL, $RecordID = NULL)
	{
		$return_response = true;
		try {
			/* Get object id by order type */
			$object_id = $this->helper->getObjectId('refund_order');

			/* Get Source Platform Details */
			$SourcePlatformId = $this->helper->getPlatformIdByName($SourcePlatformName);

			$platform_account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
			if ($platform_account) {
				$limit = 20;
				$query = PlatformOrderRefund::join('platform_order', 'platform_order_refunds.platform_order_id', '=', 'platform_order.id')
					->join('platform_order_transactions', 'platform_order_refunds.id', '=', 'platform_order_transactions.platform_order_refund_id')
					->select('platform_order_refunds.id', 'platform_order_refunds.platform_order_id', 'platform_order_refunds.api_id', 'platform_order_refunds.linked_id', 'platform_order_refunds.date_created')
					->where(['platform_order.user_id' => $userId, 'platform_order.platform_id' => $SourcePlatformId, 'platform_order.user_integration_id' => $userIntegrationId]);

				if ($RecordID) {
					$query->where('platform_order_refunds.platform_order_id', $RecordID);
				} else {
					$query->where(['platform_order_transactions.sync_status' => 'Ready']);
				}

				$platform_order_refunds = $query->where('platform_order_transactions.row_type', 'REFUND')->where('platform_order_refunds.linked_id', '<>', 0)->orderBy('platform_order_refunds.platform_order_id', 'ASC')->orderBy('platform_order_refunds.updated_at', 'ASC')->take($limit)->distinct()->get();
				foreach ($platform_order_refunds as $platform_order_refund) {
					$platform_order_transactions = PlatformOrderTransaction::where(['platform_order_refund_id' => $platform_order_refund->id, 'platform_order_id' => $platform_order_refund->platform_order_id, 'row_type' => 'REFUND'])->get();
					if (count($platform_order_transactions)) {
						$isRefundSynced = 0;
						$destination_platform_order = NULL;
						foreach ($platform_order_transactions as $platform_order_transaction) {
							if ($platform_order_transaction->sync_status == 'Synced') {
								$isRefundSynced = 1;
							}
							$destination_platform_order_refund = PlatformOrderRefund::select('platform_order_id')->where('id', $platform_order_refund->linked_id)->first();
							if ($destination_platform_order_refund) {
								$destination_platform_order = PlatformOrder::select('api_order_id', 'order_date', 'tax_date', 'warehouse_id', 'currency', 'total_amount')->where('id', $destination_platform_order_refund->platform_order_id)->where('order_type', 'SC')->first();
							} else {
								$platform_order_refund->sync_status = 'Failed';
								$platform_order_refund->save();

								/* save log */
								$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $platform_order_refund->id, 'Destination sales credit not available.');
								$return_response = 'Destination sales credit not available.';
							}
						}

						//manage sales credit order
						if ($destination_platform_order && $isRefundSynced == 1) {
							/*------start create goods-in-------*/
							sleep(1);
							app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->ReceiveRefundSalesCreditInventory($platform_account, $destination_platform_order->api_order_id, $destination_platform_order->warehouse_id);
							/*------stop create goods-in--------*/

							$closeData = [];
							$closeData['taxDate'] = date(DATE_ISO8601, strtotime($destination_platform_order->tax_date ? $destination_platform_order->tax_date : $destination_platform_order->order_date));

							sleep(1);
							$this->bp->CloseSalesCreditByID($platform_account, $destination_platform_order->api_order_id, $closeData);

							PlatformOrderTransaction::where(['platform_order_refund_id' => $platform_order_refund->id, 'platform_order_id' => $platform_order_refund->platform_order_id, 'row_type' => 'REFUND'])
								->update(['sync_status' => 'Synced']);

							$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $platform_order_refund->id, NULL);
							$return_response = true;
						} elseif ($destination_platform_order && $isRefundSynced == 0) {
							/*------start create goods-in-------*/
							sleep(1);
							app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->ReceiveRefundSalesCreditInventory($platform_account, $destination_platform_order->api_order_id, $destination_platform_order->warehouse_id);
							/*------stop create goods-in--------*/

							/*------start payment-------*/
							$default_order_payment = $this->map->getMappedDataByName($userIntegrationId, NULL, "sorder_payment", ['api_code']);
							$default_order_payment = isset($default_order_payment) && $default_order_payment ? $default_order_payment->api_code : null;

							$PostPayment = ['orderId' => $destination_platform_order->api_order_id, 'paymentMethodCode' => $default_order_payment, 'paymentType' => 'PAYMENT', 'currencyIsoCode' => $destination_platform_order->currency, 'exchangeRate' => null, 'amountPaid' => abs($destination_platform_order->total_amount), 'paymentDate' => date(DATE_ISO8601, strtotime($platform_order_refund->date_created)), 'journalRef' => 'Sales Credit for order: ' . $destination_platform_order->api_order_id, 'transactionRef' => 'Ref Sales Credit-' . $platform_order_refund->api_id . '-' . $destination_platform_order->api_order_id . '-' . time()];

							app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->CreateCustomerPayment($userId, $userIntegrationId, $PostPayment);
							/*------stop payment--------*/

							$closeData = [];
							$closeData['taxDate'] = date(DATE_ISO8601, strtotime($destination_platform_order->tax_date ? $destination_platform_order->tax_date : $destination_platform_order->order_date));

							sleep(1);
							$response = $this->bp->CloseSalesCreditByID($platform_account, $destination_platform_order->api_order_id, $closeData);
							$result = json_decode($response->getBody(), true);
							if (is_array($result) && count($result) == 0) {
								$platform_order_refund->sync_status = 'Synced';
								$platform_order_refund->save();

								PlatformOrderTransaction::where(['platform_order_refund_id' => $platform_order_refund->id, 'platform_order_id' => $platform_order_refund->platform_order_id, 'row_type' => 'REFUND'])
									->update(['sync_status' => 'Synced']);

								$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $platform_order_refund->id, NULL);
								$return_response = true;
							} else {
								$platform_order_refund->sync_status = 'Failed';
								$platform_order_refund->save();

								PlatformOrderTransaction::where(['platform_order_refund_id' => $platform_order_refund->id, 'platform_order_id' => $platform_order_refund->platform_order_id, 'row_type' => 'REFUND'])
									->update(['sync_status' => 'Failed']);

								/* save log */
								$error = $this->bp->handleResponseError($result);
								$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $platform_order_refund->id, $error);
								$return_response = $error;
							}
						} else {
							$platform_order_refund->sync_status = 'Failed';
							$platform_order_refund->save();

							/* save log */
							$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $platform_order_refund->id, 'Destination sales credit not available.');
							$return_response = 'Destination sales credit not available.';
						}
					} else {
						$platform_order_refund->sync_status = 'Synced';
						$platform_order_refund->save();

						$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $platform_order_refund->id, NULL);
						$return_response = true;
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . ' -> BrightPearlApiSubController -> CreateInvoiceOnSalesCreditOrders -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			$return_response = $e->getMessage();
		}

		return $return_response;
	}

	/* Update Sales Order Row Tax Amounts */
	public function UpdateSalesOrderRowTaxAmounts($userId = NULL, $userIntegrationId = NULL, $PlatformWorkFlowID = NULL, $UserWorkFlowRuleID = NULL, $SourcePlatformName = NULL, $RecordID = NULL)
	{
		$return_response = true;
		try {
			/* Get object id by order type */
			$object_id = $this->helper->getObjectId('sales_order');

			/* Get Source Platform Details */
			$SourcePlatformId = $this->helper->getPlatformIdByName($SourcePlatformName);

			$platform_account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
			if ($platform_account) {
				$UpdatedRowTaxOrderStatusId = NULL;
				$default_updated_row_tax_order_status = $this->map->getMappedDataByName($userIntegrationId, $PlatformWorkFlowID, "updated_row_tax_order_status", ['api_id']);
				if ($default_updated_row_tax_order_status) {
					$UpdatedRowTaxOrderStatusId = $default_updated_row_tax_order_status->api_id;
				}

				$limit = 25;

				$query = PlatformOrder::select('id', 'linked_id')
					->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $SourcePlatformId]);

				if ($RecordID) {
					$query->where('id', $RecordID);
				} else {
					$query->where(['sync_status' => 'Ready']);
				}

				$platform_orders = $query->where('linked_id', '<>', 0)->orderBy('updated_at', 'ASC')->take($limit)->distinct()->get();
				foreach ($platform_orders as $platform_order) {
					$destination_platform_order = PlatformOrder::select('api_order_id')->where('id', $platform_order->linked_id)->first();
					if ($destination_platform_order) {
						$error_response = '';
						$platform_order_lines = PlatformOrderLine::select('linked_id', 'subtotal_tax', 'row_type')->where('platform_order_id', $platform_order->id)->where('is_deleted', 0)->get();
						foreach ($platform_order_lines as $platform_order_line) {
							$destination_platform_order_line = NULL;
							if ($platform_order_line->row_type == 'ITEM') {
								$destination_platform_order_line = PlatformOrderLine::select('api_order_line_id')->where('id', $platform_order_line->linked_id)->where('is_deleted', 0)->first();
							} elseif ($platform_order_line->row_type == 'SHIPPING') {
								$destination_platform_order_line = PlatformOrderLine::select('api_order_line_id')->where('platform_order_id', $platform_order->linked_id)->where('row_type', 'SHIPPING')->first();
							}

							if ($destination_platform_order_line) {
								$rowData = array(array("op" => "replace", "path" => "/rowValue/rowTax/value", "value" => $this->helper->getNumberFormat($platform_order_line->subtotal_tax, 4)), array("op" => "replace", "path" => "/rowValue/taxCalculator", "value" => "manual"));

								$response = $this->bp->UpdateOrderRowTax($platform_account, $destination_platform_order->api_order_id, $destination_platform_order_line->api_order_line_id, $rowData);
								$result = json_decode($response->getBody(), true);
								if (isset($result['response']['productId'])) {
									$return_response = true;
								} else {
									/* store error message */
									$error_response = $this->bp->handleResponseError($result);
								}
							}
						}

						if ($error_response == '') {
							if ($UpdatedRowTaxOrderStatusId) {
								$OrderStatusData = ['orderStatusId' => $UpdatedRowTaxOrderStatusId, 'orderNote' => ['text' => 'Update Order Row Tax Amounts.', 'isPublic' => true]];

								$url = "order/{$destination_platform_order->api_order_id}/status";
								$this->bp->UpdateOrderStatus($platform_account, $url, $OrderStatusData);
							}

							$this->mobj->makeUpdate('platform_order', ['sync_status' => 'Synced'], ['id' => $platform_order->id]);
							$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'success', $platform_order->id, NULL);
						} else {
							$this->mobj->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $platform_order->id]);
							$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlowRuleID, $SourcePlatformId, $this->platformId, $object_id, 'failed', $platform_order->id, $error_response);

							$return_response = $error_response;
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . ' -> BrightPearlApiSubController -> UpdateSalesOrderRowTaxAmounts -> ' . $e->getLine() . ' -> ' . $e->getMessage());
			$return_response = $e->getMessage();
		}

		return $return_response;
	}

	public function CreateBrightpearlInvoiceJournalEntry($ufound, $userId, $userIntegrationId, $WorkFlowID, $UserWorkFlow, $SourcePlatformId, $object_id, $id, $contact_code, $invoice_ref, $txn_date, $due_date, $currency, $exchange_rate, $description, $nominal_code, $net_amount, $default_tax_id, $default_credit_nominal_code = "", $default_tax_nominal_code = "", $platform_order_id = 0, $order_id = "")
	{

		$find = PlatformCustomer::select('api_customer_id')->where(['platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'api_customer_code' => $contact_code])->first();

		$ids = $descriptions = [];
		$wheredata = ['user_integration_id' => $userIntegrationId, 'platform_id' => $SourcePlatformId, 'ref_number' => $invoice_ref, 'sync_status' => 'Ready'];
		$list = DB::table('platform_invoice')->where($wheredata)->select(['id', 'invoice_code', 'api_tax_code', 'exchange_rate', 'net_total', 'total_tax', 'message'])->get();
		foreach ($list as $row) {
			$ids[] = $row->id;
			$descriptions[] = $row->message;
		}


		if ($find) {
			$contact_id = $find->api_customer_id;

			if ($currency != '' && $txn_date != '' && $invoice_ref != '' && $nominal_code != '') {

				// For Non PO Invoice -> NO need to associate any purchase order and any purchase order can process here by contact id

				$response_currency = $this->bp->GetCurrency($ufound, $currency);

				$currency_id = "";
				if ($result_currency = json_decode($response_currency->getBody(), true)) {
					if (isset($result_currency['response']['results'][0][0])) {
						$currency_id = $result_currency['response']['results'][0][0];
					}
				}


				if ($currency_id != '') {


					$PostData = [];

					$PostData['header']['journalType'] = 'PI';
					$PostData['header']['journalDate'] = $txn_date; //date('Y-m-d', strtotime($txn_date));//strtotime is not working property the format will be Y-m-d from db directly
					$PostData['header']['dateDue'] = $due_date; //date('Y-m-d', strtotime($due_date));//strtotime is not working property the format will be Y-m-d from db directly
					$PostData['header']['description'] = implode('; ', array_filter($descriptions));
					$PostData['header']['currencyId'] = $currency_id;
					$PostData['header']['exchangeRate'] = @$exchange_rate ? $exchange_rate : 1;
					$PostData['header']['contactId'] = $contact_id;


					$ct_lines = $credit_total = 0;


					// this is muiltiple debit lines to add lines on it for create journal lines
					foreach ($list as $row) {

						$tax_id = '';
						if (trim($row->api_tax_code) != '') {

							$res_tax_code = $this->map->getObjectDataByObjectName($userIntegrationId, 'taxcode', 'api_code', $row->api_tax_code, ['api_id']);
							$tax_id = @$res_tax_code->api_id ? $res_tax_code->api_id : $default_tax_id;
						} else {
							$tax_id = $default_tax_id;
						}


						$total_tax = @$row->total_tax ? @$row->total_tax : 0;
						$net_total = @$row->net_total ? @$row->net_total : 0;

						$total = round($net_total + $total_tax, 2);

						if ($total > 0) {

							$credit_total += $total;

							$PostData['lines'][$ct_lines]['transactionDebit'] = $net_total;
							$PostData['lines'][$ct_lines]['nominalCode'] = @$row->invoice_code;
							$PostData['lines'][$ct_lines]['invoiceRef'] = $invoice_ref;
							$PostData['lines'][$ct_lines]['taxClassId'] = $tax_id;
							if ($platform_order_id != 0 && $order_id != '') {
								$PostData['lines'][$ct_lines]['orderId'] = $order_id;
							}

							if ($default_tax_nominal_code != '' && $total_tax > 0) {

								$ct_lines++;

								$PostData['lines'][$ct_lines]['transactionDebit'] = $total_tax;
								$PostData['lines'][$ct_lines]['nominalCode'] = $default_tax_nominal_code;
								$PostData['lines'][$ct_lines]['invoiceRef'] = $invoice_ref;
								$PostData['lines'][$ct_lines]['taxClassId'] = $tax_id;
								if ($platform_order_id != 0 && $order_id != '') {
									$PostData['lines'][$ct_lines]['orderId'] = $order_id;
								}
							}
						} else {

							$credit_total -= abs($total);

							$PostData['lines'][$ct_lines]['transactionCredit'] = abs($net_total);
							$PostData['lines'][$ct_lines]['nominalCode'] = @$row->invoice_code;
							$PostData['lines'][$ct_lines]['invoiceRef'] = $invoice_ref;
							$PostData['lines'][$ct_lines]['taxClassId'] = $tax_id;
							if ($platform_order_id != 0 && $order_id != '') {
								$PostData['lines'][$ct_lines]['orderId'] = $order_id;
							}

							if ($default_tax_nominal_code != '' && abs($total_tax) > 0) {

								$ct_lines++;

								$PostData['lines'][$ct_lines]['transactionCredit'] = abs($total_tax);
								$PostData['lines'][$ct_lines]['nominalCode'] = $default_tax_nominal_code;
								$PostData['lines'][$ct_lines]['invoiceRef'] = $invoice_ref;
								$PostData['lines'][$ct_lines]['taxClassId'] = $tax_id;
								if ($platform_order_id != 0 && $order_id != '') {
									$PostData['lines'][$ct_lines]['orderId'] = $order_id;
								}
							}
						}

						$ct_lines++;
					}


					// here for this is credit line to create balance between journal credit & debit
					$PostData['lines'][$ct_lines]['transactionCredit'] = $credit_total;
					$PostData['lines'][$ct_lines]['nominalCode'] = $default_credit_nominal_code; //2100;
					$PostData['lines'][$ct_lines]['invoiceRef'] = $invoice_ref;
					$PostData['lines'][$ct_lines]['taxClassId'] = $default_tax_id;
					if ($platform_order_id != 0 && $order_id != '') {
						$PostData['lines'][$ct_lines]['orderId'] = $order_id;
					}
					//echo "<pre>";
					//print_r($PostData);

					$response = $this->bp->CreateJournalEntry($ufound, $PostData);
					$journalResponse = json_decode($response->getBody(), true);
					//print_r($journalResponse);

					\Storage::disk('local')->append('Bhoopendra.txt', "\r\n" . "CreateBrightpearlInvoiceJournalEntry Date -> " . date('Y-m-d H:i:s') . "userIntegrationId : " . $userIntegrationId . " | journalResponse : " . json_encode($journalResponse, true));

					if (isset($journalResponse['response'])) {

						$linked_id = $this->mobj->makeInsertGetId('platform_invoice', [
							'platform_id' => $this->platformId,
							'user_integration_id' => $userIntegrationId,
							'api_invoice_id' => $journalResponse['response'],
							'sync_status' => 'Synced',
							'linked_id' => $id,
						]);



						PlatformInvoice::whereIn('id', $ids)->update(['linked_id' => $linked_id, 'sync_status' => 'Synced']);
						if ($platform_order_id != 0) {
							PlatformOrder::where('id', $platform_order_id)->update(['invoice_sync_status' => 'Synced']);
						}
						foreach ($ids as $id) {
							$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $id, null);
						}
					} else {
						$error = $this->bp->handleResponseError($journalResponse);

						PlatformInvoice::whereIn('id', $ids)->update(['sync_status' => 'Failed']);
						if ($platform_order_id != 0) {
							PlatformOrder::where('id', $platform_order_id)->update(['invoice_sync_status' => 'Failed']);
						}
						foreach ($ids as $id) {
							$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $id, $error);
						}
					}
				} else {
					$error = "currency is missing";
					PlatformInvoice::whereIn('id', $ids)->update(['sync_status' => 'Failed']);
					if ($platform_order_id != 0) {
						PlatformOrder::where('id', $platform_order_id)->update(['invoice_sync_status' => 'Failed']);
					}
					foreach ($ids as $id) {
						$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $id, $error);
					}
				}
			} else {

				$error = "some details are missing";
				PlatformInvoice::whereIn('id', $ids)->update(['sync_status' => 'Failed']);
				if ($platform_order_id != 0) {
					PlatformOrder::where('id', $platform_order_id)->update(['invoice_sync_status' => 'Failed']);
				}
				foreach ($ids as $id) {
					$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $id, $error);
				}
			}
		} else {
			$error = "supplier not found";
			PlatformInvoice::whereIn('id', $ids)->update(['sync_status' => 'Failed']);
			if ($platform_order_id != 0) {
				PlatformOrder::where('id', $platform_order_id)->update(['invoice_sync_status' => 'Failed']);
			}
			foreach ($ids as $id) {
				$this->log->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $id, $error);
			}
		}
	}

	/* Adjustment of upcomming inventory not correction overall */
	public function AdjustInventory($user_id = '', $user_integration_id = '', $source_platform_name = '', $platform_workflow_rule_id = '', $user_workflow_rule_id = '', $sync_status = "Ready", $record_id = '')
	{
		try {

			$return = true;
			$process_limit = 50;

			$defInventPriceListMap = false;
			$sel_def_inv_priclist_objId = null;

			/* ---Get Default inventory PriceList Mapping--- */
			$defInvPriceListObj = $this->helper->getObjectId('inventory_pricelist');
			$sel_def_inv_priclist_objId = $this->map->getMappedApiIdByObjectId($user_integration_id, $defInvPriceListObj, 'default', 'id');
			if ($sel_def_inv_priclist_objId) {
				$defInventPriceListMap = true;
			}

			$Inventory_arr = [];
			$source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
			$inventory_object_id = $this->helper->getObjectId('inventory');

			/* Destination Platform Account Credentials */
			$ufound = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId, ['access_token', 'platform_id', 'id', 'user_id', 'api_domain', 'account_name', 'app_id', 'app_secret']);
			/* ----------------------------------------- */

			/* Find BP account additinal information for currency */
			$accountInformation = $this->mobj->getFirstResultByConditions('platform_account_addtional_information', ['account_id' => $ufound->id, 'user_integration_id' => $user_integration_id], ['account_currency_code'], ['id' => 'asc']);
			$account_currency_code = isset($accountInformation->account_currency_code) ? $accountInformation->account_currency_code : "";
			/* ------------------------------------------------------ */


			if ($ufound) {
				$DefaultInventoryWarehouseLocation = null;
				$DefaultInventoryWarehouseId = null;
				/* Find Default Warehouse */
				$DefaultWarehouseId = $this->map->getMappedDataByName($user_integration_id, NULL, "inventory_warehouse", ['api_id']);

				if ($DefaultWarehouseId) {
					$DefaultInventoryWarehouseLocation = app('App\Http\Controllers\Brightpearl\BrightPearlApiController')->GetWarehouseDefaultLocation($user_integration_id, $DefaultWarehouseId->api_id);
					$DefaultInventoryWarehouseId = $DefaultWarehouseId->api_id;
				}
				/* -------------------------------------- */
				/* Identify Product Uniqueness  $platform_workflow_rule_id */
				$identity = app('App\Http\Controllers\Brightpearl\BrightpearlUtility')->ProductIdentityMapping($user_integration_id, NULL);
				$source_identity = $identity['source_identity']; //Source Identity
				$destination_identity = $identity['destination_identity']; //Destination Identity

				/* Source & Destination Identity not null or empty */
				if ($destination_identity && $source_identity) {

					do {

						$allow_next_call = false;


						if ($record_id) { //If single record found

							$Inventory_arr = DB::table('platform_product as source_platform_product')
								->join('platform_product as destination_platform_product', 'destination_platform_product.' . $destination_identity, '=', 'source_platform_product.' . $source_identity)
								->select('source_platform_product.id', 'destination_platform_product.sku', 'destination_platform_product.api_product_id', 'destination_platform_product.id as destination_platform_product_id')
								->where(['source_platform_product.user_integration_id' => $user_integration_id, 'destination_platform_product.user_integration_id' => $user_integration_id])
								->where(['source_platform_product.platform_id' => $source_platform_id, 'destination_platform_product.platform_id' => $this->platformId])
								->where('source_platform_product.id', $record_id)
								->where('source_platform_product.is_deleted', 0)
								->where('destination_platform_product.is_deleted', 0)
								->limit($process_limit)
								->distinct()
								->get();
						} else {

							$query = DB::table('platform_product as source_platform_product')
								->join('platform_product as destination_platform_product', 'destination_platform_product.' . $destination_identity, '=', 'source_platform_product.' . $source_identity);

							$Inventory_arr = $query->where(['source_platform_product.adjustment_sync_status' => $sync_status, 'source_platform_product.user_integration_id' => $user_integration_id, 'destination_platform_product.user_integration_id' => $user_integration_id])
								->where(['source_platform_product.platform_id' => $source_platform_id, 'destination_platform_product.platform_id' => $this->platformId])->select('source_platform_product.id', 'destination_platform_product.sku', 'destination_platform_product.api_product_id', 'destination_platform_product.id as destination_platform_product_id')
								->where('source_platform_product.is_deleted', 0)
								//additional  check.. to prevent run inventory adjustment whern snapshot recieve & record ready to sync
								->where('source_platform_product.inventory_sync_status', '!=', 'Ready')
								->where('destination_platform_product.is_deleted', 0)
								->orderBy('source_platform_product.updated_at', 'asc')
								->limit($process_limit)
								->distinct()
								->get();

							if (!count($Inventory_arr)) { //if Ready not exist then pick Failed inventory.
								$query = DB::table('platform_product as source_platform_product')
									->join('platform_product as destination_platform_product', 'destination_platform_product.' . $destination_identity, '=', 'source_platform_product.' . $source_identity);

								$Inventory_arr = $query->select('source_platform_product.id', 'destination_platform_product.sku', 'destination_platform_product.api_product_id', 'destination_platform_product.id as destination_platform_product_id')
									->where(['source_platform_product.adjustment_sync_status' => 'Failed', 'source_platform_product.user_integration_id' => $user_integration_id, 'destination_platform_product.user_integration_id' => $user_integration_id])
									->where(['source_platform_product.platform_id' => $source_platform_id, 'destination_platform_product.platform_id' => $this->platformId])
									->where('source_platform_product.is_deleted', 0)
									->where('destination_platform_product.is_deleted', 0)
									->orderBy('source_platform_product.updated_at', 'asc')
									->limit($process_limit)
									->distinct()
									->get();
							}
						}


						if ($Inventory_arr && count($Inventory_arr) == $process_limit) {
							// Don't want to loop contineously
							$allow_next_call = false;
						}

						//loop inventory adjustment by product level... where adjustment sync status Ready
						if ($Inventory_arr && count($Inventory_arr)) {

							foreach ($Inventory_arr as $Inventory) {


								//push adjustment sync to priority by update data - 1 when adjustment also available... for that.. so that adjustment can be synced after snapshot
								$active_flows = $this->wfsnip->getWorkflowEventsByIntegration($user_integration_id, "destination"); //find all active flow
								if (in_array("MUTATE_BULKINVENTORY", $active_flows)) { // if MUTATE_BULKINVENTORY is active
									//check inventory sync status... for current sku
									$find_snapshot_data = PlatformProduct::where(['user_integration_id' => $user_integration_id, 'inventory_sync_status' => 'Ready', 'platform_id' => $source_platform_id, 'sku' => $Inventory->sku])->first();
									if ($find_snapshot_data) {
										PlatformProduct::where('id', $find_snapshot_data->id)->update(['updated_at' => date('Y-m-d H:i:s', strtotime('-1 days'))]);
										continue;
									}
								}


								//get inventory from platform_inventory_trails for adjustment for inventory sync ready product.. id
								$product_inventory_arr = PlatformInventoryTrail::where(['user_integration_id' => $user_integration_id, 'platform_product_id' => $Inventory->id])->whereIn('sync_status', ['Ready', 'Failed'])->select('id', 'api_warehouse_id', 'api_quantity as quantity', 'platform_product_id')
									->get();


								if (count($product_inventory_arr)) {

									//get bp product inventory on instance....
									$getinventoryurl = $Inventory->api_product_id . '?includeOptional=breakDownByLocation';
									$response = $this->bp->GetInventory($ufound, $getinventoryurl, 0);
									$BpGetInventory = json_decode($response->getBody(), true);

									//find bp inventory...
									if (isset($BpGetInventory['response']) && is_array($BpGetInventory['response'])) {

										//start update logic here ..

										$product_synced_error = null;


										//loop multiple inventory adjustment row for single product sku
										$total_item_quantity = 0;
										$inv_adjustment_row_ids = [];
										foreach ($product_inventory_arr as $product_inventory) {
											$total_item_quantity = $total_item_quantity + $product_inventory->quantity;
											array_push($inv_adjustment_row_ids, $product_inventory->id);
										}



										$update_inventory_data = [];
										$priceListValue = 0;
										//Set Price list if mapping exists or set it to default
										if ($defInventPriceListMap) {

											if ($sel_def_inv_priclist_objId) {
												$prodPriceListData = $this->mobj->getFirstResultByConditions('platform_porduct_price_list', ['platform_product_id' => $Inventory->destination_platform_product_id, 'platform_object_data_id' => $sel_def_inv_priclist_objId], ['price', 'api_currency_code']); //get product Price List details

												if ($prodPriceListData) {
													$priceListValue = $prodPriceListData->price;
													$account_currency_code = $prodPriceListData->api_currency_code;
												}
											}
										}
										//End

										//Set default update qty
										$update_quantity = 0;

										if ($DefaultInventoryWarehouseLocation) {

											$inventory = $BpGetInventory['response'];

											//loop brightpearl inventory for selected product & pic mapped warehouse quantity
											foreach ($inventory as $pr => $Inv_arr) {

												$quantity = 0;
												$reason = '';

												if (count($Inv_arr['warehouses']) > 0) {

													$findWarehouse = 0;
													foreach ($Inv_arr['warehouses'] as $warehouse => $Inv) {

														if ($warehouse == $DefaultInventoryWarehouseId) {
															foreach ($Inv['byLocation'] as $location_id => $byLocation_data) {
																$quantity = $byLocation_data['onHand'];
																if ($location_id == $DefaultInventoryWarehouseLocation) {

																	$findWarehouse = 1;

																	$finalQtyArray = $this->CalculatInventoryAdjustmentQty($quantity, $total_item_quantity);
																	if ($finalQtyArray) {
																		$update_quantity = $finalQtyArray['update_qty'];
																		$reason = $finalQtyArray['reason'];
																	} else {
																		$update_quantity = 0;
																		$reason = "";
																	}
																	if ($update_quantity != 0) {
																		$update_inventory_data[] = array("locationId" => $location_id, "productId" => $Inventory->api_product_id, "reason" => $reason, "quantity" => $update_quantity, "cost" => ['currency' => $account_currency_code, 'value' => $priceListValue]);
																	}
																}
															}
														}
													}

													if ($findWarehouse == 0) {

														$quantity = 0;

														$finalQtyArray = $this->CalculatInventoryAdjustmentQty($quantity, $total_item_quantity);
														if ($finalQtyArray) {
															$update_quantity = $finalQtyArray['update_qty'];
															$reason = $finalQtyArray['reason'];
														} else {
															$update_quantity = 0;
															$reason = "";
														}

														if ($update_quantity != 0) {
															$reason = "Add stock to warehouse";
															$update_inventory_data[] = array("locationId" => $DefaultInventoryWarehouseLocation, "productId" => $Inventory->api_product_id, "reason" => $reason, "quantity" => $update_quantity, "cost" => ['currency' => $account_currency_code, 'value' => $priceListValue]);
														}
													}
												} else {

													// if warehouse not get from Bp inventory
													$quantity = 0;

													$finalQtyArray = $this->CalculatInventoryAdjustmentQty($quantity, $total_item_quantity);

													if ($finalQtyArray) {
														$update_quantity = $finalQtyArray['update_qty'];
														$reason = $finalQtyArray['reason'];
													} else {
														$update_quantity = 0;
														$reason = "";
													}

													if ($update_quantity != 0) {
														$update_inventory_data[] = array("locationId" => $DefaultInventoryWarehouseLocation, "productId" => $Inventory->api_product_id, "reason" => $reason, "quantity" => $update_quantity, "cost" => ['currency' => $account_currency_code, 'value' => $priceListValue]);
													}
												}
											}



											//start inventory adjustment.. call
											if (count($update_inventory_data) > 0) {

												$curl_post_data['corrections'] = $update_inventory_data;



												$response = $this->bp->UpdateInventory($ufound, $DefaultInventoryWarehouseLocation, $curl_post_data);
												$Inventory_data = json_decode($response->getBody(), true);

												if (isset($Inventory_data['response'])) {

													PlatformInventoryTrail::whereIn('id', $inv_adjustment_row_ids)->update(['sync_status' => 'Synced']);
												} elseif (isset($Inventory_data['errors'])) {

													$product_synced_error = @$Inventory_data['errors'][0]['message'];
													PlatformInventoryTrail::whereIn('id', $inv_adjustment_row_ids)->update(['sync_status' => 'Failed']);
												}
											} else {

												if ($update_quantity == 0) {
													$sync_status = "Synced";
													PlatformInventoryTrail::whereIn('id', $inv_adjustment_row_ids)->update(['sync_status' => 'Synced']);
												} else {
													$product_synced_error = 'Inventory information not Found';
													$sync_status = "Failed";
													PlatformInventoryTrail::whereIn('id', $inv_adjustment_row_ids)->update(['sync_status' => 'Failed']);
												}
											}
										} else {

											$product_synced_error = 'Default warehouse location not found for selected inventory warehouse.';
											$sync_status = "Failed";
											PlatformInventoryTrail::whereIn('id', $inv_adjustment_row_ids)->update(['sync_status' => 'Failed']);
										}


										//update platform product inventory sync status synced or faield ....below
										if ($product_synced_error) {

											$return = $product_synced_error;
											$this->mobj->makeUpdate('platform_product', ['adjustment_sync_status' => "Failed"], ['id' => $Inventory->id]);
											$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $inventory_object_id, "failed", $Inventory->id, $product_synced_error);
										} else {

											$this->mobj->makeUpdate('platform_product', ['adjustment_sync_status' => "Synced"], ['id' => $Inventory->id]);
											$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $inventory_object_id, "success", $Inventory->id, 'Inventory synced successfully!');
										}

										////end update logic here ..

									} elseif (isset($BpGetInventory['errors'][0]['message'])) {

										$return = $BpGetInventory['errors'][0]['message'];
										$this->mobj->makeUpdate('platform_product', ['adjustment_sync_status' => 'Failed'], ['id' => $Inventory->id]);
										$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $inventory_object_id, 'failed', $Inventory->id, $BpGetInventory['errors'][0]['message']);
									}
								} else {
									$this->mobj->makeUpdate('platform_product', ['adjustment_sync_status' => 'Synced'], ['id' => $Inventory->id]);
									$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $inventory_object_id, 'success', $Inventory->id, 'Inventory synced!');
								}
							}
						}
					} while ($allow_next_call);
				}
			}
		} catch (\Exception $e) {
			Log::error($user_integration_id . " -> BrightPearlApiSubController -> AdjustInventory -> " . $e->getLine() . " -> " . $e->getMessage());
			$return = $e->getMessage();
		}
		return $return;
	}

	//calculation inventory adjustment
	public function CalculatInventoryAdjustmentQty($bp_quantity, $product_inventory_quantity)
	{
		$finalQtyArray = [];

		//check $product_inventory_quantity if in minus & brightpearl quantity already 0 then... no need to curection
		if ($product_inventory_quantity < 0) {

			if ($bp_quantity == 0) {
				$update_quantity = 0;
				$reason = "Invenory are same";
			} else {

				//case 1 : 10 - 5 = 5
				//case 2 : 5 - 10 = -5

				if ($bp_quantity - abs($product_inventory_quantity) < 0) {
					$update_quantity = -$bp_quantity;
				} else {
					$update_quantity = $product_inventory_quantity;
				}
				$reason = "Removed by APIWORX";
			}
		} else {
			$update_quantity = $product_inventory_quantity;
			$reason = "Added by APIWORX";
		}

		$finalQtyArray['update_qty'] = $update_quantity;
		$finalQtyArray['reason'] = $reason;

		return $finalQtyArray;
	}

	//Update Brightpearl Order line
	public function UpdateOrderLine($user_integration_id, $ufound, $platform_order_id, $api_order_id, $row_type = 'SHIPPING')
	{
		//Get Order line data
		$find_order_line = PlatformOrderLine::where(['platform_order_id' => $platform_order_id, 'row_type' => $row_type])->first();

		if ($find_order_line && $find_order_line->notes != $row_type . ' updated in brightpearl' && $find_order_line->total > 0) {

			//get default shipping line item mapping
			// $default_shipping_item_obj = $this->helper->getObjectId('default_shipping_product_id');
			$default_shipping_item_map =  $this->map->getMappedDataByName($user_integration_id, NULL, "default_shipping_product_id", ['custom_data'], "default");
			if ($default_shipping_item_map && $default_shipping_item_map->custom_data) {
				$curl_post_data['productId'] = $default_shipping_item_map->custom_data;
			} else {
				$curl_post_data['productName'] = $find_order_line->product_name;
			}


			$curl_post_data['quantity']['magnitude'] = $find_order_line->qty;
			$curl_post_data['rowValue']['taxCode'] = '-';
			$curl_post_data['rowValue']['rowNet']['value'] = $this->helper->getNumberFormat($find_order_line->total, 4);
			$curl_post_data['rowValue']['rowTax']['value'] = $this->helper->getNumberFormat(0, 4);

			//get shipping nominal code from account additional info
			$bp_account_additional_info = PlatformAccountAdditionalInfo::where(['account_id' => $ufound->id, 'user_integration_id' => $user_integration_id])
				->select('account_shipping_nominal_code')->first();
			if ($bp_account_additional_info && $bp_account_additional_info->account_shipping_nominal_code) {
				$curl_post_data['nominalCode'] = $bp_account_additional_info->account_shipping_nominal_code;
			}


			$response = $this->bp->addOrderLine($ufound, $api_order_id, $curl_post_data);
			$data = json_decode($response->getBody(), true);
			if (isset($data['response']) && $data['response'] > 0) {
				PlatformOrderLine::where(['id' => $find_order_line->id])->update(['notes' => $row_type . ' updated in brightpearl']);
			}
		}

		return true;
	}

	//Goods out Note delete
	public function deleteGoodsoutNote($is_initial_sync, $user_id, $user_integration_id, $source_platform_name, $user_workflow_rule_id, $record_id)
	{
		$returnstatus = true;
		$limit = 10;

		try {
			// get the account sub domain
			$account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			if ($account) {

				if ($is_initial_sync) {
					return $returnstatus;
				} else {

					$source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
					$object_id = $this->helper->getObjectId('sales_order');

					if ($record_id) {
						$shipment_status = $sync_status = 'Failed';
					} else {
						$shipment_status = $sync_status = 'Ready';
					}

					//Get source platform deleted orders
					$parent_orders = PlatformOrder::where(['platform_id' => $source_platform_id, 'user_integration_id' => $user_integration_id, 'sync_status' => $sync_status, 'shipment_status' => $shipment_status, 'is_deleted' => 1]);
					if ($record_id) {
						$parent_orders = $parent_orders->where('platform_order.id', $record_id);
					}
					$parent_orders = $parent_orders->limit($limit)->get();

					if ($parent_orders) {

						/* ------------------------------------------- */
						//here parent_order is source platform order
						foreach ($parent_orders as $parent_order) {

							if ($parent_order->linked_id) {

								//Get Brightpearl goodsout note data for delete api call
								$find_dest_shipment = PlatformOrderShipment::where('platform_order_id', $parent_order->linked_id)->select('id', 'shipment_id as gonId', 'order_id')->first();

								if ($find_dest_shipment) {

									$response = $this->bp->DeleteGoodsoutNote($account, $find_dest_shipment->order_id, $find_dest_shipment->gonId);
									$data = json_decode($response->getBody(), true);

									if (isset($data) && isset($data['errors'])) {

										if (count($data['errors']) > 0 && isset($data['errors'][0]['message'])) {
											$message = $data['errors'][0]['message'];
										} else {
											$message = "Api error occured on delete goodsout note.";
										}

										$log_status = 'failed';

										$parent_order->sync_status = 'Failed';
										$parent_order->shipment_status = 'Failed';
										$parent_order->order_updated_at = date('Y-m-d H:i:s');
										$parent_order->save();
										$returnstatus = $message;
									} else {

										//update bp order & shipment for deleted
										PlatformOrder::where('id', $parent_order->linked_id)->update(['sync_status' => 'Synced', 'shipment_status' => 'Synced', 'order_updated_at' => date('Y-m-d H:i:s'), 'is_deleted' => 1]);

										//shipment
										$find_dest_shipment->sync_status = 'Synced';
										$find_dest_shipment->save();

										$message = "Goodsout Note Deleted";
										$parent_order->sync_status = 'Synced';
										$parent_order->shipment_status = 'Synced';
										$parent_order->order_updated_at = date('Y-m-d H:i:s');
										$parent_order->save();
										$returnstatus = $message;
										$log_status = 'success';
									}


									$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, $log_status, $parent_order->id, $message);
								} else {
									$message = "Shipment data not found in our fetched brightpearl list";
									$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $parent_order->id, $message);
								}

								if ($record_id) {
									$returnstatus = $message;
								}
							}
						}
					}
				}
			}

			return $returnstatus;
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> brightpearl -> deleteGoodsoutNote -> " . $e->getMessage());
			return $e->getMessage();
		}
	}
}
