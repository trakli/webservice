<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        new OA\Property(property: 'failed_imports_count', description: 'Total number of failed imports', type: 'integer'),
    ],
    type: 'object'
)]
class FileImport extends Model
{
    use HasFactory;

    protected $fillable = ['file_path', 'name', 'file_type', 'user_id', 'progress', 'num_rows'];

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
}
