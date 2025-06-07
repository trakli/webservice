<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Icon extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'content'];

    protected $appends = ['image'];

    public function getImageAttribute()
    {
        return $this->image()->first();
    }

    public function image(): MorphOne
    {
        return $this->morphOne(File::class, 'fileable');
    }

    public function iconable(): MorphTo
    {
        return $this->morphTo();
    }
}
