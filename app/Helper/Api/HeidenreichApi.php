<?php

namespace App\Helper\Api;

use Auth;
use DB;
use App\Helper\MainModel;
// use App\Common;

class HeidenreichApi
{
    public function __construct()
    {
        $this->mobj = new MainModel();
    }
    public function GetInventory($request_data_json,$authHash,$username,$ProductNumbersArr)
    {
        $url="https://www.heidenreich-online.no/OnlineStockRequest.asmx/getStocksExternal?";

        $ProductNumbers ="";
        if($ProductNumbersArr){
            $ProductNumbers = $ProductNumbersArr;
        } else {
            $ProductNumbers .="&ProductNumbers=0000000";
        }

        $service_url = $url.$ProductNumbers.'&Stock=010&authentication='.$authHash.'&username='.$username;
        $headers = [];
        $response = $this->mobj->makeCurlRequest('GET', $service_url, $request_data_json, $headers); //('POST', $service_url, $request_data_json, $headers);
        return $response;
    }
}

//call back urls
// https://gconlineplus.de/OnlineStockRequest.asmx
//https://www.heidenreich-online.no/OnlineStockRequest.asmx
//https://gconlineplus.de/OnlineStockRequest.asmx/getStocksExternal?authentication=742422A4D5B78841572EC093B1E312E1&username=611518LAGER&Stock=010&ProductNumbers=1001005&ProductNumbers=5111513&ProductNumbers=5111523