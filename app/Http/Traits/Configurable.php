<?php

namespace App\Http\Traits;

use App\Models\Configuration;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Whilesmart\ModelConfiguration\Traits\Configurable as ConfigurableTrait;

trait Configurable
{
    use ConfigurableTrait;

    public function configurations(): MorphMany
    {
        // Override the configurations method to use our custom Configuration class.
        return $this->morphMany(Configuration::class, 'configurable');
    }
}
