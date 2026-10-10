<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Concept extends Model
{
    protected $fillable = ['name', 'description', 'difficulty'];

    public function learningSpace(): BelongsTo
    {
        return $this->belongsTo(LearningSpace::class);
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(MaterialChunk::class, 'concept_sources');
    }

    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'concept_dependencies', 'concept_id', 'prerequisite_concept_id')
            ->withPivot('source_supported');
    }

    public function assessmentQuestions(): HasMany
    {
        return $this->hasMany(AssessmentQuestion::class);
    }
}
