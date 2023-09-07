<?php

use Illuminate\Database\Seeder;
use App\Models\UserInformation;
use App\User;
class UsersInformationTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $users = User::where('status',1)->select('id')->get();
        if(!empty($users)){
            foreach($users as $user){
                UserInformation::create(['user_id'=>$user->id]);
            }
        }
    }
}
