<?php

namespace App\Models;

use App\Enums\ConfigValueType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserConfiguration extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'key', 'type', 'value'];

    protected $casts = [
        'value' => 'json',
    ];

    public static function getValue($key, $userId)
    {
        $config = static::where('key', $key)
            ->where('user_id', $userId)
            ->first();
        if (! is_null($config)) {
            $type = ConfigValueType::tryFrom($config->type);
            if (! is_null($type)) {
                return $type->getValue($config->value);
            }
        }

        return null;
    }

    public static function setValue($key, $value, $userId, ConfigValueType $type)
    {
        if ($type === ConfigValueType::Date && $value instanceof Carbon) {
            $value = $value->toDateTimeString(); // Or any other suitable string format
        }

        return static::updateOrCreate([
            'key' => $key,
            'user_id' => $userId,
            'value' => $value,
            'type' => $type->value,
        ]
        );
    }
}
