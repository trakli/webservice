<?php

namespace App\Support;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;
use Whilesmart\ModelConfiguration\Models\Configuration;

class CategoryAllowance
{
    public const KEY = 'category-allowance-exempt-ids';

    public function count(User $owner): int
    {
        return $owner->categories()->whereNotIn('id', $this->exemptIds($owner))
            ->lockForUpdate()->pluck('id')->count();
    }

    public function exempt(User $owner, Category $category): void
    {
        $this->store($owner, array_values(array_unique([...$this->exemptIds($owner), $category->getKey()])));
    }

    private function exemptIds(User $owner): array
    {
        $configuration = $this->query($owner)->lockForUpdate()->first();
        if ($configuration !== null) {
            return $configuration->value;
        }

        $ids = $owner->categories()->withTrashed()->pluck('id')->all();
        $this->store($owner, $ids);

        return $ids;
    }

    private function query(User $owner): Builder
    {
        return Configuration::query()->where([
            'configurable_type' => $owner->getMorphClass(),
            'configurable_id' => $owner->getKey(),
            'key' => self::KEY,
        ]);
    }

    private function store(User $owner, array $ids): void
    {
        $configuration = $this->query($owner)->lockForUpdate()->first() ?? new Configuration([
            'configurable_type' => $owner->getMorphClass(),
            'configurable_id' => $owner->getKey(),
            'key' => self::KEY,
        ]);
        $configuration->fill(['type' => ConfigValueType::Array->value, 'value' => $ids])->save();
    }
}
