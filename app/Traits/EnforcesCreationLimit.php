<?php

namespace App\Traits;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Whilesmart\Entitlements\Contracts\Entitlements;

trait EnforcesCreationLimit
{
    protected function performInsert(Builder $query)
    {
        return $this->getConnection()->transaction(function () use ($query) {
            $owner = User::whereKey($this->user_id)->lockForUpdate()->first();
            $key = $this instanceof Category ? 'max_categories' : 'max_wallets';
            $limit = app(Entitlements::class)->limit($owner, $key);
            $count = static::where('user_id', $this->user_id)
                ->when($this instanceof Category, fn ($q) => $q->where('provenance', 'custom'))
                ->count();
            $exempt = $this instanceof Category && $this->provenance === 'seeded';

            if (! $exempt && $limit !== null && $count >= $limit) {
                throw new HttpResponseException(app(config('user-authentication.response_formatter'))->failure(
                    'Your plan limit has been reached.',
                    403,
                    ['limit' => $key, 'maximum' => $limit],
                ));
            }

            return parent::performInsert($query);
        });
    }
}
