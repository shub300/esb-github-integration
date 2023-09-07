<?php

use Illuminate\Database\Seeder;
use App\User;
use Illuminate\Support\Facades\Hash;
class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        User::create(['name'=>'Super Admin','email'=>'superadmin@constacloud.com','password'=>Hash::make(123456),'role'=>'superadmin']);
        User::create(['name'=>'Admin','email'=>'admin@constacloud.com','password'=>Hash::make(123456),'role'=>'admin']);
        User::create(['name'=>'System User','email'=>'systemuser@constacloud.com','password'=>Hash::make(123456),'role'=>'user']);
        User::create(['name'=>'Admin Staff','email'=>'staffadmin@constacloud.com','password'=>Hash::make(123456),'role'=>'admin_staff']);
        User::create(['name'=>'User Staff','email'=>'staffuser@constacloud.com','password'=>Hash::make(123456),'role'=>'user_staff']);
    }
}
