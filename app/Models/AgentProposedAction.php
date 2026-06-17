<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A write the agent wants to perform, awaiting the user's confirmation. Nothing
 * mutates the user's data until a proposed action is confirmed and executed.
 */
class AgentProposedAction extends Model
{
    use HasFactory;

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    protected $fillable = [
        'chat_session_id',
        'chat_message_id',
        'user_id',
        'tool_name',
        'action_type',
        'payload',
        'summary',
        'risk',
        'auto_confirm',
        'status',
        'idempotency_key',
        'executed_resource_type',
        'executed_resource_id',
        'error',
        'expires_at',
        'confirmed_at',
        'executed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'auto_confirm' => 'boolean',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function executedResource(): MorphTo
    {
        return $this->morphTo('executed_resource');
    }

    public function isExecuted(): bool
    {
        return $this->status === self::STATUS_EXECUTED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PROPOSED;
    }
}
