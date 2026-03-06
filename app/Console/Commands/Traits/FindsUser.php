<?php

namespace App\Console\Commands\Traits;

use App\Models\User;

trait FindsUser
{
    private function findUser(?string $identifier = null): ?User
    {
        $identifier = $identifier ?? $this->argument('identifier');

        if (! $identifier) {
            $this->error('Please provide a user email or ID.');

            return null;
        }

        $user = is_numeric($identifier)
            ? User::find($identifier)
            : User::where('email', $identifier)->first();

        if (! $user) {
            $this->error("User '{$identifier}' not found.");

            return null;
        }

        return $user;
    }
}
