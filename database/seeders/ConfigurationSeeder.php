<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ConfigurationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //enable feature for all existing users by default, can be turned off by user if they want
        foreach (\App\Models\User::all() as $user) {
            $user->setConfigValue('create-transfers-for-myself-transactions', true, \Whilesmart\ModelConfiguration\Enums\ConfigValueType::Boolean);
        }




    }
}
