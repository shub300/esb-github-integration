<?php

namespace App\Http\Controllers\Bigcommerce;

use Illuminate\Http\Request;
use App\Helper\Logger;
use App\Helper\MainModel;
use App\Models\PlatformOrder;
use App\Helper\ConnectionHelper;
use App\Models\PlatformOrderLine;
use App\Helper\Api\BigcommerceApi;
use App\Helper\FieldMappingHelper;
use App\Models\PlatformObjectData;
use Illuminate\Support\Facades\DB;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderRefund;
use App\Models\PlatformOrderTransaction;
use Illuminate\Support\Facades\Storage;

class BigCommerceSubController extends BigcommerceApi
{
	private const PLATFORMNAME = 'bigcommerce';

	private $connectionHelper, $mainModel, $logger, $fieldMapHelper;

	public function __construct()
	{
		$this->connectionHelper = new ConnectionHelper();
		$this->mainModel = new MainModel();
		$this->logger = new Logger();
		$this->fieldMapHelper = new FieldMappingHelper();
		// Set the platform ID
		$this->platformId = $this->connectionHelper->getPlatformIdByName(self::PLATFORMNAME);
	}

	public function SearchCustomerByID($id = NULL, $select = [])
	{
		$return_response = NULL;
		$platform_customer = $this->mainModel->getFirstResultByConditions('platform_customer', ['id' => $id], $select);
		if ($platform_customer) {
			$return_response = $platform_customer;
		}
		return $return_response;
	}

