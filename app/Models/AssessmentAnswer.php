<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentAnswer extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'assessment_question_id', 'selected_option_id', 'correct', 'written_response', 'marking_status',
        'points_awarded', 'max_points', 'marking_feedback', 'marking_citations', 'marking_criteria_results',
    ];

    protected function casts(): array
    {
        return ['correct' => 'boolean', 'marking_citations' => 'array', 'marking_criteria_results' => 'array'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssessmentAttempt::class, 'assessment_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(AssessmentQuestion::class, 'assessment_question_id');
    }
}
