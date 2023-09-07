<?php

use Illuminate\Database\Seeder;
use App\Models\PlatformLookup;
class PlatformLookupTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        PlatformLookup::create(['platform_id'=>'brightpearl','platform_name'=>'Brightpearl','platform_image'=>'/public/esb_asset/brand_icons/brightpearl.jpgs','auth_endpoint'=>'InitiateBPAuth','status'=>1,'auth_type'=>'auth_type']);
    }
}
