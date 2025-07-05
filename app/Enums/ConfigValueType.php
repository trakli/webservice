<?php

namespace App\Enums;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

enum ConfigValueType: string
{
    case String = 'string';
    case Integer = 'int';
    case Float = 'float';
    case Boolean = 'bool';
    case Array = 'array';
    case Json = 'json';
    case Date = 'date';

    public function getValue(mixed $raw): mixed
    {
        return match ($this) {
            self::String => $this->getStringValue($raw),
            self::Integer => $this->getIntegerValue($raw),
            self::Float => $this->getFloatValue($raw),
            self::Boolean => $this->getBooleanValue($raw),
            self::Array => $this->getArrayValue($raw),
            self::Json => $this->getJsonValue($raw),
            self::Date => $this->getDateValue($raw),
        };
    }

    protected function getStringValue(mixed $value): string
    {
        return (string) $value;
    }

    protected function getIntegerValue(mixed $value): int
    {
        return (int) $value;
    }

    protected function getFloatValue(mixed $value): float
    {
        return (float) $value;
    }

    protected function getBooleanValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    protected function getArrayValue(mixed $value): array
    {
        return (array) $value;
    }

    protected function getJsonValue(mixed $value): mixed
    {
        return $value;
    }

    protected function getDateValue(mixed $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\InvalidArgumentException $e) {
            Log::warning("Failed to parse date value: $value. Error: {$e->getMessage()}");

            return null;
        }
    }
}
