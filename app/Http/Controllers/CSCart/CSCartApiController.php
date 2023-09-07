<?php

namespace App\Http\Controllers\CSCart;

use App\Helper\Api\CSCartApi;
use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use App\Helper\ConnectionHelper;
use App\Helper\FieldMappingHelper;
use App\Helper\WorkflowSnippet;
use App\Helper\Logger;
use App\Helper\MainModel;
use App\Models\PlatformAccount;
use App\Models\PlatformCustomer;
use App\Models\PlatformObjectData;
use App\Models\PlatformOrder;
use App\Models\PlatformOrderAddress;
use App\Models\PlatformOrderLine;
use App\Models\PlatformProduct;
use App\Models\PlatformProductDetailAttribute;
use App\Models\PlatformProductPriceList;
use App\Models\PlatformProductOption;
use App\Models\PlatformUrl;
use Illuminate\Support\Carbon;
use Lang;

class CSCartApiController extends Controller
{
	/**
	 * Create a new controller instance.
	 *
	 * @return void
	 */
	public $mobj, $cs, $helper, $map, $platformId, $log, $WorkflowSnippet;
	public static $myPlatform = 'cscart';
	public function __construct()
	{
		$this->mobj = new MainModel();
		$this->cs = new CSCartApi();
		$this->map = new FieldMappingHelper();
		$this->log = new Logger();
		$this->helper = new ConnectionHelper;
		$this->WorkflowSnippet = new WorkflowSnippet();
		$this->platformId = $this->helper->getPlatformIdByName(self::$myPlatform);
	}

	/* Display form for credentials */
	public function InitiateCSCartAuth(Request $request)
	{
		if ($request->isMethod('get')) {

			return view("pages.apiauth.auth_cscart", ["platform" => self::$myPlatform]);
		}
	}

	/* Check Duplicate Account Connection */
	public function CheckExistingConnectedAc($platform_id, $email, $key, $domain)
	{
		$email = $this->mobj->encrypt_decrypt($email);
		$key = $this->mobj->encrypt_decrypt($key);

		$obj_existing = PlatformAccount::select('id')->where([['platform_id', '=', $platform_id], ['app_id', '=', $email], ['app_secret', '=', $key], ['api_domain', '=', $domain]])->first();

		if ($obj_existing) {
			return true;
		} else {
			return false;
		}
	}

	/* Save Credentials */
	public function ConnectCSCartAuth(Request $request)
	{
		$regex = '/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/';

		$request->validate(['cscart_email' => 'required', 'cscart_api_key' => 'required', 'cscart_domain' => 'required|regex:' . $regex, 'custom_domain' => 'regex:' . $regex]);

		$cscart_email = trim($request->cscart_email);
		$cscart_api_key = trim($request->cscart_api_key);
		$cscart_domain = trim($request->cscart_domain);
		$custom_domain = trim($request->custom_domain);

		$data = [];

		if($this->mobj->checkHtmlTags( $request->all() ) ){
            $data['status_code'] = 0;
            $data['status_text'] = Lang::get('tags.validate');
            return json_encode($data);
        }
		
		try {
			$flag = true;
			// to check whether given account is already in use or not.
			$checkExistingAc = $this->CheckExistingConnectedAc($this->platformId, $cscart_email, $cscart_api_key, $cscart_domain);
			if ($checkExistingAc) {
				$flag = false;
				$data['status_code'] = 0;
				$data['status_text'] = 'This account detail already exist, Try with another account.';
			} else {
				if (filter_var($cscart_domain, FILTER_VALIDATE_URL) === FALSE) {
					$flag = false;
					$data['status_code'] = 0;
					$data['status_text'] = 'This is not a valid URL.';
				} else {
					if (filter_var($custom_domain, FILTER_VALIDATE_URL) === FALSE && isset($custom_domain) && $custom_domain) {
						$flag = false;
						$data['status_code'] = 0;
						$data['status_text'] = 'Custom domain is not a valid URL.';
					} else {
						$customAPI = true;
						if (isset($custom_domain) && $custom_domain) {
							$checkCustomCredentials = $this->cs->CheckCustomCredentials($custom_domain);


							if (($checkCustomCredentials !== 200 && !is_array($checkCustomCredentials))) {
								$flag = false;
								$data['status_code'] = 0;
								$data['status_text'] = 'Invalid ' . self::$myPlatform . ' Custom API  credentials!';
								$customAPI = false;
							} else {
								$customAPI = true;
							}
						}
						if ($customAPI) {
							$checkCredentials = $this->cs->CheckCredentials($cscart_email, $cscart_api_key, $cscart_domain);
							if (!isset($this->platformId) || ($checkCredentials !== 200 && !is_array($checkCredentials))) {
								$flag = false;
								$data['status_code'] = 0;
								$data['status_text'] = 'Invalid ' . self::$myPlatform . ' credentials!';
							} else {
								$domain = parse_url($cscart_domain, PHP_URL_HOST);
								$count =  PlatformAccount::where('platform_id', self::$myPlatform)->get()->count();
								$increment = $count > 0 ?  '_' . $domain . "_" . $count . "_" . date('m-d-Y') : '_' . $domain . "_" . date('m-d-Y');
								$arr_field = [
									'account_name' => self::$myPlatform . $increment,
									'user_id' => Auth::user()->id,
									'platform_id' => $this->platformId,
									'app_id' => $this->mobj->encrypt_decrypt($cscart_email),
									'app_secret' => $this->mobj->encrypt_decrypt($cscart_api_key),
									'api_domain' => $cscart_domain,
									'custom_domain' => $custom_domain,
									'allow_refresh' => 0,
								];
								$this->mobj->makeInsertGetId('platform_accounts', $arr_field);
							}
						}
					}
				}
			}
			if ($flag) {
				$data['status_code'] = 1;
				$data['status_text'] = 'Account connected successfully.';
			}
			return json_encode($data);
		} catch (\Exception $e) {
			$data['status_code'] = 0;
			$data['status_text'] = $e->getMessage();
			return json_encode($data);
		}
	}

