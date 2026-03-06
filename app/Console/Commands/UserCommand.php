<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\FindsUser;
use App\Events\AccountDeleted;
use Illuminate\Console\Command;

class UserCommand extends Command
{
    use FindsUser;

    protected $signature = 'user
        {action : The action to perform (show|delete)}
        {identifier : User email or ID}';

    protected $description = 'User management (show, delete)';

    public function handle(): void
    {
        $action = $this->argument('action');

        match ($action) {
            'show' => $this->showUser(),
            'delete' => $this->deleteUser(),
            default => $this->error("Unknown action '{$action}'. Use: show, delete."),
        };
    }

    private function showUser(): void
    {
        $user = $this->findUser();
        if (! $user) {
            return;
        }

        $isAdmin = $user->hasRole('admin') ? 'Yes' : 'No';

        $this->table(['Field', 'Value'], [
            ['ID', $user->id],
            ['Name', $user->first_name . ' ' . $user->last_name],
            ['Email', $user->email],
            ['Username', $user->username],
            ['Phone', $user->phone ?? 'N/A'],
            ['Admin', $isAdmin],
            ['Wallets', $user->wallets()->count()],
            ['Transactions', $user->transactions()->count()],
            ['Categories', $user->categories()->count()],
            ['Created', $user->created_at->format('Y-m-d H:i:s')],
        ]);
    }

    private function deleteUser(): void
    {
        $user = $this->findUser();
        if (! $user) {
            return;
        }

        $this->showUser();

        if (! $this->confirm("Are you sure you want to delete {$user->email}?")) {
            $this->info('Cancelled.');

            return;
        }

        $reason = $this->ask('Reason for deletion', 'Deleted by admin via CLI');

        $email = $user->email;
        $name = $user->first_name . ' ' . $user->last_name;

        $user->tokens()->delete();
        $user->delete();

        AccountDeleted::dispatch($name, $email, $reason, 'Admin CLI');

        $this->info("User {$email} deleted.");
    }
}
