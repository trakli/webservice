<?php

namespace App\Ai;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Whilesmart\Agents\Facades\Agents;
use Whilesmart\Agents\ValueObjects\ToolContext;

class ModelCall
{
    public static function text(string $name, ?Model $owner, string $system, string $prompt, ?string $provider, ?string $model, float $temperature): string
    {
        $result = Agents::complete($prompt, new ToolContext($owner instanceof Authenticatable ? $owner : null), [
            'name' => $name,
            'system' => $system,
            'provider' => $provider,
            'model' => $model,
            'temperature' => $temperature,
        ]);

        if (! $result->ok) {
            throw new RuntimeException($result->error ?? 'The model call failed.');
        }

        return $result->text;
    }
}
