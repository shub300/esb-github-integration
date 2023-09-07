<?php

namespace App\Helper\Api;

use DB;
use Auth;
use Mail;
use App\Helper\MainModel;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Excel;

class WoocommerceApi
{


    public $mobj, $myPlatform;
    public function __construct()
    {
        $this->mobj = new MainModel();
        $this->myPlatform = "woocommerce";
    }
    private function userAgents()
    {
        $userAgents = ([
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/111.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_2_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/111.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/111.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/111.0.0.0 Safari/537.36',
        ]);
        shuffle($userAgents);
        return $userAgents[0];
    }
    /* WooCommerce Header */
    public function MakeHeader($account, $consumer_key = NULL, $consumer_secret = NULL)
    {

        if (!empty($account)) {
            $header = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => $this->userAgents(),
                'consumer_key' => $this->mobj->encrypt_decrypt($account->app_id, 'decrypt'), 'consumer_secret' => $this->mobj->encrypt_decrypt($account->app_secret, 'decrypt')
            ];
        } else {
            $header = [
                'consumer_key' => $this->mobj->encrypt_decrypt($consumer_key, 'decrypt'), 'consumer_secret' => $this->mobj->encrypt_decrypt($consumer_secret, 'decrypt'),
                'User-Agent' => $this->userAgents(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ];
        }
        return $header;
    }

	/* API Call */
	public function CallAPI($account, $method, $url, $postData = [],$json=NULL)
	{

		$header = $this->MakeHeader($account);
        $url = $account->api_domain ."/wp-json/wc/v3/". $url."consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";

		$response = $this->mobj->makeRequest(strtoupper($method), $url, $postData, $header,$json);

		return $response;
	}
    /* Check Woocommerce credentials */
    public function CheckCredentials($consumer_key, $consumer_secret, $api_domain)
    {
        try {
            $method = "GET";

            $url = $api_domain . "/wp-json/wc/v3/products?consumer_key={$consumer_key}&consumer_secret={$consumer_secret}";
            $header = ['consumer_key' => $consumer_key, 'consumer_secret' => $consumer_secret,'User-Agent' => $this->userAgents(),'Content-Type' => 'application/json',
            'Accept' => 'application/json'];
            $response = $this->mobj->makeRequest($method, $url, $post_data = [], $header, 'json');
            $status = $response->getStatusCode();

            if ($status == 200) {
                return true;
            } else {
                return false;
            }
        } catch (Exception $e) {
            \Log::info($e->getMessage());
            return $e->getMessage();
        }
    }
    /* Create Webhook || Batch Update  */
    public function CreateOrDeleteWebhook($account, $url = NULL, array $postData, $type = "normal")
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/webhooks/batch?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        $response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Get Webhook LIST  */
    public function GetWebhookList($account, $url = NULL, array $postData = [], $type = "normal")
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/webhooks?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        $response = $this->mobj->makeRequest('GET', $url, [], $header);
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Get Products  */
    public function GetProducts($account, $url = NULL, $page = 1, $limit = 100, $type = "normal")
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        if ($url) {
            $url = $account->api_domain . "{$url}&consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        } else {
            $url = $account->api_domain . "/wp-json/wc/v3/products?page={$page}&per_page={$limit}&consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        }

        $response = $this->mobj->makeRequest('GET', $url, [], $header);
        return $this->getResponse($response);
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }

