<?php

namespace App\Models;

use Database\Factories\LearningSpaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningSpace extends Model
{
    public const TUTOR_LANGUAGES = [
        'en' => 'English',
        'ha' => 'Hausa',
        'yo' => 'Yoruba',
        'ig' => 'Igbo',
    ];

    public const PURPOSES = [
        'exam_prep' => 'Prepare for an exam',
        'learn_topic' => 'Learn a topic',
        'course' => 'Study a course',
        'general' => 'General study',
    ];

    /** @use HasFactory<LearningSpaceFactory> */
    use HasFactory;

    protected $fillable = ['title', 'purpose', 'goal', 'subject', 'level', 'exam_date', 'preferred_language'];

    protected function casts(): array
    {
        return ['exam_date' => 'date'];
    }

    public function purposeLabel(): string
    {
        return self::PURPOSES[$this->purpose] ?? self::PURPOSES['general'];
    }

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

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }
}
