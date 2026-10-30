<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\ActiveMonthSeeder;
use Database\Seeders\SharedSpaceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Whilesmart\UserAuthentication\Events\UserRegisteredEvent;

class DemoCommand extends Command
{
    protected $signature = 'demo {scenario : Screenshot account to create (onboarding|active-month|shared-space)}';

    protected $description = 'Create a dedicated screenshot account with generated credentials';

    private const SCENARIOS = ['onboarding', 'active-month', 'shared-space'];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Screenshot accounts can only be created in local or testing environments.');

            return self::FAILURE;
        }

        $scenario = $this->argument('scenario');
        if (! in_array($scenario, self::SCENARIOS, true)) {
            $this->error('Unknown scenario. Use ' . implode(', ', self::SCENARIOS) . '.');

            return self::FAILURE;
        }

        $password = Str::password(24, symbols: false);
        $output = DB::transaction(function () use ($scenario, $password) {
            $user = $this->account(
                $scenario,
                $scenario === 'onboarding' ? 'Alex' : 'Maya',
                $scenario === 'onboarding' ? 'Morgan' : 'Fischer',
                $password
            );
            if ($scenario !== 'onboarding') {
                (new ActiveMonthSeeder())->run($user);
            }
            $output = ['username' => $user->email, 'password' => $password];

            if ($scenario === 'shared-space') {
                $member = $this->account($scenario, 'Amara', 'Okafor', Str::password(24, symbols: false));
                $invitee = 'demo+' . $scenario . '-invitee-' . Str::uuid() . '@trakli.test';
                $seeder = new SharedSpaceSeeder();
                $workspace = $seeder->run($user, $member, $invitee);
                $output['workspace'] = $workspace->slug;
                if ($seeder->invitationUrl) {
                    $output['invitation'] = parse_url($seeder->invitationUrl, PHP_URL_PATH);
                }
            }

            return $output;
        });

        $this->line(json_encode($output, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function account(string $scenario, string $firstName, string $lastName, string $password): User
    {
        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'demo+' . $scenario . '-' . Str::uuid() . '@trakli.test',
            'password' => $password,
            'email_verified_at' => now(),
        ]);
        UserRegisteredEvent::dispatch($user);

        return $user;
    }
}
