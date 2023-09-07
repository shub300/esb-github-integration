<?php

namespace App\Http\Middleware;

use Closure;

class XFrameHeaders
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);
        $response->header('X-Frame-Options', 'deny');
        //add more headers here
        return $response;
    }
}
