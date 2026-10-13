<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PastPaperVerifiedAnswer extends Model
{
    use HasUuids;

    protected $fillable = [
        'past_paper_question_id',
        'marking_scheme_match_id',
        'marking_scheme_chunk_id',
        'confirmed_by',
        'paper_extraction_version',
        'marking_scheme_extraction_version',
        'confirmation_version',
        'source_page',
        'option_ids',
        'answer_excerpt',
    ];

    protected function casts(): array
    {
        return ['option_ids' => 'array'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(PastPaperQuestion::class, 'past_paper_question_id');
    }

    public function markingSchemeMatch(): BelongsTo
    {
        return $this->belongsTo(PastPaperMarkingSchemeMatch::class, 'marking_scheme_match_id');
    }

    public function markingSchemeChunk(): BelongsTo
    {
        return $this->belongsTo(MaterialChunk::class, 'marking_scheme_chunk_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PastPaperAttempt::class, 'verified_answer_id');
    }
}
