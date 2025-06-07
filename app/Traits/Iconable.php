<?php

namespace App\Traits;

use App\Models\Icon;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait Iconable
{
    public function getIconAttribute()
    {
        return $this->icon()->first();
    }

    public function icon(): MorphOne
    {
        return $this->morphOne(Icon::class, 'iconable');
    }
}
