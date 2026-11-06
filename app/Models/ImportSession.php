<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Whilesmart\Agents\Contracts\HasAgentResource;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;

class ImportSession extends Model implements HasAgentResource
{
    use \App\Traits\HasFinanceOwner;

    protected $attributes = ['revision' => 0];

    protected $fillable = [
        'revision',
        'file_name',
        'file_type',
        'document_type',
        'notes',
        'processor',
        'status',
        'suggestions',
        'metadata',
    ];

    protected $casts = [
        'revision' => 'integer',
        'suggestions' => 'array',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $session) {
            if ($session->isDirty('suggestions') && ($session->exists || $session->suggestions !== [])) {
                $session->suggestions = array_map(function (array $row) {
                    $row['id'] ??= (string) Str::uuid();

                    return $row;
                }, $session->suggestions ?? []);
                $session->revision = ($session->getRawOriginal('revision') ?? 0) + 1;
            } elseif ($session->exists && $session->isDirty('status')) {
                $session->revision = ($session->getRawOriginal('revision') ?? 0) + 1;
            }
        });
    }

    public function ensureSuggestionIds(): void
    {
        DB::transaction(function () {
            $locked = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            if (collect($locked->suggestions)->contains(fn ($row) => empty($row['id']))) {
                $locked->suggestions = array_map(function ($row) {
                    $row['id'] ??= (string) Str::uuid();

                    return $row;
                }, $locked->suggestions);
                $locked->save();
            }
            $this->setRawAttributes($locked->getAttributes(), true);
        });
    }

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
                ResourceField::text('notes', 'Instructions from the account owner'),
                ResourceField::string('status', 'Analysis status'),
                ResourceField::integer('revision', 'Import review revision'),
                ResourceField::internal('owner_id'),
                ResourceField::internal('owner_type', 'string'),
            ],
        );
    }
}
