<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HttpsProtocol {

    public function handle($request, Closure $next)
    {
            if (!$request->secure() && env('APP_ENV') === 'prod') {
                //$request->setTrustedProxies([$request->getClientIp(), null]);
                //Request::setTrustedProxies([$request->getClientIp()], Request::HEADER_X_FORWARDED_ALL);
                //if (!$request->secure()) {
                    //return redirect()->secure($request->getRequestUri());
                // }
                if (!app()->environment('local')) {
                    // for Proxies
                    Request::setTrustedProxies([$request->getClientIp()],
                        Request::HEADER_X_FORWARDED_ALL);

                    if (!$request->isSecure()) {
                        return redirect()->secure($request->getRequestUri());
                    }
                }

            }
            return $next($request);
    }
}

?>
