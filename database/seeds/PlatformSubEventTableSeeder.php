<?php

use Illuminate\Database\Seeder;
use app\Models\PlatformEvent;
use app\Models\PlatformSubEvents;
class PlatformSubEventTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
       $platformEvent = PlatformEvent::where('platform_id',1)->select('id','platform_id')->first();
       if(!empty($platformEvent)){
       // PlatformSubEvents::create(['platform_event_id'=>$platformEvent->id,'name'=>'CUSTOMER','is_primary'=>1,'run_in_min'=>1]);
       }
    }
}
