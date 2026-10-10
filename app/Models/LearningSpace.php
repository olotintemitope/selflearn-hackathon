<?php

namespace App\Models;

use Database\Factories\LearningSpaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningSpace extends Model
{
    /** @use HasFactory<LearningSpaceFactory> */
    use HasFactory;

    protected $fillable = ['title', 'goal', 'preferred_language'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function concepts(): HasMany
    {
        return $this->hasMany(Concept::class);
    }

    public function learningPaths(): HasMany
    {
        return $this->hasMany(LearningPath::class);
    }

    public function tutorMessages(): HasMany
    {
        return $this->hasMany(TutorMessage::class);
    }
}
