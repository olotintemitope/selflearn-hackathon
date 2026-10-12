<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TutorMessage extends Model
{
    protected $fillable = [
        'role', 'content', 'citations', 'supplementary_sections', 'insufficient_evidence',
        'status', 'failure_message', 'prompt_version', 'concept_id', 'understanding_check',
        'language',
    ];

    protected function casts(): array
    {
        return [
            'citations' => 'array',
            'supplementary_sections' => 'array',
            'insufficient_evidence' => 'boolean',
        ];
    }

    public function learningSpace(): BelongsTo
    {
        return $this->belongsTo(LearningSpace::class);
    }
}
