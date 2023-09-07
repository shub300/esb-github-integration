<?php
 
namespace App\Http\View\Composers;
 
use Illuminate\View\View;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use App\Models\Organizations;
class OrganizationStyleComposer
{
    
    public function compose(View $view)
    {
        $org_style = isset(Organizations::find(config('org_details.id'))->style) ? Organizations::find(config('org_details.id'))->style : null;
    
        $view->with('org_style', $org_style);
    }
}