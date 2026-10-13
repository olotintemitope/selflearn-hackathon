<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PastPaperMarkingSchemeMatch extends Model
{
    protected $fillable = [
        'paper_material_id',
        'marking_scheme_material_id',
        'confirmed_by',
        'paper_extraction_version',
        'marking_scheme_extraction_version',
    ];

    public function paper(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'paper_material_id');
    }

    public function markingScheme(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'marking_scheme_material_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
