<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PastPaperPracticeRun extends Model
{
    use HasUuids;

    protected $fillable = ['material_id', 'selected_material_ids', 'user_id', 'question_ids', 'started_at', 'duration_minutes', 'expires_at', 'submitted_at'];

    protected function casts(): array
    {
        return ['question_ids' => 'array', 'selected_material_ids' => 'array', 'started_at' => 'datetime', 'expires_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PastPaperAttempt::class, 'practice_run_id');
    }
}
