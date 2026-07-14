<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ChatMessage',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'user_id', type: 'integer', nullable: true),
        new OA\Property(property: 'role', type: 'string', enum: ['user', 'assistant', 'system']),
        new OA\Property(property: 'content', type: 'string', nullable: true),
        new OA\Property(
            property: 'status',
            type: 'string',
            enum: ['pending', 'processing', 'completed', 'failed'],
            nullable: true
        ),
        new OA\Property(property: 'format_hint', type: 'string', nullable: true),
        new OA\Property(property: 'language', type: 'string', nullable: true),
        new OA\Property(property: 'result', type: 'object', nullable: true),
        new OA\Property(property: 'error', type: 'string', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
class ChatMessage extends Model
{
    use HasFactory;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'chat_session_id',
        'user_id',
        'role',
        'content',
        'status',
        'format_hint',
        'language',
        'result',
        'progress',
        'error',
        'completed_at',
    ];

    protected $casts = [
        'result' => 'array',
        'progress' => 'array',
        'completed_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
