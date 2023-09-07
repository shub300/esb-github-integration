<?php

use Illuminate\Database\Seeder;
use App\Models\PlatformLookup;
use App\Models\PlatformEvent;
class PlatformEventTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $platform = PlatformLookup::where(['platform_id'=>'brightpearl','status'=>1])->first();
        if(!empty($platform)){
            PlatformEvent::create(['platform_id'=>$platform->id,'event_description'=>'Get Customer','event_id'=>'GET_CUSTOMER','event_name'=>'Customer']);
        }
    }
}
