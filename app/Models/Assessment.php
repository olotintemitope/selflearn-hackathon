<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    use HasUuids;

    protected $fillable = ['concept_id', 'prompt_version', 'mode', 'started_at', 'duration_seconds'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime'];
    }

    public function learningSpace(): BelongsTo
    {
        return $this->belongsTo(LearningSpace::class);
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(AssessmentQuestion::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AssessmentAttempt::class);
    }
}
