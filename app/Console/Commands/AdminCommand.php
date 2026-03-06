<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\FindsUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Whilesmart\Roles\Models\Role;
use Whilesmart\Roles\Models\RoleAssignment;

class AdminCommand extends Command
{
    use FindsUser;

    protected $signature = 'admin
        {action : The action to perform (grant|revoke|create|list)}
        {identifier? : User email or ID}';

    protected $description = 'Admin role management (grant, revoke, create, list)';

    public function handle(): void
    {
        $action = $this->argument('action');

        match ($action) {
            'grant' => $this->grantAdmin(),
            'revoke' => $this->revokeAdmin(),
            'create' => $this->createAdmin(),
            'list' => $this->listAdmins(),
            default => $this->error("Unknown action '{$action}'. Use: grant, revoke, create, list."),
        };
    }

    private function ensureAdminRole(): void
    {
        Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Admin', 'description' => 'System administrator']
        );
    }

    private function grantAdmin(): void
    {
        $user = $this->findUser();
        if (! $user) {
            return;
        }

        $this->ensureAdminRole();

        if ($user->hasRole('admin')) {
            $this->warn("{$user->email} is already an admin.");

            return;
        }

        $user->assignRole('admin');
        $this->info("{$user->email} is now an admin.");
    }

    private function revokeAdmin(): void
    {
        $user = $this->findUser();
        if (! $user) {
            return;
        }

        if (! $user->hasRole('admin')) {
            $this->warn("{$user->email} is not an admin.");

            return;
        }

        $user->removeRole('admin');
        $this->info("Admin role revoked from {$user->email}.");
    }

    private function createAdmin(): void
    {
        $email = $this->argument('identifier') ?? $this->ask('Email');
        $firstName = $this->ask('First name');
        $lastName = $this->ask('Last name');
        $password = $this->secret('Password');

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email '{$email}' already exists. Use 'admin grant {$email}' instead.");

            return;
        }

        $user = User::create([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => strstr($email, '@', true) ?: $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);

        $this->ensureAdminRole();
        $user->assignRole('admin');

        $this->info("Admin user created: {$user->email}");
    }

    private function listAdmins(): void
    {
        $adminAssignments = RoleAssignment::whereHas('role', function ($query) {
            $query->where('slug', 'admin');
        })->where('assignable_type', User::class)->pluck('assignable_id');

        $admins = User::whereIn('id', $adminAssignments)
            ->get(['id', 'first_name', 'last_name', 'email', 'created_at']);

        if ($admins->isEmpty()) {
            $this->warn('No admin users found.');

            return;
        }

        $this->table(
            ['ID', 'Name', 'Email', 'Created'],
            $admins->map(fn ($user) => [
                $user->id,
                $user->first_name . ' ' . $user->last_name,
                $user->email,
                $user->created_at->format('Y-m-d'),
            ])
        );
    }
}
