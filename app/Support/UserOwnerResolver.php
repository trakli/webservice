<?php

namespace App\Support;

use App\Contracts\OwnerResolver;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class UserOwnerResolver implements OwnerResolver
{
    public function resolveUserIds(Model $owner): array
    {
        if ($owner instanceof User) {
            return [$owner->id];
        }

        return [];
    }

    public function visibleOwners(Model $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        return [
            ['owner_type' => User::class, 'owner_ids' => [$user->id]],
        ];
    }
}
