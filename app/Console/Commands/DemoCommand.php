<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\ActiveMonthSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Whilesmart\UserAuthentication\Events\UserRegisteredEvent;

class DemoCommand extends Command
{
    protected $signature = 'demo {scenario : Screenshot account to create (onboarding|active-month)}';

    protected $description = 'Create a dedicated screenshot account with generated credentials';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Screenshot accounts can only be created in local or testing environments.');

            return self::FAILURE;
        }

        $scenario = $this->argument('scenario');
        if (! in_array($scenario, ['onboarding', 'active-month'], true)) {
            $this->error('Unknown scenario. Use onboarding or active-month.');

            return self::FAILURE;
        }

        $password = Str::password(24, symbols: false);
        $user = DB::transaction(function () use ($scenario, $password) {
            $user = User::create([
                'first_name' => $scenario === 'onboarding' ? 'Alex' : 'Maya',
                'last_name' => $scenario === 'onboarding' ? 'Morgan' : 'Fischer',
                'email' => 'demo+' . $scenario . '-' . Str::uuid() . '@trakli.test',
                'password' => $password,
                'email_verified_at' => now(),
            ]);
            UserRegisteredEvent::dispatch($user);

            if ($scenario === 'active-month') {
                (new ActiveMonthSeeder())->run($user);
            }

            return $user;
        });

        $this->line(json_encode(['username' => $user->email, 'password' => $password], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
