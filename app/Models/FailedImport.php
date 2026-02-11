<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FailedImport',
    properties: [
        new OA\Property(property: 'id', description: 'ID of the failed import', type: 'integer'),
        new OA\Property(
            property: 'amount',
            description: 'The amount of the transaction',
            type: 'number',
            format: 'float'
        ),
        new OA\Property(property: 'type', description: 'The type of the transaction', type: 'string'),
        new OA\Property(property: 'party', description: 'The party of the transaction', type: 'string'),
        new OA\Property(property: 'wallet', description: 'The wallet of the transaction', type: 'string'),
        new OA\Property(property: 'category', description: 'The category of the transaction', type: 'string'),
        new OA\Property(property: 'description', description: 'The description of the transaction', type: 'string'),
        new OA\Property(property: 'date', description: 'The date of the transaction', type: 'date'),
        new OA\Property(property: 'reason', description: 'The reason why the transaction failed', type: 'string'),
    ]
)]
class FailedImport extends Model
{
    use HasFactory;

    protected $fillable = ['amount',
        'currency', 'type', 'party',
        'wallet', 'category', 'description',
        'date', 'reason', 'file_import_id'];

    protected $hidden = ['file_import_id'];

    public function fileImport(): BelongsTo
    {
        return $this->belongsTo(FileImport::class, 'file_import_id');
    }
}
