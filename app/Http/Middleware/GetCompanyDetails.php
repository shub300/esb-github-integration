<?php

namespace App\Http\Middleware;

use Closure;
use DB;
use App\Http\Controllers\CommonController;
class GetCompanyDetails
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
        $host = request()->getHost();
        $host = preg_replace('#^www\.(.+\.)#i', '$1', $host); // to remove www from host name if it contains.

        // Set default theme color
        config(['global.theme_color_1' => '#00a2cf']);
        config(['global.theme_color_2' => '#006696']);

        $org_details = DB::table('es_organizations')
        ->select('id', 'name', 'organization_id', 'logo_url', 'favicon_url','help_doc_url','contact_us_url','privacy_url','terms_url','support_code','org_identity','notify_to_support','org_target_url')
        ->where('access_url', $host)->first();
        
        if($org_details){
            config(['org_details.id' => $org_details->id]);
            config(['org_details.name' => $org_details->name]);
            config(['org_details.logo' => $org_details->logo_url]);
            config(['org_details.favicon' => $org_details->favicon_url]);
            config(['org_details.access_url' => $host]);
            config(['org_details.organization_id' => $org_details->organization_id]);
            config(['org_details.help_doc_url' => trim($org_details->help_doc_url) ]);
            config(['org_details.contact_us_url' => trim($org_details->contact_us_url) ]); //trim
            config(['org_details.org_target_url' => trim($org_details->org_target_url) ]); 
            config(['org_details.privacy_url' => trim($org_details->privacy_url) ]);
            config(['org_details.terms_url' => trim($org_details->terms_url) ]);
            config(['org_details.support_code' => trim($org_details->support_code) ]);
            config(['org_details.org_identity' => trim($org_details->org_identity) ]);
            config(['org_details.notify_to_support' => $org_details->notify_to_support ]);
            return $next($request);
        }
        else{
            abort(404);
        }
    }
}
