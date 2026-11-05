<?php

namespace App\Models;

use App\Enums\ImportMatchField;
use App\Enums\ImportMatchMode;
use App\Enums\ImportRuleKind;
use App\Services\ImportRuleValidator;
use App\Traits\HasFinanceOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Whilesmart\Agents\Contracts\HasAgentResource;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;

#[OA\Schema(
    schema: 'ImportRuleInput',
    required: ['kind'],
    properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['party_alias', 'category_hint', 'wallet_hint', 'skip', 'instruction']),
        new OA\Property(property: 'match_field', type: 'string', enum: ['description', 'counterparty', 'any'], nullable: true),
        new OA\Property(property: 'match_mode', type: 'string', enum: ['contains', 'exact', 'starts_with'], nullable: true),
        new OA\Property(property: 'pattern', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'target_type', type: 'string', enum: ['party', 'category', 'wallet'], nullable: true),
        new OA\Property(property: 'target_id', type: 'integer', nullable: true),
        new OA\Property(property: 'instruction', type: 'string', maxLength: 500, nullable: true),
        new OA\Property(property: 'active', type: 'boolean', default: true),
    ],
    type: 'object',
    description: 'Matching rules require a field, mode and pattern. Alias and hint rules require a target of the corresponding type owned by the caller. Instructions require instruction text and no matching fields. Skip and instruction rules have no target.'
)]
class ImportRule extends Model implements HasAgentResource
{
    use HasFinanceOwner;
    use SoftDeletes;

    protected $fillable = ['kind', 'match_field', 'match_mode', 'pattern', 'target_type', 'target_id', 'instruction', 'source_session_id', 'active'];

    protected $casts = [
        'kind' => ImportRuleKind::class,
        'match_field' => ImportMatchField::class,
        'match_mode' => ImportMatchMode::class,
        'active' => 'boolean',
        'last_used_at' => 'datetime',
        'hits' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $rule) {
            app(ImportRuleValidator::class)->validate($rule->attributesToArray(), $rule->owner);
            if ($rule->target_id && (! $rule->target() || ! $rule->hasSameOwner($rule->target()))) {
                throw new InvalidArgumentException('An import rule and its target must have the same owner.');
            }
            if ($rule->source_session_id && ! $rule->hasSameOwner(ImportSession::findOrFail($rule->source_session_id))) {
                throw new InvalidArgumentException('An import rule and its source session must have the same owner.');
            }
        });
    }

    public static function targetTypeFor(string $kind): ?string
    {
        return match ($kind) {
            'party_alias' => 'party',
            'category_hint' => 'category',
            'wallet_hint' => 'wallet',
            default => null,
        };
    }

    public static function targetClass(string $type): string
    {
        return match ($type) {
            'party' => Party::class,
            'category' => Category::class,
            'wallet' => Wallet::class,
        };
    }

    public function target(): ?Model
    {
        return $this->target_type && $this->target_id
            ? self::targetClass($this->target_type)::query()->find($this->target_id)
            : null;
    }

    public static function agentResource(): AgentResource
    {
        $fields = [
            ResourceField::enum('kind', array_column(ImportRuleKind::cases(), 'value'), rules: 'required|in:party_alias,category_hint,wallet_hint,skip,instruction', required: true),
            ResourceField::enum('match_field', ['description', 'counterparty', 'any']),
            ResourceField::enum('match_mode', ['contains', 'exact', 'starts_with']),
            ResourceField::string('pattern'),
            ResourceField::enum('target_type', ['party', 'category', 'wallet']),
            ResourceField::integer('target_id', 'Target ID interpreted through target_type: party, category or wallet'),
            ResourceField::text('instruction', rules: 'nullable|string|max:500'),
            ResourceField::boolean('active', rules: 'sometimes|boolean'),
        ];

        return new AgentResource(
            name: 'import_rules',
            model: self::class,
            table: 'import_rules',
            description: 'Saved statement aliases, classification hints, skipped rows and import instructions.',
            labelColumn: 'pattern',
            ownerKey: 'owner_id',
            ownerParam: 'user_id',
            ownerConstants: ['owner_type' => User::class],
            readable: [ResourceField::key(), ...$fields,
                ResourceField::integer('hits'), ResourceField::datetime('last_used_at'),
                ResourceField::reference('source_session_id', 'import_sessions.id'),
                ResourceField::internal('owner_id'), ResourceField::internal('owner_type', 'string'),
            ],
            writable: $fields,
            writeEnabled: true,
        );
    }
}
