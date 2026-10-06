<?php

namespace App\Ai\Billing;

use InvalidArgumentException;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class RemoteModelGrant
{
    public function issue(User $owner): string
    {
        return Crypt::encryptString(json_encode([
            'scope' => 'smartql.model',
            'owner_id' => $owner->getKey(),
            'request_id' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function resolve(string $token): array
    {
        $grant = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        if (
            ($grant['scope'] ?? null) !== 'smartql.model'
            || ! is_int($grant['expires_at'] ?? null)
            || $grant['expires_at'] <= now()->timestamp
            || ! Str::isUuid($grant['request_id'] ?? '')
        ) {
            throw new InvalidArgumentException('Invalid model owner grant.');
        }

        $owner = User::findOrFail($grant['owner_id'] ?? null);

        return [$owner, $grant['request_id']];
    }
}
