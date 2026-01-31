<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'File',
    properties: [
        new OA\Property(property: 'id', description: 'ID of the file', type: 'integer'),
        new OA\Property(property: 'path', description: 'Path or content of the file', type: 'string'),
        new OA\Property(property: 'link', description: 'URL to access the file', type: 'string'),
        new OA\Property(property: 'type', description: 'Type of file (image, icon, emoji, pdf)', type: 'string', enum: ['image', 'pdf', 'icon', 'emoji']),
        new OA\Property(property: 'model', description: 'Related model name', type: 'string'),
        new OA\Property(property: 'model_id', description: 'ID of the related model', type: 'integer'),
    ],
    type: 'object'
)]
class File extends Model
{
    use HasFactory;

    protected $fillable = ['path', 'type', 'fileable_type', 'fileable_id'];

    protected $appends = ['link'];

    protected $casts = [
        'model_id' => 'integer',
    ];

    public function getLinkAttribute(): ?string
    {
        if (! $this->id) {
            return null;
        }

        return url("/api/v1/files/{$this->id}");
    }

    /**
     * Get the owning fileable model.
     */
    public function fileable()
    {
        return $this->morphTo();
    }

    public function update(array $attributes = [], array $options = [])
    {
        // delete file in storage
        $path = $this->path;
        if (Storage::exists($path)) {
            Storage::delete($path);
        }

        return parent::update($attributes, $options);
    }

    public function delete()
    {
        // delete file in storage
        $path = $this->path;
        if (Storage::exists($path)) {
            Storage::delete($path);
        }

        return parent::delete();
    }
}
