<?php

namespace App\Support;

use App\Contracts\Entitlements;
use Illuminate\Database\Eloquent\Model;

/**
 * Default Entitlements: grants every feature, imposes no limit, never meters.
 * Keeps the open core free and self-hostable until a plugin overrides it.
 */
class AllowAllEntitlements implements Entitlements
{
    public function allows(?Model $owner, string $feature): bool
    {
        return true;
    }

    public function limit(?Model $owner, string $key): ?int
    {
        return null;
    }

    public function remaining(?Model $owner, string $meter): int|float
    {
        return INF;
    }

    public function consume(?Model $owner, string $meter, int $amount): void
    {
    }
}
