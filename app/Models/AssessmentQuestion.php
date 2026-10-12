<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentQuestion extends Model
{
    use HasUuids;

    protected $fillable = ['concept_id', 'question_type', 'knowledge_basis', 'prompt', 'options', 'correct_option_id', 'explanation', 'source_refs', 'rubric', 'max_points', 'solution_steps'];

    protected $hidden = ['correct_option_id', 'rubric', 'solution_steps'];

    protected function casts(): array
    {
        return ['options' => 'array', 'source_refs' => 'array', 'rubric' => 'array', 'solution_steps' => 'array'];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }
}
