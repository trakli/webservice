<?php

namespace App\Holdings;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;

/**
 * Scopes owner-access records to the authenticated user: a user may only reach
 * records whose owner is their own User.
 */
class UserOwnerAuthorizer implements OwnerAuthorizer
{
    public function authorize(?Authenticatable $user, string $ownerType, mixed $ownerId): bool
    {
        return $user !== null
            && $ownerType === User::class
            && (int) $ownerId === (int) $user->getAuthIdentifier();
    }

    public function scope(
        Builder $query,
        ?Authenticatable $user,
        string $ownerTypeColumn = 'owner_type',
        string $ownerIdColumn = 'owner_id',
    ): Builder {
        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where($ownerTypeColumn, User::class)
            ->where($ownerIdColumn, $user->getAuthIdentifier());
    }
}
