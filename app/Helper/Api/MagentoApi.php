<?php
namespace App\Helper\Api;
use App\Helper\MainModel;


class MagentoApi
{
    public $mobj;

    public function __construct()
    {
        $this->mobj = new MainModel();
    }
    
    public function GetMagentoHeader($user_integration_id = 0, $platform_id = 0, $access_token = null, $host = null )
    {
        if($user_integration_id && $platform_id)
        {
            $Token = $this->mobj->getPlatformAccountByUserIntegration($user_integration_id, $platform_id, ['access_token','api_domain']);

            if(empty($Token))
            {
                return false;
            }

            $header = ['header' => ["Accept: application/json","Content-Type: application/json",'Authorization: Bearer '.$this->mobj->encrypt_decrypt($Token->access_token,'decrypt')] , 'host' => $Token->api_domain ];
        }
        else
        {
            $header = ['header' => ["Accept: application/json","Content-Type: application/json",'Authorization: Bearer '.$access_token] , 'host' => $host ];
        }

        return $header;
    }
    
    public function validateToken($user_integration_id = 0, $platform_id = 0, $access_token = null, $host = null )
    {
        $host = $host ? trim($host,' /') : $host;
        $url = $host."/index.php/rest/V1/orders?searchCriteria[pageSize]=1"; 
         
        $header =  $this->GetMagentoHeader($user_integration_id,$platform_id,$access_token,$host);        
      
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header['header']);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch , CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode($result, true);
        
        if($httpCode == 200)
        {
            return true;
        }
        return false;
    }
    
    
    public function GetStores($user_integration_id = 0, $platform_id = 0 )
    {
         
        $header =  $this->GetMagentoHeader($user_integration_id,$platform_id); 
        
        if(empty($header))
        {
            return false;
        }       
        
        $host =  trim($header['host'],' /');
        $url = $host."/index.php/rest/V1/store/storeViews"; 
       
      
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header['header']);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch , CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode($result, false); 
        
        if($httpCode != 200)
        {
            return false;
        }       
        return $result;
    }
    
    public function SearchCustomers($user_integration_id = 0, $platform_id = 0, $search_string, $store_id = '')
    {
         
        $header =  $this->GetMagentoHeader($user_integration_id,$platform_id); 
        
        if(empty($header))
        {
            return false;
        }       
        
        $host =  trim($header['host'],' /');
        $url = $host."/index.php/rest/V1/customers/search?searchCriteria[filterGroups][0][filters][0][conditionType]=equal&searchCriteria[filterGroups][0][filters][0][field]=email&searchCriteria[filterGroups][0][filters][0][value]=".urlencode($search_string);//."&searchCriteria[filterGroups][1][filters][0][conditionType]=equal&searchCriteria[filterGroups][1][filters][0][field]=store_id&searchCriteria[filterGroups][1][filters][0][value]=".$store_id; 
       
      
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header['header']);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch , CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode($result, false); 
        
        if($httpCode != 200)
        {
            return false;
        }       
        return $result;
    }
    
    public function CreateCustomers($user_integration_id = 0, $platform_id = 0, $data)
    {
         
        $header =  $this->GetMagentoHeader($user_integration_id,$platform_id); 
        
        if(empty($header))
        {
            return false;
        }       
        
        $host =  trim($header['host'],' /');
        $url = $host."/index.php/rest/V1/customers"; 
        
        $body = json_encode(["customer" => [
        "email" => $data['email'],
         "firstname" => $data['firstName'],
        "lastname" => $data['lastName'], 
          'store_id'=> $data['store_id'],
        "addresses" => [["city" => $data['city'], 'country_id' => $data['country'], 'postcode' =>  $data['zip'], 
        'street' => [$data['address1'], $data['address2']], "telephone" => $data['phone'],
         "firstname" => $data['firstName'],
        "lastname" => $data['lastName'], 
        ]
      
        ]
        ]]);
       
      
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header['header']);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch , CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode($result, true); 
        
        if($httpCode != 200)
        {
            return ['error' => !empty($result->message) ? $result->message : 'error'];
        }       
        return $result;
    }
    
    
    public function SearchProducts($user_integration_id = 0, $platform_id = 0, $search_string, $store_id = '')
    {
         
        $header =  $this->GetMagentoHeader($user_integration_id,$platform_id); 
        
        if(empty($header))
        {
            return false;
        }       
        
        $host =  trim($header['host'],' /');
        $url = $host."/index.php/rest/V1/products?searchCriteria[filterGroups][0][filters][0][conditionType]=equal&searchCriteria[filterGroups][0][filters][0][field]=sku&searchCriteria[filterGroups][0][filters][0][value]=".urlencode($search_string);//."&searchCriteria[filterGroups][1][filters][0][conditionType]=equal&searchCriteria[filterGroups][1][filters][0][field]=store_id&searchCriteria[filterGroups][1][filters][0][value]=".$store_id; 
       
      
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header['header']);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch , CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode($result, false); 
        
        if($httpCode != 200)
        {
            return false;
        }       
        return $result;
    }
    
    
    public function CreateSalesOrder($user_integration_id = 0, $platform_id = 0, $data)
    {
         
        $header =  $this->GetMagentoHeader($user_integration_id,$platform_id); 
        
        if(empty($header))
        {
            return false;
        }       
        
        $host =  trim($header['host'],' /');
        $url = $host."/index.php/rest/V1/orders"; 
        
        $body = json_encode($data);
       
      
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header['header']);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch , CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode($result, true); 
        
        if($httpCode != 200)
        {
            return ['error' => !empty($result['message']) ? $result['message'] : 'error'];
        }       
        return $result;
    }
}
