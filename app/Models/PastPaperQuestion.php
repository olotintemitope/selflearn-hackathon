<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PastPaperQuestion extends Model
{
    use HasUuids;

    public const PRACTICE_READY_STATUSES = ['ready', 'owner_checked'];

    protected $fillable = [
        'material_id', 'material_chunk_id', 'extraction_version', 'page', 'paper_year', 'marks',
        'question_number', 'prompt', 'question_type', 'options',
        'source_text',
        'expected_answer', 'answer_source', 'review_status', 'review_reason',
        'confidence', 'parser_version',
    ];

    protected $hidden = ['expected_answer'];

    protected function casts(): array
    {
        return ['options' => 'array', 'confidence' => 'float'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function sourceChunk(): BelongsTo
    {
        return $this->belongsTo(MaterialChunk::class, 'material_chunk_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PastPaperAttempt::class, 'past_paper_question_id');
    }

    public function verifiedAnswers(): HasMany
    {
        return $this->hasMany(PastPaperVerifiedAnswer::class, 'past_paper_question_id');
    }
}
