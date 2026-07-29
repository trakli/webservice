<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\User;
use InvalidArgumentException;

/**
 * Turns the loose target references an agent produces ("groceries", a bare id)
 * into the pivot rows a budget needs, resolved against the acting user's own
 * records. A target the user does not own is refused rather than dropped, so a
 * budget never silently ends up covering less than it says.
 */
class BudgetTargetResolver
{
    /** Target kind to the user relation holding those records. */
    private const RELATIONS = [
        'category' => 'categories',
        'group' => 'groups',
        'wallet' => 'wallets',
    ];

    /**
     * Normalise targets to [['type' => ..., 'id' => ...], ...].
     *
     * @param  array<int, array<string, mixed>|string>  $targets
     * @return array<int, array{type: string, id: int}>
     */
    public function resolve(User $user, array $targets): array
    {
        $resolved = [];

        foreach ($targets as $target) {
            if (! is_array($target)) {
                throw new InvalidArgumentException('Each budget target needs a type (category, group or wallet) and a name or id.');
            }

            $type = strtolower(trim((string) ($target['type'] ?? '')));

            if (! isset(self::RELATIONS[$type])) {
                throw new InvalidArgumentException("Budget targets must be a category, group or wallet; \"{$type}\" is none of those.");
            }

            $resolved[] = ['type' => $type, 'id' => $this->resolveId($user, $type, $target)];
        }

        return $resolved;
    }

    /**
     * Attach resolved targets to a budget.
     *
     * @param  array<int, array{type: string, id: int}>  $targets
     */
    public function apply(Budget $budget, array $targets): void
    {
        $byRelation = [];

        foreach ($targets as $target) {
            $byRelation[self::RELATIONS[$target['type']]][] = $target['id'];
        }

        foreach ($byRelation as $relation => $ids) {
            $budget->{$relation}()->syncWithoutDetaching($ids);
        }

        if ($byRelation !== []) {
            // sync() leaves the parent alone, so a budget whose only change is
            // its targets would keep a stale updated_at.
            $budget->touch();
        }
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function resolveId(User $user, string $type, array $target): int
    {
        $relation = self::RELATIONS[$type];

        if (! empty($target['id'])) {
            $targetId = (int) $target['id'];

            if (! $user->{$relation}()->whereKey($targetId)->exists()) {
                throw new InvalidArgumentException("That {$type} does not belong to you.");
            }

            return $targetId;
        }

        $name = trim((string) ($target['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException("Each budget target needs a {$type} name or id.");
        }

        $record = $user->{$relation}()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($record === null) {
            throw new InvalidArgumentException("No {$type} named \"{$name}\" was found. Confirm it with the user or create it first.");
        }

        return (int) $record->id;
    }
}