	/* Sync Order */
	public function syncOrder($userId = NULL, $userIntegrationId = NULL, $WorkFlowID = NULL, $UserWorkFlow = NULL, $SourcePlatformName = NULL, $sync_status = "Ready", $RecordID = NULL)
	{
		$return_response = false;
		try {
			$limit = 25;
			$object_id = $this->connectionHelper->getObjectId('sales_order');
			$pricelist_object_id = $this->connectionHelper->getObjectId('pricelist');
			$platform_account = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId);

			$SourcePlatformId = $this->connectionHelper->getPlatformIdByName($SourcePlatformName);
			if ($platform_account) {
				$source_row_data = $destination_row_data = 'sku';
				$product_identity_obj_id = $this->connectionHelper->getObjectId('product_identity');
				$order_status_obj_id = $this->connectionHelper->getObjectId('order_status');
				$mapping_data = $this->fieldMapHelper->getMappedField($userIntegrationId, NULL, $product_identity_obj_id);
				if ($mapping_data) {
					if ($mapping_data['destination_platform_id'] == 'bigcommerce') {
						$destination_row_data = $mapping_data['destination_row_data'];
						$source_row_data = $mapping_data['source_row_data'];
					} else {
						$destination_row_data = $mapping_data['source_row_data'];
						$source_row_data = $mapping_data['destination_row_data'];
					}
				}

				$SourceOrDestination = "source";
				$platform_workflow_rule = $this->connectionHelper->getPlatformFlowDetail($WorkFlowID);
				if ($platform_workflow_rule && $platform_workflow_rule->destination_platform_id == $this->platformId) {
					$SourceOrDestination = "destination";
				}

				$authentication_password = NULL;
				$default_password = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, NULL, "custom_field", ['custom_data'], "default");
				if ($default_password) {
					$authentication_password = $default_password->custom_data;
				}

				$default_sorder_shipping_method = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, NULL, "default_sorder_shipping_method_bc", ['name']);
				$default_sorder_status = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, NULL, "default_sorder_status_bc", ['api_id']);

				$query = PlatformOrder::select('platform_customer_id', 'api_order_id', 'customer_email', 'order_number', 'order_date', 'due_days', 'department', 'vendor', 'total_discount', 'total_tax', 'discount_tax', 'total_amount', 'notes', 'sync_status', 'linked_id', 'shipping_total', 'shipping_tax', 'carrier_code', 'warehouse_id', 'order_update_status', 'id', 'currency', 'shipping_method', 'payment_date', 'delivery_date', 'is_voided', 'api_pricelist_id', 'order_status', 'shipment_status')
					->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $SourcePlatformId]);

				if ($RecordID) {
					$query->where('id', $RecordID);
				} else {
					$query->where('sync_status', $sync_status);
				}

				$platform_orders = $query->where('order_type', 'SO')->take($limit)->orderBy('updated_at', 'asc')->get();

				foreach ($platform_orders as $order) {
					$customerID = null;
					$platform_customer_id = null;
					if ($order->linked_id == 0 && !$order->is_voided) {
						$platform_customer = $this->mainModel->getFirstResultByConditions('platform_customer', ['id' => $order->platform_customer_id], ['api_customer_group_id', 'customer_name as address_name', 'first_name as firstname', 'last_name as lastname', 'address1', 'address2 as city', 'address3 as state', 'postal_addresses as postal_code', 'country', 'phone as phone_number', 'email', 'company_name as company']);

						$shipping_method = '';
						$sorder_shipping_method = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, NULL, "sorder_shipping_method", ['api_id', 'name'], 'regular', $order->shipping_method);
						if ($sorder_shipping_method) {
							$shipping_method = $sorder_shipping_method->name;
						} elseif ($default_sorder_shipping_method) {
							$shipping_method = $default_sorder_shipping_method->name;
						}

						$customer_address = NULL;

						/* Find Shipping Address */
						$shipping_addresses = [];
						$order_shipping_address = $this->mainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $order->id, 'address_type' => 'shipping'], ['address_name', 'firstname', 'lastname', 'address1', 'address2', 'city', 'state', 'postal_code', 'country', 'phone_number', 'email', 'company']);
						if ($order_shipping_address) {
							$shipping_addresses[] = array("first_name" => (($order_shipping_address->firstname) ? $order_shipping_address->firstname : $order_shipping_address->address_name), "last_name" => (($order_shipping_address->lastname) ? $order_shipping_address->lastname : ' '), "company" => $order_shipping_address->company, "street_1" => $order_shipping_address->address1, "street_2" => $order_shipping_address->address2, "city" => $order_shipping_address->city, "state" => $order_shipping_address->state, "zip" => $order_shipping_address->postal_code, "country_iso2" => $order_shipping_address->country, "phone" => $order_shipping_address->phone_number, "email" => $order_shipping_address->email, "shipping_method" => $shipping_method);

							$customer_address = $order_shipping_address;
						}

						/* Find Billing Address */
						$billing_address = NULL;
						$order_billing_address = $this->mainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $order->id, 'address_type' => 'billing'], ['address_name', 'firstname', 'lastname', 'address1', 'address2', 'city', 'state', 'postal_code', 'country', 'phone_number', 'email', 'company']);
						if ($order_billing_address) {
							$billing_address = array("first_name" => (($order_billing_address->firstname) ? $order_billing_address->firstname : $order_billing_address->address_name), "last_name" => (($order_billing_address->lastname) ? $order_billing_address->lastname : ' '), "company" => $order_billing_address->company, "street_1" => $order_billing_address->address1, "street_2" => $order_billing_address->address2, "city" => $order_billing_address->city, "state" => $order_billing_address->state, "zip" => $order_billing_address->postal_code, "country_iso2" => $order_billing_address->country, "phone" => $order_billing_address->phone_number, "email" => $order_billing_address->email);

							$customer_address = $order_billing_address;
						}

						if ($platform_customer) {
							$CustomerData = array("email" => $platform_customer->email, "first_name" => (($customer_address->firstname) ? $customer_address->firstname : $customer_address->address_name), "last_name" => (($customer_address->lastname) ? $customer_address->lastname : ' '), "company" => $customer_address->company, "phone" => $customer_address->phone_number, "addresses" => array(array("address1" => $customer_address->address1, "address2" => $customer_address->address2, "city" => $customer_address->city, "company" => $customer_address->company, "country_code" => $customer_address->country, "first_name" => (($customer_address->firstname) ? $customer_address->firstname : $customer_address->address_name), "last_name" => (($customer_address->lastname) ? $customer_address->lastname : ' '), "phone" => $customer_address->phone_number, "postal_code" => $customer_address->postal_code, "state_or_province" => $customer_address->state)), "accepts_product_review_abandoned_cart_emails" => false, "origin_channel_id" => 1, "channel_ids" => array(1));

							if ($pricelist_object_id) {
								$sc_pricelist_name = PlatformObjectData::select('name')->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $SourcePlatformId, 'platform_object_id' => $pricelist_object_id, 'api_id' => $platform_customer->api_customer_group_id])->first();
								if ($sc_pricelist_name) {
									$dc_pricelist_api_id = PlatformObjectData::select('api_id')->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $pricelist_object_id, 'name' => $sc_pricelist_name->name])->first();
									if ($dc_pricelist_api_id) {
										$CustomerData['customer_group_id'] = $dc_pricelist_api_id->api_id;
									}
								}
							}
						} else {
							$CustomerData = array("email" => $customer_address->email, "first_name" => (($customer_address->firstname) ? $customer_address->firstname : $customer_address->address_name), "last_name" => (($customer_address->lastname) ? $customer_address->lastname : ' '), "company" => $customer_address->company, "phone" => $customer_address->phone_number, "addresses" => array(array("address1" => $customer_address->address1, "address2" => $customer_address->address2, "city" => $customer_address->city, "company" => $customer_address->company, "country_code" => $customer_address->country, "first_name" => (($customer_address->firstname) ? $customer_address->firstname : $customer_address->address_name), "last_name" => (($customer_address->lastname) ? $customer_address->lastname : ' '), "phone" => $customer_address->phone_number, "postal_code" => $customer_address->postal_code, "state_or_province" => $customer_address->state)), "accepts_product_review_abandoned_cart_emails" => false, "origin_channel_id" => 1, "channel_ids" => array(1));
						}

						$customer_create_response = NULL;
						$existing_customer_response = isset($order->platform_customer_id) && ($order->platform_customer_id > 0) ? $this->SearchCustomerByID($order->platform_customer_id, ['id', 'email']) : NULL;
						//Search ID in customer table
						if ($existing_customer_response && $existing_customer_response->email) {
							$findBigCommerceCustomer = $this->connectionHelper->findCustomerByEmail($existing_customer_response->email, $userId, $this->platformId, $userIntegrationId);
							if (isset($findBigCommerceCustomer->api_customer_id)) {
								$customerID = $findBigCommerceCustomer->api_customer_id;
								$platform_customer_id = $findBigCommerceCustomer->id;
								//update customer
								$CustomerData['id'] = $findBigCommerceCustomer->api_customer_id;
								$this->createUpdateAPICustomer($platform_account, [$CustomerData], true);
							} else {
								$customer_response = $this->getAPICustomerFromEMAILWithAddress($platform_account, $customer_address->email);
								if (isset($customer_response['data'][0]['id'])) {
									$customerID = $customer_response['data'][0]['id'];

									//update customer
									$CustomerData['id'] = $customer_response['data'][0]['id'];
									$this->createUpdateAPICustomer($platform_account, [$CustomerData], true);

									if ($platform_customer) {
										$customer_address = $platform_customer;
									}

									$platform_customer_id = $this->mainModel->makeInsertGetId('platform_customer', ['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'api_customer_id' => $customerID, 'email' => ($customer_address->email ? $customer_address->email : $existing_customer_response->email), 'customer_name' => $customer_address->address_name, 'first_name' => (($customer_address->firstname) ? $customer_address->firstname : $customer_address->address_name), 'last_name' => (($customer_address->lastname) ? $customer_address->lastname : ' ')]);
								} else {
									if ($authentication_password) {
										$CustomerData['authentication'] = array("force_password_reset" => true, "new_password" => $authentication_password);
									}

									$customer_create_response = $this->createUpdateAPICustomer($platform_account, [$CustomerData]);
									if (isset($customer_create_response['data'][0]['id'])) {
										$customerID = $customer_create_response['data'][0]['id'];

										if ($platform_customer) {
											$customer_address = $platform_customer;
										}

										$platform_customer_id = $this->mainModel->makeInsertGetId('platform_customer', ['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'api_customer_id' => $customerID, 'email' => ($customer_address->email ? $customer_address->email : $existing_customer_response->email), 'customer_name' => $customer_address->address_name, 'first_name' => (($customer_address->firstname) ? $customer_address->firstname : $customer_address->address_name), 'last_name' => (($customer_address->lastname) ? $customer_address->lastname : ' ')]);
									}
								}
							}
						} else {
							if (isset($customer_address->address1) && isset($customer_address->email)) {
								$findBpCustomer = $this->connectionHelper->findCustomerByEmail($customer_address->email, $userId, $this->platformId, $userIntegrationId);
								if (isset($findBpCustomer->api_customer_id)) {
									$customerID = $findBpCustomer->api_customer_id;
									$platform_customer_id = $findBpCustomer->id;

									//update customer
									$CustomerData['id'] = $findBpCustomer->api_customer_id;
									$this->createUpdateAPICustomer($platform_account, [$CustomerData], true);
								} else {
									$customer_response = $this->getAPICustomerFromEMAILWithAddress($platform_account, $customer_address->email);
									if (isset($customer_response['data'][0]['id'])) {
										$customerID = $customer_response['data'][0]['id'];

										//update customer
										$CustomerData['id'] = $customer_response['data'][0]['id'];
										$this->createUpdateAPICustomer($platform_account, [$CustomerData], true);

										if ($platform_customer) {
											$customer_address = $platform_customer;
										}

										$platform_customer_id = $this->mainModel->makeInsertGetId('platform_customer', ['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'api_customer_id' => $customerID, 'email' => $customer_address->email, 'customer_name' => $customer_address->address_name, 'first_name' => (($customer_address->firstname) ? $customer_address->firstname : $customer_address->address_name), 'last_name' => (($customer_address->lastname) ? $customer_address->lastname : ' ')]);
									} else {
										if ($authentication_password) {
											$CustomerData['authentication'] = array("force_password_reset" => true, "new_password" => $authentication_password);
										}

										$customer_create_response = $this->createUpdateAPICustomer($platform_account, [$CustomerData]);
										if (isset($customer_create_response['data'][0]['id'])) {
											$customerID = $customer_create_response['data'][0]['id'];

											if ($platform_customer) {
												$customer_address = $platform_customer;
											}

											$platform_customer_id = $this->mainModel->makeInsertGetId('platform_customer', ['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'api_customer_id' => $customerID, 'email' => $customer_address->email, 'customer_name' => $customer_address->address_name, 'first_name' => (($customer_address->firstname) ? $customer_address->firstname : $customer_address->address_name), 'last_name' => (($customer_address->lastname) ? $customer_address->lastname : ' ')]);
										}
									}
								}
							}
						}

						//Create order in BigCommerce
						if ($customerID && is_numeric($customerID)) {
							$products = [];
							$SHIPPING = 0;
							$DISCOUNT = 0;
							$platform_order_lines = $this->mainModel->getResultByConditions('platform_order_line', ['platform_order_id' => $order->id, 'is_deleted' => 0], ['product_name', 'sku', 'qty', 'price', 'unit_price', 'ean', 'gtin', 'upc', 'mpn', 'total', 'total_tax', 'row_type', 'item_row_sequence'], ['item_row_sequence' => 'asc', 'id' => 'asc', 'row_type' => 'asc']);
							foreach ($platform_order_lines as $platform_order_line) {
								if ($platform_order_line->row_type == 'ITEM') {
									if ($platform_order_line->{$source_row_data}) {
										$platform_product = $this->mainModel->getFirstResultByConditions('platform_product', ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'is_deleted' => 0, $destination_row_data => $platform_order_line->{$source_row_data}], ['api_product_id']);
										if ($platform_product) {
											$products[] = array("product_id" => $platform_product->api_product_id, "quantity" => $platform_order_line->qty, "price_inc_tax" => (($platform_order_line->total + $platform_order_line->total_tax) / $platform_order_line->qty), "price_ex_tax" => ($platform_order_line->total / $platform_order_line->qty));
										} else {
											$products[] = array("name" => $platform_order_line->product_name, "quantity" => $platform_order_line->qty, "price_inc_tax" => (($platform_order_line->total + $platform_order_line->total_tax) / $platform_order_line->qty), "price_ex_tax" => ($platform_order_line->total / $platform_order_line->qty));
										}
									} else {
										$products[] = array("name" => $platform_order_line->product_name, "quantity" => $platform_order_line->qty, "price_inc_tax" => (($platform_order_line->total + $platform_order_line->total_tax) / $platform_order_line->qty), "price_ex_tax" => ($platform_order_line->total / $platform_order_line->qty));
									}
								} elseif ($platform_order_line->row_type == 'SHIPPING') {
									$SHIPPING = $SHIPPING + $platform_order_line->total;
								} elseif ($platform_order_line->row_type == 'DISCOUNT') {
									$DISCOUNT = $DISCOUNT + $platform_order_line->total;
								}
							}

							/*----------------Start to find order status----------------*/
							$bigcommerce_status_id = NULL;
							$sorder_status_name = $this->mainModel->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $userIntegrationId, 'platform_id' => $SourcePlatformId, 'platform_object_id' => $order_status_obj_id, 'name' => $order->order_status, 'status' => 1, 'api_code' => 'SO'], ['api_id']);
							if ($sorder_status_name) {
								$sorder_status = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, NULL, "sorder_status", ['api_id'], 'regular', $sorder_status_name->api_id, "single", $SourceOrDestination);
								if ($sorder_status) {
									$bigcommerce_status_id = $sorder_status->api_id;
								}
							} else {
								$sorder_status = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, NULL, "sorder_status", ['api_id'], 'regular', $order->order_status, "single", $SourceOrDestination);
								if ($sorder_status) {
									$bigcommerce_status_id = $sorder_status->api_id;
								}
							}

							if ($bigcommerce_status_id == NULL && $default_sorder_status) {
								$bigcommerce_status_id = $default_sorder_status->api_id;
							}
							/*----------------End to find order status----------------*/

							$discount_amount = $order->total_discount;
							$base_shipping_cost = $order->shipping_total;

							if ($SHIPPING) {
								$base_shipping_cost = $SHIPPING;
							}
							if ($DISCOUNT) {
								$discount_amount = $DISCOUNT * (-1);
							}

							$bc_create_order = array(
								"date_created" => date(DATE_RFC2822, strtotime($order->order_date)),
								"customer_id" => $customerID,
								//"currency_code"=>$order->currency, 
								"staff_notes" => "Order Number: " . $order->order_number,
								"discount_amount" => $discount_amount,
								"base_shipping_cost" => $base_shipping_cost,
								"shipping_cost_ex_tax" => $base_shipping_cost,
								"shipping_cost_inc_tax" => $base_shipping_cost,
								"billing_address" => $billing_address,
								"shipping_addresses" => $shipping_addresses,
								"products" => $products
							);

							if ($bigcommerce_status_id) {
								$bc_create_order["status_id"] = $bigcommerce_status_id;
							}

							if ($order->notes) {
								$bc_create_order["customer_message"] = $order->notes;
							}

							if (!empty($bc_create_order)) {
								$result = $this->createOrder($platform_account, $bc_create_order);
								if (isset($result['id'])) {
									$OrderLinked = $this->mainModel->makeInsertGetId('platform_order', ['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'order_type' => "SO", 'api_order_id' => $result['id'], 'order_date' => date("Y-m-d H:i:s"), 'order_number' => $result['id'], 'sync_status' => 'Pending', 'linked_id' => $order->id, 'shipment_status' => "Pending", 'created_at' => date("Y-m-d H:i:s"), 'updated_at' => date("Y-m-d H:i:s"), 'order_updated_at' => date("Y-m-d H:i:s")]);
									$this->mainModel->makeUpdate('platform_order', ['linked_id' => $OrderLinked, 'sync_status' => 'Synced'], ['id' => $order->id]);
									$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $order->id, NULL);

									PlatformOrderTransaction::create(['platform_order_id' => $OrderLinked, 'transaction_id' => random_int(999999999, 9999999999), 'transaction_datetime' => date('Y-m-d H:i:s'), 'transaction_type' => 'payment', 'transaction_method' => 'Manual', 'transaction_amount' => 0, 'transaction_approval' => 'ok', 'transaction_gateway_id' => random_int(999999999, 9999999999), 'row_type' => 'PAYMENT', 'platform_customer_id' => $platform_customer_id, 'sync_status' => 'Synced', 'linked_id' => 1]);
									$return_response = true;
								} elseif (isset($result[0]['message'])) {
									$error = $result[0]['message'];
									$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $order->id]);
									$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order->id, $result[0]['message']);
									$return_response = $error;
								} else {
									$error = $result;
									$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $order->id]);
									$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order->id, $error);
									$return_response = $error;
								}

								sleep(1);
							}
						} else {
							$error = $customer_create_response; //"Customer or address may be not found.";
							$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $order->id]);
							$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order->id, $error);
							$return_response = $error;
						}
					} elseif ($order->linked_id != 0) {
						$bigcommerce_status_id = NULL;
						$bigcommerce_status_name = NULL;
						$bigcommerce_order = $this->mainModel->getFirstResultByConditions('platform_order', ['id' => $order->linked_id], ['api_order_id', 'order_status', 'total_amount']);
						if ($bigcommerce_order) {
							/*----------------Start to find order status----------------*/
							$sorder_status_name = $this->mainModel->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $userIntegrationId, 'platform_id' => $SourcePlatformId, 'platform_object_id' => $order_status_obj_id, 'name' => $order->order_status, 'status' => 1, 'api_code' => 'SO'], ['api_id']);
							if ($sorder_status_name) {
								$sorder_status = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, NULL, "sorder_status", ['api_id', 'name'], 'regular', $sorder_status_name->api_id, "single", $SourceOrDestination);
								if ($sorder_status) {
									$bigcommerce_status_id = $sorder_status->api_id;
									$bigcommerce_status_name = $sorder_status->name;
								}
							} else {
								$sorder_status = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, NULL, "sorder_status", ['api_id', 'name'], 'regular', $order->order_status, "single", $SourceOrDestination);
								if ($sorder_status) {
									$bigcommerce_status_id = $sorder_status->api_id;
									$bigcommerce_status_name = $sorder_status->name;
								}
							}
							/*----------------End to find order status----------------*/

							//$bigcommerce_status_id == 10 Completed
							//$bigcommerce_status_id == 5 Cancelled
							$liveOrderStatus = static::getAPISalesOrderFromId($platform_account, $bigcommerce_order->api_order_id);
							if ((isset($liveOrderStatus['status']) && in_array($liveOrderStatus['status'], ['Partially Refunded', 'Refunded', 'Partially Shipped', 'Shipped']) == false) || $bigcommerce_status_id == 10 || $bigcommerce_status_id == 5) {
								if ($bigcommerce_status_id && $bigcommerce_order && ($bigcommerce_status_name != $bigcommerce_order->order_status) && in_array($order->shipment_status, ['Ready', 'Synced']) == false) {
									$bc_update_order = array("status_id" => $bigcommerce_status_id);

									$result = $this->updateOrderStatus($platform_account, $bigcommerce_order->api_order_id, $bc_update_order);
									sleep(1);
									if (isset($result['id'])) {
										$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Synced', 'order_updated_at' => date("Y-m-d H:i:s")], ['id' => $order->id]);
										$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $order->id, NULL);
										$return_response = true;
									} elseif (isset($result[0]['message'])) {
										$error = $result[0]['message'];
										$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $order->id]);
										$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order->id, $result[0]['message']);
										$return_response = $error;
									} else {
										$error = $result;
										$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Failed'], ['id' => $order->id]);
										$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order->id, $error);
										$return_response = $error;
									}
								} else {
									$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Synced'], ['id' => $order->id]);
									$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $order->id, NULL);
									$return_response = true;
								}
							} else {
								$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Synced'], ['id' => $order->id]);
								$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $order->id, NULL);
								$return_response = true;
							}
						} else {
							$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Ignore'], ['id' => $order->id]);
							$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $order->id, NULL);
							$return_response = true;
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . " -> BigCommerceSubController -> syncOrder -> " . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	public function syncOrderShipment($user_id = 0, $user_integration_id = 0, $source_platform_name = '', $platform_workflow_rule_id = 0, $user_workflow_rule_id = 0, $record_id = 0)
	{
		$return_data = true;
		$process_limit = 25;
		try {
			$source_platform_id = $this->connectionHelper->getPlatformIdByName($source_platform_name);
			$object_id = $this->connectionHelper->getObjectId('sales_order_shipment');

			$platform_account = $this->mainModel->getPlatformAccountByUserIntegration($user_integration_id, $this->platformId);
			if ($platform_account) {
				$default_sorder_shipping_method = $this->fieldMapHelper->getMappedDataByName($user_integration_id, NULL, "default_sorder_shipping_method_bc", ['name']);

				$source_row_data = $destination_row_data = 'sku';

				$product_identity_obj_id = $this->connectionHelper->getObjectId('product_identity');
				$mapping_data = $this->fieldMapHelper->getMappedField($user_integration_id, NULL, $product_identity_obj_id);
				if ($mapping_data) {
					if ($mapping_data['destination_platform_id'] == 'bigcommerce') {
						$destination_row_data = $mapping_data['destination_row_data'];
						$source_row_data = $mapping_data['source_row_data'];
					} else {
						$destination_row_data = $mapping_data['source_row_data'];
						$source_row_data = $mapping_data['destination_row_data'];
					}
				}

				$platform_order_shipments = DB::table('platform_order_shipments')
					->where(function ($query) use ($record_id, $user_id, $user_integration_id, $source_platform_id) {
						if ($record_id > 0) {
							$query->where('platform_order_id', $record_id)->where('sync_status', 'Failed')->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id]);
						} else {
							$query->where(['sync_status' => 'Ready', 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id]);
						}
					})
					->whereIn('type', ['Shipment', 'DropShipment'])
					->where(function ($query) {
						$query->whereNull('linked_id')->orWhere('linked_id', 0);
					})
					->limit($process_limit)
					->orderBy('updated_at', 'asc')
					->get();

				foreach ($platform_order_shipments as $platform_order_shipment) {
					$destination_platform_order = $this->mainModel->getFirstResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'linked_id' => $platform_order_shipment->platform_order_id], ['id', 'api_order_id', 'shipment_status']);

					$source_platform_order = $this->mainModel->getFirstResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'id' => $platform_order_shipment->platform_order_id], ['id', 'shipment_status']);
					if ($destination_platform_order && $source_platform_order) {
						$shipping_address = $this->mainModel->getFirstResultByConditions('platform_order_address', ['platform_order_id' => $destination_platform_order->id, 'address_type' => 'shipping'], ['address_id']);
						if ($shipping_address) {
							$platform_order_shipment_lines = DB::table('platform_order_shipment_lines')
								->leftJoin('platform_product', 'platform_order_shipment_lines.product_id', '=', 'platform_product.api_product_id')
								->select('platform_product.' . $source_row_data, 'platform_order_shipment_lines.quantity')
								->where('platform_order_shipment_lines.platform_order_shipment_id', $platform_order_shipment->id)
								->where('platform_product.user_id', $user_id)
								->where('platform_product.user_integration_id', $user_integration_id)
								->where('platform_product.platform_id', $source_platform_id)
								->get();

							$skuList = [];
							$skuQtyList = [];
							foreach ($platform_order_shipment_lines as $platform_order_shipment_line) {
								$skuList[] = $platform_order_shipment_line->{$source_row_data};
								$skuQtyList[$platform_order_shipment_line->{$source_row_data}] = $platform_order_shipment_line->quantity;
							}

							if (count($skuList) > 0) {
								$platform_order_lines = DB::table('platform_order_line')->select('api_order_line_id', $destination_row_data)->where('platform_order_id', $destination_platform_order->id)->whereIn($destination_row_data, $skuList)->get();

								$items = [];
								foreach ($platform_order_lines as $platform_order_line) {
									$items[] = array("order_product_id" => $platform_order_line->api_order_line_id, "quantity" => $skuQtyList[$platform_order_line->{$destination_row_data}]);
								}

								if (count($items) > 0) {
									$shippingMethodName = '';
									$sorder_shipping_method = $this->fieldMapHelper->getMappedDataByName($user_integration_id, NULL, "sorder_shipping_method", ['api_id', 'name'], 'regular', $platform_order_shipment->shipping_method);
									if ($sorder_shipping_method) {
										$shippingMethodName = $sorder_shipping_method->name;
									} elseif ($default_sorder_shipping_method) {
										$shippingMethodName = $default_sorder_shipping_method->name;
									}

									$postDATA = array("tracking_number" => (($platform_order_shipment->tracking_info) ? $platform_order_shipment->tracking_info : ""), "shipping_method" => $shippingMethodName, "comments" => "", "order_address_id" => $shipping_address->address_id, "shipping_provider" => "", "tracking_carrier" => "", "items" => $items);

									if ($platform_order_shipment->tracking_url) {
										$postDATA['tracking_link'] = $platform_order_shipment->tracking_url;
									}

									$result = $this->createOrderShipment($platform_account, $destination_platform_order->api_order_id, $postDATA);
									if (isset($result['id'])) {
										$this->mainModel->makeUpdate('platform_order_shipments', ['sync_status' => 'Synced', 'linked_id' => 1], ['id' => $platform_order_shipment->id]);
										$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'success', $source_platform_order->id, 'Shipment synced successfully!');
										$this->mainModel->makeUpdate('platform_order', ['shipment_status' => 'Synced'], ['id' => $source_platform_order->id]);
										/*
												if($source_platform_order->shipment_status == 'Ready')
												{
												$this->mainModel->makeUpdate('platform_order', ['shipment_status'=>'Synced'], ['id'=>$source_platform_order->id]);
												}
												elseif($source_platform_order->shipment_status != 'Synced')
												{
												$this->mainModel->makeUpdate('platform_order', ['shipment_status'=>'Partial'], ['id'=>$source_platform_order->id]);
												}
											*/
										$return_data = true;
									} elseif (isset($result[0]['message'])) {
										$return_data = $result[0]['message'];
										$this->mainModel->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);
										$this->mainModel->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $source_platform_order->id]);
										$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_order->id, $result[0]['message']);
									} else {
										$return_data = $result;
										$this->mainModel->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);
										$this->mainModel->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $source_platform_order->id]);
										$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_order->id, $result);
									}
								} else {
									$return_data = "Shipment product not fetched.";
									$this->mainModel->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);
									$this->mainModel->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $source_platform_order->id]);
									$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_order->id, "Shipment product not fetched.");
								}
							} else {
								$return_data = "Shipment product not fetched.";
								$this->mainModel->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);
								$this->mainModel->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $source_platform_order->id]);
								$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_order->id, "Shipment product not fetched.");
							}
						} else {
							$return_data = "Order shipping address not fetched.";
							$this->mainModel->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);
							$this->mainModel->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $source_platform_order->id]);
							$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_order->id, "Order shipping address not fetched.");
						}
					} else {
						$return_data = "Destination order not fetched.";
						$this->mainModel->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);
						$this->mainModel->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $source_platform_order->id]);
						$this->logger->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $this->platformId, $object_id, 'failed', $source_platform_order->id, "Destination order not fetched.");
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> BigCommerceSubController -> syncOrderShipment -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	/* Sync Refund Order */
	public function syncRefundOrder($userId = NULL, $userIntegrationId = NULL, $WorkFlowID = NULL, $UserWorkFlow = NULL, $SourcePlatformName = NULL, $sync_status = "Ready", $RecordID = NULL)
	{
		$return_response = false;
		try {
			$limit = 25;
			$SourcePlatformId = $this->connectionHelper->getPlatformIdByName($SourcePlatformName);
			$object_id = $this->connectionHelper->getObjectId('refund_order');

			$platform_account = $this->mainModel->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId);
			if ($platform_account) {
				$source_row_data = $destination_row_data = 'sku';
				$product_identity_obj_id = $this->connectionHelper->getObjectId('product_identity');
				$order_status_obj_id = $this->connectionHelper->getObjectId('order_status');
				$mapping_data = $this->fieldMapHelper->getMappedField($userIntegrationId, NULL, $product_identity_obj_id);
				if ($mapping_data) {
					if ($mapping_data['destination_platform_id'] == 'bigcommerce') {
						$destination_row_data = $mapping_data['destination_row_data'];
						$source_row_data = $mapping_data['source_row_data'];
					} else {
						$destination_row_data = $mapping_data['source_row_data'];
						$source_row_data = $mapping_data['destination_row_data'];
					}
				}

				$query = PlatformOrder::select('id', 'order_number', 'api_order_reference', 'order_status');
				if ($RecordID) {
					$query->where('id', $RecordID);
				} else {
					$query->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $SourcePlatformId, 'sync_status' => $sync_status]);
				}

				$source_platform_orders = $query->where('order_type', 'SC')->where('linked_id', 0)->take($limit)->orderBy('updated_at', 'asc')->get();
				foreach ($source_platform_orders as $source_platform_order) {
					$platform_order_refunds = PlatformOrderRefund::select('id')->where('platform_order_id', $source_platform_order->id)->whereIn('sync_status', ['Failed', 'Ready'])->where('linked_id', 0)->get();
					if (count($platform_order_refunds)) {
						foreach ($platform_order_refunds as $platform_order_refund) {
							$destination_platform_order = PlatformOrder::select('id', 'api_order_id')->where(['order_number' => $source_platform_order->api_order_reference, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'order_type' => 'SO'])->where('linked_id', '<>', 0)->first();
							if (is_null($destination_platform_order)) {
								$destination_platform_order = PlatformOrder::select('id', 'api_order_id')->where(['order_number' => $source_platform_order->order_number, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'order_type' => 'SO'])->where('linked_id', '<>', 0)->first();
							}

							if ($destination_platform_order) {
								/*{
										"items": [
											{
												"item_type": "PRODUCT",  // Refund a product
												"item_id": 8,            // Order product ID
												"quantity": 1,           // Quantity to refund
											},
											{
												"item_type": "SHIPPING", // Refund shipping
												"item_id": 9,            // Order address ID
												"amount": 10,            // Amount to refund
											},
											{
												"item_type": "ORDER",    // Tax-exempt order level refund
												"item_id": 9,            // Order ID
												"amount": 1,             // Amount to refund
											}
										]
									}*/

								$platform_order_refund_lines = DB::table('platform_order_refund_lines')
									->leftJoin('platform_product', 'platform_order_refund_lines.api_product_id', '=', 'platform_product.api_product_id')
									->select('platform_product.' . $source_row_data, 'platform_order_refund_lines.qty')
									->where('platform_order_refund_lines.platform_order_refund_id', $platform_order_refund->id)
									->where('platform_order_refund_lines.row_type', 'ITEM')
									->where('platform_product.user_integration_id', $userIntegrationId)
									->where('platform_product.platform_id', $SourcePlatformId)
									->where('platform_product.user_id', $userId)
									->get();

								$skuList = [];
								$skuQtyList = [];
								foreach ($platform_order_refund_lines as $platform_order_refund_line) {
									$skuList[] = $platform_order_refund_line->{$source_row_data};
									$skuQtyList[$platform_order_refund_line->{$source_row_data}] = $platform_order_refund_line->qty;
								}

								if (count($skuList)) {
									$destination_platform_order_lines = PlatformOrderLine::select('api_order_line_id', $destination_row_data)->where('platform_order_id', $destination_platform_order->id)->whereIn($destination_row_data, $skuList)->get();

									$items = [];
									foreach ($destination_platform_order_lines as $destination_platform_order_line) {
										$items[] = array("item_type" => "PRODUCT", "item_id" => (int)$destination_platform_order_line->api_order_line_id, "quantity" => $skuQtyList[$destination_platform_order_line->{$destination_row_data}]);
									}
								}

								$shipping_refund_line = DB::table('platform_order_refund_lines')->select('total')->where('platform_order_refund_id', $platform_order_refund->id)->where('row_type', 'SHIPPING')->first();

								$handling_refund_line = DB::table('platform_order_refund_lines')->select('total')->where('platform_order_refund_id', $platform_order_refund->id)->where('row_type', 'HANDLING')->first();

								if ($shipping_refund_line || $handling_refund_line) {
									$destination_platform_order_address = PlatformOrderAddress::select('address_id')->where('platform_order_id', $destination_platform_order->id)->where('address_type', 'shipping')->first();

									if ($destination_platform_order_address && $shipping_refund_line && $shipping_refund_line->total != 0) {
										$items[] = array("item_type" => "SHIPPING", "item_id" => (int)$destination_platform_order_address->address_id, "amount" => $shipping_refund_line->total);
									}

									if ($destination_platform_order_address && $handling_refund_line && $handling_refund_line->total != 0) {
										$items[] = array("item_type" => "HANDLING", "item_id" => (int)$destination_platform_order_address->address_id, "amount" => $handling_refund_line->total);
									}
								}

								if (count($items)) {
									$request_refund_quote = array("order_id" => $destination_platform_order->api_order_id, "items" => $items);

									$response_refund_quote = static::createRefundQuote($platform_account, $destination_platform_order->api_order_id, $request_refund_quote);

									Storage::append('BigCommerce/' . date('Y-m-d') . '_order_refund.txt', 'Order ID: ' . $destination_platform_order->api_order_id . ', Request Refund Quote: ' . json_encode($request_refund_quote) . ', Response: ' . json_encode($response_refund_quote));

									if (isset($response_refund_quote['data']['refund_methods'][0][0]['provider_id'])) {
										/*
											[provider_id] => storecredit
											[provider_description] => Store Credit
											[amount] => 89
											[offline] => 
											[offline_provider] => 
											[offline_reason] => 
										*/

										$refund_payment_method = NULL;
										$scorder_status_name = $this->mainModel->getFirstResultByConditions('platform_object_data', ['user_integration_id' => $userIntegrationId, 'platform_id' => $SourcePlatformId, 'platform_object_id' => $order_status_obj_id, 'name' => $source_platform_order->order_status, 'status' => 1, 'api_code' => 'SC'], ['api_id']);
										if ($scorder_status_name) {
											$payment = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, $WorkFlowID, "scorder_status", ['api_id'], 'cross', $scorder_status_name->api_id);
											if ($payment) {
												$refund_payment_method = $payment->api_id;
											}
										} else {
											$payment = $this->fieldMapHelper->getMappedDataByName($userIntegrationId, $WorkFlowID, "scorder_status", ['api_id'], 'cross', $source_platform_order->order_status);
											if ($payment) {
												$refund_payment_method = $payment->api_id;
											}
										}

										$request_refund = array("items" => $items, "payments" => array(array("provider_id" => $response_refund_quote['data']['refund_methods'][0][0]['provider_id'], "amount" => $response_refund_quote['data']['refund_methods'][0][0]['amount'], "offline" => $response_refund_quote['data']['refund_methods'][0][0]['offline'])));
										if ($refund_payment_method) {
											foreach ($response_refund_quote['data']['refund_methods'] as $refund_methods) {
												foreach ($refund_methods as $refund_method) {
													if ($refund_payment_method == $refund_method['provider_id']) {
														$request_refund = array("items" => $items, "payments" => array(array("provider_id" => $refund_method['provider_id'], "amount" => $refund_method['amount'], "offline" => $refund_method['offline'])));
													}
												}
											}
										}

										$response_request_refund = static::createRefund($platform_account, $destination_platform_order->api_order_id, $request_refund);

										Storage::append('BigCommerce/' . date('Y-m-d') . '_order_refund.txt', 'Order ID: ' . $destination_platform_order->api_order_id . ', Request Refund: ' . json_encode($request_refund) . ', Response: ' . json_encode($response_request_refund));

										if (isset($response_request_refund['data']['id'])) {
											$linkedId = $this->mainModel->makeInsertGetId('platform_order_refunds', ['platform_order_id' => $destination_platform_order->id, 'api_id' => $response_request_refund['data']['id'], 'refund_order_number' => $destination_platform_order->api_order_id, 'date_created' => date("Y-m-d H:i:s"), 'amount' => $response_refund_quote['data']['refund_methods'][0][0]['amount'], 'sync_status' => 'Synced', 'linked_id' => $platform_order_refund->id]);
											$this->mainModel->makeUpdate('platform_order_refunds', ['linked_id' => $linkedId, 'sync_status' => 'Synced'], ['id' => $platform_order_refund->id]);
											$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Synced', 'refund_sync_status' => 'Synced', 'order_updated_at' => date("Y-m-d H:i:s")], ['id' => $source_platform_order->id]);

											if (isset($response_request_refund['data']['payments'][0]['provider_id'])) {
												foreach ($response_request_refund['data']['payments'] as $payment) {
													if ($payment['is_declined'] == 0 || $payment['is_declined'] == false) {
														PlatformOrderTransaction::create(['platform_order_id' => $destination_platform_order->id, 'platform_order_refund_id' => $linkedId, 'api_transaction_index_id' => $payment['id'], 'transaction_id' => $payment['id'], 'transaction_gateway_id' => $payment['provider_id'], 'transaction_amount' => $payment['amount'], 'row_type' => 'REFUND', 'sync_status' => 'Ready']);

														$this->mainModel->makeUpdate('platform_order_refunds', ['sync_status' => 'Ready'], ['id' => $linkedId]);
													}
												}
											}

											$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $source_platform_order->id, 'Refund synced successfully!');
											$return_response = true;
										} elseif (isset($response_request_refund[0]['message'])) {
											$return_response = $response_request_refund[0]['message'];
											$this->mainModel->makeUpdate('platform_order_refunds', ['sync_status' => 'Failed'], ['id' => $platform_order_refund->id]);
											$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Failed', 'refund_sync_status' => 'Failed'], ['id' => $source_platform_order->id]);
											$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $source_platform_order->id, $response_request_refund[0]['message']);
										} else {
											$return_response = $response_request_refund;
											$this->mainModel->makeUpdate('platform_order_refunds', ['sync_status' => 'Failed'], ['id' => $platform_order_refund->id]);
											$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Failed', 'refund_sync_status' => 'Failed'], ['id' => $source_platform_order->id]);
											$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $source_platform_order->id, $response_request_refund);
										}
									} elseif (isset($response_refund_quote[0]['message'])) {
										$return_response = $response_refund_quote[0]['message'];
										$this->mainModel->makeUpdate('platform_order_refunds', ['sync_status' => 'Failed'], ['id' => $platform_order_refund->id]);
										$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Failed', 'refund_sync_status' => 'Failed'], ['id' => $source_platform_order->id]);
										$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $source_platform_order->id, $response_refund_quote[0]['message']);
									} else {
										$return_response = $response_refund_quote;
										$this->mainModel->makeUpdate('platform_order_refunds', ['sync_status' => 'Failed'], ['id' => $platform_order_refund->id]);
										$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Failed', 'refund_sync_status' => 'Failed'], ['id' => $source_platform_order->id]);
										$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $source_platform_order->id, $response_refund_quote);
									}
								} else {
									$return_response = "Refund product not fetched.";
									$this->mainModel->makeUpdate('platform_order_refunds', ['sync_status' => 'Failed'], ['id' => $platform_order_refund->id]);
									$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Failed', 'refund_sync_status' => 'Failed'], ['id' => $source_platform_order->id]);
									$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $source_platform_order->id, "Refund product not fetched.");
								}
							} else {
								$return_response = "Order number not available in destination platform.";
								$this->mainModel->makeUpdate('platform_order_refunds', ['sync_status' => 'Failed'], ['id' => $platform_order_refund->id]);
								$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Failed', 'refund_sync_status' => 'Failed'], ['id' => $source_platform_order->id]);
								$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'failed', $source_platform_order->id, "Order number not available in destination platform.");
							}
						}
					} else {
						$this->mainModel->makeUpdate('platform_order', ['sync_status' => 'Synced', 'refund_sync_status' => 'Synced'], ['id' => $source_platform_order->id]);
						$this->logger->syncLog($userId, $userIntegrationId, $UserWorkFlow, $SourcePlatformId, $this->platformId, $object_id, 'success', $source_platform_order->id, 'Refund synced successfully!');
						$return_response = true;
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . " -> BigCommerceSubController -> syncRefundOrder -> " . $e->getMessage());
			$return_response = $e->getMessage();
		}

		return $return_response;
	}
}
