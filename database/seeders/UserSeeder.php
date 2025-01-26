<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Group;
use App\Models\Party;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        foreach (range(1, 10) as $index) {
            $user = User::factory()->create([
                'email' => "user{$index}@trakli.io",
                'username' => "user{$index}",
            ]);

            Party::factory(5)->create([
                'user_id' => $user->id,
            ]);

            Category::factory(5)->create([
                'user_id' => $user->id,
            ]);

            Wallet::factory(5)->create([
                'user_id' => $user->id,
            ]);

            Group::factory(5)->create([
                'user_id' => $user->id,
            ]);
        }
    }
}
