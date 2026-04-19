<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Model;

interface OwnerResolver
{
    /**
     * Return the set of user IDs whose transactions count toward resources
     * owned by $owner. For a User owner this is simply [$owner->id]. When
     * Workspace/Couple land, their resolvers return the full membership.
     *
     * @return array<int>
     */
    public function resolveUserIds(Model $owner): array;

    /**
     * Return the set of budget records visible to $user across every
     * supported owner type. Used by BudgetController scopes and queries
     * that need to enumerate what the user can see.
     *
     * @return array<array{owner_type: class-string, owner_ids: array<int>}>
     */
    public function visibleOwners(Model $user): array;
}
