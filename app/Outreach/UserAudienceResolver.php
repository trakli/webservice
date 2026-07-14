<?php

namespace App\Outreach;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Whilesmart\Outreach\Contracts\AudienceResolver;

class UserAudienceResolver implements AudienceResolver
{
    /**
     * @param  array<string, mixed>  $audience
     * @return iterable<Model>
     */
    public function resolve(array $audience): iterable
    {
        $mode = $audience['mode'] ?? 'all';
        $userIds = $audience['user_ids'] ?? [];

        return match ($mode) {
            'test' => User::query()->whereKey($audience['actor_id'] ?? 0)->get(),
            'specific' => User::query()->whereIn('id', $userIds)->get(),
            'active' => User::query()->whereHas('transactions', function ($q) {
                $q->where('datetime', '>=', now()->subDays(30));
            })->get(),
            'inactive' => User::query()->whereDoesntHave('transactions', function ($q) {
                $q->where('datetime', '>=', now()->subDays(30));
            })->get(),
            default => User::query()->get(),
        };
    }
}
