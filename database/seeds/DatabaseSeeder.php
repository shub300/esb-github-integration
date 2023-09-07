<?php

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
         $this->call(OrganizationsTableSeeder::class);
         $this->call(CountryCodeTableSeeder::class);
         $this->call(UsersTableSeeder::class);
         $this->call(UsersInformationTableSeeder::class);
         $this->call(PlatformLookupTableSeeder::class);
         $this->call(PlatformEventTableSeeder::class);
         $this->call(PlatformSubEventTableSeeder::class);
    }
}
