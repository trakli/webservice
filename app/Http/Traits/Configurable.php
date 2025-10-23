<?php

namespace App\Http\Traits;

use App\Models\Configuration;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;
use Whilesmart\ModelConfiguration\Traits\Configurable as ConfigurableTrait;

trait Configurable
{
    use ConfigurableTrait;

    public function configurations(): MorphMany
    {
        return $this->morphMany(Configuration::class, 'configurable');
    }

    public function setConfigValue($key, $value, ConfigValueType $type): Configuration
    {
        if ($type === ConfigValueType::Date && $value instanceof Carbon) {
            $value = $value->toDateTimeString(); // Or any other suitable string format
        }

        $configuration = $this->configurations()->where('key', $key)->first();
        if ($configuration) {
            $configuration->update([
                'value' => $value,
                'type' => $type->value,
            ]);
        } else {
            $configuration = $this->configurations()->create([
                'key' => $key,
                'value' => $value,
                'type' => $type->value,
            ]);
        }

        return $configuration;
    }
}
