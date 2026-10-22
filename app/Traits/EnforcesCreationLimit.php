<?php

namespace App\Traits;

use App\Models\Category;
use App\Models\User;
use App\Support\CategoryAllowance;
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
            $allowance = app(CategoryAllowance::class);
            $count = $this instanceof Category
                ? $allowance->count($owner)
                : static::where('user_id', $this->user_id)->count();
            $exempt = $this instanceof Category && $this->isSeeded();

            if (! $exempt && $limit !== null && $count >= $limit) {
                throw new HttpResponseException(app(config('user-authentication.response_formatter'))->failure(
                    'Your plan limit has been reached.',
                    403,
                    ['limit' => $key, 'maximum' => $limit],
                ));
            }

            $inserted = parent::performInsert($query);
            if ($inserted && $exempt) {
                $allowance->exempt($owner, $this);
            }

            return $inserted;
        });
    }
}
