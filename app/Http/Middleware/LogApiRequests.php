<?php

namespace App\Http\Middleware;

use Closure;

class LogApiRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        $api_log = [
            'URI' => $request->url(),
            'METHOD' => $request->method(),
            'REQUEST_DATA' => $request->all(),
            'RESPONSE' => $response->getContent()
        ];

        \Storage::disk('local')->append('LogApiRequests.txt', 'Log Api request Run-Time: ' . date("Y-m-d H:i:s") . ', API Log: ' . json_encode($api_log) . PHP_EOL);

        return $response;
    }
}
