<?php

namespace App\Models;

use RuntimeException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Whilesmart\Agents\Contracts\HasAgentResource;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;
use Whilesmart\Workspaces\Models\Workspace as BaseWorkspace;

class Workspace extends BaseWorkspace implements HasAgentResource
{
    protected static function booted(): void
    {
        static::deleting(function (Workspace $workspace) {
            if (! $workspace->isForceDeleting()) {
                return;
            }
            $relations = ['wallets', 'transactions', 'categories', 'groups', 'parties', 'transfers',
                'reminders', 'fileImports', 'importSessions', 'budgets'];
            foreach ($relations as $relation) {
                if ($workspace->{$relation}()->withoutGlobalScopes()->exists()) {
                    throw new RuntimeException('A workspace with financial records cannot be permanently deleted.');
                }
            }
        });
    }

    public function wallets(): MorphMany
    {
        return $this->morphMany(Wallet::class, 'owner');
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'owner');
    }

    public function categories(): MorphMany
    {
        return $this->morphMany(Category::class, 'owner');
    }

    public function groups(): MorphMany
    {
        return $this->morphMany(Group::class, 'owner');
    }

    public function parties(): MorphMany
    {
        return $this->morphMany(Party::class, 'owner');
    }

    public function transfers(): MorphMany
    {
        return $this->morphMany(Transfer::class, 'owner');
    }

    public function reminders(): MorphMany
    {
        return $this->morphMany(Reminder::class, 'owner');
    }

    public function importSessions(): MorphMany
    {
        return $this->morphMany(ImportSession::class, 'owner');
    }

    public function fileImports(): MorphMany
    {
        return $this->morphMany(FileImport::class, 'owner');
    }

    public function budgets(): MorphMany
    {
        return $this->morphMany(Budget::class, 'owner');
    }

    public static function agentResource(): AgentResource
    {
        return new AgentResource(
            name: 'workspaces',
            model: self::class,
            table: 'workspaces',
            description: 'Families and groups collaborating on shared finances.',
            labelColumn: 'name',
            ownerKey: 'owner_id',
            ownerConstants: ['owner_type' => User::class],
            readable: [ResourceField::key(), ResourceField::string('name', 'Workspace name'),
                ResourceField::text('description', 'What the workspace is for'),
                ResourceField::internal('owner_id'), ResourceField::internal('owner_type', 'string')],
            readTool: false,
        );
    }
}
