<?php

namespace App\Traits;

use App\Models\Group;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait Groupable
{
    public function getGroupsAttribute()
    {
        return $this->groups()->get();
    }

    public function getGroupAttribute()
    {
        return $this->groups()->first();
    }

    public function groups(): MorphToMany
    {
        return $this->morphToMany(Group::class, 'groupable');
    }
}
