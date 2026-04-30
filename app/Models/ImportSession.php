<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportSession extends Model
{
    protected $fillable = [
        'user_id',
        'file_name',
        'file_type',
        'document_type',
        'processor',
        'status',
        'suggestions',
        'metadata',
    ];

    protected $casts = [
        'suggestions' => 'array',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
