<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PastPaperAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'practice_run_id', 'past_paper_question_id', 'user_id', 'response', 'outcome', 'marking_basis', 'submitted_at',
    ];

    protected function casts(): array
    {
        return ['response' => 'array', 'submitted_at' => 'datetime'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(PastPaperQuestion::class, 'past_paper_question_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function practiceRun(): BelongsTo
    {
        return $this->belongsTo(PastPaperPracticeRun::class, 'practice_run_id');
    }
}
