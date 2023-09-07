<?php

namespace App\Http\Middleware;

use Closure;
use Auth;
class IsMasterStaff
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
        if (Auth::user() &&  (Auth::user()->role == "master_staff" || Auth::user()->role == "user_staff")) {
             return $next($request);
        }

        return redirect('/')->with('error','You have not access');
    }
}
