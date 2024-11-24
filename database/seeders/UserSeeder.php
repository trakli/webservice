<?php

namespace Database\Seeders;

use App\Models\User;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        foreach (range(1, 10) as $index) {
            $email = "user{$index}@trakli.io";
            $username = "user{$index}";

            User::create([
                'first_name' => $faker->firstName,
                'last_name' => $faker->lastName,
                'email' => $email,
                'username' => $username,
                'phone' => $faker->phoneNumber,
                'password' => Hash::make('password123'),
            ]);
        }
    }
}
