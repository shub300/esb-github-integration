<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ShipBobRedirectController extends Controller
{
    public function ShipBobRedirectHandler(Request $request){
        $log = $request->all();
        \Storage::append('ship_bob_redirect_handler_log.txt', print_r($log, true));
        \Storage::append('ship_bob_redirect_handler_log.txt', ' ');
    }
}