	/* Create / Update Product Prices List*/
	public function CreateOrUpdateProductPriceList($ObjectName = NULL, $ProductPrimaryID, $Products = NULL)
	{
		$return_response = false;
		try {
			$ObjectId = $this->helper->getObjectId($ObjectName);
			if ($ObjectId) {
				$findObjectData = PlatformObjectData::select('id', 'api_id')->where([

					['platform_id', '=', $this->platformId],
					['platform_object_id', '=', $ObjectId],
				])->get();

				if (!empty($findObjectData)) {

					foreach ($findObjectData as $key => $object) {

						if (isset($Products[$object->api_id])) {
							$ProductPrice = [
								'platform_product_id' => $ProductPrimaryID,
								'platform_object_data_id' => $object->id,
								'price' => $Products[$object->api_id],
							];
							PlatformProductPriceList::updateOrCreate([
								'platform_product_id' => $ProductPrimaryID,
								'platform_object_data_id' => $object->id
							], $ProductPrice);
						}
					}
					$return_response = true;
				}
			}
		} catch (\Exception $e) {
			\Log::error("CSCartController->CreateOrUpdateProductPriceList->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	/* Insert Update Product Attributes */
	public function CreateOrUpdateProductAttributes($ProductID = NULL, $PostData = [])
	{
		if ($ProductID && !empty($PostData)) {
			PlatformProductDetailAttribute::updateOrCreate(['platform_product_id' => $ProductID], $PostData);
		}
	}
	/* Update Status=0 */
	public function GetUpdateStatus($where)
	{
		PlatformObjectData::where($where)->update(['status' => 0]);
	}
	/* Get Options */
	public function GetProductAttributes($arr, $productId)
	{
		if (!empty($arr)) {
			//Set Status 0

			PlatformProductOption::where('platform_product_id', $productId)->update(['status' => 0]);
			$find = PlatformProductOption::where([
				['platform_product_id', '=', $productId],
				['api_option_id', '=', $arr['api_option_id']],
				[
					'api_option_value_id', '=', $arr['api_option_value_id']
				]
			])->first();

			if ($find) {

				$find->api_option_id = $arr['api_option_id'];
				$find->option_name = $arr['option_name'];
				$find->option_value = $arr['option_value'];
				$find->api_option_value_id = $arr['api_option_value_id'];
				$find->status =  1;
				$find->save();
			} else {
				PlatformProductOption::insert($arr);
			}
		}
	}

	//This function basically used for find the brand from Full Description in CS CART
	public function PREG_MATCH_STRING($str)
	{
		$pattern = "/Brand-(.*)/"; //find with Sentence
		$matched = $brandName = NULL;
		preg_match($pattern, $str, $match);
		if (isset($match[1])) {
			$matched = $match[1];
		} else {
			$pattern = "/brand-(.*)/"; //find with small chars
			preg_match($pattern, $str, $match);
			if (isset($match[1])) {
				$matched = $match[1];
			}
		}
		if ($matched) {
			$text = strip_tags($matched); //remove HTML tags
			$strpos = strpos($text, "\n"); //find first \n
			if ($strpos) {
				//Get the first half of the string
				$brandName = substr($text, 0, $strpos);
			} else {
				$brandName = $text;
			}
			$brandName = str_ireplace(array("\r", "\n", '\r', '\n', '\t',), '', $brandName); //remove special chars like \n\t\r
			$brandName = preg_replace('@<(\w+)\b.*?>.*?</\1>@si', '', $brandName);
		}

		return $brandName;
	}
	/* Insert/Update CS Cart Brand */
	public function SaveBrand($user_id, $user_integration_id, $brand_id, $name)
	{
		$object_id = $this->helper->getObjectId('brand'); //Get Object ID
		$where = [
			'user_id' => $user_id,
			'user_integration_id' => $user_integration_id,
			'platform_id' => $this->platformId,
			'platform_object_id' => $object_id,
			'api_id' => $brand_id,
		];
		$fields = [
			'user_id' => $user_id,
			'user_integration_id' => $user_integration_id,
			'platform_id' =>  $this->platformId,
			'api_id' => $brand_id,
			'name' =>  $name,
			'platform_object_id' => $object_id,

		];
		PlatformObjectData::updateOrCreate($where, $fields);
	}
	/* Get Products */
	public function GetProducts($userId = NULL, $userIntegrationId = NULL, $Initial = 0)
	{
		$this->mobj->AddMemory();
		$return_response = false;
		try {
			$platform_account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['api_domain', 'app_id', 'app_secret', 'custom_domain']);
			if ($platform_account) {
				if ($Initial) {
					//To pull items
					$x = 1;
					while ($x <= 2) {
						$loopBreaker = true;
						$pageNo = PlatformUrl::select('url', 'id', 'status')->where([['user_integration_id', '=', $userIntegrationId], ['platform_id', '=', $this->platformId], ['url_name', '=', 'products']])->first();

						if (isset($pageNo->url)) {
							if ($pageNo->url == 0 && $pageNo->status == 1) {

								$loopBreaker = false;
							} else {
								$page = $pageNo->url + 1;
							}
						} else {
							$page = 1;
						}

						if ($loopBreaker) {
							$response = $this->cs->CallAPI($platform_account, "GET", "/api/products?items_per_page=1&page=1&sort_by=code&sort_order=desc");
							$before = json_decode($response->getBody(), true);
							//Call Official CS Cart API before Custom API Call
							if (isset($before)) {
								if ($response->getStatusCode() === 200) {
									if (is_array($before['products']) && isset($before['products']) && !empty($before['products'])) {
										$pageCounter = $page;
										$pageLimit = 200;
										$breakCounter = 0;
										$response = $this->cs->CallCustomAPI($platform_account, "GET", "/api/v1/product?per_page={$pageLimit}&page={$page}&sort_by=updated_timestamp&order_by=asc&filter_by=updated_timestamp");
										$items = json_decode($response->getBody(), true);

										if (isset($items)) {
											if ($response->getStatusCode() === 200) {

												if (is_array($items['data']) && isset($items['data']) && !empty($items['data'])) {

													if (isset($items['data']) && $items['data'] && is_array($items['data'])) {

														foreach ($items['data'] as $product) {
															$categoryids = $brandID = NULL;
															if (is_array($product['category_ids']) && $product['category_ids']) {

																foreach ($product['category_ids'] as $key => $item) {
																	$categoryids .= $item['category_id'] . ',';
																}
																$categoryids = rtrim($categoryids, ",");
															}
															if (isset($product['supplier']['supplier_id'])) {

																// //Extract brand from full description
																// $brandID=$this->PREG_MATCH_STRING($product['description']['full_description']);
																$brandID = $product['supplier']['supplier_id'];
																//save/update brand
																$this->SaveBrand($userId, $userIntegrationId, $brandID, $product['supplier']['links']['name']);
															}
															$productData = [
																'user_id' => $userId,
																'user_integration_id' => $userIntegrationId,
																'platform_id' => $this->platformId,
																'api_product_id' => $product['product_id'],
																'api_product_code' => $product['product_code'],
																'sku' => $product['product_code'],
																'product_name' => isset($product['description']['product']) ? $product['description']['product'] : NULL,
																'weight' => $product['weight'],
																'product_status' => $product['status'],
																'price' => isset($product['price']['price']) ? $product['price']['price'] : NULL,
																'is_deleted' => in_array($product['status'], ['D', 'H']) ? 1 : 0,
																'bundle' => 0, 'stock_track' => $product['tracking'] == "B" ? 1 : 0,
																'category_id' => $categoryids,
																'brand_id' => $brandID,
																'api_updated_at' => date('Y-m-d H:i:s', $product['updated_timestamp'])
															];

															$platform_product = $this->mobj->getFirstResultByConditions('platform_product', [
																'user_integration_id' => $userIntegrationId,
																'platform_id' => $this->platformId,
																'api_product_id' => $product['product_id']
															], ['id', 'api_updated_at']);
															if ($platform_product) {
																if ($platform_product->api_updated_at != date('Y-m-d H:i:s', $product['updated_timestamp'])) {
																	$productData['product_sync_status'] = "Ready";
																}
																$this->mobj->makeUpdate('platform_product', $productData, ['id' => $platform_product->id]);
																$platform_product_id = $platform_product->id;
															} else {
																$productData['product_sync_status'] = "Ready";
																$platform_product_id = $this->mobj->makeInsertGetId('platform_product', $productData);
															}

															$AttributeData = [
																'platform_product_id' => $platform_product_id,
																'fulldescription' => isset($product['description']['full_description']) ? $product['description']['full_description'] : NULL,
																'shortdescription' => isset($product['description']['short_description']) ? $product['description']['short_description'] : NULL,
																'lenght' => $product['length'],
																'height' => $product['height'],
																'width' => $product['width'],
																// 'taxcode_ids' => is_array($product['tax_ids']) && $product['tax_ids'] ? implode(",", $product['tax_ids']) : NULL
															];

															/* Product Extra Attribute */
															$this->CreateOrUpdateProductAttributes($platform_product_id, $AttributeData);
															// /* Product Price */
															// $this->CreateOrUpdateProductPriceList("pricelist", $platform_product_id, $product);
															if (isset($product['has_options']) && $product['has_options']) {
																$product_ID = $product['product_id'];
																$response = $this->cs->CallAPI($platform_account, "GET", "/api/options?product_id={$product_ID}");
																$option = json_decode($response->getBody(), true);

																if (isset($option)) {
																	if ($response->getStatusCode() === 200) {
																		if (is_array($option) && isset($option) && !empty($option)) {
																			foreach ($option  as $attr) {

																				if (isset($attr['variants']) && $attr['variants']) {

																					foreach ($attr['variants']  as $var) {
																						$attrOption = [
																							'api_option_id' => $attr['option_id'],
																							'platform_product_id' => $platform_product_id,
																							'option_name' => isset($attr['option_name']) ? $attr['option_name'] : NULL,
																							'api_option_value_id' => $var['variant_id'],
																							'option_value' => $var['variant_name'],
																							'status' => 1
																						];



																						$this->GetProductAttributes($attrOption, $platform_product_id);
																					}
																				}
																			}
																		}
																	}
																}
															}
														}
													}

													if ($breakCounter == 0) {

														if (isset($pageNo->url)) {
															$pageNo->url = $page;
															$pageNo->status = 0;
															$pageNo->save();
														} else {
															PlatformUrl::insert([
																'user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId,
																'url' => $page + 1,
																'url_name' => 'products',
																'status' => 0
															]);
														}

														$return_response = "Page-{$pageCounter} data processed";
													} else {
														$return_response = "API Error to get products from CS-Cart";
													}
												} elseif (is_array($items['data']) && isset($items['data']) && empty($items['data'])) {

													if (isset($pageNo->url)) {
														$pageNo->url = 0;
														$pageNo->status = 1;
														$pageNo->save();
													}
													$return_response = true;
												} else {
													$return_response = isset($items['error']) ? $items['error'] : "Internal API Error";
													$breakCounter = 1;
													break;
												}
											} else {
												$return_response = isset($items['error']) ? $items['error'] : "API Error:Unauthorized";
												$breakCounter = 1;
												break;
											}
										} else {
											if ($response->getStatusCode() !== 200) {
												$return_response = "API Error:Unauthorized";
											} else {
												$return_response = "API Error";
											}
											break;
										}
									}
								}
							}
						}
						$x++;
					}
				} else {
					$response = $this->cs->CallAPI($platform_account, "GET", "/api/products?items_per_page=1&page=1&sort_by=code&sort_order=desc");
					$before = json_decode($response->getBody(), true);
					//Call Official CS Cart API before Custom API Call

					if (isset($before)) {
						if ($response->getStatusCode() === 200) {
							if (is_array($before['products']) && isset($before['products']) && !empty($before['products'])) {

								$page = 1;
								$pageLimit = 200;
								$findDate = PlatformProduct::select('api_updated_at')->where([
									'user_integration_id' => $userIntegrationId,
									'platform_id' => $this->platformId
								])->orderByRaw("DATE_FORMAT(api_updated_at, '%Y-%m-%d %H-%i-%s') DESC")->first();
								if ($findDate) {
									$from_timestamp = strtotime($findDate->api_updated_at);
								} else {
									$from_timestamp = Carbon::now()->subMinutes(15)->timestamp;
								}

								$to_timestamp = Carbon::now()->timestamp;
								//Custom API Call
								$response = $this->cs->CallCustomAPI($platform_account, "GET", "/api/v1/product?per_page={$pageLimit}&page={$page}&sort_by=updated_timestamp&from_timestamp={$from_timestamp}&to_timestamp={$to_timestamp}&order_by=asc&filter_by=updated_timestamp");

								$items = json_decode($response->getBody(), true);

								if (isset($items)) {
									if ($response->getStatusCode() === 200) {
										if (isset($items['data']) && $items['data'] && is_array($items['data'])) {

											foreach ($items['data'] as $product) {
												$categoryids = $brandID = NULL;
												if (is_array($product['category_ids']) && $product['category_ids']) {

													foreach ($product['category_ids'] as $key => $item) {
														$categoryids .= $item['category_id'] . ',';
													}
													$categoryids = rtrim($categoryids, ",");
												}
												if (isset($product['supplier']['supplier_id'])) {

													// //Extract brand from full description
													// $brandID=$this->PREG_MATCH_STRING($product['description']['full_description']);
													$brandID = $product['supplier']['supplier_id'];
													//save/update brand
													$this->SaveBrand($userId, $userIntegrationId, $brandID, $product['supplier']['links']['name']);
												}

												$productData = [
													'user_id' => $userId,
													'user_integration_id' => $userIntegrationId,
													'platform_id' => $this->platformId,
													'api_product_id' => $product['product_id'],
													'api_product_code' => $product['product_code'],
													'sku' => $product['product_code'],
													'product_name' => isset($product['description']['product']) ? $product['description']['product'] : NULL,
													'weight' => $product['weight'],
													'product_status' => $product['status'],
													'price' => isset($product['price']['price']) ? $product['price']['price'] : NULL,
													'is_deleted' => in_array($product['status'], ['D', 'H']) ? 1 : 0,
													'bundle' => 0,
													'stock_track' => $product['tracking'] == "B" ? 1 : 0,
													'category_id' => $categoryids,
													'brand_id' => $brandID,
													'api_updated_at' => date('Y-m-d H:i:s', $product['updated_timestamp'])
												];

												$platform_product = $this->mobj->getFirstResultByConditions('platform_product', [
													'user_integration_id' => $userIntegrationId,
													'platform_id' => $this->platformId,
													'api_product_id' => $product['product_id']
												], ['id', 'api_updated_at']);
												if ($platform_product) {
													if ($platform_product->api_updated_at != date('Y-m-d H:i:s', $product['updated_timestamp'])) {
														$productData['product_sync_status'] = "Ready";
													}
													$this->mobj->makeUpdate('platform_product', $productData, ['id' => $platform_product->id]);
													$platform_product_id = $platform_product->id;
												} else {
													$productData['product_sync_status'] = "Ready";
													$platform_product_id = $this->mobj->makeInsertGetId('platform_product', $productData);
												}

												$AttributeData = [
													'platform_product_id' => $platform_product_id,
													'fulldescription' => isset($product['description']['full_description']) ? $product['description']['full_description'] : NULL,
													'shortdescription' => isset($product['description']['short_description']) ? $product['description']['short_description'] : NULL,
													'lenght' => $product['length'],
													'height' => $product['height'],
													'width' => $product['width'],
													// 'taxcode_ids' => is_array($product['tax_ids']) && $product['tax_ids'] ? implode(",", $product['tax_ids']) : NULL
												];

												/* Product Extra Attribute */
												$this->CreateOrUpdateProductAttributes($platform_product_id, $AttributeData);
												// /* Product Price */
												// $this->CreateOrUpdateProductPriceList("pricelist", $platform_product_id, $product);

												if (isset($product['has_options']) && $product['has_options']) {
													$product_ID = $product['product_id'];
													$response = $this->cs->CallAPI($platform_account, "GET", "/api/options?product_id={$product_ID}");
													$option = json_decode($response->getBody(), true);

													if (isset($option)) {
														if ($response->getStatusCode() === 200) {
															if (is_array($option) && isset($option) && !empty($option)) {
																foreach ($option  as $attr) {

																	if (isset($attr['variants']) && $attr['variants']) {

																		foreach ($attr['variants']  as $var) {
																			$attrOption = [
																				'api_option_id' => $attr['option_id'],
																				'platform_product_id' => $platform_product_id,
																				'option_name' => isset($attr['option_name']) ? $attr['option_name'] : NULL,
																				'api_option_value_id' => $var['variant_id'],
																				'option_value' => $var['variant_name'],
																				'status' => 1
																			];

																			$this->GetProductAttributes($attrOption, $platform_product_id);
																		}
																	}
																}
															}
														}
													}
												}
												$return_response = true;
											}
										} elseif (is_array($items['data']) && isset($items['data']) && empty($items['data'])) {
											$return_response = true;
										} else {
											$return_response = isset($items['error']) ? $items['error'] : "Internal API Error";
										}
									} else {
										$return_response = isset($items['error']) ? $items['error'] : "API Error:Unauthorized";
									}
								} else {
									if ($response->getStatusCode() !== 200) {
										$return_response = "API Error:Unauthorized";
									} else {
										$return_response = "API Error";
									}
								}
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "->CSCartController->GetProducts->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}
	/* Get Carries | Shipping Methods */
	public function GetShippingMethods($userId = NULL, $userIntegrationId = NULL, $attempt, $Initial = 0)
	{
		$return_response = false;
		try {
			$account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['platform_id', 'id', 'user_id', 'api_domain', 'app_id', 'app_secret']);
			if ($account) {
				$object_id = $this->helper->getObjectId('shipping_method'); //Get Object ID
				if ($object_id) {
					$this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $object_id, 'status' => 1]);

					$response = $this->cs->CallAPI($account, "GET", "/api/shippings?page=1&items_per_page=500&sort_by=shipping_id&sort_order=desc");
					$result = json_decode($response->getBody(), true);
					if (isset($result['shippings'][0]['shipping_id'])) {
						foreach ($result['shippings'] as $shipping) {
							$fields = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'api_id' => $shipping['shipping_id'], 'api_code' => $shipping['shipping'], 'name' => $shipping['shipping'], 'description' => $shipping['delivery_time'], 'platform_object_id' => $object_id];

							if ($shipping['status'] == 'A') {
								$fields['status'] = 1;
							} else {
								$fields['status'] = 0;
							}

							$where = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'platform_object_id' => $object_id, 'api_id' => $shipping['shipping_id']];

							PlatformObjectData::updateOrCreate($where, $fields);
						}
						$return_response = true;
					} else {
						if ($response->getStatusCode() !== 200) {
							$return_response = "API Error:Unauthorized";
						} else {
							$return_response = "API Error";
						}
					}
				} else {
					$return_response = "Object ID not found";
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "->CSCartController->GetShippingMethods->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	/* Get Tax Codes */
	public function GetTaxCodes($userId = NULL, $userIntegrationId = NULL, $attempt, $Initial = 0)
	{
		$return_response = false;
		try {
			$platform_account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['api_domain', 'app_id', 'app_secret']);
			if ($platform_account) {
				$object_id = $this->helper->getObjectId('taxcode'); //Get Object ID
				if ($object_id) {
					$this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $object_id, 'status' => 1]);

					$response = $this->cs->CallAPI($platform_account, "GET", "/api/taxes?page=1&items_per_page=500&sort_by=tax_id&sort_order=desc");
					$result = json_decode($response->getBody(), true);
					if (isset($result['taxes'][0]['tax_id'])) {
						foreach ($result['taxes'] as $tax) {
							$fields = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'api_id' => $tax['tax_id'], 'api_code' => $tax['tax'], 'name' => $tax['tax'], 'description' => $tax['regnumber'], 'platform_object_id' => $object_id];

							if ($tax['status'] == 'A') {
								$fields['status'] = 1;
							} else {
								$fields['status'] = 0;
							}

							$where = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $object_id, 'api_id' => $tax['tax_id']];
							PlatformObjectData::updateOrCreate($where, $fields);
						}
						$return_response = true;
					} else {
						if ($response->getStatusCode() !== 200) {
							$return_response = "API Error:Unauthorized";
						} else {
							$return_response = "API Error";
						}
					}
				} else {
					$return_response = "Object ID not found";
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "->CSCartApiController->GetTaxCodes->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	/* Get Catagories Codes */
	public function GetCategories($userId = NULL, $userIntegrationId = NULL, $attempt, $Initial = 0)
	{
		$return_response = false;
		try {
			$platform_account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['api_domain', 'app_id', 'app_secret']);
			if ($platform_account) {
				//To pull category id
				$object_id = $this->helper->getObjectId('category'); //Get Object ID
				if ($object_id) {
					//update status to 0.
					// PlatformObjectData::where(['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $object_id])
					// 	->update(['status' => 0]);

					$x = true;
					$page = 1;
					$pageLimit = 100;
					while ($x) {
						$response = $this->cs->CallAPI($platform_account, "GET", "/api/categories?page={$page}&items_per_page={$pageLimit}");
						$category = json_decode($response->getBody(), true);
						if (isset($category)) {
							if ($response->getStatusCode() === 200) {
								if (isset($category['categories'][0]['category_id'])) {
									foreach ($category['categories'] as $value) {
										$fields = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'api_id' => $value['category_id'], 'name' => $value['category'], 'platform_object_id' => $object_id, "status" => 1];

										$where = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $object_id, 'api_id' => $value['category_id']];
										PlatformObjectData::updateOrCreate($where, $fields);
									}

									$return_response = true;

									if (count($category['categories']) != $pageLimit) {
										$x = false;
										break;
									}
								} elseif (is_array($category['categories']) && isset($category['categories']) && empty($category['categories'])) {
									$return_response = true;
									$x = false;

									break;
								} else {
									$return_response = isset($category['ErrorCode']) ? $category['ErrorCode'] : "Internal API Error";
									$x = false;
									break;
								}
								$page++;
							} else {
								$return_response = isset($category['message']) ? $category['message'] : "API Error:Unauthorized";
								$x = false;
								break;
							}
						} else {
							if ($response->getStatusCode() !== 200) {
								$return_response = "API Error:Unauthorized";
							} else {
								$return_response = "API Error";
							}
							$x = false;
							break;
						}
					}
				} else {
					$return_response = "Object ID not found";
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "->CSCartApiController->GetCategories->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}
	/* Get Payment Method */
	public function GetPaymentMethods($userId = NULL, $userIntegrationId = NULL, $attempt, $Initial = 0)
	{
		$return_response = false;
		try {
			$account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId,  $this->platformId, ['platform_id', 'id', 'user_id', 'api_domain', 'app_id', 'app_secret']);

			if ($account) {
				$object_id = $this->helper->getObjectId('payment'); //Get Object ID
				if ($object_id) {
					$this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $object_id, 'status' => 1]);

					$response = $this->cs->CallAPI($account, "GET", "/api/payments?page=1&items_per_page=500");
					$result = json_decode($response->getBody(), true);
					if (isset($result['payments'][0]['payment_id'])) {
						foreach ($result['payments'] as $payment) {
							$fields = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'api_id' => $payment['payment_id'], 'api_code' => $payment['payment'], 'name' => $payment['payment'], 'description' => $payment['description'], 'platform_object_id' => $object_id];

							if ($payment['status'] == 'A') {
								$fields['status'] = 1;
							} else {
								$fields['status'] = 0;
							}

							$where = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'platform_object_id' => $object_id, 'api_id' => $payment['payment_id']];
							PlatformObjectData::updateOrCreate($where, $fields);
						}

						$return_response = true;
					} else {
						if ($response->getStatusCode() !== 200) {
							$return_response = "API Error:Unauthorized";
						} else {
							$return_response = "API Error";
						}
					}
				} else {
					$return_response = "Object ID not found";
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "->CSCartController->GetPaymentMethods->" . $e->getMessage());
			$return_response = $e->getMessage();
		}

		return $return_response;
	}

	/* Get Order Status */
	public function GetOrderStatus($userId = NULL, $userIntegrationId = NULL, $attempt, $Initial = 0)
	{
		$return_response = false;
		try {
			$account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId, $this->platformId, ['platform_id', 'id', 'user_id', 'api_domain', 'app_id', 'app_secret']);
			if ($account) {
				$object_id = $this->helper->getObjectId('order_status'); //Get Object ID
				if ($object_id) {
					$this->mobj->makeUpdate('platform_object_data', ['status' => 0], ['user_integration_id' => $userIntegrationId, 'platform_id' => $this->platformId, 'platform_object_id' => $object_id, 'status' => 1]);

					$response = $this->cs->CallAPI($account, "GET", "/api/statuses?page=1&items_per_page=500");
					$result = json_decode($response->getBody(), true);
					if (isset($result['statuses'][0]['status_id'])) {
						foreach ($result['statuses'] as $status) {
							$fields = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'api_id' => $status['status_id'], 'api_code' => $status['status'], 'status' => 1, 'name' => $status['description'], 'description' => $status['description'], 'platform_object_id' => $object_id];

							$where = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'platform_object_id' => $object_id, 'api_id' => $status['status_id']];

							PlatformObjectData::updateOrCreate($where, $fields);
						}

						$return_response = true;
					} else {
						if ($response->getStatusCode() !== 200) {
							$return_response = "API Error:Unauthorized";
						} else {
							$return_response = "API Error";
						}
					}
				} else {
					$return_response = "Object ID not found";
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "->CSCartController->GetOrderStatus->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	/* Get Customers */
	public function GetCustomers($userId = NULL, $userIntegrationId = NULL, $attempt, $Initial = 0)
	{
		$this->mobj->AddMemory();
		$return_response = false;
		try {
			$account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId,  $this->platformId, ['platform_id', 'id', 'user_id', 'api_domain', 'app_id', 'app_secret']);

			if ($account && $this->platformId) {

				if (isset($account->platform_id) && $account->platform_id == $this->platformId) {

					if ($attempt == 1 && $Initial == 1) { // To pull users
						$x = true;
						$page = 1;
						$pageLimit = 100;
						while ($x) {

							$response = $this->cs->CallAPI($account, "GET", "/api/users?user_type=C&items_per_page={$pageLimit}&page={$page}");
							$customers = json_decode($response->getBody(), true);
							if (isset($customers)) {
								if ($response->getStatusCode() === 200) {
									if (is_array($customers['users']) && isset($customers['users']) && !empty($customers['users'])) {

										foreach ($customers['users'] as $key => $value) {
											if (isset($value['email'])) {
												$fname = isset($value['firstname']) ? $value['firstname'] : NULL;
												$lname = isset($value['lastname']) ? $value['lastname'] : NULL;
												$customer_name = $fname . " " . $lname;
												$fields = [
													'user_id' => $userId,
													'user_integration_id' => $userIntegrationId,
													'platform_id' => $account->platform_id,
													'api_customer_id' => isset($value['user_id']) ? $value['user_id'] : NULL,
													'first_name' => isset($value['firstname']) ? $value['firstname'] : NULL,
													'last_name' => isset($value['lastname']) ? $value['lastname'] : NULL,
													'email' => isset($value['email']) ? $value['email'] : NULL,
													'company_id' => isset($value['company_id']) ? $value['company_id'] : NULL,
													'customer_name' => $customer_name
												];
												$where = [
													'user_id' => $userId,
													'user_integration_id' => $userIntegrationId,
													'platform_id' => $account->platform_id,
													'api_customer_id' => isset($value['user_id']) ? $value['user_id'] : NULL,
												];
												PlatformCustomer::updateOrCreate($where, $fields);
											}
										}
									} else if (is_array($customers['users']) && isset($customers['users']) && empty($customers['users'])) {
										$return_response = $x;
										$x = false;
										break;
									} else {
										$return_response = isset($customers['ErrorCode']) ? $customers['ErrorCode'] : "Internal API Error";
										$x = false;
										break;
									}
									$page++;
								} else {
									$return_response = isset($customers['message']) ? $customers['message'] : "API Error:Unauthorized";
									$x = false;
									break;
								}
							} else {
								if ($response->getStatusCode() !== 200) {
									$return_response = "API Error:Unauthorized";
								} else {
									$return_response = "API Error";
								}
								$x = false;
								break;
							}
						}
					} else if ($attempt == 2 && $Initial == 0) {
						$x = true;
						$page = 1;
						$pageLimit = 100;
						$date = time();
						$response = $this->cs->CallAPI($account, "GET", "/api/users?user_type=C&items_per_page={$pageLimit}&page={$page}&updated_timestamp={$date}");
						$customers = json_decode($response->getBody(), true);
						$customers = json_decode($response->getBody(), true);
						if (isset($customers)) {
							if ($response->getStatusCode() === 200) {
								if (is_array($customers['users']) && isset($customers['users']) && !empty($customers['users'])) {
									foreach ($customers['users'] as $key => $value) {
										if (isset($value['email'])) {
											$fname = isset($value['firstname']) ? $value['firstname'] : NULL;
											$lname = isset($value['lastname']) ? $value['lastname'] : NULL;
											$customer_name = $fname . " " . $lname;
											$fields = [
												'user_id' => $userId,
												'user_integration_id' => $userIntegrationId,
												'platform_id' => $account->platform_id,
												'api_customer_id' => isset($value['user_id']) ? $value['user_id'] : NULL,
												'first_name' => isset($value['firstname']) ? $value['firstname'] : NULL,
												'last_name' => isset($value['lastname']) ? $value['lastname'] : NULL,
												'email' => isset($value['email']) ? $value['email'] : NULL,
												'company_id' => isset($value['company_id']) ? $value['company_id'] : NULL,
												'customer_name' => $customer_name
											];
											$where = [
												'user_id' => $userId,
												'user_integration_id' => $userIntegrationId,
												'platform_id' => $account->platform_id,
												'api_customer_id' => isset($value['user_id']) ? $value['user_id'] : NULL,
											];
											PlatformCustomer::updateOrCreate($where, $fields);
										}
									}
									$return_response = true;
								} else if (is_array($customers['users']) && isset($customers['users']) && empty($customers['users'])) {
									$return_response = true;
								} else {
									$return_response = isset($customers['ErrorCode']) ? $customers['ErrorCode'] : "Internal API Error";
								}
							} else {
								$return_response = isset($customers['message']) ? $customers['message'] : "API Error:Unauthorized";
							}
						} else {
							if ($response->getStatusCode() !== 200) {
								$return_response = "API Error:Unauthorized";
							} else {
								$return_response = "API Error";
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "->CSCartController->GetCustomers->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}
	/* Get Customer By ID */
	public function GetCustomerById($CustomerID = NULL, $userId = NULL, $userIntegrationId = NULL, $account = NULL)
	{
		$return_response = false;
		try {
			if ($account) {
				$response = $this->cs->CallAPI($account, "GET", "/api/users/{$CustomerID}");
				if ($customers = json_decode($response->getBody(), true)) {
					if (is_array($customers['customers']) && isset($customers['customers']) && !empty($customers['customers'])) {
						foreach ($customers['customers'] as $key => $value) {
							if (isset($value['email'])) {
								$fname = isset($value['firstname']) ? $value['firstname'] : NULL;
								$lname = isset($value['lastname']) ? $value['lastname'] : NULL;
								$customer_name = $fname . " " . $lname;
								$fields = [
									'user_id' => $userId,
									'user_integration_id' => $userIntegrationId,
									'platform_id' => $account->platform_id,
									'api_customer_id' => isset($value['user_id']) ? $value['user_id'] : NULL,
									'first_name' => isset($value['firstname']) ? $value['firstname'] : NULL,
									'last_name' => isset($value['lastname']) ? $value['lastname'] : NULL,
									'email' => isset($value['email']) ? $value['email'] : NULL,
									'company_id' => isset($value['company_id']) ? $value['company_id'] : NULL,
									'customer_name' => $customer_name
								];
								$where = [
									'user_id' => $userId,
									'user_integration_id' => $userIntegrationId,
									'platform_id' => $account->platform_id,
									'api_customer_id' => isset($value['user_id']) ? $value['user_id'] : NULL,
								];
								$LastID = PlatformCustomer::updateOrCreate($where, $fields);
							}
						}
						$return_response = $LastID;
					} else if (is_array($customers['customers']) && isset($customers['customers']) && empty($customers['customers'])) {
						$return_response = true;
					} else {
						$return_response = isset($customers['ErrorCode']) ? $customers['ErrorCode'] : "Internal API Error";
					}
				} else {
					if ($response->getStatusCode() !== 200) {
						$return_response = "API Error:Unauthorized";
					} else {
						$return_response = "API Error";
					}
				}
			} else {
				$account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId,  $this->platformId, ['platform_id', 'id', 'user_id', 'api_domain', 'app_id', 'app_secret']);

				if ($account && $this->platformId) {

					if (isset($account->platform_id) && $account->platform_id == $this->platformId) {

						$response = $this->cs->CallAPI($account, "GET", "/api/users/{$CustomerID}");
						if ($customers = json_decode($response->getBody(), true)) {
							if (is_array($customers['customers']) && isset($customers['customers']) && !empty($customers['customers'])) {
								foreach ($customers['customers'] as $key => $value) {
									if (isset($value['email'])) {
										$fname = isset($value['firstname']) ? $value['firstname'] : NULL;
										$lname = isset($value['lastname']) ? $value['lastname'] : NULL;
										$customer_name = $fname . " " . $lname;
										$fields = [
											'user_id' => $userId,
											'user_integration_id' => $userIntegrationId,
											'platform_id' => $account->platform_id,
											'api_customer_id' => isset($value['user_id']) ? $value['user_id'] : NULL,
											'first_name' => isset($value['firstname']) ? $value['firstname'] : NULL,
											'last_name' => isset($value['lastname']) ? $value['lastname'] : NULL,
											'email' => isset($value['email']) ? $value['email'] : NULL,
											'company_id' => isset($value['company_id']) ? $value['company_id'] : NULL,
											'customer_name' => $customer_name
										];
										$where = [
											'user_id' => $userId,
											'user_integration_id' => $userIntegrationId,
											'platform_id' => $account->platform_id,
											'api_customer_id' => isset($value['user_id']) ? $value['user_id'] : NULL,
										];
										PlatformCustomer::updateOrCreate($where, $fields);
									}
								}
								$return_response = true;
							} else if (is_array($customers['customers']) && isset($customers['customers']) && empty($customers['customers'])) {
								$return_response = true;
							} else {
								$return_response = isset($customers['ErrorCode']) ? $customers['ErrorCode'] : "Internal API Error";
							}
						} else {
							if ($response->getStatusCode() !== 200) {
								$return_response = "API Error:Unauthorized";
							} else {
								$return_response = "API Error";
							}
						}
					}
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "->CSCartController->GetCustomerById->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}
	/* Search customer id in platform_customer table */
	public function SearchCustomerByID($CustomerID = NULL, $userId = NULL, $userIntegrationId = NULL, $PlatformId = NULL, $account = NULL)
	{
		$return_response = false;
		$findCustomer = PlatformCustomer::select('id')->where([
			['user_integration_id', '=', $userIntegrationId],
			['platform_id', '=', $PlatformId],
			['api_customer_id', '=', $CustomerID]
		])->first();
		if ($findCustomer) {
			$return_response = $findCustomer->id;
		} else {
			$return_response = $this->GetCustomerById($CustomerID, $userId, $userIntegrationId, $account);
		}
		return $return_response;
	}

	/* Get Orders */
	public function GetOrders($userId = NULL, $userIntegrationId = NULL)
	{
		$this->mobj->AddMemory();
		$return_response = false;
		try {
			$EventID = "GET_SALESORDER";
			
			$selectFields = ['e.event_id','ur.status'];

			$user_work_flow = $this->map->getUserIntegWorkFlow($userIntegrationId, $EventID, $selectFields, self::$myPlatform);
			
			if(isset($user_work_flow[$EventID])){
				/* First Check whether Order Sync is ON */
				if ($user_work_flow[$EventID]['status'] == 1) {
				$account = $this->mobj->getPlatformAccountByUserIntegration($userIntegrationId,  $this->platformId, ['platform_id', 'id', 'user_id', 'api_domain', 'app_id', 'app_secret']);

				if ($account) {
					$user_workflow_rule = $this->mobj->getFirstResultByConditions('user_workflow_rule', ['user_integration_id' => $userIntegrationId, 'status' => 1], ['platform_workflow_rule_id']);
					if ($user_workflow_rule) {
						//get mapped order statuses
						$order_status_list = $this->map->getMappedDataByName($userIntegrationId, $user_workflow_rule->platform_workflow_rule_id, "get_sorder_status", ['api_code'], "regular", NULL, "multi", "source");

						foreach ($order_status_list as $orderstatus) {
							//To Get New/Updated Order
							$response = $this->cs->CallAPI($account, "GET", "/api/orders?items_per_page=50&page=1&sort_by=date&sort_order=desc&status={$orderstatus}");
							$result = json_decode($response->getBody(), true);

							if (isset($result['orders'])) {
								if (isset($result['orders'][0]['order_id'])) {
									foreach ($result['orders'] as $order) {
										$orderDetails = $this->GetOrderByID($order['order_id'], $userId, $userIntegrationId, $this->platformId, $account);
										if (isset($orderDetails['order_id'])) {
											//echo "<pre>";
											//print_r($orderDetails);//die;
											if ($orderDetails['status'] == 'I') {
												$platform_order = PlatformOrder::select('id', 'api_updated_at')->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'sync_status' => "Synced", 'api_order_id' => $order['order_id']])->first();
												if ($platform_order) {
													$platform_order_id = $platform_order->id;
													$OrderShipping = $orderDetails['shipping_cost'];
													$OrderDiscount = $orderDetails['subtotal_discount'];
													$OrderSalesTax = $orderDetails['tax_subtotal'];

													$CustomerData = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'api_customer_id' => $orderDetails['user_id'], 'api_customer_code' => $orderDetails['user_id'], 'customer_name' => $orderDetails['b_firstname'] . ' ' . $orderDetails['b_lastname'], 'first_name' => $orderDetails['b_firstname'], 'last_name' => $orderDetails['b_lastname'], 'company_name' => $orderDetails['company'], 'phone' => $orderDetails['b_phone'], 'fax' => $orderDetails['fax'], 'email' => $orderDetails['email'], 'address1' => $orderDetails['s_address'], 'address2' => $orderDetails['s_address_2'], 'address3' => $orderDetails['b_city'], 'postal_addresses' => $orderDetails['b_zipcode'], 'country' => $orderDetails['b_country'], 'company_id' => $orderDetails['company_id']];

													if ($orderDetails['user_id']) {
														$platform_customer = PlatformCustomer::select('id')->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'api_customer_id' => $orderDetails['user_id']])->first();
													} else {
														$platform_customer = PlatformCustomer::select('id')->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'email' => $orderDetails['email']])->first();
													}

													if ($platform_customer) {
														$platform_customer_id = $platform_customer->id;
														PlatformCustomer::where('id', $platform_customer->id)->update($CustomerData);
													} else {
														$platform_customer_id = PlatformCustomer::insertGetId($CustomerData);
													}

													$orderData = ['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'platform_customer_id' => $platform_customer_id, 'order_type' => "SO", 'customer_email' => $order['email'], 'api_order_id' => $order['order_id'], 'order_number' => $order['order_id'], 'order_date' => date("Y-m-d H:i:s", $order['timestamp']), 'currency' => @$orderDetails['secondary_currency'], 'order_status' => $orderDetails['status'], 'vendor' => $orderDetails['company_id'], 'total_discount' => $orderDetails['subtotal_discount'], 'total_tax' => $OrderSalesTax, 'total_amount' => $orderDetails['total'], 'net_amount' => $orderDetails['subtotal'], 'shipping_total' => $orderDetails['shipping_cost'], 'notes' => $orderDetails['notes'], 'shipping_method' => @$orderDetails['shipping'][0]['shipping_id'], 'api_updated_at' => date('Y-m-d H:i:s', $orderDetails['updated_at'])];

													if ($orderDetails['status'] == 'I') {
														$orderData['is_voided'] = 1;
													} else {
														$orderData['is_voided'] = 0;
													}

													if (isset($orderDetails['payment_method']['payment_id'])) {
														$orderData['api_order_payment_status'] = 'paid';
														$orderData['payment_date'] = date("Y-m-d H:i:s", $order['timestamp']);
													} else {
														$orderData['api_order_payment_status'] = 'unpaid';
													}

													if ($platform_order->api_updated_at != date('Y-m-d H:i:s', $orderDetails['updated_at'])) {
														$orderData['order_updated_at'] = date("Y-m-d H:i:s");
														$orderData['sync_status'] = "Ready";
														PlatformOrder::where('id', $platform_order_id)->update($orderData);
													}

													$billingAddressData = ['platform_order_id' => $platform_order_id, 'address_type' => 'billing', 'address_name' => $orderDetails['b_firstname'] . ' ' . $orderDetails['b_lastname'], 'firstname' => $orderDetails['b_firstname'], 'lastname' => $orderDetails['b_lastname'], 'company' => $orderDetails['company'], 'address1' => $orderDetails['b_address'], 'address2' => $orderDetails['b_address_2'], 'city' => $orderDetails['b_city'], 'state' => $orderDetails['b_state'], 'postal_code' => $orderDetails['b_zipcode'], 'country' => $orderDetails['b_country'], 'email' => $orderDetails['email'], 'phone_number' => $orderDetails['b_phone']];

													$platform_billing_address = PlatformOrderAddress::select('id')->where(['platform_order_id' => $platform_order_id, 'address_type' => 'billing'])->first();
													if ($platform_billing_address) {
														PlatformOrderAddress::where('id', $platform_billing_address->id)->update($billingAddressData);
													} else {
														PlatformOrderAddress::insert($billingAddressData);
													}

													$first_name = isset($orderDetails['s_firstname']) && $orderDetails['s_firstname'] ? $orderDetails['s_firstname'] : $orderDetails['b_firstname'];
													$last_name = isset($orderDetails['s_lastname']) && $orderDetails['s_lastname'] ? $orderDetails['s_lastname'] : $orderDetails['b_lastname'];
													$address = $first_name . " " . $last_name;

													$shippingAddressData = ['platform_order_id' => $platform_order_id, 'address_type' => 'shipping', 'address_name' => $address, 'firstname' => $first_name, 'lastname' => $last_name, 'company' => $orderDetails['company'], 'address1' => isset($orderDetails['s_address']) && $orderDetails['s_address'] ? $orderDetails['s_address'] : $orderDetails['b_address'], 'address2' => isset($orderDetails['s_address_2']) && $orderDetails['s_address_2'] ? $orderDetails['s_address_2'] : $orderDetails['b_address_2'], 'city' => isset($orderDetails['s_city']) && $orderDetails['s_city'] ? $orderDetails['s_city'] : $orderDetails['b_city'], 'state' => isset($orderDetails['s_state']) && $orderDetails['s_state'] ? $orderDetails['s_state'] : $orderDetails['b_state'], 'postal_code' => isset($orderDetails['s_zipcode']) && $orderDetails['s_zipcode'] ? $orderDetails['s_zipcode'] : $orderDetails['b_zipcode'], 'country' => isset($orderDetails['s_country']) && $orderDetails['s_country'] ? $orderDetails['s_country'] : $orderDetails['b_country'], 'email' => $orderDetails['email'], 'phone_number' => isset($orderDetails['s_phone']) && $orderDetails['s_phone'] ? $orderDetails['s_phone'] : $orderDetails['b_phone']];

													$platform_shipping_address = PlatformOrderAddress::select('id')->where(['platform_order_id' => $platform_order_id, 'address_type' => 'shipping'])->first();
													if ($platform_shipping_address) {
														PlatformOrderAddress::where('id', $platform_shipping_address->id)->update($shippingAddressData);
													} else {
														PlatformOrderAddress::insert($shippingAddressData);
													}

													foreach ($orderDetails['products'] as $product) {
														$productData = ['platform_order_id' => $platform_order_id, 'api_order_line_id' => trim($product['item_id']), 'api_product_id' => $product['product_id'], 'product_name' => $product['product'], 'sku' => $product['product_code'], 'qty' => $product['amount'], 'price' => $product['price'], 'subtotal' => $product['subtotal'], 'unit_price' => $product['price'], 'updated_at' => date('Y-m-d H:i:s')];

														$platform_order_line = PlatformOrderLine::select('id')->where(['platform_order_id' => $platform_order_id, 'api_order_line_id' => trim($product['item_id'])])->first();
														if ($platform_order_line) {
															PlatformOrderLine::where('id', $platform_order_line->id)->update($productData);
														} else {
															PlatformOrderLine::insert($productData);
														}
													}

													//order transaction details
													if (isset($orderDetails['payment_method']['payment_id'])) {
														$payment = $orderDetails['payment_method'];

														$paymentData = ['platform_order_id' => $platform_order_id, 'api_transaction_index_id' => $payment['payment_id'],  'transaction_datetime' => date("Y-m-d H:i:s", $order['timestamp']),  'transaction_type' => $payment['description'], 'transaction_method' => $payment['payment'], 'transaction_amount' => $orderDetails['total'], 'transaction_avs' => @$orderDetails['payment_info']['expiry_month'] . '/' . @$orderDetails['payment_info']['expiry_year'], 'transaction_id' => @$orderDetails['payment_info']['card_number'], 'transaction_reference' => @$orderDetails['payment_info']['cardholder_name'], 'transaction_cvv2' => @$orderDetails['payment_info']['cvv2']];

														$platform_order_transaction = $this->mobj->getFirstResultByConditions('platform_order_transactions', ['platform_order_id' => $platform_order_id], ['id']);
														if ($platform_order_transaction) {
															$this->mobj->makeUpdate('platform_order_transactions', $paymentData, ['id' => $platform_order_transaction->id]);
														} else {
															$this->mobj->makeInsert('platform_order_transactions', $paymentData);
														}
													}

													if ($OrderShipping > 0) {
														$OrderItemData = ['platform_order_id' => $platform_order_id, 'product_name' => "SHIPPING", 'qty' => 1, 'unit_price' => $OrderShipping, 'subtotal' => $OrderShipping, 'total' => $OrderShipping, 'row_type' => "SHIPPING"];

														$platform_order_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => "SHIPPING"], ['id']);
														if ($platform_order_line) {
															$this->mobj->makeUpdate('platform_order_line', $OrderItemData, ['id' => $platform_order_line->id]);
														} else {
															$this->mobj->makeInsert('platform_order_line', $OrderItemData);
														}
													} else {
														$this->mobj->makeDelete('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => "SHIPPING"]);
													}

													if ($OrderDiscount > 0) {
														$OrderItemData = ['platform_order_id' => $platform_order_id, 'product_name' => "DISCOUNT", 'qty' => 1, 'unit_price' => ($OrderDiscount * (-1)), 'subtotal' => ($OrderDiscount * (-1)), 'total' => ($OrderDiscount * (-1)), 'row_type' => "DISCOUNT"];

														$platform_order_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => "DISCOUNT"], ['id']);
														if ($platform_order_line) {
															$this->mobj->makeUpdate('platform_order_line', $OrderItemData, ['id' => $platform_order_line->id]);
														} else {
															$this->mobj->makeInsert('platform_order_line', $OrderItemData);
														}
													} else {
														$this->mobj->makeDelete('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => "DISCOUNT"]);
													}

													if ($OrderSalesTax > 0) {
														$OrderItemData = ['platform_order_id' => $platform_order_id, 'product_name' => "Sales Tax", 'qty' => 1, 'unit_price' => $OrderSalesTax, 'subtotal' => $OrderSalesTax, 'total' => $OrderSalesTax, 'row_type' => "TAX"];

														$platform_order_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => "TAX"], ['id']);
														if ($platform_order_line) {
															$this->mobj->makeUpdate('platform_order_line', $OrderItemData, ['id' => $platform_order_line->id]);
														} else {
															$this->mobj->makeInsert('platform_order_line', $OrderItemData);
														}
													} else {
														$this->mobj->makeDelete('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => "TAX"]);
													}
												}
											} else {
												$OrderShipping = $orderDetails['shipping_cost'];
												$OrderDiscount = $orderDetails['subtotal_discount'];
												$OrderSalesTax = $orderDetails['tax_subtotal'];

												$CustomerData = ['user_id' => $userId, 'user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'api_customer_id' => $orderDetails['user_id'], 'api_customer_code' => $orderDetails['user_id'], 'customer_name' => $orderDetails['b_firstname'] . ' ' . $orderDetails['b_lastname'], 'first_name' => $orderDetails['b_firstname'], 'last_name' => $orderDetails['b_lastname'], 'company_name' => $orderDetails['company'], 'phone' => $orderDetails['b_phone'], 'fax' => $orderDetails['fax'], 'email' => $orderDetails['email'], 'address1' => $orderDetails['s_address'], 'address2' => $orderDetails['s_address_2'], 'address3' => $orderDetails['b_city'], 'postal_addresses' => $orderDetails['b_zipcode'], 'country' => $orderDetails['b_country'], 'company_id' => $orderDetails['company_id']];

												if ($orderDetails['user_id']) {
													$platform_customer = PlatformCustomer::select('id')->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'api_customer_id' => $orderDetails['user_id']])->first();
												} else {
													$platform_customer = PlatformCustomer::select('id')->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'email' => $orderDetails['email']])->first();
												}

												if ($platform_customer) {
													$platform_customer_id = $platform_customer->id;
													PlatformCustomer::where('id', $platform_customer->id)->update($CustomerData);
												} else {
													$platform_customer_id = PlatformCustomer::insertGetId($CustomerData);
												}

												$orderData = ['user_id' => $userId, 'platform_id' => $this->platformId, 'user_integration_id' => $userIntegrationId, 'platform_customer_id' => $platform_customer_id, 'order_type' => "SO", 'customer_email' => $order['email'], 'api_order_id' => $order['order_id'], 'order_number' => $order['order_id'], 'order_date' => date("Y-m-d H:i:s", $order['timestamp']), 'currency' => @$orderDetails['secondary_currency'], 'order_status' => $orderDetails['status'], 'vendor' => $orderDetails['company_id'], 'total_discount' => $orderDetails['subtotal_discount'], 'total_tax' => $OrderSalesTax, 'total_amount' => $orderDetails['total'], 'net_amount' => $orderDetails['subtotal'], 'shipping_total' => $orderDetails['shipping_cost'], 'notes' => $orderDetails['notes'], 'shipping_method' => @$orderDetails['shipping'][0]['shipping_id'], 'api_updated_at' => date('Y-m-d H:i:s', $orderDetails['updated_at'])];

												if ($orderDetails['status'] == 'I') {
													$orderData['is_voided'] = 1;
												} else {
													$orderData['is_voided'] = 0;
												}

												if (isset($orderDetails['payment_method']['payment_id'])) {
													$orderData['api_order_payment_status'] = 'paid';
													$orderData['payment_date'] = date("Y-m-d H:i:s", $order['timestamp']);
												} else {
													$orderData['api_order_payment_status'] = 'unpaid';
												}

												$platform_order = PlatformOrder::select('id', 'api_updated_at', 'linked_id')->where(['user_integration_id' => $userIntegrationId, 'platform_id' => $account->platform_id, 'api_order_id' => $order['order_id']])->first();
												if($platform_order)
												{
													$platform_order_id = $platform_order->id;
													if($platform_order->api_updated_at != date('Y-m-d H:i:s', $orderDetails['updated_at']) && $platform_order->linked_id == 0)
													{
														$orderData['order_updated_at'] = date("Y-m-d H:i:s");
														$orderData['sync_status'] = "Ready";
														PlatformOrder::where('id', $platform_order->id)->update($orderData);
													}
												}
												else
												{
													$orderData['sync_status'] = "Ready";
													$orderData['order_updated_at'] = date("Y-m-d H:i:s");
													$platform_order_id = PlatformOrder::insertGetId($orderData);
												}

												$billingAddressData = ['platform_order_id' => $platform_order_id, 'address_type' => 'billing', 'address_name' => $orderDetails['b_firstname'] . ' ' . $orderDetails['b_lastname'], 'firstname' => $orderDetails['b_firstname'], 'lastname' => $orderDetails['b_lastname'], 'company' => $orderDetails['company'], 'address1' => $orderDetails['b_address'], 'address2' => $orderDetails['b_address_2'], 'city' => $orderDetails['b_city'], 'state' => $orderDetails['b_state'], 'postal_code' => $orderDetails['b_zipcode'], 'country' => $orderDetails['b_country'], 'email' => $orderDetails['email'], 'phone_number' => $orderDetails['b_phone']];

												$platform_billing_address = PlatformOrderAddress::select('id')->where(['platform_order_id' => $platform_order_id, 'address_type' => 'billing'])->first();
												if ($platform_billing_address) {
													PlatformOrderAddress::where('id', $platform_billing_address->id)->update($billingAddressData);
												} else {
													PlatformOrderAddress::insert($billingAddressData);
												}

												$first_name = isset($orderDetails['s_firstname']) && $orderDetails['s_firstname'] ? $orderDetails['s_firstname'] : $orderDetails['b_firstname'];
												$last_name = isset($orderDetails['s_lastname']) && $orderDetails['s_lastname'] ? $orderDetails['s_lastname'] : $orderDetails['b_lastname'];
												$address = $first_name . " " . $last_name;

												$shippingAddressData = ['platform_order_id' => $platform_order_id, 'address_type' => 'shipping', 'address_name' => $address, 'firstname' => $first_name, 'lastname' => $last_name, 'company' => $orderDetails['company'], 'address1' => isset($orderDetails['s_address']) && $orderDetails['s_address'] ? $orderDetails['s_address'] : $orderDetails['b_address'], 'address2' => isset($orderDetails['s_address_2']) && $orderDetails['s_address_2'] ? $orderDetails['s_address_2'] : $orderDetails['b_address_2'], 'city' => isset($orderDetails['s_city']) && $orderDetails['s_city'] ? $orderDetails['s_city'] : $orderDetails['b_city'], 'state' => isset($orderDetails['s_state']) && $orderDetails['s_state'] ? $orderDetails['s_state'] : $orderDetails['b_state'], 'postal_code' => isset($orderDetails['s_zipcode']) && $orderDetails['s_zipcode'] ? $orderDetails['s_zipcode'] : $orderDetails['b_zipcode'], 'country' => isset($orderDetails['s_country']) && $orderDetails['s_country'] ? $orderDetails['s_country'] : $orderDetails['b_country'], 'email' => $orderDetails['email'], 'phone_number' => isset($orderDetails['s_phone']) && $orderDetails['s_phone'] ? $orderDetails['s_phone'] : $orderDetails['b_phone']];

												$platform_shipping_address = PlatformOrderAddress::select('id')->where(['platform_order_id' => $platform_order_id, 'address_type' => 'shipping'])->first();
												if ($platform_shipping_address) {
													PlatformOrderAddress::where('id', $platform_shipping_address->id)->update($shippingAddressData);
												} else {
													PlatformOrderAddress::insert($shippingAddressData);
												}

												foreach ($orderDetails['products'] as $product) {
													$productData = ['platform_order_id' => $platform_order_id, 'api_order_line_id' => trim($product['item_id']), 'api_product_id' => $product['product_id'], 'product_name' => $product['product'], 'sku' => $product['product_code'], 'qty' => $product['amount'], 'price' => $product['price'], 'subtotal' => $product['subtotal'], 'unit_price' => $product['price'], 'updated_at' => date('Y-m-d H:i:s')];

													$platform_order_line = PlatformOrderLine::select('id')->where(['platform_order_id' => $platform_order_id, 'api_order_line_id' => trim($product['item_id'])])->first();
													if ($platform_order_line) {
														PlatformOrderLine::where('id', $platform_order_line->id)->update($productData);
													} else {
														PlatformOrderLine::insert($productData);
													}
												}

												//order transaction details
												if (isset($orderDetails['payment_method']['payment_id'])) {
													$payment = $orderDetails['payment_method'];

													$paymentData = ['platform_order_id' => $platform_order_id, 'api_transaction_index_id' => $payment['payment_id'],  'transaction_datetime' => date("Y-m-d H:i:s", $order['timestamp']),  'transaction_type' => $payment['description'], 'transaction_method' => $payment['payment'], 'transaction_amount' => $orderDetails['total'], 'transaction_avs' => @$orderDetails['payment_info']['expiry_month'] . '/' . @$orderDetails['payment_info']['expiry_year'], 'transaction_id' => @$orderDetails['payment_info']['card_number'], 'transaction_reference' => @$orderDetails['payment_info']['cardholder_name'], 'transaction_cvv2' => @$orderDetails['payment_info']['cvv2']];

													$platform_order_transaction = $this->mobj->getFirstResultByConditions('platform_order_transactions', ['platform_order_id' => $platform_order_id], ['id']);
													if ($platform_order_transaction) {
														$this->mobj->makeUpdate('platform_order_transactions', $paymentData, ['id' => $platform_order_transaction->id]);
													} else {
														$this->mobj->makeInsert('platform_order_transactions', $paymentData);
													}
												}

												if ($OrderShipping > 0) {
													$OrderItemData = ['platform_order_id' => $platform_order_id, 'product_name' => "SHIPPING", 'qty' => 1, 'unit_price' => $OrderShipping, 'subtotal' => $OrderShipping, 'total' => $OrderShipping, 'row_type' => "SHIPPING"];

													$platform_order_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => "SHIPPING"], ['id']);
													if ($platform_order_line) {
														$this->mobj->makeUpdate('platform_order_line', $OrderItemData, ['id' => $platform_order_line->id]);
													} else {
														$this->mobj->makeInsert('platform_order_line', $OrderItemData);
													}
												} else {
													$this->mobj->makeDelete('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => "SHIPPING"]);
												}

												if ($OrderDiscount > 0) {
													$OrderItemData = ['platform_order_id' => $platform_order_id, 'product_name' => "DISCOUNT", 'qty' => 1, 'unit_price' => ($OrderDiscount * (-1)), 'subtotal' => ($OrderDiscount * (-1)), 'total' => ($OrderDiscount * (-1)), 'row_type' => "DISCOUNT"];

													$platform_order_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => "DISCOUNT"], ['id']);
													if ($platform_order_line) {
														$this->mobj->makeUpdate('platform_order_line', $OrderItemData, ['id' => $platform_order_line->id]);
													} else {
														$this->mobj->makeInsert('platform_order_line', $OrderItemData);
													}
												} else {
													$this->mobj->makeDelete('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => "DISCOUNT"]);
												}

												if ($OrderSalesTax > 0) {
													$OrderItemData = ['platform_order_id' => $platform_order_id, 'product_name' => "Sales Tax", 'qty' => 1, 'unit_price' => $OrderSalesTax, 'subtotal' => $OrderSalesTax, 'total' => $OrderSalesTax, 'row_type' => "TAX"];

													$platform_order_line = $this->mobj->getFirstResultByConditions('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => "TAX"], ['id']);
													if ($platform_order_line) {
														$this->mobj->makeUpdate('platform_order_line', $OrderItemData, ['id' => $platform_order_line->id]);
													} else {
														$this->mobj->makeInsert('platform_order_line', $OrderItemData);
													}
												} else {
													$this->mobj->makeDelete('platform_order_line', ['platform_order_id' => $platform_order_id, 'row_type' => "TAX"]);
												}
											}
										}
									}
								}

								$return_response = true;
							} else {
								if ($response->getStatusCode() !== 200) {
									$return_response = "API Error:Unauthorized";
								} else {
									$return_response = "API Error";
								}
							}
						}
					}
				}
			  }
			}
			
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "->CSCartController->GetOrders->" . $e->getMessage());
			$return_response = $e->getMessage();
		}
		return $return_response;
	}

