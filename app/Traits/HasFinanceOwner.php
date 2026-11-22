<?php

namespace App\Traits;

use InvalidArgumentException;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

trait HasFinanceOwner
{
    public function initializeHasFinanceOwner(): void
    {
        $this->append('user_id');
        $this->mergeFillable(['owner_type', 'owner_id']);
    }

    public static function bootHasFinanceOwner(): void
    {
        static::saving(function (Model $record) {
            $allowedTypes = [(new User())->getMorphClass(), (new Workspace())->getMorphClass()];
            if (! in_array($record->owner_type, $allowedTypes, true) || ! $record->owner_id) {
                throw new InvalidArgumentException('A finance record must have a user or workspace owner.');
            }
            if ($record->exists && $record->isDirty(['owner_type', 'owner_id'])) {
                throw new InvalidArgumentException('Finance ownership cannot be changed after creation.');
            }
        });
    }

    public function isOwnedBy(Model $owner): bool
    {
        return $this->owner_type === $owner->getMorphClass() && (int) $this->owner_id === (int) $owner->getKey();
    }

    public function hasSameOwner(Model $record): bool
    {
        return $this->owner_type === $record->owner_type && (int) $this->owner_id === (int) $record->owner_id;
    }

    protected function getArrayableAppends(): array
    {
        $appends = parent::getArrayableAppends();
        if (! array_key_exists('owner_type', $this->attributes) || ! array_key_exists('owner_id', $this->attributes)) {
            unset($appends['user_id']);
        }

        return $appends;
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where($this->qualifyColumn('owner_type'), $owner->getMorphClass())
            ->where($this->qualifyColumn('owner_id'), $owner->getKey());
    }

    public function getUserIdAttribute(): ?int
    {
        return $this->owner_type === (new User())->getMorphClass() ? (int) $this->owner_id : null;
    }

    public function setUserIdAttribute($value): void
    {
        if ($value !== null) {
            $this->attributes['owner_type'] = (new User())->getMorphClass();
            $this->attributes['owner_id'] = (int) $value;
        }
    }
}
