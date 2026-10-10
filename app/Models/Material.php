<?php

namespace App\Models;

use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    protected $fillable = [
        'title', 'private_source_path', 'original_filename', 'mime_type',
        'size_bytes', 'status', 'extraction_version', 'metadata', 'failure_message',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function learningSpace(): BelongsTo
    {
        return $this->belongsTo(LearningSpace::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(MaterialChunk::class);
    }
}
