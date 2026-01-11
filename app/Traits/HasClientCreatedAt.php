<?php

namespace App\Traits;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

trait HasClientCreatedAt
{
    protected static function bootSetsCreatedAtFromClient()
    {
        static::creating(function ($model) {
            $input = request()->input('created_at');

            if ($input) {
                try {
                    $parsed = Carbon::parse($input);
                    $model->created_at = $parsed;
                    $model->updated_at = $parsed;

                    Log::info('[Sync] Setting created_at from client', [
                        'model' => class_basename($model),
                        'id' => $model->id,
                        'client_created_at' => $input,
                        'parsed_created_at' => $parsed->toDateTimeString(),
                    ]);
                } catch (\Exception $e) {
                    Log::warning('[Sync] Invalid created_at format received from client', [
                        'model' => class_basename($model),
                        'input' => $input,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
