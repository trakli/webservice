<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Whilesmart\Agents\Contracts\HasAgentResource;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportSession extends Model implements HasAgentResource
{
    use \App\Traits\HasFinanceOwner;

    protected $fillable = [
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

    public static function agentResource(): AgentResource
    {
        return new AgentResource(
            name: 'import_sessions',
            model: self::class,
            table: 'import_sessions',
            description: 'Document import analysis status.',
            labelColumn: 'file_name',
            ownerKey: 'owner_id',
            ownerParam: 'user_id',
            ownerConstants: ['owner_type' => User::class],
            readable: [
                ResourceField::key(),
                ResourceField::string('file_name', 'Document name'),
                ResourceField::string('file_type', 'File format'),
                ResourceField::string('document_type', 'Document classification'),
                ResourceField::string('status', 'Analysis status'),
                ResourceField::internal('owner_id'),
                ResourceField::internal('owner_type', 'string'),
            ],
        );
    }
}