    /* Get Bulk Order Update  */
    public function OrderBulkUpdate($account, $url = NULL, $postData = [])
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);

        $url = $account->api_domain . "/wp-json/wc/v3/orders/batch?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";

        $response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Get Order Update  */
    public function OrderUpdate($account,$orderID, $payload = [])
    {
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/orders/{$orderID}?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        return $this->mobj->makeRequest('POST', $url, $payload, $header, "json");
    }
    /* Get Product Bulk Update  */
    public function ProductBulkUpdate($account, $url = NULL, $postData = [])
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);

        $url = $account->api_domain . "/wp-json/wc/v3/products/batch?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";

        $response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Get Product variant Bulk Update  */
    public function ProductVariantBulkUpdate($account, $url = NULL, $postData = [], $productID = NULL)
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        if ($url) {
            $url = $account->api_domain . "{$url}?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        } else {
            $url = $account->api_domain . "/wp-json/wc/v3/products/{$productID}/variations/batch?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        }

        $response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Create Order Note  */
    public function CreateOrderNote($account, $url = NULL, $orderID, $postData = [])
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/orders/$orderID/notes?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        $response = $this->mobj->makeRequest('POST', $url, $postData, $header, "json");
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Get Customers  */
    public function GetCustomers($account, $url = NULL, $page = 1, $limit = 100, $type = "normal")
    {
        // try {
        //     $response = false;

        $header = $this->MakeHeader($account);
        if ($url) {
            $url = $account->api_domain . "{$url}&consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        } else {
            $url = $account->api_domain . "/wp-json/wc/v3/customers?page={$page}&per_page={$limit}&consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        }
        $response = $this->mobj->makeRequest('GET', $url, [], $header);
        return $response;


        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Get Payment Gateways  */
    public function GetPaymentGateways($account, $url = NULL, $page = 1, $type = "normal")
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/payment_gateways?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        $response = $this->mobj->makeRequest('GET', $url, [], $header);
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Get Shipping Methods  */
    public function GetShippingMethods($account, $url = NULL, $page = 1, $type = "normal")
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/shipping_methods?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        $response = $this->mobj->makeRequest('GET', $url, [], $header);
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Get Zone for shipping Methods  */
    public function GetZone($account, $url = NULL, $page = 1, $type = "normal")
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/shipping/zones?page=1&per_page=100&consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        $response = $this->mobj->makeRequest('GET', $url, [], $header);
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Get  shipping Methods by Zone ID */
    public function GetShippingMethodByZoneID($account, $url = NULL, $zoneID = NULL)
    {
        //  try {
        //      $response = false;
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/shipping/zones/{$zoneID}/methods?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        $response = $this->mobj->makeRequest('GET', $url, [], $header);
        return $response;
        //  } catch (\Exception $e) {
        //      \Log::error($e->getMessage());
        //  }
    }
    /* Get Tax Codes Methods  */
    public function GetTaxCodes($account, $url = NULL, $page = 1, $type = "normal")
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/taxes?per_page=100&page=1&consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        $response = $this->mobj->makeRequest('GET', $url, [], $header);
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Get Product Attributes Codes Methods  */
    public function GetAttributes($account, $url = NULL, $page = 1, $type = "normal")
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/products/attributes?per_page=100&page=1&consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        $response = $this->mobj->makeRequest('GET', $url, [], $header);
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Get Customer By ID  */
    public function GetCustomerById($account, $url = NULL)
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        if (isset($url)) {
            $url = $account->api_domain . "/wp-json/wc/v3/{$url}?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        } else {
            $url = $account->api_domain . "/wp-json/wc/v3/customers?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        }

        $response = $this->mobj->makeRequest('GET', $url, [], $header);
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Get Categories List  */
    public function GetCategories($account, $url = NULL, $page = 1, $limit=100)
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/products/categories?page={$page}&per_page={$limit}&consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        $response = $this->mobj->makeRequest('GET', $url, [], $header);
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Create Or Update Or Delete Categories  */
    public function CreateOrUpdateOrDeleteCategories($account, $url = NULL, $postData = [])
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/products/categories/batch?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        $response = $this->mobj->makeRequest('POST', $url, $postData, $header);
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
    /* Create | Update | Delete Product  */
    public function CreateOrUpdateOrDeleteProduct($account, $url = NULL, $postData = [], $type = NULL)
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        if ($type == "update") {
            $url = $account->api_domain . "/wp-json/wc/v3/{$url}?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
            $response = $this->mobj->makeRequest('PUT', $url, $postData, $header);
        } else  if ($type == "create") {
            $url = $account->api_domain . "/wp-json/wc/v3/{$url}?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
            $response = $this->mobj->makeRequest('POST', $url, $postData, $header);
        } else  if ($type == "delete") {
            $url = $account->api_domain . "/wp-json/wc/v3/{$url}?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
            $response = $this->mobj->makeRequest('DELETE', $url, $postData, $header);
        }
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
      /* Create | Update | Delete Product Variation */
      public function CreateOrUpdateOrDeleteVariationProduct($account, $url = NULL, $postData = [], $type = NULL)
      {
          // try {
          //     $response = false;
          $header = $this->MakeHeader($account);
          if ($type == "update") {
              $url = $account->api_domain . "/wp-json/wc/v3/{$url}?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
              $response = $this->mobj->makeRequest('PUT', $url, $postData, $header);
          } else  if ($type == "create") {
              $url = $account->api_domain . "/wp-json/wc/v3/{$url}?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
              $response = $this->mobj->makeRequest('POST', $url, $postData, $header);
          } else  if ($type == "delete") {
              $url = $account->api_domain . "/wp-json/wc/v3/{$url}?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
              $response = $this->mobj->makeRequest('DELETE', $url, $postData, $header);
          }
          return $response;
          // } catch (\Exception $e) {
          //     \Log::error($e->getMessage());
          // }
      }
    /* Get Order Refund  */
    public function GetOrderRefundDetail($account, $url = NULL, $orderID = 0)
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        if ($url) {
            $url = $account->api_domain . "/wp-json/wc/v3/{$url}?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        } else {
            $url = $account->api_domain . "/wp-json/wc/v3/orders/{$orderID}/refunds?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        }
        $response = $this->mobj->makeRequest('GET', $url, [], $header);
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
     /* Get Orders  */
     public function GetOrders($account, $url = NULL, $page = 1, $limit = 100, $type = "normal")
     {

         $header = $this->MakeHeader($account);
         if ($url) {
             $url = $account->api_domain ."/wp-json/wc/v3/{$url}consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
         } else {
             $url = $account->api_domain . "/wp-json/wc/v3/orders?page={$page}&per_page={$limit}&consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
         }
         $response = $this->mobj->makeRequest('GET', $url, [], $header);
         return $response;
     }
      /* search product by sku  */
    public function searchProductBySKU($account, $url)
    {
        // try {
        //     $response = false;
        $header = $this->MakeHeader($account);
        $url = $account->api_domain . "/wp-json/wc/v3/products?{$url}&consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
        $response = $this->mobj->makeRequest('POST', $url, [], $header, "json");
        return $response;
        // } catch (\Exception $e) {
        //     \Log::error($e->getMessage());
        // }
    }
     /* search Product By ID  */
     public function searchProductByID($account, $productID)
     {
         // try {
         //     $response = false;
         $header = $this->MakeHeader($account);
         $url = $account->api_domain . "/wp-json/wc/v3/products/{$productID}?consumer_key={$this->mobj->encrypt_decrypt($account->app_id, "decrypt")}&consumer_secret={$this->mobj->encrypt_decrypt($account->app_secret, "decrypt")}";
         $response = $this->mobj->makeRequest('GET', $url, [], $header, "json");
         return $response;
         // } catch (\Exception $e) {
         //     \Log::error($e->getMessage());
         // }
     }
     private function getResponse($server_response)
     {
         return [
             'status_code' => $server_response->getStatusCode(),
             'body' => json_decode($server_response->getBody(), true),
             'reason' => $server_response->getReasonPhrase()
         ];
     }
}