	/* Get Order Details By ID */
	public function GetOrderByID($OrderID = NULL, $userId = NULL, $userIntegrationId = NULL, $PlatformId = NULL, $account = NULL)
	{
		$return_response = [];
		try {
			if ($account) {
				$response1 = $this->cs->CallAPI($account, "GET", "/api/orders/{$OrderID}");
				$result1 = json_decode($response1->getBody(), true);
				if (isset($result1['order_id'])) {
					$return_response = $result1;
				}
			}
		} catch (\Exception $e) {
			\Log::error($userIntegrationId . "->CSCartController->GetOrderByID->" . $e->getMessage());
			$return_response = [];
		}
		return $return_response;
	}

	public function UpdateCSCartInventory($user_id = 0, $user_integration_id = 0, $source_platform_name = '', $platform_workflow_rule_id = 0, $user_workflow_rule_id = 0, $record_id = 0)
	{
		$return_data = true;
		$process_limit = 50;
		try {
			$source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
			$destination_platform_id = $this->helper->getPlatformIdByName('cscart');
			$object_id = $this->helper->getObjectId('inventory');
			$product_identity_obj_id = $this->helper->getObjectId('product_identity');

			$source_row_data = $destination_row_data = 'sku';

			$product_identity_obj_id = $this->helper->getObjectId('product_identity');
			$mapping_data = $this->map->getMappedField($user_integration_id, NULL, $product_identity_obj_id);
			if ($mapping_data) {
				if ($mapping_data['destination_platform_id'] == 'cscart') {
					$destination_row_data = $mapping_data['destination_row_data'];
					$source_row_data = $mapping_data['source_row_data'];
				} else {
					$destination_row_data = $mapping_data['source_row_data'];
					$source_row_data = $mapping_data['destination_row_data'];
				}
			}

			$destination_platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $destination_platform_id, ['platform_id', 'id', 'user_id', 'api_domain', 'app_id', 'app_secret']);
			if ($destination_platform_account) {
				do {
					$allow_next_call = false;

					$source_platform_products = DB::table('platform_product as source_platform_product')
					->join('platform_product as destination_platform_product', 'destination_platform_product.'.$destination_row_data, '=', 'source_platform_product.'.$source_row_data)
					->select('source_platform_product.id', 'source_platform_product.sku', 'destination_platform_product.api_product_id as cscart_api_product_id', 'source_platform_product.api_product_id as source_api_product_id')
					->where(['source_platform_product.user_integration_id'=>$user_integration_id, 'destination_platform_product.user_integration_id'=>$user_integration_id, 'source_platform_product.platform_id'=>$source_platform_id, 'destination_platform_product.platform_id'=>$destination_platform_id])
					->where(function($query) use($record_id){
						if($record_id)
						{
							$query->where('source_platform_product.id', $record_id);
						}
						else
						{
							$query->where('source_platform_product.inventory_sync_status', 'Ready');
						}
					})
					->where('source_platform_product.is_deleted', 0)
					->limit($process_limit)
					->orderBy('source_platform_product.updated_at', 'asc')
					->distinct()
					->get();

					if(count($source_platform_products) == $process_limit)
					{
						//want to loop continuously
						$allow_next_call = true;
					}

					if(count($source_platform_products))
					{
						foreach($source_platform_products as $source_platform_product)
						{
							$platform_product_inventories = $this->mobj->getResultByConditions('platform_product_inventory', ['user_integration_id'=>$user_integration_id, 'api_product_id'=>$source_platform_product->source_api_product_id], ['id', 'api_warehouse_id', 'quantity']);
							if(count($platform_product_inventories))
							{
								$Stock = 0;

								$warehouseArray = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "inventory_warehouse", ['api_id'], "regular", NULL, "multi", "source");
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

								$response = $this->cs->CallAPI($destination_platform_account, "PUT", "/api/products/" . $source_platform_product->cscart_api_product_id, ['amount'=>$Stock]);
								$result = json_decode($response->getBody(), true);

								if(isset($result['product_id']))
								{
									$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Synced'], ['id'=>$source_platform_product->id]);

									$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'success', $source_platform_product->id, 'Inventory synced successfully!');

									$return_data = true;
								}
								elseif(isset($result['message']))
								{
									$return_data = $result['message'];

									$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Failed'], ['id'=>$source_platform_product->id]);

									$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_product->id, $result['message']);
								}
								else
								{
									if($response->getStatusCode() !== 200)
									{
										$return_data = "API Error:Unauthorized";

										$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Failed'], ['id'=>$source_platform_product->id]);

										$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_product->id, "API Error:Unauthorized");
									}
									else
									{
										$return_data = "API Error";

										$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Failed'], ['id'=>$source_platform_product->id]);

										$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_product->id, "API Error");
									}
								}
							}
							else
							{
								$return_data = "Inventory record not available.";

								$this->mobj->makeUpdate('platform_product', ['inventory_sync_status'=>'Failed'], ['id'=>$source_platform_product->id]);

								$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_product->id, "Inventory record not available.");
							}
						}
					}
				}while ($allow_next_call);
			}
		}
		catch(\Exception $e)
		{
			\Log::error($user_integration_id . "->CSCartController->UpdateCSCartInventory->" . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	public function OldCreateOrderShipment($user_id = 0, $user_integration_id = 0, $source_platform_name = '', $platform_workflow_rule_id = 0, $user_workflow_rule_id = 0, $record_id = 0)
	{
		$return_data = true;
		$process_limit = 100;
		try {
			$source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
			$destination_platform_id = $this->helper->getPlatformIdByName('cscart');
			$object_id = $this->helper->getObjectId('sales_order_shipment');

			$destination_platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $destination_platform_id, ['platform_id', 'id', 'user_id', 'api_domain', 'app_id', 'app_secret']);
			if ($destination_platform_account) {
				do {
					$allow_next_call = false;

					$platform_order_shipments = DB::table('platform_order_shipments')
						->where(function ($query) use ($record_id, $user_id, $user_integration_id, $source_platform_id) {
							if ($record_id > 0) {
								$query->where('platform_order_id', $record_id)->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id]);
							} else {
								$query->where(['sync_status' => 'Ready', 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id]);
							}
						})
						->limit($process_limit)
						->orderBy('id', 'asc')
						->get();

					if (count($platform_order_shipments) == $process_limit) {
						//want to loop continuously
						$allow_next_call = true;
					}

					if (count($platform_order_shipments) > 0) {
						foreach ($platform_order_shipments as $platform_order_shipment) {
							$destination_platform_order = $this->mobj->getFirstResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'linked_id' => $platform_order_shipment->platform_order_id], ['id', 'api_order_id', 'platform_customer_id', 'shipment_status']);
							$source_platform_order = $this->mobj->getFirstResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'id' => $platform_order_shipment->platform_order_id], ['id', 'shipment_status']);
							if ($destination_platform_order && $source_platform_order) {
								$platform_customer = $this->mobj->getFirstResultByConditions('platform_customer', ['id' => $destination_platform_order->platform_customer_id], ['api_customer_id']);
								if ($platform_customer) {
									$platform_order_shipment_lines = DB::table('platform_order_shipment_lines')
										->leftJoin('platform_product', 'platform_order_shipment_lines.product_id', '=', 'platform_product.api_product_id')
										->select('platform_product.sku', 'platform_order_shipment_lines.quantity')
										->where('platform_order_shipment_lines.platform_order_shipment_id', $platform_order_shipment->id)
										->where('platform_product.user_id', $user_id)
										->where('platform_product.user_integration_id', $user_integration_id)
										->where('platform_product.platform_id', $source_platform_id)
										->get();

									$ShipmentItems = [];
									foreach ($platform_order_shipment_lines as $platform_order_shipment_line) {
										$platform_order_line = DB::table('platform_order_line')->select('api_order_line_id')->where('platform_order_id', $destination_platform_order->id)->where('sku', $platform_order_shipment_line->sku)->first();
										if ($platform_order_line) {
											$ShipmentItems[] = array($platform_order_line->api_order_line_id => $platform_order_shipment_line->quantity);
										}
									}

									if (count($ShipmentItems) > 0) {
										$shipping_id = 1;
										$shipping = "Custom shipping method";
										$carrier = "USPS";

										$default_shipping_method = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "sorder_shipping_method", ['api_id', 'name'], 'regular', $platform_order_shipment->shipping_method);
										if ($default_shipping_method) {
											$shipping_id = $default_shipping_method->api_id;
											$shipping = $default_shipping_method->name;
											$carrier = $default_shipping_method->name;
										} else {
											$shipping_method = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "sorder_shipping_method", ['api_id', 'name']);
											if ($shipping_method) {
												$shipping_id = $shipping_method->api_id;
												$shipping = $shipping_method->name;
												$carrier = $shipping_method->name;
											}
										}
										/*
											$shipmentData = array("carrier"=>$carrier,
											"order_id"=>$destination_platform_order->api_order_id,
											"products"=>json_encode($ShipmentItems),
											"shipping"=>$shipping,
											"shipping_id"=>$shipping_id,
											"user_id"=>$platform_customer->api_customer_id,
											"tracking_number"=>$platform_order_shipment->tracking_info);
											*/
										$shipmentData = array(
											"carrier" => $carrier,
											"order_id" => $destination_platform_order->api_order_id,
											"shipping_id" => $shipping_id,
											"tracking_number" => $platform_order_shipment->tracking_info
										);

										$response = $this->cs->CallAPI($destination_platform_account, "POST", "/api/shipments", $shipmentData);
										$result = json_decode($response->getBody(), true);

										if (isset($result['shipment_id'])) {
											$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Synced'], ['id' => $platform_order_shipment->id]);

											$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'success', $source_platform_order->id, 'Shipment synced successfully!');

											if ($source_platform_order->shipment_status == 'Ready') {
												$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Synced'], ['id' => $source_platform_order->id]);
											} elseif ($source_platform_order->shipment_status != 'Synced') {
												$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Synced'], ['id' => $source_platform_order->id]);
											}
										} else {
											if ($response->getStatusCode() !== 200) {
												$return_data = "API Error:Unauthorized";

												$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

												$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $source_platform_order->id]);

												$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_order->id, "API Error:Unauthorized");
											} else {
												$return_data = "API Error";

												$this->mobj->makeUpdate('platform_order_shipments', ['sync_status' => 'Failed'], ['id' => $platform_order_shipment->id]);

												$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $source_platform_order->id]);

												$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $source_platform_order->id, "API Error");
											}
										}
									}
								}
							}
						}
					}
				} while ($allow_next_call);
			}
		} catch (\Exception $e) {
			\Log::error($e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	public function CreateOrderShipment($user_id = 0, $user_integration_id = 0, $source_platform_name = '', $platform_workflow_rule_id = 0, $user_workflow_rule_id = 0, $record_id = 0)
	{
		$return_data = true;
		$process_limit = 50;
		try {
			$source_platform_id = $this->helper->getPlatformIdByName($source_platform_name);
			$destination_platform_id = $this->helper->getPlatformIdByName('cscart');
			$object_id = $this->helper->getObjectId('sales_order_shipment');

			$destination_platform_account = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $destination_platform_id, ['platform_id', 'id', 'user_id', 'api_domain', 'app_id', 'app_secret']);
			if ($destination_platform_account) {
				do {
					$allow_next_call = false;

					$platform_orders = DB::table('platform_order')
						->where(function ($query) use ($record_id, $user_id, $user_integration_id, $source_platform_id) {
							if ($record_id > 0) {
								$query->where('id', $record_id)->where(['user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id]);
							} else {
								$query->where(['shipment_status' => 'Ready', 'user_integration_id' => $user_integration_id, 'platform_id' => $source_platform_id]);
							}
						})
						->limit($process_limit)
						->orderBy('id', 'asc')
						->get();

					if (count($platform_orders) == $process_limit) {
						//want to loop continuously
						$allow_next_call = true;
					}

					if (count($platform_orders) > 0) {
						foreach ($platform_orders as $platform_order) {
							$destination_platform_order = $this->mobj->getFirstResultByConditions('platform_order', ['user_integration_id' => $user_integration_id, 'linked_id' => $platform_order->id], ['id', 'api_order_id', 'platform_customer_id', 'shipment_status']);
							if ($destination_platform_order) {
								$platform_customer = $this->mobj->getFirstResultByConditions('platform_customer', ['id' => $destination_platform_order->platform_customer_id], ['api_customer_id']);
								if ($platform_customer) {
									$shipping_id = 1;
									$carrier = "USPS";

									$default_shipping_method = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "sorder_shipping_method", ['api_id', 'name'], 'regular', $platform_order->shipping_method);
									if ($default_shipping_method) {
										$shipping_id = $default_shipping_method->api_id;
										$carrier = $default_shipping_method->name;
									} else {
										$shipping_method = $this->map->getMappedDataByName($user_integration_id, $platform_workflow_rule_id, "sorder_shipping_method", ['api_id', 'name']);
										if ($shipping_method) {
											$shipping_id = $shipping_method->api_id;
											$carrier = $shipping_method->name;
										}
									}

									$tracking_number = [];
									$platform_order_shipments = $this->mobj->getResultByConditions('platform_order_shipments', ['user_integration_id' => $user_integration_id, 'platform_order_id' => $platform_order->id], ['tracking_info']);
									foreach ($platform_order_shipments as $platform_order_shipment) {
										$tracking_number[] = $platform_order_shipment->tracking_info;
									}

									$shipmentData = array(
										"carrier" => $carrier,
										"order_id" => $destination_platform_order->api_order_id,
										"shipping_id" => $shipping_id,
										"tracking_number" => implode(", ", $tracking_number)
									);

									$response = $this->cs->CallAPI($destination_platform_account, "POST", "/api/shipments", $shipmentData);
									$result = json_decode($response->getBody(), true);

									if(isset($result['shipment_id']))
									{
										$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'success', $platform_order->id, 'Shipment synced successfully!');

										$this->mobj->makeUpdate('platform_order', ['shipment_status'=>'Synced'], ['id'=>$platform_order->id]);

										$ChangeStatusAndNotifyByEmail = array("status"=>"C", "notify_user"=>"1", "notify_department"=>"1", "notify_vendor"=>"0");

										$this->cs->CallAPI($destination_platform_account, "PUT", "/api/orders/".$destination_platform_order->api_order_id, $ChangeStatusAndNotifyByEmail);
									}
									else
									{
										if($response->getStatusCode() !== 200)
										{
											$return_data = isset($result['message'])?$result['message']:"API Error: Unauthorized";

											$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order->id]);

											$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $platform_order->id, $return_data);
										}
										else
										{
											$return_data = "API Error";

											$this->mobj->makeUpdate('platform_order', ['shipment_status' => 'Failed'], ['id' => $platform_order->id]);

											$this->log->syncLog($user_id, $user_integration_id, $user_workflow_rule_id, $source_platform_id, $destination_platform_id, $object_id, 'failed', $platform_order->id, "API Error");
										}
									}
								}
							}
						}
					}
				} while ($allow_next_call);
			}
		} catch (\Exception $e) {
			\Log::error($user_integration_id . " -> CSCartController -> CreateOrderShipment -> " . $e->getMessage());
			$return_data = $e->getMessage();
		}
		return $return_data;
	}

	public function test()
	{
		//$bc=new \App\Http\Controllers\Brightpearl\BrightPearlApiController;
		//dd($bc->GetPaymentMethods(Auth::user()->id, 44, 1));

		//dd($bc->CreateOrDeleteWebhook(Auth::user()->id, 44, ['all'], 2));
		//dd($bc->CreateOrDeleteWebhook(Auth::user()->id, 44, ['shipment', 'product'], 1));
		//dd($bc->GetWebhookList(44));
		//dd($bc->SyncOrderInBP(Auth::user()->id, 204, 53, 319, 'cscart', "Ready", NULL));

		//dd($this->mobj->decryptString('Y2MxZDBjMWFjM2JkYzRhZGIwOWU1NGZlOWUyZWNiNTg='));
		//$response=$this->BrightPearlApi->GetWarehouse(Auth::user()->id, 42, 1);

		//$response = $this->GetOrders(Auth::user()->id, 204);
		//$response=$this->GetOrderStatus(Auth::user()->id, 210, 1, 0);
		//$response=$this->GetPaymentMethods(Auth::user()->id, 210, 1, 0);
		//$response=$this->GetShippingMethods(Auth::user()->id, 210, 1, 0);
		//$response=$this->GetTaxCodes(Auth::user()->id, 204, 1, 0);
		//$response = $this->UpdateCSCartInventory(Auth::user()->id, 221, 'brightpearl', 10, 0, 0);
		//$response=$this->ThreeDCartApi->GetProducts(Auth::user()->id, 10, false);
		//dd($response);
		//echo $this->mobj->encrypt_decrypt('E0gts2PnM9ORQPp6m17zUtTjxJi70u38');
	}

	/* Execute CS Cart Method */
	public function ExecuteEvents($method = '', $event = '', $destination_platform_id = '', $user_id = '', $user_integration_id = '', $is_initial_sync = 0, $user_workflow_rule_id = '', $source_platform_id = '', $platform_workflow_rule_id = '', $record_id = NULL)
	{
		$response = true;
		if ($method == 'GET' && $event == 'CATEGORY') {
			$response = $this->GetCategories($user_id, $user_integration_id, 1, $is_initial_sync);
			\Log::channel('webhook')->info("CATEGORY_CS -" . $user_id . " Integration " . $user_integration_id . "PlatformWorkFlow=" . $platform_workflow_rule_id . " UserWorkFlow: " . $user_workflow_rule_id . " Response " . $response . " Created Date : " . date('Y-m-d H:i:s'));
		} elseif ($method == 'GET' && $event == 'TAXCODE') {
			$response = $this->GetTaxCodes($user_id, $user_integration_id, 1, $is_initial_sync);
			\Log::channel('webhook')->info("TAXCODE_CS -" . $user_id . " Integration " . $user_integration_id . "PlatformWorkFlow=" . $platform_workflow_rule_id . " UserWorkFlow: " . $user_workflow_rule_id . " Response " . $response . " Created Date : " . date('Y-m-d H:i:s'));
		} elseif ($method == 'GET' && $event == 'ORDERSTATUS') {
			$response = $this->GetOrderStatus($user_id, $user_integration_id, 1, $is_initial_sync);
			\Log::channel('webhook')->info("ORDERSTATUS_CS -" . $user_id . " Integration " . $user_integration_id . "PlatformWorkFlow=" . $platform_workflow_rule_id . " UserWorkFlow: " . $user_workflow_rule_id . " Response " . $response . " Created Date : " . date('Y-m-d H:i:s'));
		} elseif ($method == 'GET' && $event == 'SHIPPINGMETHOD') {
			$response = $this->GetShippingMethods($user_id, $user_integration_id, 1, $is_initial_sync);
			\Log::channel('webhook')->info("SHIPPINGMETHOD_CS -" . $user_id . " Integration " . $user_integration_id . "PlatformWorkFlow=" . $platform_workflow_rule_id . " UserWorkFlow: " . $user_workflow_rule_id . " Response " . $response . " Created Date : " . date('Y-m-d H:i:s'));
		} elseif ($method == 'GET' && $event == 'PAYMENTMETHOD') {
			$response = $this->GetPaymentMethods($user_id, $user_integration_id, 1, $is_initial_sync);
			\Log::channel('webhook')->info("PAYMENTMETHOD_CS -" . $user_id . " Integration " . $user_integration_id . "PlatformWorkFlow=" . $platform_workflow_rule_id . " UserWorkFlow: " . $user_workflow_rule_id . " Response " . $response . " Created Date : " . date('Y-m-d H:i:s'));
		} elseif ($method == 'GET' && $event == 'PRODUCT') {
			$response = $this->GetProducts($user_id, $user_integration_id, $is_initial_sync);
			\Log::channel('webhook')->info("PRODUCT_CS -" . $user_id . " Integration " . $user_integration_id . "PlatformWorkFlow=" . $platform_workflow_rule_id . " UserWorkFlow: " . $user_workflow_rule_id . " Response " . $response . " Created Date : " . date('Y-m-d H:i:s'));
		} elseif ($method == 'GET' && $event == 'CUSTOMER') {
			$response = $this->GetCustomers($user_id, $user_integration_id, 1, $is_initial_sync);
			\Log::channel('webhook')->info("CUSTOMER_CS -" . $user_id . " Integration " . $user_integration_id . "PlatformWorkFlow=" . $platform_workflow_rule_id . " UserWorkFlow: " . $user_workflow_rule_id . " Response " . $response . " Created Date : " . date('Y-m-d H:i:s'));
		} elseif ($method == 'GET' && $event == 'SALESORDER') {
			$response = $this->GetOrders($user_id, $user_integration_id);
		} elseif ($method == 'MUTATE' && $event == 'INVENTORY') {
			$response = $this->UpdateCSCartInventory($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
		} elseif ($method == 'MUTATE' && $event == 'SHIPMENT') {
			$response = $this->CreateOrderShipment($user_id, $user_integration_id, $source_platform_id, $platform_workflow_rule_id, $user_workflow_rule_id, $record_id);
		}
		return $response;
	}
}
