<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Whilesmart\Agents\Contracts\HasAgentResource;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;
use Whilesmart\Workspaces\Models\Workspace as BaseWorkspace;

class Workspace extends BaseWorkspace implements HasAgentResource
{
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
                ResourceField::internal('owner_id'), ResourceField::internal('owner_type')],
            readTool: false,
        );
    }
}
