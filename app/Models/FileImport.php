<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Whilesmart\Agents\Contracts\HasAgentResource;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FileImport',
    properties: [
        new OA\Property(property: 'id', description: 'ID of the file import', type: 'integer'),
        new OA\Property(property: 'name', description: 'Name of the file to import', type: 'string'),
        new OA\Property(property: 'file_type', description: 'Type of the file to import', type: 'string'),
        new OA\Property(property: 'progress', description: 'Current import progress', type: 'integer'),
        new OA\Property(property: 'num_rows', description: 'Total number of records to import', type: 'integer'),
        new OA\Property(
            property: 'failed_imports_count',
            description: 'Total number of failed imports',
            type: 'integer'
        ),
    ],
    type: 'object'
)]
class FileImport extends Model implements HasAgentResource
{
    use HasFactory;
    use \App\Traits\HasFinanceOwner;

    protected $fillable = ['file_path', 'name', 'file_type', 'progress', 'num_rows'];

    protected $appends = ['failed_imports_count'];

    protected $hidden = ['file_path'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFailedImportsCountAttribute()
    {
        return $this->failedImports()->count();
    }

    public function failedImports(): HasMany
    {
        return $this->hasMany(FailedImport::class, 'file_import_id');
    }

    public static function agentResource(): AgentResource
    {
        return new AgentResource(
            name: 'file_imports',
            model: self::class,
            table: 'file_imports',
            description: 'CSV statement import progress.',
            labelColumn: 'name',
            ownerKey: 'owner_id',
            ownerParam: 'user_id',
            ownerConstants: ['owner_type' => User::class],
            readable: [
                ResourceField::key(),
                ResourceField::string('name', 'Statement name'),
                ResourceField::string('file_type', 'File format'),
                ResourceField::integer('progress', 'Rows processed'),
                ResourceField::integer('num_rows', 'Total rows'),
                ResourceField::internal('owner_id'),
                ResourceField::internal('owner_type', 'string'),
            ],
        );
    }
}
