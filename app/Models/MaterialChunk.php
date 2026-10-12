<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MaterialChunk extends Model
{
    protected $fillable = [
        'extraction_version', 'content', 'page', 'section', 'locator',
        'relevance_status', 'relevance_confidence', 'relevance_reason',
    ];

    protected function casts(): array
    {
        return ['locator' => 'array', 'relevance_confidence' => 'float'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function concepts(): BelongsToMany
    {
        return $this->belongsToMany(Concept::class, 'concept_sources');
    }
}
