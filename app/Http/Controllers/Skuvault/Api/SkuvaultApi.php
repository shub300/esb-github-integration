<?php


namespace App\Http\Controllers\Skuvault\Api;

use Auth;
use DB;
use App\Helper\MainModel;
use App\Common;

class SkuvaultApi
{

    public function __construct()
    {
        $this->mobj = new MainModel();
    }


    public function GetSKVToken($sku_email, $sku_pwd, $url)
    {
        $mobj = new MainModel;
        $post_data = json_encode(["Email" => $sku_email, "Password" => $sku_pwd], true);
        $header = ['Content-Type: application/json', 'Accept: application/json'];
        $response = $this->mobj->makeCurlRequest('post', $url . '/gettokens', $post_data, $header);

        return $response;
    }

    public function GetProduct($request_data_json, $url)
    {
        $service_url = $url . '/products/getProducts';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];

        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function GetWarehouse($request_data_json, $url)
    {
        $service_url = $url . '/inventory/getWarehouses';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function GetProductInventory($request_data_json, $url)
    {
        $service_url =  $url . '/inventory/getInventoryByLocation';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];

        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function GetProductInventoryBytime($request_data_json, $url)
    {
        $service_url =  $url . '/inventory/getItemQuantities';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];

        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function createOnlineSale($order_array, $url)
    {

        $request_data_json = json_encode($order_array, true);

        $service_url = $url . '/sales/syncOnlineSale';   //for solo order api
        $headers = ['Content-Type:application/json', 'Accept:application/json'];

        //API request
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function getOnlineSaleByMultipleIds($orderGetPayload, $url)
    {

        $request_data_json = json_encode($orderGetPayload, true);

        $service_url = $url . '/sales/getSales';

        $headers = ['Content-Type:application/json', 'Accept:application/json'];

        //API request
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);

        return $response;
    }

    public function UpdateProduct($request_data_json, $url, $endpoint)
    {
        $service_url =  $url . $endpoint;
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }
    public function GetBrands($request_data_json, $url)
    {
        $service_url = $url . '/products/getBrands';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function GetCategory($request_data_json, $url)
    {
        $service_url = $url . '/products/getClassifications';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }
    public function CreateSupplier($request_data_json, $url)
    {
        $service_url = $url . '/products/createSuppliers';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function GetSuppliers($request_data_json, $url)
    {
        $service_url = $url . '/products/getSuppliers';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }
    public function CreateBrand($request_data_json, $url)
    {
        $service_url = $url . '/products/createBrands';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function GetShipments($request_data_json, $url)
    {
        $service_url = $url . '/sales/getShipments';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];
        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }
    public function GetAvailableQuantities($request_data_json, $url)
    {
        $service_url =  $url . '/inventory/getAvailableQuantities';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];

        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    public function GetOrderStatus($request_data_json, $url)
    {
        $service_url =  $url . '/sales/getSalesByDate';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];

        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }
	
	
	//get Sku vault product by sku
    public function GetProduct_by_sku($request_data_json, $url)
    {
        $service_url = $url . '/products/getProduct';
        $headers = [
            'Content-Type:application/json',
            'Accept:application/json'
        ];

        $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
        return $response;
    }

    //get Sku vault product by sku
    public function GetAvailableKitAndQuantities($request_data_json, $url)
    {
         $service_url = $url . '/products/getKits';
         $headers = [
             'Content-Type:application/json',
             'Accept:application/json'
         ];
 
         $response = $this->mobj->makeCurlRequest('POST', $service_url, $request_data_json, $headers);
         return $response;
    } 
	
	
}
