<?php

use Illuminate\Database\Seeder;
use App\Models\Organizations;
class OrganizationsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Organizations::create(
                ['name'=>'APIWORX',
                'access_url'=>'localhost',
                'about_org'=>'APIWORX'
               ]);
    }
}
