<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Gating seam for features, limits and metered usage, keyed on the resource
 * owner so a shared owner (a future workspace or couple) is gated as a unit.
 *
 * The core binds a permissive default that allows everything; an external
 * plugin may rebind it to enforce limits. No billing logic lives here.
 */
interface Entitlements
{
    public function allows(?Model $owner, string $feature): bool;

    public function limit(?Model $owner, string $key): ?int;

    public function remaining(?Model $owner, string $meter): int|float;

    public function consume(?Model $owner, string $meter, int $amount): void;
}
